<?php
/**
 * Lottery history + manual entry
 */

$pageTitle = '开奖管理';
$breadcrumb = [['开奖管理', '/lottery']];

$period = trim($_GET['period'] ?? '');
$page = max(1, intval($_GET['p'] ?? 1));
$perPage = 30;

$whereSql = '';
$args = [];
if ($period !== '') {
    $whereSql = "WHERE period = ?";
    $args[] = $period;
}

$total = DB::count("SELECT COUNT(*) FROM lottery_history $whereSql", $args);
$totalPages = max(1, ceil($total / $perPage));
$offset = ($page - 1) * $perPage;

$history = DB::fetchAll(
    "SELECT * FROM lottery_history $whereSql ORDER BY id DESC LIMIT $perPage OFFSET $offset",
    $args
);

// Stats
$totalRecords = DB::count("SELECT COUNT(*) FROM lottery_history");
$lastRecord = DB::fetch("SELECT * FROM lottery_history ORDER BY id DESC LIMIT 1");

// Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    if ($action === 'manual_add') {
        $periodStr = trim($_POST['period'] ?? '');
        $numbersRaw = trim($_POST['numbers'] ?? '');
        if (!$periodStr || !$numbersRaw) {
            echo json_encode(['ok'=>false, 'msg'=>'期号和开奖号码不能为空']); exit;
        }
        // Parse numbers (comma/space separated)
        $nums = array_filter(array_map('intval', preg_split('/[,\s]+/', $numbersRaw)));
        if (count($nums) < 3) {
            echo json_encode(['ok'=>false, 'msg'=>'请输入至少3个数字']); exit;
        }
        $nums = array_slice($nums, 0, 3);
        $total = array_sum($nums);
        $size = $total >= 14 ? '大' : '小';
        $oddEven = $total % 2 === 0 ? '双' : '单';

        $existing = DB::fetch("SELECT id FROM lottery_history WHERE period=?", [$periodStr]);
        if ($existing) {
            DB::update('lottery_history', [
                'numbers'=>json_encode($nums), 'total'=>$total, 'size'=>$size, 'odd_even'=>$oddEven
            ], 'period=?', [$periodStr]);
            $msg = '开奖结果已更新';
        } else {
            DB::insert('lottery_history', [
                'period'=>$periodStr, 'numbers'=>json_encode($nums),
                'total'=>$total, 'size'=>$size, 'odd_even'=>$oddEven
            ]);
            $msg = '开奖记录已添加';
        }

        DB::insert('operations_log', [
            'admin_id'=>Auth::id(), 'action'=>'manual_lottery',
            'detail'=>json_encode(['period'=>$periodStr, 'numbers'=>$nums, 'total'=>$total])
        ]);
        echo json_encode(['ok'=>true, 'msg'=>$msg]);
    } else {
        echo json_encode(['ok'=>false, 'msg'=>'未知操作']);
    }
    exit;
}
?>

<!-- Stats row -->
<div class="kpi-grid" style="margin-bottom:20px">
    <div class="kpi-card">
        <div class="kpi-label"><span class="icon">📋</span> 总记录数</div>
        <div class="kpi-value"><?= number_format($totalRecords) ?></div>
    </div>
    <?php if ($lastRecord): $nums = json_decode($lastRecord['numbers'] ?? '[]', true); ?>
    <div class="kpi-card">
        <div class="kpi-label"><span class="icon">🔢</span> 最新一期</div>
        <div class="kpi-value font-mono" style="font-size:20px"><?= htmlspecialchars($lastRecord['period']) ?></div>
        <div class="kpi-delta neutral">
            <?= implode(' + ', $nums) ?> = <?= $lastRecord['total'] ?>
            &nbsp;|&nbsp; 大小: <?= $lastRecord['size'] ?> &nbsp;|&nbsp; 单双: <?= $lastRecord['odd_even'] ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Manual add -->
