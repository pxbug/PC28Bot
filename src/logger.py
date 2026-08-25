"""
日志工具 - 同时输出到控制台和文件
"""
import sys
import os
from datetime import datetime

LOG_FILE = "bot_debug.log"

def log(msg):
    """同时输出到控制台和日志文件"""
    timestamp = datetime.now().strftime("%H:%M:%S")
    line = f"[{timestamp}] {msg}"
    
    # 输出到控制台
    print(msg, flush=True)
    
    # 写入日志文件
    try:
        with open(LOG_FILE, "a", encoding="utf-8") as f:
            f.write(line + "\n")
    except:
        pass
