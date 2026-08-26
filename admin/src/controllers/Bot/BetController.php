<?php
namespace App\Controllers\Bot;

use App\Db;
use App\Response;

/**
 * 下注（Bot 端调用）
 */
class BetController
{
    // 玩法 → 判定函数名
    private const JUDGERS = [
        'dx'   => 'isDaXiao',
        'dd'   => 'isDanShuang',
        'dxdd' => 'isZuHe',
        'jd'   => 'isJiDa',
        'jx'   => 'isJiXiao',
        'bz'   => 'isBaoZi',
        'sh'   => 'isShunZi',
        'dz'   => 'isDuiZi',
        'lh'   => 'isLongHu',
        'num'  => 'isTeMa',
    ];

    /**
     * 下注
     * body: { uid, issue, bets: json-string|[], ... }
     */
    public function place(array $body): void
    {
        $uid = trim((string)($body['uid'] ?? ''));
        $issue = trim((string)($body['issue'] ?? ''));
        $betsRaw = $body['bets'] ?? [];

        if ($uid === '' || $issue === '') {
            Response::fail('参数不完整', 1001);
        }

        if (is_string($betsRaw)) {
            $betsRaw = json_decode($betsRaw, true) ?? [];
        }
        if (!is_array($betsRaw) || empty($betsRaw)) {
            Response::fail('下注内容为空', 1001);
        }

        $user = Db::fetch('SELECT * FROM bot_users WHERE uid = ?', [$uid]);
        if (!$user) {
            Response::fail('用户不存在，请先注册', 1002);
        }
        if ((int)$user['status'] === 0) {
            Response::fail('用户已被封禁', 1003);
        }

        $totalAmount = 0;
        foreach ($betsRaw as $b) {
            $totalAmount += (float)($b['amount'] ?? 0);
        }

        if ($totalAmount <= 0) {
            Response::fail('下注金额无效', 1001);
        }

        if ((float)$user['balance'] < $totalAmount) {
            Response::fail('余额不足', 1004);
        }

        // 检查是否已封盘（本期已开奖则拒绝下注）
        $lottery = Db::fetch('SELECT id FROM bot_lottery WHERE issue = ? AND settled = 1', [$issue]);
        if ($lottery) {
            Response::fail('本期已封盘，无法下注', 1005);
        }

        // 写入下注记录 + 扣余额（事务）
        Db::begin();
        try {
            foreach ($betsRaw as $b) {
                Db::insert('bot_bets', [
                    'user_id'  => (int)$user['id'],
                    'uid'      => $uid,
                    'issue'    => $issue,
                    'bet_type' => trim((string)($b['type'] ?? '')),
                    'content'  => trim((string)($b['content'] ?? '')),
                    'amount'   => (float)($b['amount'] ?? 0),
                    'odds'     => (float)($b['odds'] ?? 1),
                    'payout'   => 0,
                    'status'   => 0,
                ]);
            }

            Db::execute(
                'UPDATE bot_users SET balance = balance - ?, total_bet = total_bet + ? WHERE id = ?',
                [$totalAmount, $totalAmount, (int)$user['id']]
            );

            Db::commit();

            $newBalance = (float)$user['balance'] - $totalAmount;
            Response::ok([
                'total_amount' => $totalAmount,
                'balance'      => $newBalance,
                'bet_count'     => count($betsRaw),
            ]);
        } catch (\Exception $e) {
            Db::rollback();
            Response::fail('下注失败：' . $e->getMessage(), 1999);
        }
    }

    /**
     * 按期号查询下注列表
     */
    public function list(array $body): void
    {
        $issue = trim((string)($body['issue'] ?? ''));
        if ($issue === '') {
            Response::fail('期号不能为空', 1001);
        }

        $rows = Db::fetchAll(
            'SELECT b.*, u.nickname FROM bot_bets b
             LEFT JOIN bot_users u ON b.user_id = u.id
             WHERE b.issue = ? ORDER BY b.id ASC',
            [$issue]
        );

        $totalAmount = array_sum(array_column($rows, 'amount'));
        Response::ok([
            'issue'        => $issue,
            'total_amount' => $totalAmount,
            'count'        => count($rows),
            'list'         => $rows,
        ]);
    }

    // ==================== 判定方法 ====================

    public static function isDaXiao(int $sum, string $content): bool
    {
        return ($content === '大' && $sum >= 14) || ($content === '小' && $sum <= 13);
    }

    public static function isDanShuang(int $sum, string $content): bool
    {
        return ($content === '单' && $sum % 2 !== 0) || ($content === '双' && $sum % 2 === 0);
    }

    public static function isZuHe(int $sum, string $content): bool
    {
        $isDa = $sum >= 14; $isDan = $sum % 2 !== 0;
        return match ($content) {
            '大单' => $isDa && $isDan,
            '小单' => !$isDa && $isDan,
            '大双' => $isDa && !$isDan,
            '小双' => !$isDa && !$isDan,
            default => false,
        };
    }

    public static function isJiDa(int $sum, string $content): bool
    {
        return $content === '极大' && $sum >= 22;
    }

    public static function isJiXiao(int $sum, string $content): bool
    {
        return $content === '极小' && $sum <= 5;
    }

    public static function isBaoZi(int $sum, string $content): bool
    {
        return $content === '豹子'; // 需要三个数字，后续扩展
    }

    public static function isShunZi(int $sum, string $content): bool
    {
        return $content === '顺子'; // 需要三个数字，后续扩展
    }

    public static function isDuiZi(int $sum, string $content): bool
    {
        return $content === '对子'; // 需要三个数字，后续扩展
    }

    public static function isLongHu(int $sum, string $content): bool
    {
        return in_array($content, ['龙', '虎', '豹']); // 需要三个数字，后续扩展
    }

    public static function isTeMa(int $sum, string $content): bool
    {
        return (int)$content === $sum;
    }

    /** 统一判定入口 */
    public static function judge(int $sum, string $betType, string $content): bool
    {
        return match ($betType) {
            'dx'   => self::isDaXiao($sum, $content),
            'dd'   => self::isDanShuang($sum, $content),
            'dxdd' => self::isZuHe($sum, $content),
            'jd'   => self::isJiDa($sum, $content),
            'jx'   => self::isJiXiao($sum, $content),
            'bz'   => self::isBaoZi($sum, $content),
            'sh'   => self::isShunZi($sum, $content),
            'dz'   => self::isDuiZi($sum, $content),
            'lh'   => self::isLongHu($sum, $content),
            'num'  => self::isTeMa($sum, $content),
            default => false,
        };
    }
}