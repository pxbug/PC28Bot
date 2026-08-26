<?php
/**
 * User detail page
 */
require_once __DIR__ . '/src/db.php';
require_once __DIR__ . '/src/auth.php';

DB::init(__DIR__ . '/data/admin.db');
Auth::require();

$uid = intval($param ?? 0);
$user = DB::fetch("SELECT * FROM users WHERE id = ?", [$uid]);

if (!$user) {
    echo '<div class="empty-state"><div class="empty-state-title">用户不存在</div></div>';
    exit;
}

// User's recent bets
$recentBets = DB::fetchAll(
    "SELECT b.*, u.nickname FROM bets b JOIN users u ON u.id=b.user_id WHERE b.user_id=? ORDER BY b.id DESC LIMIT 50",
    [$uid]
);

// User's deposits
$deposits = DB::fetchAll(
    "SELECT * FROM deposits WHERE user_id=? ORDER BY id DESC LIMIT 20",
    [$uid]
);

// User's withdrawals
$withdrawals = DB::fetchAll(
    "SELECT * FROM withdrawals WHERE user_id=? ORDER BY id DESC LIMIT 20",
    [$uid]
);

// Cashback history
$cashbacks = DB::fetchAll(
    "SELECT * FROM cashback_log WHERE user_id=? ORDER BY id DESC LIMIT 20",
    [$uid]
);

$pageTitle = '用户详情: ' . htmlspecialchars($user['nickname']);
$breadcrumb = [['用户管理', '/users'], [$user['nickname']]];

// Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    if ($action === 'freeze') {
        DB::update('users', ['status' => 'frozen'], 'id = ?', [$uid]);
        echo json_encode(['ok' => true]);
    } elseif ($action === 'unfreeze' || $action === 'activate') {
        DB::update('users', ['status' => 'active'], 'id = ?', [$uid]);
        echo json_encode(['ok' => true]);
    } elseif ($action === 'ban') {
        DB::update('users', ['status' => 'banned'], 'id = ?', [$uid]);
        echo json_encode(['ok' => true]);
    } elseif ($action === 'adjust_balance') {
        $amount = floatval($_POST['amount'] ?? 0);
        $note = trim($_POST['note'] ?? '');
        $newBal = $user['balance'] + $amount;
        if ($newBal < 0) { echo json_encode(['ok'=>false, 'msg'=>'余额不足']); exit; }
        DB::update('users', ['balance'=>$newBal, 'updated_at'=>time()], 'id=?', [$uid]);
        DB::insert('operations_log', [
            'admin_id'=>Auth::id(), 'action'=>'adjust_balance',
            'target_type'=>'user', 'target_id'=>$uid,
            'detail'=>json_encode(['amount'=>$amount,'new_balance'=>$newBal,'note'=>$note])
        ]);
        echo json_encode(['ok'=>true]);
    } else {
        echo json_encode(['ok'=>false, 'msg'=>'未知操作']);
    }
    exit;
}
?>

<style>
.user-profile-card {
    background: var(--bg-elevated);
    border-radius: var(--radius-md);
    box-shadow: var(--shadow-sm);
    border: 0.5px solid rgba(128,128,128,0.1);
    padding: 24px;
    margin-bottom: 20px;
    display: flex;
    align-items: flex-start;
    gap: 20px;
}
.user-avatar-lg {
    width: 64px; height: 64px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--accent), #5856d6);
    display: flex; align-items: center; justify-content: center;
    color: white; font-size: 28px; font-weight: 700;
    flex-shrink: 0;
}
.user-info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
    gap: 16px;
    flex: 1;
}
.user-stat-item {}
.user-stat-label { font-size: 11.5px; color: var(--text-tertiary); margin-bottom: 3px; }
.user-stat-value { font-size: 15px; font-weight: 600; color: var(--text-primary); }
</style>

