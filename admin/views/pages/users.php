<?php
/**
 * 用户管理页面
 */
use App\Db;
use App\Helper;
use App\Auth;

// 分页
$page  = max(1, (int)($_GET['p'] ?? 1));
$limit = 20;
$offset = ($page - 1) * $limit;
$search = trim($_GET['search'] ?? '');
$status = $_GET['status'] ?? '';

// 统计
$where = [];
$params = [];
if ($search !== '') {
    $where[] = '(uid LIKE :s OR nickname LIKE :s)';
    $params[':s'] = "%{$search}%";
}
if ($status !== '') {
    $where[] = 'status = :status';
    $params[':status'] = (int)$status;
}
$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$total = (int)(Db::fetch(
    "SELECT COUNT(*) as c FROM bot_users {$whereSQL}",
    $params
)['c'] ?? 0);
$totalPages = max(1, (int)ceil($total / $limit));

$rows = Db::fetchAll(
    "SELECT * FROM bot_users {$whereSQL} ORDER BY id DESC LIMIT {$limit} OFFSET {$offset}",
    $params
);

// 提交充值/提现
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $uid = trim($_POST['uid'] ?? '');
    $amount = (float)($_POST['amount'] ?? 0);
    $remark = trim($_POST['remark'] ?? '');
    $operator = Auth::user()['username'] ?? 'admin';

    if ($uid && $amount > 0) {
        $user = Db::fetch('SELECT * FROM bot_users WHERE uid = ?', [$uid]);
        if ($user) {
            if ($_POST['action'] === 'recharge') {
                Db::begin();
                try {
                    Db::execute(
                        'UPDATE bot_users SET balance = balance + ?, total_recharge = total_recharge + ? WHERE id = ?',
                        [$amount, $amount, (int)$user['id']]
                    );
                    Db::insert('bot_recharges', [
                        'user_id'  => (int)$user['id'],
                        'amount'   => $amount,
                        'remark'   => $remark,
                        'operator' => $operator,
                    ]);
                    Db::commit();
                    Helper::flash('toast', "已为用户 {$uid} 充值 {$amount} 元");
                } catch (\Exception $e) {
                    Db::rollback();
                    Helper::flash('toast', '充值失败：' . $e->getMessage());
                }
            } elseif ($_POST['action'] === 'withdraw') {
                if ((float)$user['balance'] < $amount) {
                    Helper::flash('toast', '余额不足');
                } else {
                    Db::begin();
                    try {
                        Db::execute(
                            'UPDATE bot_users SET balance = balance - ?, total_withdraw = total_withdraw + ? WHERE id = ?',
                            [$amount, $amount, (int)$user['id']]
                        );
                        Db::insert('bot_withdraws', [
                            'user_id'  => (int)$user['id'],
                            'amount'   => $amount,
                            'remark'   => $remark,
                            'operator' => $operator,
                        ]);
                        Db::commit();
                        Helper::flash('toast', "已为用户 {$uid} 提现 {$amount} 元");
                    } catch (\Exception $e) {
                        Db::rollback();
                        Helper::flash('toast', '提现失败：' . $e->getMessage());
                    }
                }
            } elseif ($_POST['action'] === 'toggle_status') {
                $newStatus = $user['status'] ? 0 : 1;
                Db::update('bot_users', ['status' => $newStatus], 'id = :id', ['id' => $user['id']]);
                Helper::flash('toast', $newStatus ? '已恢复用户 ' . $uid : '已封禁用户 ' . $uid);
            }
        }
    }
    Response::redirect('/index.php?page=users' . ($search ? "&search=" . urlencode($search) : '') . ($status ? "&status={$status}" : ''));
}

// 构建分页URL
$pagerBase = '/index.php?page=users' . ($search ? '&search=' . urlencode($search) : '') . ($status ? "&status={$status}" : '');
?>

<!-- 搜索栏 -->
<form method="GET" class="flex gap-3 items-center" style="margin-bottom:20px;flex-wrap:wrap">
    <input type="hidden" name="page" value="users">
    <input type="text" name="search" class="input" placeholder="搜索 UID / 昵称…" value="<?= Helper::h($search) ?>" style="max-width:260px">
    <select name="status" class="input" style="width:120px">
        <option value="">全部状态</option>
        <option value="1" <?= $status==='1'?'selected':'' ?>>正常</option>
        <option value="0" <?= $status==='0'?'selected':'' ?>>已封禁</option>
    </select>
    <button type="submit" class="btn btn-primary">
        <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
        搜索
    </button>
    <?php if ($search || $status): ?>
    <a href="/index.php?page=users" class="btn btn-secondary">重置</a>
    <?php endif; ?>
    <span class="text-sm text-muted" style="margin-left:auto">共 <?= number_format($total) ?> 条</span>
</form>

