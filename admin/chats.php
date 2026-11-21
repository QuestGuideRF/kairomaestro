<?php
require_once __DIR__ . '/bootstrap.php';
$adminTitle = 'Чаты';
$adminSection = 'chats';
$rows = $adminRepository->chats();
require_once __DIR__ . '/header.php';
?>
<div class="admin-table-wrap"><table class="admin-table">
<thead><tr><th>Чат</th><th>Контекст</th><th>Участники</th><th>Сообщения</th><th>Последняя активность</th></tr></thead>
<tbody><?php foreach ($rows as $row): ?>
<?php
$contextLabel = '';
if (!empty($row['order_id'])) {
    $contextLabel = 'Заказ #' . (int)$row['order_id'];
} elseif (!empty($row['lead_id'])) {
    $contextLabel = 'Заявка #' . (int)$row['lead_id'];
}
$chatTitle = trim((string)($row['title'] ?? ''));
$chatTitle = $chatTitle !== '' ? $chatTitle : 'Чат #' . (int)$row['id'];
?>
<tr>
<td><a href="/chat.php?id=<?php echo (int)$row['id']; ?>"><?php echo escape($chatTitle); ?></a></td>
<td><?php echo escape($row['type']); ?><?php if ($contextLabel !== ''): ?><br><small><?php echo escape($contextLabel); ?></small><?php endif; ?></td>
<td><?php echo (int)$row['participant_count']; ?></td><td><?php echo (int)$row['message_count']; ?></td><td><?php echo escape($row['last_message_at'] ?: '—'); ?></td>
</tr><?php endforeach; ?></tbody>
</table></div>
<?php require_once __DIR__ . '/footer.php'; ?>
