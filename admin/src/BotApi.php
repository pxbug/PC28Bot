<?php
namespace App;

/**
 * Bot API 签名验证
 *
 * 签名规则（与 src/bot_api_client.py 一致）：
 *   X-Sign = md5(app_id + timestamp + secret_key)
 *
 * 请求头：
 *   X-App-Id
 *   X-Timestamp
 *   X-Sign
 */
class BotApi
{
    public static function verifyRequest(array $headers): bool
    {
        $config = require __DIR__ . '/../config.php';
        $cfg = $config['bot_api'];

        $appId = $headers['x-app-id']    ?? '';
        $ts    = $headers['x-timestamp'] ?? '';
        $sign  = $headers['x-sign']      ?? '';

        if (!$appId || !$ts || !$sign) {
            return false;
        }
        if ($appId !== $cfg['app_id']) {
            return false;
        }

        // 时间戳偏差 ±300 秒
        if (abs(time() - (int)$ts) > 300) {
            return false;
        }

        $expected = md5($cfg['app_id'] . $ts . $cfg['secret_key']);
        return hash_equals($expected, strtolower($sign));
    }

    public static function getRequestHeaders(): array
    {
        // 兼容大小写
        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($k, 5)));
                $headers[$name] = $v;
            }
        }
        return $headers;
    }
}