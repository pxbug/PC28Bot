"""HTTP API 封装：登录、群、消息、成员、名单、申请等全部端点复刻。

行为规格来源：现版发布包 api_client.py 静态反汇编。
"""
import hashlib
import json
import os
import subprocess
import sys
import tempfile
import threading
import time
import uuid
from urllib.parse import parse_qs, urlparse

import requests
from requests.adapters import HTTPAdapter
from urllib3.util.retry import Retry

API_BASE = "https://api.lajiaoliao.com"
IM_API_BASE = "https://im-api.lajiaoliao.com"
PLATFORM_ID = 5
CONFIG_FILE = "lajiao_bot.ini"
USER_AGENT = (
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) "
    "lajiaoliao/1.0.1 Chrome/120.0.0.0 Electron/28.0.0 Safari/537.36"
)

# ---- DNS 兜底：DNS 解析失败时用已知 IP，正常时用域名 ----
_KNOWN_IPS = {
    "api.lajiaoliao.com": "39.108.102.113",
    "im-api.lajiaoliao.com": "120.77.83.174",
    "ws.lajiaoliao.com": "120.77.83.174",
}


def _install_dns_fallback():
    """DNS 解析失败时用已知 IP 直连（保留域名用于 TLS/SNI）。"""
    try:
        from urllib3.util.connection import create_connection as _orig

        def _patched(address, *args, **kwargs):
            host = address[0]
            try:
                return _orig(address, *args, **kwargs)
            except OSError:
                ip = _KNOWN_IPS.get(host)
                if ip:
                    try:
                        return _orig((ip, address[1]), *args, **kwargs)
                    except OSError:
                        pass
                raise

        import urllib3.util.connection as _mod
        _mod.create_connection = _patched
    except Exception:
        pass


_install_dns_fallback()


def _build_direct_session():
    """构建 Session（域名直连 + DNS 兜底）。"""
    sess = requests.Session()
    retry_strategy = Retry(
        total=2,
        connect=1,
        read=1,
        status=2,
        backoff_factor=0.3,
        status_forcelist=[500, 502, 503, 504],
        raise_on_status=False,
    )
    adapter = HTTPAdapter(max_retries=retry_strategy)
    sess.mount("https://", adapter)
    sess.mount("http://", adapter)
    return sess


def _opid():
    return uuid.uuid4().hex


def _md5(t):
    return hashlib.md5(t.encode("utf-8")).hexdigest()


def _password_variants(raw_password):
    """生成候选密码格式：md5 / 明文 / 双重md5。返回 (label, value)。"""
    variants = [
        ("md5", _md5(raw_password)),
        ("plain", raw_password),
        ("md5(md5)", _md5(_md5(raw_password))),
        ("md5(phone+md5)", None),  # 占位，调用方填充
    ]
    return variants


class Config:
    """本地 token/device 配置，写入 INI 文件。"""

    def __init__(self, path=None):
        import configparser
        self.parser = configparser.ConfigParser()
        self.path = path or CONFIG_FILE
        if os.path.exists(self.path):
            self.parser.read(self.path, encoding="utf-8")

    def save_token(self, uid, im, chat):
        if not self.parser.has_section("token"):
            self.parser.add_section("token")
        self.parser.set("token", "user_id", str(uid))
        self.parser.set("token", "im_token", str(im))
        self.parser.set("token", "chat_token", str(chat))
        self._write()

    def load_token(self):
        if not self.parser.has_section("token"):
            return {"user_id": "", "im_token": "", "chat_token": ""}
        return {
            "user_id": self.parser.get("token", "user_id", fallback=""),
            "im_token": self.parser.get("token", "im_token", fallback=""),
            "chat_token": self.parser.get("token", "chat_token", fallback=""),
        }

    def save_device(self, did):
        if not self.parser.has_section("device"):
            self.parser.add_section("device")
        self.parser.set("device", "id", str(did))
        self._write()

    def load_device(self):
        if not self.parser.has_section("device"):
            return ""
        return self.parser.get("device", "id", fallback="")

    def clear(self):
        for section in list(self.parser.sections()):
            self.parser.remove_section(section)
        self._write()

    def _write(self):
        try:
            with open(self.path, "w", encoding="utf-8") as f:
                self.parser.write(f)
        except Exception:
            pass


