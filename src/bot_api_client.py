"""
PC28 机器人 - FastAdmin API 客户端

用于机器人与后端管理系统通信，支持：
- 用户注册/同步
- 用户信息查询
- 余额查询
- 下注上报
- 开奖结算回调

签名规则：md5(app_id + timestamp + secret_key)
"""

import hashlib
import json
import time
import logging
from typing import Optional, List, Dict, Any

try:
    import requests
except ImportError:
    requests = None


logger = logging.getLogger(__name__)


class BotApiError(Exception):
    """API 调用错误"""
    def __init__(self, code: int, msg: str, data=None):
        self.code = code
        self.msg = msg
        self.data = data
        super().__init__(f"[{code}] {msg}")


class BotApiClient:
    """FastAdmin API 客户端"""

    def __init__(self, base_url: str, app_id: str, secret_key: str, timeout: int = 10):
        """
        初始化 API 客户端

        Args:
            base_url: API 基础地址，如 http://127.0.0.1:8080/api/bot/
            app_id: 应用ID
            secret_key: API 密钥
            timeout: 请求超时时间（秒）
        """
        self.base_url = base_url.rstrip('/')
        self.app_id = app_id
        self.secret_key = secret_key
        self.timeout = timeout

    def _make_sign(self, timestamp: int) -> str:
        """生成签名: md5(app_id + timestamp + secret_key)"""
        raw = f"{self.app_id}{timestamp}{self.secret_key}"
        return hashlib.md5(raw.encode('utf-8')).hexdigest()

    def _headers(self, timestamp: int) -> Dict[str, str]:
        """生成请求头"""
        return {
            'X-App-Id': self.app_id,
            'X-Timestamp': str(timestamp),
            'X-Sign': self._make_sign(timestamp),
            'Content-Type': 'application/json',
        }

    def _post(self, action: str, data: Dict[str, Any]) -> Dict[str, Any]:
        """POST 请求封装"""
        if requests is None:
            raise BotApiError(9999, "requests 库未安装")

        url = f"{self.base_url}/{action}"
        timestamp = int(time.time())
        headers = self._headers(timestamp)

        try:
            resp = requests.post(
                url,
                json=data,
                headers=headers,
                timeout=self.timeout
            )
            resp.raise_for_status()
            result = resp.json()

            if result.get('code') != 0:
                raise BotApiError(
                    result.get('code', -1),
                    result.get('msg', '未知错误'),
                    result.get('data')
                )

            return result

        except requests.exceptions.Timeout:
            raise BotApiError(9998, f"请求超时（{self.timeout}秒）")
        except requests.exceptions.ConnectionError:
            raise BotApiError(9997, "无法连接到服务器")
        except requests.exceptions.RequestException as e:
            raise BotApiError(9996, f"请求失败: {e}")

    # ==================== 用户相关 ====================

    def register(self, uid: str, nickname: str = "") -> Dict[str, Any]:
        """
        注册/同步用户

        Args:
            uid: 群内用户ID
            nickname: 用户昵称

        Returns:
            用户信息 {"id", "uid", "nickname", "balance", "status"}
        """
        return self._post('register', {
            'uid': uid,
            'nickname': nickname,
        })

    def get_user_info(self, uid: str) -> Dict[str, Any]:
        """
        查询用户信息

        Args:
            uid: 群内用户ID

        Returns:
            用户信息
        """
        return self._post('user_info', {
            'uid': uid,
        })

    def get_balance(self, uid: str) -> float:
        """
        查询用户余额

        Args:
            uid: 群内用户ID

        Returns:
            余额
        """
        result = self._post('balance', {'uid': uid})
        return float(result.get('data', {}).get('balance', 0))

    # ==================== 下注相关 ====================

    def bet(self, uid: str, issue: str, bets: List[Dict[str, Any]]) -> Dict[str, Any]:
        """
        用户下注

        Args:
            uid: 群内用户ID
            issue: 期号
            bets: 下注列表，如 [
                {"type": "dx", "content": "大", "amount": 100, "odds": 2.0},
                {"type": "dd", "content": "单", "amount": 50, "odds": 2.0}
            ]

        Returns:
            下注结果 {"total_amount", "balance", "bet_count"}
        """
        return self._post('bet', {
            'uid': uid,
            'issue': issue,
            'bets': json.dumps(bets, ensure_ascii=False),
        })

    def get_bet_list(self, issue: str) -> Dict[str, Any]:
        """
        获取期号下注列表

        Args:
            issue: 期号

        Returns:
            下注列表 {"issue", "total_amount", "count", "list"}
        """
        return self._post('bet_list', {'issue': issue})

    # ==================== 开奖结算 ====================

    def settle(self, issue: str, number: str, sum: int) -> Dict[str, Any]:
        """
        开奖结算

        Args:
            issue: 期号
            number: 开奖号码，如 "8+9+2=19"
            sum: 和值（0-27）

        Returns:
            结算结果 {"issue", "number", "sum", "settled_count", "settle_amount", "results"}
        """
        return self._post('settle', {
            'issue': issue,
            'number': number,
            'sum': sum,
        })

    # ==================== 工具方法 ====================

    def test_connection(self) -> bool:
        """测试连接（使用 register 接口）"""
        try:
            self.register(uid="__test__", nickname="连接测试")
            return True
        except BotApiError:
            return False


# ==================== 快捷调用 ====================

_global_client: Optional[BotApiClient] = None


def init_client(base_url: str, app_id: str, secret_key: str, timeout: int = 10):
    """初始化全局客户端"""
    global _global_client
    _global_client = BotApiClient(base_url, app_id, secret_key, timeout)
    logger.info(f"Bot API 客户端已初始化: {base_url}")


def get_client() -> Optional[BotApiClient]:
    """获取全局客户端"""
    return _global_client


def register(uid: str, nickname: str = "") -> Dict[str, Any]:
    """快捷注册"""
    if _global_client:
        return _global_client.register(uid, nickname)
    raise BotApiError(9999, "API 客户端未初始化")


def get_user_info(uid: str) -> Dict[str, Any]:
    """快捷获取用户信息"""
    if _global_client:
        return _global_client.get_user_info(uid)
    raise BotApiError(9999, "API 客户端未初始化")


def get_balance(uid: str) -> float:
    """快捷获取余额"""
    if _global_client:
        return _global_client.get_balance(uid)
    raise BotApiError(9999, "API 客户端未初始化")


def bet(uid: str, issue: str, bets: List[Dict[str, Any]]) -> Dict[str, Any]:
    """快捷下注"""
    if _global_client:
        return _global_client.bet(uid, issue, bets)
    raise BotApiError(9999, "API 客户端未初始化")


def settle(issue: str, number: str, sum: int) -> Dict[str, Any]:
    """快捷结算"""
    if _global_client:
        return _global_client.settle(issue, number, sum)
    raise BotApiError(9999, "API 客户端未初始化")


def get_bet_list(issue: str) -> Dict[str, Any]:
    """快捷获取下注列表"""
    if _global_client:
        return _global_client.get_bet_list(issue)
    raise BotApiError(9999, "API 客户端未初始化")
