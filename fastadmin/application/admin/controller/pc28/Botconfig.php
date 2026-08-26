<?php

namespace app\admin\controller\pc28;

use app\common\controller\Backend;
use app\common\model\BotConfig;

/**
 * PC28 Bot API 配置管理
 *
 * @icon   fa fa-cog
 */
class Botconfig extends Backend
{
    protected $model = null;

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new BotConfig();
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
     * 添加
     */
    public function add()
    {
        if ($this->request->isPost()) {
            $appId = $this->request->post('app_id', '');
            $secretKey = $this->request->post('secret_key', '');
            $name = $this->request->post('name', '');
            $status = $this->request->post('status', 1);

            if (empty($appId) || empty($secretKey)) {
                $this->error('应用ID和密钥不能为空');
            }

            if ($this->model->where('app_id', $appId)->find()) {
                $this->error('应用ID已存在');
            }

            $this->model->create([
                'app_id' => $appId,
                'secret_key' => $secretKey,
                'name' => $name,
                'status' => $status,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            $this->success('添加成功');
        }

        return $this->view->fetch();
    }

    /**
     * 编辑
     */
    public function edit($ids = null)
    {
        $row = $this->model->get($ids);
        if (!$row) {
            $this->error('记录不存在');
        }

        if ($this->request->isPost()) {
            $name = $this->request->post('name', '');
            $secretKey = $this->request->post('secret_key', '');
            $status = $this->request->post('status', 1);

            $row->name = $name;
            $row->status = $status;
            if ($secretKey) {
                $row->secret_key = $secretKey;
            }
            $row->updated_at = date('Y-m-d H:i:s');
            $row->save();

            $this->success('保存成功');
        }

        $this->view->assign('row', $row);
        return $this->view->fetch();
    }

    /**
     * 删除
     */
    public function del($ids = '')
    {
        if (!$this->request->isPost()) {
            $this->error('Invalid parameters');
        }

        $ids = $ids ?: $this->request->post('ids');
        if (empty($ids)) {
            $this->error('请选择要删除的记录');
        }

        $count = $this->model->where('id', 'in', explode(',', $ids))->delete();
        if ($count) {
            $this->success('删除成功');
        }

        $this->error('删除失败');
    }
}
