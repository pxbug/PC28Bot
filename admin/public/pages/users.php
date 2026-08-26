<?php
/**
 * Users management — list + search + CRUD
 */
require_once __DIR__ . '/src/db.php';
require_once __DIR__ . '/src/auth.php';

DB::init(__DIR__ . '/data/admin.db');
Auth::require();

$pageTitle = '用户管理';
$breadcrumb = [['用户管理', '/users']];

// Filters
$search = trim($_GET['q'] ?? '');
$status = $_GET['status'] ?? '';
$page = max(1, intval($_GET['p'] ?? 1));
$perPage = 20;

// Build where
$where = [];
$args = [];
if ($search !== '') {
    $where[] = "(nickname LIKE ? OR openid LIKE ? OR id = ?)";
    $args[] = "%$search%";
    $args[] = "%$search%";
    $args[] = is_numeric($search) ? intval($search) : -1;
}
if ($status !== '') {
    $where[] = "status = ?";
    $args[] = $status;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Total count
$total = DB::count("SELECT COUNT(*) FROM users $whereSql", $args);
$totalPages = max(1, ceil($total / $perPage));
$offset = ($page - 1) * $perPage;

// Fetch users
$users = DB::fetchAll(
    "SELECT * FROM users $whereSql ORDER BY id DESC LIMIT $perPage OFFSET $offset",
    $args
);

// API: adjust balance
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $uid = intval($_POST['uid'] ?? 0);
    $amount = floatval($_POST['amount'] ?? 0);
    $note = trim($_POST['note'] ?? '');

    if ($uid <= 0 || $amount == 0) {
        echo json_encode(['ok'=>false, 'msg'=>'参数错误']);
        exit;
    }

    $user = DB::fetch("SELECT id, balance FROM users WHERE id = ?", [$uid]);
    if (!$user) {
        echo json_encode(['ok'=>false, 'msg'=>'用户不存在']);
        exit;
    }

    $newBalance = $user['balance'] + $amount;
    if ($newBalance < 0) {
        echo json_encode(['ok'=>false, 'msg'=>'余额不足']);
        exit;
    }

    DB::update('users', ['balance' => $newBalance, 'updated_at' => time()], 'id = ?', [$uid]);

    // Log
    DB::insert('operations_log', [
        'admin_id' => Auth::id(),
        'action' => 'adjust_balance',
        'target_type' => 'user',
        'target_id' => $uid,
        'detail' => json_encode(['amount'=>$amount, 'new_balance'=>$newBalance, 'note'=>$note]),
    ]);

    echo json_encode(['ok'=>true]);
    exit;
}
?>
<div class="filter-bar">
    <form method="GET" action="/users" style="display:flex;gap:8px;flex:1;max-width:400px;">
        <div class="search-bar" style="flex:1">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
            </svg>
            <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="搜索昵称 / OpenID / 用户ID">
        </div>
        <select name="status" class="form-input" style="width:auto" onchange="this.form.submit()">
            <option value="">全部状态</option>
            <option value="active" <?= $status==='active'?'selected':''?>>正常</option>
            <option value="frozen" <?= $status==='frozen'?'selected':''?>>冻结</option>
            <option value="banned" <?= $status==='banned'?'selected':''?>>封禁</option>
        </select>
        <button type="submit" class="btn btn-secondary">搜索</button>
    </form>
    <div style="color:var(--text-secondary);font-size:13px;">
        共 <?= number_format($total) ?> 位用户
    </div>
</div>