<!-- 表格 -->
<div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th>UID</th>
                <th>昵称</th>
                <th>余额</th>
                <th>累计充值</th>
                <th>累计提现</th>
                <th>累计下注</th>
                <th>累计反水</th>
                <th>状态</th>
                <th>注册时间</th>
                <th>操作</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($rows)): ?>
            <tr><td colspan="10" class="text-center text-muted" style="padding:40px">暂无数据</td></tr>
            <?php else: foreach ($rows as $row): ?>
            <tr>
                <td><code style="font-size:13px"><?= Helper::h($row['uid']) ?></code></td>
                <td class="truncate" style="max-width:100px" title="<?= Helper::h($row['nickname']) ?>"><?= Helper::h($row['nickname']) ?></td>
                <td class="text-right" style="font-weight:600<?= (float)$row['balance'] < 0 ? ';color:var(--red)' : '' ?>">
                    <?= Helper::money($row['balance']) ?>
                </td>
                <td class="text-right text-muted"><?= Helper::money($row['total_recharge']) ?></td>
                <td class="text-right text-muted"><?= Helper::money($row['total_withdraw']) ?></td>
                <td class="text-right text-muted"><?= Helper::money($row['total_bet']) ?></td>
                <td class="text-right text-muted"><?= Helper::money($row['total_rebate']) ?></td>
                <td>
                    <span class="badge <?= $row['status'] ? 'badge-green' : 'badge-red' ?>">
                        <?= $row['status'] ? '正常' : '已封禁' ?>
                    </span>
                </td>
                <td class="text-sm text-muted"><?= Helper::dt($row['created_at']) ?></td>
                <td>
                    <div class="flex gap-2">
                        <button class="btn btn-ghost btn-sm" onclick="openRecharge('<?= Helper::h($row['uid']) ?>')">充值</button>
                        <button class="btn btn-ghost btn-sm" onclick="openWithdraw('<?= Helper::h($row['uid']) ?>')">提现</button>
                        <button class="btn btn-ghost btn-sm <?= $row['status'] ? 'text-red' : 'text-green' ?>"
                                onclick="toggleStatus('<?= Helper::h($row['uid']) ?>')">
                            <?= $row['status'] ? '封禁' : '恢复' ?>
                        </button>
                    </div>
                </td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<!-- 分页 -->
<?php if ($totalPages > 1): ?>
<div class="pagination">
    <?php
    $prev = $page > 1 ? "<a href=\"{$pagerBase}&p=" . ($page-1) . "\">‹</a>" : '<span class="disabled">‹</span>';
    $next = $page < $totalPages ? "<a href=\"{$pagerBase}&p=" . ($page+1) . "\">›</a>" : '<span class="disabled">›</span>';
    echo $prev;
    $pages = [];
    for ($i = 1; $i <= $totalPages; $i++) {
        if ($i === 1 || $i === $totalPages || ($i >= $page - 2 && $i <= $page + 2)) {
            $pages[] = $i === $page ? "<span class=\"current\">{$i}</span>" : "<a href=\"{$pagerBase}&p={$i}\">{$i}</a>";
        } elseif (end($pages) !== '…') {
            $pages[] = '…';
        }
    }
    echo implode('', $pages);
    echo $next;
    ?>
</div>
<?php endif; ?>

<!-- 充值模态框 -->
<div class="modal-overlay" id="modal-recharge">
    <div class="modal">
        <div class="modal-header">
            <span class="modal-title">为用户充值</span>
            <button class="modal-close" data-modal-close="modal-recharge">×</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="recharge">
            <input type="hidden" name="uid" id="recharge-uid">
            <div class="modal-body">
                <div class="input-group">
                    <label class="input-label">用户 UID</label>
                    <input type="text" id="recharge-uid-display" class="input" readonly>
                </div>
                <div class="input-group">
                    <label class="input-label">充值金额（元）</label>
                    <input type="number" name="amount" class="input" placeholder="输入金额" min="0.01" step="0.01" required>
                </div>
                <div class="input-group">
                    <label class="input-label">备注（可选）</label>
                    <input type="text" name="remark" class="input" placeholder="如：活动奖励">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-modal-close="modal-recharge">取消</button>
                <button type="submit" class="btn btn-success">确认充值</button>
            </div>
        </form>
    </div>
</div>

<!-- 提现模态框 -->
<div class="modal-overlay" id="modal-withdraw">
    <div class="modal">
        <div class="modal-header">
            <span class="modal-title">为用户提现</span>
            <button class="modal-close" data-modal-close="modal-withdraw">×</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="withdraw">
            <input type="hidden" name="uid" id="withdraw-uid">
            <div class="modal-body">
                <div class="input-group">
                    <label class="input-label">用户 UID</label>
                    <input type="text" id="withdraw-uid-display" class="input" readonly>
                </div>
                <div class="input-group">
                    <label class="input-label">提现金额（元）</label>
                    <input type="number" name="amount" class="input" placeholder="输入金额" min="0.01" step="0.01" required>
                </div>
                <div class="input-group">
                    <label class="input-label">备注（可选）</label>
                    <input type="text" name="remark" class="input" placeholder="如：用户申请提现">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-modal-close="modal-withdraw">取消</button>
                <button type="submit" class="btn btn-danger">确认提现</button>
            </div>
        </form>
    </div>
</div>

<script>
function openRecharge(uid) {
    document.getElementById('recharge-uid').value = uid;
    document.getElementById('recharge-uid-display').value = uid;
    modalOpen('modal-recharge');
}
function openWithdraw(uid) {
    document.getElementById('withdraw-uid').value = uid;
    document.getElementById('withdraw-uid-display').value = uid;
    modalOpen('modal-withdraw');
}
async function toggleStatus(uid) {
    if (!await confirm('确定要切换该用户状态吗？')) return;
    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = `
        <input name="action" value="toggle_status">
        <input name="uid" value="${uid}">
    `;
    document.body.appendChild(form);
    form.submit();
}
</script>