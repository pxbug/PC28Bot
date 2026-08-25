# -*- mode: python ; coding: utf-8 -*-
# Watchdog onefile 打包：输出 Watchdog.exe（独立看门狗，仅标准库）
# 使用：pyinstaller watchdog.spec

from pathlib import Path

root = Path(SPECPATH)

a = Analysis(
    [str(root / "watchdog.py")],
    pathex=[str(root)],
    binaries=[],
    datas=[],
    hiddenimports=[],
    hookspath=[],
    runtime_hooks=[],
    excludes=["tkinter", "webview", "requests", "websockets", "google.protobuf"],
    noarchive=False,
)

pyz = PYZ(a.pure)

exe = EXE(
    pyz,
    a.scripts,
    a.binaries,
    a.datas,
    [],
    name="Watchdog",
    debug=False,
    bootloader_ignore_signals=False,
    strip=False,
    upx=False,
    console=False,
    disable_windowed_traceback=False,
    target_arch=None,
    codesign_identity=None,
    entitlements_file=None,
)
