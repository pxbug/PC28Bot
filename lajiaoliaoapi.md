# 辣椒聊 API 文档

> 本文档记录通过 Fiddler 抓包获取的辣椒聊 IM 接口信息

---

## 🔑 通用 Headers（所有 API 必须包含）

```http
token: <JWT>
operationID: <UUID>
deviceID: <device_id>
version: "1.0.2"
platform: 3
Content-Type: application/json
```

### Headers 字段说明

| 字段 | 格式 | 说明 |
|------|------|------|
| `token` | JWT | 登录后获取的认证令牌 |
| `operationID` | UUID | 请求唯一标识，格式如 `5d00eeff-7373-4fa0-89bf-dcc3ef92117d` |
| `deviceID` | 字符串 | 设备标识，格式如 `device_windows_xxx` |
| `version` | 字符串 | 版本号，如 `"1.0.2"` |
| `platform` | 数字 | 平台类型，`3` 代表 Windows |
| `Content-Type` | 字符串 | 固定为 `application/json` |

---

## 📡 API 接口列表

### 1. 获取群信息

**请求：**
```
POST https://im-api.lajiaoliao.com/group/get_groups_info
```

**请求 Body：**
```json
{
  "groupIDs": ["388888"]
}
```

**请求示例：**
```http
POST /group/get_groups_info HTTP/1.1
Host: im-api.lajiaoliao.com
token: eyJhbGciOiJIUzI1NiIs...
operationID: 5d00eeff-7373-4fa0-89bf-dcc3ef92117d
deviceID: device_windows_b2ffdd41-c26b-428a-b72c-e264d6d94ec6
version: 1.0.2
platform: 3
Content-Type: application/json

{"groupIDs":["388888"]}
```

**响应 Body：**
```json
{
  "errCode": 0,
  "errMsg": "",
  "errDlt": "",
  "data": {
    "groupInfos": [
      {
        "groupID": "38066455",
        "groupName": "久久网.COM【清华北大落榜生交流群】",
        "notification": "",
        "introduction": "",
        "faceURL": "https://lj-im-chat-01.lajiaoliao.com/image/...",
        "ownerUserID": "",
        "createTime": 1784455288761,
        "memberCount": 0,
        "ex": "",
        "status": 0,
        "creatorUserID": "",
        "groupType": 2,
        "needVerification": 0,
        "lookMemberInfo": 0,
        "applyMemberFriend": 0,
        "notificationUpdateTime": 0,
        "notificationUserID": "",
        "maxMemberCount": 0,
        "maxAdminCount": 0,
        "numbers": [
          {
            "number": "388888",
            "isSpecial": true,
            "grade": "premium",
            "isPremium": true,
            "status": 1
          }
        ],
        "groupEntitlementInfo": null
      }
    ]
  }
}
```

**响应字段说明：**

| 字段 | 类型 | 说明 |
|------|------|------|
| `errCode` | number | 错误码，`0` 表示成功 |
| `errMsg` | string | 错误信息 |
| `data.groupInfos` | array | 群信息数组 |
| `groupID` | string | 群 ID |
| `groupName` | string | 群名称 |
| `faceURL` | string | 群头像 URL |
| `groupType` | number | 群类型 |
| `needVerification` | number | 加入是否需要验证 |
| `memberCount` | number | 成员数量 |
| `numbers` | array | 群号码信息 |

---

### 2. 获取已加入群列表

**请求：**
```
POST https://im-api.lajiaoliao.com/group/get_joined_group_list
```

**请求 Body：**
```json
{
  "fromUserID": "2640787",
  "pagination": {
    "pageNumber": 1,
    "showNumber": 100
  }
}
```

**请求示例：**
```http
POST /group/get_joined_group_list HTTP/1.1
Host: im-api.lajiaoliao.com
token: eyJhbGciOiJIUzI1NiIs...
operationID: b74fe337-0708-4fb4-83fb-2e21e408c1f4
deviceID: device_windows_b2ffdd41-c26b-428a-b72c-e264d6d94ec6
version: 1.0.2
platform: 3
Content-Type: application/json

{"fromUserID":"2640787","pagination":{"pageNumber":1,"showNumber":100}}
```

