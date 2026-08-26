"""V2 指令系统。

仅保留 3 个指令 + GM 超管菜单：
- 当前期号              查询最新 1 期开奖
- 历史开奖 [N]          查询最近 N 期（默认 20，最多 100）
- 启动本群              超管专属：启用本群机器人
- GM                  超管菜单
"""
import re


# ---------- 命令解析 ----------

RE_GM = re.compile(r"^GM\s*$", re.IGNORECASE)
RE_MENU = re.compile(r"^(菜单|menu|help)\s*$", re.IGNORECASE)
RE_KJ = re.compile(r"^(开奖|当前期号|当前)\s*$", re.IGNORECASE)
RE_HISTORY = re.compile(r"^历史(?:开奖)?(?:\s+(\d{1,3}))?\s*$", re.IGNORECASE)
RE_HISTORY2 = re.compile(r"^历史(?:开奖)?(\d{1,3})\s*$", re.IGNORECASE)
RE_START_GROUP = re.compile(r"^启动本群\s*$", re.IGNORECASE)


def parse_command(text):
    """轻量解析：返回 dict 或 None。"""
    if not text or not isinstance(text, str):
        return None
    s = text.strip()
    if not s:
        return None
    m = RE_GM.match(s)
    if m:
        return {"cmd": "gm"}
    m = RE_MENU.match(s)
    if m:
        return {"cmd": "menu"}
    m = RE_KJ.match(s)
    if m:
        return {"cmd": "kj"}
    m = RE_HISTORY.match(s) or RE_HISTORY2.match(s)
    if m:
        n = int(m.group(1)) if m.group(1) else 20
        return {"cmd": "history", "n": max(1, min(100, n))}
    m = RE_START_GROUP.match(s)
    if m:
        return {"cmd": "start_group"}
    return None


# ---------- 帮助 ----------

HELP_TEXT = (
    "━━━━━━━━━━━━━━━\n"
    "🎱 当前期号\n"
    "📜 历史开奖\n"
    "━━━━━━━━━━━━━━━"
)


SUPER_HELP_TEXT = (
    "━━━━━━━━━━━━━━━\n"
    "🎱 当前期号\n"
    "📜 历史开奖\n"
    "🚀 启动本群\n"
    "━━━━━━━━━━━━━━━"
)


# ---------- 执行入口 ----------

def execute(config, store, gid, sender_id, text, at_user_id=None, member_name=None,
            daily_count=None, resolve=None):
    """执行指令。"""
    parsed = parse_command(text)
    if parsed is None:
        return {"reply": None}

    cmd = parsed["cmd"]
    sender = str(sender_id or "")

    # GM：所有人可见，显示超级菜单
    if cmd == "gm":
        return {"reply": SUPER_HELP_TEXT}

    # 菜单：所有人可见
    if cmd == "menu":
        return {"reply": HELP_TEXT}

    # 其余：必须有 store 才能查询
    if store is None:
        return {"reply": "❌ 状态存储未初始化"}

    # 启动本群：仅超管
    if cmd == "start_group":
        from . import config as _cfg
        if not _cfg.is_super_admin(config, sender):
            return {"reply": "❌ 仅超级管理员可执行此操作"}
        store.set_enabled(gid, True)
        return {"reply": "✅ 已启动本群机器人", "refresh_lottery": True}

    # 获取 lottery 客户端
    client, err = _get_lottery_client(config)

    if cmd == "kj":
        return _cmd_kj(client, err)
    if cmd == "history":
        return _cmd_history(client, parsed["n"], err)
    return {"reply": None}


# ---------- 命令实现 ----------

def _cmd_kj(client, err=None):
    if client is None:
        return {"reply": err or "❌ 开奖 API 未配置"}
    from .lottery import fetch_recent_safe, format_recent
    data = fetch_recent_safe(client, 1)
    if not data:
        return {"reply": "❌ 开奖接口暂时不可用，请稍后重试"}
    return {"reply": format_recent(data, n=1, title="🎰 最新开奖")}


def _cmd_history(client, n, err=None):
    if client is None:
        return {"reply": err or "❌ 开奖 API 未配置"}
    if n > 100:
        n = 100
    from .lottery import fetch_recent_safe, format_recent
    data = fetch_recent_safe(client, n)
    if not data:
        return {"reply": "❌ 开奖接口暂时不可用，请稍后重试"}
    return {"reply": format_recent(data, n=n)}


# ---------- 客户端获取 ----------

def _get_lottery_client(config):
    """从 config 读取 API Key / base_url / game 构建客户端，缺失则返回 (None, 错误提示)。"""
    lc = (config or {}).get("lottery", {}) or {}
    if not lc.get("enabled", False):
        return None, "❌ 开奖 API 未启用（config.lottery.enabled=false）"
    api_key = lc.get("api_key") or ""
    if not api_key:
        return None, "❌ 开奖 API 未配置（缺少 api_key）"
    base_url = lc.get("base_url") or "https://yu28.top"
    game = lc.get("game") or "jnd28"
    timeout = int(lc.get("timeout") or 10)
    from .lottery import LotteryClient
    return LotteryClient(base_url=base_url, api_key=api_key, game=game, timeout=timeout), None
