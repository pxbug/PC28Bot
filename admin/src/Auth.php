<?php
namespace App;

/**
 * 鉴权（单管理员 + Session）
 */
class Auth
{
    public static function start(): void
    {
        $config = require __DIR__ . '/../config.php';
        if (session_status() === PHP_SESSION_NONE) {
            session_name($config['app']['session_name']);
            session_start();
        }
    }

    public static function login(string $username, string $password): bool
    {
        self::start();
        $config = require __DIR__ . '/../config.php';
        $admin = $config['admin'];

        // 首次部署：用 config 中的明文密码校验并初始化 admin_user 表
        $row = Db::fetch('SELECT * FROM admin_user WHERE username = ?', [$username]);
        if (!$row) {
            if ($username === $admin['username'] && $password === $admin['password']) {
                $hash = password_hash($password, PASSWORD_BCRYPT);
                Db::insert('admin_user', [
                    'username'      => $username,
                    'password_hash' => $hash,
                ]);
                $_SESSION['admin'] = ['id' => 0, 'username' => $username];
                return true;
            }
            return false;
        }

        if (password_verify($password, $row['password_hash'])) {
            $_SESSION['admin'] = ['id' => (int)$row['id'], 'username' => $row['username']];
            return true;
        }

        // 兼容旧配置中的明文密码
        if ($username === $admin['username'] && $password === $admin['password']) {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            Db::update('admin_user', ['password_hash' => $hash], 'id = :id', ['id' => $row['id']]);
            $_SESSION['admin'] = ['id' => (int)$row['id'], 'username' => $row['username']];
            return true;
        }

        return false;
    }

    public static function logout(): void
    {
        self::start();
        $_SESSION = [];
        session_destroy();
    }

    public static function check(): bool
    {
        self::start();
        return !empty($_SESSION['admin']);
    }

    public static function user(): ?array
    {
        self::start();
        return $_SESSION['admin'] ?? null;
    }

    public static function require(): void
    {
        if (!self::check()) {
            $isApi = str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/api/');
            if ($isApi) {
                Response::json(['code' => 401, 'msg' => '未登录']);
            } else {
                header('Location: /login.php');
            }
            exit;
        }
    }
}