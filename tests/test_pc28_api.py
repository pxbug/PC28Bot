"""PC28 API 客户端单测（解析倒计时/号码/响应；不连真实 yu28.top）。"""
import sys
import os

sys.path.insert(0, os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), "src"))

import unittest

from pc28.api import (
    parse_countdown,
    parse_number,
    Issue,
    issues_from_response,
)


class TestParseCountdown(unittest.TestCase):
    def test_basic(self):
        self.assertEqual(parse_countdown("02:40"), 160)
        self.assertEqual(parse_countdown("2:40"), 160)
        self.assertEqual(parse_countdown("00:05"), 5)
        self.assertEqual(parse_countdown("00:00"), 0)

    def test_empty_and_invalid(self):
        self.assertIsNone(parse_countdown(""))
        self.assertIsNone(parse_countdown(None))
        self.assertIsNone(parse_countdown("abc"))
        self.assertIsNone(parse_countdown("100"))  # 无冒号


class TestParseNumber(unittest.TestCase):
    def test_basic(self):
        nums, sval = parse_number("4+8+2=14")
        self.assertEqual(nums, [4, 8, 2])
        self.assertEqual(sval, 14)

    def test_with_spaces(self):
        nums, sval = parse_number(" 1 + 2 + 3 = 6 ")
        self.assertEqual(nums, [1, 2, 3])
        self.assertEqual(sval, 6)

    def test_invalid(self):
        self.assertEqual(parse_number(""), ([], None))
        self.assertEqual(parse_number("xxx"), ([], None))


class TestIssue(unittest.TestCase):
    def test_from_api(self):
        item = {
            "nbr": "3463701",
            "time": "2026-07-31 11:25:00",
            "number": "4+8+2=14",
            "combination": "小双",
        }
        iss = Issue.from_api(item)
        self.assertIsNotNone(iss)
        self.assertEqual(iss.nbr, "3463701")
        self.assertEqual(iss.n1, 4)
        self.assertEqual(iss.n2, 8)
        self.assertEqual(iss.n3, 2)
        self.assertEqual(iss.sum_val, 14)
        self.assertEqual(iss.combination, "小双")

    def test_from_api_bad(self):
        self.assertIsNone(Issue.from_api({}))
        self.assertIsNone(Issue.from_api({"nbr": "x", "number": "garbage", "time": ""}))


class TestIssuesFromResponse(unittest.TestCase):
    def test_response_ok(self):
        resp = {
            "countdown": "02:40",
            "data": [
                {"nbr": "3463701", "time": "2026-07-31 11:25:00", "number": "4+8+2=14", "combination": "小双"},
                {"nbr": "3463700", "time": "2026-07-31 11:20:00", "number": "1+2+3=6", "combination": "小双"},
            ],
        }
        issues = issues_from_response(resp)
        self.assertEqual(len(issues), 2)
        self.assertEqual(issues[0].nbr, "3463701")
        self.assertEqual(issues[1].sum_val, 6)

    def test_response_empty(self):
        self.assertEqual(issues_from_response({}), [])
        self.assertEqual(issues_from_response(None), [])
        self.assertEqual(issues_from_response({"data": []}), [])


if __name__ == "__main__":
    unittest.main()
