"""V2 asyncio 群管理运行时 — 已精简为骨架。

原消息流水线（监测/关键词/名单/抽奖/定时/欢迎/净化等）已全部移除。
现仅保留：事件循环、消息去重、入群事件日志、按群缓存元信息、占位发送接口。

新业务逻辑请在此基础上重新添加。
"""
import asyncio
import time


DEFAULT_ACTION_COOLDOWN_MS = 3000


def _now_ms():
    return int(time.time() * 1000)


class BoundedLRU:
    """有界 LRU 集合（消息去重）。"""

    def __init__(self, maxsize=100000):
        self.maxsize = maxsize
        self._d = {}

    def add(self, key):
        self._d[key] = _now_ms()
        if len(self._d) > self.maxsize:
            cutoff = _now_ms() - 3600000
            stale = [k for k, t in self._d.items() if t < cutoff]
            for k in stale:
                self._d.pop(k, None)
            while len(self._d) > self.maxsize:
                self._d.pop(next(iter(self._d)), None)

    def contains(self, key):
        return key in self._d


class Runtime:
    """骨架运行时：负责事件循环、消息去重、按群元信息缓存、开奖主循环。"""

    def __init__(self, config, store, async_api=None, send_func=None, logger=None):
        self.config = config
        self.store = store
        self.api = async_api
        self.send_func = send_func or (lambda gid, text: None)
        self.logger = logger or (lambda msg: None)
        self.seen = BoundedLRU()
        self.loop = None
        self._thread = None
        self._stop = False
        # 心跳 ws 状态提供器：由 runner 注入（默认 True=进程存活）
        self.heartbeat_ws_ok = None
        # 群元信息缓存：仅记录 name / owner
        self._group_meta = {}
        # 开奖推送器（由 runner 注入；未注入则为 None）
        self.lottery_pusher = None

    # ---------- 生命周期 ----------
    def start(self):
        self._stop = False
        import threading
        self._thread = threading.Thread(target=self._run_loop, daemon=True)
        self._thread.start()

    def stop(self):
        self._stop = True
        if self.lottery_pusher is not None:
            try:
                self.lottery_pusher.stop()
            except Exception:
                pass
        if self.loop is not None:
            try:
                self.loop.call_soon_threadsafe(self.loop.stop)
            except Exception:
                pass

    def _run_loop(self):
        asyncio.set_event_loop(asyncio.new_event_loop())
        self.loop = asyncio.get_event_loop()
        self.loop.create_task(self._background_tasks())
        self.loop.run_forever()

    async def _background_tasks(self):
        """后台周期：落盘 + 写心跳。"""
        save_interval = int(self.config.get("state", {}).get("save_interval_ms", 30000))
        while not self._stop:
            try:
                self.store.save()
            except Exception:
                pass
            try:
                self._write_heartbeat()
            except Exception:
                pass
            await asyncio.sleep(min(15, save_interval / 1000))

    def _write_heartbeat(self):
        """写心跳文件（供看门狗检测）。格式：ms,ws_ok。"""
        import os
        try:
            path = getattr(self.store, "_path", None) or "logs/runtime/state.json"
            hb = os.path.join(os.path.dirname(os.path.abspath(path)), "heartbeat")
            ws_ok = 1
            if self.heartbeat_ws_ok is not None:
                try:
                    ws_ok = 1 if self.heartbeat_ws_ok() else 0
                except Exception:
                    ws_ok = 0
            with open(hb, "w", encoding="utf-8") as f:
                f.write("%d,%d" % (int(time.time() * 1000), ws_ok))
        except Exception:
            pass

    # ---------- 消息入口 ----------
    def on_message(self, msg):
        """消息入口（监听线程调用，线程安全）。骨架版只记录日志，不做业务处理。"""
        if not isinstance(msg, dict):
            return
        gid = msg.get("groupID") or msg.get("gid") or ""
        send_id = str(msg.get("sendID") or "")
        server_id = msg.get("serverMsgID") or msg.get("clientMsgID") or ""
        if server_id:
            if self.seen.contains(server_id):
                return
            self.seen.add(server_id)
        content_type = msg.get("contentType") or 0
        self.logger("[ws] 收到 gid=%s sendID=%s ctype=%s" % (gid, send_id, content_type))

    # ---------- 群元信息 ----------
    def set_group_meta(self, gid, name, owner, members=None):
        """仅缓存 name/owner，members 字段保留以兼容旧调用（不使用）。"""
        self._group_meta[gid] = {"name": name, "owner": owner, "members": members or {}}

    def group_members_loaded(self, gid):
        return bool(self._group_meta.get(gid, {}).get("members"))

    def set_batch_busy(self, gid, busy):
        """占位：保留方法签名以兼容旧 runner 调用。"""
        pass

    def is_batch_busy(self, gid):
        return False

    def on_group_member_join(self, gid, new_members):
        """占位：保留方法签名以兼容旧 runner 调用。"""
        pass

    # ---------- 开奖推送辅助 ----------
    def refresh_lottery_targets(self):
        """从 store 读取订阅群，重新设置 lottery_pusher 目标。"""
        if self.lottery_pusher is None:
            return []
        gids = self.store.lottery_subscribers() if self.store is not None else []
        self.lottery_pusher.set_targets(gids)
        self.logger("[runtime] 开奖推送目标已刷新：%d 个群" % len(gids))
        return gids
