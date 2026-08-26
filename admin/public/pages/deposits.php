<?php
/**
 * Deposit records + processing
 */
require_once __DIR__ . '/src/db.php';
require_once __DIR__ . '/src/auth.php';

DB::init(__DIR__ . '/data/admin.db');
Auth::require();

$pageTitle = '充值管理';
$breadcrumb = [['充值管理', '/deposits']];

$status = $_GET['status'] ?? 'pending';
$page = max(1, intval($_GET['p'] ?? 1));
$perPage = 20;

$whereSql = "WHERE d.status = ?";
$args = [$status];
$total = DB::count("SELECT COUNT(*) FROM deposits d JOIN users u ON u.id=d.user_id $whereSql", $args);
$totalPages = max(1, ceil($total / $perPage));
$offset = ($page - 1) * $perPage;

$deposits = DB::fetchAll(
    "SELECT d.*, u.nickname, u.openid, u.balance as user_balance
     FROM deposits d JOIN users u ON u.id=d.user_id
     $whereSql ORDER BY d.id DESC LIMIT $perPage OFFSET $offset",
    $args
);

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $did = intval($_POST['id'] ?? 0);
    $action = $_POST['action'] ?? '';

    $dep = DB::fetch("SELECT * FROM deposits WHERE id = ?", [$did]);
    if (!$dep) { echo json_encode(['ok'=>false, 'msg'=>'记录不存在']); exit; }

    if ($action === 'approve') {
        DB::update('deposits', ['status'=>'approved', 'admin_id'=>Auth::id(), 'processed_at'=>time()], 'id=?', [$did]);
        $user = DB::fetch("SELECT id, balance, total_deposit FROM users WHERE id=?", [$dep['user_id']]);
        $newBal = $user['balance'] + $dep['amount'];
        DB::update('users', ['balance'=>$newBal, 'total_deposit'=>$user['total_deposit'] + $dep['amount'], 'updated_at'=>time()], 'id=?', [$dep['user_id']]);
        DB::insert('operations_log', [
            'admin_id'=>Auth::id(), 'action'=>'approve_deposit',
            'target_type'=>'deposit', 'target_id'=>$did,
            'detail'=>json_encode(['user_id'=>$dep['user_id'], 'amount'=>$dep['amount'], 'new_balance'=>$newBal])
        ]);
        echo json_encode(['ok'=>true, 'msg'=>'充值已通过']);
    } elseif ($action === 'reject') {
        $note = trim($_POST['note'] ?? '拒绝充值');
        DB::update('deposits', ['status'=>'rejected', 'admin_id'=>Auth::id(), 'processed_at'=>time(), 'note'=>$note], 'id=?', [$did]);
        DB::insert('operations_log', [
            'admin_id'=>Auth::id(), 'action'=>'reject_deposit',
            'target_type'=>'deposit', 'target_id'=>$did, 'detail'=>$note
        ]);
        echo json_encode(['ok'=>true, 'msg'=>'已拒绝']);
    } elseif ($action === 'manual_add') {
        $amount = floatval($_POST['amount'] ?? 0);
        $note = trim($_POST['note'] ?? '');
        if ($amount <= 0) { echo json_encode(['ok'=>false, 'msg'=>'金额必须大于0']); exit; }
        $user = DB::fetch("SELECT id, balance, total_deposit FROM users WHERE id=?", [$_POST['user_id'] ?? 0]);
        if (!$user) { echo json_encode(['ok'=>false, 'msg'=>'用户不存在']); exit; }
        $newBal = $user['balance'] + $amount;
        DB::insert('deposits', [
            'user_id'=>$user['id'], 'amount'=>$amount, 'method'=>'manual',
            'status'=>'approved', 'note'=>$note ?: '管理员手动充值',
            'admin_id'=>Auth::id(), 'processed_at'=>time()
        ]);
        DB::update('users', ['balance'=>$newBal, 'total_deposit'=>$user['total_deposit']+$amount, 'updated_at'=>time()], 'id=?', [$user['id']]);
        DB::insert('operations_log', [
            'admin_id'=>Auth::id(), 'action'=>'manual_deposit',
            'target_type'=>'user', 'target_id'=>$user['id'],
            'detail'=>json_encode(['amount'=>$amount, 'new_balance'=>$newBal, 'note'=>$note])
        ]);
        echo json_encode(['ok'=>true, 'msg'=>'充值成功']);
    } else {
        echo json_encode(['ok'=>false, 'msg'=>'未知操作']);
    }
    exit;
}
?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
    <div class="tabs" style="margin-bottom:0">
        <?php foreach (['pending'=>'待处理','approved'=>'已通过','rejected'=>'已拒绝',''=>'全部'] as $s=>$l): ?>
            <a href="/deposits?status=<?= $s ?>" class="tab <?= $status===$s?'active':'' ?>"><?= $l ?></a>
        <?php endforeach; ?>
    </div>
    <button class="btn btn-primary" onclick="openModal('manual-add')">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        手动充值
    </button>
