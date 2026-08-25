# LajiaoBot 源码重建工程（V2）

基于逆向还原的 LajiaoBot 发布包底层能力，重建的可维护源码工程。
行为规格与现版完全对齐，采用旺旺机器人 V2 的架构理念：单一配置源、统一权限、独立监测模块、删除优先命令解析、安全门、自动化测试。

## 目录

```text
config/robot.config.json   # 单一配置源
src/                       # 源码（main 入口 + 模块化业务）
src/web_bridge.py          # pywebview js_api bridge（前端 ↔ Python）
src/webui/                 # 前端界面（index.html / style.css / app.js）
tests/                     # 自动化测试（136 个用例，本地纯逻辑）
docs/                      # ARCHITECTURE / CHANGELOG-AND-ROLLBACK
build.spec                 # PyInstaller 打包配置
requirements.txt           # 依赖
```

## 快速验证

```bash
python tests/check_syntax.py   # 语法检查
python tests/run_tests.py      # 自动化测试（136 个用例）
```

## 运行

```bash
pip install -r requirements.txt
python src/main.py             # 启动 pywebview 客户端（WebView2 渲染 Web UI）
```

`--captcha` 参数用于验证码子进程（由登录流程自动唤起，无需手动执行）。

## 打包

```bash
pip install -r requirements.txt
pyinstaller build.spec
# 输出：dist/LajiaoBot/LajiaoBot.exe + dist/LajiaoBot/_internal/（含 webui 资源）
```

## 界面

飞书/微信风格 Web UI（pywebview + WebView2 渲染）：
- 左侧图标导航栏（登录 / 群管理 / 监控中心）
- 群管理：会话列表 + 成员 / 关键词 / 管理员 / 白名单 / 黑名单 / 自动监测
- 监控中心：聊天流式消息展示，按会话过滤，实时更新

## 行为规格摘要

- 消息处理优先级：前置过滤 > 到期门禁 > 黑名单(终止) > 计数 > 刷屏(非终止) > 广告(终止) > 关键词 > 管理员指令。
- 刷屏默认：10 秒窗口内同内容 ≥3 条 → 撤回，禁言/踢出按违规次数自动升级。
- 广告检测：数字/字母阈值、URL、内置正则（23条）+ 自定义正则、长消息检测。
- 保护对象（管理员/群主/白名单）豁免自动处罚，命中关键词自动动作直接跳过（[关键词跳过] 日志）。
- 管理员指令：菜单/查看关键词/添加删除关键词/白名单/黑名单/踢出/禁言/解禁/今日发言/刷屏广告开关。

详见 `docs/ARCHITECTURE.md`。
