"""V2 指令系统。

已实现指令：
- 开奖                查询最近 1 期开奖
- 历史 [N]            查询最近 N 期（默认 20，最多 100）
- 开奖查询 <期号>     按期号查（字符串模糊匹配前 N 条）
- 开启开奖推送 / 关闭开奖推送  订阅/取消订阅本群自动推送
- 开奖状态            查看当前推送统计（last_issue / push_count）
- 启动本群 / 关闭本群  超管专属：启用 / 停用本群机器人
- menu / 菜单 / GM    帮助（GM 显示含超管命令的完整菜单）

保留占位：parse_command() 用于未来扩展。
"""
import re


# ---------- 命令解析 ----------

RE_MENU = re.compile(r"^(菜单|menu|help)\s*$", re.IGNORECASE)
RE_GM = re.compile(r"^GM\s*$", re.IGNORECASE)
RE_KJ = re.compile(r"^(开奖|当前期号|当前)\s*$", re.IGNORECASE)
RE_HISTORY = re.compile(r"^历史(?:开奖)?(?:\s+(\d{1,3}))?\s*$", re.IGNORECASE)
RE_HISTORY2 = re.compile(r"^历史(?:开奖)?(\d{1,3})\s*$", re.IGNORECASE)
RE_KJ_QUERY = re.compile(r"^开奖查询\s+(\S+)\s*$", re.IGNORECASE)
RE_KJ_PUSH_ON = re.compile(r"^(开启开奖推送|开启推送)\s*$", re.IGNORECASE)
RE_KJ_PUSH_OFF = re.compile(r"^(关闭开奖推送|关闭推送)\s*$", re.IGNORECASE)
RE_KJ_STATUS = re.compile(r"^(开奖状态|开奖统计)\s*$", re.IGNORECASE)
RE_START_GROUP = re.compile(r"^启动本群\s*$", re.IGNORECASE)
RE_STOP_GROUP = re.compile(r"^关闭本群\s*$", re.IGNORECASE)


def parse_command(text):
    """轻量解析：返回 dict 或 None。"""
    if not text or not isinstance(text, str):
        return None
    s = text.strip()
    if not s:
        return None
    m = RE_MENU.match(s)
    if m:
        return {"cmd": "menu"}
    m = RE_GM.match(s)
    if m:
        return {"cmd": "gm"}
    m = RE_KJ.match(s)
    if m:
        return {"cmd": "kj"}
    m = RE_HISTORY.match(s) or RE_HISTORY2.match(s)
    if m:
        n = int(m.group(1)) if m.group(1) else 20
        return {"cmd": "history", "n": max(1, min(100, n))}
    m = RE_KJ_QUERY.match(s)
    if m:
        return {"cmd": "kj_query", "nbr": m.group(1)}
    m = RE_KJ_PUSH_ON.match(s)
    if m:
        return {"cmd": "kj_push_on"}
    m = RE_KJ_PUSH_OFF.match(s)
    if m:
        return {"cmd": "kj_push_off"}
    m = RE_KJ_STATUS.match(s)
    if m:
        return {"cmd": "kj_status"}
    m = RE_START_GROUP.match(s)
    if m:
        return {"cmd": "start_group"}
    m = RE_STOP_GROUP.match(s)
    if m:
        return {"cmd": "stop_group"}
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
    """执行指令。

    入参：
      config          完整配置 dict
      store           GroupStateStore
      gid             当前群 ID
      sender_id       发送者 userID
      text            原始消息文本
      at_user_id      若 @ 了成员则为其 ID
      member_name     发送者昵称（可选）
      daily_count     当日发言数（可选）
      resolve         (gid, uid) -> name 的解析函数

    返回：{"reply": str|None, "action": dict|None, "refresh_lottery": bool|None}
      - reply      要回复给发送者的文本
      - action     给 runtime 执行的副作用（如发送消息）
      - refresh_lottery  标记订阅变更，需要刷新 pusher 目标
    """
    parsed = parse_command(text)
    if parsed is None:
        return {"reply": None}

    cmd = parsed["cmd"]
    sender = str(sender_id or "")

    # 菜单：所有人可见
    if cmd == "menu":
        return {"reply": HELP_TEXT}

    # GM：所有人可见，显示超级菜单
    if cmd == "gm":
        return {"reply": SUPER_HELP_TEXT}

    # 其余：必须有 store 才能查询
    if store is None:
        return {"reply": "❌ 状态存储未初始化"}

    # 启动/关闭本群：仅超管
    if cmd in ("start_group", "stop_group"):
        from . import config as _cfg
        if not _cfg.is_super_admin(config, sender):
            return {"reply": "❌ 仅超级管理员可执行此操作"}
        if cmd == "start_group":
            store.set_enabled(gid, True)
            return {"reply": "✅ 已启动本群机器人", "refresh_lottery": True}
        store.set_enabled(gid, False)
        return {"reply": "⏹ 已关闭本群机器人", "refresh_lottery": True}

    # 获取 lottery 客户端（从 runner 注入到 store 的 _lottery_client 属性；不存在则按需创建）
    client = _get_lottery_client(config)

    if cmd == "kj":
        return _cmd_kj(client, store)
    if cmd == "history":
        return _cmd_history(client, parsed["n"])
    if cmd == "kj_query":
        return _cmd_kj_query(client, parsed["nbr"])
    if cmd == "kj_push_on":
        store.lottery_push_set(gid, True)
        return {"reply": "✅ 已开启本群开奖自动推送", "refresh_lottery": True}
    if cmd == "kj_push_off":
        store.lottery_push_set(gid, False)
        return {"reply": "✅ 已关闭本群开奖自动推送", "refresh_lottery": True}
    if cmd == "kj_status":
        return _cmd_kj_status(store)
    return {"reply": None}


