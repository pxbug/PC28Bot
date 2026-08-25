"""v2.commands 单测：GM 菜单 / 启动 / 关闭 / 非超管拒绝。"""
import sys
import os

sys.path.insert(0, os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), "src"))

import unittest

from v2 import commands
from v2.commands import parse_command, execute, is_super_admin, render_gm_menu
from v2.state import GroupStateStore


def make_config():
    return {
        "permissions": {"superAdminIds": ["SA1"]},
        "robot": {"self_account_id": "BOT"},
        "state": {"save_interval_ms": 600000},
    }


class TestParseCommand(unittest.TestCase):
    def test_gm_variants(self):
        for s in ("GM", "gm", "Gm", "菜单", "管理", "menu"):
            r = parse_command(s)
            self.assertIsNotNone(r, msg=s)
            self.assertEqual(r["name"], "GM")

    def test_enable_variants(self):
        for s in ("1", "启动", "开", "开启", "START", "on"):
            r = parse_command(s)
            self.assertIsNotNone(r, msg=s)
            self.assertEqual(r["name"], "ENABLE_PC28")

    def test_disable_variants(self):
        for s in ("2", "关闭", "关", "停止", "停", "stop", "OFF"):
            r = parse_command(s)
            self.assertIsNotNone(r, msg=s)
            self.assertEqual(r["name"], "DISABLE_PC28")

    def test_unknown(self):
        self.assertIsNone(parse_command(""))
        self.assertIsNone(parse_command("   "))
        self.assertIsNone(parse_command("foo"))
        # 不是数字也不是关键字
        self.assertIsNone(parse_command("3"))


class TestIsSuperAdmin(unittest.TestCase):
    def test_match(self):
        cfg = make_config()
        self.assertTrue(is_super_admin(cfg, "SA1"))
        self.assertTrue(is_super_admin(cfg, "SA1 "))  # 容错
        self.assertTrue(is_super_admin(cfg, "  SA1"))

    def test_not_match(self):
        cfg = make_config()
        self.assertFalse(is_super_admin(cfg, "U1"))
        self.assertFalse(is_super_admin(cfg, ""))
        self.assertFalse(is_super_admin(cfg, None))

    def test_fallback_to_robot_super_admin(self):
        cfg = {"robot": {"super_admin": "BOSS"}, "permissions": {"superAdminIds": []}}
        self.assertTrue(is_super_admin(cfg, "BOSS"))


class TestExecute(unittest.TestCase):
    def setUp(self):
        self.cfg = make_config()
        self.store = GroupStateStore(path=None)

    def test_super_admin_gm(self):
        r = execute(self.cfg, self.store, "g1", "SA1", "GM")
        self.assertIn("【超级菜单】", r["reply"])
        self.assertIn("1. 启动", r["reply"])
        self.assertIn("2. 关闭", r["reply"])

    def test_super_admin_enable(self):
        r = execute(self.cfg, self.store, "g1", "SA1", "1")
        self.assertIn("已开启", r["reply"])
        self.assertTrue(self.store.is_pc28_push_enabled("g1"))
        # 列表也能查到
        self.assertIn("g1", self.store.pc28_push_enabled_group_ids())

    def test_super_admin_disable(self):
        self.store.set_pc28_push_enabled("g1", True)
        r = execute(self.cfg, self.store, "g1", "SA1", "2")
        self.assertIn("已关闭", r["reply"])
        self.assertFalse(self.store.is_pc28_push_enabled("g1"))

    def test_non_admin_returns_none(self):
        r = execute(self.cfg, self.store, "g1", "U9", "GM")
        self.assertIsNone(r["reply"])
        r = execute(self.cfg, self.store, "g1", "U9", "1")
        self.assertIsNone(r["reply"])
        # 同时不应改变开关
        self.assertFalse(self.store.is_pc28_push_enabled("g1"))

    def test_unknown_command_returns_none(self):
        r = execute(self.cfg, self.store, "g1", "SA1", "新增管理员@X")
        self.assertIsNone(r["reply"])

    def test_default_state_is_disabled(self):
        # 骨架：默认 false
        self.assertFalse(self.store.is_pc28_push_enabled("g1"))


class TestRenderGmMenu(unittest.TestCase):
    def test_renders_text(self):
        text = render_gm_menu()
        self.assertIn("超级菜单", text)
        self.assertIn("1. 启动", text)
        self.assertIn("2. 关闭", text)


if __name__ == "__main__":
    unittest.main()
