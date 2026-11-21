<?php
require_once __DIR__ . '/../config/config.php';
requireCrmPanel(['organizer', 'admin']);
requirePermission('view_calendar');
$calendar = new TeamCalendar();
$mode = in_array($_GET['mode'] ?? '', ['month', 'week', 'day', 'list'], true) ? $_GET['mode'] : 'month';
$isValidDate = static function ($value) {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', (string)$value);
    return $date && $date->format('Y-m-d') === $value;
};
$anchor = $isValidDate($_GET['date'] ?? '') ? $_GET['date'] : date('Y-m-d');
$dayStartFormat = 'Y-m-d 00:00:00';
$oneDayForward = ' +1 day';
$dateFrom = '';
$dateTo = '';
if ($mode === 'month') {
    $from = date('Y-m-01 00:00:00', strtotime($anchor));
    $to = date('Y-m-01 00:00:00', strtotime($from . ' +1 month'));
} elseif ($mode === 'week') {
    $from = date($dayStartFormat, strtotime('monday this week', strtotime($anchor)));
    $to = date($dayStartFormat, strtotime($from . ' +7 days'));
} elseif ($mode === 'day') {
    $from = $anchor . ' 00:00:00';
    $to = date($dayStartFormat, strtotime($anchor . $oneDayForward));
} else {
    $dateFrom = $isValidDate($_GET['date_from'] ?? '') ? $_GET['date_from'] : date('Y-m-d', strtotime('-30 days'));
    $dateTo = $isValidDate($_GET['date_to'] ?? '') ? $_GET['date_to'] : date('Y-m-d', strtotime('+90 days'));
    if ($dateTo < $dateFrom) {
        [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
    }
    $from = $dateFrom . ' 00:00:00';
    $to = date('Y-m-d 00:00:00', strtotime($dateTo . $oneDayForward));
}
$filters = array_intersect_key($_GET, array_flip([
    'order_id', 'entry_type', 'user_id', 'role', 'organizer_id', 'status', 'venue', 'event_type',
]));
$error = '';
$success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'create_entry') {
        requirePermission('manage_calendar');
        $result = $calendar->create(
            $_POST,
            $_POST['participants'] ?? [],
            (int)$_SESSION['user_id'],
            !empty($_POST['allow_conflict']),
            $_POST['override_reason'] ?? null
        );
        $success = !empty($result['success']) ? 'Запись добавлена в общий календарь' : '';
        $error = !empty($result['success']) ? '' : ($result['error'] ?? 'Не удалось создать запись');
    } elseif ($action === 'status') {
        requirePermission('manage_calendar');
        $result = $calendar->updateStatus(
            (int)($_POST['entry_id'] ?? 0),
            $_POST['status'] ?? 'planned',
            (int)$_SESSION['user_id']
        );
        $success = !empty($result['success']) ? 'Статус календарной записи обновлён' : '';
        $error = !empty($result['success']) ? '' : ($result['error'] ?? 'Не удалось обновить запись');
    } else {
        $error = 'Неизвестное действие календаря.';
    }
}
if (can('manage_calendar')) {
    $calendar->syncOrganizerOrders((int)$_SESSION['user_id'], currentRole(), $from, $to);
}
$entries = dbTableExists('calendar_entries')
    ? $calendar->list($from, $to, $filters, (int)$_SESSION['user_id'], currentRole())
    : [];