class CaptchaRequiredException(Exception):
    def __init__(self, msg, raw_response=None, phone=None, password=None, area_code=None):
        super().__init__(msg)
        self.msg = msg
        self.raw_response = raw_response
        self.phone = phone
        self.password = password
        self.area_code = area_code


class NeedConfirmSwitchException(Exception):
    def __init__(self, msg, phone=None, password=None, area_code=None):
        super().__init__(msg)
        self.msg = msg
        self.phone = phone
        self.password = password
        self.area_code = area_code


class DeviceSwitchRequired(Exception):
    def __init__(self, phone=None, area_code=None):
        super().__init__("需要短信验证码确认设备切换")
        self.phone = phone
        self.area_code = area_code


class CaptchaSolver:
    """启动子进程 + 本地 HTTP 回调完成阿里云验证码。"""

    @staticmethod
    def solve(scene_id, region, prefix, timeout_sec=180):
        import socket
        sock = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        sock.bind(("", 0))
        port = sock.getsockname()[1]
        sock.close()
        result_file = os.path.join(tempfile.gettempdir(), ".captcha_" + uuid.uuid4().hex + ".tmp")
        exe_path = sys.argv[0]
        main_py = os.path.join(os.path.dirname(os.path.abspath(__file__)), "main.py")
        if os.path.splitext(exe_path)[1].lower() != ".exe":
            # 源码运行：用当前解释器 + main.py
            cmd = [sys.executable, main_py, "--captcha", str(port), str(scene_id), str(region), str(prefix), result_file]
        else:
            cmd = [exe_path, "--captcha", str(port), str(scene_id), str(region), str(prefix), result_file]
        proc = subprocess.Popen(cmd, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        deadline = time.time() + timeout_sec
        validate = ""
        while time.time() < deadline:
            if os.path.exists(result_file):
                try:
                    with open(result_file, "r", encoding="utf-8") as f:
                        validate = f.read().strip()
                except Exception:
                    validate = ""
                break
            time.sleep(0.3)
        try:
            proc.kill()
        except Exception:
            pass
        try:
            if os.path.exists(result_file):
                os.remove(result_file)
        except Exception:
            pass
        return validate


class ApiClient:
    def __init__(self, config_path=None):
        self.session = _build_direct_session()
        self.session.headers.update({"User-Agent": USER_AGENT})
        self.config = Config(config_path)
        saved = self.config.load_token()
        self.chat_token = saved["chat_token"]
        self.im_token = saved["im_token"]
        self.user_id = saved["user_id"]
        self.last_login_diag = None
        self.device_id = self.config.load_device()
        if not self.device_id:
            self.device_id = "device_web_" + uuid.uuid4().hex
            self.config.save_device(self.device_id)

    def _raw_headers(self):
        return {
            "Content-Type": "application/json",
            "operationID": _opid(),
            "deviceID": self.device_id,
            "platform": str(PLATFORM_ID),
            "version": "1.6.4",
        }

    def _auth_headers(self, use_im=True):
        """鉴权请求头（与原版一致：use_im=True 用 im_token，False 用 chat_token）。"""
        return {
            "Content-Type": "application/json",
            "token": (self.im_token or "") if use_im else (self.chat_token or ""),
            "operationID": _opid(),
            "deviceID": self.device_id,
            "platform": str(PLATFORM_ID),
            "version": "1.6.4",
        }

    def _im_headers(self):
        """IM API 请求头（用 im_token）。"""
        return self._auth_headers(True)

    def _raw_json(self, resp):
        try:
            return resp.json()
        except Exception:
            try:
                raw = resp.text
                return json.loads(raw)
            except Exception as e:
                raise Exception(
                    "服务器返回非JSON (状态码 %s)\n请求URL: %s\n请求体: %s\n响应前500字符: %s\nJSON错误: %s"
                    % (
                        getattr(resp, "status_code", ""),
                        getattr(getattr(resp, "request", None), "url", ""),
                        "无",
                        resp.text[:500],
                        e,
                    )
                )

    # ---------- 登录 ----------

    def get_captcha_config(self):
        """返回 (scene_dict, full_config) 或 (None, None)。"""
        headers = self._raw_headers()
        resp = self.session.post(API_BASE + "/client_config/get", json={}, headers=headers, timeout=30)
        data = self._raw_json(resp)
        if data.get("errCode") != 0:
            return None, None
        d = data.get("data", {}) or {}
        raw_cfg = (d.get("config") or {}).get("captcha") or {}
        if not raw_cfg.get("enabled") or raw_cfg.get("provider") != "aliyun":
            return None, d
        cfg = {
            "region": raw_cfg.get("region", ""),
            "prefix": raw_cfg.get("prefix", ""),
            "scenes": raw_cfg.get("scenes", []),
        }
        scene = None
        for s in cfg["scenes"]:
            if s.get("sceneId"):
                scene = dict(s)
                # region/prefix 位于 captcha 顶层，合并进 scene 供调用方读取
                scene.setdefault("region", cfg["region"])
                scene.setdefault("prefix", cfg["prefix"])
                break
        return scene, d

    def login(self, phone, password, area_code="+86", captcha_verify_param=None):
        # password 为已处理好的值（默认 md5，submit_captcha 会传入探测变体）
        # 字段名对齐官方客户端：phoneNumber / areaCode / passwordMD5 / verifyCode
        payload = {
            "phoneNumber": phone,
            "areaCode": area_code,
            "passwordMD5": password,
            "platform": PLATFORM_ID,
            "verifyCode": "",
        }
        if captcha_verify_param:
            payload["captchaVerifyParam"] = captcha_verify_param
        headers = self._raw_headers()
        headers["token"] = ""
        resp = self.session.post(API_BASE + "/pc/account/login", json=payload, headers=headers, timeout=30)
        data = self._raw_json(resp)
        # 诊断：记录最近一次登录响应
        try:
            self.last_login_diag = {
                "payload_keys": list(payload.keys()),
                "resp": data,
                "has_captcha_param": bool(captcha_verify_param),
                "captcha_param_len": len(captcha_verify_param) if captcha_verify_param else 0,
                "password_len": len(str(password)),
            }
        except Exception:
            pass
        err_code = data.get("errCode")
        err_msg = data.get("errMsg") or data.get("msg", "")
        if err_code == 20022:
            raise CaptchaRequiredException(
                "需要安全验证 (20022)",
                raw_response=data,
                phone=phone,
                password=password,
                area_code=area_code,
            )
        if err_code != 0:
            raise Exception(
                "errCode=%s errMsg=%s\n请求URL: %s/pc/account/login\n请求体: %s\n完整响应: %s"
                % (err_code, err_msg, API_BASE, json.dumps(payload, ensure_ascii=False), json.dumps(data, ensure_ascii=False))
            )
        if err_code != 0:
            raise Exception(
                "errCode=%s errMsg=%s\n请求URL: %s/pc/account/login\n请求体: %s\n完整响应: %s"
                % (err_code, err_msg, API_BASE, json.dumps(payload, ensure_ascii=False), json.dumps(data, ensure_ascii=False))
            )
        result = data.get("data") or {}
        chat_token = result.get("chatToken", "")
        im_token = result.get("imToken", "")
        user_id = result.get("userID", "")
        if result.get("needConfirmSameClassDeviceSwitch"):
            raise NeedConfirmSwitchException("需要确认设备切换", phone=phone, password=password, area_code=area_code)
        fresh_im = self._refresh_im_token(chat_token)
        if fresh_im:
            im_token = fresh_im
        self.set_tokens(user_id, im_token, chat_token)
        return result

    def login_with_captcha(self, phone, password, area_code="+86"):
        try:
            return self.login(phone, _md5(password), area_code)
        except CaptchaRequiredException:
            scene, captcha_cfg = self.get_captcha_config()
            if not scene:
                raise Exception("服务器要求验证码，但无法获取验证码配置")
            validate = CaptchaSolver.solve(
                scene.get("sceneId"),
                scene.get("region", ""),
                scene.get("prefix", ""),
            )
            if not validate:
                raise Exception("验证码未完成或超时")
            # 只带 validate 重试一次；若仍被要求验证码则抛出明确错误（避免循环弹窗）
            try:
                return self.login(phone, password, area_code, captcha_verify_param=validate)
            except CaptchaRequiredException as e:
                raise Exception(
                    "验证码已提交但服务器仍要求验证（可能验证码失效或参数不完整）\n%s" % str(e)
                )
            except Exception as e:
                raise e

    def _send_sms_code(self, phone, area_code="+86", used_for=8, captcha_token=None):
        payload = {"phoneNumber": phone, "areaCode": area_code, "usedFor": used_for, "platform": PLATFORM_ID}
        headers = self._raw_headers()
        if captcha_token:
            payload["captchaVerifyParam"] = captcha_token
        resp = self.session.post(API_BASE + "/account/code/send", json=payload, headers=headers, timeout=30)
        data = self._raw_json(resp)
        if data.get("errCode") == 20022:
            if captcha_token:
                raise Exception("发送验证码失败: 需要安全验证 (20022)，验证码未通过")
            # 不弹浏览器验证码；提示需要验证码
            raise Exception("发送验证码失败: 需要安全验证 (20022)")
        if data.get("errCode") != 0:
            raise Exception("发送验证码失败: errCode=%s msg=%s" % (data.get("errCode"), data.get("errMsg")))
        return

    def login_with_verify_code(self, phone, area_code="+86", verify_code=None):
        payload = {
            "phoneNumber": phone,
            "areaCode": area_code,
            "verifyCode": verify_code or "",
            "platform": PLATFORM_ID,
            "passwordMD5": "",
        }
        headers = self._raw_headers()
        resp = self.session.post(API_BASE + "/pc/account/login", json=payload, headers=headers, timeout=30)
        data = self._raw_json(resp)
        if data.get("errCode") != 0:
            raise Exception(
                "验证码登录失败: errCode=%s msg=%s\n完整响应: %s"
                % (data.get("errCode"), data.get("errMsg"), json.dumps(data, ensure_ascii=False))
            )
        result = data.get("data") or {}
        chat_token = result.get("chatToken", "")
        im_token = result.get("imToken", "")
        user_id = result.get("userID", "")
        # 总是刷新 IM token（登录返回的 imToken 可能无效）
        fresh_im = self._refresh_im_token(chat_token)
        if fresh_im:
            im_token = fresh_im
        self.set_tokens(user_id, im_token, chat_token)
        return result

    def _refresh_im_token(self, chat_token):
        headers = self._raw_headers()
        headers["token"] = chat_token or ""
        resp = self.session.post(API_BASE + "/pc/account/get_im_token", json={"platform": PLATFORM_ID}, headers=headers, timeout=30)
        d = self._raw_json(resp)
        if d.get("errCode") != 0:
            return ""
        data = d.get("data") or {}
        return data.get("imToken", "")

    def set_tokens(self, uid, im, chat):
        self.user_id = uid
        self.im_token = im
        self.chat_token = chat
        self.config.save_token(uid, im, chat)

    # ---------- 群与消息 ----------

    def get_group_list(self):
        if not self.im_token:
            raise Exception("im_token 为空！无法调用IM API\nuser_id=%s chat_token存在=%s" % (self.user_id, bool(self.chat_token)))
        all_groups = []
        seen_ids = set()
        pagination_formats = [
            {"pagination": {"pageNumber": 1, "showNumber": 100}},
            {"pagination": {"page": 1, "size": 100}},
            {"pagination": {"pageNumber": 1, "pageSize": 100}},
            {},
        ]
        last_err = None
        for pag in pagination_formats:
            body = {"fromUserID": self.user_id, "operationID": _opid()}
            body.update(pag)
            try:
                resp = self.session.post(
                    IM_API_BASE + "/group/get_joined_group_list",
                    json=body,
                    headers=self._im_headers(),
                    timeout=30,
                )
                d = self._raw_json(resp)
            except Exception as e:
                last_err = e
                continue
            if d.get("errCode") != 0:
                last_err = Exception(str(d))
                continue
            data = d.get("data") or {}
            groups = data.get("groups") or data.get("groupList") or []
            if not isinstance(groups, list):
                groups = [data]
            for g in groups:
                gid = g.get("groupID", "")
                if gid and gid not in seen_ids:
                    seen_ids.add(gid)
                    all_groups.append(g)
            if pag and data:
                break
        if not all_groups and last_err:
            raise Exception("获取群列表失败 - 尝试了多种参数格式\n最后响应: %s" % last_err)
        return all_groups

    def get_group_max_seq(self, group_id):
        conv_id = "sg_" + group_id
        resp = self.session.post(
            IM_API_BASE + "/msg/newest_seq",
            json={"conversationID": conv_id, "userID": self.user_id},
            headers=self._im_headers(),
            timeout=30,
        )
        d = self._raw_json(resp)
        data = d.get("data") or {}
        max_seqs = data.get("maxSeqs") or {}
        if isinstance(max_seqs, dict):
            return int(max_seqs.get(conv_id, 0) or 0)
        return 0

    def get_group_messages_since(self, group_id, since_seq, count=50):
        conv_id = "sg_" + group_id
        body = {"conversationID": conv_id, "begin": since_seq, "num": count}
        resp = self.session.post(
            IM_API_BASE + "/msg/pull_msg_by_seq",
            json=body,
            headers=self._im_headers(),
            timeout=30,
        )
        d = self._raw_json(resp)
        if d.get("errCode") != 0:
            raise Exception("拉取消息失败: errCode=%s msg=%s" % (d.get("errCode"), d.get("errMsg")))
        data = d.get("data") or {}
        msg_list = data.get("msgs") or data.get("Msgs") or []
        new_max = since_seq
        for m in msg_list:
            s = m.get("seq")
            if isinstance(s, int) and s > new_max:
                new_max = s
        return msg_list, new_max

    def get_all_max_seqs(self):
        resp = self.session.post(
            IM_API_BASE + "/msg/newest_seq",
            json={"userID": self.user_id},
            headers=self._im_headers(),
            timeout=30,
        )
        d = self._raw_json(resp)
        if d.get("errCode") != 0:
            raise Exception("获取max_seq失败: errCode=%s errMsg=%s" % (d.get("errCode"), d.get("errMsg")))
        data = d.get("data") or {}
        max_seqs = data.get("maxSeqs") or {}
        result = {}
        for k, v in (max_seqs or {}).items():
            try:
                result[k] = int(v)
            except Exception:
                result[k] = 0
        return result

    def pull_msg_by_range(self, conv_id, begin, end):
        body = {"conversationID": conv_id, "begin": begin, "end": end}
        resp = self.session.post(
            IM_API_BASE + "/msg/pull_msg_by_seq",
            json=body,
            headers=self._im_headers(),
            timeout=30,
        )
        d = self._raw_json(resp)
        if d.get("errCode") != 0:
            raise Exception("拉取消息失败: errCode=%s errMsg=%s" % (d.get("errCode"), d.get("errMsg")))
        return self._extract_msgs(d.get("data") or {})

    def _extract_msgs(self, d):
        data = d.get("data") if isinstance(d, dict) else None
        for key in ("msgs", "msgList", "Msgs", "messageList"):
            val = data.get(key) if isinstance(data, dict) else None
            if isinstance(val, list):
                return val
        msgs_map = d.get("msgs") if isinstance(d, dict) else None
        if isinstance(msgs_map, dict):
            lst = []
            for k, v in msgs_map.items():
                if isinstance(v, dict):
                    sub = v.get("Msgs") or v.get("msgs") or []
                    if isinstance(sub, list):
                        lst.extend(sub)
            if lst:
                return lst
        return []

    def pull_msg_num(self, conv_id, count):
        headers = self._im_headers()
        attempts = [
            (IM_API_BASE + "/msg/pull_msg_by_seq", {"conversationID": conv_id, "begin": 0, "num": count}),
            (IM_API_BASE + "/msg/pull_msg_by_seq", {"conversationID": conv_id, "num": count}),
            (IM_API_BASE + "/msg", {"conversationID": conv_id, "begin": 0, "end": count}),
        ]
        errors = []
        for url, body in attempts:
            try:
                resp = self.session.post(url, json=body, headers=headers, timeout=30)
                d = self._raw_json(resp)
                msgs = self._extract_msgs(d.get("data") or {})
                if msgs:
                    return msgs
                if isinstance(d, dict):
                    msgs = d.get("data") or []
                    if isinstance(msgs, list) and msgs:
                        return msgs
            except Exception as e:
                errors.append("%s: %s" % (url, e))
        raise Exception("pull_msg_num 全部失败: %s" % "\n".join(errors))

    def get_group_latest(self, group_id, count=20):
        conv_id = "sg_" + group_id
        max_seq = self.get_group_max_seq(group_id)
        begin = max(0, max_seq - count)
        return self.pull_msg_by_range(conv_id, begin, max_seq + 1)

    def get_group_members(self, gid):
        all_members = []
        page = 0
        while True:
            body = {"groupID": gid, "filter": 0, "pagination": {"pageNumber": page + 1, "showNumber": 100}}
            try:
                resp = self.session.post(
                    IM_API_BASE + "/group/get_group_member_list",
                    json=body,
                    headers=self._im_headers(),
                    timeout=30,
                )
                d = self._raw_json(resp)
            except Exception as e:
                raise Exception("获取群成员失败 (page=%s): %s" % (page, e))
            if d.get("errCode") != 0:
                raise Exception("获取群成员失败 (page=%s): %s" % (page, d.get("errMsg")))
            data = d.get("data") or {}
            members = data.get("members") or []
            if not isinstance(members, list):
                break
            all_members.extend(members)
            if len(members) < 100:
                break
            page += 1
        return all_members

    # ---------- 成员治理 ----------

    def kick(self, gid, uid):
        try:
            resp = self.session.post(
                IM_API_BASE + "/group/kick_group",
                json={"groupID": gid, "kickedUserIDs": [uid], "reason": "", "sendMessage": None},
                headers=self._im_headers(),
                timeout=15,
            )
            d = self._raw_json(resp)
            if d.get("errCode") != 0:
                raise Exception("踢出失败: %s" % d.get("errMsg"))
            return d
        except Exception:
            raise

    def mute(self, gid, uid, sec):
        resp = self.session.post(
            IM_API_BASE + "/group/mute_group_member",
            json={"groupID": gid, "userID": uid, "mutedSeconds": sec},
            headers=self._im_headers(),
            timeout=30,
        )
        d = self._raw_json(resp)
        if d.get("errCode") != 0:
            raise Exception("禁言失败: %s" % d.get("errMsg"))
        return d

    def unmute(self, gid, uid):
        resp = self.session.post(
            IM_API_BASE + "/group/cancel_mute_group_member",
            json={"groupID": gid, "userID": uid},
            headers=self._im_headers(),
            timeout=30,
        )
        d = self._raw_json(resp)
        if d.get("errCode") != 0:
            raise Exception("解除禁言失败: %s" % d.get("errMsg"))
        return d

    def set_group(self, gid, kw):
        info = {"groupID": gid}
        for k, v in (kw or {}).items():
            if v:
                info[k] = v
        resp = self.session.post(
            IM_API_BASE + "/group/set_group_info",
            json={"groupInfoForSet": info},
            headers=self._im_headers(),
            timeout=30,
        )
        d = self._raw_json(resp)
        if d.get("errCode") != 0:
            raise Exception("设置失败: %s" % d.get("errMsg"))
        return d

    def set_member_nickname(self, gid, uid, nickname):
        resp = self.session.post(
            IM_API_BASE + "/group/set_group_member_info",
            json={"groupID": gid, "userID": uid, "nickname": nickname, "faceURL": ""},
            headers=self._im_headers(),
            timeout=30,
        )
        d = self._raw_json(resp)
        if d.get("errCode") != 0:
            raise Exception("设置群名片失败: %s" % d.get("errMsg"))
        return d

    def get_members_info(self, gid, uids):
        resp = self.session.post(
            IM_API_BASE + "/group/get_group_members_info",
            json={"groupID": gid, "userIDs": list(uids)},
            headers=self._im_headers(),
            timeout=30,
        )
        d = self._raw_json(resp)
        if d.get("errCode") != 0:
            raise Exception("获取成员信息失败: %s" % d.get("errMsg"))
        return (d.get("data") or {}).get("members") or []

    def get_users_info(self, uids):
        """批量查询用户资料（OpenIM 标准 /user/get_users_info）。

        用于识别封禁/注销账号：返回的用户对象可能含 accountStatus / status 等
        账号状态字段（字段名随服务端版本而异，调用方需兼容解析）。
        """
        resp = self.session.post(
            IM_API_BASE + "/user/get_users_info",
            json={"userIDs": list(uids)},
            headers=self._im_headers(),
            timeout=30,
        )
        d = self._raw_json(resp)
        if d.get("errCode") != 0:
            raise Exception("获取用户信息失败: %s" % d.get("errMsg"))
        return (d.get("data") or {}).get("users") or (d.get("data") or {}).get("userInfos") or []

    def invite_user(self, gid, uids, reason=""):
        resp = self.session.post(
            IM_API_BASE + "/group/invite_user_to_group",
            json={"groupID": gid, "invitedUserIDs": list(uids), "reason": reason},
            headers=self._im_headers(),
            timeout=30,
        )
        d = self._raw_json(resp)
        if d.get("errCode") != 0:
            raise Exception("邀请失败: %s" % d.get("errMsg"))
        return d

    def recall_msg(self, gid, msg_id):
        conv_id = "sg_" + gid
        resp = self.session.post(
            IM_API_BASE + "/msg/revoke_msg",
            json={"conversationID": conv_id, "messageID": msg_id, "userID": self.user_id},
            headers=self._im_headers(),
            timeout=30,
        )
        d = self._raw_json(resp)
        if d.get("errCode") != 0:
            raise Exception("撤回失败: %s" % d.get("errMsg"))
        return d

    # ---------- 黑名单 ----------

    def add_blacklist(self, gid, uid):
        resp = self.session.post(
            IM_API_BASE + "/group/blacklist/add",
            json={"groupID": gid, "userID": uid},
            headers=self._im_headers(),
            timeout=30,
        )
        d = self._raw_json(resp)
        if d.get("errCode") != 0:
            raise Exception("拉黑失败: %s" % d.get("errMsg"))
        return d

    def remove_blacklist(self, gid, uid):
        resp = self.session.post(
            IM_API_BASE + "/group/blacklist/remove",
            json={"groupID": gid, "userID": uid},
            headers=self._im_headers(),
            timeout=30,
        )
        d = self._raw_json(resp)
        if d.get("errCode") != 0:
            raise Exception("移出黑名单失败: %s" % d.get("errMsg"))
        return d

    def get_blacklist(self, gid):
        resp = self.session.post(
            IM_API_BASE + "/group/blacklist/get",
            json={"groupID": gid},
            headers=self._im_headers(),
            timeout=30,
        )
        d = self._raw_json(resp)
        if d.get("errCode") != 0:
            raise Exception("获取黑名单失败: %s" % d.get("errMsg"))
        data = d.get("data") or {}
        return data.get("blackList") or []

    # ---------- 消息发送 ----------

    def send_msg(self, gid, text):
        body = {
            "sendID": self.user_id,
            "recvID": gid,
            "groupID": gid,
            "senderPlatformID": PLATFORM_ID,
            "content": {"content": text, "contentType": 101},
            "contentType": 101,
            "sessionType": 3,
            "msgFrom": 100,
        }
        resp = self.session.post(
            IM_API_BASE + "/msg/send_msg",
            json=body,
            headers=self._auth_headers(True),
            timeout=30,
        )
        d = self._raw_json(resp)
        if d.get("errCode") != 0:
            raise Exception("发送失败: %s" % d.get("errMsg"))
        return d

    # ---------- 入群申请 ----------

    def get_applies(self, gid):
        resp = self.session.post(
            IM_API_BASE + "/group/group_application_f",
            json={"groupID": gid},
            headers=self._im_headers(),
            timeout=30,
        )
        d = self._raw_json(resp)
        if d.get("errCode") != 0:
            raise Exception("获取申请失败: %s" % d.get("errMsg"))
        return d.get("data") or []

    def handle_apply(self, gid, from_uid, result, msg):
        resp = self.session.post(
            IM_API_BASE + "/group/group_application_response",
            json={"groupID": gid, "fromUserID": from_uid, "handledMsg": msg, "handleResult": result},
            headers=self._im_headers(),
            timeout=30,
        )
        d = self._raw_json(resp)
        if d.get("errCode") != 0:
            raise Exception("处理申请失败: %s" % d.get("errMsg"))
        return d

    # ---------- 群主操作 ----------

    def transfer(self, gid, new_owner):
        resp = self.session.post(
            IM_API_BASE + "/group/transfer_group_owner",
            json={"groupID": gid, "newOwnerUserID": new_owner},
            headers=self._im_headers(),
            timeout=30,
        )
        d = self._raw_json(resp)
        if d.get("errCode") != 0:
            raise Exception("转让失败: %s" % d.get("errMsg"))
        return d

    def dismiss(self, gid):
        resp = self.session.post(
            IM_API_BASE + "/group/dismiss_group",
            json={"groupID": gid},
            headers=self._im_headers(),
            timeout=30,
        )
        d = self._raw_json(resp)
        if d.get("errCode") != 0:
            raise Exception("解散失败: %s" % d.get("errMsg"))
        return d

    # ---------- 加群/退群 ----------

    def leave_group(self, gid):
        """机器人退出群聊。"""
        resp = self.session.post(
            IM_API_BASE + "/group/quit_group",
            json={"groupID": gid, "userID": self.user_id},
            headers=self._im_headers(),
            timeout=30,
        )
        d = self._raw_json(resp)
        if d.get("errCode") != 0:
            raise Exception("退群失败: %s (errCode=%s)" % (d.get("errMsg"), d.get("errCode")))
        return d

    def join_group(self, gid, req_message=""):
        """机器人申请加入群聊。

        抓包确认的 API 格式（2026-08-19）：
        POST /group/join_group
        Body: {"groupID":"38066455","reqMessage":"","joinSource":3,"inviterUserID":"2640787","ex":""}
        - joinSource: 3=通过群号搜索
        - inviterUserID: 自己的 user_id
        """
        body = {
            "groupID": str(gid),
            "reqMessage": req_message or "",
            "joinSource": 3,
            "inviterUserID": str(self.user_id),
            "ex": "",
        }
        try:
            resp = self.session.post(
                IM_API_BASE + "/group/join_group",
                json=body,
                headers=self._im_headers(),
                timeout=30,
            )
            d = self._raw_json(resp)
        except Exception as e:
            raise Exception("进群失败(网络): %s" % e)
        if d.get("errCode") != 0:
            raise Exception("进群失败: %s (errCode=%s)" % (d.get("errMsg") or d.get("errDlt"), d.get("errCode")))
        return d

    def get_group_info(self, gid):
        """通过群号/群ID查询群信息，返回群信息 dict（含真实 groupID 与群名）；失败返回 None。

        抓包确认的 API 格式（2026-08-19）：
        POST /group/get_groups_info
        Body: {"groupIDs": ["388888"]}
        响应: data.groupInfos[0]，其中 groupID 为真实群ID，numbers[].number 为群号
        """
        try:
            resp = self.session.post(
                IM_API_BASE + "/group/get_groups_info",
                json={"groupIDs": [str(gid)]},
                headers=self._im_headers(),
                timeout=30,
            )
            d = self._raw_json(resp)
            if d.get("errCode") != 0:
                return None
            data = d.get("data") or {}
            groups = data.get("groupInfos") or []
            if groups:
                return groups[0]
            return None
        except Exception:
            return None

    def get_group_members_safe(self, gid):
        """获取群成员列表（容错版，返回 list 不抛异常）。"""
        try:
            return self.get_group_members(gid)
        except Exception as e:
            self.last_login_diag = None
            return []
