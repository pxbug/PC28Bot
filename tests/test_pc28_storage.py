"""PC28 storage 单测（用 NullStore 验证接口契约 + 失败降级）。"""
import sys
import os

sys.path.insert(0, os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), "src"))

import unittest

from pc28.storage import NullStore, build_store
from pc28.api import Issue


def _iss(nbr, nums):
    s = sum(nums)
    raw = "%d+%d+%d=%d" % (nums[0], nums[1], nums[2], s)
    return Issue(
        nbr=nbr, time="2026-07-31 11:25:00",
        n1=nums[0], n2=nums[1], n3=nums[2],
        sum_val=s, combination="小双", raw_number=raw,
    )


class TestNullStore(unittest.TestCase):
    def setUp(self):
        self.s = NullStore()

    def test_writes_are_noop(self):
        self.assertTrue(self.s.ensure_schema())
        self.assertFalse(self.s.is_available())
        self.assertFalse(self.s.upsert_issue(_iss("1", [1, 2, 3])))
        self.assertFalse(self.s.mark_pushed("1"))
        self.assertFalse(self.s.upsert_push_state("1", 5))
        self.s.close()  # no exception

    def test_reads_return_empty(self):
        self.assertIsNone(self.s.get_issue("1"))
        self.assertEqual(self.s.get_latest(1), [])
        self.assertEqual(self.s.get_history(20), [])
        self.assertIsNone(self.s.get_push_state())


class TestBuildStoreFallback(unittest.TestCase):
    def test_disabled_returns_nullstore(self):
        s = build_store({"enabled": False, "mysql": {"host": "127.0.0.1"}}, logger=lambda m: None)
        self.assertIsInstance(s, NullStore)

    def test_unreachable_mysql_falls_back(self):
        # 指向一个不可达端口，让 MySQLStore 连接失败 → 降级 NullStore
        s = build_store({
            "enabled": True,
            "mysql": {"host": "127.0.0.1", "port": 1, "connect_timeout": 1},
        }, logger=lambda m: None)
        self.assertIsInstance(s, NullStore)


if __name__ == "__main__":
    unittest.main()
