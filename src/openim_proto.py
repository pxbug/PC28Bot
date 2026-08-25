"""OpenIM WebSocket 推送消息 protobuf 动态解码。

protobuf 定义来源：OpenIM protocol/sdkws：
  message PushMessages {
    map<string, PullMsgs> Msgs = 1;
    map<string, PullMsgs> NotificationMsgs = 2;
  }
  message PullMsgs {
    repeated MsgData Msgs = 1;
    bool IsEnd = 2;
  }
"""
import base64
import json

from google.protobuf import descriptor_pb2, descriptor_pool, message_factory

_POOL = None
_PUSH = None
_MSG_DATA = None


def _build():
    global _POOL, _PUSH, _MSG_DATA
    file_proto = descriptor_pb2.FileDescriptorProto()
    file_proto.name = "openim.proto"
    file_proto.package = "openim"
    file_proto.syntax = "proto3"

    msg_data = file_proto.message_type.add()
    msg_data.name = "MsgData"
    msg_data_fields = [
        (1, "sendID", descriptor_pb2.FieldDescriptorProto.TYPE_STRING, descriptor_pb2.FieldDescriptorProto.LABEL_OPTIONAL),
        (2, "recvID", descriptor_pb2.FieldDescriptorProto.TYPE_STRING, descriptor_pb2.FieldDescriptorProto.LABEL_OPTIONAL),
        (3, "groupID", descriptor_pb2.FieldDescriptorProto.TYPE_STRING, descriptor_pb2.FieldDescriptorProto.LABEL_OPTIONAL),
        (4, "clientMsgID", descriptor_pb2.FieldDescriptorProto.TYPE_STRING, descriptor_pb2.FieldDescriptorProto.LABEL_OPTIONAL),
        (5, "serverMsgID", descriptor_pb2.FieldDescriptorProto.TYPE_STRING, descriptor_pb2.FieldDescriptorProto.LABEL_OPTIONAL),
        (6, "senderPlatformID", descriptor_pb2.FieldDescriptorProto.TYPE_INT32, descriptor_pb2.FieldDescriptorProto.LABEL_OPTIONAL),
        (7, "senderNickname", descriptor_pb2.FieldDescriptorProto.TYPE_STRING, descriptor_pb2.FieldDescriptorProto.LABEL_OPTIONAL),
        (8, "senderFaceUrl", descriptor_pb2.FieldDescriptorProto.TYPE_STRING, descriptor_pb2.FieldDescriptorProto.LABEL_OPTIONAL),
        (9, "sessionType", descriptor_pb2.FieldDescriptorProto.TYPE_INT32, descriptor_pb2.FieldDescriptorProto.LABEL_OPTIONAL),
        (10, "msgFrom", descriptor_pb2.FieldDescriptorProto.TYPE_INT32, descriptor_pb2.FieldDescriptorProto.LABEL_OPTIONAL),
        (11, "contentType", descriptor_pb2.FieldDescriptorProto.TYPE_INT32, descriptor_pb2.FieldDescriptorProto.LABEL_OPTIONAL),
        (12, "content", descriptor_pb2.FieldDescriptorProto.TYPE_STRING, descriptor_pb2.FieldDescriptorProto.LABEL_OPTIONAL),
        (14, "seq", descriptor_pb2.FieldDescriptorProto.TYPE_INT64, descriptor_pb2.FieldDescriptorProto.LABEL_OPTIONAL),
        (15, "sendTime", descriptor_pb2.FieldDescriptorProto.TYPE_INT64, descriptor_pb2.FieldDescriptorProto.LABEL_OPTIONAL),
        (16, "createTime", descriptor_pb2.FieldDescriptorProto.TYPE_INT64, descriptor_pb2.FieldDescriptorProto.LABEL_OPTIONAL),
        (17, "status", descriptor_pb2.FieldDescriptorProto.TYPE_INT32, descriptor_pb2.FieldDescriptorProto.LABEL_OPTIONAL),
        (18, "isRead", descriptor_pb2.FieldDescriptorProto.TYPE_BOOL, descriptor_pb2.FieldDescriptorProto.LABEL_OPTIONAL),
        (21, "atUserIDList", descriptor_pb2.FieldDescriptorProto.TYPE_STRING, descriptor_pb2.FieldDescriptorProto.LABEL_REPEATED),
    ]
    for num, name, typ, label in msg_data_fields:
        field = msg_data.field.add()
        field.number = num
        field.name = name
        field.type = typ
        field.label = label

    pull_msgs = file_proto.message_type.add()
    pull_msgs.name = "PullMsgs"
    f1 = pull_msgs.field.add()
    f1.number = 1
    f1.name = "Msgs"
    f1.type = descriptor_pb2.FieldDescriptorProto.TYPE_MESSAGE
    f1.label = descriptor_pb2.FieldDescriptorProto.LABEL_REPEATED
    f1.type_name = ".openim.MsgData"
    f2 = pull_msgs.field.add()
    f2.number = 2
    f2.name = "IsEnd"
    f2.type = descriptor_pb2.FieldDescriptorProto.TYPE_BOOL
    f2.label = descriptor_pb2.FieldDescriptorProto.LABEL_OPTIONAL

    push_messages = file_proto.message_type.add()
    push_messages.name = "PushMessages"
    for map_name, field_name in (("MsgsEntry", "Msgs"), ("NotificationMsgsEntry", "NotificationMsgs")):
        entry = push_messages.nested_type.add()
        entry.name = map_name
        entry.options.map_entry = True
        k = entry.field.add()
        k.number = 1
        k.name = "key"
        k.type = descriptor_pb2.FieldDescriptorProto.TYPE_STRING
        k.label = descriptor_pb2.FieldDescriptorProto.LABEL_OPTIONAL
        v = entry.field.add()
        v.number = 2
        v.name = "value"
        v.type = descriptor_pb2.FieldDescriptorProto.TYPE_MESSAGE
        v.label = descriptor_pb2.FieldDescriptorProto.LABEL_OPTIONAL
        v.type_name = ".openim.PullMsgs"
        f = push_messages.field.add()
        f.number = 1 if field_name == "Msgs" else 2
        f.name = field_name
        f.type = descriptor_pb2.FieldDescriptorProto.TYPE_MESSAGE
        f.label = descriptor_pb2.FieldDescriptorProto.LABEL_REPEATED
        f.type_name = ".openim.PushMessages.%s" % map_name

    pool = descriptor_pool.DescriptorPool()
    file_desc = pool.Add(file_proto)
    classes = message_factory.GetMessageClassesForFiles([file_desc.name], pool)
    _POOL = pool
    _PUSH = classes["openim.PushMessages"]
    _MSG_DATA = classes["openim.MsgData"]


