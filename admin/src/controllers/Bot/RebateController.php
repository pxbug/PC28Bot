<?php
namespace App\Controllers\Bot;

use App\Db;
use App\Response;

/**
 * 反水（Bot 端调用）
 *
 * body: {
 *   uid: 用户ID,
 *   period: "2026-08-26",
 *   turnover: 流水金额,
 *   bet_count: 投注期数,
 *   deduct_rate: 扣除率（可选，默认0）,
 *   remark: 备注（可选）
 * }
 */
class RebateController
{
    public function run(array $body): void
    {
        $uid = trim((string)($body['uid'] ?? ''));
        $period = trim((string)($body['period'] ?? ''));
        $turnover = (float)($body['turnover'] ?? 0);
        $betCount = (int)($body['bet_count'] ?? 0);
        $deductRate = (float)($body['deduct_rate'] ?? 0);
        $remark = trim((string)($body['remark'] ?? ''));

        if ($uid === '' || $period === '') {
            Response::fail('参数不完整', 1001);
        }

        // 读取配置
        $minTurnover = (float)(Db::fetch(
            "SELECT config_value FROM bot_config WHERE config_key = 'rebate_min_turnover'"
        )['config_value'] ?? 1000);
        $minCount = (int)(Db::fetch(
            "SELECT config_value FROM bot_config WHERE config_key = 'rebate_min_count'"
        )['config_value'] ?? 10);
        $baseRate = (float)(Db::fetch(
            "SELECT config_value FROM bot_config WHERE config_key = 'rebate_rate'"
        )['config_value'] ?? 0.6);
        $enabled = (int)(Db::fetch(
            "SELECT config_value FROM bot_config WHERE config_key = 'enable_rebate'"
        )['config_value'] ?? 1);

        if (!$enabled) {
            Response::fail('反水功能已关闭', 1006);
        }

        // 校验条件
        if ($turnover < $minTurnover) {
            Response::fail("流水不足（需 ≥{$minTurnover}），本次 {$turnover}", 1007);
        }
        if ($betCount < $minCount) {
            Response::fail("投注期数不足（需 ≥{$minCount} 期），本次 {$betCount}", 1008);
        }

        // 查询用户
        $user = Db::fetch('SELECT * FROM bot_users WHERE uid = ?', [$uid]);
        if (!$user) {
            Response::fail('用户不存在', 1002);
        }

        // 计算反水
        $effectiveRate = max(0, ($baseRate - $deductRate) / 100);
        if ($effectiveRate <= 0) {
            Response::fail('返点率为零（扣除后无余额）', 1009);
        }

        $rebateAmount = round($turnover * $effectiveRate, 2);

        // 写入反水记录 + 增加余额
        Db::begin();
        try {
            Db::insert('bot_rebate_log', [
                'user_id'      => (int)$user['id'],
                'uid'          => $uid,
                'period'       => $period,
                'turnover'     => $turnover,
                'bet_count'    => $betCount,
                'rebate_rate'  => $effectiveRate,
                'deduct_rate'  => $deductRate,
                'rebate_amount'=> $rebateAmount,
                'remark'       => $remark,
            ]);

            Db::execute(
                'UPDATE bot_users SET balance = balance + ?, total_rebate = total_rebate + ? WHERE id = ?',
                [$rebateAmount, $rebateAmount, (int)$user['id']]
            );

            Db::commit();

            $newBalance = (float)$user['balance'] + $rebateAmount;
            Response::ok([
                'uid'          => $uid,
                'period'       => $period,
                'turnover'     => $turnover,
                'rebate_rate'  => $effectiveRate,
                'rebate_amount'=> $rebateAmount,
                'balance'      => $newBalance,
            ]);
        } catch (\Exception $e) {
            Db::rollback();
            Response::fail('反水失败：' . $e->getMessage(), 1999);
        }
    }
}