<div class="section-card">
    <div class="section-card-header">
        <div class="section-card-title">手动添加开奖记录</div>
    </div>
    <div class="section-card-body">
        <form id="lottery-form" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
            <div class="form-group" style="margin-bottom:0">
                <label class="form-label">期号</label>
                <input type="text" name="period" class="form-input" placeholder="例: 3474065" required>
            </div>
            <div class="form-group" style="margin-bottom:0">
                <label class="form-label">开奖号码（3个数字，用空格或逗号分隔）</label>
                <input type="text" name="numbers" class="form-input" placeholder="例: 5 12 8" required>
            </div>
            <input type="hidden" name="action" value="manual_add">
            <button type="submit" class="btn btn-primary">添加 / 更新</button>
        </form>
    </div>
</div>

<div class="filter-bar">
    <form method="GET" action="?page=lottery" style="display:flex;gap:8px;">
        <input type="text" name="period" value="<?= htmlspecialchars($period) ?>" class="form-input" placeholder="搜索期号" style="width:160px">
        <button type="submit" class="btn btn-secondary">搜索</button>
    </form>
    <div style="color:var(--text-secondary);font-size:13px;">共 <?= number_format($total) ?> 条</div>
</div>

<div class="data-table-wrap">
    <table class="data-table">
        <thead>
            <tr>
                <th>ID</th><th>期号</th><th>开奖号码</th><th class="text-right">总和</th>
                <th>大小</th><th>单双</th><th>时间</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($history as $h): $nums = json_decode($h['numbers'] ?? '[]', true); ?>
                <tr>
                    <td class="font-mono"><?= $h['id'] ?></td>
                    <td class="font-mono"><?= htmlspecialchars($h['period']) ?></td>
                    <td>
                        <div style="display:flex;gap:4px;align-items:center;">
                            <?php foreach ((array)$nums as $n): ?>
                                <span style="width:24px;height:24px;background:var(--accent-subtle);border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:12px;font-weight:600;color:var(--accent);"><?= $n ?></span>
                            <?php endforeach; ?>
                            <span style="color:var(--text-secondary);margin-left:4px;">=</span>
                            <strong style="font-size:15px;"><?= $h['total'] ?></strong>
                        </div>
                    </td>
                    <td class="text-right" style="font-weight:600"><?= $h['total'] ?></td>
                    <td>
                        <span class="badge <?= $h['size']==='大'?'badge-success':'badge-danger' ?>"><?= $h['size'] ?></span>
                    </td>
                    <td>
                        <span class="badge <?= $h['odd_even']==='单'?'badge-purple':'badge-info' ?>"><?= $h['odd_even'] ?></span>
                    </td>
                    <td class="text-sm text-muted"><?= date('Y-m-d H:i', $h['created_at']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($history)): ?>
                <tr><td colspan="7" class="text-center text-muted" style="padding:40px"><div class="empty-state-icon">🔔</div><div class="empty-state-title">暂无开奖记录</div></td></tr>
            <?php endif; ?>
        </tbody>
    </table>
    <?php if ($totalPages > 1): ?>
        <div class="pagination" id="pagination"></div>
        <script>renderPagination(document.getElementById('pagination'), <?= $page ?>, <?= $totalPages ?>, 'goPage'); function goPage(p){ const u=new URL(location); u.searchParams.set('p',p); location=u; }</script>
    <?php endif; ?>
</div>

<script>
document.getElementById('lottery-form').addEventListener('submit', function(e) {
    e.preventDefault();
    const data = new FormData(this);
    fetch('/lottery', {method:'POST', body:data, headers:{'X-Requested-With':'XMLHttpRequest'}})
        .then(r=>r.json())
        .then(d=>{ d.ok ? (showToast(d.msg), setTimeout(()=>location.reload(),800)) : showToast(d.msg||'失败','error'); })
        .catch(()=>showToast('请求失败','error'));
});
</script>
