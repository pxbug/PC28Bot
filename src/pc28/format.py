"""PC28 开奖卡片格式化（当期 + 历史 20 期）。

文本结构（紧凑，适合群聊）：
  【开奖】期号 3463701  时间 2026-07-31 11:25:00
  号码：4+8+2=14  和值：14  形态：小双

  近 20 期：
   1) 3463701  4+8+2=14  小双
   2) 3463700  1+2+3=6    小双
   ...
"""

from .api import Issue


def format_latest(issue, history=None, max_history=20):
    """当期 Issue + 历史 Issue 列表 → 推送文本。

    history: 从最新到最旧；本函数会自动截取前 max_history 条，
    并把"当期"放到 history 头部避免重复。
    """
    if issue is None:
        return ""
    lines = []
    lines.append("【开奖】期号 %s  时间 %s" % (issue.nbr, issue.time))
    lines.append("号码：%s  和值：%s  形态：%s" % (
        issue.raw_number or "?",
        issue.sum_val,
        issue.combination or "?",
    ))
    items = []
    if history:
        items.extend(history)
    if not items or items[0].nbr != issue.nbr:
        items.insert(0, issue)
    items = items[:max_history]
    if items:
        lines.append("")
        lines.append("近 %d 期：" % len(items))
        for idx, it in enumerate(items, 1):
            lines.append("%2d) %s  %s  %s" % (
                idx,
                it.nbr,
                (it.raw_number or "?").ljust(8),
                it.combination or "?",
            ))
    return "\n".join(lines)


def format_history_only(history, max_history=20):
    """纯历史列表（无当期强调）。"""
    items = list(history or [])[:max_history]
    if not items:
        return ""
    lines = ["近 %d 期：" % len(items)]
    for idx, it in enumerate(items, 1):
        lines.append("%2d) %s  %s  %s" % (
            idx,
            it.nbr,
            (it.raw_number or "?").ljust(8),
            it.combination or "?",
        ))
    return "\n".join(lines)
