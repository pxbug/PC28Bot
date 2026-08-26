<?php
$searchUid = trim($_GET['search_uid'] ?? '');
$page = max(1, intval($_GET['p'] ?? 1));
$limit = 50;
$offset = ($page - 1) * $limit;

$where = [];
$params = [];
if ($searchUid) { $where[] = "(uid LIKE ? OR nickname LIKE ?)"; $params[] = '%' . $searchUid . '%'; $params[] = '%' . $searchUid . '%'; }
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$total = $pdo->query("SELECT COUNT(*) as cnt FROM " . table('user') . " $whereSql")->fetch()['cnt'];
$stmt = $pdo->prepare("SELECT * FROM " . table('user') . " $whereSql ORDER BY created_at DESC LIMIT $offset, $limit");
$stmt->execute($params);
$users = $stmt->fetchAll();
$totalPages = ceil($total / $limit);
?>
<style>
    .toolbar { display: flex; gap: 10px; margin-bottom: 16px; }
    .toolbar input { padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px; font-size: 13px; width: 200px; }
    .toolbar button { padding: 8px 16px; background: #667eea; color: #fff; border: none; border-radius: 4px; cursor: pointer; font-size: 13px; }
    table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 8px; overflow: hidden; }
    th { background: #f8f9fa; padding: 12px 10px; text-align: left; font-size: 13px; color: #555; }
    td { padding: 10px; border-bottom: 1px solid #f0f0f0; font-size: 13px; }
    tr:hover { background: #fafafa; }
    .tag { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 12px; }
    .tag.green { background: #d4edda; color: #155724; }
    .tag.red { background: #f8d7da; color: #721c24; }
    .btn { padding: 4px 10px; border: none; border-radius: 4px; cursor: pointer; font-size: 12px; }
    .btn-success { background: #28a745; color: #fff; }
    .btn-danger { background: #dc3545; color: #fff; }
    .positive { color: #28a745; font-weight: 600; }
    .pagination { display: flex; gap: 4px; margin-top: 16px; }
    .pagination a, .pagination span { padding: 6px 12px; border: 1px solid #ddd; border-radius: 4px; font-size: 13px; }
    .pagination a:hover { background: #f0f0f0; }
    .pagination .current { background: #667eea; color: #fff; border-color: #667eea; }
    .pagination .disabled { color: #ccc; pointer-events: none; }
</style>
<div style="background:#fff;padding:20px;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,0.05);">
    <h2 style="font-size:18px;margin-bottom:16px;">👥 用户管理</h2>
    <div class="toolbar">
        <form method="GET">
            <input type="hidden" name="page" value="users">
            <input type="text" name="search_uid" placeholder="搜索 UID 或昵称" value="<?= htmlspecialchars($searchUid) ?>">
            <button type="submit">🔍 搜索</button>
        </form>
        <span style="margin-left:auto;color:#888;font-size:13px;">共 <?= number_format($total) ?> 个用户</span>
    </div>
    <table>
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
                <td class="positive">¥<?= number_format($u['balance'], 2) ?></td>
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
                    <a href="?page=balance&search_uid=<?= urlencode($u['uid']) ?>" class="btn btn-success">充值</a>
                    <a href="?page=balance&search_uid=<?= urlencode($u['uid']) ?>" class="btn btn-danger">提现</a>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($users)): ?>
            <tr><td colspan="7" style="text-align:center;color:#999;">暂无用户</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
    <div class="pagination">
        <?php if ($page > 1): ?>
            <a href="?page=users&search_uid=<?= urlencode($searchUid) ?>&p=<?= $page - 1 ?>">上一页</a>
        <?php else: ?>
            <span class="disabled">上一页</span>
        <?php endif; ?>
        <span class="current"><?= $page ?>/<?= $totalPages ?></span>
        <?php if ($page < $totalPages): ?>
            <a href="?page=users&search_uid=<?= urlencode($searchUid) ?>&p=<?= $page + 1 ?>">下一页</a>
        <?php else: ?>
            <span class="disabled">下一页</span>
        <?php endif; ?>
    </div>
</div>
