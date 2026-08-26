<?php

namespace app\common\model;

use think\Model;

class BotConfig extends Model
{
    protected $name = 'bot_config';

    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = 'created_at';
    protected $updateTime = 'updated_at';

    protected $type = [
        'status' => 'integer',
    ];
}
