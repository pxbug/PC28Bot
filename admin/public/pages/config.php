<?php
/**
 * System config page
 */

$pageTitle = '系统配置';
$breadcrumb = [['系统配置', '/config']];

$configFile = __DIR__ . '/../../config/robot.config.json';
$config = file_exists($configFile) ? (json_decode(file_get_contents($configFile), true) ?? []) : [];
$apiSection = $config['admin_api'] ?? [];
$botUrl = $apiSection['base_url'] ?? 'http://154.36.188.150/api/bot/';
$secretKey = $apiSection['secret_key'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    if ($action === 'save_bot_url') {
        $newUrl = trim($_POST['bot_url'] ?? '');
        if (!isset($config['admin_api'])) $config['admin_api'] = [];
        $config['admin_api']['base_url'] = $newUrl;
        if (file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))) {
            DB::insert('operations_log', [
                'admin_id'=>Auth::id(), 'action'=>'update_config',
                'detail'=>"更新 Bot API URL: $newUrl"
            ]);
            echo json_encode(['ok'=>true, 'msg'=>'Bot URL 已更新']);
        } else {
            echo json_encode(['ok'=>false, 'msg'=>'保存失败']);
        }
    } elseif ($action === 'regenerate_key') {
        $newKey = bin2hex(random_bytes(16));
        if (!isset($config['admin_api'])) $config['admin_api'] = [];
        $config['admin_api']['secret_key'] = $newKey;
        if (file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))) {
            DB::insert('operations_log', [
                'admin_id'=>Auth::id(), 'action'=>'regenerate_secret',
                'detail'=>'重新生成 Secret Key'
            ]);
            echo json_encode(['ok'=>true, 'msg'=>'Secret Key 已重新生成', 'key'=>$newKey]);
        } else {
            echo json_encode(['ok'=>false, 'msg'=>'生成失败']);
        }
    } elseif ($action === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $newPwd = $_POST['new_password'] ?? '';
        $confirmPwd = $_POST['confirm_password'] ?? '';

        if (strlen($newPwd) < 6) {
            echo json_encode(['ok'=>false, 'msg'=>'新密码长度至少6位']); exit;
        }
        if ($newPwd !== $confirmPwd) {
            echo json_encode(['ok'=>false, 'msg'=>'两次密码不一致']); exit;
        }

        $admin = Auth::admin();
        if (!password_verify($current, DB::fetch("SELECT password FROM admins WHERE id=?", [Auth::id()])['password'])) {
            echo json_encode(['ok'=>false, 'msg'=>'当前密码错误']); exit;
        }

        DB::update('admins', ['password'=>Auth::hashPassword($newPwd)], 'id=?', [Auth::id()]);
        DB::insert('operations_log', [
            'admin_id'=>Auth::id(), 'action'=>'change_password',
            'detail'=>'修改管理员密码'
        ]);
        echo json_encode(['ok'=>true, 'msg'=>'密码已更新']);
    } else {
        echo json_encode(['ok'=>false, 'msg'=>'未知操作']);
    }
    exit;
}
?>
<div style="max-width:640px">

<!-- Bot API Config -->
<div class="section-card">
    <div class="section-card-header">
        <div class="section-card-title">Bot API 配置</div>
    </div>
    <div class="section-card-body">
        <p style="font-size:12.5px;color:var(--text-secondary);margin-bottom:16px;">
            机器人的后端 API 地址，用于下注、结算等业务逻辑。修改后需重启机器人服务。
        </p>
        <form id="bot-url-form">
            <div class="form-group">
                <label class="form-label">Bot API 基础地址</label>
                <input type="url" name="bot_url" class="form-input" value="<?= htmlspecialchars($botUrl) ?>" placeholder="https://your-bot.com/api/" required>
            </div>
            <input type="hidden" name="action" value="save_bot_url">
            <button type="submit" class="btn btn-primary">保存 Bot URL</button>
        </form>
    </div>
</div>

<!-- Secret Key -->
<div class="section-card">
    <div class="section-card-header">
        <div class="section-card-title">安全密钥</div>
    </div>
    <div class="section-card-body">
        <p style="font-size:12.5px;color:var(--text-secondary);margin-bottom:16px;">
            与机器人通信的密钥，请妥善保管，不要泄露给他人。
        </p>
        <div class="form-group">
            <label class="form-label">当前 Secret Key</label>
            <div style="display:flex;gap:8px;">
                <input type="password" id="secret-key-display" class="form-input font-mono" value="<?= htmlspecialchars($secretKey) ?>" readonly style="flex:1">
                <button class="btn btn-secondary" onclick="toggleKey()" type="button" id="toggle-btn">显示</button>
            </div>
        </div>
        <button class="btn btn-secondary" onclick="regenerateKey()">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/>
                <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>
            </svg>
            重新生成密钥
        </button>
    </div>
