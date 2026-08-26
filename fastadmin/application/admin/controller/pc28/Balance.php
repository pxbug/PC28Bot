<?php

namespace app\admin\controller\pc28;

use app\common\controller\Backend;
use app\common\model\User;
use app\common\model\BalanceLog;
use think\Db;

/**
 * PC28 余额操作管理
 *
 * 管理员给用户充值/扣款
 *
 * @icon   fa fa-cny
 */
class Balance extends Backend
{
    protected $model = null;

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new User();
    }

    /**
     * 余额操作（充值/提现）
     */
    public function operate()
    {
        if ($this->request->isPost()) {
            $uid = $this->request->post('uid', '');
            $action = $this->request->post('action', '');
            $amount = floatval($this->request->post('amount', 0));
            $note = $this->request->post('note', '');

            if (empty($uid)) {
                $this->error('用户UID不能为空');
            }

            if (!in_array($action, ['recharge', 'withdraw'])) {
                $this->error('操作类型错误');
            }

            if ($amount <= 0) {
                $this->error('金额必须大于0');
            }

            $user = User::where('uid', $uid)->find();
            if (!$user) {
                $this->error('用户不存在');
            }

            Db::startTrans();
            try {
                $balanceBefore = $user->balance;

                if ($action === 'recharge') {
                    // 充值：加余额
                    $user->balance = bcadd($user->balance, $amount, 2);
                    $actionLabel = '人工充值';
                } else {
                    // 提现：减余额
                    if (bccomp($user->balance, $amount, 2) < 0) {
                        throw new \Exception('余额不足');
                    }
                    $user->balance = bcsub($user->balance, $amount, 2);
                    $actionLabel = '人工提现';
                }

                $user->save();

                // 记录流水
                BalanceLog::create([
                    'uid' => $uid,
                    'action' => $action,
                    'amount' => $action === 'recharge' ? $amount : -$amount,
                    'balance_before' => $balanceBefore,
                    'balance_after' => $user->balance,
                    'operator_id' => $this->auth->id,
                    'operator_name' => $this->auth->nickname ?: $this->auth->username,
                    'note' => $note ?: $actionLabel,
                    'issue' => '',
                    'created_at' => date('Y-m-d H:i:s'),
                ]);

                Db::commit();
                $this->success($actionLabel . '成功，当前余额：' . $user->balance);
            } catch (\Exception $e) {
                Db::rollback();
                $this->error($actionLabel . '失败：' . $e->getMessage());
            }
        }

        return $this->view->fetch();
    }

    /**
     * 查看余额流水记录
     */
    public function log()
    {
        return $this->view->fetch();
    }

    /**
     * 余额流水列表数据
     */
    public function log_list()
    {
        $uid = $this->request->get('uid', '');
        $action = $this->request->get('action', '');

        $model = new BalanceLog();

        if ($uid) {
            $model = $model->where('uid', $uid);
        }
        if ($action) {
            $model = $model->where('action', $action);
        }

        list($where, $sort, $order, $offset, $limit) = $this->buildparams();

        $list = $model
            ->order('created_at', 'desc')
            ->paginate($limit);

        // 格式化操作类型
        $actionMap = [
            'recharge' => '人工充值',
            'withdraw' => '人工提现',
            'bet' => '下注',
            'settle' => '结算',
            'rebate' => '返水',
        ];

        foreach ($list as &$item) {
            $item['action_text'] = $actionMap[$item['action']] ?? $item['action'];
        }

        return json(['total' => $list->total(), 'rows' => $list->items()]);
    }

    /**
     * 快捷充值（输入UID和金额）
     */
    public function quick_recharge()
    {
        return $this->view->fetch();
    }

    /**
     * 快捷扣款
     */
    public function quick_deduct()
    {
        return $this->view->fetch();
    }
}
