<?php
namespace App;

/**
 * 统一响应
 */
class Response
{
    public static function json($data, int $code = 200): void
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function ok($data = null, string $msg = 'ok'): void
    {
        self::json(['code' => 0, 'msg' => $msg, 'data' => $data]);
    }

    public static function fail(string $msg, int $code = 1, $data = null, int $http = 200): void
    {
        self::json(['code' => $code, 'msg' => $msg, 'data' => $data], $http);
    }

    public static function html(string $content): void
    {
        header('Content-Type: text/html; charset=utf-8');
        echo $content;
        exit;
    }

    public static function redirect(string $url): void
    {
        header('Location: ' . $url);
        exit;
    }
}