# ---------- 命令实现 ----------

def _cmd_kj(client, store):
    if client is None:
        return {"reply": "❌ 开奖 API 未配置（缺少 API Key）"}
    from .lottery import fetch_recent_safe, format_recent
    data = fetch_recent_safe(client, 1)
    if not data:
        return {"reply": "❌ 开奖接口暂时不可用，请稍后重试"}
    return {"reply": format_recent(data, n=1, title="🎰 最新开奖")}


def _cmd_history(client, n):
    if client is None:
        return {"reply": "❌ 开奖 API 未配置（缺少 API Key）"}
    if n > 100:
        n = 100
    from .lottery import fetch_recent_safe, format_recent
    data = fetch_recent_safe(client, n)
    if not data:
        return {"reply": "❌ 开奖接口暂时不可用，请稍后重试"}
    return {"reply": format_recent(data, n=n)}


def _cmd_kj_query(client, nbr_query):
    if client is None:
        return {"reply": "❌ 开奖 API 未配置（缺少 API Key）"}
    from .lottery import fetch_recent_safe, format_recent
    data = fetch_recent_safe(client, 100)   # 拉满 100 期本地筛选
    if not data:
        return {"reply": "❌ 开奖接口暂时不可用，请稍后重试"}
    matched = [d for d in data if nbr_query in str(d.get("nbr") or "")]
    if not matched:
        return {"reply": "🔍 在最近 100 期中未找到期号 %s" % nbr_query}
    return {"reply": format_recent(matched, n=len(matched), title="🔍 查询结果（%s）" % nbr_query)}


def _cmd_kj_status(store):
    """显示推送统计：last_issue / push_count / last_push_at / 订阅群数。"""
    counter_path = "logs/runtime/push_count.json"
    from .lottery import PushCounter
    pc = PushCounter(counter_path)
    cur = pc.get()
    last_issue = cur.get("last_issue") or "-"
    push_count = int(cur.get("push_count") or 0)
    last_push_at = int(cur.get("last_push_at") or 0)
    if last_push_at > 0:
        import time as _t
        ago = int(_t.time() * 1000) - last_push_at
        ago_str = "%d 秒前" % (ago // 1000) if ago < 60000 else "%d 分钟前" % (ago // 60000)
    else:
        ago_str = "暂无"
    subs = store.lottery_subscribers() if store is not None else []
    lines = [
        "📊 开奖推送状态",
        "────────────",
        "上期推送：第 %s 期" % last_issue,
        "累计推送：%d 条" % push_count,
        "上次推送：%s" % ago_str,
        "订阅群数：%d 个" % len(subs),
    ]
    return {"reply": "\n".join(lines)}


# ---------- 客户端获取 ----------

def _get_lottery_client(config):
    """从 config 读取 API Key / base_url / game 构建客户端，缺失则 None。"""
    lc = (config or {}).get("lottery", {}) or {}
    if not lc.get("enabled", False):
        return None
    api_key = lc.get("api_key") or ""
    if not api_key:
        return None
    base_url = lc.get("base_url") or "https://yu28.top"
    game = lc.get("game") or "jnd28"
    timeout = int(lc.get("timeout") or 10)
    from .lottery import LotteryClient
    return LotteryClient(base_url=base_url, api_key=api_key, game=game, timeout=timeout)