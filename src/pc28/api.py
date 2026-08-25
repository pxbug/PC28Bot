"""PC28 yu28.top API 客户端。

端点：GET https://yu28.top/api/kj.json?nbr=N
鉴权 header：X-Api-Key: <KEY>
（旧版也支持 ?key=<KEY> query，但新版要求 header 优先，query 会被 401 拒绝）
响应示例：
    {
      "countdown": "02:40",
      "data": [
        {"nbr":"3463701","time":"2026-07-31 11:25:00",
         "number":"4+8+2=14","combination":"小双", ...}
      ]
    }
"""
import json
import time
from urllib.parse import urlencode

import requests
from requests.adapters import HTTPAdapter
from urllib3.util.retry import Retry


DEFAULT_BASE = "https://yu28.top"
DEFAULT_TIMEOUT = 10
DEFAULT_RETRIES = 3


def _make_session():
    sess = requests.Session()
    retry = Retry(
        total=DEFAULT_RETRIES,
        connect=DEFAULT_RETRIES,
        read=DEFAULT_RETRIES,
        backoff_factor=0.3,
        status_forcelist=[500, 502, 503, 504],
        raise_on_status=False,
    )
    adapter = HTTPAdapter(max_retries=retry)
    sess.mount("https://", adapter)
    sess.mount("http://", adapter)
    return sess


def parse_countdown(s):
    """把 "02:40" / "2:40" / "00:05" / "" 解析为秒数；解析失败返回 None。

    - 倒数时（下一期开始前）的剩余秒数。
    - 空串表示未知（API 在结算前后短时返回空）。
    """
    if not s:
        return None
    s = str(s).strip()
    if ":" not in s:
        return None
    try:
        mm, ss = s.split(":", 1)
        return int(mm) * 60 + int(ss)
    except Exception:
        return None


def parse_number(raw):
    """把 "4+8+2=14" 解析为 ([n1, n2, n3], sum_val)；失败返回 ([], None)。"""
    if not raw:
        return [], None
    try:
        left, right = raw.split("=", 1)
        nums = [int(x.strip()) for x in left.split("+") if x.strip() != ""]
        sval = int(right.strip())
        return nums, sval
    except Exception:
        return [], None


class Yu28Client:
    """薄封装：get_latest(n=1) / get_history(n) / get_countdown()。

    不维护长连接，每次独立请求；保持简单与重试。
    """

    def __init__(self, base_url=None, api_key=None, timeout=None, session=None):
        self.base_url = (base_url or DEFAULT_BASE).rstrip("/")
        self.api_key = api_key or ""
        self.timeout = timeout or DEFAULT_TIMEOUT
        self.session = session or _make_session()

    def _url(self, n):
        # 2026-08 yu28.top 已禁止 URL query 传 key，必须用 X-Api-Key header。
        # 保留 query 是兜底，部分代理/CDN 可能 strip header。
        params = {"nbr": int(n)}
        return "%s/api/kj.json?%s" % (self.base_url, urlencode(params))

    def _headers(self):
        h = {"Accept": "application/json"}
        if self.api_key:
            h["X-Api-Key"] = self.api_key
            h["Authorization"] = "Bearer %s" % self.api_key
        return h

    def _get(self, n):
        try:
            resp = self.session.get(self._url(n), headers=self._headers(), timeout=self.timeout)
            resp.raise_for_status()
        except Exception as e:
            raise RuntimeError("yu28 request failed: %s" % e)
        try:
            return resp.json()
        except Exception as e:
            raise RuntimeError("yu28 invalid JSON: %s (body=%r)" % (e, resp.text[:200]))

    def get_latest(self, n=1):
        """拉最近 N 条开奖。返回 dict（含 countdown + data）。"""
        return self._get(max(1, int(n)))

    def get_history(self, n=20):
        """拉历史 N 条开奖。返回 dict。"""
        return self._get(max(1, min(100, int(n))))

    def get_countdown(self):
        """拉最近一期并返回倒计时秒数；未知返回 None。"""
        try:
            d = self._get(1)
        except Exception:
            return None
        cd = d.get("countdown", "")
        return parse_countdown(cd)


class Issue:
    """单期开奖结果。"""

    __slots__ = ("nbr", "time", "n1", "n2", "n3", "sum_val", "combination", "raw_number")

    def __init__(self, nbr, time, n1, n2, n3, sum_val, combination, raw_number):
        self.nbr = nbr
        self.time = time
        self.n1 = n1
        self.n2 = n2
        self.n3 = n3
        self.sum_val = sum_val
        self.combination = combination
        self.raw_number = raw_number

    @classmethod
    def from_api(cls, item):
        nbr = str(item.get("nbr", "")).strip()
        time_str = str(item.get("time", "")).strip()
        raw = str(item.get("number", "")).strip()
        combination = str(item.get("combination", "")).strip()
        nums, sval = parse_number(raw)
        if not nbr or len(nums) < 3 or sval is None:
            return None
        return cls(
            nbr=nbr,
            time=time_str,
            n1=int(nums[0]),
            n2=int(nums[1]),
            n3=int(nums[2]),
            sum_val=int(sval),
            combination=combination,
            raw_number=raw,
        )

    def to_dict(self):
        return {
            "nbr": self.nbr,
            "time": self.time,
            "n1": self.n1,
            "n2": self.n2,
            "n3": self.n3,
            "sum_val": self.sum_val,
            "combination": self.combination,
            "raw_number": self.raw_number,
        }

    @classmethod
    def from_dict(cls, d):
        return cls(
            nbr=d.get("nbr", ""),
            time=d.get("time", ""),
            n1=int(d.get("n1", 0)),
            n2=int(d.get("n2", 0)),
            n3=int(d.get("n3", 0)),
            sum_val=int(d.get("sum_val", 0)),
            combination=d.get("combination", ""),
            raw_number=d.get("raw_number", ""),
        )

    def __repr__(self):
        return "Issue(%s %s %s=%s)" % (self.nbr, self.time, self.raw_number, self.sum_val)


def issues_from_response(resp):
    """把 API 响应 dict 转成 [Issue, ...]（最新在前）。"""
    out = []
    if not isinstance(resp, dict):
        return out
    for it in resp.get("data", []) or []:
        if not isinstance(it, dict):
            continue
        iss = Issue.from_api(it)
        if iss is not None:
            out.append(iss)
    return out


if __name__ == "__main__":
    # 简单自检：仅本地调试用
    cli = Yu28Client(api_key="yu28_c4aaa4ccc91a5bf8")
    d = cli.get_latest(1)
    print(json.dumps(d, ensure_ascii=False, indent=2)[:400])
    print("countdown sec:", cli.get_countdown())
