<?php
namespace App\Controllers\Bot;

/**
 * Bot API 默认入口（未匹配路由时）
 */
class IndexController
{
    public function index(array $body): void
    {
        \App\Response::fail('未知接口', 404);
    }
}