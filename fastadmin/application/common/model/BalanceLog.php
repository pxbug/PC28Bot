<?php

namespace app\common\model;

use think\Model;

class BalanceLog extends Model
{
    protected $name = 'balance_log';

    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = 'created_at';

    protected $type = [
        'amount' => 'float',
        'balance_before' => 'float',
        'balance_after' => 'float',
        'operator_id' => 'integer',
    ];
}
