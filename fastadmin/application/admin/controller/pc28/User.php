<?php

namespace app\admin\controller\pc28;

use app\common\controller\Backend;
use app\common\model\User;
use app\common\model\BalanceLog;
use think\Db;

/**
 * PC28 用户管理
 *
 * @icon   fa fa-users
 */
class User extends Backend
{
    protected $model = null;

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new User();
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
                ->order($sort, $order)
                ->paginate($limit);

            $result = [
                'total' => $list->total(),
                'rows' => $list->items(),
            ];

            return json($result);
        }

        return $this->view->fetch();
    }

    /**
     * 添加用户
     */
    public function add()
    {
        if ($this->request->isPost()) {
            $uid = $this->request->post('uid', '');
            $nickname = $this->request->post('nickname', '');
            $balance = $this->request->post('balance', 0);
            $remark = $this->request->post('remark', '');

            if (empty($uid)) {
                $this->error('用户UID不能为空');
            }

            // 检查是否已存在
            if ($this->model->where('uid', $uid)->find()) {
                $this->error('该用户UID已存在');
            }

            $this->model->create([
                'uid' => $uid,
                'nickname' => $nickname,
                'balance' => $balance,
                'status' => 1,
                'remark' => $remark,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            $this->success('添加成功');
        }

        return $this->view->fetch();
    }

    /**
     * 编辑用户
     */
    public function edit($ids = null)
    {
        $row = $this->model->get($ids);
        if (!$row) {
            $this->error('用户不存在');
        }

        if ($this->request->isPost()) {
            $nickname = $this->request->post('nickname', '');
            $remark = $this->request->post('remark', '');
            $status = $this->request->post('status', 1);

            $row->nickname = $nickname;
            $row->remark = $remark;
            $row->status = $status;
            $row->updated_at = date('Y-m-d H:i:s');
            $row->save();

            $this->success('保存成功');
        }

        $this->view->assign('row', $row);
        return $this->view->fetch();
    }

    /**
     * 删除用户
     */
    public function del($ids = '')
    {
        if (!$this->request->isPost()) {
            $this->error('Invalid parameters');
        }

        $ids = $ids ?: $this->request->post('ids');
        if (empty($ids)) {
            $this->error('请选择要删除的用户');
        }

        $count = $this->model->where('id', 'in', $ids)->delete();
        if ($count) {
            $this->success('删除成功，共删除 ' . $count . ' 条记录');
        }

        $this->error('删除失败');
    }

    /**
     * 查看用户余额记录
     */
    public function balance_log()
    {
        $uid = $this->request->get('uid', '');

        $this->view->assign('uid', $uid);
        return $this->view->fetch();
    }

    /**
     * 余额记录列表数据
     */
    public function balance_log_list()
    {
        $uid = $this->request->get('uid', '');
        list($where, $sort, $order, $offset, $limit) = $this->buildparams();

        $model = new BalanceLog();

        if ($uid) {
            $model = $model->where('uid', $uid);
        }

        $list = $model
            ->where($where)
            ->order('created_at', 'desc')
            ->paginate($limit);

        return json(['total' => $list->total(), 'rows' => $list->items()]);
    }

    /**
     * 下注记录
     */
    public function bet_log()
    {
        $uid = $this->request->get('uid', '');
        $this->view->assign('uid', $uid);
        return $this->view->fetch();
    }

    /**
     * 下注记录列表数据
     */
    public function bet_log_list()
    {
        $uid = $this->request->get('uid', '');
        $issue = $this->request->get('issue', '');

        $model = \app\common\model\Bet::order('created_at', 'desc');

        if ($uid) {
            $model = $model->where('uid', $uid);
        }
        if ($issue) {
            $model = $model->where('issue', $issue);
        }

        list($where, $sort, $order, $offset, $limit) = $this->buildparams();

        $list = $model->where($where)->paginate($limit);

        return json(['total' => $list->total(), 'rows' => $list->items()]);
    }
}
