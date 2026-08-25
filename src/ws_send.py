"""通过 WS 发送消息（Go gob + gzip 帧，与官方客户端一致）。

帧结构（Go encoding/gob）：
  <类型定义 GeneralWsReq> <gob_uint(值消息长度)> <值消息>
值消息 = <ReqIdentifier 等固定头> <sendID> <operationID> <msgIncr> <Data值> <00>
Data 值 = <01> <gob_uint(MsgData 长度)> <MsgData protobuf>
"""
import json
import time
import uuid
import gzip

# 类型定义（固定，从官方客户端抓包提取）
HEADER_SCHEMA = bytes.fromhex(
    "657f0301010c47656e6572616c577352657101ff80000106010d5265714964656e7469666965720104000105546f6b656e"
    "010c00010653656e644944010c00010b4f7065726174696f6e4944010c0001074d7367496e6372010c00010444617461"
    "010a000000"
)
# 值消息固定头（ReqIdentifier=1003 编码 + SendID 字段增量）
VALUE_START = bytes.fromhex("ff8001fe07d602")

_DEFAULT_NICKNAME = "机器人V1"
_DEFAULT_FACE = ""


def gob_uint(x):
    """Go encoding/gob 无符号整数编码：<128 单字节，否则 <负字节数> <大端字节>。"""
    if x <= 0x7F:
        return bytes([x])
    n = (x.bit_length() + 7) // 8
    return bytes([256 - n]) + x.to_bytes(n, "big")


def _varint(n):
    out = b""
    while True:
        b = n & 0x7F
        n >>= 7
        if n:
            out += bytes([b | 0x80])
        else:
            out += bytes([b])
            break
    return out


def _f_str(num, s):
    b = s.encode("utf-8")
    return _varint(num << 3 | 2) + _varint(len(b)) + b


def _f_bytes(num, b):
    return _varint(num << 3 | 2) + _varint(len(b)) + b


def _f_var(num, v):
    return _varint(num << 3 | 0) + _varint(v)


def build_msgdata(user_id, gid, text, cmid, now_ms, group_name, nickname, face_url):
    """构造 MsgData protobuf（字段号与官方客户端一致）。"""
    content = json.dumps({"content": text}, ensure_ascii=False, separators=(",", ":"))
    msg_json = json.dumps({
        "clientMsgID": cmid,
        "conversationID": "sg_" + gid,
        "groupID": gid,
        "recvID": "",
        "contentType": 101,
    }, ensure_ascii=False, separators=(",", ":"))
    read_json = json.dumps({
        "groupHasReadInfo": {"hasReadCount": 0, "groupMemberCount": 20},
        "isPrivateChat": False, "burnDuration": 0, "hasReadTime": 0,
        "isEncryption": False, "inEncryptStatus": False,
    }, ensure_ascii=False, separators=(",", ":"))
    nested20 = (_f_str(1, group_name) + _f_str(2, text) + _f_str(3, msg_json)
                + _f_str(4, "default") + _f_var(5, 1))
    parts = [
        _f_str(1, user_id),
        _f_str(3, gid),
        _f_str(4, cmid),
        _f_var(6, 5),
        _f_str(7, nickname),
        _f_str(8, face_url),
        _f_var(9, 3),
        _f_var(10, 100),
        _f_var(11, 101),
        _f_str(12, content),
        _f_var(16, now_ms),
        _f_var(17, 1),
        _f_bytes(20, nested20),
        _f_str(22, read_json),
        b"\x00",
    ]
    return b"".join(parts)


