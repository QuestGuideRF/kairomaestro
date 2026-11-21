<?php
require_once __DIR__ . '/../config/config.php';
requireWorkerPanel();
$notificationObj = new Notification();
$userId = (int)$_SESSION['user_id'];
$notifications = $notificationObj->getByUser($userId, 50);
$unreadCount = count(array_filter($notifications, static function ($notification) {
    return empty($notification['is_read']);
}));
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_all_read'])) {
    requireCsrf();
    $notificationObj->markAllAsRead($userId);
    redirect('/worker/notifications.php');
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Уведомления - Азбука Декора</title>
    <?php include_once __DIR__ . '/../includes/favicon-links.php'; ?>
    <link rel="stylesheet" href="<?php echo asset('assets/css/style.min.css'); ?>">
</head>
<body>
    <?php include_once __DIR__ . '/../includes/worker-header.php'; ?>
    <main class="notifications-page worker-notifications-page">
        <div class="container">
            <div class="dashboard-header notifications-hero">
                <div class="notifications-hero__title">
                    <span class="notifications-hero__icon"><img src="/assets/icons/bell.svg" alt="" aria-hidden="true"></span>
                    <div>
                        <p class="eyebrow">Центр событий</p>
                        <h1>Уведомления</h1>
                        <p>Изменения задач, мероприятий и выплат собраны в одном месте.</p>
                    </div>
                </div>
                <?php if ($unreadCount > 0): ?>
                <form method="POST" action="" class="notifications-read-form">
                    <?php echo csrfField(); ?>
                    <button type="submit" name="mark_all_read" class="btn btn-outline">Отметить все прочитанными</button>
                </form>
                <?php endif; ?>
            </div>
            <div class="notification-summary" aria-label="Сводка уведомлений">
                <div><strong><?php echo $unreadCount; ?></strong><span>Новых</span></div>
                <div><strong><?php echo count($notifications); ?></strong><span>Последних уведомлений</span></div>
            </div>
            <?php if (empty($notifications)): ?>
            <div class="empty-state">
                <div class="empty-icon"><img src="/assets/icons/bell.svg" alt="" aria-hidden="true"></div>
                <h2>Новых уведомлений нет</h2>
                <p>Здесь появятся назначения, изменения задач и важные сообщения по событиям.</p>
            </div>
            <?php else: ?>
            <div class="notification-feed">
                <?php foreach ($notifications as $notification): ?>
                <article class="notification-feed__item <?php echo $notification['is_read'] ? 'is-read' : 'is-unread'; ?>">
                    <span class="notification-feed__marker" aria-hidden="true"></span>
                    <div class="notification-feed__content">
                        <div class="notification-feed__heading">
                            <h2><?php echo escape($notification['title']); ?></h2>
                            <?php if (!$notification['is_read']): ?><span class="badge">Новое</span><?php endif; ?>
                        </div>
                        <p><?php echo escape($notification['message']); ?></p>
                        <time datetime="<?php echo escape(date(DATE_ATOM, strtotime($notification['created_at']))); ?>">
                            <?php echo escape(formatRussianDateTime($notification['created_at'])); ?>
                        </time>
                    </div>
                    <?php if ($notification['link']): ?>
                    <a href="<?php echo escape($notification['link']); ?>" class="btn btn-sm btn-outline notification-feed__action">Открыть</a>
                    <?php endif; ?>
                </article>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </main>
    <?php include_once __DIR__ . '/../includes/worker-footer.php'; ?>
</body>
</html>
