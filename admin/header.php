<?php
$adminTitle = $adminTitle ?? 'Управление';
$adminSection = $adminSection ?? '';
$adminNav = [
    'dashboard' => ['index.php', 'Обзор'],
    'users' => ['users.php', 'Пользователи'],
    'leads' => ['leads.php', 'Заявки'],
    'orders' => ['orders.php', 'Заказы'],
    'staff' => ['staff.php', 'Команда'],
    'candidates' => ['candidates.php', 'Кандидаты'],
    'chats' => ['chats.php', 'Чаты'],
    'telegram' => ['telegram.php', 'Telegram'],
    'pool' => ['group-pool.php', 'Пул групп'],
    'portfolio' => ['portfolio.php', 'Портфолио'],
    'audit' => ['audit.php', 'Аудит'],
    'settings' => ['settings.php', 'Настройки'],
];
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <title><?php echo escape($adminTitle); ?> — <?php echo escape(SITE_NAME); ?></title>
    <?php include_once __DIR__ . '/../includes/favicon-links.php'; ?>
    <link rel="stylesheet" href="<?php echo asset('assets/css/admin.css'); ?>">
</head>
<body class="admin-body">
<header class="admin-topbar">
    <a class="admin-brand" href="/admin/">Управление</a>
    <div class="admin-topbar__actions">
        <a href="/">Сайт</a>
        <a href="/telegram-settings.php">Безопасность</a>
        <a href="/logout.php">Выйти</a>
    </div>
</header>
<div class="admin-shell">
    <nav class="admin-nav" aria-label="Разделы администратора">
        <?php foreach ($adminNav as $key => [$href, $label]): ?>
            <a class="<?php echo $adminSection === $key ? 'is-active' : ''; ?>"
               href="/admin/<?php echo escape($href); ?>"><?php echo escape($label); ?></a>
        <?php endforeach; ?>
    </nav>
    <main class="admin-main">
        <div class="admin-heading">
            <p class="admin-eyebrow">Администратор</p>
            <h1><?php echo escape($adminTitle); ?></h1>
        </div>
        <?php if ($adminFlash): ?>
            <div class="admin-alert <?php echo !empty($adminFlash['success']) ? 'is-success' : 'is-error'; ?>">
                <?php echo escape($adminFlash['message']); ?>
            </div>
        <?php endif; ?>
