<?php

namespace app\admin\controller\pc28;

use app\common\controller\Backend;
use app\common\model\Bet;

/**
 * PC28 下注记录管理
 *
 * @icon   fa fa-list
 */
class Bet extends Backend
{
    protected $model = null;

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new Bet();
    }

    /**
     * 查看列表
     */
    public function index()
    {
        if ($this->request->isAjax()) {
            list($where, $sort, $order, $offset, $limit) = $this->buildparams();

            $list = $this->model
                ->where($where)
                ->order('created_at', 'desc')
                ->paginate($limit);

            // 格式化状态
            $statusMap = [
                0 => '待结算',
                1 => '赢',
                2 => '输',
            ];

            foreach ($list as &$item) {
                $item['status_text'] = $statusMap[$item['status']] ?? '未知';
            }

            $result = [
                'total' => $list->total(),
                'rows' => $list->items(),
            ];

            return json($result);
        }

        return $this->view->fetch();
    }

    /**
     * 按期号查看
     */
    public function by_issue()
    {
        return $this->view->fetch();
    }

    /**
     * 按期号查看列表数据
     */
    public function by_issue_list()
    {
        $issue = $this->request->get('issue', '');

        if (empty($issue)) {
            return json(['total' => 0, 'rows' => []]);
        }

        $list = $this->model
            ->where('issue', $issue)
            ->order('created_at', 'asc')
            ->select();

        // 统计
        $totalAmount = 0;
        $totalSettle = 0;
        $winCount = 0;
        $loseCount = 0;

        $statusMap = [
            0 => '待结算',
            1 => '赢',
            2 => '输',
        ];

        foreach ($list as &$item) {
            $item['status_text'] = $statusMap[$item['status']] ?? '未知';
            $totalAmount = bcadd($totalAmount, $item['amount'], 2);
            $totalSettle = bcadd($totalSettle, $item['settle_amount'], 2);
            if ($item['status'] == 1) $winCount++;
            if ($item['status'] == 2) $loseCount++;
        }

        return json([
            'total' => count($list),
            'rows' => $list,
            'summary' => [
                'total_amount' => $totalAmount,
                'total_settle' => $totalSettle,
                'win_count' => $winCount,
                'lose_count' => $loseCount,
            ],
        ]);
    }

    /**
     * 批量结算（手动开奖后使用）
     */
    public function batch_settle()
    {
        if ($this->request->isPost()) {
            $issue = $this->request->post('issue', '');
            $number = $this->request->post('number', '');
            $sum = intval($this->request->post('sum', 0));

            if (empty($issue) || $sum < 0 || $sum > 27) {
                $this->error('参数错误');
            }

            // 这里直接调用 Bot 控制器的结算逻辑
            // 实际可以简化为调用一个公共方法
            $this->success('批量结算功能开发中...');
        }

        return $this->view->fetch();
    }
}
