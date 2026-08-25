"""PC28 MySQL DAO（PyMySQL）。

两张表：
- pc28_lottery     ：开奖历史（主键 nbr 期号）
- pc28_push_state  ：推送状态（last_issue / push_count）

设计原则：
- 启动时 ensure_schema() 自动 CREATE TABLE IF NOT EXISTS（不依赖手工迁移）
- 所有方法线程安全（内部 RLock）
- 不可用时（MySQL 没启、连接失败）调用方应捕获异常，存储降级为 no-op，
  不阻塞 fetcher 主循环。
"""
import threading
import time

import pymysql
from pymysql.cursors import DictCursor


def _default_dsn():
    return {
        "host": "127.0.0.1",
        "port": 3306,
        "user": "root",
        "password": "",
        "db": "pc28",
        "charset": "utf8mb4",
        "autocommit": True,
        "connect_timeout": 5,
        "read_timeout": 10,
        "write_timeout": 10,
    }


SCHEMA_LOTTERY = """
CREATE TABLE IF NOT EXISTS pc28_lottery (
  nbr          VARCHAR(20)  PRIMARY KEY,
  draw_time    VARCHAR(32)  NOT NULL,
  n1 TINYINT      NOT NULL,
  n2           TINYINT      NOT NULL,
  n3           TINYINT      NOT NULL,
  sum_val TINYINT      NOT NULL,
  combination VARCHAR(32)  NOT NULL,
  raw_number VARCHAR(32)  NOT NULL,
  fetched_at   BIGINT       NOT NULL,
  pushed_at    BIGINT       NULL,
  INDEX idx_draw_time (draw_time),
  INDEX idx_pushed_at (pushed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
"""

SCHEMA_PUSH_STATE = """
CREATE TABLE IF NOT EXISTS pc28_push_state (
  id INT PRIMARY KEY AUTO_INCREMENT,
  last_issue   VARCHAR(20) NOT NULL,
  push_count   INT NOT NULL DEFAULT 0,
  updated_at   BIGINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
"""


