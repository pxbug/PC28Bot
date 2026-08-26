<?php
/**
 * Withdrawal records + processing
 */

$pageTitle = '提现管理';
$breadcrumb = [['提现管理', '/withdrawals']];

$status = $_GET['status'] ?? 'pending';
$page = max(1, intval($_GET['p'] ?? 1));
$perPage = 20;

$whereSql = "WHERE w.status = ?";
$args = [$status];
$total = DB::count("SELECT COUNT(*) FROM withdrawals w JOIN users u ON u.id=w.user_id $whereSql", $args);
$totalPages = max(1, ceil($total / $perPage));
$offset = ($page - 1) * $perPage;

$withdrawals = DB::fetchAll(
    "SELECT w.*, u.nickname, u.openid, u.balance as user_balance
     FROM withdrawals w JOIN users u ON u.id=w.user_id
     $whereSql ORDER BY w.id DESC LIMIT $perPage OFFSET $offset",
    $args
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $wid = intval($_POST['id'] ?? 0);
    $action = $_POST['action'] ?? '';

    $w = DB::fetch("SELECT * FROM withdrawals WHERE id = ?", [$wid]);
    if (!$w) { echo json_encode(['ok'=>false, 'msg'=>'记录不存在']); exit; }

    if ($action === 'approve') {
        if ($w['amount'] > 0) {
            $user = DB::fetch("SELECT id, balance, total_withdraw FROM users WHERE id=?", [$w['user_id']]);
            if ($user['balance'] < $w['amount']) {
                echo json_encode(['ok'=>false, 'msg'=>'用户余额不足']); exit;
            }
            $newBal = $user['balance'] - $w['amount'];
            DB::update('users', ['balance'=>$newBal, 'total_withdraw'=>$user['total_withdraw']+$w['amount'], 'updated_at'=>time()], 'id=?', [$w['user_id']]);
        }
        DB::update('withdrawals', ['status'=>'approved', 'admin_id'=>Auth::id(), 'processed_at'=>time()], 'id=?', [$wid]);
        DB::insert('operations_log', [
            'admin_id'=>Auth::id(), 'action'=>'approve_withdrawal',
            'target_type'=>'withdrawal', 'target_id'=>$wid,
            'detail'=>json_encode(['user_id'=>$w['user_id'], 'amount'=>$w['amount']])
        ]);
        echo json_encode(['ok'=>true, 'msg'=>'提现已通过']);
    } elseif ($action === 'reject') {
        $note = trim($_POST['note'] ?? '拒绝提现');
        // Return balance
        $user = DB::fetch("SELECT id, balance FROM users WHERE id=?", [$w['user_id']]);
        DB::update('users', ['balance'=>$user['balance'] + $w['amount'], 'updated_at'=>time()], 'id=?', [$w['user_id']]);
        DB::update('withdrawals', ['status'=>'rejected', 'admin_id'=>Auth::id(), 'processed_at'=>time(), 'note'=>$note], 'id=?', [$wid]);
        DB::insert('operations_log', [
            'admin_id'=>Auth::id(), 'action'=>'reject_withdrawal',
            'target_type'=>'withdrawal', 'target_id'=>$wid, 'detail'=>$note
        ]);
        echo json_encode(['ok'=>true, 'msg'=>'已拒绝，余额已退回']);
    } else {
        echo json_encode(['ok'=>false, 'msg'=>'未知操作']);
    }
    exit;
}
?>
<div style="margin-bottom:16px;">
    <div class="tabs">
        <?php foreach (['pending'=>'待处理','approved'=>'已通过','rejected'=>'已拒绝',''=>'全部'] as $s=>$l): ?>
            <a href="?page=withdrawals&status=<?= $s ?>" class="tab <?= $status===$s?'active':'' ?>"><?= $l ?></a>
        <?php endforeach; ?>
    </div>
</div>

<div class="data-table-wrap">
    <table class="data-table">
        <thead>
            <tr>
                <th>ID</th><th>用户</th><th class="text-right">提现金额</th><th>方式</th><th>状态</th>
                <th>银行信息</th><th>备注</th><th>时间</th><th>操作</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($withdrawals as $w): ?>
                <tr>
                    <td class="font-mono"><?= $w['id'] ?></td>
                    <td>
                        <a href="?page=user_detail&id=<?= $w['user_id'] ?>"><?= htmlspecialchars($w['nickname']) ?></a>
                        <div class="text-sm text-muted">余额: ¥<?= number_format($w['user_balance'], 2) ?></div>
                    </td>
                    <td class="text-right text-danger">-¥<?= number_format($w['amount'], 2) ?></td>
                    <td><?= htmlspecialchars($w['method']) ?></td>
                    <td>
                        <?php if ($w['status'] === 'approved'): ?><span class="badge badge-success">已通过</span>
                        <?php elseif ($w['status'] === 'rejected'): ?><span class="badge badge-danger">已拒绝</span>
                        <?php else: ?><span class="badge badge-warning">待处理</span><?php endif; ?>
                    </td>
                    <td class="text-sm font-mono"><?= htmlspecialchars($w['bank_info'] ?? '-') ?></td>
                    <td class="text-sm text-muted"><?= htmlspecialchars($w['note'] ?? '-') ?></td>
                    <td class="text-sm text-muted"><?= date('Y-m-d H:i', $w['created_at']) ?></td>
                    <td>
                        <?php if ($w['status'] === 'pending'): ?>
                            <div style="display:flex;gap:4px;">
                                <button class="btn btn-sm btn-primary" onclick="processW(<?= $w['id'] ?>, 'approve')">通过</button>
                                <button class="btn btn-sm btn-danger" onclick="processW(<?= $w['id'] ?>, 'reject')">拒绝</button>
                            </div>
                        <?php else: ?>
                            <span class="text-muted text-sm">-</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($withdrawals)): ?>
                <tr><td colspan="9" class="text-center text-muted" style="padding:40px"><div class="empty-state-icon">🏧</div><div class="empty-state-title">暂无提现记录</div></td></tr>
            <?php endif; ?>
        </tbody>
    </table>
    <?php if ($totalPages > 1): ?>
        <div class="pagination" id="pagination"></div>
        <script>renderPagination(document.getElementById('pagination'), <?= $page ?>, <?= $totalPages ?>, 'goPage'); function goPage(p){ const u=new URL(location); u.searchParams.set('p',p); location=u; }</script>
    <?php endif; ?>
</div>

<script>
function processW(id, action) {
    if (action === 'reject') {
        const note = prompt('请输入拒绝原因（余额将退回）:');
        if (note === null) return;
        submitApi('/withdrawals', {id, action, note});
    } else {
        if (!confirm('确认通过此提现？')) return;
        submitApi('/withdrawals', {id, action});
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
</script>
