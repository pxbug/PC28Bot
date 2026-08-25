"""看门狗：监控 LajiaoBot.exe 进程与心跳，崩溃/卡死/断线时自动重启。

用法：python watchdog.py  （或双击 start_watchdog.bat）
- 每 30 秒检查一次：
  * 进程是否存活（死了 → 重启）
  * 心跳文件是否新鲜（>5 分钟没更新 → 认为卡死 → 重启）
  * WS 连接是否正常（heartbeat 内包含连接状态）
- 重启前先结束残留的 LajiaoBot.exe，避免多开。
- 所有事件写入 logs/runtime/watchdog.log。
"""
import os
import sys
import time
import json
import subprocess

if getattr(sys, "frozen", False):
    # PyInstaller 打包后：以 exe 实际所在目录为准（onefile 的 __file__ 指向临时解压目录）
    HERE = os.path.dirname(os.path.abspath(sys.executable))
else:
    HERE = os.path.dirname(os.path.abspath(__file__))
# 兼容：watchdog 在 src/ 下（源码运行）或与 exe 同目录（dist/LajiaoBot/）
if os.path.basename(HERE) == "src":
    EXE = os.path.join(os.path.dirname(HERE), "dist", "LajiaoBot", "LajiaoBot.exe")
else:
    EXE = os.path.join(HERE, "LajiaoBot.exe")
WORKDIR = os.path.dirname(EXE)          # exe 运行目录（日志/心跳写这里）
LOG_DIR = os.path.join(WORKDIR, "logs", "runtime")
HEARTBEAT = os.path.join(LOG_DIR, "heartbeat")
WATCH_LOG = os.path.join(LOG_DIR, "watchdog.log")

CHECK_INTERVAL = 30          # 秒
STALE_MS = 5 * 60 * 1000     # 心跳超过 5 分钟视为卡死

if not os.path.exists(EXE):
    print("未找到 LajiaoBot.exe：%s" % EXE)
    sys.exit(1)


def log(msg):
    try:
        os.makedirs(LOG_DIR, exist_ok=True)
        with open(WATCH_LOG, "a", encoding="utf-8") as f:
            f.write("[%s] %s\n" % (time.strftime("%Y-%m-%d %H:%M:%S"), msg))
    except Exception:
        pass


def read_heartbeat():
    """返回 (last_ms, ws_ok) 或 (None, None)。"""
    try:
        if not os.path.exists(HEARTBEAT):
            return None, None
        raw = open(HEARTBEAT, encoding="utf-8").read().strip()
        parts = raw.split(",")
        return int(parts[0]), (len(parts) > 1 and parts[1] == "1")
    except Exception:
        return None, None


def kill_existing():
    try:
        subprocess.run(["taskkill", "/f", "/im", "LajiaoBot.exe"],
                       capture_output=True, timeout=10)
    except Exception:
        pass


def start():
    kill_existing()
    time.sleep(2)
    DETACHED = 0x00000008
    CREATE_NEW_PROCESS_GROUP = 0x00000200
    try:
        proc = subprocess.Popen([EXE], cwd=WORKDIR,
                                creationflags=DETACHED | CREATE_NEW_PROCESS_GROUP)
    except Exception as e:
        log("启动失败: %s" % e)
        return None
    log("已启动 LajiaoBot（pid=%s，cwd=%s）" % (proc.pid, WORKDIR))
    return proc


def main():
    log("===== 看门狗启动 =====")
    proc = start()
    if proc is None:
        return
    ws_down_count = 0
    startup_grace = 4          # 启动后前 4 次检测（约2分钟）忽略 WS 断开
    while True:
        time.sleep(CHECK_INTERVAL)
        alive = proc.poll() is None
        last_ms, ws_ok = read_heartbeat()
        age_ms = (int(time.time() * 1000) - last_ms) if last_ms is not None else None
        stale = age_ms is not None and age_ms > STALE_MS
        if not alive:
            log("检测到进程已退出（code=%s），重启中..." % proc.returncode)
            proc = start()
            ws_down_count = 0
            startup_grace = 4
        elif stale:
            log("检测到心跳超时（%.0f 分钟无更新），视为卡死，重启中..." % (age_ms / 60000))
            proc = start()
            ws_down_count = 0
            startup_grace = 4
        elif last_ms is None:
            log("未检测到心跳文件，等待机器人写入...")
        else:
            # WS 断开：WS 本身会自动重连，仅在持续断开（约5分钟）且非启动期时重启
            if ws_ok is False:
                if startup_grace > 0:
                    startup_grace -= 1
                    ws_down_count = 0
                else:
                    ws_down_count += 1
                    log("WS 连接断开（第 %d 次检测）" % ws_down_count)
                    if ws_down_count >= 10:
                        log("WS 持续断开（约5分钟），重启机器人...")
                        proc = start()
                        ws_down_count = 0
                        startup_grace = 4
            else:
                ws_down_count = 0


if __name__ == "__main__":
    main()