<!-- User profile -->
<div class="user-profile-card">
    <div class="user-avatar-lg"><?= mb_substr($user['nickname'], 0, 1) ?></div>
    <div style="flex:1">
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px;">
            <div>
                <div style="font-family:var(--font-display);font-size:20px;font-weight:700;"><?= htmlspecialchars($user['nickname']) ?></div>
                <div class="font-mono text-muted text-sm"><?= htmlspecialchars($user['openid']) ?></div>
            </div>
            <?php if ($user['status'] === 'active'): ?>
                <span class="badge badge-success">正常</span>
            <?php elseif ($user['status'] === 'frozen'): ?>
                <span class="badge badge-warning">冻结</span>
            <?php else: ?>
                <span class="badge badge-danger">封禁</span>
            <?php endif; ?>
        </div>
        <div class="user-info-grid">
            <div class="user-stat-item">
                <div class="user-stat-label">当前余额</div>
                <div class="user-stat-value <?= $user['balance'] < 0 ? 'text-danger' : '' ?>">¥<?= number_format($user['balance'], 2) ?></div>
            </div>
            <div class="user-stat-item">
                <div class="user-stat-label">累计充值</div>
                <div class="user-stat-value text-success">¥<?= number_format($user['total_deposit'], 2) ?></div>
            </div>
            <div class="user-stat-item">
                <div class="user-stat-label">累计提现</div>
                <div class="user-stat-value text-danger">¥<?= number_format($user['total_withdraw'], 2) ?></div>
            </div>
            <div class="user-stat-item">
                <div class="user-stat-label">累计下注</div>
                <div class="user-stat-value"><?= number_format($user['total_bet']) ?> 次</div>
            </div>
            <div class="user-stat-item">
                <div class="user-stat-label">累计中奖</div>
                <div class="user-stat-value text-success">¥<?= number_format($user['total_win'], 2) ?></div>
            </div>
            <div class="user-stat-item">
                <div class="user-stat-label">净盈亏</div>
                <div class="user-stat-value <?= ($user['total_win']-$user['total_bet']) >= 0 ? 'text-success' : 'text-danger' ?>">
                    <?= ($user['total_win']-$user['total_bet']) >= 0 ? '+' : '' ?>¥<?= number_format($user['total_win']-$user['total_bet'], 2) ?>
                </div>
            </div>
            <div class="user-stat-item">
                <div class="user-stat-label">注册时间</div>
                <div class="user-stat-value text-sm"><?= date('Y-m-d H:i', $user['created_at']) ?></div>
            </div>
            <div class="user-stat-item">
                <div class="user-stat-label">最后下注</div>
                <div class="user-stat-value text-sm"><?= $user['last_bet'] ? timeAgo($user['last_bet']) : '从未' ?></div>
            </div>
        </div>
        <div style="display:flex;gap:8px;margin-top:16px;flex-wrap:wrap;" id="action-buttons">
            <?php if ($user['status'] === 'active'): ?>
                <button class="btn btn-sm btn-secondary" onclick="userAction('freeze')">冻结账户</button>
                <button class="btn btn-sm btn-danger" onclick="userAction('ban')">封禁账户</button>
            <?php elseif ($user['status'] === 'frozen'): ?>
                <button class="btn btn-sm btn-primary" onclick="userAction('activate')">解除冻结</button>
                <button class="btn btn-sm btn-danger" onclick="userAction('ban')">封禁账户</button>
            <?php else: ?>
                <button class="btn btn-sm btn-primary" onclick="userAction('activate')">解除封禁</button>
            <?php endif; ?>
            <button class="btn btn-sm btn-secondary" onclick="openBalanceModal()">调整余额</button>
        </div>
    </div>
</div>

<!-- Tabs -->
<div class="tabs">
    <button class="tab active" onclick="switchTab(this, 'tab-bets')">下注记录</button>
    <button class="tab" onclick="switchTab(this, 'tab-deposits')">充值记录</button>
    <button class="tab" onclick="switchTab(this, 'tab-withdrawals')">提现记录</button>
    <button class="tab" onclick="switchTab(this, 'tab-cashback')">返水记录</button>
</div>

