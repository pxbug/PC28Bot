<?php
/**
 * Session-based admin authentication
 */
class Auth {
    const SESSION_KEY = 'admin_id';
    private static ?array $currentAdmin = null;

    public static function start(): void {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    public static function check(): bool {
        self::start();
        return isset($_SESSION[self::SESSION_KEY]);
    }

    public static function id(): ?int {
        self::start();
        return $_SESSION[self::SESSION_KEY] ?? null;
    }

    public static function admin(): ?array {
        if (self::$currentAdmin !== null) return self::$currentAdmin;
        $id = self::id();
        if (!$id) return null;
        self::$currentAdmin = DB::fetch("SELECT id, username, nickname, role FROM admins WHERE id = ? AND status = 'active'", [$id]);
        return self::$currentAdmin;
    }

    public static function role(): string {
        return self::admin()['role'] ?? 'guest';
    }

    public static function isSuperAdmin(): bool {
        return self::role() === 'super';
    }

    public static function login(string $username, string $password): bool {
        $row = DB::fetch("SELECT id, password FROM admins WHERE username = ? AND status = 'active'", [$username]);
        if (!$row) return false;
        if (!password_verify($password, $row['password'])) return false;

        self::start();
        $_SESSION[self::SESSION_KEY] = $row['id'];
        DB::exec("UPDATE admins SET last_login = ? WHERE id = ?", [time(), $row['id']]);
        self::$currentAdmin = null;
        return true;
    }

    public static function logout(): void {
        self::start();
        unset($_SESSION[self::SESSION_KEY]);
        self::$currentAdmin = null;
    }

    public static function require(): void {
        if (!self::check()) {
            header('Location: /login');
            exit;
        }
    }

    public static function hashPassword(string $plain): string {
        return password_hash($plain, PASSWORD_DEFAULT);
    }

    public static function seedAdmin(string $username, string $password, string $nickname = 'SuperAdmin', string $role = 'super'): void {
        $existing = DB::fetch("SELECT id FROM admins WHERE username = ?", [$username]);
        if ($existing) return;
        DB::insert('admins', [
            'username' => $username,
            'password' => self::hashPassword($password),
            'nickname' => $nickname,
            'role' => $role,
            'status' => 'active',
        ]);
    }
}
