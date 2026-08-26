"""message_normalizer 单元测试。"""
import sys
import os

sys.path.insert(0, os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), "src"))

import unittest

from message_normalizer import parse_content, to_msg_dict


class TestParseContent(unittest.TestCase):
    def test_json_string_with_content(self):
        self.assertEqual(parse_content({"content": '{"content":"GM"}'}), "GM")

    def test_json_string_with_text(self):
        self.assertEqual(parse_content({"content": '{"text":"开奖"}'}), "开奖")

    def test_nested_json(self):
        self.assertEqual(parse_content({"content": '{"content":"启动本群"}'}), "启动本群")

    def test_plain_string(self):
        self.assertEqual(parse_content({"content": "hello"}), "hello")

    def test_empty_string(self):
        self.assertEqual(parse_content({"content": ""}), "")

    def test_missing_key(self):
        self.assertEqual(parse_content({}), "")


class TestToMsgDict(unittest.TestCase):
    def test_json_content_parsed(self):
        m = {
            "groupID": "g1",
            "sendID": "u1",
            "senderNickname": "User",
            "serverMsgID": "msg1",
            "clientMsgID": "cli1",
            "contentType": 101,
            "content": '{"content":"GM"}',
        }
        d = to_msg_dict(m)
        self.assertEqual(d["groupID"], "g1")
        self.assertEqual(d["content"], "GM")

    def test_plain_content_preserved(self):
        m = {
            "groupID": "g2",
            "sendID": "u2",
            "content": "开奖",
        }
        d = to_msg_dict(m)
        self.assertEqual(d["content"], "开奖")


if __name__ == "__main__":
    unittest.main()
