"""V2 异步 API 适配器：把同步 api_client 包成 asyncio（线程池执行）。"""
import asyncio
from concurrent.futures import ThreadPoolExecutor


class AsyncApi:
    def __init__(self, client, max_workers=64):
        self.client = client
        self._pool = ThreadPoolExecutor(max_workers=max_workers, thread_name_prefix="lajiao-api")

    async def _run(self, fn, *args, **kwargs):
        return await asyncio.get_event_loop().run_in_executor(self._pool, lambda: fn(*args, **kwargs))

    # 群
    async def get_group_list(self):
        return await self._run(self.client.get_group_list)

    async def get_group_members(self, gid):
        return await self._run(self.client.get_group_members, gid)

    async def get_members_info(self, gid, uids):
        return await self._run(self.client.get_members_info, gid, uids)

    async def get_users_info(self, uids):
        return await self._run(self.client.get_users_info, uids)

    async def get_blacklist(self, gid):
        return await self._run(self.client.get_blacklist, gid)

    async def get_group_max_seq(self, gid):
        return await self._run(self.client.get_group_max_seq, gid)

    # 动作
    async def kick(self, gid, uid):
        return await self._run(self.client.kick, gid, uid)

    async def mute(self, gid, uid, sec):
        return await self._run(self.client.mute, gid, uid, sec)

    async def unmute(self, gid, uid):
        return await self._run(self.client.unmute, gid, uid)

    async def recall_msg(self, gid, msg_id):
        return await self._run(self.client.recall_msg, gid, msg_id)

    async def set_member_nickname(self, gid, uid, nickname):
        return await self._run(self.client.set_member_nickname, gid, uid, nickname)

    async def add_blacklist(self, gid, uid):
        return await self._run(self.client.add_blacklist, gid, uid)

    async def remove_blacklist(self, gid, uid):
        return await self._run(self.client.remove_blacklist, gid, uid)

    async def send_http(self, gid, text):
        """HTTP 发送（备用通道）。"""
        return await self._run(self.client.send_msg, gid, text)

    async def leave_group(self, gid):
        return await self._run(self.client.leave_group, gid)

    async def join_group(self, gid, req_message=""):
        return await self._run(self.client.join_group, gid, req_message)

    async def get_group_info(self, gid):
        """通过群号/群ID查询群信息（含真实 groupID）。失败返回 None。"""
        return await self._run(self.client.get_group_info, gid)

    async def get_group_members_safe(self, gid):
        return await self._run(self.client.get_group_members_safe, gid)
