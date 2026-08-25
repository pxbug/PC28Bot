"""PC28 开奖卡片格式化单测。"""
import sys
import os

sys.path.insert(0, os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), "src"))

import unittest

from pc28.api import Issue
from pc28.format import format_latest, format_history_only


def _iss(nbr, nums, combination=""):
    s = sum(nums)
    raw = "%d+%d+%d=%d" % (nums[0], nums[1], nums[2], s)
    return Issue(
        nbr=nbr, time="2026-07-31 11:25:00",
        n1=nums[0], n2=nums[1], n3=nums[2],
        sum_val=s, combination=combination, raw_number=raw,
    )


class TestFormatLatest(unittest.TestCase):
    def test_with_history(self):
        cur = _iss("3463701", [4, 8, 2], "小双")
        history = [_iss("3463700", [1, 2, 3], "小双")]
        text = format_latest(cur, history=history, max_history=20)
        self.assertIn("3463701", text)
        self.assertIn("4+8+2=14", text)
        self.assertIn("小双", text)
        self.assertIn("近 2 期", text)

    def test_no_history_still_shows_one(self):
        cur = _iss("3463701", [4, 8, 2], "小双")
        text = format_latest(cur, history=None, max_history=20)
        self.assertIn("3463701", text)
        self.assertIn("近 1 期", text)

    def test_max_history_caps(self):
        cur = _iss("100", [1, 1, 1], "豹子")
        history = [_iss(str(99 - i), [i + 1, i + 2, i + 3]) for i in range(30)]
        text = format_latest(cur, history=history, max_history=20)
        # 列表总数 = 当期 + 前 19 期 = 20 条（cur 是最新，会顶替 history[0]）
        self.assertIn("近 20 期", text)

    def test_history_only_dedup_when_dup_in_history(self):
        # history[0] 与 cur 重复时，去重只保留一份
        cur = _iss("3463701", [4, 8, 2], "小双")
        history = [cur, _iss("3463700", [1, 2, 3], "小双")]
        text = format_latest(cur, history=history, max_history=20)
        # 同一期号只出现一次（卡片头 + 列表 1 行）
        self.assertEqual(text.count("3463701"), 2)  # header + 列表


class TestFormatHistoryOnly(unittest.TestCase):
    def test_basic(self):
        # history[0] 是最新的（nbr=100）；max_history=3 → 只取前 3 条
        issues = [_iss(str(100 - i), [i, i + 1, i + 2]) for i in range(5)]
        text = format_history_only(issues, max_history=3)
        self.assertIn("近 3 期", text)
        # 第 4 期（nbr=96）应不在结果中
        self.assertNotIn(" 96", text)
        # nbr=100 应在结果中（最新一条）
        self.assertIn("100", text)

    def test_empty(self):
        self.assertEqual(format_history_only([], max_history=20), "")


if __name__ == "__main__":
    unittest.main()
