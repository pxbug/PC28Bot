import json
import os
import threading
import time
import re
from typing import Callable, Optional, List, Dict, Any


DEFAULT_BASE_URL = "https://yu28.top"
DEFAULT_GAME = "jnd28"   # jnd28=加拿大28, hx28=哈希28
DEFAULT_TIMEOUT = 10


def _now_ms():
    return int(time.time() * 1000)


# ---------- 工具 ----------

_COUNTDOWN_RE = re.compile(r"^\s*(\d{1,3}):([0-5]\d)\s*$")


def parse_countdown(text):
    if not text or not isinstance(text, str):
        return None
    m = _COUNTDOWN_RE.match(text)
    if not m:
        return None
    mm = int(m.group(1))
    ss = int(m.group(2))
    return mm * 60 + ss


def normalize_issue(item):
    """把 API 返回的 data[i] 规整为内部统一结构。容忍字段缺失/类型异常。"""
    if not isinstance(item, dict):
        return None
    nbr = str(item.get("nbr") or "").strip()
    if not nbr:
        return None
    return {
        "nbr": nbr,
        "time": str(item.get("time") or "").strip(),
        "number": str(item.get("number") or "").strip(),
        "combination": str(item.get("combination") or "").strip(),
        "height": item.get("height"),
        "hash": item.get("hash"),
        "md5": item.get("md5"),
    }


# ---------- API 客户端 ----------

class LotteryAPIError(Exception):
    """API 调用失败（网络/鉴权/响应格式）。"""


class LotteryClient:
    """yu28.top 开奖 API 客户端（同步 requests）。"""

    def __init__(self, base_url=None, api_key=None, game=None, timeout=None, logger=None):
        self.base_url = (base_url or DEFAULT_BASE_URL).rstrip("/")
        self.api_key = api_key or ""
        self.game = game or DEFAULT_GAME
        self.timeout = int(timeout or DEFAULT_TIMEOUT)
        self.logger = logger or (lambda msg: None)

    def _auth_headers(self):
        if not self.api_key:
            return {}
        return {"X-Api-Key": self.api_key, "Authorization": "Bearer " + self.api_key}

    def _url(self, nbr):
        n = max(1, min(100, int(nbr or 1)))
        return "%s/api/kj.json?nbr=%d&game=%s" % (self.base_url, n, self.game)

    def fetch_recent(self, nbr=1):
        """拉最近 N 期开奖（最新在前），返回 data 列表。

        nbr 必须 1-100，越界按 1 处理；返回空列表视为失败（不抛异常给上层选择）。
        """
        n = max(1, min(100, int(nbr or 1)))
        try:
            import requests
        except ImportError as e:
            raise LotteryAPIError("缺少 requests 依赖") from e
        url = self._url(n)
        params = {}
        if self.api_key:
            params["key"] = self.api_key
        try:
            r = requests.get(url, params=params, headers=self._auth_headers(),
                             timeout=self.timeout)
        except Exception as e:
            raise LotteryAPIError("网络错误: %s" % e) from e
        if r.status_code != 200:
            raise LotteryAPIError("HTTP %d: %s" % (r.status_code, r.text[:200]))
        try:
            payload = r.json()
        except Exception as e:
            raise LotteryAPIError("响应非 JSON: %s" % e) from e
        data = payload.get("data") if isinstance(payload, dict) else None
        if not isinstance(data, list):
            return [], payload.get("countdown") if isinstance(payload, dict) else None
        normalized = []
        for item in data:
            norm = normalize_issue(item)
            if norm:
                normalized.append(norm)
        return normalized, (payload.get("countdown") if isinstance(payload, dict) else None)

    def fetch_countdown(self, nbr=1):
        """单独拿倒计时（轻量）。"""
        items, countdown = self.fetch_recent(nbr)
        return countdown


# ---------- 文本格式化 ----------

def format_issue(item, game=None):
    """单期多行文本（保留供测试 / 备用）。"""
    if not isinstance(item, dict):
        return ""
    lines = ["第 %s 期" % (item.get("nbr") or "")]
    if item.get("time"):
        lines.append("时间：%s" % item["time"])
    lines.append("开奖：%s" % (item.get("number") or "-"))
    combo = item.get("combination") or ""
    if combo:
        lines.append("组合：%s" % combo)
    if game == "hx28" and item.get("md5"):
        lines.append("校验：%s" % item["md5"])
    return "\n".join(lines)


def format_push(item, source="PC28 开奖"):
    """推送单条开奖消息（群里发的文本）。

    示例输出：
        🎰 PC28 开奖
        ━━━━━━━━━━━
        期号：3473898
        开奖：8 + 9 + 2 = 19
        组合：大单
    """
    if not isinstance(item, dict):
        return ""
    nbr = item.get("nbr") or "-"
    num = item.get("number") or "-"
    combo = item.get("combination") or "-"
    return (
        "🎰 %s\n"
        "━━━━━━━━━━━\n"
        "期号：%s\n"
        "开奖：%s\n"
        "组合：%s"
    ) % (source, nbr, num, combo)


