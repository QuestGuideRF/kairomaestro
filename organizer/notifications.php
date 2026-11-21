<?php
require_once __DIR__ . '/../config/config.php';
if (!isLoggedIn()) {
    redirect('/login.php');
}
$notificationObj = new Notification();
$userId = $_SESSION['user_id'];
$notifications = $notificationObj->getByUser($userId, 50);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_all_read'])) {
    requireCsrf();
    $notificationObj->markAllAsRead($userId);
    redirect(isWorkerRole() ? '/worker/notifications.php' : '/organizer/notifications.php');
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
    <?php
    if (isWorkerRole()) {
        include_once __DIR__ . '/../includes/worker-header.php';
    } elseif (currentRole() === 'manager') {
        include_once __DIR__ . '/../includes/manager-header.php';
    } else {
        include_once __DIR__ . '/../includes/organizer-header.php';
    }
    ?>
    <div class="notifications-page">
        <div class="container">
            <div class="page-header">
                <h1>Уведомления</h1>
                <form method="POST" action="" style="display: inline;">
                    <?php echo csrfField(); ?>
                    <button type="submit" name="mark_all_read" class="btn btn-outline">Отметить все прочитанными</button>
                </form>
            </div>
            <?php if (empty($notifications)): ?>
            <div class="empty-state">
                <p>У вас нет уведомлений</p>
            </div>
            <?php else: ?>
            <div class="notifications-list">
                <?php foreach ($notifications as $notification): ?>
                <div class="notification-item <?php echo $notification['is_read'] ? 'read' : 'unread'; ?>">
                    <div class="notification-content">
                        <strong><?php echo escape($notification['title']); ?></strong>
                        <p><?php echo escape($notification['message']); ?></p>
                        <small><?php echo date('d.m.Y H:i', strtotime($notification['created_at'])); ?></small>
                    </div>
                    <?php if ($notification['link']): ?>
                    <a href="<?php echo escape($notification['link']); ?>" class="btn btn-sm">Открыть</a>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php
    if (isWorkerRole()) {
        include_once __DIR__ . '/../includes/worker-footer.php';
    } else {
        include_once __DIR__ . '/../includes/organizer-footer.php';
    }
    ?>
</body>
</html>
