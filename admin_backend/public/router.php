<?php
/**
 * PHP 内置服务器路由
 *
 * /api/bot/* -> Bot API（供机器人调用）
 * 其他 -> 后台管理界面
 */

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Bot API 路由：所有 /api/bot/* 都走 bot_api.php
if (preg_match('#^/api/bot#', $uri)) {
    // 将 /api/bot/action 映射到 ../src/bot_api.php
    // 但 router.php 在 public/ 目录，所以要 ../src/bot_api.php
    require __DIR__ . '/../src/bot_api.php';
    return true;
}

// 其他路由走默认的 index.php
return false;
