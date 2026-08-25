"""压力测试 v2：20 个 5000 人群 + 混合违规，模拟真实时间分布。

场景设计（贴近真实群运营）：
- 广告消息：大量不同人各自发一条 → 应快速撤回（并行撤回通道）
- 刷屏消息：少量刷屏号在 10 秒窗口内连发 → 触发撤回+升级禁言
- 图片/表情刷屏：同一人连发同类媒体 → 触发撤回
- 正常消息：不应有任何处理，验证无误伤

测量指标：
1. 消息识别吞吐（条/秒）
2. 广告撤回端到端延迟：消息进入 → 撤回完成
3. 刷屏号升级延迟
4. 队列积压/丢弃
"""
import sys
import os
import time
import threading
from concurrent.futures import ThreadPoolExecutor

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, ROOT)
sys.path.insert(0, os.path.join(ROOT, "src"))

from v2.runtime import Runtime
from v2.state import GroupStateStore

API_DELAY_MS = 50        # 模拟真实网络延迟
GROUPS = 20              # 20 个群
MEMBERS_PER_GROUP = 5000 # 每群 5000 人

AD_PER_GROUP = 200       # 每群广告消息（200 个不同人）
NORMAL_PER_GROUP = 50    # 每群正常消息
SPAMMERS = 20            # 每群刷屏号
SPAM_EACH = 4            # 每个刷屏号发 4 条（触发升级禁言）
THREADS = 40


class MockClient:
    def __init__(self):
        self.lock = threading.Lock()
        self.recalls = 0
        self.kicks = 0
        self.mutes = 0
        self.blacklists = 0
        self.recall_times = []   # 撤回完成耗时
        self.recall_start = {}   # msg_id -> 进入队列时间

    def _sleep(self):
        time.sleep(API_DELAY_MS / 1000)

    def get_group_members(self, gid):
        return [{"userID": str(i), "nickname": "成员%d" % i,
                 "roleLevel": (100 if i == 1 else 60 if i in (2, 3) else 0)}
                for i in range(MEMBERS_PER_GROUP)]

    def get_members_info(self, gid, uids):
        return [{"userID": str(u), "roleLevel": 0} for u in uids]

    def recall_msg(self, gid, msg_id):
        self._sleep()
        with self.lock:
            self.recalls += 1
            st = self.recall_start.pop(str(msg_id), None)
            if st is not None:
                self.recall_times.append(time.time() - st)

    def kick(self, gid, uid):
        self._sleep()
        with self.lock:
            self.kicks += 1

    def mute(self, gid, uid, sec):
        self._sleep()
        with self.lock:
            self.mutes += 1

    def unmute(self, gid, uid, sec=0):
        self._sleep()

    def set_member_nickname(self, gid, uid, nickname):
        self._sleep()

    def add_blacklist(self, gid, uid):
        self._sleep()
        with self.lock:
            self.blacklists += 1

    def remove_blacklist(self, gid, uid):
        self._sleep()

    def get_group_list(self):
        return [{"groupID": "g%d" % i, "groupName": "群%d" % i, "ownerUserID": "owner%d" % i} for i in range(GROUPS)]