def _get_classes():
    if _PUSH is None:
        _build()
    return _PUSH, _MSG_DATA


def _b2s(val):
    if isinstance(val, bytes):
        return val.decode("utf-8", errors="replace")
    return val


def decode_push(base64_str):
    """解码 base64 的 PushMessages，返回 (messages, notifications)。"""
    PushMessages, _ = _get_classes()
    raw = base64.b64decode(base64_str)
    pb = PushMessages()
    pb.ParseFromString(raw)

    def extract(items):
        result = []
        for conv_id, pull in items:
            for m in pull.Msgs:
                d = {}
                for name in (
                    "sendID", "recvID", "groupID", "senderNickname", "senderFaceUrl",
                    "sessionType", "msgFrom", "contentType", "content", "seq",
                    "sendTime", "createTime", "status", "isRead", "clientMsgID",
                    "serverMsgID", "senderPlatformID", "atUserIDList",
                ):
                    val = getattr(m, name, None)
                    if val is not None and val != "":
                        d[name] = val
                at_list = list(m.atUserIDList or [])
                if at_list:
                    d["atUserIDList"] = at_list
                if "content" in d:
                    d["content"] = _b2s(d["content"])
                result.append(d)
        return result

    msgs = extract(pb.Msgs.items())
    notifs = extract(pb.NotificationMsgs.items())
    return msgs, notifs


def make_msg_data(user_id, gid, text, now_ms, platform):
    """构造一条 MsgData（用于 WS 发送）。"""
    _, MsgData = _get_classes()
    m = MsgData()
    m.sendID = user_id
    m.recvID = gid
    m.groupID = gid
    if platform:
        m.senderPlatformID = int(platform)
    m.content = json.dumps({"content": text}, ensure_ascii=False)
    m.contentType = 101
    m.sessionType = 3
    m.msgFrom = 100
    m.sendTime = now_ms
    m.createTime = now_ms
    m.clientMsgID = __import__("uuid").uuid4().hex
    return m
