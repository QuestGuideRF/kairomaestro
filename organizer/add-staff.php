<?php
require_once __DIR__ . '/../config/config.php';
requireCrmPanel(['organizer', 'admin']);
$eventService = new Event();
$userService = new User();
$orderService = new Order();
$eventId = (int)($_GET['event_id'] ?? $_POST['event_id'] ?? 0);
$orderId = (int)($_GET['order_id'] ?? $_POST['order_id'] ?? 0);
$event = $eventId > 0 ? $eventService->getById($eventId) : null;
if (!$event && $orderId > 0) {
    $order = $orderService->getById($orderId);
    if (!$order) {
        http_response_code(404);
        die('Заказ не найден.');
    }
    if (!$orderService->canMutate($order, (int)$_SESSION['user_id'], currentRole())) {
        http_response_code(403);
        die('Заказ недоступен.');
    }
    try {
        $eventId = (new OrderTeamService(Database::getInstance()->getConnection()))
            ->ensureEventForOrder($order, (int)$_SESSION['user_id']);
        $event = $eventService->getById($eventId);
    } catch (Throwable $error) {
        http_response_code(409);
        die(escape(publicExceptionMessage($error, 'Не удалось подготовить мероприятие для заказа.')));
    }
}
if (!$event) {
    http_response_code(404);
    die('Мероприятие не найдено.');
}
if ((int)$event['organizer_id'] !== (int)$_SESSION['user_id'] && !isAdminRole()) {
    http_response_code(403);
    die('Мероприятие недоступно.');
}
$roleLabels = [
    'florist' => 'Флорист',
    'courier' => 'Курьер',
    'installer' => 'Монтажник',
    'helper' => 'Помощник',
];
$selectedRole = (string)($_POST['role'] ?? $_GET['role'] ?? '');
if (!array_key_exists($selectedRole, $roleLabels)) {
    $selectedRole = '';
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $workerId = (int)($_POST['worker_id'] ?? 0);
    $worker = $userService->getUserById($workerId);
    if ($selectedRole === '' || !$worker || ($worker['role'] ?? '') !== $selectedRole) {
        $error = 'Выберите сотрудника указанной роли.';
    } else {
        $assignmentData = [
            'planned_hours' => $_POST['planned_hours'] ?? '0',
            'tasks_summary' => $_POST['tasks_summary'] ?? '',
            'internal_comment' => '',
            'allow_conflict' => !empty($_POST['allow_conflict']) ? 1 : 0,
            'conflict_reason' => $_POST['conflict_reason'] ?? '',
        ];
        $result = !empty($event['order_id'])
            ? $orderService->addTeamMember((int)$event['order_id'], $workerId, (int)$_SESSION['user_id'], $assignmentData)
            : $eventService->assignWorker($eventId, $workerId, (int)$_SESSION['user_id']);
        if (!empty($result['success'])) {
            redirect('/organizer/event.php?id=' . $eventId . '&staff_added=1');
        }
        $error = $result['error'] ?? 'Не удалось добавить сотрудника.';
    }
}
$assignedStaff = [];
foreach ($eventService->getEventStaff($eventId) as $member) {
    $assignedStaff[(int)$member['id']] = $member;
}
$workers = $selectedRole !== '' ? $userService->getAllWorkers(['role' => $selectedRole]) : [];
foreach ($workers as &$worker) {
    $worker['is_assigned'] = isset($assignedStaff[(int)$worker['id']]);
    $worker['is_available'] = $worker['is_assigned'] || $userService->isWorkerAvailable(
        (int)$worker['id'],
        $event['event_date'],
        $event['start_time'] ?: null,
        $event['end_time'] ?: null,
        $eventId
    );
}
unset($worker);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title>Добавить сотрудника - <?php echo escape(SITE_NAME); ?></title>
    <link rel="stylesheet" href="<?php echo asset('assets/css/style.min.css'); ?>">