def format_recent(data, n=20, title=None):
    """组装历史 N 期文本（旧→新）。

    API 返回最新在前；本函数按期号升序展示（最旧在上、最新在下），
    便于从前往后顺读时间线。

    示例输出（固定 3 列右对齐：期号 / 开奖 / 组合）：
        📜 历史开奖（最近 20 期）
        期号       开奖            组合
        -------  --------------  ----
        3473879  1 + 8 + 7 = 16  [大双]
        3473880  6 + 0 + 1 = 7   [小单]
        ...
    """
    if not isinstance(data, list) or not data:
        return "暂无开奖数据"
    limit = max(1, int(n or 20))
    items = data[:limit]
    # API 返回最新在前，按期号升序（旧→新）展示
    items.sort(key=lambda i: str(i.get("nbr") or ""))
    head = title or "📜 历史开奖（最近 %d 期）" % len(items)

    # 列宽
    nbr_w = max(len("期号"), max(len(str(i.get("nbr") or "-")) for i in items))
    num_w = max(len("开奖"), max(len(str(i.get("number") or "-")) for i in items))

    lines = [head, "%s  %s  %s" % ("期号".ljust(nbr_w), "开奖".ljust(num_w), "组合")]
    lines.append("-" * nbr_w + "  " + "-" * num_w + "  " + "----")
    for it in items:
        nbr = str(it.get("nbr") or "-")
        num = str(it.get("number") or "-")
        combo = str(it.get("combination") or "-")
        lines.append("%s  %s  [%s]" % (nbr.ljust(nbr_w), num.ljust(num_w), combo))
    return "\n".join(lines)


# ---------- 持久化 ----------

class PushCounter:
    """push_count.json 持久化（last_issue / push_count / last_push_at）。"""

    def __init__(self, path):
        self.path = path
        self._lock = threading.Lock()
        self._data = {"last_issue": "", "push_count": 0, "last_push_at": 0}
        self._load()

    def _load(self):
        if not self.path or not os.path.exists(self.path):
            return
        try:
            with open(self.path, "r", encoding="utf-8") as f:
                d = json.load(f)
            if isinstance(d, dict):
                self._data["last_issue"] = str(d.get("last_issue") or "")
                self._data["push_count"] = int(d.get("push_count") or 0)
                self._data["last_push_at"] = int(d.get("last_push_at") or 0)
        except Exception:
            pass

    def _save_locked(self):
        if not self.path:
            return
        try:
            d = os.path.dirname(self.path)
            if d:
                os.makedirs(d, exist_ok=True)
            tmp = self.path + ".tmp"
            with open(tmp, "w", encoding="utf-8") as f:
                json.dump(self._data, f, ensure_ascii=False, indent=2)
            os.replace(tmp, self.path)
        except Exception:
            pass

    def get(self):
        with self._lock:
            return dict(self._data)

    def record(self, issue_nbr):
        """记录一次成功推送：更新 last_issue / push_count++ / last_push_at。"""
        if not issue_nbr:
            return
        with self._lock:
            self._data["last_issue"] = str(issue_nbr)
            self._data["push_count"] = int(self._data.get("push_count") or 0) + 1
            self._data["last_push_at"] = _now_ms()
            self._save_locked()

    def init_last_issue(self, issue_nbr):
        """首次启动：把 last_issue 初始化为当前期号（不计入 push_count）。"""
        if not issue_nbr:
            return
        with self._lock:
            if not self._data.get("last_issue"):
                self._data["last_issue"] = str(issue_nbr)
                self._save_locked()


# ---------- 主循环 ----------

