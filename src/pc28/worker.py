"""PC28 后台 Worker 启动器：把 Fetcher 挂到 runner 线程。

用法（v2/runner.py）：
    from pc28.worker import start_lottery_worker
    start_lottery_worker(runner, send_func=self._ws_send)
"""
import threading
import time

from .api import Yu28Client
from .fetcher import Fetcher, DEFAULT_HISTORY, DEFAULT_COUNTDOWN_EXTRA_SEC, DEFAULT_POST_RESULT_DELAY_SEC
from .storage import build_store


def _resolve_pc28_config(config):
    cfg = (config or {}).get("pc28") or {}
    return {
        "enabled": cfg.get("enabled", True),
        "api_base": cfg.get("api_base", "https://yu28.top"),
        "api_key": cfg.get("api_key", ""),
        "history_size": int(cfg.get("history_size", DEFAULT_HISTORY)),
        "countdown_extra_sec": int(cfg.get("countdown_extra_sec", DEFAULT_COUNTDOWN_EXTRA_SEC)),
        "post_result_delay_sec": int(cfg.get("post_result_delay_sec", DEFAULT_POST_RESULT_DELAY_SEC)),
        "mysql": cfg.get("mysql") or {},
    }


def start_lottery_worker(runner, send_func=None, logger=None):
    """启动一个后台线程运行 PC28 fetcher。

    runner: v2.runner.V2Runner 实例（取其 config / store / client）
    send_func(gid, text): 同步发送函数，默认用 runner._ws_send
    返回 (thread, fetcher)；thread 已 start()。失败时 fetcher is None。
    """
    log = logger or (lambda m: None)
    cfg = _resolve_pc28_config(getattr(runner, "config", {}) or {})
    if not cfg["enabled"]:
        log("[pc28.worker] 未启用 (pc28.enabled=false)")
        return None, None
    if not cfg["api_key"]:
        log("[pc28.worker] 缺少 api_key，跳过启动")
        return None, None
    api = Yu28Client(base_url=cfg["api_base"], api_key=cfg["api_key"])
    store = build_store({"enabled": True, "mysql": cfg["mysql"]}, logger=log)
    sf = send_func or getattr(runner, "_ws_send", None) or (lambda gid, text: None)

    def _targets():
        # 取所有开启了开奖推送的群
        try:
            snap = runner.store.snapshot()
        except Exception:
            snap = {}
        return [gid for gid, st in (snap or {}).items() if st.get("pc28_push_enabled")]

    fetcher = Fetcher(
        api=api,
        store=store,
        push_targets_fn=_targets,
        logger=log,
        history_size=cfg["history_size"],
        countdown_extra_sec=cfg["countdown_extra_sec"],
        post_result_delay_sec=cfg["post_result_delay_sec"],
    )
    # 恢复 last_issue / push_count（从存储读）
    try:
        prev = store.get_push_state()
        if prev:
            fetcher.last_issue = str(prev.get("last_issue") or "")
            try:
                fetcher.push_count = int(prev.get("push_count") or 0)
            except Exception:
                fetcher.push_count = 0
    except Exception as e:
        log("[pc28.worker] 恢复 push_state 失败: %s" % e)
    thread = threading.Thread(
        target=fetcher.run_forever,
        kwargs={"send_fn": sf},
        name="pc28-lottery-worker",
        daemon=True,
    )
    thread.start()
    log("[pc28.worker] 已启动")
    return thread, fetcher


def stop_lottery_worker(fetcher):
    if fetcher is not None:
        try:
            fetcher.stop()
        except Exception:
            pass
