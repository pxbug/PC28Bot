# LajiaoBot 源码重建工程架构（V2）

## 1. 目标

基于已逆向还原的 LajiaoBot 发布包（PyInstaller 打包）底层能力，重建一套**可维护、可测试、模块解耦**的 Python 源码工程。行为规格与现版保持一致，同时吸收旺旺机器人 V2 的架构理念：单一配置源、统一权限、独立监测模块、删除优先命令解析、安全门、自动化测试。

## 2. 与现版发布包的关系

- 现版发布包 `LajiaoBot/` 与 `LajiaoBot-patched/` **完全不动**，仅作为行为参考与回滚备份。
- 本工程 `LajiaoBot-src/` 是全新源码工程，按同一行为规格重建。
- 重建范围与现版能力完全对齐（见规格表），不新增业务特性。

## 3. 目录结构

```text
LajiaoBot-src/
├── config/
│   └── robot.config.json          # 单一配置源（默认值 + 可覆盖）
├── src/
│   ├── __init__.py
│   ├── main.py                    # 入口：GUI 启动 / --captcha 子进程分派
│   ├── config.py                  # 配置加载（默认值 + JSON 合并）
│   ├── logger.py                  # 结构化日志（文件 + 控制台）
│   ├── api_client.py              # HTTP API 封装（全部端点复刻）
│   ├── openim_proto.py            # OpenIM protobuf 动态解码
│   ├── message_listener.py        # WebSocket 监听（连接/心跳/重连/发送）
│   ├── message_normalizer.py      # WS 推送 → 消息字典归一化
│   ├── captcha_webview.py         # 阿里云验证码子进程（--captcha 分派）
│   ├── permissions.py             # ★ 统一权限（唯一权限来源）
│   ├── store/
│   │   ├── __init__.py
│   │   └── group_state.py         # 群状态存储（配置/名单/历史/统计）
│   ├── monitors/
│   │   ├── __init__.py
│   │   ├── spam_monitor.py        # 刷屏检测（独立）
│   │   ├── ad_monitor.py          # 广告检测（独立）
│   │   └── blacklist_monitor.py   # 黑名单处理（独立）
│   ├── engine/
│   │   ├── __init__.py
│   │   ├── rule_engine.py         # 关键词规则引擎
│   │   └── safety_gate.py         # 动作安全门（撤回/踢人/禁言总闸）
│   ├── command/
│   │   ├── __init__.py
│   │   ├── command_parser.py      # 指令解析（删除优先，防误判）
│   │   └── command_executor.py    # 指令执行（权限 + 落库 + 回复）
│   ├── runtime/
│   │   ├── __init__.py
│   │   └── robot_runtime.py       # ★ 核心运行时（消息处理优先级编排）
│   └── gui/
│       ├── __init__.py
│       └── gui_app.py             # CustomTkinter 桌面界面
├── tests/                         # 自动化测试（不连接真实服务）
├── docs/
│   ├── ARCHITECTURE.md            # 本文件
│   └── CHANGELOG-AND-ROLLBACK.md  # 变更记录与回滚指南
├── build.spec                     # PyInstaller 打包配置
└── requirements.txt
```

## 4. 模块职责

| 模块 | 职责 | 对应现版模块 |
|---|---|---|
| `main` | 入口，分派 GUI / 验证码子进程 | `main` |
| `config` | 单一配置源，默认值与 JSON 合并 | `api_client.Config`(仅 token) |
| `api_client` | 登录、群、消息、成员、名单、申请全部 API | `api_client` |
| `openim_proto` | 动态构造 OpenIM proto 并解码推送 | `openim_proto` |
| `message_listener` | WS 连接、30s 心跳、5s 断线重连、fire-and-forget 发送 | `message_listener` |
| `message_normalizer` | 把 `decode_push` 输出转成 `_process_msg_dict` 需要的消息字典 | `gui_app._on_ws_msg` |
| `permissions` | `_is_protected` + 管理员/群主判定统一入口 | `gui_app._is_protected` |
| `store.group_state` | `_get_group_config` 数据 + `_keyword_rules` + `_daily_stats` | `gui_app` 实例字段 |
| `monitors.spam` | `_check_spam` / `_do_spam_action` | `gui_app` 同名方法 |
| `monitors.ad` | `_check_ad` / `_do_ad_action` | `gui_app` 同名方法 |
| `monitors.blacklist` | 黑名单成员发言 → 踢出 | `_process_msg_dict` 黑名单分支 |
| `engine.rule_engine` | `_matches_keywords` + `_auto_act`（保护对象跳过） | `gui_app` 同名方法 |
| `engine.safety_gate` | 动作执行前的总开关（撤回/踢/禁言） | 新增（默认全部放行） |
| `command.parser/executor` | `_process_admin_cmd` 全部指令 | `gui_app._process_admin_cmd` |
| `runtime.robot_runtime` | `_process_msg_dict` 优先级编排 | `gui_app._process_msg_dict` |
| `gui.gui_app` | 登录页 + 群管理页 + 监控中心 | `gui_app.GuiApp` |

