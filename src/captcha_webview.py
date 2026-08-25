"""验证码子进程：本地 HTTP 服务 + 浏览器阿里云验证码回调。

由主程序以 --captcha <port> <sceneId> <region> <prefix> 唤起。
"""
import sys
import time
import webbrowser
import threading
import os
from http.server import HTTPServer, BaseHTTPRequestHandler
from urllib.parse import urlparse, parse_qs

CAPTCHA_HTML = """<!DOCTYPE html>
<html><head><meta charset="utf-8"><title>安全验证</title>
<script src="https://o.alicdn.com/captcha-frontend/aliyunCaptcha/AliyunCaptcha.js"></script>
<style>body{font-family:sans-serif;margin:0;padding:0;background:#fff}
#container{display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:100vh;padding:24px;box-sizing:border-box}
h3{color:#333;margin-bottom:8px;font-size:16px}
#status{color:#999;margin-top:16px;font-size:13px}
</style></head><body>
<div id="container">
<h3>安全验证</h3>
<p style="font-size:13px;color:#666;margin-bottom:16px">请完成下方滑块验证</p>
<div id="aliyun-captcha-element" style="margin:0 auto;width:360px"></div>
<button id="aliyun-captcha-button" style="display:none">验证</button>
<div id="status">加载中...</div>
</div>
<script>
window.AliyunCaptchaConfig = {region:"__REGION__",prefix:"__PREFIX__"};
window.initAliyunCaptcha({
SceneId:"__SCENEID__",mode:"embed",element:"#aliyun-captcha-element",
button:"#aliyun-captcha-button",slideStyle:{width:340,height:42},
language:"cn",
success:function(e){var v=String(e||"");
document.getElementById("status").textContent="验证成功，正在登录...";
try { fetch("http://127.0.0.1:__PORT__/callback?validate=" + encodeURIComponent(v), {mode:"no-cors"}); } catch(err) {}
setTimeout(function(){ try { window.location.href = "http://127.0.0.1:__PORT__/callback?validate=" + encodeURIComponent(v); } catch(err2) {} }, 1500);
},fail:function(e){document.getElementById("status").textContent="验证失败，请重试"},
onClose:function(){},
getInstance:function(e){setTimeout(function(){typeof e.show==="function"?e.show():document.getElementById("aliyun-captcha-button").click()},200)}
});
</script>
</body></html>"""


def _open_browser(url):
    """打开系统浏览器打开验证码页。用 os.startfile（Windows 原生，立即返回不阻塞）。"""
    try:
        if os.name == "nt":
            os.startfile(url)
            return
    except Exception:
        pass
    try:
        import subprocess
        subprocess.Popen(["cmd", "/c", "start", "", url], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    except Exception:
        try:
            webbrowser.open(url)
        except Exception:
            pass


def main():
    args = sys.argv
    if len(args) < 3 or "--captcha" not in args:
        print("ERROR: missing arguments", file=sys.stderr)
        sys.exit(1)
    try:
        port = int(args[2])
    except (IndexError, ValueError):
        print("ERROR: missing arguments", file=sys.stderr)
        sys.exit(1)
    scene_id = args[3] if len(args) > 3 else ""
    region = args[4] if len(args) > 4 else ""
    prefix = args[5] if len(args) > 5 else ""
    result_file = args[6] if len(args) > 6 else ""

    class Handler(BaseHTTPRequestHandler):
        def do_GET(self):
            parsed = urlparse(self.path)
            qs = parse_qs(parsed.query)
            if parsed.path == "/callback":
                validate = (qs.get("validate") or [""])[0]
                if result_file:
                    try:
                        with open(result_file, "w", encoding="utf-8") as f:
                            f.write(validate)
                    except Exception:
                        pass
                self.send_response(200)
                self.send_header("Content-Type", "text/html; charset=utf-8")
                self.send_header("Access-Control-Allow-Origin", "*")
                self.end_headers()
                self.wfile.write(("<html><body><h3>验证成功，可关闭本窗口</h3></body></html>").encode("utf-8"))
            elif parsed.path == "/":
                html = (
                    CAPTCHA_HTML
                    .replace("__REGION__", region)
                    .replace("__PREFIX__", prefix)
                    .replace("__SCENEID__", scene_id)
                    .replace("__PORT__", str(port))
                )
                self.send_response(200)
                self.send_header("Content-Type", "text/html; charset=utf-8")
                self.end_headers()
                self.wfile.write(html.encode("utf-8"))
            else:
                self.send_response(404)
                self.end_headers()

        def log_message(self, *args):
            pass

    server = HTTPServer(("127.0.0.1", port), Handler)
    threading.Thread(target=server.serve_forever, daemon=True).start()
    # 等待服务就绪后再打开浏览器（避免浏览器先于服务器连接被拒）
    try:
        import urllib.request
        for _ in range(20):
            try:
                urllib.request.urlopen("http://127.0.0.1:%d/" % port, timeout=1)
                break
            except Exception:
                time.sleep(0.2)
    except Exception:
        pass
    _open_browser("http://127.0.0.1:%d/" % port)
    deadline = time.time() + 180
    while time.time() < deadline:
        if result_file and os.path.exists(result_file):
            break
        time.sleep(0.3)
    # 验证成功后浏览器会再次跳转 /callback 展示成功页；留 3 秒让跳转完成再退出
    time.sleep(3)
    try:
        server.shutdown()
    except Exception:
        pass
    sys.exit(0)


if __name__ == "__main__":
    main()
