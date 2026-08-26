<?php
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $uid = trim($_POST['uid'] ?? '');
    $amount = floatval($_POST['amount'] ?? 0);
    $note = trim($_POST['note'] ?? '');

    if ($uid === '') {
        $error = '用户UID不能为空';
    } elseif (!in_array($action, ['recharge', 'withdraw'])) {
        $error = '操作类型错误';
    } elseif ($amount <= 0) {
        $error = '金额必须大于0';
    } else {
        $pdo = db();
        $stmt = $pdo->prepare("SELECT * FROM " . table('user') . " WHERE uid = ? LIMIT 1");
        $stmt->execute([$uid]);
        $user = $stmt->fetch();
        if (!$user) {
            $error = '用户不存在';
        } else {
            $pdo->beginTransaction();
            try {
                $balanceBefore = $user['balance'];
                if ($action === 'recharge') {
                    $newBalance = bcadd($user['balance'], $amount, 2);
                    $logAmount = $amount;
                    $actionLabel = '人工充值';
                } else {
                    if (bccomp($user['balance'], $amount, 2) < 0) {
                        throw new Exception('余额不足，当前余额：' . $user['balance']);
                    }
                    $newBalance = bcsub($user['balance'], $amount, 2);
                    $logAmount = -$amount;
                    $actionLabel = '人工提现';
                }
                $stmt = $pdo->prepare("UPDATE " . table('user') . " SET balance = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$newBalance, $user['id']]);
                $stmt = $pdo->prepare("INSERT INTO " . table('balance_log') . " (uid, action, amount, balance_before, balance_after, operator_id, operator_name, note, issue, created_at) VALUES (?, ?, ?, ?, ?, 0, 'Admin', ?, '', NOW())");
                $stmt->execute([$uid, $action, $logAmount, $balanceBefore, $newBalance, $note ?: $actionLabel]);
                $pdo->commit();
                $success = $actionLabel . '成功，当前余额：' . number_format($newBalance, 2) . ' 元';
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = $e->getMessage();
            }
        }
    }
}

