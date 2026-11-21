<?php
require_once __DIR__ . '/bootstrap.php';
$adminTitle = 'Журнал аудита';
$adminSection = 'audit';
$rows = $adminRepository->audit();
require_once __DIR__ . '/header.php';
?>
<div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Время</th><th>Действие</th><th>Сущность</th><th>Инициатор</th><th>Результат</th><th>IP</th></tr></thead><tbody><?php foreach ($rows as $row): ?><tr><td><?php echo escape($row['created_at']); ?></td><td><?php echo escape($row['action']); ?></td><td><?php echo escape($row['entity_type']); ?> <?php echo $row['entity_id'] ? '#' . (int)$row['entity_id'] : ''; ?></td><td><?php echo $row['user_id'] ? '#' . (int)$row['user_id'] : 'Система'; ?><br><small><?php echo escape($row['role'] ?: ''); ?></small></td><td><?php echo escape($row['result']); ?></td><td><?php echo escape($row['ip_address'] ?: '—'); ?></td></tr><?php endforeach; ?></tbody></table></div>
<?php require_once __DIR__ . '/footer.php'; ?>