<div class="data-table-wrap">
    <div class="table-header">
        <div class="table-title">用户列表</div>
    </div>
    <table class="data-table">
        <thead>
            <tr>
                <th>ID</th>
                <th>用户</th>
                <th>OpenID</th>
                <th class="text-right">余额</th>
                <th class="text-right">累计充值</th>
                <th class="text-right">累计提现</th>
                <th class="text-right">累计下注</th>
                <th class="text-right">累计盈利</th>
                <th>状态</th>
                <th>注册时间</th>
                <th>操作</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($users as $u): ?>
                <tr>
                    <td class="font-mono"><?= $u['id'] ?></td>
                    <td>
                        <div style="font-weight:500;"><?= htmlspecialchars($u['nickname']) ?></div>
                    </td>
                    <td class="font-mono text-muted"><?= htmlspecialchars(substr($u['openid'], 0, 12)) ?>…</td>
                    <td class="text-right">
                        <span class="<?= $u['balance'] < 0 ? 'text-danger' : '' ?>">
                            ¥<?= number_format($u['balance'], 2) ?>
                        </span>
                    </td>
                    <td class="text-right text-success">¥<?= number_format($u['total_deposit'], 2) ?></td>
                    <td class="text-right text-danger">¥<?= number_format($u['total_withdraw'], 2) ?></td>
                    <td class="text-right"><?= number_format($u['total_bet']) ?></td>
                    <td class="text-right <?= ($u['total_win'] - $u['total_bet']) >= 0 ? 'text-success' : 'text-danger' ?>">
                        <?= ($u['total_win'] - $u['total_bet']) >= 0 ? '+' : '' ?>¥<?= number_format($u['total_win'] - $u['total_bet'], 2) ?>
                    </td>
                    <td>
                        <?php if ($u['status'] === 'active'): ?>
                            <span class="badge badge-success">正常</span>
                        <?php elseif ($u['status'] === 'frozen'): ?>
                            <span class="badge badge-warning">冻结</span>
                        <?php else: ?>
                            <span class="badge badge-danger">封禁</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-sm text-muted"><?= date('Y-m-d', $u['created_at']) ?></td>
                    <td>
                        <div style="display:flex;gap:4px;">
                            <a href="/users/<?= $u['id'] ?>" class="btn btn-sm btn-secondary">详情</a>
                            <button class="btn btn-sm btn-secondary" onclick="openBalanceModal(<?= $u['id'] ?>, '<?= htmlspecialchars($u['nickname']) ?>', <?= $u['balance'] ?>)">调账</button>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($users)): ?>
                <tr><td colspan="11" class="text-center text-muted" style="padding:40px">
                    <div class="empty-state-icon">👥</div>
                    <div class="empty-state-title">暂无用户</div>
                </td></tr>
            <?php endif; ?>
        </tbody>
    </table>
    <?php if ($totalPages > 1): ?>
        <div class="pagination" id="pagination"></div>
        <script>renderPagination(document.getElementById('pagination'), <?= $page ?>, <?= $totalPages ?>, 'goPage'); function goPage(p){ const u=new URL(location); u.searchParams.set('p',p); location=u; }</script>
    <?php endif; ?>
</div>

<!-- Balance adjust modal -->
<div class="modal-overlay" id="modal-balance">
    <div class="modal">
        <div class="modal-title">调整用户余额</div>
        <form id="balance-form">
            <input type="hidden" name="action" value="adjust">
            <input type="hidden" name="uid" id="bal-uid">
            <p style="margin-bottom:16px;color:var(--text-secondary);font-size:13px;">
                用户: <strong id="bal-nickname"></strong><br>
                当前余额: <strong id="bal-current"></strong>
            </p>
            <div class="form-group">
                <label class="form-label">调整金额（正数增加，负数扣减）</label>
                <input type="number" step="0.01" name="amount" id="bal-amount" class="form-input" placeholder="例如: 100 或 -50" required>
            </div>
            <div class="form-group">
                <label class="form-label">备注</label>
                <input type="text" name="note" class="form-input" placeholder="操作原因（可选）">
            </div>
        </form>
        <div class="modal-actions">
            <button class="btn btn-secondary" onclick="closeModal('balance')">取消</button>
            <button class="btn btn-primary" onclick="submitBalance()">确认调整</button>
        </div>
    </div>
</div>

<script>
function openBalanceModal(uid, nickname, balance) {
    document.getElementById('bal-uid').value = uid;
    document.getElementById('bal-nickname').textContent = nickname;
    document.getElementById('bal-current').textContent = '¥' + formatMoney(balance);
    document.getElementById('bal-amount').value = '';
    openModal('balance');
}

function submitBalance() {
    const form = document.getElementById('balance-form');
    const data = new FormData(form);
    fetch('/users', { method:'POST', body: data, headers:{'X-Requested-With':'XMLHttpRequest'} })
        .then(r=>r.json())
        .then(d=>{
            if(d.ok) {
                closeModal('balance');
                showToast('余额已调整');
                setTimeout(()=>location.reload(), 500);
            } else {
                showToast(d.msg || '操作失败', 'error');
            }
        })
        .catch(()=>showToast('请求失败', 'error'));
}
</script>