## 5. 行为规格（与原版逐项对齐）

### 5.1 消息处理优先级（`runtime.robot_runtime.process_msg`）

```text
1. 提取 gid / send_id
   - gid = msg['groupID']；若空且 conversationID 以 'sg_' 开头 → gid = conv[3:]
   - 无 gid 或无 send_id → 记日志 [WS未知格式]，return
2. 消息去重：mid = serverMsgID 或 clientMsgID，已在 seen → return；否则加入
3. 自身消息过滤：send_id == user_id → return
4. contentType 过滤：仅处理 101 / 106（ct not in (101,106) → return）
5. 群配置有效期检查：expiry.enabled 且 start_ts>0 且 now > start_ts + days*86400 → return
6. 展示日志：_[gname] nickname(send_id): content
7. 黑名单检查：send_id in blacklisted
   - 是保护对象 → 跳过
   - 否则 → kick 并 return（终止型）
8. 发言计数 +1（_increment_msg_count）
9. 刷屏检测 _check_spam（不检查返回值，不阻断后续）
10. 广告检测 _check_ad（命中返回 True → 终止，阻断关键词/指令）
11. 关键词匹配 _matches_keywords → 命中启动 _auto_act 后台线程（不阻断）
12. 管理员身份判断 → 命中启动 _safe_process_admin_cmd 后台线程（主流程结束）
```

### 5.2 刷屏检测（`monitors/spam_monitor.py`）——对齐旺旺机器人 V2

```text
参数：gid, send_id, msg_id, content, now
1. 豁免检查（权限模块统一）→ return False
2. 未启用 → return False（默认 enabledByDefault=True，群级可关）
3. 内容归一化：trim + 空白折叠，空则 "__empty__"
4. 分桶：bucket_key = 群:发送人:内容；仅保留 windowMs(默认10000ms) 内记录
5. 冷却窗口：触发后 windowMs 内同一桶再发 → 持续触发（继续撤回）
6. 桶内同内容数 >= threshold(默认3) → 触发
7. 触发 → 撤回桶内全部消息（maxMessages 上限）→ 设置冷却窗口 → 记违规
8. 违规升级（当日累计，按群按人）：每 muteEvery(3) 次 → 追加禁言60分钟；
   禁言 kickAfterMutes(3) 次后 → 追加踢出并清零当日计数
动作顺序：recall_message（全部目标）→ mute_member → kick_member，逐条容错
日志：[刷屏] {send_id}: {reason} (撤回{N}条)
```

### 5.3 广告检测（`monitors/ad_monitor.py`）——对齐旺旺机器人 V2

```text
参数：gid, send_id, content, msg_id, msg
1. 豁免检查（权限模块统一）→ return False
2. 未启用 → return False（默认 enabledByDefault=False，群级可开）
3. @提及片段先剔除（@昵称 常带数字，不计入）
4. 判定维度（任一命中即触发）：
   - 数字数 > digitThreshold(默认5)       → digits_exceed
   - 字母数 > letterThreshold(默认5)      → letters_exceed
   - URL 检测 urlDetection(默认开)         → url_detected
   - 内置 AD_PATTERNS(23条) + 自定义 patterns 正则命中 → pattern:xxx
   - 单条普通消息长度 > longMessageChars(默认50) → long_message
5. 转发消息：对每条原文分别判定（避免多段合并虚高）
6. 命中 → 记违规 → 触发动作，return True（终止型）
7. 违规升级：同刷屏（muteEvery/kickAfterMutes/muteMinutes）
动作顺序：recall_message → mute_member → kick_member，逐条容错
日志：[广告监测] {send_id}: {reason} ({actions})
```

内置 AD_PATTERNS（23条）：URL/域名/QQ/微信/V/手机/座机/加我/联系我/私聊我/收售/代理/扫码/二维码/公众号/点赞转发等。
违规计数（`store/group_state.py`）：`record_ad_violation` / `record_spam_violation`，当日累计、跨天自动清零，`reset_*_violations` 在踢出后清零。

### 5.4 关键词（`engine/rule_engine.py`）