def main():
    config = {
        "permissions": {"superAdminIds": ["SA1"]},
        "robot": {"self_account_id": "BOT", "ignore_self_message": True},
        "monitors": {
            "ad": {"enabled": True, "digit_threshold": 5, "letter_threshold": 5, "url_detection": True, "patterns": [], "long_message_chars": 0, "mute_every": 3, "kick_after_mutes": 3, "mute_minutes": 60, "kick_after": 0},
            "spam": {"enabled": True, "window_ms": 10000, "threshold": 3, "mute_every": 3, "kick_after_mutes": 3, "mute_minutes": 60, "kick_after": 0, "recall_mute_count": 5, "recall_mute_window_ms": 5000, "recall_mute_minutes": 10},
            "blacklist": {"action": "kick_member", "recall": True},
        },
        "safety": {"allow_send": True, "send_min_interval_ms": 1000, "never_act_on_self": True, "never_kick_owner": True, "never_kick_admins": True},
        "queue": {"max_pending_per_group": 2000, "action_cooldown_ms": 3000, "recall_cooldown_ms": 300, "recall_workers": 8},
        "state": {"save_interval_ms": 600000},
    }

    store = GroupStateStore(path=None)
    client = MockClient()

    from v2.api import AsyncApi
    api = AsyncApi(client)

    for i in range(GROUPS):
        store.set_license_days("g%d" % i, 30)

    sent = []
    runtime = Runtime(config, store, api, send_func=lambda gid, t: sent.append((gid, t)), logger=lambda m: None)
    runtime.start()
    time.sleep(0.3)

    # 预置群元信息（成员昵称缓存 + 角色缓存）
    for i in range(GROUPS):
        gid = "g%d" % i
        members = client.get_group_members(gid)
        meta = {str(m["userID"]): m["nickname"] for m in members}
        runtime.set_group_meta(gid, "群%d" % i, "owner%d" % i, meta)
        runtime.protector.seed_roles(gid, members)

    # 构造消息（按时间顺序，模拟真实流量）
    all_msgs = []
    # 阶段1：广告（200个不同人各发1条）+ 正常（50人）
    for gi in range(GROUPS):
        gid = "g%d" % gi
        for i in range(AD_PER_GROUP):
            uid = 10 + i  # 避开 1-3 的群主/管理
            mid = "ad_%d_%d" % (gi, i)
            client.recall_start[mid] = None  # 占位
            all_msgs.append({"groupID": gid, "sendID": str(uid),
                             "content": "加我微信 https://www.example%d.com/goods?id=%d 限时抢购" % (i, i),
                             "serverMsgID": mid, "contentType": 101})
        for i in range(NORMAL_PER_GROUP):
            uid = 5000 - i
            all_msgs.append({"groupID": gid, "sendID": str(uid),
                             "content": "大家今天讨论的话题%d" % i,
                             "serverMsgID": "n_%d_%d" % (gi, i), "contentType": 101})

    # 阶段2：刷屏（20个号各连发4条，模拟10秒窗口内的刷屏攻击）
    for gi in range(GROUPS):
        gid = "g%d" % gi
        for si in range(SPAMMERS):
            uid = 100 + si
            for k in range(SPAM_EACH):
                mid = "sp_%d_%d_%d" % (gi, si, k)
                all_msgs.append({"groupID": gid, "sendID": str(uid),
                                 "content": "【置顶】加群领红包加群领红包加群领红包",
                                 "serverMsgID": mid, "contentType": 101})
            # 图片刷屏：同一刷屏号连发 4 张图
            for k in range(SPAM_EACH):
                mid = "img_%d_%d_%d" % (gi, si, k)
                all_msgs.append({"groupID": gid, "sendID": str(uid),
                                 "content": '{"uuid":"img_%d_%d_%d","url":"https://cdn.x.com/a%d.png"}' % (gi, si, k, si),
                                 "serverMsgID": mid, "contentType": 102})

    total = len(all_msgs)
    # 为每条广告消息设置 recall_start 计时点
    for m in all_msgs:
        if m["serverMsgID"].startswith("ad_"):
            client.recall_start[m["serverMsgID"]] = time.time()  # 实际进入时再覆盖

    start = time.time()
    def feed(msgs):
        for m in msgs:
            if m["serverMsgID"].startswith("ad_"):
                client.recall_start[m["serverMsgID"]] = time.time()
            runtime.on_message(m)
            time.sleep(0.0005)  # 模拟真实到达间隔

    chunks = [all_msgs[i::THREADS] for i in range(THREADS)]
    with ThreadPoolExecutor(max_workers=THREADS) as ex:
        list(ex.map(feed, chunks))
    ingest_done = time.time()
    ingest_elapsed = ingest_done - start

    def total_pending():
        return sum(
            w.action_queue.qsize() + w.reply_queue.qsize() + w.recall_queue.qsize()
            for w in runtime.workers.values()
        )

    # 等待排空（最多 120 秒）
    deadline = time.time() + 120
    while time.time() < deadline:
        p = total_pending()
        if p == 0:
            time.sleep(1)
            if total_pending() == 0:
                break
        time.sleep(0.5)
    drain_end = time.time()

    actions = client.recalls + client.kicks + client.mutes + client.blacklists
    drain_elapsed = drain_end - ingest_done
    viol = (AD_PER_GROUP + NORMAL_PER_GROUP) * GROUPS  # 实际需处理的
    # 广告消息数量
    ad_total = AD_PER_GROUP * GROUPS
    # 刷屏消息（文本+图片）触发次数
    spam_total = SPAMMERS * SPAM_EACH * 2 * GROUPS

    print("=" * 64)
    print("压力测试结果 v2（真实场景）")
    print("=" * 64)
    print("群数: %d | 每群成员: %d | 注入消息: %d" % (GROUPS, MEMBERS_PER_GROUP, total))
    print("构成: 广告=%d 正常=%d 刷屏文本=%d 刷屏图片=%d" % (
        ad_total, NORMAL_PER_GROUP * GROUPS, SPAMMERS * SPAM_EACH * GROUPS, SPAMMERS * SPAM_EACH * GROUPS))
    print("-" * 64)
    print("消息灌入: %.2f 秒 | 识别吞吐: %.0f 条/秒" % (ingest_elapsed, total / ingest_elapsed))
    print("动作排空: %.2f 秒 | 动作吞吐: %.1f 次/秒" % (drain_elapsed, actions / drain_elapsed if drain_elapsed > 0 else 0))
    print("-" * 64)
    print("动作: 撤回=%d 踢出=%d 禁言=%d 拉黑=%d" % (client.recalls, client.kicks, client.mutes, client.blacklists))
    pend = total_pending()
    print("剩余未处理队列: %d" % pend)
    print("-" * 64)
    # 端到端延迟
    if client.recall_times:
        p50 = sorted(client.recall_times)[len(client.recall_times) // 2]
        p95 = sorted(client.recall_times)[int(len(client.recall_times) * 0.95)]
        print("广告撤回端到端延迟: 中位 %.0f ms | P95 %.0f ms (共%d条)" % (
            p50 * 1000, p95 * 1000, len(client.recall_times)))
    else:
        print("广告撤回: 未捕获到延迟数据")
    # 每群平均
    print("-" * 64)
    # 期望撤回：广告全部 + 刷屏每条
    print("期望撤回: 广告 %d + 刷屏 %d = %d | 实际: %d" % (
        ad_total, spam_total, ad_total + spam_total, client.recalls))
    print("撤回达成率: %.1f%%" % (client.recalls / (ad_total + spam_total) * 100))
    print("=" * 64)

    runtime.stop()
    time.sleep(0.5)
    return 0


if __name__ == "__main__":
    sys.exit(main())
