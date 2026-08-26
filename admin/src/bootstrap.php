<?php
/**
 * 全局启动文件
 *
 * 被所有入口（页面、API）引入：
 *   - 加载配置
 *   - 初始化数据库连接
 *   - 自动加载 App\* 类
 *   - 设置时区
 */

$config = require __DIR__ . '/../config.php';

date_default_timezone_set($config['app']['timezone']);
if (!empty($config['app']['debug'])) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
    ini_set('display_errors', '0');
}

spl_autoload_register(function (string $class) {
    if (str_starts_with($class, 'App\\')) {
        $path = __DIR__ . '/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($path)) {
            require $path;
        }
    }
});

\App\Db::init($config['db']);