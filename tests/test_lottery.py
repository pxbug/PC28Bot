"""开奖模块单元测试。

覆盖：
- 倒计时解析 (parse_countdown)
- 期号归一化 (normalize_issue)
- 单期/历史 20 期文本格式化 (format_issue / format_recent / format_push)
- PushCounter 持久化（last_issue / push_count / record 写盘）
- 命令解析 (parse_command)
- 命令执行 (execute) — 群内开奖指令
- LotteryPusher 主循环（mock client，跳过 sleep）
"""
import json
import os
import sys
import tempfile
import unittest

sys.path.insert(0, os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), "src"))

from v2 import lottery, commands
from v2.state import GroupStateStore


# ---------- 倒计时 ----------

class TestParseCountdown(unittest.TestCase):
    def test_basic(self):
        self.assertEqual(lottery.parse_countdown("02:40"), 160)
        self.assertEqual(lottery.parse_countdown("00:01"), 1)
        self.assertEqual(lottery.parse_countdown("10:00"), 600)

    def test_with_whitespace(self):
        self.assertEqual(lottery.parse_countdown("  05:30  "), 330)

    def test_empty_and_invalid(self):
        self.assertIsNone(lottery.parse_countdown(""))
        self.assertIsNone(lottery.parse_countdown(None))
        self.assertIsNone(lottery.parse_countdown("abc"))
        self.assertIsNone(lottery.parse_countdown("5:99"))
        self.assertIsNone(lottery.parse_countdown("1:2"))

    def test_overflow_min(self):
        self.assertEqual(lottery.parse_countdown("999:59"), 999 * 60 + 59)


# ---------- 归一化 ----------

class TestNormalizeIssue(unittest.TestCase):
    def test_full(self):
        item = {"nbr": 3463701, "time": "2026-07-31 11:25:00",
                "number": "4+8+2=14", "combination": "小双"}
        norm = lottery.normalize_issue(item)
        self.assertEqual(norm["nbr"], "3463701")
        self.assertEqual(norm["combination"], "小双")
        self.assertIsNone(norm["height"])

    def test_missing_nbr(self):
        self.assertIsNone(lottery.normalize_issue({"time": "x"}))
        self.assertIsNone(lottery.normalize_issue({"nbr": ""}))
        self.assertIsNone(lottery.normalize_issue(None))


# ---------- 格式化 ----------

class TestFormat(unittest.TestCase):
    def test_format_issue_full(self):
        item = {"nbr": "3463701", "time": "2026-07-31 11:25:00",
                "number": "4+8+2=14", "combination": "小双"}
        out = lottery.format_issue(item)
        self.assertIn("第 3463701 期", out)
        self.assertIn("开奖：4+8+2=14", out)
        self.assertIn("小双", out)

    def test_format_issue_no_combo(self):
        item = {"nbr": "1", "time": "", "number": "1+2+3=6", "combination": ""}
        out = lottery.format_issue(item)
        self.assertIn("第 1 期", out)
        self.assertIn("开奖：1+2+3=6", out)
        self.assertNotIn("形态", out)

    def test_format_recent_20(self):
        data = [
            {"nbr": str(3463700 + i), "time": "2026-07-31 11:%02d:00" % (25 + i),
             "number": "1+2+3=6", "combination": "小双"}
            for i in range(20)
        ]
        out = lottery.format_recent(data, n=20)
        self.assertIn("历史开奖（最近 20 期）", out)
        self.assertIn("期号", out)
        self.assertIn("开奖", out)
        self.assertIn("组合", out)
        for i in range(20):
            self.assertIn(str(3463700 + i), out)
        self.assertIn("[小双]", out)

    def test_format_recent_empty(self):
        self.assertEqual(lottery.format_recent([]), "暂无开奖数据")

    def test_format_recent_caps_n(self):
        data = [{"nbr": str(i), "time": "", "number": "", "combination": ""} for i in range(100)]
        out = lottery.format_recent(data, n=20)
        lines = out.splitlines()
        # 跳过头部 / 表头 / 分隔线（仅前 3 行）
        self.assertEqual(len(lines), 3 + 20)

    def test_format_push(self):
        item = {"nbr": "3473898", "time": "2026-07-31 11:25:00",
                "number": "8 + 9 + 2 = 19", "combination": "大单"}
        out = lottery.format_push(item)
        self.assertIn("🎰 PC28 开奖", out)
        self.assertIn("━━━━━━━━━━━", out)
        self.assertIn("期号：3473898", out)
        self.assertIn("开奖：8 + 9 + 2 = 19", out)
        self.assertIn("组合：大单", out)
        self.assertNotIn("形态：", out)
        self.assertNotIn("时间：", out)

    def test_format_recent_columns_aligned(self):
        data = [
            {"nbr": "3473879", "time": "", "number": "1 + 8 + 7 = 16", "combination": "大双"},
            {"nbr": "3473898", "time": "", "number": "8 + 9 + 2 = 19", "combination": "大单"},
        ]
        out = lottery.format_recent(data, n=20)
        lines = out.splitlines()
        # 数据行（最后 2 行）
        data_lines = lines[3:]
        self.assertEqual(len(data_lines), 2)
        self.assertEqual(len(data_lines[0]), len(data_lines[1]))

    def test_format_recent_old_to_new(self):
        """API 返回最新在前，format_recent 应按期号升序（旧→新）展示。"""
        data = [
            {"nbr": "3474113", "time": "", "number": "6+9+4=19", "combination": "大单"},
            {"nbr": "3474112", "time": "", "number": "2+7+6=15", "combination": "大单"},
            {"nbr": "3474111", "time": "", "number": "2+1+2=5",  "combination": "小单"},
        ]
        out = lottery.format_recent(data, n=20)
        lines = out.splitlines()
        data_lines = lines[3:]
        # 第一期数据行应是 3474111（最旧），最后应是 3474113（最新）
        self.assertIn("3474111", data_lines[0])
        self.assertIn("3474112", data_lines[1])
        self.assertIn("3474113", data_lines[2])

    def test_format_recent_n_caps_after_sort(self):
        """截断按 n 之后，截断前先按升序排序。"""
        data = [
            {"nbr": str(3474110 + i), "time": "", "number": "1+2+3=6", "combination": "小双"}
            for i in range(5)
        ]  # 3474110 .. 3474114
        out = lottery.format_recent(data, n=3)
        lines = out.splitlines()
        data_lines = lines[3:]
        self.assertEqual(len(data_lines), 3)
        self.assertIn("3474110", data_lines[0])
        self.assertIn("3474111", data_lines[1])
        self.assertIn("3474112", data_lines[2])