<?php include_once __DIR__ . '/../includes/favicon-links.php'; ?></head>
<body>
<?php include_once __DIR__ . '/../includes/organizer-header.php'; ?>
<main class="dashboard staff-picker-page">
    <div class="container">
        <header class="dashboard-header staff-picker-hero">
            <div>
                <p class="eyebrow">Команда события</p>
                <h1>Добавить сотрудника</h1>
                <p><?php echo escape($event['title']); ?> · <?php echo escape(formatRussianDate($event['event_date'])); ?></p>
            </div>
            <a href="/organizer/event.php?id=<?php echo $eventId; ?>" class="btn btn-outline">К мероприятию</a>
        </header>
        <?php if ($error !== ''): ?><div class="alert alert-error" role="alert"><?php echo escape($error); ?></div><?php endif; ?>
        <section class="staff-picker-step" aria-labelledby="staff-role-title">
            <div class="section-header">
                <div><span class="step-kicker">Шаг 1</span><h2 id="staff-role-title">Кого нужно добавить</h2></div>
                <p>Сначала выберите роль. Система покажет только подходящих сотрудников.</p>
            </div>
            <nav class="staff-role-grid" aria-label="Выбор роли сотрудника">
                <?php foreach ($roleLabels as $role => $label): ?>
                    <a class="staff-role-card <?php echo $selectedRole === $role ? 'is-selected' : ''; ?>" href="?event_id=<?php echo $eventId; ?>&role=<?php echo escape($role); ?>" <?php echo $selectedRole === $role ? 'aria-current="true"' : ''; ?>>
                        <span class="staff-role-card__mark" aria-hidden="true"><?php echo escape(utf8Substring($label, 0, 1)); ?></span>
                        <strong><?php echo escape($label); ?></strong>
                        <span>Показать специалистов</span>
                    </a>
                <?php endforeach; ?>
            </nav>
        </section>
        <?php if ($selectedRole !== ''): ?>
            <section class="staff-picker-step" aria-labelledby="staff-worker-title">
                <div class="section-header">
                    <div><span class="step-kicker">Шаг 2</span><h2 id="staff-worker-title">Выберите: <?php echo escape($roleLabels[$selectedRole]); ?></h2></div>
                    <p>Доступность рассчитана на дату и время этого мероприятия.</p>
                </div>
                <div class="staff-candidate-grid">
                    <?php foreach ($workers as $worker): ?>
                        <?php
                        $fullName = trim($worker['first_name'] . ' ' . $worker['last_name']);
                        $initial = utf8Substring($worker['first_name'] ?: $worker['last_name'], 0, 1);
                        $assigned = $worker['is_assigned'];
                        $available = $worker['is_available'];
                        $availabilityClass = 'is-busy';
                        $availabilityText = 'Есть пересечение в календаре';
                        if ($assigned) {
                            $availabilityClass = 'is-assigned';
                            $availabilityText = 'Уже в команде';
                        } elseif ($available) {
                            $availabilityClass = 'is-free';
                            $availabilityText = 'Свободен в это время';
                        }
                        ?>
                        <article class="staff-candidate-card <?php echo !$available ? 'is-busy' : ''; ?>">
                            <div class="staff-candidate-card__identity">
                                <div class="worker-avatar staff-candidate-card__avatar">
                                    <?php if (!empty($worker['avatar'])): ?>
                                        <img src="/uploads/avatars/<?php echo escape(basename($worker['avatar'])); ?>" alt="" width="64" height="64" loading="lazy">
                                    <?php else: ?>
                                        <span class="worker-avatar-placeholder" aria-hidden="true"><?php echo escape($initial); ?></span>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <p class="staff-candidate-card__role"><?php echo escape($roleLabels[$selectedRole]); ?></p>
                                    <h3><?php echo escape($fullName); ?></h3>
                                </div>
                            </div>
                            <dl class="staff-candidate-meta">
                                <div><dt>Рейтинг</dt><dd><?php echo escape(number_format((float)($worker['rating'] ?? 5), 1, ',', ' ')); ?></dd></div>
                                <div><dt>Мероприятий</dt><dd><?php echo (int)($worker['total_events'] ?? 0); ?></dd></div>
                                <div><dt>Опыт</dt><dd><?php echo escape(number_format((float)($worker['experience_years'] ?? 0), 1, ',', ' ')); ?> г.</dd></div>
                                <div><dt>Ставка</dt><dd><?php echo escape(number_format((float)($worker['hourly_rate'] ?? 0), 0, ',', ' ')); ?> ₽/ч</dd></div>
                            </dl>
                            <p class="staff-availability <?php echo escape($availabilityClass); ?>">
                                <?php echo escape($availabilityText); ?>
                            </p>
                            <?php if (!$assigned): ?>
                                <form method="POST" class="staff-assignment-form">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="event_id" value="<?php echo $eventId; ?>">
                                    <input type="hidden" name="role" value="<?php echo escape($selectedRole); ?>">
                                    <input type="hidden" name="worker_id" value="<?php echo (int)$worker['id']; ?>">
                                    <details class="staff-assignment-options" <?php echo !$available ? 'open' : ''; ?>>
                                        <summary>Уточнить назначение</summary>
                                        <div class="staff-assignment-options__fields">
                                            <div class="form-group">
                                                <label for="hours-<?php echo (int)$worker['id']; ?>">Плановые часы</label>
                                                <input id="hours-<?php echo (int)$worker['id']; ?>" name="planned_hours" class="form-control" type="number" min="0" max="1000" step="0.5" value="0">
                                            </div>
                                            <div class="form-group">
                                                <label for="tasks-<?php echo (int)$worker['id']; ?>">Задачи на событии</label>
                                                <textarea id="tasks-<?php echo (int)$worker['id']; ?>" name="tasks_summary" class="form-control" rows="3" maxlength="10000"></textarea>
                                            </div>
                                            <?php if (!$available): ?>
                                                <label class="checkbox-row" for="override-<?php echo (int)$worker['id']; ?>"><input id="override-<?php echo (int)$worker['id']; ?>" type="checkbox" name="allow_conflict" value="1"> Подтвердить пересечение</label>
                                                <div class="form-group">
                                                    <label for="reason-<?php echo (int)$worker['id']; ?>">Почему назначение допустимо</label>
                                                    <input id="reason-<?php echo (int)$worker['id']; ?>" name="conflict_reason" class="form-control" maxlength="1000">
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </details>
                                    <button class="btn btn-primary btn-block" type="submit" <?php echo !$available && !can('override_calendar_conflict') ? 'disabled' : ''; ?>>Добавить в команду</button>
                                </form>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                    <?php if (!$workers): ?><div class="empty-state staff-picker-empty"><h3>Сотрудников этой роли пока нет</h3><p>Добавьте профиль сотрудника или выберите другую роль.</p></div><?php endif; ?>
                </div>
            </section>
        <?php endif; ?>
    </div>
</main>
<?php include_once __DIR__ . '/../includes/organizer-footer.php'; ?>
</body>
</html>