```text
_matches_keywords(gid, text)：锁内复制规则列表，子串包含匹配，返回第一个 rule
rule = {'keyword', 'reply', 'action'}，action ∈ {仅回复, 踢出, 禁言1h, 撤回}

_auto_act(msg, gname, rule)（补丁版语义，保护检查在回复之前）：
1. gid/send_id 从 msg 提取；protected = _is_protected(gid, send_id)
2. reply = rule['reply'].replace('{nick}', send_id)
3. if protected → 日志 [关键词跳过] 保护对象 {send_id} 命中关键词规则，已跳过自动动作 → return
4. if reply → _send_msg(gid, reply)，日志 [自动回复] {gname}: {reply}
5. action 分派：踢出 → kick / 禁言1h → mute(gid,uid,86400) / 撤回 → recall
6. 异常 → 日志 [自动操作失败] {gname}: {e}
```

### 5.5 管理员指令（`command/command_parser.py` + `command_executor.py`）

完整指令表（触发文本 / 动作 / 回复）：

| 触发文本 | 动作 | 回复 |
|---|---|---|
| `菜单` `帮助` `管理` | 发送 MENU_TEXT | 菜单文案 |
| `查看关键词` | 列出规则 | `1. kw → action(reply)` / 暂无关键词规则 |
| `添加关键词 xxx 回复：内容` | 追加规则 | `已添加关键词: [kw] → action` |
| `删除关键词 序号` | pop 规则 | `已删除关键词: [kw]` / 序号无效 |
| `查看白名单` `白名单` | 列出 | 📋 白名单列表 / 白名单为空 |
| `加白 @用户` `添加白名单` | 加入 | `✅ 已添加白名单: name` |
| `移白` `移除白名单` `移出白名单` | 移除 | `✅ 已移出白名单: name` |
| `踢出 @用户` | kick | `已踢出: name` |
| `拉黑 @用户` `加入黑名单` | 移白+拉黑+kick+add_blacklist | `⛔ 已拉黑: name` |
| `查看黑名单` `黑名单` | get_blacklist 列出 | ⛔ 黑名单列表 / 黑名单为空 |
| `解黑` `移除黑名单` `移出黑名单` | remove_blacklist | `已移出黑名单: name` |
| `禁言 @用户` | mute(86400) | `已禁言 name 24小时` |
| `解禁` `取消禁言` | mute(1) | `已解除禁言 name` |
| `今日发言` `发言排行` `今日排行` `统计` | 排行前20 | 今日发言排行 / 今日暂无发言记录 |
| `(开启\|打开\|启用\|关闭\|停用\|禁用)\s*(刷屏\|广告)(监测)?` | 开关 | `刷屏监测: 已启用/已禁用` 等 |

权限：仅 `cfg['admins']` 成员或群主可执行；群主自动提升进 admins。

### 5.6 群配置结构（`store/group_state.py`）

```python
DEFAULT_GROUP_CONFIG = {
    'admins': set(),
    'whitelist': set(),
    'spam': {'enabled': True, 'windowMs': 10000, 'threshold': 3, 'maxMessages': 3,
             'actions': ['recall_message'], 'muteEvery': 3, 'kickAfterMutes': 3, 'muteMinutes': 60},
    'ad': {'enabled': False, 'digitThreshold': 5, 'letterThreshold': 5, 'urlDetection': True,
           'patterns': [], 'actions': ['recall_message'], 'violationWindowMs': 0,
           'longMessageChars': 50, 'muteEvery': 3, 'kickAfterMutes': 3, 'muteMinutes': 60},
    'msg_history': {},          # send_id → [(ts, msg_id), ...]
    'expiry': {'enabled': False, 'start_ts': 0, 'days': 30},
}
# 运行时惰性字段：'blacklisted'（set）；关键词规则在 KeywordStore
# 违规计数：store 内部 ad_violations / spam_violations（当日，按群按人）
```

### 5.7 保护对象（`permissions.py`）

`is_protected(gid, send_id)` = `send_id in admins` 或 `send_id in whitelist` 或 `send_id == group_owners[gid]`。
白名单仅免疫处罚，不授予管理权限。

## 6. 依赖

- `requests`：HTTP API
- `websockets`：WebSocket 监听（现版 16.1.1）
- `customtkinter` + `tkinter`：桌面 GUI
- `protobuf`（google）：OpenIM proto 动态解码
- 测试：仅用标准库 `unittest`，不连接真实服务

## 7. 打包

`build.spec` 基于 PyInstaller onedir 模式，输出与现版结构一致（`LajiaoBot.exe` + `_internal/`）。`--captcha` 参数由主进程重新唤起自身进入验证码子进程。

## 8. 安全边界

- 动作类调用（撤回/踢/禁言/拉黑）在 `engine/safety_gate.py` 统一放行；默认全部允许以保持行为一致。
- 保护对象永远豁免自动处罚（`permissions.is_protected`）。
- 所有测试为本地纯逻辑测试，不发起真实网络请求、不执行真实动作。
