<?php
/**
 * 系统配置页面
 */
use App\Db;
use App\Helper;
use App\Auth;

$message = '';

// 保存配置
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'save_config') {
        $key = trim($_POST['config_key'] ?? '');
        $value = $_POST['config_value'] ?? '';
        $desc = trim($_POST['description'] ?? '');
        if ($key) {
            $exists = Db::fetch('SELECT id FROM bot_config WHERE config_key = ?', [$key]);
            if ($exists) {
                Db::update('bot_config',
                    ['config_value' => $value, 'description' => $desc],
                    'config_key = :key', ['key' => $key]
                );
            } else {
                Db::insert('bot_config', [
                    'config_key'    => $key,
                    'config_value'  => $value,
                    'description'   => $desc,
                ]);
            }
            Helper::flash('toast', "配置 {$key} 已保存");
            Response::redirect('/index.php?page=config');
        }
    } elseif ($_POST['action'] === 'change_password') {
        $old = $_POST['old_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';
        if ($new !== $confirm) {
            $message = '两次密码输入不一致';
        } elseif (strlen($new) < 6) {
            $message = '新密码长度不能少于 6 位';
        } else {
            $user = Auth::user();
            $row = Db::fetch('SELECT * FROM admin_user WHERE username = ?', [$user['username']]);
            if (!$row || !password_verify($old, $row['password_hash'])) {
                $message = '原密码错误';
            } else {
                Db::update('admin_user',
                    ['password_hash' => password_hash($new, PASSWORD_BCRYPT)],
                    'id = :id', ['id' => $row['id']]
                );
                Helper::flash('toast', '密码修改成功');
                Response::redirect('/index.php?page=config');
            }
        }
    } elseif ($_POST['action'] === 'delete_config') {
        $key = trim($_POST['config_key'] ?? '');
        if ($key) {
            Db::execute('DELETE FROM bot_config WHERE config_key = ?', [$key]);
            Helper::flash('toast', "配置 {$key} 已删除");
            Response::redirect('/index.php?page=config');
        }
    }
}

// 读取所有配置
$configs = Db::fetchAll('SELECT * FROM bot_config ORDER BY id ASC');

// Bot API 信息
$configMap = [];
foreach ($configs as $c) {
    $configMap[$c['config_key']] = $c['config_value'];
}
?>

<?php if ($message): ?>
<div style="background:rgba(255,59,48,.1);color:var(--red);padding:12px 16px;border-radius:var(--radius-md);font-size:13px;margin-bottom:20px">
    <?= Helper::h($message) ?>
</div>
<?php endif; ?>

<div class="flex gap-4" style="flex-wrap:wrap">

    <!-- 赔率/限额配置 -->
    <div style="flex:1;min-width:360px">
        <div class="card">
            <div class="card-header">赔率 & 限额配置</div>
            <div style="padding:16px 20px;display:flex;flex-direction:column;gap:12px">
                <?php
                $displayConfigs = [
                    'rebate_min_turnover'   => ['最低流水要求（元）', 'number', '最低流水才返水'],
                    'rebate_min_count'      => ['最低投注期数', 'number', '最低投注期数才返水'],
                    'rebate_rate'           => ['基础返点率', 'number', '百分比，如 0.6 表示 0.6%'],
                    'rebate_deduct_opposite'=> ['对压扣除率', 'number', '百分比，如 0.4 表示 0.4%'],
                    'max_total_per_issue'   => ['单期总下注封顶', 'number', '元'],
                    'max_payout_per_issue'  => ['单期最大赔付', 'number', '元'],
                    'enable_rebate'         => ['启用反水', 'select', '0=关闭 1=开启', [0 => '关闭', 1 => '开启']],
                ];
                foreach ($displayConfigs as $key => $info):
                    $label = $info[0];
                    $type = $info[1];
                    $hint = $info[2] ?? '';
                    $options = $info[3] ?? [];
                    $value = $configMap[$key] ?? '';
                ?>
                <form method="POST" class="flex gap-2 items-center" style="flex-wrap:wrap">
                    <input type="hidden" name="action" value="save_config">
                    <input type="hidden" name="config_key" value="<?= Helper::h($key) ?>">
                    <input type="hidden" name="description" value="<?= Helper::h($label) ?>">
                    <label class="text-sm" style="width:140px;flex-shrink:0;font-weight:500"><?= Helper::h($label) ?></label>
                    <?php if ($type === 'select'): ?>
                    <select name="config_value" class="input" style="flex:1;min-width:100px">
                        <?php foreach ($options as $v => $l): ?>
                        <option value="<?= $v ?>" <?= (string)$value === (string)$v ? 'selected' : '' ?>><?= Helper::h($l) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php else: ?>
                    <input type="<?= $type ?>" name="config_value" class="input" value="<?= Helper::h($value) ?>" style="flex:1;min-width:100px">
                    <?php endif; ?>
                    <button type="submit" class="btn btn-secondary btn-sm">保存</button>
                </form>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- 修改密码 -->
    <div style="flex:1;min-width:300px">
        <div class="card" style="margin-bottom:20px">
            <div class="card-header">修改管理员密码</div>
            <form method="POST" style="padding:16px 20px;display:flex;flex-direction:column;gap:14px">
                <input type="hidden" name="action" value="change_password">
                <div class="input-group">
                    <label class="input-label">原密码</label>
                    <input type="password" name="old_password" class="input" required>
                </div>
                <div class="input-group">
                    <label class="input-label">新密码</label>
                    <input type="password" name="new_password" class="input" required minlength="6">
                </div>
                <div class="input-group">
                    <label class="input-label">确认新密码</label>
                    <input type="password" name="confirm_password" class="input" required minlength="6">
                </div>
                <button type="submit" class="btn btn-primary">修改密码</button>
            </form>
        </div>

        <div class="card">
            <div class="card-header">Bot API 对接信息</div>
            <div style="padding:16px 20px;display:flex;flex-direction:column;gap:10px">
                <?php
                $cfg = require __DIR__ . '/../../config.php';
                ?>
                <div class="flex items-center gap-2">
                    <span class="text-sm text-muted" style="width:80px">App ID</span>
                    <code style="font-size:13px;background:rgba(0,0,0,.05);padding:2px 8px;border-radius:4px"><?= Helper::h($cfg['bot_api']['app_id']) ?></code>
                </div>
                <div class="flex items-center gap-2">
                    <span class="text-sm text-muted" style="width:80px">Secret</span>
                    <code style="font-size:13px;background:rgba(0,0,0,.05);padding:2px 8px;border-radius:4px"><?= Helper::h($cfg['bot_api']['secret_key']) ?></code>
                </div>
                <div class="flex items-center gap-2">
                    <span class="text-sm text-muted" style="width:80px">回调地址</span>
                    <code style="font-size:12px;word-break:break-all"><?= Helper::h(rtrim($_SERVER['HTTP_HOST'] ?? '', '/') ?>/api/bot/</code>
                </div>
                <p class="text-xs text-muted" style="margin-top:4px">在 robot.config.json 中配置相同的 app_id 和 secret_key。</p>
            </div>
        </div>
    </div>

</div>