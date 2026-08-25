"""V2 登录桥接：手机号+密码（含阿里云验证码 / 设备切换短信）。"""
from api_client import (
    ApiClient,
    CaptchaRequiredException,
    NeedConfirmSwitchException,
    DeviceSwitchRequired,
    _md5,
)


def _password_variants(raw):
    return [
        ("md5", _md5(raw)),
        ("plain", raw),
        ("md5(md5)", _md5(_md5(raw))),
    ]


class LoginBridge:
    def __init__(self, config_path=None, logger=None):
        self.client = ApiClient(config_path)
        self.logger = logger or (lambda msg: None)
        self._pending = None  # {phone, password}
        self._captcha_result = None

    @property
    def is_logged_in(self):
        return bool(self.client.user_id and self.client.im_token)

    def login(self, phone, password):
        """第一步登录。返回 {ok} / {need_captcha} / {need_sms} / {error}。"""
        phone = (phone or "").strip()
        if not phone or not password:
            return {"ok": False, "error": "请输入手机号和密码"}
        self._pending = {"phone": phone, "password": password}
        for label, pwd in _password_variants(password):
            try:
                result = self.client.login(phone, pwd, "+86")
                self._pending = None
                return {"ok": True, "user_id": result.get("userID", ""), "password_mode": label}
            except CaptchaRequiredException:
                return self._need_captcha(phone, password)
            except (NeedConfirmSwitchException, DeviceSwitchRequired) as ex:
                return self._need_sms(ex)
            except Exception as e:
                self.logger("[login] %s: %s" % (label, e))
                continue
        return {"ok": False, "error": "登录失败，请检查账号密码"}

    def get_captcha(self):
        """获取验证码场景配置（前端据此渲染验证码）。"""
        try:
            scene, _ = self.client.get_captcha_config()
            if not scene:
                return {"error": "无法获取验证码配置"}
            return {
                "ok": True,
                "scene_id": scene.get("sceneId", ""),
                "region": scene.get("region", ""),
                "prefix": scene.get("prefix", ""),
            }
        except Exception as e:
            return {"error": str(e)}

    def submit_captcha(self, validate):
        """验证码通过后，用 validate 完成登录。"""
        validate = str(validate or "").strip()
        if not validate:
            return {"ok": False, "error": "验证码无效"}
        if not self._pending:
            return {"ok": False, "error": "登录会话已失效，请重新登录"}
        phone = self._pending["phone"]
        password = self._pending["password"]
        for label, pwd in _password_variants(password):
            try:
                result = self.client.login(phone, pwd, "+86", captcha_verify_param=validate)
                self._pending = None
                return {"ok": True, "user_id": result.get("userID", ""), "password_mode": label}
            except CaptchaRequiredException:
                return {"ok": False, "error": "验证码未通过，请重试", "need_captcha": True}
            except (NeedConfirmSwitchException, DeviceSwitchRequired) as ex:
                return self._need_sms(ex)
            except Exception as e:
                self.logger("[captcha] %s: %s" % (label, e))
                continue
        return {"ok": False, "error": "登录失败"}

    def send_sms(self, captcha_token=None):
        """设备切换：发送短信验证码。若需安全验证（20022）则返回 need_captcha。"""
        if not self._pending:
            return {"ok": False, "error": "登录会话已失效，请重新登录"}
        try:
            self.client._send_sms_code(self._pending["phone"], "+86", used_for=8, captcha_token=captcha_token)
            return {"ok": True}
        except Exception as e:
            msg = str(e)
            if "20022" in msg and not captcha_token:
                return {"need_captcha": True}
            return {"ok": False, "error": msg}

    def submit_sms(self, code):
        """设备切换：用短信验证码登录。"""
        if not self._pending:
            return {"ok": False, "error": "登录会话已失效，请重新登录"}
        try:
            result = self.client.login_with_verify_code(self._pending["phone"], "+86", str(code or "").strip())
            self._pending = None
            return {"ok": True, "user_id": result.get("userID", "")}
        except Exception as e:
            return {"ok": False, "error": str(e)}

    def logout(self):
        try:
            self.client.config.clear()
        except Exception:
            pass
        self.client.user_id = ""
        self.client.im_token = ""
        self.client.chat_token = ""
        return {"ok": True}

    def _need_captcha(self, phone, password):
        self._pending = {"phone": phone, "password": password}
        return {"need_captcha": True}

    def _need_sms(self, ex):
        phone = getattr(ex, "phone", self._pending["phone"] if self._pending else "")
        self._pending = {"phone": phone, "password": self._pending["password"] if self._pending else ""}
        return {"need_sms": True}