// 搜索
$searchUid = trim($_GET['search_uid'] ?? '');
$users = [];
if ($searchUid) {
    $stmt = $pdo->prepare("SELECT * FROM " . table('user') . " WHERE uid LIKE ? ORDER BY created_at DESC LIMIT 50");
    $stmt->execute(['%' . $searchUid . '%']);
} else {
    $stmt = $pdo->query("SELECT * FROM " . table('user') . " ORDER BY created_at DESC LIMIT 100");
}
$users = $stmt->fetchAll();
?>
<style>
    .form-inline { display: flex; gap: 10px; margin-bottom: 20px; align-items: center; }
    .form-inline input { padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; }
    .form-inline button { padding: 8px 16px; background: #667eea; color: #fff; border: none; border-radius: 4px; cursor: pointer; }
    .form-inline button:hover { background: #5568d3; }
    .alert { padding: 12px 16px; border-radius: 4px; margin-bottom: 16px; }
    .alert.success { background: #d4edda; color: #155724; }
    .alert.error { background: #f8d7da; color: #721c24; }
    table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 8px; overflow: hidden; }
    th { background: #f8f9fa; padding: 12px 10px; text-align: left; font-size: 13px; color: #555; }
    td { padding: 12px 10px; border-bottom: 1px solid #f0f0f0; font-size: 13px; }
    tr:hover { background: #fafafa; }
    .tag { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 12px; }
    .tag.green { background: #d4edda; color: #155724; }
    .tag.red { background: #f8d7da; color: #721c24; }
    .modal { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center; }
    .modal.show { display: flex; }
    .modal-content { background: #fff; border-radius: 8px; padding: 24px; min-width: 400px; }
    .modal-content h3 { margin-bottom: 16px; }
    .form-group { margin-bottom: 12px; }
    .form-group label { display: block; margin-bottom: 6px; font-size: 13px; color: #555; font-weight: 600; }
    .form-group input, .form-group select { width: 100%; padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; }
    .form-actions { display: flex; gap: 10px; justify-content: flex-end; margin-top: 16px; }
    .btn { padding: 8px 16px; border: none; border-radius: 4px; cursor: pointer; font-size: 13px; }
    .btn-primary { background: #667eea; color: #fff; }
    .btn-secondary { background: #e9ecef; color: #333; }
    .btn-sm { padding: 4px 10px; font-size: 12px; }
    .btn-success { background: #28a745; color: #fff; }
    .btn-danger { background: #dc3545; color: #fff; }
    .positive { color: #28a745; font-weight: 600; }
    .negative { color: #dc3545; font-weight: 600; }
</style>
<div style="background:#fff;padding:20px;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,0.05);margin-bottom:20px;">
    <h2 style="font-size:18px;margin-bottom:16px;">💰 余额操作</h2>
    <?php if ($error): ?>
    <div class="alert error"><?= htmlspecialchars($error) ?></div>
    <?php elseif ($success): ?>
    <div class="alert success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <form method="POST" class="form-inline">
        <input type="hidden" name="action" id="form_action" value="">
        <input type="text" name="uid" id="form_uid" placeholder="用户UID" required style="width:180px;">
        <input type="number" step="0.01" min="0.01" name="amount" id="form_amount" placeholder="金额" required style="width:120px;">
        <input type="text" name="note" id="form_note" placeholder="备注（可选）" style="width:200px;">
        <button type="button" onclick="doOp('recharge')" style="background:#28a745;">💰 充值</button>
        <button type="button" onclick="doOp('withdraw')" style="background:#dc3545;">💸 提现</button>
    </form>
</div>
<div style="background:#fff;padding:20px;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,0.05);">
    <h2 style="font-size:18px;margin-bottom:16px;">👥 用户列表</h2>
    <form method="GET" class="form-inline">
        <input type="hidden" name="page" value="balance">
        <input type="text" name="search_uid" placeholder="搜索 UID 或昵称" value="<?= htmlspecialchars($searchUid) ?>" style="width:200px;">
        <button type="submit">🔍 搜索</button>
    </form>
    <table style="margin-top:16px;">
        <thead>
            <tr>
                <th>UID</th>
                <th>昵称</th>
                <th>余额</th>
                <th>状态</th>
                <th>备注</th>
                <th>注册时间</th>
                <th>操作</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($users as $u): ?>
            <tr>
                <td><?= htmlspecialchars($u['uid']) ?></td>
                <td><?= htmlspecialchars($u['nickname']) ?></td>
                <td class="<?= $u['balance']>=0?'positive':'negative' ?>"><?= number_format($u['balance'], 2) ?></td>
                <td>
                    <?php if ($u['status'] == 1): ?>
                        <span class="tag green">正常</span>
                    <?php else: ?>
                        <span class="tag red">冻结</span>
                    <?php endif; ?>
                </td>
                <td><?= htmlspecialchars($u['remark']) ?></td>
                <td><?= substr($u['created_at'], 0, 16) ?></td>
                <td>
                    <button class="btn btn-success btn-sm" onclick="fillUser('<?= htmlspecialchars($u['uid']) ?>')">充值</button>
                    <button class="btn btn-danger btn-sm" onclick="fillUser('<?= htmlspecialchars($u['uid']) ?>')">提现</button>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($users)): ?>
            <tr><td colspan="7" style="text-align:center;color:#999;">暂无用户</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
<script>
function doOp(action) {
    var uid = document.getElementById('form_uid').value.trim();
    if (!uid) { alert('请输入用户UID'); return; }
    var amount = parseFloat(document.getElementById('form_amount').value);
    if (!amount || amount <= 0) { alert('请输入正确的金额'); return; }
    document.getElementById('form_action').value = action;
    var label = action === 'recharge' ? '确认充值' : '确认提现';
    if (confirm(label + ' ¥' + amount + ' 给用户 ' + uid + '？')) {
        document.querySelector('.form-inline').submit();
    }
}
function fillUser(uid) {
    document.getElementById('form_uid').value = uid;
    document.getElementById('form_uid').focus();
}
</script>
