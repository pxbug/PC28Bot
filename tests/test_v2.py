import sys
import os

sys.path.insert(0, os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), "src"))

import unittest

from v2 import commands as cmds
from v2 import config as cfg
from v2.state import GroupStateStore


def make_config():
    return {
        "permissions": {"superAdminIds": ["SA1"]},
        "robot": {"self_account_id": "BOT"},
        "monitors": {"ad": {"enabled": False, "digit_threshold": 5, "letter_threshold": 5, "url_detection": True, "patterns": []},
                     "spam": {"enabled": True, "window_ms": 10000, "threshold": 3},
                     "blacklist": {"action": "kick_member", "recall": True}},
        "safety": {"allow_send": True},
        "queue": {"action_cooldown_ms": 0},
        "state": {"save_interval_ms": 600000},
    }


class TestV2Permissions(unittest.TestCase):
    def setUp(self):
        self.cfg = make_config()
        self.store = GroupStateStore(path=None)

    def test_super_admin_adds_group_admin(self):
        # 超管添加群管理员
        r = cmds.execute(self.cfg, self.store, "g1", "SA1", "添加管理员@A1", "A1")
        self.assertEqual(r["reply"], "✅ 已添加群管理员：A1")
        self.assertTrue(self.store.in_list("g1", "admins", "A1"))

    def test_non_admin_cannot_add_admin(self):
        r = cmds.execute(self.cfg, self.store, "g1", "U1", "添加管理员@A1", "A1")
        self.assertIsNone(r["reply"])

    def test_group_admin_cannot_add_admin(self):
        self.store.list_add("g1", "admins", "GA1")
        r = cmds.execute(self.cfg, self.store, "g1", "GA1", "添加管理员@A1", "A1")
        self.assertIsNone(r["reply"])

    def test_group_admin_can_manage_own_group(self):
        self.store.list_add("g1", "admins", "GA1")
        r = cmds.execute(self.cfg, self.store, "g1", "GA1", "加回复 报价=请联系客服")
        self.assertIn("已添加关键词回复", r["reply"])
        kr = self.store.keyword_reply_get("g1")
        self.assertEqual(kr["rules"][0]["keyword"], "报价")

    def test_group_admin_cannot_license(self):
        self.store.list_add("g1", "admins", "GA1")
        r = cmds.execute(self.cfg, self.store, "g1", "GA1", "设置使用时长30天")
        self.assertIsNone(r["reply"])
        r = cmds.execute(self.cfg, self.store, "g1", "GA1", "查看使用期")
        self.assertIsNone(r["reply"])

    def test_super_license(self):
        r = cmds.execute(self.cfg, self.store, "g1", "SA1", "设置使用时长30天")
        self.assertIn("已设置使用时长 30 天", r["reply"])
        self.assertIsNotNone(self.store.get_license("g1")["expiresAtMs"])
        r = cmds.execute(self.cfg, self.store, "g1", "SA1", "查看使用期")
        self.assertIn("使用时长", r["reply"])

    def test_kick_returns_action(self):
        r = cmds.execute(self.cfg, self.store, "g1", "SA1", "踢出@U9", "U9")
        self.assertEqual(r["action"], {"type": "kick", "target": "U9"})

    def test_blacklist_add(self):
        r = cmds.execute(self.cfg, self.store, "g1", "SA1", "拉黑@U2", "U2")
        self.assertIn("已拉黑", r["reply"])
        self.assertTrue(self.store.in_list("g1", "blacklist", "U2"))

    def test_adkid_toggle(self):
        r = cmds.execute(self.cfg, self.store, "g1", "SA1", "开广告仔监测")
        self.assertIn("已开启", r["reply"])
        self.assertTrue(self.store.monitor_enabled("g1", "adkid", False))

    def test_interval_add_list_remove(self):
        cmds.execute(self.cfg, self.store, "g1", "SA1", "每隔10分钟发送 你好")
        tasks = self.store.interval_list("g1")
        self.assertEqual(len(tasks), 1)
        self.assertEqual(tasks[0]["intervalSeconds"], 600)
        r = cmds.execute(self.cfg, self.store, "g1", "SA1", "取消定时发送1")
        self.assertEqual(len(self.store.interval_list("g1")), 0)

    def test_interval_min_clamp(self):
        # 最小间隔 5 分钟（300秒），更短被钳制
        cmds.execute(self.cfg, self.store, "g1", "SA1", "每隔2分钟发送 你好")
        tasks = self.store.interval_list("g1")
        self.assertEqual(tasks[0]["intervalSeconds"], 300)


if __name__ == "__main__":
    unittest.main()