**响应 Body：**
```json
{
  "errCode": 0,
  "errMsg": "",
  "errDlt": "",
  "data": {
    "total": 1,
    "groups": [
      {
        "groupID": "41358648",
        "groupName": "测试",
        "notification": "",
        "introduction": "",
        "faceURL": "https://lj-im-chat-01.lajiaoliao.com/default_group_avatar.png",
        "ownerUserID": "9076382",
        "createTime": 1786868204211,
        "memberCount": 4,
        "ex": "{\"groupStatus\":0,\"maxAdminCount\":5,\"maxMemberCount\":200,...}",
        "status": 0,
        "creatorUserID": "",
        "groupType": 2,
        "needVerification": 0,
        "lookMemberInfo": 0,
        "applyMemberFriend": 0,
        "notificationUpdateTime": 0,
        "notificationUserID": "",
        "maxMemberCount": 200,
        "maxAdminCount": 5,
        "groupEntitlementInfo": {
          "memberLimit": 200,
          "adminLimit": 5,
          "groupStatus": 0,
          "secretChatEnabled": false,
          "meetingCreationEnabled": false,
          "groupPackageID": "91452bc5-fe85-4aa1-a68a-aeaf1645fc3d",
          "groupPackageLevel": "NORMAL_GROUP"
        }
      }
    ]
  }
}
```

**请求参数说明：**

| 字段 | 类型 | 说明 |
|------|------|------|
| `fromUserID` | string | 当前用户 ID |
| `pagination.pageNumber` | number | 页码，从 1 开始 |
| `pagination.showNumber` | number | 每页数量 |

**响应字段说明：**

| 字段 | 类型 | 说明 |
|------|------|------|
| `data.total` | number | 群总数 |
| `data.groups` | array | 群信息数组 |
| `groupID` | string | 群 ID |
| `groupName` | string | 群名称 |
| `ownerUserID` | string | 群主用户 ID |
| `memberCount` | number | 成员数量 |
| `maxMemberCount` | number | 最大成员数 |
| `groupType` | number | 群类型 |
| `needVerification` | number | 加入是否需要验证 |

---

### 3. 进群 / 加入群

**请求：**
```
POST https://im-api.lajiaoliao.com/group/join_group
```

**请求 Body：**
```json
{
  "groupID": "38066455",
  "reqMessage": "",
  "joinSource": 3,
  "inviterUserID": "2640787",
  "ex": ""
}
```

**请求示例：**
```http
POST /group/join_group HTTP/1.1
Host: im-api.lajiaoliao.com
token: eyJhbGciOiJIUzI1NiIs...
operationID: 17855100-1a11-453f-aa05-15cb72bb033c
content-type: application/json
...

{"groupID":"38066455","reqMessage":"","joinSource":3,"inviterUserID":"2640787","ex":""}
```

**响应 Body：**
```json
{
  "errCode": 0,
  "errMsg": "",
  "errDlt": ""
}
```

**请求参数说明：**

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `groupID` | string | ✅ | 目标群 ID |
| `reqMessage` | string | ❌ | 申请入群的留言，可为空 |
| `joinSource` | number | ✅ | 入群来源，`3` 表示通过群号搜索 |
| `inviterUserID` | string | ✅ | 邀请人用户 ID（自己的 ID） |
| `ex` | string | ❌ | 扩展字段，可为空 |

**joinSource 枚举值：**

| 值 | 说明 |
|----|------|
| 1 | 通过群链接 |
| 2 | 通过朋友邀请 |
| 3 | 通过群号搜索 |
| 4 | 通过二维码 |

---

### 4. 退群 / 退出群

**请求：**
```
POST https://im-api.lajiaoliao.com/group/quit_group
```

