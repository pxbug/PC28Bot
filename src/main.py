"""程序入口：--captcha 子进程分派 / pywebview 桌面客户端启动。"""
import os
import sys


def _webui_dir():
    here = os.path.dirname(os.path.abspath(__file__))
    # PyInstaller 打包后资源在 _internal/webui；源码运行时在 src/webui
    candidates = [
        os.path.join(here, "_internal", "webui"),
        os.path.join(here, "webui"),
    ]
    for path in candidates:
        if os.path.isdir(path):
            return path
    return candidates[-1]


def _run_gui():
    from v2.gui import main as v2_main
    v2_main()


def main():
    if len(sys.argv) >= 2 and sys.argv[1] == "--captcha":
        import captcha_webview
        captcha_webview.main()
        return
    _run_gui()


if __name__ == "__main__":
    main()
