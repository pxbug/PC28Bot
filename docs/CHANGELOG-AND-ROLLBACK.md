# LajiaoBot 源码重建变更记录与回滚指南

## 1. 背景

原 LajiaoBot 是 PyInstaller 打包的 Windows 桌面程序（`LajiaoBot/LajiaoBot.exe`），无源码。
本次基于发布包静态反汇编还原的底层能力，重建为可维护、可测试的 Python 源码工程 `LajiaoBot-src/`。

## 2. 与现版的关系

| 项 | 现版发布包 | 源码重建工程 |
| --- | --- | --- |
| 位置 | `LajiaoBot/`、`LajiaoBot-patched/`（未动） | `LajiaoBot-src/` |
| 形态 | PyInstaller onedir（无源码） | Python 源码 + 测试 + 文档 |
| 权限 | `_is_protected` 方法 | 统一 `permissions.PermissionService` |
| 监测 | `_check_spam/_check_ad` 方法 | 独立 `monitors/` 模块 |
| 指令 | `_process_admin_cmd` 方法 | `command/` parser + executor |
| 配置 | 实例字段 `_group_config` | `store/group_state.py` |
| 消息处理 | `_process_msg_dict` 方法 | `runtime/robot_runtime.py` |
| 动作闸门 | 无 | `engine/safety_gate.py`（默认全放行） |

## 3. 行为对齐点

- 消息处理优先级逐项复刻：`contentType in (101,106)` 过滤、`sg_` 会话兜底、去重、自身过滤、到期门禁、黑名单终止、刷屏非终止、广告终止、关键词、管理员指令。
- 刷屏窗口/阈值/动作、广告关键词/正则/自定义词、关键词 reply/action 全部与现版一致。
- 保护对象豁免与管理员指令权限判定与现版一致。
- **风险补丁已内置**：`_auto_act` 保护检查在回复之前（`[关键词跳过] 保护对象 ...` 日志），即补丁版语义。
- 全部管理员指令文本、动作、回复文案与现版一致。

## 4. 验证

```bash
python tests/check_syntax.py   # 语法检查：39 文件通过
python tests/run_tests.py      # 自动化测试：70 用例全绿
```

## 5. 如何打包上线

```bash
pip install -r requirements.txt
pyinstaller build.spec
```

产出 `dist/LajiaoBot/LajiaoBot.exe`，结构与现版发布包一致。

## 6. 如何回滚 / 使用现版

- 现版发布包 `LajiaoBot/` 与 `LajiaoBot-patched/` 从头到尾未修改，可随时直接使用。
- `LajiaoBot-patched/LajiaoBot.exe.before-risk-patch.bak` 为补丁前副本（若需还原旧行为：用该 .bak 覆盖 LajiaoBot.exe）。
- 源码工程只是并行重建，不影响现版。

## 7. 安全边界

- 所有动作（撤回/踢/禁言/拉黑）统一走 `safety_gate.allow()`；默认全部允许以保持行为一致，可改 `config/robot.config.json` 的 `safety` 段快速熔断。
- 保护对象永远豁免自动处罚。
- 测试全部为本地纯逻辑测试，不连接真实服务、不执行真实动作。

## 8. PC28 P1（2026-08-26）

新增功能：开奖抓取 + 超管指令驱动的群推送。

### 新增模块

- `src/pc28/` — `api.py`（yu28.top 客户端）、`format.py`（卡片文本）、`storage.py`（MySQL DAO + NullStore 降级）、`fetcher.py`（倒计时驱动主循环）、`worker.py`（线程启动器）
- `src/v2/commands/`（包化重写，原 `src/v2/commands.py` 已删除）

### 配置

`config/robot.config.json` 新增 `pc28` 段：

```json
"pc28": {
  "enabled": true,
  "api_base": "https://yu28.top",
  "api_key": "yu28_c4aaa4ccc91a5bf8",
  "history_size": 20,
  "countdown_extra_sec": 1,
  "post_result_delay_sec": 3,
  "mysql": {
    "host": "127.0.0.1",
    "port": 3306,
    "user": "pc28bot",
    "password": "pc28bot",
    "db": "pc28",
    "charset": "utf8mb4"
  }
}
```

### 协议

- 超管发 `GM` → 机器人回复【超级菜单】1.启动 2.关闭
- 超管回 `1` / `启动` → 开启本群开奖推送
- 超管回 `2` / `关闭` → 关闭本群开奖推送
- 非超管 → 不响应

### 部署前准备

```sql
-- 在 MySQL 创建 pc28bot 用户并授权
CREATE USER IF NOT EXISTS 'pc28bot'@'127.0.0.1' IDENTIFIED BY 'pc28bot';
GRANT ALL PRIVILEGES ON pc28.* TO 'pc28bot'@'127.0.0.1';
FLUSH PRIVILEGES;
```

机器人启动时会自动 `CREATE TABLE IF NOT EXISTS` 建表（`pc28_lottery` / `pc28_push_state`）。

### 注意事项

- exe 内嵌 `config/robot.config.json`（PyInstaller `--add-data`），**改配置必须重新打包**才能让 exe 读到新值
- MySQL 不可用时，存储降级为 NullStore，**不会阻塞推送**（仅无落库）
- 推送群列表来自 `GroupStateStore.pc28_push_enabled`，群开关重启后自动恢复
- 倒计时解析失败（API 在结算前后短时返回空）时自动 2 秒重试，**无需人工干预**