**请求 Body：**
```json
{
  "groupID": "38066455",
  "userID": "2640787"
}
```

**请求示例：**
```http
POST /group/quit_group HTTP/1.1
Host: im-api.lajiaoliao.com
token: eyJhbGciOiJIUzI1NiIs...
operationID: b92b1432-0dfe-4e4e-af10-a1f588b667bf
content-type: application/json
...

{"groupID":"38066455","userID":"2640787"}
```

**响应 Body：**
```json
{
  "errCode": 0,
  "errMsg": "",
  "errDlt": ""
}
```

**请求参数说明：**

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `groupID` | string | ✅ | 目标群 ID |
| `userID` | string | ✅ | 用户 ID（自己的 ID） |

---

### 5. 获取群成员列表

#### 5.1 获取所有群成员用户 ID（推荐）

**请求：**
```
POST https://im-api.lajiaoliao.com/group/get_full_group_member_user_ids
```

**请求 Body：**
```json
{
  "idHash": 0,
  "groupID": "38066455"
}
```

**请求示例：**
```http
POST /group/get_full_group_member_user_ids HTTP/1.1
Host: im-api.lajiaoliao.com
token: eyJhbGciOiJIUzI1NiIs...
operationID: 17855100-1a11-453f-aa05-15cb72bb033c
content-type: application/json
...

{"idHash":0,"groupID":"38066455"}
```

**响应 Body：**
```json
{
  "errCode": 0,
  "errMsg": "",
  "errDlt": "",
  "data": {
    "version": 16927689102343263438,
    "versionID": "6a5ca07866d6e9bccfab3bfc",
    "equal": false,
    "userIDs": [
      "9076382",
      "2995512",
      "8920260",
      "7284675",
      "..."
    ]
  }
}
```

**请求参数说明：**

| 字段 | 类型 | 说明 |
|------|------|------|
| `idHash` | number | 哈希值，首次请求传 `0` |
| `groupID` | string | 群 ID |

**响应字段说明：**

| 字段 | 类型 | 说明 |
|------|------|------|
| `data.version` | number | 版本号，用于增量更新 |
| `data.versionID` | string | 版本 ID |
| `data.equal` | boolean | 是否与本地版本相同 |
| `data.userIDs` | array[string] | 所有成员的用户 ID 数组 |

#### 5.2 获取群成员详细信息（分页）

**请求：**
```
POST https://im-api.lajiaoliao.com/group/get_group_member_list
```

**请求 Body：**
```json
{
  "pagination": {"pageNumber": 1, "showNumber": 100},
  "groupID": "38066455",
  "filter": 0,
  "keyword": ""
}
```

**请求示例：**
```http
POST /group/get_group_member_list HTTP/1.1
Host: im-api.lajiaoliao.com
token: eyJhbGciOiJIUzI1NiIs...
operationID: 17855100-1a11-453f-aa05-15cb72bb033c
content-type: application/json
...

{"pagination":{"pageNumber":1,"showNumber":100},"groupID":"38066455","filter":0,"keyword":""}
```

**响应 Body（部分）：**
```json
{
  "errCode": 0,
  "errMsg": "",
  "errDlt": "",
  "data": {
    "total": 1219,
    "members": [
      {
        "groupID": "38066455",
        "userID": "9076382",
        "roleLevel": 100,
        "joinTime": 1784455288765,
        "nickname": "包包.",
        "faceURL": "https://lj-im-chat-01.lajiaoliao.com/image/9076382/...",
        "joinSource": 2,
        "operatorUserID": "9076382",
        "muteEndTime": 0,
        "inviterUserID": "9076382"
      }
    ]
  }
}
```

**请求参数说明：**

| 字段 | 类型 | 说明 |
|------|------|------|
| `pagination.pageNumber` | number | 页码，从 1 开始 |
| `pagination.showNumber` | number | 每页数量，最大 100 |
| `groupID` | string | 群 ID |
| `filter` | number | 过滤条件，`0` 表示全部 |
| `keyword` | string | 搜索关键词，可为空 |

