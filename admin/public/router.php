<?php
/**
 * 路由（用于不支持 Nginx rewrite 的环境）
 *
 * 如果 Nginx 配置了 try_files，这文件一般不需要。
 * 但如果 PHP 内置服务器或其他 Web 服务器，
 * 可以用这个做简单路由：
 *
 *   php -S localhost:8080 router.php
 *
 * 对于 Nginx，建议使用 nginx.conf.example 中的 try_files 规则。
 */
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

// 静态文件直接放行
if (preg_match('/\.(css|js|png|jpg|jpeg|gif|ico|svg|woff2?|ttf|eot|map)$/', $path)) {
    return false;
}

// Bot API → api/bot.php
if (str_starts_with($path, '/api/bot')) {
    $_SERVER['SCRIPT_NAME'] = '/api/bot.php';
    require __DIR__ . '/api/bot.php';
    return true;
}

// 其他 → index.php
$_SERVER['SCRIPT_NAME'] = '/index.php';
require __DIR__ . '/index.php';
return true;