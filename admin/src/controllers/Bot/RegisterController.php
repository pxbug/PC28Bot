<?php
namespace App\Controllers\Bot;

use App\Db;
use App\Response;

/**
 * 用户注册（Bot 端调用）
 */
class RegisterController
{
    public function register(array $body): void
    {
        $uid = trim((string)($body['uid'] ?? ''));
        $nickname = trim((string)($body['nickname'] ?? ''));

        if ($uid === '') {
            Response::fail('uid 不能为空', 1001);
        }

        // 系统连接测试请求
        if ($uid === '__test__') {
            Response::ok(['ping' => 'pong']);
        }

        $row = Db::fetch('SELECT * FROM bot_users WHERE uid = ?', [$uid]);
        if ($row) {
            if ($nickname !== '' && $nickname !== $row['nickname']) {
                Db::update('bot_users', ['nickname' => $nickname], 'id = :id', ['id' => $row['id']]);
                $row['nickname'] = $nickname;
            }
        } else {
            $id = Db::insert('bot_users', [
                'uid'      => $uid,
                'nickname' => $nickname ?: $uid,
                'balance'  => 0,
                'status'   => 1,
            ]);
            $row = Db::fetch('SELECT * FROM bot_users WHERE id = ?', [$id]);
        }

        Response::ok([
            'id'       => (int)$row['id'],
            'uid'      => $row['uid'],
            'nickname' => $row['nickname'],
            'balance'  => (float)$row['balance'],
            'status'   => (int)$row['status'],
        ]);
    }
}