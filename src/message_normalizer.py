"""WS 推送 → 消息字典归一化。

把 message_listener 收到的原始推送 dict 转为 _process_msg_dict 需要的消息字典。
"""
import json


def parse_content(m):
    """从消息字典提取文本内容。content 可能是 JSON 字符串或 dict。"""
    raw = m.get("content", "")
    if isinstance(raw, dict):
        inner = raw.get("content") or raw.get("text")
        return inner if isinstance(inner, str) else ""
    if isinstance(raw, str):
        try:
            parsed = json.loads(raw)
        except Exception:
            return raw
        if isinstance(parsed, dict):
            txt = parsed.get("text") or parsed.get("content") or ""
            return txt if isinstance(txt, str) else ""
        if isinstance(parsed, str):
            return parsed
    return ""


def normalize_ws_data(data):
    """从 WS 推送 data 中提取消息列表。

    支持格式：{'raw': <base64 push>} 或 {'data': ...} 或直接消息 dict 列表。
    返回 (msgs, notifs)。
    """
    msgs = []
    notifs = []
    if isinstance(data, list):
        return data, []
    if not isinstance(data, dict):
        return [], []
    raw = data.get("raw") or data.get("data") or data
    if isinstance(raw, str):
        from openim_proto import decode_push
        try:
            msgs, notifs = decode_push(raw)
        except Exception:
            msgs, notifs = [], []
    elif isinstance(raw, dict):
        b64 = raw.get("msgData") or raw.get("data") or ""
        if isinstance(b64, str):
            from openim_proto import decode_push
            try:
                msgs, notifs = decode_push(b64)
            except Exception:
                msgs, notifs = [], []
        elif isinstance(b64, list):
            msgs = b64
    elif isinstance(raw, list):
        msgs = raw
    return msgs, notifs


def to_msg_dict(m):
    """把 openim_proto.MsgData 解出的 dict 转成运行时消息字典。"""
    raw_content = m.get("content", "")
    return {
        "groupID": m.get("groupID", ""),
        "conversationID": m.get("conversationID", ""),
        "sendID": m.get("sendID", ""),
        "senderNickname": m.get("senderNickname", ""),
        "serverMsgID": m.get("serverMsgID", ""),
        "clientMsgID": m.get("clientMsgID", ""),
        "contentType": m.get("contentType", 0),
        "content": parse_content({"content": raw_content}),
        "seq": m.get("seq", 0),
        "sendTime": m.get("sendTime", 0),
        "atUserIDList": m.get("atUserIDList", []),
    }
