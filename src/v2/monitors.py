"""广告/刷屏检测 — 已移除。

保留模块以兼容旧调用：check_ad() 返回 []；check_spam() 返回 False；SpamWindow 类存在但无行为。
新监测逻辑可直接在此处重新设计。
"""
import time


class SpamWindow:
    """空壳：保留类名与构造参数，避免 runtime.py 引用崩溃。"""

    def __init__(self, window_ms=10000, threshold=3):
        self.window_ms = window_ms
        self.threshold = threshold
        self._hits = {}

    def check(self, gid, member_id, content_key, now=None):
        return False

    def reset(self, gid, member_id, content_key=None):
        pass

    def prune(self, max_age_ms=3600000):
        pass


def check_ad(text, config):
    """空壳：永远返回未命中。"""
    return []


def check_spam(store, gid, member_id, content_key, spam_window, config):
    """空壳：永远返回未命中。"""
    return False
