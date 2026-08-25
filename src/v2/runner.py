"""V2 运行器：配置 → 状态 → 运行时 → WS 监听/发送 → 群元信息。

已精简：移除 _ad_escalation / _spam_escalation / 一键进退群 / 自动导出 /
名片净化 / 清理死人 / 抽奖 / 定时任务 等业务钩子，仅保留传输/接入层。
"""
import os
import time
import threading


def _config_path():
    import sys as _sys
    here = os.path.dirname(os.path.abspath(__file__))
    local = os.path.normpath(os.path.join(os.getcwd(), "config", "robot.config.json"))
    candidates = [local]
    base = getattr(_sys, "_MEIPASS", None)
    if base:
        candidates.append(os.path.join(base, "config", "robot.config.json"))
    candidates.append(os.path.join(here, "..", "..", "config", "robot.config.json"))
    candidates.append(os.path.join(here, "..", "config", "robot.config.json"))
    for p in candidates:
        if os.path.exists(p):
            return p
    return None


def _writable_config_path():
    p = os.path.normpath(os.path.join(os.getcwd(), "config", "robot.config.json"))
    d = os.path.dirname(p)
    if d:
        try:
            os.makedirs(d, exist_ok=True)
        except Exception:
            pass
    return p


class V2Runner:
    def __init__(self, logger=None):
        self.logger = logger or (lambda msg: print(msg))
        from . import config as cfg
        from .state import GroupStateStore
        self.cfg_mod = cfg
        self.config = cfg.load_config(_config_path())
        self.store = GroupStateStore(
            path=self.config.get("state", {}).get("path", "logs/runtime/state.json"),
            save_interval_ms=int(self.config.get("state", {}).get("save_interval_ms", 30000)),
        )
        self.client = None
        self.api = None
        self.runtime = None
        self.listener = None
        self.send_conn = None
        self.lottery_worker = None
        self._stop = False
        self._start_ts = time.time()
        self._member_refresh_lock = threading.Lock()
        self._last_member_refresh = {}

    def login_from_ini(self, ini_path="lajiao_bot.ini"):
        from api_client import ApiClient
        self.client = ApiClient(ini_path)
        self.config["robot"]["self_account_id"] = self.client.user_id
        if self.client.user_id and self.client.im_token:
            self.logger("[runner] 已加载登录态 user_id=%s" % self.client.user_id)
        if not self.cfg_mod.super_admins(self.config):
            self.logger("[warn] 未配置超级管理员 super_admin，请在 config/robot.config.json 设置")

    def set_super_admin(self, uid):
        """写入本地配置：设置超级管理员。"""
        uid = str(uid or "").strip()
        if not uid:
            return False
        path = _writable_config_path()
        import json
        data = {}
        if path and os.path.exists(path):
            try:
                with open(path, "r", encoding="utf-8") as f:
                    data = json.load(f)
            except Exception:
                data = {}
        perms = data.setdefault("permissions", {})
        perms["superAdminIds"] = [uid]
        try:
            with open(path, "w", encoding="utf-8") as f:
                json.dump(data, f, ensure_ascii=False, indent=2)
            self.config = self.cfg_mod.load_config(path)
            return True
        except Exception as e:
            self.logger("[runner] 写入超管配置失败: %s" % e)
            return False

    def _ensure_api(self):
        if self.api is None and self.client is not None:
            from .api import AsyncApi
            self.api = AsyncApi(self.client)
        return self.api

    def _refresh_group_meta(self):
        """拉取群列表 + 群主；不加载成员。"""
        if self.client is None:
            return
        try:
            groups = self.client.get_group_list()
        except Exception as e:
            self.logger("[runner] 获取群列表失败: %s" % e)
            return
        for g in groups:
            gid = g.get("groupID", "")
            if not gid:
                continue
            self.runtime.set_group_meta(gid, g.get("groupName", "") or gid, g.get("ownerUserID", "") or "")
        return len(groups)

    def start(self):
        self._ensure_api()
        from .runtime import Runtime
        from message_listener import MessageListener
        import ws_send  # noqa

        self.runtime = Runtime(self.config, self.store, self.api, send_func=self._ws_send, logger=self.logger)
        self.runtime.start()

        group_count = self._refresh_group_meta()

        self.listener = MessageListener(self.client.user_id, self.client.im_token, platform=5)
        from ws_send import WsSendConn
        self.send_conn = WsSendConn(self.client.user_id, self.client.im_token, platform=5)
        self.send_conn.start()
        self._start_health_monitor()
        self._start_heartbeat()

        self.listener.on_message = self._on_ws
        self.listener.on_error = lambda e: self.logger("[ws] %s" % e)
        self.listener.on_connected = lambda: self.logger("[ws] 已连接")
        self.listener.start()
        self.runtime.heartbeat_ws_ok = lambda: (
            self.listener is not None and getattr(self.listener, "_ws", None) is not None)
        self.logger("[runner] 启动完成，群数=%s" % (group_count or 0))

        # PC28 开奖抓取 Worker（后台线程，独立于 WS 生命周期）
        try:
            from pc28.worker import start_lottery_worker
            _t, self.lottery_worker = start_lottery_worker(
                self, send_func=self._ws_send, logger=self.logger,
            )
        except Exception as e:
            self.logger("[runner] PC28 worker 启动失败: %s" % e)

    def _start_heartbeat(self):
        """每 30 秒写心跳文件（含 WS 连接状态），供看门狗检测卡死/断线。"""
        def run():
            import os as _os
            while not self._stop:
                try:
                    ws_ok = self.listener is not None and getattr(self.listener, "_ws", None) is not None
                    hb_path = _os.path.join(_os.getcwd(), "logs", "runtime", "heartbeat")
                    try:
                        _os.makedirs(_os.path.dirname(hb_path), exist_ok=True)
                    except Exception:
                        pass
                    with open(hb_path, "w", encoding="utf-8") as f:
                        f.write("%d,%d" % (int(time.time() * 1000), 1 if ws_ok else 0))
                except Exception:
                    pass
                time.sleep(30)
        try:
            t = threading.Thread(target=run, daemon=True)
            t.start()
        except Exception:
            pass

    def _on_ws(self, data):
        """监听器原始帧 → runtime。"""
        if not isinstance(data, dict):
            return
        if data.get("type") == "connected":
            return
        try:
            from message_normalizer import normalize_ws_data, to_msg_dict
            msgs, _notifs = normalize_ws_data(data)
            for m in msgs:
                md = to_msg_dict(m)
                self.logger("[ws] 收到 gid=%s sendID=%s ctype=%s content=%r" % (
                    md.get("groupID"), md.get("sendID"), md.get("contentType"),
                    (md.get("content") or "")[:40]))
                self.runtime.on_message(md)
        except Exception as e:
            self.logger("[ws] 解码失败: %s" % e)

    def _start_health_monitor(self):
        """每小时上报运行健康状态。"""
        def run():
            while not self._stop:
                try:
                    uptime_h = (time.time() - self._start_ts) / 3600
                    ws_ok = self.listener is not None and getattr(self.listener, "_ws", None) is not None
                    send_ok = self.send_conn is not None and getattr(self.send_conn, "_loop", None) is not None and self.send_conn._loop.is_running()
                    seen = len(self.runtime.seen._d) if self.runtime is not None else 0
                    groups = len(self.store.group_ids()) if self.store is not None else 0
                    self.logger("[health] 运行 %.1f 小时 | 接收连接=%s 发送通道=%s | 去重表=%d 群数=%d"
                                % (uptime_h, "OK" if ws_ok else "断开", "OK" if send_ok else "异常",
                                   seen, groups))
                except Exception:
                    pass
                time.sleep(3600)
        try:
            t = threading.Thread(target=run, daemon=True)
            t.start()
        except Exception:
            pass

    def _ws_send(self, gid, text):
        self.logger("[send] 尝试发送 %s: %r" % (gid, (text or "")[:50]))
        gname = self.runtime._group_meta.get(gid, {}).get("name", gid)
        if self.send_conn is not None:
            self.send_conn.send_msg("sg_" + gid, text, group_name=gname)
        elif self.client is not None:
            self.client.send_msg(gid, text)

    def stop(self):
        self._stop = True
        # PC28 worker 先停（不再发起新推送）
        try:
            if self.lottery_worker is not None:
                self.lottery_worker.stop()
        except Exception:
            pass
        if self.runtime is not None:
            self.runtime.stop()
        if self.listener is not None:
            try:
                self.listener.stop()
            except Exception:
                pass
        if self.send_conn is not None:
            try:
                self.send_conn.stop()
            except Exception:
                pass


def run():
    runner = V2Runner()
    runner.login_from_ini()
    runner.start()
    try:
        while True:
            time.sleep(1)
    except KeyboardInterrupt:
        runner.stop()


if __name__ == "__main__":
    run()
