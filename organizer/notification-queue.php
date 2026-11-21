<?php
require_once __DIR__ . '/../config/config.php';
requireCrmPanel(['admin']);
requirePermission('retry_notifications');
$queue = new NotificationQueue();
$message = '';
$messageIsError = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $result = $queue->retry(
        (int)($_POST['queue_id'] ?? 0),
        (int)$_SESSION['user_id']
    );
    $message = $result['success']
        ? 'Уведомление возвращено в очередь'
        : ($result['error'] ?? 'Запись нельзя повторить');
    $messageIsError = !$result['success'];
}
$status = trim((string)($_GET['status'] ?? ''));
$rows = [];
if (dbTableExists('notification_queue')) {
    $rows = $queue->recent($status !== '' ? $status : null, 200);
}
$statusLabels = [
    '' => 'Все',
    'pending' => 'Ожидают',
    'processing' => 'В обработке',
    'retry' => 'Повтор',
    'sent' => 'Отправлены',
    'failed' => 'Ошибки',
    'cancelled' => 'Отменены',
];
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Очередь уведомлений - Азбука Декора</title>
    <link rel="stylesheet" href="<?php echo asset('assets/css/style.min.css'); ?>">
<?php include_once __DIR__ . '/../includes/favicon-links.php'; ?></head>
<body>
    <?php include_once __DIR__ . '/../includes/organizer-header.php'; ?>
    <main class="dashboard">
        <div class="container container-wide">
            <div class="dashboard-header">
                <div>
                    <p class="eyebrow">Telegram</p>
                    <h1>Очередь уведомлений</h1>
                    <p>Ошибки Telegram не задерживают PHP-запросы сайта.</p>
                </div>
            </div>
            <?php if ($message): ?>
                <div class="alert <?php echo $messageIsError ? 'alert-error' : 'alert-success'; ?>">
                    <?php echo escape($message); ?>
                </div>
            <?php endif; ?>
            <nav class="filters-tabs" aria-label="Фильтр очереди">
                <?php foreach ($statusLabels as $key => $label): ?>
                    <a
                        class="filter-tab <?php echo $status === $key ? 'active' : ''; ?>"
                        href="?status=<?php echo rawurlencode($key); ?>"
                    >
                        <?php echo escape($label); ?>
                    </a>
                <?php endforeach; ?>
            </nav>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Событие</th>
                            <th>Получатель</th>
                            <th>Статус</th>
                            <th>Попытки</th>
                            <th>Следующая попытка</th>
                            <th>Ошибка</th>
                            <th><span class="sr-only">Действия</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$rows): ?>
                            <tr>
                                <td colspan="8">В выбранном разделе записей нет.</td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($rows as $row): ?>
                            <?php
                            $recipient = (string)($row['recipient_type'] ?? '');
                            if (!empty($row['recipient_id'])) {
                                $recipient .= ' #' . (int)$row['recipient_id'];
                            } elseif (!empty($row['telegram_chat_id'])) {
                                $recipient .= ' chat ' . (string)$row['telegram_chat_id'];
                            }
                            ?>
                            <tr>
                                <td><?php echo (int)$row['id']; ?></td>
                                <td><?php echo escape($row['event_type'] ?? ''); ?></td>
                                <td><?php echo escape($recipient); ?></td>
                                <td>
                                    <span class="badge badge-<?php echo escape($row['status'] ?? 'pending'); ?>">
                                        <?php echo escape($statusLabels[$row['status'] ?? ''] ?? ($row['status'] ?? '')); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php echo (int)($row['attempts'] ?? 0); ?>
                                    /
                                    <?php echo (int)($row['max_attempts'] ?? 0); ?>
                                </td>
                                <td>
                                    <?php echo !empty($row['next_attempt_at'])
                                        ? escape(formatRussianDate($row['next_attempt_at']))
                                        : '—'; ?>
                                </td>
                                <td><?php echo escape($row['last_error'] ?? ''); ?></td>
                                <td>
                                    <?php if (in_array($row['status'] ?? '', ['failed', 'cancelled'], true)
                                        && ($row['event_type'] ?? '') !== 'privileged_login_code'): ?>
                                        <form method="post">
                                            <?php echo csrfField(); ?>
                                            <input type="hidden" name="queue_id" value="<?php echo (int)$row['id']; ?>">
                                            <button type="submit" class="btn btn-secondary btn-sm">Повторить</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
    <?php include_once __DIR__ . '/../includes/organizer-footer.php'; ?>
</body>
</html>
