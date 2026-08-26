<?php
namespace App;

/**
 * 业务工具
 */
class Helper
{
    /** 金额显示：1234567.89 → 1,234,567.89 */
    public static function money(float|int|string $v): string
    {
        return number_format((float)$v, 2, '.', ',');
    }

    /** 时间显示：Y-m-d H:i */
    public static function dt(?string $v): string
    {
        if (!$v) return '-';
        return date('Y-m-d H:i', strtotime($v));
    }

    /** 安全 HTML 转义 */
    public static function h(?string $v): string
    {
        return htmlspecialchars((string)$v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** 读取 JSON body */
    public static function jsonBody(): array
    {
        $raw = file_get_contents('php://input');
        if (!$raw) return [];
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    /** 读取 GET/POST 参数（默认 GET） */
    public static function input(string $key, $default = null, string $method = 'GET')
    {
        $source = strtoupper($method) === 'POST' ? $_POST : $_GET;
        return $source[$key] ?? $default;
    }

    public static function flash(string $key, ?string $value = null): ?string
    {
        Auth::start();
        if ($value !== null) {
            $_SESSION['_flash'][$key] = $value;
            return null;
        }
        $v = $_SESSION['_flash'][$key] ?? null;
        unset($_SESSION['_flash'][$key]);
        return $v;
    }
}