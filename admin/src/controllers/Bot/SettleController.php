<?php
namespace App\Controllers\Bot;

use App\Db;
use App\Response;

/**
 * 开奖结算（Bot 端调用）
 */
class SettleController
{
    /**
     * 开奖并结算
     *
     * body: {
     *   issue: 期号,
     *   number: "8+9+2=19",
     *   sum: 19
     * }
     */
    public function settle(array $body): void
    {
        $issue = trim((string)($body['issue'] ?? ''));
        $number = trim((string)($body['number'] ?? ''));
        $sum = (int)($body['sum'] ?? -1);

        if ($issue === '' || $number === '' || $sum < 0 || $sum > 27) {
            Response::fail('参数无效', 1001);
        }

        // 写入开奖记录
        $exists = Db::fetch('SELECT id FROM bot_lottery WHERE issue = ?', [$issue]);
        if (!$exists) {
            Db::insert('bot_lottery', [
                'issue'    => $issue,
                'number'   => $number,
                'sum'      => $sum,
                'settled'  => 0,
            ]);
        } else {
            Db::update('bot_lottery', [
                'number'  => $number,
                'sum'     => $sum,
                'settled' => 0,
            ], 'issue = :issue', ['issue' => $issue]);
        }

        // 结算下注
        $settledCount = 0;
        $settleAmount = 0.0;
        $results = [];

        $bets = Db::fetchAll(
            'SELECT b.*, u.uid, u.id as user_id, u.nickname, u.balance
             FROM bot_bets b
             LEFT JOIN bot_users u ON b.user_id = u.id
             WHERE b.issue = ? AND b.status = 0',
            [$issue]
        );

        foreach ($bets as $bet) {
            $win = BetController::judge($sum, $bet['bet_type'], $bet['content']);
            $payout = 0.0;
            if ($win) {
                $payout = (float)$bet['amount'] * (float)$bet['odds'];
            }

            Db::execute(
                'UPDATE bot_bets SET payout = ?, status = ?, settled_at = NOW() WHERE id = ?',
                [$payout, $win ? 1 : 2, (int)$bet['id']]
            );

            if ($win && $payout > 0) {
                Db::execute(
                    'UPDATE bot_users SET balance = balance + ? WHERE id = ?',
                    [$payout, (int)$bet['user_id']]
                );
            }

            $settledCount++;
            $settleAmount += $payout;
            $results[] = [
                'uid'      => $bet['uid'],
                'content'  => $bet['content'],
                'amount'   => (float)$bet['amount'],
                'win'      => $win,
                'payout'   => $payout,
            ];
        }

        // 标记为已结算
        Db::execute('UPDATE bot_lottery SET settled = 1 WHERE issue = ?', [$issue]);

        Response::ok([
            'issue'          => $issue,
            'number'         => $number,
            'sum'            => $sum,
            'settled_count'  => $settledCount,
            'settle_amount'  => $settleAmount,
            'results'        => $results,
        ]);
    }
}