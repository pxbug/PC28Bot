"""V2 指令系统 — GM 超级菜单（精简版）。

协议（按 PC28开发.md 后续澄清）：
  超管发 "GM"          → 机器人回复【超级菜单】1.启动 2.关闭
  超管回 "1" / "启动"  → 开启本群 PC28 开奖推送
  超管回 "2" / "关闭"  → 关闭本群 PC28 开奖推送

非超管 → 不响应（与原骨架一致）。
本阶段无二级菜单、无查开奖指令、无快捷别名（"开开奖" / "关开奖" 等均不识别）。
"""
from .parser import parse_command, execute, is_super_admin
from .menu import render_gm_menu

__all__ = ["parse_command", "execute", "is_super_admin", "render_gm_menu"]
