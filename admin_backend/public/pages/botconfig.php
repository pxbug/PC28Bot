<?php
$stmt = $pdo->query("SELECT * FROM " . table('bot_config'));
$configs = $stmt->fetchAll();
?>
<style>
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
    .btn { padding: 6px 12px; border: none; border-radius: 4px; cursor: pointer; font-size: 12px; }
    .btn-danger { background: #dc3545; color: #fff; }
    code { background: #f0f0f0; padding: 2px 6px; border-radius: 4px; font-size: 12px; }
</style>
<div style="background:#fff;padding:20px;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,0.05);">
    <h2 style="font-size:18px;margin-bottom:16px;">⚙️ Bot API 配置</h2>
    <table>
        <thead>
            <tr>
                <th>App ID</th>
                <th>应用名称</th>
                <th>密钥</th>
                <th>状态</th>
                <th>创建时间</th>
                <th>操作</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($configs as $c): ?>
            <tr>
                <td><code><?= htmlspecialchars($c['app_id']) ?></code></td>
                <td><?= htmlspecialchars($c['name']) ?></td>
                <td><code><?= htmlspecialchars($c['secret_key']) ?></code></td>
                <td>
                    <?php if ($c['status'] == 1): ?>
                        <span class="tag green">启用</span>
                    <?php else: ?>
                        <span class="tag red">禁用</span>
                    <?php endif; ?>
                </td>
                <td><?= substr($c['created_at'], 0, 16) ?></td>
                <td>
                    <button class="btn btn-danger" onclick="toggleStatus(<?= $c['id'] ?>, <?= 1 - $c['status'] ?>)">
                        <?= $c['status'] == 1 ? '禁用' : '启用' ?>
                    </button>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <div style="margin-top:20px;padding:16px;background:#f8f9fa;border-radius:8px;">
        <h3 style="font-size:14px;margin-bottom:8px;">📝 机器人端配置</h3>
        <p style="font-size:13px;color:#555;line-height:1.8;">
            请将以下配置写入机器人的 <code>config/robot.config.json</code>：
        </p>
        <pre style="background:#fff;padding:12px;border-radius:4px;font-size:13px;margin-top:8px;overflow-x:auto;">"admin_api": {
    "enabled": true,
    "base_url": "http://你的服务器IP:8080/api/bot/",
    "app_id": "pc28bot",
    "secret_key": "<?= htmlspecialchars($configs[0]['secret_key'] ?? 'YOUR_SECRET_KEY') ?>",
    "timeout": 10
}</pre>
        <p style="font-size:13px;color:#888;margin-top:8px;">
            ⚠️ secret_key 必须与上方表格中的一致
        </p>
    </div>
</div>
<script>
function toggleStatus(id, status) {
    if (confirm('确定要' + (status === 1 ? '启用' : '禁用') + '此配置吗？')) {
        window.location.href = '?page=botconfig&action=toggle&id=' + id + '&status=' + status;
    }
}
</script>
