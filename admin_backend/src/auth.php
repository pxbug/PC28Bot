<?php
/**
 * Bot API 签名验证
 *
 * 验证规则：
 * - Header X-App-Id: 应用ID
 * - Header X-Timestamp: 时间戳（秒）
 * - Header X-Sign: md5(app_id + timestamp + secret_key)
 *
 * 签名有效期：5分钟
 */

require_once __DIR__ . '/db.php';

function verifySign() {
    $appId = $_SERVER['HTTP_X_APP_ID'] ?? '';
    $timestamp = $_SERVER['HTTP_X_TIMESTAMP'] ?? '';
    $sign = $_SERVER['HTTP_X_SIGN'] ?? '';

    if (!$appId || !$timestamp || !$sign) {
        jsonResponse(401, '缺少签名参数: X-App-Id, X-Timestamp, X-Sign');
    }

    if (!is_numeric($timestamp)) {
        jsonResponse(401, '无效的时间戳格式');
    }

    $timestamp = intval($timestamp);
    if (abs(time() - $timestamp) > 300) {
        jsonResponse(401, '签名已过期，请重新请求');
    }

    $row = db()->prepare("SELECT * FROM " . table('bot_config') . " WHERE app_id = ? AND status = 1 LIMIT 1");
    $row->execute([$appId]);
    $config = $row->fetch();

    if (!$config) {
        jsonResponse(401, '无效的 AppId 或已被禁用');
    }

    $expectedSign = md5($appId . $timestamp . $config['secret_key']);
    if (strcasecmp($sign, $expectedSign) !== 0) {
        jsonResponse(401, '签名验证失败');
    }

    return $config;
}

function makeSign($appId, $secretKey, $timestamp = null) {
    $timestamp = $timestamp ?: time();
    return [
        'app_id' => $appId,
        'timestamp' => $timestamp,
        'sign' => md5($appId . $timestamp . $secretKey),
    ];
}
