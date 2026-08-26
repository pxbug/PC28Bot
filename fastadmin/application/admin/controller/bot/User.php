<?php

namespace app\admin\controller\bot;

use app\common\controller\Backend;
use app\common\model\User;

/**
 * PC28 用户管理
 */
class User extends Backend
{
    protected $model = null;
    protected $searchFields = 'uid,nickname';

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new User();
    }

    /**
     * 用户列表
     */
    public function index()
    {
        if ($this->request->isAjax()) {
            list($where, $sort, $order, $offset, $limit) = $this->buildparams();

            $total = $this->model
                ->where($where)
                ->order($sort, $order)
                ->count();

            $list = $this->model
                ->where($where)
                ->order($sort, $order)
                ->limit($offset, $limit)
                ->select();

            $result = [
                'total' => $total,
                'rows' => $list,
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
            $params = $this->request->post();
            if ($params) {
                $params['created_at'] = date('Y-m-d H:i:s');
                $params['updated_at'] = date('Y-m-d H:i:s');
                $result = $this->model->save($params);
                if ($result !== false) {
                    $this->success('添加成功');
                } else {
                    $this->error('添加失败');
                }
            }
            $this->error('参数错误');
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
            $params = $this->request->post();
            if ($params) {
                $params['updated_at'] = date('Y-m-d H:i:s');
                $result = $row->save($params);
                if ($result !== false) {
                    $this->success('修改成功');
                } else {
                    $this->error('修改失败');
                }
            }
            $this->error('参数错误');
        }

        $this->view->assign('row', $row);
        return $this->view->fetch();
    }

    /**
     * 删除用户
     */
    public function del($ids = '')
    {
        if ($ids) {
            $ids = explode(',', $ids);
            $count = $this->model->where('id', 'in', $ids)->delete();
            if ($count) {
                $this->success('删除成功');
            }
        }
        $this->error('删除失败');
    }

    /**
     * 冻结/解冻用户
     */
    public function toggle_status($ids = null)
    {
        $row = $this->model->get($ids);
        if (!$row) {
            $this->error('用户不存在');
        }

        $row->status = $row->status == 1 ? 0 : 1;
        $row->updated_at = date('Y-m-d H:i:s');
        $row->save();

        $this->success('状态已更新');
    }
}