</div>

<!-- Change Password -->
<div class="section-card">
    <div class="section-card-header">
        <div class="section-card-title">修改密码</div>
    </div>
    <div class="section-card-body">
        <form id="password-form">
            <div class="form-group">
                <label class="form-label">当前密码</label>
                <input type="password" name="current_password" class="form-input" required>
            </div>
            <div class="form-group">
                <label class="form-label">新密码</label>
                <input type="password" name="new_password" class="form-input" placeholder="至少6位" required>
            </div>
            <div class="form-group">
                <label class="form-label">确认新密码</label>
                <input type="password" name="confirm_password" class="form-input" required>
            </div>
            <button type="submit" class="btn btn-primary">修改密码</button>
        </form>
    </div>
</div>

<!-- System Info -->
<div class="section-card">
    <div class="section-card-header">
        <div class="section-card-title">系统信息</div>
    </div>
    <div class="section-card-body">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
            <?php
            $dbSize = file_exists(__DIR__ . '/../../../data/admin.db') ? filesize(__DIR__ . '/../../../data/admin.db') : 0;
            $userCount = DB::count("SELECT COUNT(*) FROM users");
            $betCount = DB::count("SELECT COUNT(*) FROM bets");
            $todayBet = DB::count("SELECT COUNT(*) FROM bets WHERE created_at >= ?", [strtotime('today')]);
            ?>
            <div style="background:rgba(128,128,128,0.06);border-radius:8px;padding:12px">
                <div class="text-sm text-muted">数据库大小</div>
                <div style="font-weight:600;font-size:14px;"><?= $dbSize > 0 ? round($dbSize/1024, 1).' KB' : '未初始化' ?></div>
            </div>
            <div style="background:rgba(128,128,128,0.06);border-radius:8px;padding:12px">
                <div class="text-sm text-muted">用户总数</div>
                <div style="font-weight:600;font-size:14px;"><?= number_format($userCount) ?></div>
            </div>
            <div style="background:rgba(128,128,128,0.06);border-radius:8px;padding:12px">
                <div class="text-sm text-muted">下注总记录</div>
                <div style="font-weight:600;font-size:14px;"><?= number_format($betCount) ?></div>
            </div>
            <div style="background:rgba(128,128,128,0.06);border-radius:8px;padding:12px">
                <div class="text-sm text-muted">今日下注</div>
                <div style="font-weight:600;font-size:14px;"><?= number_format($todayBet) ?></div>
            </div>
        </div>
    </div>
</div>
</div>

<script>
function toggleKey() {
    const input = document.getElementById('secret-key-display');
    const btn = document.getElementById('toggle-btn');
    if (input.type === 'password') {
        input.type = 'text';
        btn.textContent = '隐藏';
    } else {
        input.type = 'password';
        btn.textContent = '显示';
    }
}

function submitForm(url, formId, successMsg) {
    const form = document.getElementById(formId);
    const data = new FormData(form);
    fetch(url || window.location.pathname, {method:'POST', body:data, headers:{'X-Requested-With':'XMLHttpRequest'}})
        .then(r=>r.json())
        .then(d=>{
            if(d.ok) {
                showToast(d.msg || successMsg || '操作成功');
                if(d.key) document.getElementById('secret-key-display').value = d.key;
            } else {
                showToast(d.msg || '操作失败', 'error');
            }
        })
        .catch(()=>showToast('请求失败','error'));
}

document.getElementById('bot-url-form').addEventListener('submit', function(e){ e.preventDefault(); submitForm('/config', 'bot-url-form', 'Bot URL 已保存'); });
document.getElementById('password-form').addEventListener('submit', function(e){ e.preventDefault(); submitForm('/config', 'password-form', '密码已修改'); });

function regenerateKey() {
    if (!confirm('确定要重新生成密钥吗？机器人需要同步更新配置才能正常工作。')) return;
    const form = new FormData();
    form.append('action', 'regenerate_key');
    fetch('/config', {method:'POST', body:form, headers:{'X-Requested-With':'XMLHttpRequest'}})
        .then(r=>r.json())
        .then(d=>{
            if(d.ok) {
                document.getElementById('secret-key-display').value = d.key;
                document.getElementById('secret-key-display').type = 'text';
                document.getElementById('toggle-btn').textContent = '隐藏';
                showToast('新密钥: ' + d.key);
            } else {
                showToast(d.msg || '失败', 'error');
            }
        })
        .catch(()=>showToast('请求失败','error'));
}
</script>
