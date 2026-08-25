"""Real-time group message listener via OpenIM WebSocket protocol.

连接：wss://ws.lajiaoliao.com?sdkType=js&isBackground=false&isMsgResp=true&operationID=...
心跳：30s 无数据 → ping；断线 sleep(5) 重连。
发送：fire-and-forget（WS 发送消息）。
"""
import asyncio
import base64
import gzip
import json
import ssl
import threading
import time
import uuid

import websockets

import openim_proto
import ws_send


def _make_ws_url(user_id, im_token, platform):
    opid = str(uuid.uuid4()).replace("-", "")
    return (
        "wss://ws.lajiaoliao.com?sdkType=js&isBackground=false&isMsgResp=true&operationID="
        + opid
        + "&platformID="
        + str(platform)
        + "&sendID="
        + str(user_id)
        + "&token="
        + str(im_token)
    )


class MessageListener:
    def __init__(self, user_id, im_token, platform=5):
        self.user_id = user_id
        self.im_token = im_token
        self.platform = platform
        self._loop = None
        self._thread = None
        self._running = False
        self._ws = None
        self._pending_responses = {}
        self.on_message = None
        self.on_error = None
        self.on_close = None
        self.on_connected = None
        self.logger = None
        # 长连接兜底：超过该时长无任何数据则强制重连（防服务端静默挂起）
        self.idle_reconnect_ms = 120000

    def start(self):
        self._running = True
        self._thread = threading.Thread(target=self._run_loop, daemon=True)
        self._thread.start()

    def stop(self):
        self._running = False
        if self._loop is not None:
            try:
                self._loop.call_soon_threadsafe(self._close_ws)
                self._loop.call_soon_threadsafe(self._loop.stop)
            except Exception:
                pass

    def _close_ws(self):
        try:
            if self._ws is not None:
                asyncio.create_task(self._ws.close())
        except Exception:
            pass

    def send_msg(self, conv_id, text, group_name="", nickname="", face_url=""):
        """Send via WebSocket (Go gob + gzip 帧，与官方客户端一致，fire-and-forget)。"""
        if self._loop is None:
            raise Exception("WebSocket not connected")
        gid = conv_id[3:] if conv_id.startswith("sg_") else conv_id
        frame = ws_send.build_gzip_frame(
            self.user_id, gid, text,
            group_name=group_name, nickname=nickname, face_url=face_url,
        )
        deadline = time.time() + 15
        while time.time() < deadline:
            ws = self._ws
            if ws is not None and self._loop.is_running():
                try:
                    fut = asyncio.run_coroutine_threadsafe(self._async_send(ws, frame), self._loop)
                    fut.result(timeout=10)
                    return
                except Exception:
                    time.sleep(1)
                    continue
            time.sleep(1)
        raise Exception("WebSocket not connected")

    async def _async_send(self, ws, data):
        if isinstance(data, bytes):
            await ws.send(data)
        elif isinstance(data, str):
            await ws.send(data.encode("utf-8"))
        else:
            await ws.send(json.dumps(data))

    async def _async_send_and_wait(self, payload, opid):
        fut = self._loop.create_future()
        self._pending_responses[opid] = fut
        await self._async_send(payload)
        return await asyncio.wait_for(fut, timeout=12)

    def _on_response(self, data):
        opid = data.get("operationID", "")
        fut = self._pending_responses.pop(opid, None)
        if fut is not None and not fut.done():
            fut.set_result(data)

    def _run_loop(self):
        asyncio.set_event_loop(asyncio.new_event_loop())
        self._loop = asyncio.get_event_loop()
        self._pending_responses = {}
        self._loop.run_until_complete(self._ws_listen())

    async def _ws_listen(self):
        consecutive = 0
        ssl_ctx = ssl.create_default_context()
        ssl_ctx.check_hostname = False
        ssl_ctx.verify_mode = ssl.CERT_NONE
        while self._running:
            try:
                ws = await websockets.connect(
                    _make_ws_url(self.user_id, self.im_token, self.platform),
                    ping_interval=20,
                    ping_timeout=10,
                    close_timeout=5,
                    max_size=1048576,
                    ssl=ssl_ctx,
                    proxy=None,  # 强制直连，不走系统/环境变量代理（防 Fiddler 等抓包代理干扰）
                )
                consecutive = 0
                self._ws = ws
                if self.on_connected:
                    try:
                        self.on_connected()
                    except Exception:
                        pass
                if self.on_message:
                    try:
                        self.on_message({"type": "connected"})
                    except Exception:
                        pass
                # 长连接存活兜底：若服务端静默挂起（不发数据也不发 close），
                # 长时间无任何数据则主动断开重连，避免"看似在线实则收不到消息"。
                last_data_ms = int(time.time() * 1000)
                while self._running:
                    try:
                        msg = await asyncio.wait_for(ws.recv(), timeout=20)
                        last_data_ms = int(time.time() * 1000)
                    except asyncio.TimeoutError:
                        if int(time.time() * 1000) - last_data_ms >= self.idle_reconnect_ms:
                            if self.on_error:
                                try:
                                    self.on_error(Exception("[ws] 超过 %dms 无数据，强制重连" % self.idle_reconnect_ms))
                                except Exception:
                                    pass
                            try:
                                await ws.close()
                            except Exception:
                                pass
                            break
                        continue
                    data = None
                    if isinstance(msg, bytes):
                        try:
                            txt = msg.decode("utf-8")
                        except Exception:
                            try:
                                txt = gzip.decompress(msg).decode("utf-8")
                            except Exception:
                                txt = None
                        if txt is not None:
                            try:
                                data = json.loads(txt)
                            except Exception:
                                data = None
                    elif isinstance(msg, str):
                        try:
                            data = json.loads(msg)
                        except Exception:
                            data = None
                    if not isinstance(data, dict):
                        continue
                    if data.get("operationID") and data.get("operationID") in self._pending_responses:
                        self._on_response(data)
                        continue
                    if self.on_message:
                        try:
                            self.on_message(data)
                        except Exception:
                            pass
            except Exception as e:
                consecutive += 1
                # 诊断细节：异常类型 + close frame（code/reason），判断是互踢/代理断开/服务端关闭
                diag = type(e).__name__
                rcvd = getattr(e, "rcvd", None)
                if rcvd is not None:
                    diag += " code=%s reason=%s" % (getattr(rcvd, "code", "?"), getattr(rcvd, "reason", ""))
                sent = getattr(e, "sent", None)
                if sent is not None:
                    diag += " sent_code=%s" % getattr(sent, "code", "?")
                if self.on_error:
                    try:
                        self.on_error(Exception("[ws] 断开: %s | %s" % (diag, str(e)[:120])))
                        if consecutive == 5:
                            self.on_error(Exception("[ws] 连续 %d 次连接失败，请检查账号登录态/token 是否过期" % consecutive))
                    except Exception:
                        pass
                try:
                    self._ws = None
                except Exception:
                    pass
                # 指数退避：3s→5s→10s→20s→40s→60s（持续失败时避免空转）
                backoff = min(60, 3 * (2 ** min(consecutive - 1, 5)))
                await asyncio.sleep(backoff)