class LotteryPusher:
    """开奖主循环（独立线程）。

    启动流程严格按 开奖接口.md：
      拉倒计时 → 解析秒数 → 首次启动初始化 last_issue
      → sleep(countdown_sec+1) → sleep(3)
      → 拉当前期号 → 拉最新结果 → 去重
      → 是则跳过推送；否则生成文本 → 串行推送 → 写盘 → 循环

    串行推送：每个目标群调用一次 send_func（每个 send_func 内部已是异步安全）。
    """

    def __init__(self, client=None, counter=None, target_gids=None,
                 send_func=None, logger=None, source_tag="PC28 开奖",
                 history_follow_n=20, history_follow_delay=1):
        """send_func(gid, text) -> None；线程安全即可（用 ws_send 的 send_msg）。

        history_follow_n: 每期开奖推送后，附带推送最近 N 期历史表格（0 表示关闭）。
        history_follow_delay: 推送开奖后等待多少秒再推历史（默认 1 秒）。
        """
        self.client = client or LotteryClient()
        self.counter = counter or PushCounter("logs/runtime/push_count.json")
        self.target_gids = list(target_gids or [])
        self.send_func = send_func or (lambda gid, text: None)
        self.logger = logger or (lambda msg: None)
        self.source_tag = source_tag
        self.history_follow_n = max(0, int(history_follow_n or 0))
        self.history_follow_delay = max(0, int(history_follow_delay or 0))
        self._stop = False
        self._thread = None
        self._push_count_today = 0
        self._initialised = False   # 实例级：每个 pusher 独立

    # ---------- 生命周期 ----------
    def start(self):
        if self._thread is not None and self._thread.is_alive():
            return
        self._stop = False
        self._thread = threading.Thread(target=self._run, daemon=True, name="lottery-pusher")
        self._thread.start()

    def stop(self):
        self._stop = True

    def set_targets(self, gids):
        self.target_gids = list(gids or [])

    # ---------- 主循环 ----------
    def _run(self):
        while not self._stop:
            try:
                self._cycle()
            except Exception as e:
                self.logger("[lottery] 循环异常: %s" % e)
            if not self._stop:
                time.sleep(5)

    def _cycle(self):
        """单轮主循环（按 spec 实现）。"""
        # 1) 拉倒计时
        try:
            items, countdown_text = self.client.fetch_recent(1)
        except Exception as e:
            self.logger("[lottery] fetch_countdown 失败: %s" % e)
            time.sleep(15)
            return
        countdown_sec = parse_countdown(countdown_text or "")
        if countdown_sec is None:
            countdown_sec = 180
        # 2) 首次启动：仅初始化 last_issue，不参与本轮推送
        if not self._initialised:
            if items:
                self.counter.init_last_issue(items[0]["nbr"])
            self._initialised = True
            self.logger("[lottery] 首次启动，初始化 last_issue=%s（跳过本轮推送）"
                        % (items[0]["nbr"] if items else "-"))
            return
        # 3) sleep(countdown_sec + 1)
        if not self._sleep(countdown_sec + 1):
            return
        # 4) sleep(3)
        if not self._sleep(3):
            return
        # 5) 拉当前期号 + 6) 拉最新结果
        try:
            data, _cd2 = self.client.fetch_recent(1)
        except Exception as e:
            self.logger("[lottery] fetch_recent 失败: %s" % e)
            return
        if not data:
            return
        latest = data[0]
        # 7) 去重判断
        cur = self.counter.get()
        if cur.get("last_issue") and cur["last_issue"] == latest["nbr"]:
            self.logger("[lottery] 已推送过 %s，跳过" % latest["nbr"])
            return
        # 8) 生成文本 + 9) 串行推送
        text = format_push(latest, source=self.source_tag)
        for gid in self.target_gids:
            if self._stop:
                break
            try:
                self.send_func(gid, text)
            except Exception as e:
                self.logger("[lottery] 推送到 %s 失败: %s" % (gid, e))
            time.sleep(0.4)
        # 9.5) 开奖后附带：等待 N 秒再推送历史 N 期表格
        if self.history_follow_n > 0 and self.history_follow_delay > 0:
            if not self._sleep(self.history_follow_delay):
                return  # 等待期间被停止
            hist = fetch_recent_safe(self.client, self.history_follow_n, logger=self.logger)
            if hist:
                hist_text = format_recent(hist, n=self.history_follow_n)
                for gid in self.target_gids:
                    if self._stop:
                        break
                    try:
                        self.send_func(gid, hist_text)
                    except Exception as e:
                        self.logger("[lottery] 历史补推 %s 失败: %s" % (gid, e))
                    time.sleep(0.4)
                self.logger("[lottery] 已补推最近 %d 期历史表格" % self.history_follow_n)
        # 10) 更新 last_issue + push_count + 写盘
        self.counter.record(latest["nbr"])
        self._push_count_today += 1
        self.logger("[lottery] 已推送 %s，push_count=%d" %
                    (latest["nbr"], self.counter.get().get("push_count") or 0))

    def _sleep(self, sec):
        """可中断 sleep。"""
        end = time.time() + max(0, int(sec))
        while time.time() < end and not self._stop:
            time.sleep(0.5)
        return not self._stop


# ---------- 历史查询辅助 ----------

def fetch_recent_safe(client, nbr, logger=None):
    """安全封装 fetch_recent（异常时返回空列表 + 日志）。"""
    try:
        data, _cd = client.fetch_recent(nbr)
        return data or []
    except Exception as e:
        if logger:
            logger("[lottery] fetch_recent(%d) 失败: %s" % (nbr, e))
        return []