"""V2 指令系统。

指令列表：
- 当前期号              查询最新 1 期开奖
- 历史开奖 [N]          查询最近 N 期（默认 20，最多 100）
- 启动本群              超管专属：启用本群机器人
- 余额                  查询当前余额
- 下注 玩法 金额        下注（如：大 100、小 50、单 20）
- GM                  超管菜单
"""
import re
from typing import Optional, Dict, Any, List, Tuple


# ---------- 下注赔率配置 ----------

# 赔率表（包含本金）
BET_ODDS = {
    # 大小单双
    '大': 2.0, '小': 2.0,
    '单': 2.0, '双': 2.0,
    # 组合
    '大单': 4.2, '小单': 4.6, '大双': 4.6, '小双': 4.2,
    # 极值
    '极大': 15.0, '极小': 15.0,
    # 形态
    '豹子': 66.0, '顺子': 15.0, '对子': 3.0,
    # 龙虎豹
    '龙': 2.85, '虎': 2.85, '豹': 2.85,
}

# 下注类型映射
BET_TYPE_MAP = {
    '大': 'dx', '小': 'dx',
    '单': 'dd', '双': 'dd',
    '大单': 'dxdd', '小单': 'dxdd', '大双': 'dxdd', '小双': 'dxdd',
    '极大': 'jd', '极小': 'jx',
    '豹子': 'bz', '顺子': 'sh', '对子': 'dz',
    '龙': 'lh', '虎': 'lh', '豹': 'lh',
}


# ---------- 命令解析 ----------

RE_GM = re.compile(r"^GM\s*$", re.IGNORECASE)
RE_MENU = re.compile(r"^(菜单|menu|help)\s*$", re.IGNORECASE)
RE_KJ = re.compile(r"^(开奖|当前期号|当前)\s*$", re.IGNORECASE)
RE_HISTORY = re.compile(r"^历史(?:开奖)?(?:\s+(\d{1,3}))?\s*$", re.IGNORECASE)
RE_HISTORY2 = re.compile(r"^历史(?:开奖)?(\d{1,3})\s*$", re.IGNORECASE)
RE_START_GROUP = re.compile(r"^启动本群\s*$", re.IGNORECASE)
RE_BALANCE = re.compile(r"^(余额|余额查询|我的余额)\s*$", re.IGNORECASE)

# 下注解析：支持多种格式
# 大 100 | 大100 | 大：100 | 大 100 小 50 | 大100单50
RE_BET = re.compile(r"^(下注|投注|bet)\s+", re.IGNORECASE)
RE_BET_PATTERN = re.compile(
    r"([大大小单双单双]+|[极极大小]+|[豹顺对]+|[龙虎豹]|[特码]|[0-9]{1,2})\s*[:：]?\s*(\d+(?:\.\d+)?)",
    re.IGNORECASE
)


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
    "💰 余额\n"
    "━━━━━━━━━━━━━━━"
)


SUPER_HELP_TEXT = (
    "━━━━━━━━━━━━━━━\n"
    "🎱 当前期号\n"
    "📜 历史开奖\n"
    "💰 余额\n"
    "🚀 启动本群\n"
    "━━━━━━━━━━━━━━━"
)


# ---------- 执行入口 ----------

def execute(config, store, gid, sender_id, text, at_user_id=None, member_name=None,
            daily_count=None, resolve=None, issue=None):
    """执行指令。

    Args:
        issue: 当前期号（用于下注时关联）
    """
    parsed = parse_command(text)
    if parsed is None:
        # 尝试解析下注格式（如：大 100）
        if _is_bet_text(text):
            return _cmd_bet(config, gid, sender_id, text, member_name, issue)
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

    # 余额查询
    if cmd == "balance":
        return _cmd_balance(config, sender, member_name)

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


def _get_admin_api(config):
    """从 config 读取 admin_api 配置，初始化全局客户端"""
    ac = (config or {}).get("admin_api", {}) or {}
    if not ac.get("enabled", False):
        return None
    try:
        import sys
        sys.path.insert(0, ".")
        from bot_api_client import init_client
        init_client(
            base_url=ac.get("base_url", "http://127.0.0.1:8080/api/bot/"),
            app_id=ac.get("app_id", ""),
            secret_key=ac.get("secret_key", ""),
            timeout=int(ac.get("timeout") or 10),
        )
        from bot_api_client import get_client
        return get_client()
    except Exception as e:
        # 静默失败
        return None


# ---------- 下注相关 ----------

def _is_bet_text(text: str) -> bool:
    """判断文本是否为下注指令"""
    if not text:
        return False
    s = text.strip()
    # 必须包含玩法关键词
    keywords = list(BET_ODDS.keys()) + ['大单', '小单', '大双', '小双']
    # 必须包含数字
    if not re.search(r"\d+", s):
        return False
    # 必须是玩法的开头的命令才返回true（其他以数字开头的是别的指令）
    return any(kw in s for kw in keywords)