$entriesByDay = [];
foreach ($entries as &$entry) {
    $entry['participants'] = $calendar->participants((int)$entry['id']);
    $entry['tasks'] = !empty($entry['order_id'])
        ? $calendar->upcomingTasksForOrder((int)$entry['order_id'], 4)
        : [];
    $entriesByDay[date('Y-m-d', strtotime($entry['starts_at']))][] = $entry;
}
unset($entry);
$workers = (new User())->getAllWorkers();
$organizers = (new User())->getOrganizers();
$orderService = new Order();
$orders = $orderService->getForCrmUser((int)$_SESSION['user_id'], currentRole());
$orderStatuses = $orderService->statuses();
$calendarStatuses = [
    'planned' => 'Запланировано',
    'in_progress' => 'В работе',
    'completed' => 'Выполнено',
    'cancelled' => 'Отменено',
];
$roleLabels = [
    'organizer' => 'Организатор',
    'worker' => 'Сотрудник',
    'florist' => 'Флорист',
    'courier' => 'Курьер',
    'installer' => 'Монтажник',
    'helper' => 'Помощник',
];
$previousOffset = ' -1 day';
$nextOffset = $oneDayForward;
if ($mode === 'month') {
    $previousOffset = ' -1 month';
    $nextOffset = ' +1 month';
} elseif ($mode === 'week') {
    $previousOffset = ' -7 days';
    $nextOffset = ' +7 days';
}
$prev = date('Y-m-d', strtotime($anchor . $previousOffset));
$next = date('Y-m-d', strtotime($anchor . $nextOffset));
$calendarUrl = static function ($targetMode, $targetDate, $extra = []) use ($filters) {
    return '?' . http_build_query(array_merge($filters, ['mode' => $targetMode, 'date' => $targetDate], $extra));
};
$monthName = formatRussianDate($anchor, 'month_name');
$monthTitle = utf8Upper(utf8Substring($monthName, 0, 1)) . utf8Substring($monthName, 1) . ' ' . date('Y', strtotime($anchor));
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title>Календарь - <?php echo escape(SITE_NAME); ?></title>
    <link rel="stylesheet" href="<?php echo asset('assets/css/style.min.css'); ?>">