def build_frame(user_id, gid, text, group_name="", nickname=_DEFAULT_NICKNAME, face_url=_DEFAULT_FACE):
    """构造完整的 WS 发送帧（未压缩）。"""
    cmid = uuid.uuid4().hex
    now_ms = int(time.time() * 1000)
    msgdata = build_msgdata(user_id, gid, text, cmid, now_ms, group_name, nickname, face_url)
    data = msgdata[:-1]  # 去掉结尾 00（gob 帧终止符）
    op = str(uuid.uuid4())
    incr = "%s_%d%06d" % (user_id, now_ms, 1)
    data_value = b"\x01" + gob_uint(len(data)) + data
    payload = (VALUE_START
               + b"\x07" + user_id.encode()  # sendID（gob 字符串）
               + b"\x01\x24" + op.encode()
               + b"\x01\x1b" + incr.encode()
               + data_value
               + b"\x00")
    return HEADER_SCHEMA + gob_uint(len(payload)) + payload


def build_gzip_frame(user_id, gid, text, group_name="", nickname=_DEFAULT_NICKNAME, face_url=_DEFAULT_FACE):
    """构造 gzip 压缩的 WS 发送帧。"""
    return gzip.compress(build_frame(user_id, gid, text, group_name, nickname, face_url))


class WsSendConn:
    """短连接发送器。

    关键：js 接收连接发 gob 帧服务器不投递，需用 compression=gzip 连接发送。
    但持久 gzip 连接与 js 监听并存会破坏推送接收，因此采用【短连接】：
    每次发送临时连一个 gzip 连接，发送后立即关闭，只保留 js 监听一个常驻连接。
    """
    def __init__(self, user_id, im_token, platform=5):
        import threading as _t
        self.user_id = str(user_id)
        self.im_token = im_token
        self.platform = platform
        self._loop = None
        self._thread = None
        self._running = False
        self._thread_mod = _t

    def start(self):
        self._running = True
        self._thread = self._thread_mod.Thread(target=self._run, daemon=True)
        self._thread.start()

    def stop(self):
        self._running = False
        if self._loop is not None:
            try:
                self._loop.call_soon_threadsafe(self._loop.stop)
            except Exception:
                pass

    def _url(self):
        import uuid as _uuid
        opid = str(_uuid.uuid4()).replace("-", "")
        return (
            "wss://ws.lajiaoliao.com?compression=gzip&isBackground=false&isMsgResp=true&operationID="
            + opid
            + "&platformID="
            + str(self.platform)
            + "&sendID="
            + self.user_id
            + "&token="
            + str(self.im_token)
        )

    def _run(self):
        import asyncio as _aio
        _aio.set_event_loop(_aio.new_event_loop())
        self._loop = _aio.get_event_loop()
        self._loop.run_forever()

    def send_msg(self, conv_id, text, group_name="", nickname=_DEFAULT_NICKNAME, face_url=""):
        import asyncio as _aio
        import time as _time
        if self._loop is None:
            raise Exception("send conn not ready")
        gid = conv_id[3:] if conv_id.startswith("sg_") else conv_id
        frame = build_gzip_frame(self.user_id, gid, text, group_name=group_name, nickname=nickname, face_url=face_url)
        deadline = _time.time() + 15
        while _time.time() < deadline:
            try:
                fut = _aio.run_coroutine_threadsafe(self._one_shot_send(frame), self._loop)
                fut.result(timeout=12)
                return
            except Exception:
                _time.sleep(1)
                continue
        raise Exception("send conn send failed")

    async def _one_shot_send(self, frame):
        import asyncio as _aio
        import ssl as _ssl
        import websockets as _ws_mod
        ssl_ctx = _ssl.create_default_context()
        ssl_ctx.check_hostname = False
        ssl_ctx.verify_mode = _ssl.CERT_NONE
        ws = await _ws_mod.connect(
            self._url(),
            ping_interval=None,
            ping_timeout=None,
            close_timeout=5,
            max_size=1048576,
            ssl=ssl_ctx,
        )
        try:
            await ws.send(frame)
            # 等待片刻，避免连接立刻关闭导致消息丢失
            await _aio.sleep(0.3)
        finally:
            try:
                await ws.close()
            except Exception:
                pass
