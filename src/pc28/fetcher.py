"""PC28 倒计时驱动的主循环 fetcher。

按 `开奖接口.md` 第 55-74 行流程：
  拉 API 拿倒计时 → 解析为秒 → sleep(倒计时+1) → sleep(3) →
  拉最新 1 期拿当前 nbr → 拉历史 20 期 → 去重 nbr == last_issue →
  写库 → 推送到开启群 → 更新 last_issue / push_count

模块支持两种运行方式：
  1) 作为线程（worker.py 启动）持续运行
  2) 独立 CLI（python -m pc28.fetcher --once 或常驻）便于调试与部署
"""
import threading
import time

from .api import Yu28Client, Issue, issues_from_response
from .format import format_latest


DEFAULT_HISTORY = 20
DEFAULT_COUNTDOWN_EXTRA_SEC = 1
DEFAULT_POST_RESULT_DELAY_SEC = 3


def _sleep_sec(sec):
    """分块睡眠，便于 stop_event 立即响应。"""
    deadline = time.time() + max(0, sec)
    while time.time() < deadline:
        time.sleep(0.2)


class Fetcher:
    """单实例 fetcher；可作为线程 target 或独立脚本入口。"""

    def __init__(self, api, store, push_targets_fn, logger=None,
                 history_size=DEFAULT_HISTORY,
                 countdown_extra_sec=DEFAULT_COUNTDOWN_EXTRA_SEC,
                 post_result_delay_sec=DEFAULT_POST_RESULT_DELAY_SEC,
                 send_delay_sec=0.3):
        self.api = api
        self.store = store
        self.push_targets_fn = push_targets_fn  # callable() -> iterable[gid]
        self.logger = logger or (lambda m: None)
        self.history_size = int(history_size)
        self.countdown_extra_sec = int(countdown_extra_sec)
        self.post_result_delay_sec = int(post_result_delay_sec)
        self.send_delay_sec = float(send_delay_sec)
        self._stop = threading.Event()
        self.last_issue = ""
        self.push_count = 0

    def stop(self):
        self._stop.set()

    # ---------- 单次抓取流程（可独立调用，便于测试）----------
    def step_once(self, send_fn=None):
        """执行一次完整的"等倒计时 → 拉数据 → 写库 → 推送"流程。

        send_fn(gid, text): 同步发送函数（如 ws_send_conn.send_msg）。
                            若为 None，则只写库不推送。
        返回 True 表示成功处理了一期；False 表示本轮跳过或失败。
        """
        cd = self.api.get_countdown()
        if cd is None:
            self.logger("[pc28] 倒计时未知，2 秒后重试")
            _sleep_sec(2)
            return False
        self.logger("[pc28] 倒计时 %ds" % cd)
        # 等到下期出号 + 1s 缓冲
        _sleep_sec(cd + self.countdown_extra_sec)
        # 额外 3s 等结果落地
        _sleep_sec(self.post_result_delay_sec)
        # 拉最新 1 期拿当前 nbr
        try:
            snap = self.api.get_latest(1)
        except Exception as e:
            self.logger("[pc28] 拉最新失败: %s" % e)
            return False
        issues = issues_from_response(snap)
        if not issues:
            self.logger("[pc28] 最新期为空，跳过本轮")
            return False
        issue = issues[0]
        if issue.nbr == self.last_issue:
            self.logger("[pc28] 期号 %s 已处理，跳过去重" % issue.nbr)
            return False
        # 写库
        try:
            self.store.upsert_issue(issue)
        except Exception as e:
            self.logger("[pc28] 写库失败: %s" % e)
        # 拉历史
        history = []
        try:
            hist_resp = self.api.get_history(self.history_size)
            history = issues_from_response(hist_resp)
        except Exception as e:
            self.logger("[pc28] 拉历史失败: %s（仅推当期）" % e)
        # 文本
        text = format_latest(issue, history=history, max_history=self.history_size)
        # 推送
        if send_fn is not None:
            gids = []
            try:
                gids = list(self.push_targets_fn() or [])
            except Exception as e:
                self.logger("[pc28] 取推送群列表失败: %s" % e)
            for gid in gids:
                if self._stop.is_set():
                    break
                try:
                    send_fn(gid, text)
                    if self.send_delay_sec > 0:
                        time.sleep(self.send_delay_sec)
                except Exception as e:
                    self.logger("[pc28] 推送到 %s 失败: %s" % (gid, e))
            self.logger("[pc28] 期号 %s 推送完成 群数=%d" % (issue.nbr, len(gids)))
        # 更新状态
        self.last_issue = issue.nbr
        self.push_count += 1
        try:
            self.store.mark_pushed(issue.nbr)
        except Exception:
            pass
        try:
            self.store.upsert_push_state(self.last_issue, self.push_count)
        except Exception:
            pass
        return True

    # ---------- 常驻循环 ----------
    def run_forever(self, send_fn=None):
        """常驻循环；stop_event 或 stop() 退出。"""
        self.logger("[pc28] fetcher 启动")
        while not self._stop.is_set():
            try:
                self.step_once(send_fn=send_fn)
            except Exception as e:
                self.logger("[pc28] step_once 异常: %s" % e)
                _sleep_sec(2)
        self.logger("[pc28] fetcher 已停止")


def main(argv=None):
    """CLI 入口：python -m pc28.fetcher [--once] [--api-key=...] [--base=...]"""
    import argparse
    import sys as _sys

    parser = argparse.ArgumentParser(description="PC28 fetcher")
    parser.add_argument("--once", action="store_true", help="只跑一次后退出")
    parser.add_argument("--api-key", default="yu28_c4aaa4ccc91a5bf8")
    parser.add_argument("--base", default="https://yu28.top")
    parser.add_argument("--history", type=int, default=DEFAULT_HISTORY)
    args = parser.parse_args(argv if argv is not None else _sys.argv[1:])

    api = Yu28Client(base_url=args.base, api_key=args.api_key)
    from .storage import NullStore
    store = NullStore(logger=lambda m: print(m))
    f = Fetcher(api=api, store=store, push_targets_fn=lambda: [],
                logger=lambda m: print(m), history_size=args.history)
    if args.once:
        f.step_once(send_fn=lambda gid, text: print("[send %s]\n%s" % (gid, text)))
    else:
        f.run_forever(send_fn=lambda gid, text: print("[send %s]\n%s" % (gid, text)))


if __name__ == "__main__":
    main()