<?php include_once __DIR__ . '/../includes/favicon-links.php'; ?></head>
<body>
<?php include_once __DIR__ . '/../includes/organizer-header.php'; ?>
<main class="dashboard calendar-workspace organizer-calendar-page">
    <div class="container container-wide">
        <header class="dashboard-header calendar-page-header">
            <div>
                <p class="eyebrow">Команда и сроки</p>
                <h1>Календарь</h1>
                <p>Свадьбы, встречи, монтаж, доставка, задачи и занятость команды.</p>
            </div>
            <div class="calendar-nav" aria-label="Навигация по календарю">
                <a class="btn btn-outline btn-sm" href="<?php echo escape($calendarUrl($mode, $prev)); ?>" aria-label="Предыдущий период">Назад</a>
                <a class="btn btn-outline btn-sm" href="<?php echo escape($calendarUrl($mode, date('Y-m-d'))); ?>">Сегодня</a>
                <a class="btn btn-outline btn-sm" href="<?php echo escape($calendarUrl($mode, $next)); ?>" aria-label="Следующий период">Вперёд</a>
            </div>
        </header>
        <div class="calendar-toolbar">
            <h2><?php echo escape($mode === 'month' ? $monthTitle : formatRussianDate($anchor, 'date_only')); ?></h2>
            <nav class="workspace-tabs calendar-mode-tabs" aria-label="Режим календаря">
                <?php foreach (['month' => 'Месяц', 'week' => 'Неделя', 'day' => 'День', 'list' => 'Список'] as $key => $label): ?>
                    <a class="<?php echo $mode === $key ? 'active' : ''; ?>" href="<?php echo escape($calendarUrl($key, $anchor)); ?>" <?php echo $mode === $key ? 'aria-current="page"' : ''; ?>><?php echo escape($label); ?></a>
                <?php endforeach; ?>
            </nav>
        </div>
        <?php if ($error !== ''): ?><div class="alert alert-error" role="alert"><?php echo escape($error); ?></div><?php endif; ?>
        <?php if ($success !== ''): ?><output class="alert alert-success"><?php echo escape($success); ?></output><?php endif; ?>
        <details class="calendar-filters">
            <summary>Фильтры</summary>
            <form method="GET" class="crm-filter-panel calendar-filter-panel">
                <input type="hidden" name="mode" value="<?php echo escape($mode); ?>">
                <input type="hidden" name="date" value="<?php echo escape($anchor); ?>">
                <?php if ($mode === 'list'): ?>
                    <div class="form-group"><label for="date_from">Период от</label><input id="date_from" type="date" name="date_from" class="form-control" value="<?php echo escape($dateFrom); ?>"></div>
                    <div class="form-group"><label for="date_to">Период до</label><input id="date_to" type="date" name="date_to" class="form-control" value="<?php echo escape($dateTo); ?>"></div>
                <?php endif; ?>
                <div class="form-group"><label for="calendar_order">Заказ</label><select id="calendar_order" name="order_id" class="form-control"><option value="">Все</option><?php foreach ($orders as $item): ?><option value="<?php echo (int)$item['id']; ?>" <?php echo (int)($filters['order_id'] ?? 0) === (int)$item['id'] ? 'selected' : ''; ?>><?php echo escape($item['title']); ?></option><?php endforeach; ?></select></div>
                <div class="form-group"><label for="calendar_type">Тип записи</label><select id="calendar_type" name="entry_type" class="form-control"><option value="">Все</option><?php foreach ($calendar->entryTypes() as $key => $label): ?><option value="<?php echo escape($key); ?>" <?php echo ($filters['entry_type'] ?? '') === $key ? 'selected' : ''; ?>><?php echo escape($label); ?></option><?php endforeach; ?></select></div>
                <div class="form-group"><label for="calendar_user">Сотрудник</label><select id="calendar_user" name="user_id" class="form-control"><option value="">Все</option><?php foreach ($workers as $worker): ?><option value="<?php echo (int)$worker['id']; ?>" <?php echo (int)($filters['user_id'] ?? 0) === (int)$worker['id'] ? 'selected' : ''; ?>><?php echo escape($worker['first_name'] . ' ' . $worker['last_name']); ?></option><?php endforeach; ?></select></div>
                <div class="form-group"><label for="calendar_role">Роль</label><select id="calendar_role" name="role" class="form-control"><option value="">Все</option><?php foreach (['florist', 'courier', 'installer', 'helper'] as $role): ?><option value="<?php echo escape($role); ?>" <?php echo ($filters['role'] ?? '') === $role ? 'selected' : ''; ?>><?php echo escape($roleLabels[$role]); ?></option><?php endforeach; ?></select></div>
                <?php if (isAdminRole()): ?><div class="form-group"><label for="calendar_organizer">Организатор</label><select id="calendar_organizer" name="organizer_id" class="form-control"><option value="">Все</option><?php foreach ($organizers as $organizer): ?><option value="<?php echo (int)$organizer['id']; ?>" <?php echo (int)($filters['organizer_id'] ?? 0) === (int)$organizer['id'] ? 'selected' : ''; ?>><?php echo escape($organizer['first_name'] . ' ' . $organizer['last_name']); ?></option><?php endforeach; ?></select></div><?php endif; ?>
                <div class="form-group"><label for="calendar_status">Статус</label><select id="calendar_status" name="status" class="form-control"><option value="">Все</option><?php foreach ($calendarStatuses as $key => $label): ?><option value="<?php echo escape($key); ?>" <?php echo ($filters['status'] ?? '') === $key ? 'selected' : ''; ?>><?php echo escape($label); ?></option><?php endforeach; ?></select></div>
                <div class="form-group"><label for="calendar_event_type">Тип события</label><input id="calendar_event_type" name="event_type" class="form-control" value="<?php echo escape($filters['event_type'] ?? ''); ?>" placeholder="Например, свадьба"></div>
                <div class="form-group"><label for="calendar_venue">Площадка</label><input id="calendar_venue" name="venue" class="form-control" value="<?php echo escape($filters['venue'] ?? ''); ?>"></div>
                <div class="filter-actions"><button class="btn btn-primary" type="submit">Применить</button><a class="btn btn-outline" href="?mode=<?php echo escape($mode); ?>&date=<?php echo escape($anchor); ?>">Сбросить</a></div>
            </form>
        </details>
        <?php if ($mode === 'month'): ?>
            <section class="calendar-month" aria-label="<?php echo escape($monthTitle); ?>">
                <div class="calendar-weekdays" aria-hidden="true"><?php foreach (['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'] as $dayName): ?><span><?php echo $dayName; ?></span><?php endforeach; ?></div>
                <div class="calendar-month-grid">
                    <?php
                    $first = strtotime(date('Y-m-01', strtotime($anchor)));
                    $offset = (int)date('N', $first) - 1;
                    $days = (int)date('t', $first);
                    for ($i = 0; $i < $offset; $i++): ?><div class="calendar-day is-empty" aria-hidden="true"></div><?php endfor;
                    for ($day = 1; $day <= $days; $day++):
                        $date = date('Y-m-', $first) . str_pad((string)$day, 2, '0', STR_PAD_LEFT);
                        $dayEntries = $entriesByDay[$date] ?? [];
                    ?>
                        <article class="calendar-day <?php echo $date === date('Y-m-d') ? 'is-today' : ''; ?> <?php echo $dayEntries ? 'has-events' : ''; ?>">
                            <button type="button" class="calendar-day__trigger" data-calendar-day="<?php echo escape($date); ?>" aria-label="Открыть <?php echo escape(formatRussianDate($date, 'date_only')); ?>">
                                <time datetime="<?php echo escape($date); ?>"><?php echo $day; ?></time>
                                <?php if ($dayEntries): ?><span class="calendar-day__count"><?php echo count($dayEntries); ?></span><?php endif; ?>
                            </button>
                            <div class="calendar-day__events">
                                <?php foreach (array_slice($dayEntries, 0, 3) as $entry): ?>
                                    <div class="calendar-event type-<?php echo escape($entry['entry_type']); ?>">
                                        <strong><?php echo escape(date('H:i', strtotime($entry['starts_at']))); ?> · <?php echo escape($entry['event_type'] ?: $entry['title']); ?></strong>
                                        <?php if (!empty($entry['client_first_name'])): ?><span><?php echo escape(trim($entry['client_first_name'] . ' ' . $entry['client_last_name'])); ?></span><?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                                <?php if (count($dayEntries) > 3): ?><span class="calendar-day__more">Ещё <?php echo count($dayEntries) - 3; ?></span><?php endif; ?>
                            </div>
                        </article>
                    <?php endfor; ?>
                </div>
            </section>
            <div hidden>
                <?php for ($day = 1; $day <= $days; $day++):
                    $date = date('Y-m-', $first) . str_pad((string)$day, 2, '0', STR_PAD_LEFT);
                    $dayEntries = $entriesByDay[$date] ?? [];
                ?>
                    <template id="calendar-day-<?php echo str_replace('-', '', $date); ?>">
                        <div class="calendar-day-detail">
                            <p class="eyebrow">План дня</p>
                            <h2><?php echo escape(formatRussianDate($date, 'date_only')); ?></h2>
                            <?php if (!$dayEntries): ?>
                                <div class="empty-state"><h3>На этот день записей нет</h3><p>Можно оставить день свободным или добавить рабочую запись ниже календаря.</p></div>
                            <?php else: ?>
                                <div class="calendar-day-detail__list">
                                    <?php foreach ($dayEntries as $entry): ?>
                                        <article class="calendar-day-detail__item">
                                            <div class="calendar-day-detail__time"><time><?php echo escape(date('H:i', strtotime($entry['starts_at']))); ?></time><span><?php echo escape($calendar->entryTypes()[$entry['entry_type']] ?? $entry['entry_type']); ?></span></div>
                                            <div class="calendar-day-detail__body">
                                                <h3><?php echo escape($entry['event_type'] ?: $entry['title']); ?></h3>
                                                <?php if (!empty($entry['client_first_name'])): ?><p class="calendar-day-detail__client"><?php echo escape(trim($entry['client_first_name'] . ' ' . $entry['client_last_name'])); ?></p><?php endif; ?>
                                                <dl class="calendar-day-detail__facts">
                                                    <div><dt>Площадка</dt><dd><?php echo escape($entry['venue'] ?: 'Площадка уточняется'); ?></dd></div>
                                                    <div><dt>Адрес</dt><dd><?php echo escape($entry['address'] ?: ($entry['location'] ?: 'Адрес уточняется')); ?></dd></div>
                                                    <div><dt>Статус</dt><dd><?php echo escape(!empty($entry['order_status']) ? ($orderStatuses[$entry['order_status']] ?? $entry['order_status']) : ($calendarStatuses[$entry['status']] ?? $entry['status'])); ?></dd></div>
                                                </dl>
                                                <?php if (!empty($entry['participants'])): ?>
                                                    <div class="calendar-day-detail__team"><strong>Команда</strong><p><?php echo escape(implode(', ', array_map(static fn($person) => trim($person['first_name'] . ' ' . $person['last_name']) . ' · ' . ($roleLabels[$person['role']] ?? $person['role']), $entry['participants']))); ?></p></div>
                                                <?php endif; ?>
                                                <?php if (!empty($entry['tasks'])): ?>
                                                    <div class="calendar-day-detail__tasks"><strong>Ближайшие задачи</strong><ul><?php foreach ($entry['tasks'] as $task): ?><li><?php echo escape($task['title']); ?><?php if ($task['due_date']): ?> · до <?php echo escape(formatRussianDate($task['due_date'], 'date_only')); ?><?php endif; ?></li><?php endforeach; ?></ul></div>
                                                <?php endif; ?>
                                                <?php if (!empty($entry['order_id'])): ?><a class="btn btn-primary btn-sm" href="/organizer/order-workspace.php?id=<?php echo (int)$entry['order_id']; ?>">Открыть заказ</a><?php endif; ?>
                                            </div>
                                        </article>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </template>
                <?php endfor; ?>
            </div>
        <?php else: ?>
            <section class="calendar-list-view mode-<?php echo escape($mode); ?>">
                <?php foreach ($entries as $entry): ?>
                    <article class="calendar-list-item">
                        <time datetime="<?php echo escape($entry['starts_at']); ?>"><?php echo escape(formatRussianDateTime($entry['starts_at'])); ?><small>до <?php echo escape(date('d.m H:i', strtotime($entry['ends_at']))); ?></small></time>
                        <div><span class="badge"><?php echo escape($calendar->entryTypes()[$entry['entry_type']] ?? $entry['entry_type']); ?></span><h3><?php echo escape($entry['title']); ?></h3><p><?php echo escape($entry['location'] ?: 'Место уточняется'); ?></p><p><?php echo escape(implode(', ', array_map(static fn($person) => trim($person['first_name'] . ' ' . $person['last_name']), $entry['participants']))); ?></p></div>
                        <?php if (can('manage_calendar')): ?><form method="POST" class="inline-form"><?php echo csrfField(); ?><input type="hidden" name="action" value="status"><input type="hidden" name="entry_id" value="<?php echo (int)$entry['id']; ?>"><label class="sr-only" for="entry-status-<?php echo (int)$entry['id']; ?>">Статус записи</label><select id="entry-status-<?php echo (int)$entry['id']; ?>" name="status" class="form-control"><?php foreach ($calendarStatuses as $key => $label): ?><option value="<?php echo escape($key); ?>" <?php echo $entry['status'] === $key ? 'selected' : ''; ?>><?php echo escape($label); ?></option><?php endforeach; ?></select><button class="btn btn-sm btn-outline" type="submit">Сохранить</button></form><?php endif; ?>
                    </article>
                <?php endforeach; ?>
                <?php if (!$entries): ?><div class="empty-state"><p>В выбранном периоде записей нет.</p></div><?php endif; ?>
            </section>
        <?php endif; ?>
        <?php if (can('manage_calendar')): ?>
            <details class="panel-section calendar-create-panel">
                <summary><span><span class="eyebrow">Новая запись</span><strong>Добавить в календарь</strong></span><span aria-hidden="true">+</span></summary>
                <form method="POST" class="event-form">
                    <input type="hidden" name="action" value="create_entry">
                    <?php echo csrfField(); ?>
                    <div class="form-row">
                        <div class="form-group"><label for="new-entry-type">Тип</label><select id="new-entry-type" name="entry_type" class="form-control"><?php foreach ($calendar->entryTypes() as $key => $label): ?><option value="<?php echo escape($key); ?>"><?php echo escape($label); ?></option><?php endforeach; ?></select></div>
                        <div class="form-group"><label for="new-entry-title">Название</label><input id="new-entry-title" name="title" class="form-control" required maxlength="190"></div>
                        <div class="form-group"><label for="new-entry-start">Начало</label><input id="new-entry-start" type="datetime-local" name="starts_at" class="form-control" required></div>
                        <div class="form-group"><label for="new-entry-end">Окончание</label><input id="new-entry-end" type="datetime-local" name="ends_at" class="form-control" required></div>
                    </div>
                    <div class="form-row">
                        <div class="form-group"><label for="new-entry-order">Заказ</label><select id="new-entry-order" name="order_id" class="form-control"><option value="">Без заказа</option><?php foreach ($orders as $item): ?><option value="<?php echo (int)$item['id']; ?>"><?php echo escape($item['title']); ?></option><?php endforeach; ?></select></div>
                        <div class="form-group"><label for="new-entry-location">Место</label><input id="new-entry-location" name="location" class="form-control" maxlength="500"></div>
                    </div>
                    <div class="form-group"><label for="new-entry-participants">Участники</label><select id="new-entry-participants" name="participants[]" class="form-control multi-select" multiple size="6"><?php foreach ($workers as $worker): ?><option value="<?php echo (int)$worker['id']; ?>"><?php echo escape($worker['first_name'] . ' ' . $worker['last_name'] . ' · ' . ($roleLabels[$worker['role']] ?? $worker['specialty'])); ?></option><?php endforeach; ?></select></div>
                    <div class="form-group"><label for="new-entry-description">Описание</label><textarea id="new-entry-description" name="description" class="form-control" rows="3" maxlength="10000"></textarea></div>
                    <label class="checkbox-row" for="new-entry-allow-conflict"><input id="new-entry-allow-conflict" type="checkbox" name="allow_conflict" value="1"> Подтвердить пересечение времени</label>
                    <div class="form-group"><label for="new-entry-override-reason">Причина подтверждения конфликта</label><input id="new-entry-override-reason" name="override_reason" class="form-control" maxlength="1000"></div>
                    <button class="btn btn-primary" type="submit">Добавить запись</button>
                </form>
            </details>
        <?php endif; ?>
    </div>
</main>
<dialog id="organizerDayDialog" class="calendar-day-dialog">
    <div class="calendar-day-dialog__panel">
        <button type="button" class="calendar-day-dialog__close" aria-label="Закрыть">×</button>
        <div id="organizerDayDialogContent"></div>
    </div>
</dialog>
<script>
(function () {
    var dialog = document.getElementById('organizerDayDialog');
    var content = document.getElementById('organizerDayDialogContent');
    if (!dialog || !content || typeof dialog.showModal !== 'function') return;
    document.querySelectorAll('[data-calendar-day]').forEach(function (button) {
        button.addEventListener('click', function () {
            var template = document.getElementById('calendar-day-' + button.dataset.calendarDay.replace(/-/g, ''));
            if (!template) return;
            content.replaceChildren(template.content.cloneNode(true));
            dialog.showModal();
        });
    });
    dialog.querySelector('.calendar-day-dialog__close').addEventListener('click', function () { dialog.close(); });
    dialog.addEventListener('click', function (event) { if (event.target === dialog) dialog.close(); });
}());
</script>
<?php include_once __DIR__ . '/../includes/organizer-footer.php'; ?>
</body>
</html>
