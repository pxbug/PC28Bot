"""V2 看板桥接：登录 + 群列表（只读）。"""
import time
import json

from .runner import V2Runner


def json_dumps(obj):
    try:
        return json.dumps(obj, ensure_ascii=False)[:400]
    except Exception:
        return str(obj)[:400]


class DashboardBridge:
    def __init__(self, logger=None):
        self.logger = logger or (lambda msg: None)
        self.runner = V2Runner(logger=logger)
        self._started = False
        self._ini_path = "lajiao_bot.ini"
        self._lb = None

    # ---------- 登录 ----------
    def _login_bridge(self):
        if self._lb is None:
            from .login import LoginBridge
            self._lb = LoginBridge(self._ini_path, self.logger)
        return self._lb

    def login(self, phone, password):
        lb = self._login_bridge()
        result = lb.login(phone, password)
        self.logger("[dashboard] login -> %s" % json_dumps(result))
        if result.get("ok"):
            self._ini_path = lb.client.config.path or self._ini_path
            self._apply_login()
        return result

    def get_captcha(self):
        return self._login_bridge().get_captcha()

    def submit_captcha(self, validate):
        result = self._login_bridge().submit_captcha(validate)
        self.logger("[dashboard] submit_captcha -> %s" % json_dumps(result))
        if result.get("ok"):
            self._apply_login()
        return result

    def send_sms(self, captcha_token=None):
        result = self._login_bridge().send_sms(captcha_token)
        self.logger("[dashboard] send_sms -> %s" % json_dumps(result))
        return result

    def submit_sms(self, code):
        result = self._login_bridge().submit_sms(code)
        if result.get("ok"):
            self._apply_login()
        return result

    def get_login_state(self):
        lb = self._login_bridge()
        return {"logged_in": bool(lb.is_logged_in), "user_id": lb.client.user_id}

    def _apply_login(self):
        try:
            self.runner.login_from_ini(self._ini_path)
            self.runner.start()
            self._started = True
        except Exception as e:
            self.logger("[dashboard] 启动运行时失败: %s" % e)

    def start(self, ini_path="lajiao_bot.ini"):
        self._ini_path = ini_path
        self.runner.login_from_ini(ini_path)
        if self.runner.client.user_id and self.runner.client.im_token:
            self.runner.start()
            self._started = True

    def stop(self):
        if self._started:
            self.runner.stop()
            self._started = False

    def set_super_admin(self, uid):
        ok = self.runner.set_super_admin(uid)
        return {"ok": ok, "super_admins": self.runner.cfg_mod.super_admins(self.runner.config)}

    def log_js(self, msg):
        try:
            self.logger("[js] %s" % str(msg))
        except Exception:
            pass
        return {"ok": True}

    # ---------- 前端只读接口 ----------
    def get_dashboard(self):
        if not self._started:
            self.start(self._ini_path)
        if not self._started:
            return {"need_login": True}
        runner = self.runner
        config = runner.config
        store = runner.store
        super_admins = [str(x) for x in config.get("permissions", {}).get("superAdminIds", [])]
        groups = []
        for gid in store.group_ids():
            meta = runner.runtime._group_meta.get(gid, {})
            gname = meta.get("name", gid)
            groups.append({
                "gid": gid,
                "gname": gname,
                "enabled": bool(store.is_group_active(gid)),
                "remaining": "运行中" if store.is_group_active(gid) else "已停用",
            })
        if runner.client is not None:
            try:
                gl = runner.client.get_group_list()
                known = set(g["gid"] for g in groups)
                for g in gl:
                    gid = g.get("groupID", "")
                    if gid and gid not in known:
                        groups.append({
                            "gid": gid,
                            "gname": g.get("groupName", "") or gid,
                            "enabled": bool(store.is_group_active(gid)),
                            "remaining": "运行中" if store.is_group_active(gid) else "已停用",
                        })
            except Exception:
                pass
        return {
            "need_login": False,
            "user_id": (runner.client.user_id if runner.client else ""),
            "super_admins": super_admins,
            "groups": groups,
            "time": int(time.time() * 1000),
        }

    def get_status(self):
        return {"running": self._started, "user_id": (self.runner.client.user_id if self.runner.client else "")}
