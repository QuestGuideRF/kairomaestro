<?php
require_once __DIR__ . '/bootstrap.php';
$adminTitle = 'Команда';
$adminSection = 'staff';
$rows = array_filter($adminRepository->users(), static fn($row) => in_array($row['role'], ['manager','organizer','florist','courier','installer','helper','worker','admin'], true));
require_once __DIR__ . '/header.php';
?>
<section class="admin-card-grid">
<?php foreach ($rows as $row): ?>
<article class="admin-card">
    <p class="admin-card__meta"><?php echo escape($row['role']); ?> ·
    <h2><?php echo escape(trim($row['first_name'] . ' ' . $row['last_name'])); ?></h2>
    <p><?php echo escape($row['phone'] ?: ($row['email'] ?: 'Контакт не указан')); ?></p>
    <p><?php echo $row['is_active'] ? 'Аккаунт активен' : 'Аккаунт отключён'; ?> · <?php echo $row['telegram_user_id'] ? 'Telegram подключён' : 'Telegram не подключён'; ?></p>
</article>
<?php endforeach; ?>
</section>
<?php require_once __DIR__ . '/footer.php'; ?>
