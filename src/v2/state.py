"""V2 群状态存储 — 已精简为骨架。

仅保留：JSON 加载/保存、线程安全读写、按 gid 取群对象。
所有业务字段（使用期/名单/关键词/监测/抽奖/定时/欢迎/名片/违规/今日发言）已移除。

新增业务字段可在 GroupStateStore 内按需扩展。
"""
import json
import os
import threading
import time


def _now_ms():
    return int(time.time() * 1000)


def _default_group():
    """默认群结构：启用 + 开奖订阅标记（lottery_push_enabled）。"""
    return {
        "enabled": True,
        "lottery_push_enabled": False,
    }


class GroupStateStore:
    def __init__(self, path=None, save_interval_ms=30000):
        self._path = path
        self._save_interval = max(1000, int(save_interval_ms or 30000))
        self._lock = threading.RLock()
        self._groups = {}
        self._dirty = False
        self._last_save = 0
        self._load()

    # ---------- 内部 ----------
    def _load(self):
        if not self._path or not os.path.exists(self._path):
            return
        try:
            with open(self._path, "r", encoding="utf-8") as f:
                data = json.load(f)
            loaded = data.get("groups", {}) if isinstance(data, dict) else {}
            if isinstance(loaded, dict):
                self._groups = {}
                for gid, g in loaded.items():
                    if not isinstance(g, dict):
                        continue
                    base = _default_group()
                    base.update({k: v for k, v in g.items() if k in base})
                    self._groups[gid] = base
        except Exception:
            self._groups = {}

    def save(self, force=False):
        if not self._path:
            return
        with self._lock:
            if not force and not self._dirty:
                return
            now = _now_ms()
            if not force and now - self._last_save < self._save_interval:
                return
            try:
                d = os.path.dirname(self._path)
                if d:
                    os.makedirs(d, exist_ok=True)
                tmp = self._path + ".tmp"
                with open(tmp, "w", encoding="utf-8") as f:
                    json.dump({"version": 2, "groups": self._groups}, f, ensure_ascii=False)
                os.replace(tmp, self._path)
                self._dirty = False
                self._last_save = now
            except Exception:
                pass

    def _touch(self):
        self._dirty = True

    def _group(self, gid):
        with self._lock:
            g = self._groups.get(gid)
            if g is None:
                g = _default_group()
                self._groups[gid] = g
            return g

    def group_ids(self):
        with self._lock:
            return list(self._groups.keys())

    def snapshot(self):
        """看板用：每群状态快照。"""
        with self._lock:
            out = {}
            for gid, g in self._groups.items():
                out[gid] = {
                    "enabled": bool(g.get("enabled", True)),
                    "lottery_push_enabled": bool(g.get("lottery_push_enabled", False)),
                }
            return out

    # ---------- 启用开关 ----------
    def set_enabled(self, gid, enabled):
        g = self._group(gid)
        with self._lock:
            g["enabled"] = bool(enabled)
            self._touch()

    def is_group_active(self, gid, now=None):
        """骨架版：仅看 enabled 标志。"""
        g = self._group(gid)
        with self._lock:
            return bool(g.get("enabled", True))

    # ---------- 开奖订阅 ----------
    def lottery_push_set(self, gid, enabled):
        """开启/关闭某群的开奖自动推送订阅。"""
        g = self._group(gid)
        with self._lock:
            g["lottery_push_enabled"] = bool(enabled)
            self._touch()

    def lottery_push_enabled(self, gid):
        g = self._group(gid)
        with self._lock:
            return bool(g.get("lottery_push_enabled", False))

    def lottery_subscribers(self):
        """返回所有订阅了开奖推送的群 ID。"""
        with self._lock:
            return [gid for gid, g in self._groups.items()
                    if bool(g.get("enabled", False))]
