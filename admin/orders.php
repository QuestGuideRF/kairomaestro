<?php
require_once __DIR__ . '/bootstrap.php';
$adminTitle = 'Заказы';
$adminSection = 'orders';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    adminRedirect('orders.php', $adminRepository->replaceOrderOrganizer(
        (int)($_POST['order_id'] ?? 0),
        (int)($_POST['organizer_id'] ?? 0),
        (int)$_SESSION['user_id']
    ));
}
$rows = $adminRepository->orders();
$organizers = $adminRepository->activeOrganizers();
require_once __DIR__ . '/header.php';
?>
<div class="admin-table-wrap"><table class="admin-table">
<thead><tr><th>Заказ</th><th>Клиент</th><th>Дата и место</th><th>Организатор</th><th>Статус</th><th></th></tr></thead>
<tbody><?php foreach ($rows as $row): ?><tr>
<td><strong>
<td><?php echo escape(trim($row['client_first_name'] . ' ' . $row['client_last_name'])); ?></td>
<td><?php echo escape(formatRussianDate($row['event_date'], 'date_only')); ?><br><?php echo escape($row['venue'] ?: ($row['address'] ?: 'Уточняется')); ?></td>
<td>
    <strong><?php echo escape(trim(($row['organizer_first_name'] ?? '') . ' ' . ($row['organizer_last_name'] ?? '')) ?: 'Не назначен'); ?></strong>
    <form method="post" class="admin-inline-form admin-organizer-form">
        <?php echo csrfField(); ?>
        <input type="hidden" name="order_id" value="<?php echo (int)$row['id']; ?>">
        <label>
            <span class="admin-visually-hidden">Новый организатор</span>
            <select name="organizer_id" required>
                <option value="">Выберите организатора</option>
                <?php foreach ($organizers as $organizer): ?>
                    <option value="<?php echo (int)$organizer['id']; ?>"
                        <?php echo (int)($row['organizer_id'] ?? 0) === (int)$organizer['id'] ? 'selected' : ''; ?>>
                        <?php echo escape(trim($organizer['first_name'] . ' ' . $organizer['last_name'])); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <button type="submit">Назначить</button>
    </form>
</td>
<td><?php echo escape($row['status']); ?></td>
<td><a href="/organizer/order-workspace.php?id=<?php echo (int)$row['id']; ?>">Открыть</a></td>
</tr><?php endforeach; ?></tbody>
</table></div>
<?php require_once __DIR__ . '/footer.php'; ?>
