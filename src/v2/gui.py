"""V2 看板 GUI（tkinter 零依赖版）：登录 + 看板。

无需 WebView2/.NET/pythonnet，任何 Windows 均可运行。
验证码通过系统浏览器完成（本地 HTTP 回调）。
"""
import os
import sys
import time
import socket
import threading
import subprocess
import tempfile

import tkinter as tk
from tkinter import ttk

from .dashboard import DashboardBridge


def _write_log(logf, msg):
    try:
        os.makedirs(os.path.dirname(logf), exist_ok=True)
        with open(logf, "a", encoding="utf-8") as f:
            f.write("[%s] %s\n" % (time.strftime("%H:%M:%S"), msg))
    except Exception:
        pass


def _find_ini():
    candidates = []
    base = getattr(sys, "_MEIPASS", None)
    if base:
        candidates.append(os.path.join(base, "lajiao_bot.ini"))
    if getattr(sys, "executable", None):
        candidates.append(os.path.join(os.path.dirname(sys.executable), "lajiao_bot.ini"))
    candidates.append(os.path.join(os.getcwd(), "lajiao_bot.ini"))
    for p in candidates:
        if os.path.exists(p):
            return p
    return "lajiao_bot.ini"


def _free_port():
    s = socket.socket()
    s.bind(("127.0.0.1", 0))
    port = s.getsockname()[1]
    s.close()
    return port


