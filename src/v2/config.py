"""V2 配置加载：单一配置源，默认值合并。

已精简：仅保留最小配置（permissions + state + logging + version）。
所有业务相关（monitors / license / safety / queue）已移除，新功能按需添加。
"""
import json
import os

DEFAULT_CONFIG = {
    "version": "2.0.0",
    "robot": {
        "name": "lajiao-group-bot",
        "super_admin": "",
        "self_account_id": "",
        "ignore_self_message": True,
        "ignore_system_message": True,
    },
    "permissions": {"superAdminIds": [], "groupAdmins": {}},
    "state": {"path": "logs/runtime/state.json", "save_interval_ms": 30000},
    "logging": {"dir": "logs/runtime", "max_bytes": 20971520, "max_files": 10},
}


def _deep_merge(base, override):
    out = dict(base)
    for k, v in (override or {}).items():
        if isinstance(v, dict) and isinstance(out.get(k), dict):
            out[k] = _deep_merge(out[k], v)
        else:
            out[k] = v
    return out


def load_config(path=None):
    """加载配置（默认值 + 可选外部 json）。"""
    cfg = _deep_merge({}, DEFAULT_CONFIG)
    if path and os.path.exists(path):
        try:
            with open(path, "r", encoding="utf-8") as f:
                user = json.load(f)
            cfg = _deep_merge(cfg, user)
        except Exception:
            pass
    perms = cfg.get("permissions", {})
    admins = []
    sa = perms.get("superAdminIds") or cfg.get("robot", {}).get("super_admin") or ""
    if isinstance(sa, str) and sa:
        admins = [sa]
    elif isinstance(sa, list):
        admins = [str(x) for x in sa]
    perms["superAdminIds"] = admins
    return cfg


def super_admins(config):
    return [str(x) for x in config.get("permissions", {}).get("superAdminIds", [])]


def is_super_admin(config, user_id):
    uid = str(user_id or "")
    return bool(uid) and uid in super_admins(config)