class MySQLStore:
    """线程安全的 PC28 数据访问对象。"""

    def __init__(self, dsn=None, logger=None):
        self.dsn = dict(_default_dsn())
        if dsn:
            self.dsn.update({k: v for k, v in dsn.items() if v is not None})
        self.logger = logger or (lambda m: None)
        self._lock = threading.RLock()
        self._conn = None
        self._available = None  # None=未尝试，True/False
        self._init_attempts = 0

    # ---------- 连接管理 ----------
    def _connect(self):
        try:
            self._conn = pymysql.connect(
                host=self.dsn.get("host", "127.0.0.1"),
                port=int(self.dsn.get("port", 3306)),
                user=self.dsn.get("user", "root"),
                password=self.dsn.get("password", ""),
                database=self.dsn.get("db", "pc28"),
                charset=self.dsn.get("charset", "utf8mb4"),
                autocommit=self.dsn.get("autocommit", True),
                connect_timeout=int(self.dsn.get("connect_timeout", 5)),
                read_timeout=int(self.dsn.get("read_timeout", 10)),
                write_timeout=int(self.dsn.get("write_timeout", 10)),
                cursorclass=DictCursor,
            )
            return True
        except Exception as e:
            self._conn = None
            self.logger("[pc28.store] MySQL 连接失败: %s" % e)
            return False

    def _ensure_conn(self):
        if self._conn is not None:
            try:
                self._conn.ping(reconnect=True)
                return True
            except Exception:
                self._conn = None
        return self._connect()

    def ensure_schema(self):
        """确保两张表存在（幂等）。"""
        with self._lock:
            if not self._ensure_conn():
                return False
            try:
                with self._conn.cursor() as cur:
                    cur.execute(SCHEMA_LOTTERY)
                    cur.execute(SCHEMA_PUSH_STATE)
                return True
            except Exception as e:
                self.logger("[pc28.store] ensure_schema 失败: %s" % e)
                return False

    def is_available(self):
        """探测当前是否可用（首次尝试 + 缓存）。"""
        with self._lock:
            if self._available is True:
                return True
            if self._available is False and self._init_attempts < 1:
                pass  # 再试一次
            ok = self._ensure_conn()
            self._available = ok
            self._init_attempts += 1
            return ok

    def close(self):
        with self._lock:
            try:
                if self._conn is not None:
                    self._conn.close()
            except Exception:
                pass
            self._conn = None

    # ---------- 开奖 ----------
    def upsert_issue(self, issue):
        """写入或更新一条开奖（按 nbr 主键）。"""
        if issue is None:
            return False
        with self._lock:
            if not self._ensure_conn():
                return False
            try:
                now_ms = int(time.time() * 1000)
                with self._conn.cursor() as cur:
                    cur.execute(
                        """INSERT INTO pc28_lottery
                           (nbr, draw_time, n1, n2, n3, sum_val, combination, raw_number, fetched_at)
                           VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s)
                           ON DUPLICATE KEY UPDATE
                             draw_time=VALUES(draw_time),
                             n1=VALUES(n1), n2=VALUES(n2), n3=VALUES(n3),
                             sum_val=VALUES(sum_val), combination=VALUES(combination),
                             raw_number=VALUES(raw_number),
                             fetched_at=VALUES(fetched_at)
                        """,
                        (issue.nbr, issue.time, issue.n1, issue.n2, issue.n3,
                         issue.sum_val, issue.combination, issue.raw_number, now_ms),
                    )
                return True
            except Exception as e:
                self.logger("[pc28.store] upsert_issue 失败: %s" % e)
                self._conn = None
                return False

    def mark_pushed(self, nbr):
        """标记某期已推送。"""
        with self._lock:
            if not self._ensure_conn():
                return False
            try:
                now_ms = int(time.time() * 1000)
                with self._conn.cursor() as cur:
                    cur.execute(
                        "UPDATE pc28_lottery SET pushed_at=%s WHERE nbr=%s AND pushed_at IS NULL",
                        (now_ms, nbr),
                    )
                return True
            except Exception as e:
                self.logger("[pc28.store] mark_pushed 失败: %s" % e)
                self._conn = None
                return False

    def get_issue(self, nbr):
        """按期号查询单期。返回 Issue 或 None。"""
        with self._lock:
            if not self._ensure_conn():
                return None
            try:
                with self._conn.cursor() as cur:
                    cur.execute(
                        "SELECT nbr, draw_time, n1, n2, n3, sum_val, combination, raw_number "
                        "FROM pc28_lottery WHERE nbr=%s",
                        (str(nbr),),
                    )
                    row = cur.fetchone()
                if not row:
                    return None
                from .api import Issue
                return Issue.from_dict(row)
            except Exception as e:
                self.logger("[pc28.store] get_issue 失败: %s" % e)
                self._conn = None
                return None

    def get_latest(self, limit=1):
        """按 draw_time DESC 取最近 limit 条。"""
        return self.get_history(limit)

    def get_history(self, limit=20):
        """按 draw_time DESC 取最近 limit 条（最新在前）。"""
        with self._lock:
            if not self._ensure_conn():
                return []
            try:
                with self._conn.cursor() as cur:
                    cur.execute(
                        "SELECT nbr, draw_time, n1, n2, n3, sum_val, combination, raw_number "
                        "FROM pc28_lottery ORDER BY draw_time DESC, nbr DESC LIMIT %s",
                        (max(1, int(limit)),),
                    )
                    rows = cur.fetchall()
                from .api import Issue
                return [Issue.from_dict(r) for r in rows]
            except Exception as e:
                self.logger("[pc28.store] get_history 失败: %s" % e)
                self._conn = None
                return []

    # ---------- 推送状态 ----------
    def get_push_state(self):
        """返回 {last_issue, push_count, updated_at}；无记录返回 None。"""
        with self._lock:
            if not self._ensure_conn():
                return None
            try:
                with self._conn.cursor() as cur:
                    cur.execute(
                        "SELECT last_issue, push_count, updated_at FROM pc28_push_state "
                        "ORDER BY id DESC LIMIT 1"
                    )
                    row = cur.fetchone()
                return row
            except Exception as e:
                self.logger("[pc28.store] get_push_state 失败: %s" % e)
                self._conn = None
                return None

    def upsert_push_state(self, last_issue, push_count):
        """更新推送状态（始终保留最新一行）。"""
        with self._lock:
            if not self._ensure_conn():
                return False
            try:
                now_ms = int(time.time() * 1000)
                with self._conn.cursor() as cur:
                    cur.execute("SELECT id FROM pc28_push_state ORDER BY id DESC LIMIT 1")
                    row = cur.fetchone()
                    if row:
                        cur.execute(
                            "UPDATE pc28_push_state SET last_issue=%s, push_count=%s, updated_at=%s WHERE id=%s",
                            (last_issue, int(push_count), now_ms, row["id"]),
                        )
                    else:
                        cur.execute(
                            "INSERT INTO pc28_push_state (last_issue, push_count, updated_at) VALUES (%s,%s,%s)",
                            (last_issue, int(push_count), now_ms),
                        )
                return True
            except Exception as e:
                self.logger("[pc28.store] upsert_push_state 失败: %s" % e)
                self._conn = None
                return False


class NullStore:
    """MySQL 不可用时的降级空实现。所有写方法返回 False，所有读方法返回 None/[]。"""

    def __init__(self, logger=None):
        self.logger = logger or (lambda m: None)

    def ensure_schema(self):
        return True

    def is_available(self):
        return False

    def close(self):
        pass

    def upsert_issue(self, issue):
        return False

    def mark_pushed(self, nbr):
        return False

    def get_issue(self, nbr):
        return None

    def get_latest(self, limit=1):
        return []

    def get_history(self, limit=20):
        return []

    def get_push_state(self):
        return None

    def upsert_push_state(self, last_issue, push_count):
        return False


def build_store(cfg, logger=None):
    """根据配置构造存储实例。MySQL 失败时降级为 NullStore。

    cfg 期望结构：pc28.mysql.{host,port,user,password,db}。
    """
    if not cfg:
        return NullStore(logger=logger)
    log = logger or (lambda m: None)
    if not cfg.get("enabled", True):
        log("[pc28.store] pc28.enabled=false，使用 NullStore")
        return NullStore(logger=log)
    dsn = cfg.get("mysql") or {}
    try:
        store = MySQLStore(dsn=dsn, logger=log)
        if store.is_available():
            store.ensure_schema()
            return store
        log("[pc28.store] MySQL 不可用，降级为 NullStore")
        store.close()
        return NullStore(logger=log)
    except Exception as e:
        log("[pc28.store] 构造存储失败: %s" % e)
        return NullStore(logger=log)
