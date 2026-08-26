"""Runner 群停用门控测试。

验证：群被 set_enabled(False) 后，runner._handle_message 应丢弃非超管消息；
超管发送的消息（含"启动本群"）仍能正常通过，触发重新启用。
"""
import sys
import os

sys.path.insert(0, os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), "src"))

import unittest

from v2.state import GroupStateStore
from v2.runner import V2Runner


def _make_runner_for_test(cfg, store, sent):
    """构造一个绕过真实 start() 的 runner stub，仅装配 _handle_message 所需的字段。"""
    r = V2Runner.__new__(V2Runner)
    r.logger = lambda msg: None
    r.config = cfg
    r.store = store
    r.runtime = type("R", (), {})()
    r.runtime._group_meta = {}
    r.runtime.seen = type("S", (), {"_d": {}})()
    r.runtime.on_message = lambda md: None
    r.runtime.refresh_lottery_targets = lambda: []
    r.send_conn = None
    r.client = None
    r.listener = None

    def _send(gid, text):
        sent.append((gid, text))
    r._ws_send = _send
    return r


class TestRunnerGate(unittest.TestCase):
    def setUp(self):
        self.cfg = {"permissions": {"superAdminIds": ["SA1"]}}
        self.store = GroupStateStore(path=None)
        self.sent = []

    def _md(self, gid, sender, content):
        return {"groupID": gid, "sendID": sender, "content": content,
                "contentType": 101, "serverMsgID": "m-%s-%s" % (gid, content)}

    def test_disabled_group_drops_normal_member(self):
        self.store.set_enabled("g1", False)
        r = _make_runner_for_test(self.cfg, self.store, self.sent)
        r._handle_message(self._md("g1", "U1", "开奖"))
        self.assertEqual(self.sent, [])

    def test_disabled_group_passes_super_admin(self):
        self.store.set_enabled("g1", False)
        r = _make_runner_for_test(self.cfg, self.store, self.sent)
        r._handle_message(self._md("g1", "SA1", "开奖"))
        # 没有 lottery 客户端 → execute 返回"未配置"，仍会 _ws_send 出去
        self.assertTrue(self.sent)
        self.assertIn("未配置", self.sent[0][1])

    def test_disabled_group_super_admin_can_start(self):
        self.store.set_enabled("g1", False)
        r = _make_runner_for_test(self.cfg, self.store, self.sent)
        r._handle_message(self._md("g1", "SA1", "启动本群"))
        self.assertTrue(self.store.is_group_active("g1"))
        self.assertTrue(any("已启动" in t for _, t in self.sent))

    def test_enabled_group_normal_member_passes(self):
        self.store.set_enabled("g1", True)
        r = _make_runner_for_test(self.cfg, self.store, self.sent)
        r._handle_message(self._md("g1", "U1", "GM"))
        self.assertTrue(any("超级管理菜单" in t for _, t in self.sent))


if __name__ == "__main__":
    unittest.main()