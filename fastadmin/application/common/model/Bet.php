<?php

namespace app\common\model;

use think\Model;

class Bet extends Model
{
    protected $name = 'bet';

    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = 'created_at';

    protected $type = [
        'amount' => 'float',
        'odds' => 'float',
        'settle_amount' => 'float',
        'status' => 'integer',
    ];
}