# ---------- PushCounter ----------

class TestPushCounter(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.NamedTemporaryFile(mode="w", suffix=".json", delete=False)
        self.tmp.close()
        self.path = self.tmp.name

    def tearDown(self):
        try:
            os.unlink(self.path)
        except OSError:
            pass

    def test_init_empty(self):
        pc = lottery.PushCounter(self.path)
        cur = pc.get()
        self.assertEqual(cur["last_issue"], "")
        self.assertEqual(cur["push_count"], 0)

    def test_init_with_file(self):
        with open(self.path, "w", encoding="utf-8") as f:
            json.dump({"last_issue": "999", "push_count": 7, "last_push_at": 12345}, f)
        pc = lottery.PushCounter(self.path)
        self.assertEqual(pc.get()["last_issue"], "999")
        self.assertEqual(pc.get()["push_count"], 7)
        self.assertEqual(pc.get()["last_push_at"], 12345)

    def test_record(self):
        pc = lottery.PushCounter(self.path)
        pc.record("1001")
        pc.record("1002")
        cur = pc.get()
        self.assertEqual(cur["last_issue"], "1002")
        self.assertEqual(cur["push_count"], 2)
        with open(self.path, "r", encoding="utf-8") as f:
            d = json.load(f)
        self.assertEqual(d["last_issue"], "1002")
        self.assertEqual(d["push_count"], 2)

    def test_init_last_issue_first_time(self):
        pc = lottery.PushCounter(self.path)
        pc.init_last_issue("2001")
        self.assertEqual(pc.get()["last_issue"], "2001")
        self.assertEqual(pc.get()["push_count"], 0)

    def test_init_last_issue_does_not_overwrite(self):
        pc = lottery.PushCounter(self.path)
        pc.record("1001")
        pc.init_last_issue("9999")
        self.assertEqual(pc.get()["last_issue"], "1001")
        self.assertEqual(pc.get()["push_count"], 1)


# ---------- 命令解析 ----------

class TestParseCommand(unittest.TestCase):
    def test_kj(self):
        self.assertEqual(commands.parse_command("开奖"), {"cmd": "kj"})
        self.assertEqual(commands.parse_command("开奖 "), {"cmd": "kj"})
        self.assertEqual(commands.parse_command("当前期号"), {"cmd": "kj"})
        self.assertEqual(commands.parse_command("当前"), {"cmd": "kj"})

    def test_history_default(self):
        self.assertEqual(commands.parse_command("历史"), {"cmd": "history", "n": 20})
        self.assertEqual(commands.parse_command("历史开奖"), {"cmd": "history", "n": 20})

    def test_history_with_n(self):
        self.assertEqual(commands.parse_command("历史50"), {"cmd": "history", "n": 50})
        self.assertEqual(commands.parse_command("历史开奖5"), {"cmd": "history", "n": 5})

    def test_history_caps(self):
        self.assertEqual(commands.parse_command("历史999")["n"], 100)

    def test_gm(self):
        self.assertEqual(commands.parse_command("GM"), {"cmd": "gm"})
        self.assertEqual(commands.parse_command("gm"), {"cmd": "gm"})

    def test_menu(self):
        self.assertEqual(commands.parse_command("菜单"), {"cmd": "menu"})
        self.assertEqual(commands.parse_command("menu"), {"cmd": "menu"})
        self.assertEqual(commands.parse_command("help"), {"cmd": "menu"})

    def test_start_group(self):
        self.assertEqual(commands.parse_command("启动本群"), {"cmd": "start_group"})
        self.assertIsNone(commands.parse_command("启动本群  马上"))
        self.assertIsNone(commands.parse_command("启动其他群"))

    def test_unknown_returns_none(self):
        self.assertIsNone(commands.parse_command("你好"))
        self.assertIsNone(commands.parse_command(""))
        self.assertIsNone(commands.parse_command(None))
        self.assertIsNone(commands.parse_command("开奖查询"))
        self.assertIsNone(commands.parse_command("开启开奖推送"))


# ---------- 命令执行（开奖/历史/订阅） ----------

class TestLotteryCommands(unittest.TestCase):
    def setUp(self):
        self.cfg_with_lottery = {
            "permissions": {"superAdminIds": ["SA1"]},
            "lottery": {"enabled": True, "api_key": "TESTKEY",
                        "base_url": "http://example.invalid", "game": "jnd28"},
        }
        self.cfg_no_lottery = {"permissions": {"superAdminIds": ["SA1"]}}
        self.store = GroupStateStore(path=None)

    def test_kj_no_client(self):
        r = commands.execute(self.cfg_no_lottery, self.store, "g1", "U1", "开奖")
        self.assertIn("未启用", r["reply"])

    def test_history_no_client(self):
        r = commands.execute(self.cfg_no_lottery, self.store, "g1", "U1", "历史")
        self.assertIn("未启用", r["reply"])

    def test_kj_missing_api_key(self):
        cfg = {"permissions": {"superAdminIds": ["SA1"]},
               "lottery": {"enabled": True}}
        r = commands.execute(cfg, self.store, "g1", "U1", "开奖")
        self.assertIn("api_key", r["reply"])

    def test_menu_visible(self):
        r = commands.execute(self.cfg_no_lottery, self.store, "g1", "U1", "菜单")
        self.assertIn("当前期号", r["reply"])
        self.assertIn("历史", r["reply"])

    def test_unknown_returns_none_reply(self):
        r = commands.execute(self.cfg_no_lottery, self.store, "g1", "U1", "你好世界")
        self.assertIsNone(r["reply"])

    def test_gm_returns_super_menu(self):
        r = commands.execute(self.cfg_no_lottery, self.store, "g1", "U1", "GM")
        self.assertIn("🎱", r["reply"])
        self.assertIn("🚀", r["reply"])


# ---------- LotteryPusher（mock） ----------

class TestLotteryPusher(unittest.TestCase):
    def _client_mock(self, items=None, countdown="02:00", items_after=None):
        """items_after: 首次 init 后的"下一期"数据（默认 None = 用 items 模拟不变期号）。"""
        from v2.lottery import LotteryClient
        c = LotteryClient.__new__(LotteryClient)
        c.base_url = "http://test"
        c.api_key = "x"
        c.game = "jnd28"
        c.timeout = 1
        c.logger = lambda m: None
        c._mock_items = items or []
        c._mock_items_after = items_after
        c._mock_countdown = countdown
        c._call_count = 0

        def fetch_recent(nbr=1):
            c._call_count += 1
            if c._call_count == 1 or c._mock_items_after is None:
                return (c._mock_items[:nbr], c._mock_countdown)
            return (c._mock_items_after[:nbr], c._mock_countdown)
        c.fetch_recent = fetch_recent
        return c

    def _make_pusher(self, items, target_gids, sent=None, items_after=None,
                     history_follow_n=0, history_follow_delay=0):
        from v2.lottery import LotteryPusher, PushCounter
        client = self._client_mock(items=items, items_after=items_after)
        counter = PushCounter(tempfile.NamedTemporaryFile(suffix=".json", delete=False).name)
        sent_list = sent if sent is not None else []
        pusher = LotteryPusher(client=client, counter=counter,
                               target_gids=target_gids,
                               send_func=lambda g, t: sent_list.append((g, t)),
                               logger=lambda m: None,
                               history_follow_n=history_follow_n,
                               history_follow_delay=history_follow_delay)
        # 跳过 _sleep，避免测试假死
        pusher._sleep = lambda s: True
        return pusher, sent_list

    def test_cycle_dedup(self):
        # 模拟：初始化后下一期没来（API 仍返回同一期），应跳过
        pusher, sent = self._make_pusher(
            items=[{"nbr": "100", "time": "", "number": "1+1+1=3", "combination": "小"}],
            target_gids=["g1"],
            items_after=None)   # 不变
        pusher._cycle()   # init
        pusher._cycle()   # fetch 同一期 → skip
        self.assertEqual(sent, [])
        self.assertEqual(pusher.counter.get()["last_issue"], "100")
        self.assertEqual(pusher.counter.get()["push_count"], 0)

    def test_cycle_push(self):
        # 模拟：初始化（"200"），下一期（"201"）
        pusher, sent = self._make_pusher(
            items=[{"nbr": "200", "time": "2026-07-31 11:25:00",
                    "number": "1+2+3=6", "combination": "小双"}],
            target_gids=["g1", "g2"],
            items_after=[{"nbr": "201", "time": "2026-07-31 11:30:00",
                          "number": "5+6+7=18", "combination": "大双"}])
        # 第一次循环：首次启动，初始化 last_issue=200
        pusher._cycle()
        self.assertEqual(sent, [])
        self.assertEqual(pusher.counter.get()["last_issue"], "200")
        # 第二次循环：推送 201
        pusher._cycle()
        self.assertEqual(len(sent), 2)
        self.assertEqual([s[0] for s in sent], ["g1", "g2"])
        self.assertIn("201", sent[0][1])
        self.assertEqual(pusher.counter.get()["last_issue"], "201")
        self.assertEqual(pusher.counter.get()["push_count"], 1)

    def test_cycle_no_targets(self):
        pusher, sent = self._make_pusher(
            items=[{"nbr": "300", "time": "", "number": "", "combination": ""}],
            target_gids=[],
            items_after=[{"nbr": "301", "time": "", "number": "", "combination": ""}])
        pusher._cycle()   # init (last_issue=300)
        pusher._cycle()   # push attempt (no targets; new nbr 301)
        # 即使没人订阅也要更新 last_issue
        self.assertEqual(pusher.counter.get()["last_issue"], "301")
        self.assertEqual(sent, [])

    def test_cycle_history_follow_disabled(self):
        """未启用 history_follow 时只发单条推送。"""
        pusher, sent = self._make_pusher(
            items=[{"nbr": "400", "time": "", "number": "", "combination": ""}],
            target_gids=["g1"],
            items_after=[{"nbr": "401", "time": "", "number": "", "combination": ""}])
        pusher._cycle()
        pusher._cycle()
        self.assertEqual(len(sent), 1)
        self.assertIn("401", sent[0][1])

    def test_cycle_history_follow_push(self):
        """启用 history_follow=5 时，开奖 + 历史各发一条，群内共 2 条。"""
        items_after = [{"nbr": "501", "time": "", "number": "", "combination": ""}] + \
                      [{"nbr": str(500 - i), "time": "", "number": "", "combination": ""}
                       for i in range(4)]
        pusher, sent = self._make_pusher(
            items=[{"nbr": "500", "time": "", "number": "", "combination": ""}],
            target_gids=["g1", "g2"],
            items_after=items_after,
            history_follow_n=5,
            history_follow_delay=1)
        pusher._cycle()   # init
        pusher._cycle()   # push 501 + history
        # 每个群 2 条（开奖 + 历史），共 4 条
        self.assertEqual(len(sent), 4)
        # 第 1 条是开奖（包含 nbr=501），第 2 条是历史（不包含纯 501 但带 "最近 5"）
        self.assertIn("501", sent[0][1])
        self.assertIn("501", sent[1][1])
        self.assertIn("最近 5", sent[2][1])
        self.assertIn("最近 5", sent[3][1])
        # 两个群都收到
        self.assertEqual(sorted({s[0] for s in sent}), ["g1", "g2"])

    def test_cycle_history_follow_disabled_when_n_zero(self):
        """n=0 时不补推历史。"""
        pusher, sent = self._make_pusher(
            items=[{"nbr": "600", "time": "", "number": "", "combination": ""}],
            target_gids=["g1"],
            items_after=[{"nbr": "601", "time": "", "number": "", "combination": ""}],
            history_follow_n=0,
            history_follow_delay=1)
        pusher._cycle()
        pusher._cycle()
        self.assertEqual(len(sent), 1)


class TestLotteryClientFetch(unittest.TestCase):
    def test_nbr_clamped_to_1_100(self):
        from v2.lottery import LotteryClient
        c = LotteryClient(api_key="x", timeout=1, logger=lambda m: None)
        self.assertIn("nbr=1", c._url(0))
        self.assertIn("nbr=1", c._url(-5))
        self.assertIn("nbr=100", c._url(99999))
        self.assertIn("nbr=50", c._url(50))


if __name__ == "__main__":
    unittest.main()