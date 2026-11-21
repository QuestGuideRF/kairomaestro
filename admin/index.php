<?php
require_once __DIR__ . '/bootstrap.php';
$adminTitle = 'Состояние системы';
$adminSection = 'dashboard';
$stats = $adminRepository->dashboard();
require_once __DIR__ . '/header.php';
?>
<section class="admin-metrics">
    <?php foreach ([
        'Пользователи' => $stats['users'],
        'Активные аккаунты' => $stats['active_users'],
        'Новые заявки' => $stats['new_leads'],
        'Активные заказы' => $stats['active_orders'],
        'Новые кандидаты' => $stats['new_candidates'],
        'Свободные группы клиента' => $stats['pool_available_client'],
        'Свободные группы команды' => $stats['pool_available_team'],
    ] as $label => $value): ?>
        <article class="admin-metric">
            <strong><?php echo (int)$value; ?></strong>
            <span><?php echo escape($label); ?></span>
        </article>
    <?php endforeach; ?>
</section>
<section class="admin-panel">
    <h2>Контрольные точки</h2>
    <div class="admin-actions">
        <a class="admin-button" href="/admin/telegram.php">Проверить Telegram</a>
        <a class="admin-button is-secondary" href="/admin/portfolio.php">Создать проект</a>
    </div>
</section>
<?php require_once __DIR__ . '/footer.php'; ?>
