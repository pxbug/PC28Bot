<?php

namespace app\api\controller;

use app\common\controller\Api;
use app\common\library\BotAuth;
use app\common\model\User;
use app\common\model\Bet;
use app\common\model\BalanceLog;
use think\Db;

/**
 * Bot API 网关
 *
 * 供 PC28 机器人调用的 JSON API，带签名验证
 */
class Bot extends Api
{
    protected $noNeedLogin = ['*'];
    protected $noNeedRight = ['*'];

    public function _initialize()
    {
        parent::_initialize();
    }

    /**
     * 统一响应格式
     */
    private function response($code = 0, $msg = '', $data = null)
    {
        return json([
            'code' => $code,
            'msg' => $msg,
            'data' => $data,
            'time' => time(),
        ]);
    }

    /**
     * 验证签名（所有接口都需要）
     */
    protected function verifySign()
    {
        $result = BotAuth::verify();
        if ($result['code'] !== 0) {
            $this->response($result['code'], $result['msg'], null)->send();
            exit;
        }
        return $result['config'];
    }

    // ==================== 用户相关 ====================

    /**
     * 注册/同步用户
     * POST /api/bot/register
     * Body: { "uid": "xxx", "nickname": "xxx" }
     */
    public function register()
    {
        $this->verifySign();

        $uid = $this->request->post('uid', '');
        $nickname = $this->request->post('nickname', '');

        if (empty($uid)) {
            return $this->response(4001, 'uid 不能为空');
        }

        $user = User::where('uid', $uid)->find();

        if ($user) {
            // 更新昵称
            if ($nickname && $user->nickname != $nickname) {
                $user->nickname = $nickname;
                $user->save();
            }
            return $this->response(0, '用户已存在', [
                'id' => $user->id,
                'uid' => $user->uid,
                'nickname' => $user->nickname,
                'balance' => $user->balance,
                'status' => $user->status,
            ]);
        }

        // 创建新用户
        $user = User::create([
            'uid' => $uid,
            'nickname' => $nickname ?: '',
            'balance' => 0.00,
            'status' => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->response(0, '注册成功', [
            'id' => $user->id,
            'uid' => $user->uid,
            'nickname' => $user->nickname,
            'balance' => $user->balance,
            'status' => $user->status,
        ]);
    }

    /**
     * 查询用户信息
     * POST /api/bot/user_info
     * Body: { "uid": "xxx" }
     */
    public function user_info()
    {
        $this->verifySign();

        $uid = $this->request->post('uid', '');

        if (empty($uid)) {
            return $this->response(4001, 'uid 不能为空');
        }

        $user = User::where('uid', $uid)->find();

        if (!$user) {
            return $this->response(4041, '用户不存在');
        }

        return $this->response(0, 'success', [
            'id' => $user->id,
            'uid' => $user->uid,
            'nickname' => $user->nickname,
            'balance' => $user->balance,
            'status' => $user->status,
            'remark' => $user->remark,
            'created_at' => $user->created_at,
        ]);
    }

    // ==================== 下注相关 ====================

    /**
     * 用户下注
     * POST /api/bot/bet
     * Body: {
     *   "uid": "xxx",
     *   "issue": "xxx",
     *   "bets": [
     *     { "type": "dx", "content": "大", "amount": 100, "odds": 2.0 },
     *     { "type": "dd", "content": "单", "amount": 50, "odds": 2.0 }
     *   ]
     * }
     */
    public function bet()
    {
        $this->verifySign();

        $uid = $this->request->post('uid', '');
        $issue = $this->request->post('issue', '');
        $betsJson = $this->request->post('bets', '[]');

        if (empty($uid) || empty($issue)) {
            return $this->response(4001, 'uid 和 issue 不能为空');
        }

        $bets = json_decode($betsJson, true);
        if (!is_array($bets) || empty($bets)) {
            return $this->response(4002, 'bets 格式错误或为空');
        }

        // 查询用户
        $user = User::where('uid', $uid)->find();
        if (!$user) {
            return $this->response(4041, '用户不存在，请先注册');
        }

        if ($user->status != 1) {
            return $this->response(4031, '账户已被冻结');
        }

        // 计算总下注金额
        $totalAmount = 0;
        foreach ($bets as $bet) {
            $totalAmount += floatval($bet['amount'] ?? 0);
        }

        if ($totalAmount <= 0) {
            return $this->response(4003, '下注金额必须大于 0');
        }

        // 检查余额
        if (bccomp($user->balance, $totalAmount, 2) < 0) {
            return $this->response(4004, '余额不足，当前余额: ' . $user->balance);
        }

        // 开启事务
        Db::startTrans();
        try {
            // 扣除余额
            $balanceBefore = $user->balance;
            $user->balance = bcsub($user->balance, $totalAmount, 2);
            $user->save();

            // 记录流水（合并为一笔）
            BalanceLog::create([
                'uid' => $uid,
                'action' => 'bet',
                'amount' => -$totalAmount,
                'balance_before' => $balanceBefore,
                'balance_after' => $user->balance,
                'operator_id' => 0,
                'operator_name' => 'Bot',
                'note' => '下注 ' . count($bets) . ' 注',
                'issue' => $issue,
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            // 批量写入下注记录
            $now = date('Y-m-d H:i:s');
            foreach ($bets as $bet) {
                Bet::create([
                    'uid' => $uid,
                    'issue' => $issue,
                    'bet_type' => $bet['type'] ?? '',
                    'bet_content' => $bet['content'] ?? '',
                    'amount' => $bet['amount'] ?? 0,
                    'odds' => $bet['odds'] ?? 1.0,
                    'status' => 0,
                    'settle_amount' => 0,
                    'created_at' => $now,
                ]);
            }

            Db::commit();

            return $this->response(0, '下注成功', [
                'total_amount' => $totalAmount,
                'balance' => $user->balance,
                'bet_count' => count($bets),
            ]);
        } catch (\Exception $e) {
            Db::rollback();
            return $this->response(5001, '下注失败: ' . $e->getMessage());
        }
    }

    /**
     * 开奖结算
     * POST /api/bot/settle
     * Body: { "issue": "xxx", "number": "8+9+2=19", "sum": 19 }
     *
     * 根据开奖结果，自动结算所有该期下注
     */
    public function settle()
    {
        $this->verifySign();

        $issue = $this->request->post('issue', '');
        $number = $this->request->post('number', '');
        $sum = intval($this->request->post('sum', 0));

        if (empty($issue) || $sum < 0 || $sum > 27) {
            return $this->response(4001, 'issue 或 sum 参数错误');
        }

        // 查询该期所有待结算下注
        $bets = Bet::where('issue', $issue)
            ->where('status', 0)
            ->select();

        if (empty($bets)) {
            return $this->response(0, '该期无下注记录', [
                'settled_count' => 0,
                'settle_amount' => 0,
            ]);
        }

        // 判断中奖条件
        $isBig = $sum >= 14;
        $isSmall = $sum <= 13;
        $isOdd = $sum % 2 == 1;
        $isEven = $sum % 2 == 0;
        $isLeopard = false; // 豹子需要看单球，暂不实现

        $settleResults = [];
        $totalSettle = 0;

        Db::startTrans();
        try {
            foreach ($bets as $bet) {
                $win = $this->checkWin($bet->bet_type, $bet->bet_content, $sum, $isBig, $isSmall, $isOdd, $isEven);

                if ($win) {
                    // 中奖：计算赔付
                    $settleAmount = bcmul($bet->amount, $bet->odds, 2);
                    $bet->status = 1;
                    $bet->settle_amount = $settleAmount;
                    $bet->settled_at = date('Y-m-d H:i:s');
                    $bet->save();

                    // 给用户加余额
                    $user = User::where('uid', $bet->uid)->find();
                    if ($user) {
                        $balanceBefore = $user->balance;
                        $user->balance = bcadd($user->balance, $settleAmount, 2);
                        $user->save();

                        // 记录流水
                        BalanceLog::create([
                            'uid' => $bet->uid,
                            'action' => 'settle',
                            'amount' => $settleAmount,
                            'balance_before' => $balanceBefore,
                            'balance_after' => $user->balance,
                            'operator_id' => 0,
                            'operator_name' => 'Bot',
                            'note' => '结算中奖: ' . $bet->bet_content,
                            'issue' => $issue,
                            'created_at' => date('Y-m-d H:i:s'),
                        ]);
                    }

                    $totalSettle = bcadd($totalSettle, $settleAmount, 2);
                    $settleResults[] = [
                        'uid' => $bet->uid,
                        'bet_content' => $bet->bet_content,
                        'amount' => $bet->amount,
                        'odds' => $bet->odds,
                        'settle_amount' => $settleAmount,
                    ];
                } else {
                    // 未中奖
                    $bet->status = 2;
                    $bet->settle_amount = 0;
                    $bet->settled_at = date('Y-m-d H:i:s');
                    $bet->save();
                }
            }

            Db::commit();

            return $this->response(0, '结算完成', [
                'issue' => $issue,
                'number' => $number,
                'sum' => $sum,
                'settled_count' => count($settleResults),
                'settle_amount' => $totalSettle,
                'results' => $settleResults,
            ]);
        } catch (\Exception $e) {
            Db::rollback();
            return $this->response(5001, '结算失败: ' . $e->getMessage());
        }
    }

    /**
     * 判断是否中奖
     */
    private function checkWin($type, $content, $sum, $isBig, $isSmall, $isOdd, $isEven)
    {
        $content = mb_strtolower(trim($content));

        switch ($type) {
            case 'dx': // 大小
                if ($content === '大') return $isBig;
                if ($content === '小') return $isSmall;
                break;

            case 'dd': // 单双
                if ($content === '单') return $isOdd;
                if ($content === '双') return $isEven;
                break;

            case 'dxdd': // 大小单双组合
                if ($content === '大单') return $isBig && $isOdd;
                if ($content === '小单') return $isSmall && $isOdd;
                if ($content === '大双') return $isBig && $isEven;
                if ($content === '小双') return $isSmall && $isEven;
                break;

            case 'jd': // 极大(22-27)
                return $sum >= 22;
            case 'jx': // 极小(0-5)
                return $sum <= 5;

            case 'num': // 特码
                return intval($content) === $sum;

            default:
                return false;
        }

        return false;
    }

    /**
     * 获取期号下注统计
     * POST /api/bot/bet_list
     * Body: { "issue": "xxx" }
     */
    public function bet_list()
    {
        $this->verifySign();

        $issue = $this->request->post('issue', '');

        if (empty($issue)) {
            return $this->response(4001, 'issue 不能为空');
        }

        $bets = Bet::where('issue', $issue)->select();

        $list = [];
        $totalAmount = 0;
        foreach ($bets as $bet) {
            $list[] = [
                'uid' => $bet->uid,
                'bet_type' => $bet->bet_type,
                'bet_content' => $bet->bet_content,
                'amount' => $bet->amount,
                'odds' => $bet->odds,
                'status' => $bet->status,
                'created_at' => $bet->created_at,
            ];
            $totalAmount = bcadd($totalAmount, $bet->amount, 2);
        }

        return $this->response(0, 'success', [
            'issue' => $issue,
            'total_amount' => $totalAmount,
            'count' => count($bets),
            'list' => $list,
        ]);
    }

    // ==================== 余额查询 ====================

    /**
     * 查询用户余额
     * POST /api/bot/balance
     * Body: { "uid": "xxx" }
     */
    public function balance()
    {
        $this->verifySign();

        $uid = $this->request->post('uid', '');

        if (empty($uid)) {
            return $this->response(4001, 'uid 不能为空');
        }

        $user = User::where('uid', $uid)->find();

        if (!$user) {
            return $this->response(4041, '用户不存在');
        }

        return $this->response(0, 'success', [
            'uid' => $user->uid,
            'balance' => $user->balance,
        ]);
    }
}
