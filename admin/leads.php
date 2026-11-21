<?php
require_once __DIR__ . '/bootstrap.php';
$adminTitle = 'Заявки';
$adminSection = 'leads';
$rows = $adminRepository->leads();
require_once __DIR__ . '/header.php';
?>
<div class="admin-table-wrap"><table class="admin-table">
<thead><tr><th>Заявка</th><th>Событие</th><th>Статус</th><th>Менеджер</th><th></th></tr></thead>
<tbody><?php foreach ($rows as $row): ?><tr>
<td><strong>
<td><?php echo escape($row['event_type']); ?><br><?php echo escape(formatRussianDate($row['event_date'], 'date_only')); ?></td>
<td><?php echo escape($row['status']); ?></td>
<td><?php echo escape(trim(($row['manager_first_name'] ?? '') . ' ' . ($row['manager_last_name'] ?? '')) ?: 'Не назначен'); ?></td>
<td><a href="/manager/lead.php?id=<?php echo (int)$row['id']; ?>">Открыть</a></td>
</tr><?php endforeach; ?></tbody>
</table></div>
<?php require_once __DIR__ . '/footer.php'; ?>