class BotGUI:
    def __init__(self, root, ini_path):
        self.root = root
        self.root.title("辣椒群管机器人")
        self.root.geometry("760x600")
        self.root.configure(bg="#f0f4ff")
        self.logf = os.path.join(os.getcwd(), "logs", "runtime", "gui.log")
        self.bridge = DashboardBridge(logger=lambda m: _write_log(self.logf, m))
        self.ini_path = ini_path
        self._build_login()
        self._build_dashboard()
        self._build_config()
        _write_log(self.logf, "[start] bridge init")
        try:
            self.bridge.start(ini_path)
        except Exception as e:
            _write_log(self.logf, "[start] error: %s" % e)
        self._start_heartbeat()
        if self.bridge.runner.client and self.bridge.runner.client.user_id and self.bridge.runner.client.im_token:
            _write_log(self.logf, "[start] started=True user=%s" % self.bridge.runner.client.user_id)
            self._show("dash")
            self._refresh_dash()
        else:
            _write_log(self.logf, "[start] started=False user=")
            self._show("login")

    def _start_heartbeat(self):
        """进程存活心跳：未登录时也写心跳文件，让看门狗知道进程活着。

        路径固定为 exe/工作目录下的 logs/runtime/heartbeat（与看门狗读取一致，
        不随 cwd 变化），格式 ms,ws状态。
        """
        def _hb_path():
            try:
                import sys as _sys
                base = os.path.dirname(os.path.abspath(_sys.executable))
                if getattr(_sys, "_MEIPASS", None):
                    base = os.path.dirname(os.path.abspath(_sys.executable))
                return os.path.join(base, "logs", "runtime", "heartbeat")
            except Exception:
                return os.path.join(os.getcwd(), "logs", "runtime", "heartbeat")

        def run():
            while True:
                try:
                    p = _hb_path()
                    os.makedirs(os.path.dirname(p), exist_ok=True)
                    # ws 状态写 1：进程存活即视为正常，避免看门狗因未登录(无WS)误判掉线而重启
                    with open(p, "w", encoding="utf-8") as f:
                        f.write("%d,1" % int(time.time() * 1000))
                except Exception:
                    pass
                time.sleep(15)

        try:
            t = threading.Thread(target=run, daemon=True)
            t.start()
        except Exception:
            pass

    # ---------- 视图切换 ----------
    def _show(self, name):
        for f in (self.login_frame, self.dash_frame, self.config_frame):
            f.pack_forget()
        target = getattr(self, name + "_frame", None)
        if target:
            target.pack(fill="both", expand=True)

    # ---------- 登录 ----------
    def _build_login(self):
        self.login_frame = tk.Frame(self.root, bg="#f0f4ff")
        card = tk.Frame(self.login_frame, bg="#ffffff")
        card.pack(pady=36, padx=48, fill="both", expand=True)
        tk.Label(card, text="辣椒群管机器人", font=("Microsoft YaHei", 20, "bold"), bg="#ffffff").pack(pady=(36, 4))
        tk.Label(card, text="登录机器人账号", font=("Microsoft YaHei", 11), fg="#666", bg="#ffffff").pack()
        tk.Label(card, text="手机号", font=("Microsoft YaHei", 10), bg="#ffffff").pack(anchor="w", padx=70, pady=(24, 2))
        self.phone_var = tk.StringVar()
        tk.Entry(card, textvariable=self.phone_var, width=36, font=("Microsoft YaHei", 11)).pack(padx=70, pady=(0, 8), fill="x")
        tk.Label(card, text="密码", font=("Microsoft YaHei", 10), bg="#ffffff").pack(anchor="w", padx=70)
        self.pwd_var = tk.StringVar()
        tk.Entry(card, textvariable=self.pwd_var, width=36, show="*", font=("Microsoft YaHei", 11)).pack(padx=70, pady=(0, 16), fill="x")
        self.login_btn = tk.Button(card, text="登录", command=self._do_login, bg="#2563eb", fg="#ffffff",
                                   font=("Microsoft YaHei", 12, "bold"), width=18, relief="flat", cursor="hand2")
        self.login_btn.pack(pady=(0, 8))
        self.login_status = tk.Label(card, text="", fg="#dc2626", bg="#ffffff", font=("Microsoft YaHei", 10), wraplength=520)
        self.login_status.pack(pady=(0, 14))
        # 短信验证（默认隐藏）
        self.sms_frame = tk.Frame(card, bg="#ffffff")
        tk.Label(self.sms_frame, text="设备切换：请输入短信验证码", fg="#333", bg="#ffffff",
                 font=("Microsoft YaHei", 10)).pack(pady=(0, 6))
        row = tk.Frame(self.sms_frame, bg="#ffffff")
        row.pack()
        self.sms_var = tk.StringVar()
        tk.Entry(row, textvariable=self.sms_var, width=16, font=("Microsoft YaHei", 11)).pack(side="left", padx=4)
        tk.Button(row, text="发送", command=self._send_sms, bg="#e5e9f0", font=("Microsoft YaHei", 10)).pack(side="left", padx=4)
        tk.Button(row, text="确认登录", command=self._submit_sms, bg="#2563eb", fg="#ffffff", font=("Microsoft YaHei", 10)).pack(side="left", padx=4)

    def _do_login(self):
        phone = self.phone_var.get().strip()
        pwd = self.pwd_var.get()
        if not phone or not pwd:
            self.login_status.config(text="请输入手机号和密码")
            return
        self.login_status.config(text="登录中...", fg="#dc2626")
        self.login_btn.config(state="disabled")
        def work():
            try:
                r = self.bridge.login(phone, pwd)
            except Exception as e:
                self.root.after(0, lambda: (self.login_status.config(text="登录失败：%s" % e),
                                            self.login_btn.config(state="normal")))
                return
            self.root.after(0, lambda: self._handle_login_result(r))
        threading.Thread(target=work, daemon=True).start()

    def _handle_login_result(self, r):
        self.login_btn.config(state="normal")
        if r.get("ok"):
            self._show("dash")
            self._refresh_dash()
        elif r.get("need_captcha"):
            self._open_captcha(mode="login")
        elif r.get("need_sms"):
            self.sms_frame.pack(pady=6)
            self.login_status.config(text="需要短信验证，请点击发送获取验证码")
        else:
            self.login_status.config(text=r.get("error") or "登录失败")

    def _open_captcha(self, mode="login"):
        """mode: login=登录验证码提交 / sms=发送短信前验证码。"""
        self.login_status.config(text="正在打开验证码...")
        def work():
            try:
                c = self.bridge.get_captcha()
                if not c.get("ok"):
                    self.root.after(0, lambda: self.login_status.config(text=c.get("error") or "验证码获取失败"))
                    return
                port = _free_port()
                result_file = os.path.join(tempfile.gettempdir(),
                                           "lajiao_captcha_%d_%d.txt" % (mode == "sms" and 1 or 0, int(time.time() * 1000)))
                if os.path.exists(result_file):
                    try:
                        os.remove(result_file)
                    except Exception:
                        pass
                if getattr(sys, "frozen", False):
                    args = [sys.executable, "--captcha", str(port), str(c.get("scene_id", "")),
                            str(c.get("region", "")), str(c.get("prefix", "")), result_file]
                else:
                    main_script = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), "main.py")
                    args = [sys.executable, main_script, "--captcha", str(port), str(c.get("scene_id", "")),
                            str(c.get("region", "")), str(c.get("prefix", "")), result_file]
                try:
                    flags = subprocess.CREATE_NO_WINDOW if os.name == "nt" else 0
                except AttributeError:
                    flags = 0
                subprocess.Popen(args, creationflags=flags)
                # 等待验证码服务就绪（最多 15 秒）
                import urllib.request
                ready = False
                for _ in range(75):
                    try:
                        urllib.request.urlopen("http://127.0.0.1:%d/" % port, timeout=1)
                        ready = True
                        break
                    except Exception:
                        time.sleep(0.2)
                if not ready:
                    self.root.after(0, lambda: self.login_status.config(text="验证码服务启动失败，请重试"))
                    return
                self.root.after(0, lambda: self.login_status.config(
                    text=("已弹出验证码，请在浏览器完成滑块验证..." if mode == "login"
                          else "请先在浏览器完成验证码，然后会自动发送短信...")))
                deadline = time.time() + 180
                validate = ""
                while time.time() < deadline:
                    if os.path.exists(result_file):
                        try:
                            validate = open(result_file, encoding="utf-8").read().strip()
                        except Exception:
                            validate = ""
                        if validate:
                            break
                    time.sleep(0.4)
                if not validate:
                    self.root.after(0, lambda: self.login_status.config(text="验证超时，请重试"))
                    return
                if mode == "sms":
                    self.root.after(0, lambda: self._send_sms_with_captcha(validate))
                else:
                    self.root.after(0, lambda: self._submit_captcha(validate))
            except Exception as e:
                self.root.after(0, lambda: self.login_status.config(text="验证码失败：%s" % e))
        threading.Thread(target=work, daemon=True).start()

    def _submit_captcha(self, validate):
        self.login_status.config(text="验证通过，登录中...")
        def work():
            try:
                r = self.bridge.submit_captcha(validate)
            except Exception as e:
                self.root.after(0, lambda: self.login_status.config(text="登录失败：%s" % e))
                return
            self.root.after(0, lambda: self._handle_login_result(r))
        threading.Thread(target=work, daemon=True).start()

    def _send_sms(self):
        self.login_status.config(text="发送中...")
        def work():
            try:
                r = self.bridge.send_sms()
            except Exception as e:
                self.root.after(0, lambda: self.login_status.config(text="发送失败：%s" % e))
                return
            if r.get("need_captcha"):
                # 发送短信需要先过验证码
                self.root.after(0, lambda: self._open_captcha(mode="sms"))
            else:
                self.root.after(0, lambda: self.login_status.config(
                    text="已发送，请查收" if r.get("ok") else (r.get("error") or "发送失败")))
        threading.Thread(target=work, daemon=True).start()

    def _send_sms_with_captcha(self, validate):
        self.login_status.config(text="验证通过，正在发送短信...")
        _write_log(self.logf, "发送短信: validate长度=%d validate=%r" % (len(validate or ""), (validate or "")[:80]))
        def work():
            try:
                r = self.bridge.send_sms(validate)
            except Exception as e:
                self.root.after(0, lambda: self.login_status.config(text="发送失败：%s" % e))
                return
            _write_log(self.logf, "发送短信结果: %r" % r)
            self.root.after(0, lambda: self.login_status.config(
                text="已发送，请查收" if r.get("ok") else (r.get("error") or "发送失败")))
        threading.Thread(target=work, daemon=True).start()

    def _submit_sms(self):
        code = self.sms_var.get().strip()
        if not code:
            self.login_status.config(text="请输入短信验证码")
            return
        self.login_status.config(text="登录中...")
        def work():
            try:
                r = self.bridge.submit_sms(code)
            except Exception as e:
                self.root.after(0, lambda: self.login_status.config(text="登录失败：%s" % e))
                return
            if r.get("ok"):
                self.root.after(0, lambda: (self._show("dash"), self._refresh_dash()))
            else:
                self.root.after(0, lambda: self.login_status.config(text=r.get("error") or "登录失败"))
        threading.Thread(target=work, daemon=True).start()

    # ---------- 看板 ----------
    def _build_dashboard(self):
        self.dash_frame = tk.Frame(self.root, bg="#f0f4ff")
        tk.Label(self.dash_frame, text="辣椒群管机器人 - 看板", font=("Microsoft YaHei", 16, "bold"),
                 bg="#f0f4ff").pack(pady=14)
        self.dash_meta = tk.Label(self.dash_frame, text="", fg="#666", bg="#f0f4ff", font=("Microsoft YaHei", 10))
        self.dash_meta.pack()
        self.dash_list = tk.Frame(self.dash_frame, bg="#f0f4ff")
        self.dash_list.pack(fill="both", expand=True, padx=24, pady=10)
        self.dash_status = tk.Label(self.dash_frame, text="", fg="#666", bg="#f0f4ff", font=("Microsoft YaHei", 10))
        self.dash_status.pack(pady=4)
        tk.Button(self.dash_frame, text="刷新", command=self._refresh_dash, bg="#2563eb", fg="#ffffff",
                  font=("Microsoft YaHei", 11), width=14, relief="flat", cursor="hand2").pack(pady=8)
        tk.Label(self.dash_frame, text="管理指令请在群内由超管发送（菜单 / 镖头最帅）", fg="#999",
                 bg="#f0f4ff", font=("Microsoft YaHei", 9)).pack(pady=(0, 10))
        # 每 10 秒自动刷新
        def _auto():
            try:
                if not self.dash_frame.winfo_ismapped():
                    return
                self._refresh_dash(silent=True)
            except Exception:
                pass
            self.root.after(10000, _auto)
        self.root.after(10000, _auto)

    def _refresh_dash(self, silent=False):
        try:
            d = self.bridge.get_dashboard()
            if d.get("need_login"):
                self.dash_status.config(text="未登录")
                self._show("login")
                return
            supers = d.get("super_admins") or []
            if not supers:
                self._show("config")
                return
            self.dash_meta.config(text="登录账号：%s ｜ 超管：%s" % (d.get("user_id", ""), ",".join(supers)))
            pc28_count = d.get("pc28_push_count", 0)
            for w in self.dash_list.winfo_children():
                w.destroy()
            groups = d.get("groups") or []
            if not groups:
                tk.Label(self.dash_list, text="暂无已加入的群", bg="#f0f4ff", fg="#999",
                         font=("Microsoft YaHei", 11)).pack()
            else:
                for g in groups:
                    dot = "🟢" if g.get("enabled") else "🔴"
                    rem = "运行中" if g.get("enabled") else "已停用"
                    pc28_flag = "  [开奖推送]" if g.get("pc28_push_enabled") else ""
                    line = "%s  %s  （%s）  %s%s" % (
                        dot, g.get("gname", ""), g.get("gid", ""), rem, pc28_flag)
                    tk.Label(self.dash_list, text=line, anchor="w", bg="#ffffff",
                             font=("Microsoft YaHei", 11), padx=12, pady=8).pack(fill="x", pady=3)
            self.dash_status.config(text="开奖推送群数：%d" % pc28_count)
        except Exception as e:
            if not silent:
                self.dash_status.config(text="刷新失败：%s" % e)

    # ---------- 配置超管 ----------
    def _build_config(self):
        self.config_frame = tk.Frame(self.root, bg="#f0f4ff")
        card = tk.Frame(self.config_frame, bg="#ffffff")
        card.pack(pady=40, padx=48, fill="both", expand=True)
        tk.Label(card, text="首次配置", font=("Microsoft YaHei", 16, "bold"), bg="#ffffff").pack(pady=(30, 6))
        tk.Label(card, text="请填写超级管理员的 userID（群内指令仅此账号生效）", fg="#666",
                 bg="#ffffff", font=("Microsoft YaHei", 10)).pack()
        tk.Label(card, text="超级管理员 userID", font=("Microsoft YaHei", 10), bg="#ffffff").pack(anchor="w", padx=70, pady=(18, 2))
        self.sa_var = tk.StringVar()
        tk.Entry(card, textvariable=self.sa_var, width=30, font=("Microsoft YaHei", 11)).pack(padx=70)
        self.sa_status = tk.Label(card, text="", fg="#dc2626", bg="#ffffff", font=("Microsoft YaHei", 10))
        self.sa_status.pack(pady=8)
        tk.Button(card, text="保存并进入", command=self._save_sa, bg="#2563eb", fg="#ffffff",
                  font=("Microsoft YaHei", 12), width=16, relief="flat", cursor="hand2").pack()

    def _save_sa(self):
        uid = self.sa_var.get().strip()
        if not uid:
            self.sa_status.config(text="请输入超级管理员 userID")
            return
        r = self.bridge.set_super_admin(uid)
        if r.get("ok"):
            self._show("dash")
            self._refresh_dash()
        else:
            self.sa_status.config(text="保存失败")


def main():
    ini_path = _find_ini()
    root = tk.Tk()
    BotGUI(root, ini_path)
    root.mainloop()
