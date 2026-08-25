"""PC28 子系统：开奖抓取、落库、推送。

模块：
- api    : yu28.top HTTP 客户端
- format : 开奖卡片文本格式化
- storage: MySQL DAO（pc28_lottery / pc28_push_state）
- fetcher: 倒计时驱动循环（主循环）
- worker : 后台线程启动器，供 v2.runner 挂载
"""

__all__ = ["api", "format", "storage", "fetcher", "worker"]
