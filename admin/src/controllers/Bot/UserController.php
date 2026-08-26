<?php
namespace App\Controllers\Bot;

use App\Db;
use App\Response;

/**
 * 用户查询（Bot 端调用）
 */
class UserController
{
    public function userInfo(array $body): void
    {
        $uid = trim((string)($body['uid'] ?? ''));
        if ($uid === '') {
            Response::fail('uid 不能为空', 1001);
        }

        $row = Db::fetch('SELECT * FROM bot_users WHERE uid = ?', [$uid]);
        if (!$row) {
            Response::fail('用户不存在', 1002);
        }

        Response::ok([
            'id'            => (int)$row['id'],
            'uid'           => $row['uid'],
            'nickname'      => $row['nickname'],
            'balance'       => (float)$row['balance'],
            'status'        => (int)$row['status'],
            'total_recharge'=> (float)$row['total_recharge'],
            'total_bet'     => (float)$row['total_bet'],
            'total_rebate'  => (float)$row['total_rebate'],
        ]);
    }

    public function balance(array $body): void
    {
        $uid = trim((string)($body['uid'] ?? ''));
        if ($uid === '') {
            Response::fail('uid 不能为空', 1001);
        }

        $row = Db::fetch('SELECT balance, status FROM bot_users WHERE uid = ?', [$uid]);
        if (!$row) {
            Response::fail('用户不存在', 1002);
        }
        if ((int)$row['status'] === 0) {
            Response::fail('用户已被封禁', 1003);
        }

        Response::ok(['balance' => (float)$row['balance']]);
    }
}