**响应字段说明：**

| 字段 | 类型 | 说明 |
|------|------|------|
| `data.total` | number | 群成员总数 |
| `data.members` | array | 成员列表 |
| `userID` | string | 用户 ID |
| `nickname` | string | 群昵称 |
| `roleLevel` | number | 角色等级（100=群主，60=成员） |
| `joinTime` | number | 加入时间（时间戳） |
| `faceURL` | string | 头像 URL |
| `joinSource` | number | 入群来源 |
| `inviterUserID` | string | 邀请人用户 ID |

**roleLevel 枚举值：**

| 值 | 说明 |
|----|------|
| 100 | 群主 |
| 60 | 普通成员 |

#### 5.3 其他群成员 API

- `/group/get_group_members_info` - 获取群成员信息
- `/group/get_incremental_group_members_batch` - 增量获取群成员

---

### 6. 查询用户信息

#### 6.1 根据用户 ID 查询完整信息

**请求：**
```
POST https://api.lajiaoliao.com/user/find/full
```

**请求 Body：**
```json
{
  "userIDs": ["8018077"]
}
```

**请求示例：**
```http
POST /user/find/full HTTP/1.1
Host: api.lajiaoliao.com
token: <chat_token>
operationID: 7b250329-fa3a-46a1-8eb5-94e89aab8ea0
deviceID: device_windows_b2ffdd41-c26b-428a-b72c-e264d6d94ec6
version: 1.0.2
platform: 3
Content-Type: application/json

{"userIDs":["8018077"]}
```

**响应 Body：**
```json
{
  "errCode": 0,
  "errMsg": "",
  "data": {
    "users": [
      {
        "userID": "8018077",
        "nickname": "无限88🥚  久久.com",
        "faceURL": "https://lj-im-chat-01.lajiaoliao.com/image/8018077/...",
        "gender": 1,
        "registerType": 2,
        "createTime": 1786864387523,
        "numbers": [],
        "isFriend": false
      }
    ]
  }
}
```

**关键说明：**
- 本接口在 `api.lajiaoliao.com`（登录服务器），用 **chat_token** 鉴权，非 im_token
- `numbers` 数组为用户的**靓号列表**：有靓号的账号（如 9076382 → 靓号 888399）在此返回；无靓号则为空数组
- **辣椒聊双 ID 体系**：账号分「靓号」（公开可搜索，如 888399）与「内部 userID」（OpenIM 协议真实 ID，如 9076382）。无靓号的账号两者相同（如 8018077）

#### 6.2 搜索用户

**请求：**
```
POST https://api.lajiaoliao.com/user/search/full
```

**请求 Body：**
```json
{
  "keyword": "5807373",
  "normal": 0,
  "pagination": {"pageNumber": 1, "showNumber": 1}
}
```

**响应 Body：**
```json
{
  "errCode": 0,
  "errMsg": "",
  "data": {
    "total": 1,
    "users": [
      {
        "userID": "5807373",
        "nickname": "无限88🥚  久久网.cc",
        "faceURL": "https://lj-im-chat-01.lajiaoliao.com/image/5807373/...",
        "allowAddFriend": 1,
        "numbers": [],
        "isFriend": false
      }
    ]
  }
}
```

**请求参数说明：**

| 字段 | 类型 | 说明 |
|------|------|------|
| `keyword` | string | 搜索关键词（手机号/靓号/内部ID/昵称均可） |
| `normal` | number | 固定 `0` |
| `pagination.pageNumber` | number | 页码 |
| `pagination.showNumber` | number | 每页数量 |

**响应字段说明：**

| 字段 | 类型 | 说明 |
|------|------|------|
| `userID` | string | 用户内部 ID |
| `nickname` | string | 昵称 |
| `allowAddFriend` | number | 是否允许添加好友（1=允许） |
| `numbers` | array | 靓号列表 |
| `isFriend` | boolean | 是否已是好友 |

