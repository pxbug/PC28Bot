"""指令解析 + 执行（精简：GM / 启动 / 关闭）。

parse_command(text):
  - 返回 dict {name, args} 或 None
  - name ∈ {"GM", "ENABLE_PC28", "DISABLE_PC28"}

execute(config, store, gid, sender_id, text, ...):
  - 仅当 sender_id 为超管且命中已知指令时返回 {"reply": str}
  - 非超管或未命中 → 返回 {"reply": None}

辅助：
  - is_super_admin(config, sender_id)：判断超管（从 config.permissions.superAdminIds）
"""
from .menu import render_gm_menu


# 启动 / 关闭关键字集合（不区分大小写、去前后空格）
ENABLE_KEYWORDS = {"1", "启动", "开", "开启", "start", "on"}
DISABLE_KEYWORDS = {"2", "关闭", "关", "停止", "停", "stop", "off"}
GM_KEYWORDS = {"gm", "菜单", "管理", "menu"}


def _norm(text):
    if text is None:
        return ""
    return str(text).strip().lower()


def is_super_admin(config, sender_id):
    """判断 sender_id 是否为超管。"""
    if not sender_id:
        return False
    perms = (config or {}).get("permissions") or {}
    admins = perms.get("superAdminIds") or []
    if not admins:
        # 兼容旧字段 robot.super_admin
        sa = (config or {}).get("robot", {}).get("super_admin") or ""
        if sa:
            admins = [sa]
    uid = str(sender_id).strip()
    return any(uid == str(a).strip() for a in admins)


def parse_command(text):
    """识别 GM / 启动 / 关闭，返回 {name, args} 或 None。

    返回：
      {"name": "GM", "args": ""}
      {"name": "ENABLE_PC28", "args": ""}
      {"name": "DISABLE_PC28", "args": ""}
      None
    """
    s = _norm(text)
    if not s:
        return None
    if s in GM_KEYWORDS:
        return {"name": "GM", "args": ""}
    if s in ENABLE_KEYWORDS:
        return {"name": "ENABLE_PC28", "args": ""}
    if s in DISABLE_KEYWORDS:
        return {"name": "DISABLE_PC28", "args": ""}
    return None


def execute(config, store, gid, sender_id, text, at_user_id=None, member_name=None,
            daily_count=None, resolve=None):
    """执行指令。非超管或不识别 → {"reply": None}。

    返回：
      {"reply": str} 命中指令
      {"reply": None} 忽略
    """
    cmd = parse_command(text)
    if cmd is None:
        return {"reply": None}
    if not is_super_admin(config, sender_id):
        return {"reply": None}
    name = cmd["name"]
    if name == "GM":
        return {"reply": render_gm_menu()}
    if name == "ENABLE_PC28":
        try:
            store.set_pc28_push_enabled(gid, True)
        except Exception as e:
            return {"reply": "开启失败：%s" % e}
        return {"reply": "✅ 已开启本群开奖推送"}
    if name == "DISABLE_PC28":
        try:
            store.set_pc28_push_enabled(gid, False)
        except Exception as e:
            return {"reply": "关闭失败：%s" % e}
        return {"reply": "✅ 已关闭本群开奖推送"}
    return {"reply": None}