<!-- Bets tab -->
<div id="tab-bets">
    <div class="data-table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>ID</th><th>期号</th><th>玩法</th><th>金额</th><th>赔率</th>
                    <th>开奖</th><th>结果</th><th>盈亏</th><th>时间</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recentBets as $b): ?>
                    <tr>
                        <td class="font-mono"><?= $b['id'] ?></td>
                        <td class="font-mono"><?= htmlspecialchars($b['period']) ?></td>
                        <td><?= htmlspecialchars($b['bet_value']) ?></td>
                        <td>¥<?= number_format($b['amount'], 2) ?></td>
                        <td><?= $b['odds'] ?>x</td>
                        <td class="font-mono"><?= $b['lottery_result'] ? htmlspecialchars($b['lottery_result']) : '-' ?></td>
                        <td>
                            <?php if ($b['result'] === 'win'): ?>
                                <span class="badge badge-success">赢</span>
                            <?php elseif ($b['result'] === 'lose'): ?>
                                <span class="badge badge-danger">输</span>
                            <?php else: ?>
                                <span class="badge badge-neutral">待结算</span>
                            <?php endif; ?>
                        </td>
                        <td class="<?= $b['win_amount'] > 0 ? 'text-success' : ($b['win_amount'] < 0 ? 'text-danger' : '') ?>">
                            <?= $b['win_amount'] > 0 ? '+' : '' ?>¥<?= number_format($b['win_amount'], 2) ?>
                        </td>
                        <td class="text-sm text-muted"><?= timeAgo($b['created_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($recentBets)): ?>
                    <tr><td colspan="9" class="text-center text-muted" style="padding:32px">暂无下注记录</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Deposits tab -->
<div id="tab-deposits" class="hidden">
    <div class="data-table-wrap">
        <table class="data-table">
            <thead>
                <tr><th>ID</th><th>金额</th><th>方式</th><th>状态</th><th>备注</th><th>时间</th></tr>
            </thead>
            <tbody>
                <?php foreach ($deposits as $d): ?>
                    <tr>
                        <td class="font-mono"><?= $d['id'] ?></td>
                        <td class="text-success">+¥<?= number_format($d['amount'], 2) ?></td>
                        <td><?= htmlspecialchars($d['method']) ?></td>
                        <td>
                            <?php if ($d['status'] === 'approved'): ?><span class="badge badge-success">已处理</span>
                            <?php elseif ($d['status'] === 'rejected'): ?><span class="badge badge-danger">已拒绝</span>
                            <?php else: ?><span class="badge badge-warning">待处理</span><?php endif; ?>
                        </td>
                        <td class="text-muted text-sm"><?= htmlspecialchars($d['note'] ?? '') ?></td>
                        <td class="text-sm text-muted"><?= date('Y-m-d H:i', $d['created_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($deposits)): ?>
                    <tr><td colspan="6" class="text-center text-muted" style="padding:32px">暂无充值记录</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Withdrawals tab -->
<div id="tab-withdrawals" class="hidden">
    <div class="data-table-wrap">
        <table class="data-table">
            <thead>
                <tr><th>ID</th><th>金额</th><th>方式</th><th>状态</th><th>备注</th><th>时间</th></tr>
            </thead>
            <tbody>
                <?php foreach ($withdrawals as $w): ?>
                    <tr>
                        <td class="font-mono"><?= $w['id'] ?></td>
                        <td class="text-danger">-¥<?= number_format($w['amount'], 2) ?></td>
                        <td><?= htmlspecialchars($w['method']) ?></td>
                        <td>
                            <?php if ($w['status'] === 'approved'): ?><span class="badge badge-success">已处理</span>
                            <?php elseif ($w['status'] === 'rejected'): ?><span class="badge badge-danger">已拒绝</span>
                            <?php else: ?><span class="badge badge-warning">待处理</span><?php endif; ?>
                        </td>
                        <td class="text-muted text-sm"><?= htmlspecialchars($w['note'] ?? '') ?></td>
                        <td class="text-sm text-muted"><?= date('Y-m-d H:i', $w['created_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($withdrawals)): ?>
                    <tr><td colspan="6" class="text-center text-muted" style="padding:32px">暂无提现记录</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Cashback tab -->
<div id="tab-cashback" class="hidden">
    <div class="data-table-wrap">
        <table class="data-table">
            <thead>
                <tr><th>ID</th><th>期号</th><th>投注金额</th><th>返水金额</th><th>时间</th></tr>
            </thead>
            <tbody>
                <?php foreach ($cashbacks as $c): ?>
                    <tr>
                        <td class="font-mono"><?= $c['id'] ?></td>
                        <td class="font-mono"><?= htmlspecialchars($c['period']) ?></td>
                        <td>¥<?= number_format($c['bet_amount'], 2) ?></td>
                        <td class="text-success">+¥<?= number_format($c['cashback'], 2) ?></td>
                        <td class="text-sm text-muted"><?= date('Y-m-d H:i', $c['created_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($cashbacks)): ?>
                    <tr><td colspan="5" class="text-center text-muted" style="padding:32px">暂无返水记录</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Balance modal -->
<div class="modal-overlay" id="modal-balance">
    <div class="modal">
        <div class="modal-title">调整余额</div>
        <form id="balance-form">
            <input type="hidden" name="action" value="adjust_balance">
            <div class="form-group">
                <label class="form-label">当前余额</label>
                <div class="form-input" style="background:rgba(128,128,128,0.06)">¥<?= number_format($user['balance'], 2) ?></div>
            </div>
            <div class="form-group">
                <label class="form-label">调整金额</label>
                <input type="number" step="0.01" name="amount" class="form-input" placeholder="正数增加，负数扣减" required>
            </div>
            <div class="form-group">
                <label class="form-label">备注</label>
                <input type="text" name="note" class="form-input" placeholder="操作原因">
            </div>
        </form>
        <div class="modal-actions">
            <button class="btn btn-secondary" onclick="closeModal('balance')">取消</button>
            <button class="btn btn-primary" onclick="submitBalance()">确认</button>
        </div>
    </div>
</div>

<script>
function switchTab(btn, id) {
    document.querySelectorAll('.tab').forEach(t=>t.classList.remove('active'));
    btn.classList.add('active');
    document.querySelectorAll('[id^="tab-"]').forEach(t=>t.classList.add('hidden'));
    document.getElementById(id).classList.remove('hidden');
}

function userAction(action) {
    if (!confirm('确认执行此操作？')) return;
    fetch('/users/<?= $uid ?>', {method:'POST', body:new FormData().append('action',action),
        headers:{'X-Requested-With':'XMLHttpRequest'}})
        .then(r=>r.json())
        .then(d=>{ d.ok ? (showToast('操作成功'), setTimeout(()=>location.reload(),500)) : showToast(d.msg||'失败','error'); })
        .catch(()=>showToast('请求失败','error'));
}

function openBalanceModal() { openModal('balance'); }

function submitBalance() {
    fetch('/users/<?= $uid ?>', {method:'POST', body:new FormData(document.getElementById('balance-form')),
        headers:{'X-Requested-With':'XMLHttpRequest'}})
        .then(r=>r.json())
        .then(d=>{
            if(d.ok) { closeModal('balance'); showToast('余额已调整'); setTimeout(()=>location.reload(),500); }
            else showToast(d.msg||'失败','error');
        })
        .catch(()=>showToast('请求失败','error'));
}
</script>