**用途：** 靓号/手机号 → 内部 userID 转换；添加好友前检查目标状态。

#### 6.3 其他用户 API

- `/user/get_users_info`（im-api.lajiaoliao.com）- IM 服务端查询用户信息，body 为 `{"userIDs": [...]}`，用 im_token 鉴权

---

### 7. 添加好友

**请求：**
```
POST https://im-api.lajiaoliao.com/friend/add_friend
```

**请求 Body：**
```json
{
  "fromUserID": "2640787",
  "toUserID": "5807373",
  "reqMsg": "你好，我是CLOZHI\n[src:1]",
  "ex": ""
}
```

**请求示例：**
```http
POST /friend/add_friend HTTP/1.1
Host: im-api.lajiaoliao.com
token: <im_token>
operationID: e776b2f8-36fa-438b-bee2-f60b747b93bf
content-type: application/json
...

{"fromUserID":"2640787","toUserID":"5807373","reqMsg":"你好，我是CLOZHI\n[src:1]","ex":""}
```

**响应 Body：**
```json
{
  "errCode": 0,
  "errMsg": "",
  "errDlt": ""
}
```

**请求参数说明：**

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `fromUserID` | string | ✅ | 发起方用户 ID（自己） |
| `toUserID` | string | ✅ | 目标用户 ID（内部 userID，靓号需先用 `/user/search/full` 转换） |
| `reqMsg` | string | ✅ | 验证消息（`\n[src:1]` 为客户端自动附加的来源标记） |
| `ex` | string | ❌ | 扩展字段，可为空 |

**注意事项：**
- 接口在 `im-api.lajiaoliao.com`，用 **im_token** 鉴权
- 目标若开启了好友验证，需要对方同意；若 `allowAddFriend=0` 则无法添加
- 批量添加前建议先用 `/user/search/full` 检查 `isFriend`（已是好友则跳过）

---

## 📝 错误码说明

| errCode | 说明 |
|---------|------|
| 0 | 成功 |
| 1001 | 参数错误 |
| 1004 | 接口不存在 |
| 110001 | Token 无效 |

---

## 🔗 服务器地址

| 用途 | 地址 |
|------|------|
| API 接口 | `https://im-api.lajiaoliao.com` |
| 登录接口 | `https://api.lajiaoliao.com` |
| WebSocket | `wss://im-ws.lajiaoliao.com` |

---

## 🛠️ Python 代码示例

