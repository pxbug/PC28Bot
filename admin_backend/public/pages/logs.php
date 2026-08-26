<?php
$searchUid = trim($_GET['search_uid'] ?? '');
$searchAction = trim($_GET['search_action'] ?? '');
$page = max(1, intval($_GET['p'] ?? 1));
$limit = 50;
$offset = ($page - 1) * $limit;

$where = [];
$params = [];
if ($searchUid) { $where[] = "uid LIKE ?"; $params[] = '%' . $searchUid . '%'; }
if ($searchAction) { $where[] = "action = ?"; $params[] = $searchAction; }
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$total = $pdo->query("SELECT COUNT(*) as cnt FROM " . table('balance_log') . " $whereSql")->fetch()['cnt'];
$stmt = $pdo->prepare("SELECT * FROM " . table('balance_log') . " $whereSql ORDER BY created_at DESC LIMIT $offset, $limit");
$stmt->execute($params);
$logs = $stmt->fetchAll();

$totalPages = ceil($total / $limit);
$actionMap = ['recharge' => '人工充值', 'withdraw' => '人工提现', 'bet' => '下注', 'settle' => '结算', 'rebate' => '返水'];
?>
<style>
    .toolbar { display: flex; gap: 10px; margin-bottom: 16px; align-items: center; }
    .toolbar input, .toolbar select { padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px; font-size: 13px; }
    .toolbar button { padding: 8px 16px; background: #667eea; color: #fff; border: none; border-radius: 4px; cursor: pointer; font-size: 13px; }
    table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 8px; overflow: hidden; }
    th { background: #f8f9fa; padding: 12px 10px; text-align: left; font-size: 13px; color: #555; }
    td { padding: 10px; border-bottom: 1px solid #f0f0f0; font-size: 13px; }
    tr:hover { background: #fafafa; }
    .tag { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 12px; }
    .tag.green { background: #d4edda; color: #155724; }
    .tag.blue { background: #d1ecf1; color: #0c5460; }
    .tag.yellow { background: #fff3cd; color: #856404; }
    .tag.gray { background: #e9ecef; color: #495057; }
    .positive { color: #28a745; font-weight: 600; }
    .negative { color: #dc3545; font-weight: 600; }
    .pagination { display: flex; gap: 4px; margin-top: 16px; }
    .pagination a, .pagination span { padding: 6px 12px; border: 1px solid #ddd; border-radius: 4px; font-size: 13px; }
    .pagination a:hover { background: #f0f0f0; }
    .pagination .current { background: #667eea; color: #fff; border-color: #667eea; }
    .pagination .disabled { color: #ccc; pointer-events: none; }
</style>
<div style="background:#fff;padding:20px;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,0.05);">
    <h2 style="font-size:18px;margin-bottom:16px;">📋 余额流水</h2>
    <div class="toolbar">
        <form method="GET" style="display:flex;gap:8px;">
            <input type="hidden" name="page" value="logs">
            <input type="text" name="search_uid" placeholder="用户UID" value="<?= htmlspecialchars($searchUid) ?>" style="width:150px;">
            <select name="search_action">
                <option value="">全部类型</option>
                <?php foreach ($actionMap as $k => $v): ?>
                    <option value="<?= $k ?>" <?= $searchAction === $k ? 'selected' : '' ?>><?= $v ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit">🔍 搜索</button>
        </form>
        <span style="margin-left:auto;color:#888;font-size:13px;">共 <?= number_format($total) ?> 条记录</span>
    </div>
    <table>
        <thead>
            <tr>
                <th>用户UID</th>
                <th>类型</th>
                <th>金额</th>
                <th>变动前</th>
                <th>变动后</th>
                <th>操作人</th>
                <th>备注</th>
                <th>期号</th>
                <th>时间</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($logs as $l): ?>
            <?php
            $tagClass = match($l['action']) {
                'recharge' => 'green',
                'withdraw' => 'blue',
                'settle' => 'green',
                default => 'gray',
            };
            ?>
            <tr>
                <td><?= htmlspecialchars($l['uid']) ?></td>
                <td><span class="tag <?= $tagClass ?>"><?= $actionMap[$l['action']] ?? $l['action'] ?></span></td>
                <td class="<?= $l['amount']>=0?'positive':'negative' ?>"><?= $l['amount']>=0?'+':'' ?><?= number_format($l['amount'], 2) ?></td>
                <td><?= number_format($l['balance_before'], 2) ?></td>
                <td><?= number_format($l['balance_after'], 2) ?></td>
                <td><?= htmlspecialchars($l['operator_name']) ?></td>
                <td><?= htmlspecialchars($l['note']) ?></td>
                <td><?= htmlspecialchars($l['issue']) ?></td>
                <td><?= substr($l['created_at'], 0, 16) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($logs)): ?>
            <tr><td colspan="9" style="text-align:center;color:#999;">暂无记录</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
    <div class="pagination">
        <?php if ($page > 1): ?>
            <a href="?page=logs&search_uid=<?= urlencode($searchUid) ?>&search_action=<?= urlencode($searchAction) ?>&p=<?= $page - 1 ?>">上一页</a>
        <?php else: ?>
            <span class="disabled">上一页</span>
        <?php endif; ?>
        <span class="current"><?= $page ?>/<?= $totalPages ?></span>
        <?php if ($page < $totalPages): ?>
            <a href="?page=logs&search_uid=<?= urlencode($searchUid) ?>&search_action=<?= urlencode($searchAction) ?>&p=<?= $page + 1 ?>">下一页</a>
        <?php else: ?>
            <span class="disabled">下一页</span>
        <?php endif; ?>
    </div>
</div>