def _parse_bets(text: str) -> Optional[List[Dict[str, Any]]]:
    """
    解析下注文本，返回下注列表

    支持格式：
    - 大 100
    - 大100 小50
    - 大：100 小：80
    - 大单 10 大双 20
    - 13 200 14 200
    - 极大 10 极小 10
    - 豹子 10 顺子 10

    Returns:
        [{"type": "dx", "content": "大", "amount": 100, "odds": 2.0}, ...]
    """
    s = text.strip()
    if not s:
        return None

    bets = []
    # 移除开头可能的"下注"命令
    s = re.sub(r"^(下注|投注|bet)\s+", "", s, flags=re.IGNORECASE)

    # 按空格或全角空格分割
    parts = re.split(r"[\s\u3000]+", s)

    i = 0
    while i < len(parts):
        part = parts[i].strip()
        if not part:
            i += 1
            continue

        # 玩法 + 金额 同一项（如：大100、大：100）
        # 先尝试玩法+数字一体
        m = re.match(r"^([大大小单双单双]+|[极极大小]+|[豹顺对]+|[龙虎豹])[:：]?(\d+(?:\.\d+)?)$", part)
        if m:
            play = m.group(1)
            amount = float(m.group(2))
            if play in BET_ODDS:
                bets.append({
                    "type": BET_TYPE_MAP.get(play, ""),
                    "content": play,
                    "amount": amount,
                    "odds": BET_ODDS[play],
                })
                i += 1
                continue

        # 特码（0-27）
        m = re.match(r"^(\d{1,2})$", part)
        if m:
            num = int(m.group(1))
            if 0 <= num <= 27:
                # 下一项应是金额
                if i + 1 < len(parts):
                    amount_str = re.sub(r"[^\d.]", "", parts[i + 1])
                    try:
                        amount = float(amount_str)
                        if amount > 0:
                            # 特码赔率（加拿大28 - 包含本金）
                            odds_table = {
                                0: 488, 1: 128, 2: 88, 3: 58, 4: 48, 5: 38,
                                6: 28, 7: 18, 8: 15, 9: 15, 10: 14, 11: 13,
                                12: 12, 13: 11, 14: 11, 15: 12, 16: 13, 17: 14,
                                18: 15, 19: 15, 20: 18, 21: 28, 22: 38, 23: 48,
                                24: 58, 25: 88, 26: 128, 27: 488,
                            }
                            odds = odds_table.get(num, 15)
                            bets.append({
                                "type": "num",
                                "content": str(num),
                                "amount": amount,
                                "odds": odds,
                            })
                            i += 2
                            continue
                    except ValueError:
                        pass
            i += 1
            continue

        # 玩法和金额分开
        if part in BET_ODDS:
            play = part
            if i + 1 < len(parts):
                amount_str = re.sub(r"[^\d.]", "", parts[i + 1])
                try:
                    amount = float(amount_str)
                    if amount > 0:
                        bets.append({
                            "type": BET_TYPE_MAP.get(play, ""),
                            "content": play,
                            "amount": amount,
                            "odds": BET_ODDS[play],
                        })
                        i += 2
                        continue
                except ValueError:
                    pass
            i += 1
            continue

        i += 1

    return bets if bets else None


def _cmd_bet(config, gid, sender_id, text, member_name=None, issue=None):
    """处理下注指令"""
    client = _get_admin_api(config)

    if client is None:
        return {"reply": "❌ 后台管理未启用，无法下注"}

    # 解析下注
    bets = _parse_bets(text)
    if not bets:
        return {"reply": "❌ 下注格式错误\n示例：大 100、小 50、单 20"}

    # 检查单注金额
    for bet in bets:
        if bet['amount'] < 1:
            return {"reply": "❌ 单注金额不能小于 1 元"}

    if not issue:
        return {"reply": "❌ 当前无正在下注的期号，请稍后再试"}

    try:
        # 调用后台 API
        result = client.bet(
            uid=str(sender_id),
            issue=str(issue),
            bets=bets,
        )

        total_amount = sum(b['amount'] for b in bets)
        balance = result.get('data', {}).get('balance', 0)

        # 构建回复文本
        bet_summary = " ".join([f"{b['content']} {b['amount']:.0f}" for b in bets])
        nickname = member_name or str(sender_id)

        reply = (
            f"✅ 已下注\n"
            f"@{nickname}\n"
            f"下注：{bet_summary}\n"
            f"金额：{total_amount:.0f}\n"
            f"期号：{issue}\n"
            f"余额：{balance:.0f}"
        )

        return {"reply": reply}
    except Exception as e:
        # 根据错误码返回不同提示
        error_msg = str(e)
        if "余额不足" in error_msg:
            return {"reply": f"❌ {error_msg}"}
        if "用户不存在" in error_msg:
            # 尝试自动注册
            try:
                client.register(str(sender_id), member_name or "")
                return _cmd_bet(config, gid, sender_id, text, member_name, issue)
            except Exception as e2:
                return {"reply": f"❌ 用户未注册且自动注册失败：{e2}"}
        return {"reply": f"❌ 下注失败：{error_msg}"}


def _cmd_balance(config, sender_id, member_name=None):
    """查询余额"""
    client = _get_admin_api(config)
    if client is None:
        return {"reply": "❌ 后台管理未启用"}

    try:
        # 先确保用户已注册
        try:
            info = client.get_user_info(str(sender_id))
        except Exception:
            # 自动注册
            client.register(str(sender_id), member_name or "")
            info = client.get_user_info(str(sender_id))

        data = info.get('data', {})
        balance = float(data.get('balance', 0))
        nickname = data.get('nickname') or member_name or str(sender_id)
        return {"reply": f"💰 {nickname} 当前余额：{balance:.0f} 元"}
    except Exception as e:
        return {"reply": f"❌ 查询余额失败：{e}"}


# ---------- parse_command 增加 balance ----------

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
    m = RE_BALANCE.match(s)
    if m:
        return {"cmd": "balance"}
    return None
