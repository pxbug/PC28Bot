<?php

namespace app\common\model;

use think\Model;

class User extends Model
{
    protected $name = 'user';

    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = 'created_at';
    protected $updateTime = 'updated_at';

    protected $type = [
        'balance' => 'float',
        'status' => 'integer',
    ];
}