```python
import requests
import json
import uuid

BASE_URL = "https://im-api.lajiaoliao.com"

def get_headers(token, device_id):
    """生成通用请求头"""
    return {
        "token": token,
        "operationID": str(uuid.uuid4()),
        "deviceID": device_id,
        "version": "1.0.2",
        "platform": "3",
        "Content-Type": "application/json"
    }

def get_group_info(group_ids, token, device_id):
    """获取群信息"""
    url = f"{BASE_URL}/group/get_groups_info"
    headers = get_headers(token, device_id)
    body = {"groupIDs": group_ids}
    response = requests.post(url, headers=headers, json=body)
    return response.json()

def get_joined_group_list(user_id, token, device_id, page=1, size=100):
    """获取已加入群列表"""
    url = f"{BASE_URL}/group/get_joined_group_list"
    headers = get_headers(token, device_id)
    body = {
        "fromUserID": user_id,
        "pagination": {
            "pageNumber": page,
            "showNumber": size
        }
    }
    response = requests.post(url, headers=headers, json=body)
    return response.json()

def join_group(group_id, user_id, token, device_id, req_message="", join_source=3):
    """申请加入群"""
    url = f"{BASE_URL}/group/join_group"
    headers = get_headers(token, device_id)
    body = {
        "groupID": group_id,
        "reqMessage": req_message,
        "joinSource": join_source,
        "inviterUserID": user_id,
        "ex": ""
    }
    response = requests.post(url, headers=headers, json=body)
    return response.json()

def quit_group(group_id, user_id, token, device_id):
    """退出群"""
    url = f"{BASE_URL}/group/quit_group"
    headers = get_headers(token, device_id)
    body = {
        "groupID": group_id,
        "userID": user_id
    }
    response = requests.post(url, headers=headers, json=body)
    return response.json()

def get_full_group_member_user_ids(group_id, token, device_id, id_hash=0):
    """获取所有群成员用户 ID"""
    url = f"{BASE_URL}/group/get_full_group_member_user_ids"
    headers = get_headers(token, device_id)
    body = {
        "idHash": id_hash,
        "groupID": group_id
    }
    response = requests.post(url, headers=headers, json=body)
    return response.json()

def get_group_member_list(group_id, token, device_id, page=1, size=100, filter=0, keyword=""):
    """获取群成员详细信息（分页）"""
    url = f"{BASE_URL}/group/get_group_member_list"
    headers = get_headers(token, device_id)
    body = {
        "pagination": {"pageNumber": page, "showNumber": size},
        "groupID": group_id,
        "filter": filter,
        "keyword": keyword
    }
    response = requests.post(url, headers=headers, json=body)
    return response.json()

# 使用示例
token = "your_token"
device_id = "your_device_id"
user_id = "2640787"

# 获取群信息
result = get_group_info(["388888"], token, device_id)
print("群信息:", json.dumps(result, ensure_ascii=False, indent=2))

# 获取已加入群列表
result = get_joined_group_list(user_id, token, device_id)
print("已加入群列表:", json.dumps(result, ensure_ascii=False, indent=2))

# 申请加入群
result = join_group("38066455", user_id, token, device_id, req_message="你好，申请入群")
print("进群结果:", json.dumps(result, ensure_ascii=False, indent=2))

# 退出群
result = quit_group("38066455", user_id, token, device_id)
print("退群结果:", json.dumps(result, ensure_ascii=False, indent=2))

# 获取群所有成员用户 ID
result = get_full_group_member_user_ids("38066455", token, device_id)
if result["errCode"] == 0:
    member_ids = result["data"]["userIDs"]
    print(f"群成员数量: {len(member_ids)}")
    print("成员 ID 列表:", member_ids[:10], "...")  # 只显示前10个

# 获取群成员详细信息（分页）
result = get_group_member_list("38066455", token, device_id, page=1, size=100)
if result["errCode"] == 0:
    members = result["data"]["members"]
    print(f"群成员总数: {result['data']['total']}")
    for member in members[:5]:  # 只显示前5个
        print(f"  - {member['nickname']} ({member['userID']})")
```

---

## 📅 文档更新记录

| 日期 | 更新内容 |
|------|----------|
| 2026-08-19 | 初始版本，记录已抓到的 `/group/get_groups_info` 接口 |
| 2026-08-19 | 新增 `/group/get_joined_group_list` 接口文档，更新 Python 代码示例 |
| 2026-08-19 | 新增 `/group/join_group` 进群接口文档，添加群成员相关 API 列表，更新 Python 代码示例 |
| 2026-08-19 | 新增 `/group/get_full_group_member_user_ids` 接口文档，完善群成员 API 文档，更新 Python 代码示例 |
| 2026-08-19 | 新增 `/group/get_group_member_list` 接口文档（群成员详细信息），更新 Python 代码示例 |
| 2026-08-19 | 新增 `/group/quit_group` 退群接口文档，更新 Python 代码示例，删除搜索群聊章节 |
| 2026-08-19 | 新增 `/user/find/full` 用户信息查询接口文档，说明辣椒聊双 ID 体系（靓号/内部 userID） |
| 2026-08-19 | 新增 `/user/search/full` 用户搜索接口文档（靓号/手机号→内部ID 转换） |
| 2026-08-19 | 新增 `/friend/add_friend` 添加好友接口文档 |
