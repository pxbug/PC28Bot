<?php
/**
 * PC28 后台管理 - 配置文件
 *
 * 部署时修改以下配置：
 * 1. db.* - 数据库连接
 * 2. admin.* - 管理员账号（首次部署后请立即修改）
 * 3. bot_api.* - Bot 对接信息
 */

return [

    // ===== 数据库 =====
    'db' => [
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'database' => 'pc28bot',
        'username' => 'pc28bot',
        'password' => 'pc28bot',
        'charset'  => 'utf8mb4',
    ],

    // ===== 管理员 =====
    // 首次部署使用 admin / admin123 登录后请修改
    'admin' => [
        'username' => 'admin',
        'password' => 'admin123',
    ],

    // ===== Bot API 对接 =====
    // Bot 通过这些凭据调用 /api/bot/* 接口
    'bot_api' => [
        'app_id'     => 'pc28bot',
        'secret_key' => 'change-me-to-a-random-string',
        // 白名单 IP（为空则不限制）
        'allow_ips'  => [],
    ],

    // ===== 系统 =====
    'app' => [
        'name'     => 'PC28 后台管理',
        'timezone' => 'Asia/Shanghai',
        'debug'    => false,
        'session_name' => 'PC28_ADMIN_SESS',
    ],
];