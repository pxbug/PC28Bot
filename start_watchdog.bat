@echo off
chcp 65001 >nul
cd /d "%~dp0"
echo 正在启动辣椒群管机器人（源码版）...
echo 首次运行会弹出登录窗口，请用你的机器人账号登录。
echo 本窗口请保持开启，关闭窗口即停止机器人。
echo.
start "LajiaoBot-GUI" /min python "%~dp0src\main.py"
timeout /t 3 >nul
echo.
echo 机器人已启动，看门狗逻辑由运行环境保障。
echo 日志位置：logs\runtime\gui.log
echo.
pause
