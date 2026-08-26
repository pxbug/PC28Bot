<?php
/**
 * Bot API 入口
 *
 * 路由：/api/bot/{action}
 * 例如：/api/bot/register、/api/bot/bet、/api/bot/settle
 *
 * 与 src/bot_api_client.py 的协议一致。
 */
require __DIR__ . '/../../src/bootstrap.php';

use App\Auth;
use App\BotApi;
use App\Db;
use App\Response;

// Bot API 不需要管理员登录，需要签名验证
$headers = BotApi::getRequestHeaders();
if (!BotApi::verifyRequest($headers)) {
    Response::json(['code' => 401, 'msg' => '签名校验失败'], 401);
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = preg_replace('#^/api/bot/?#', '', $path);
$action = trim((string)$path, '/');

$body = App\Helper::jsonBody();
$actionMap = [
    'register'   => \App\Controllers\Bot\RegisterController::class,
    'user_info'  => \App\Controllers\Bot\UserController::class,
    'balance'    => \App\Controllers\Bot\UserController::class,
    'bet'        => \App\Controllers\Bot\BetController::class,
    'bet_list'   => \App\Controllers\Bot\BetController::class,
    'settle'     => \App\Controllers\Bot\SettleController::class,
    'rebate'     => \App\Controllers\Bot\RebateController::class,
];

if (!isset($actionMap[$action])) {
    Response::fail('未知接口：' . $action, 404, null, 404);
}

$controllerClass = $actionMap[$action];
$method = $action === 'register' ? 'register'
        : ($action === 'user_info' ? 'userInfo'
        : ($action === 'balance' ? 'balance'
        : ($action === 'bet' ? 'place'
        : ($action === 'bet_list' ? 'list'
        : ($action === 'rebate' ? 'run'
        : 'settle')))));

$ctrl = new $controllerClass();
$ctrl->$method($body);