</div>

<div class="data-table-wrap">
    <table class="data-table">
        <thead>
            <tr>
                <th>ID</th><th>用户</th><th class="text-right">金额</th><th>方式</th><th>状态</th>
                <th>备注</th><th>时间</th><th>操作</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($deposits as $d): ?>
                <tr>
                    <td class="font-mono"><?= $d['id'] ?></td>
                    <td>
                        <a href="/users/<?= $d['user_id'] ?>"><?= htmlspecialchars($d['nickname']) ?></a>
                        <div class="text-sm text-muted">余额: ¥<?= number_format($d['user_balance'], 2) ?></div>
                    </td>
                    <td class="text-right text-success">+¥<?= number_format($d['amount'], 2) ?></td>
                    <td><?= htmlspecialchars($d['method']) ?></td>
                    <td>
                        <?php if ($d['status'] === 'approved'): ?><span class="badge badge-success">已通过</span>
                        <?php elseif ($d['status'] === 'rejected'): ?><span class="badge badge-danger">已拒绝</span>
                        <?php else: ?><span class="badge badge-warning">待处理</span><?php endif; ?>
                    </td>
                    <td class="text-sm text-muted"><?= htmlspecialchars($d['note'] ?? '-') ?></td>
                    <td class="text-sm text-muted"><?= date('Y-m-d H:i', $d['created_at']) ?></td>
                    <td>
                        <?php if ($d['status'] === 'pending'): ?>
                            <div style="display:flex;gap:4px;">
                                <button class="btn btn-sm btn-primary" onclick="processDeposit(<?= $d['id'] ?>, 'approve')">通过</button>
                                <button class="btn btn-sm btn-danger" onclick="processDeposit(<?= $d['id'] ?>, 'reject')">拒绝</button>
                            </div>
                        <?php else: ?>
                            <span class="text-muted text-sm">-</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($deposits)): ?>
                <tr><td colspan="8" class="text-center text-muted" style="padding:40px"><div class="empty-state-icon">💰</div><div class="empty-state-title">暂无充值记录</div></td></tr>
            <?php endif; ?>
        </tbody>
    </table>
    <?php if ($totalPages > 1): ?>
        <div class="pagination" id="pagination"></div>
        <script>renderPagination(document.getElementById('pagination'), <?= $page ?>, <?= $totalPages ?>, 'goPage'); function goPage(p){ const u=new URL(location); u.searchParams.set('p',p); location=u; }</script>
    <?php endif; ?>
</div>

<!-- Manual add modal -->
<div class="modal-overlay" id="modal-manual-add">
    <div class="modal">
        <div class="modal-title">手动充值</div>
        <form id="manual-add-form">
            <div class="form-group">
                <label class="form-label">用户ID</label>
                <input type="number" name="user_id" class="form-input" placeholder="输入用户ID" required>
            </div>
            <div class="form-group">
                <label class="form-label">充值金额</label>
                <input type="number" step="0.01" min="0.01" name="amount" class="form-input" placeholder="输入金额" required>
            </div>
            <div class="form-group">
                <label class="form-label">备注</label>
                <input type="text" name="note" class="form-input" placeholder="备注（可选）">
            </div>
        </form>
        <div class="modal-actions">
            <button class="btn btn-secondary" onclick="closeModal('manual-add')">取消</button>
            <button class="btn btn-primary" onclick="submitManualAdd()">确认充值</button>
        </div>
    </div>
</div>

<script>
function processDeposit(id, action) {
    if (action === 'reject') {
        const note = prompt('请输入拒绝原因:');
        if (note === null) return;
        submitApi('/deposits', {id, action, note});
    } else {
        if (!confirm('确认通过此充值？')) return;
        submitApi('/deposits', {id, action});
    }
}
function submitApi(url, data) {
    const form = new FormData();
    for (const [k,v] of Object.entries(data)) form.append(k,v);
    fetch(url, {method:'POST', body:form, headers:{'X-Requested-With':'XMLHttpRequest'}})
        .then(r=>r.json())
        .then(d=>{ d.ok ? (showToast(d.msg), setTimeout(()=>location.reload(),500)) : showToast(d.msg||'失败','error'); })
        .catch(()=>showToast('请求失败','error'));
}
function submitManualAdd() {
    const form = document.getElementById('manual-add-form');
    const data = new FormData(form);
    data.append('action', 'manual_add');
    fetch('/deposits', {method:'POST', body:data, headers:{'X-Requested-With':'XMLHttpRequest'}})
        .then(r=>r.json())
        .then(d=>{ d.ok ? (closeModal('manual-add'), showToast(d.msg), setTimeout(()=>location.reload(),500)) : showToast(d.msg||'失败','error'); })
        .catch(()=>showToast('请求失败','error'));
}
</script>
