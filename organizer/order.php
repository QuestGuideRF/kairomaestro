<?php
require_once __DIR__ . '/../config/config.php';
requireCrmPanel();
$orderObj = new Order();
$userObj = new User();
$orderId = (int)($_GET['id'] ?? 0);
$order = $orderObj->getById($orderId);
if (!$order) {
    http_response_code(404);
    require_once __DIR__ . '/../404.php';
    exit;
}
if (!$orderObj->canAccess($order, (int)$_SESSION['user_id'], currentRole())) {
    http_response_code(403);
    require_once __DIR__ . '/../403.php';
    exit;
}
$headerFile = __DIR__ . '/../includes/' . (currentRole() === 'manager' ? 'manager-header.php' : 'organizer-header.php');
$error = '';
$success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    if (currentRole() === 'manager') {
        http_response_code(403);
        require_once __DIR__ . '/../403.php';
        exit;
    }
    $action = $_POST['action'] ?? '';
    if ($action === 'update_status') {
        $result = $orderObj->updateStatus($orderId, $_POST['status'] ?? $order['status'], $_SESSION['user_id'], $_POST['comment'] ?? null);
        $success = $result['success'] ? 'Статус обновлен' : '';
        $error = $result['success'] ? '' : $result['error'];
    } elseif ($action === 'update_venue') {
        $result = $orderObj->updateVenue($orderId, (int)$_SESSION['user_id'], $_POST['venue'] ?? '', $_POST['address'] ?? '');
        $success = $result['success'] ? 'Площадка и адрес сохранены.' : '';
        $error = $result['success'] ? '' : $result['error'];
    } elseif ($action === 'add_material') {
        $result = $orderObj->addMaterial($orderId, $_SESSION['user_id'], $_POST, $_FILES['material_file'] ?? null);
        $success = $result['success'] ? 'Материал добавлен' : '';
        $error = $result['success'] ? '' : $result['error'];
    } elseif ($action === 'add_task') {
        $result = $orderObj->addTask($orderId, $_SESSION['user_id'], $_POST);
        $success = $result['success'] ? 'Задача добавлена' : '';
        $error = $result['success'] ? '' : $result['error'];
    } elseif ($action === 'task_status') {
        $result = $orderObj->updateTaskStatus((int)($_POST['task_id'] ?? 0), (int)$_SESSION['user_id'], $_POST['status'] ?? 'todo');
        $success = $result['success'] ? 'Статус задачи обновлён' : '';
        $error = $result['success'] ? '' : $result['error'];
    } elseif ($action === 'add_checklist') {
        $result = $orderObj->addChecklistItem($orderId, $_SESSION['user_id'], $_POST['checklist_title'] ?? '');
        $success = $result['success'] ? 'Пункт чек-листа добавлен' : '';
        $error = $result['success'] ? '' : $result['error'];
    } elseif ($action === 'toggle_checklist') {
        $result = $orderObj->toggleChecklistItem((int)($_POST['checklist_id'] ?? 0), $_SESSION['user_id'], !empty($_POST['done']));
        $success = $result['success'] ? 'Чек-лист обновлён' : '';
        $error = $result['success'] ? '' : $result['error'];
    } elseif ($action === 'claim_order' && in_array(currentRole(), ['organizer', 'admin'], true)) {
        $result = $orderObj->claimOrder($orderId, $_SESSION['user_id']);
        $success = $result['success'] ? 'Вы взяли заказ в работу. Клиентский чат создан.' : '';
        $error = $result['success'] ? '' : $result['error'];
    } elseif ($action === 'add_team' && in_array(currentRole(), ['organizer', 'admin'], true)) {
        $result = $orderObj->addTeamMember($orderId, (int)$_POST['worker_id'], $_SESSION['user_id'], $_POST);
        $success = $result['success'] ? 'Сотрудник добавлен' : '';
        $error = $result['success'] ? '' : $result['error'];
    } elseif ($action === 'team_visibility' && in_array(currentRole(), ['organizer', 'admin'], true)) {
        $result = $orderObj->setTeamClientVisibility(
            (int)($_POST['team_member_id'] ?? 0),
            (int)$_SESSION['user_id'],
            !empty($_POST['visible'])
        );
        $success = $result['success'] ? 'Видимость участника для клиента обновлена' : '';
        $error = $result['success'] ? '' : $result['error'];
    } elseif ($action === 'apply_template' && can('manage_templates')) {
        $result = (new OrderTemplate())->apply((int)($_POST['template_id'] ?? 0), $orderId, (int)$_SESSION['user_id']);
        $success = $result['success'] ? 'Шаблон скопирован в заказ' : '';
        if ($result['success'] && !empty($result['warnings'])) {
            $success .= '. Замечания: ' . implode('; ', $result['warnings']);
        }
        $error = $result['success'] ? '' : $result['error'];
    }
    $order = $orderObj->getById($orderId);
}
$statuses = $orderObj->statuses();
$isAssignedOrganizer = !empty($order['organizer_id']) && (int)$order['organizer_id'] === (int)$_SESSION['user_id'];
$canManageOrder = $orderObj->canMutate($order, (int)$_SESSION['user_id'], currentRole());
$projectOverview = $canManageOrder
    ? (new OrganizerProjectOverview())->build($order, (int)$_SESSION['user_id'])
    : null;
$isOpenOrganizerPreview = currentRole() === 'organizer' && empty($order['organizer_id']);
$materials = $canManageOrder ? $orderObj->getMaterials($orderId) : [];
$tasks = $canManageOrder ? $orderObj->getTasks($orderId) : [];
$checklistItems = $canManageOrder && dbTableExists('order_checklist_items')
    ? $orderObj->getChecklist($orderId)
    : [];
$team = $canManageOrder ? $orderObj->getTeam($orderId) : [];
$workers = $canManageOrder ? $userObj->getAllWorkers() : [];
$history = $orderObj->getStatusHistory($orderId);
$chat = $canManageOrder ? (new Chat())->getOrderClientChat($orderId) : null;
$templates = dbColumnExists('event_templates', 'template_payload') && can('manage_templates') ? (new OrderTemplate())->all() : [];
$leadReferences = !empty($order['lead_id']) && ($canManageOrder || currentRole() === 'manager')
    ? (new Lead())->getFiles((int)$order['lead_id'])
    : [];
$eventTypeLabel = $order['event_type'] ?: 'Событие';
$displayTitle = $order['title'];
if ($isOpenOrganizerPreview) {
    $displayTitle = $eventTypeLabel . ' #' . (int)$order['id'];
}
$venueDisplay = $order['venue'];
if (!$venueDisplay) {
    $venueDisplay = $order['address'] ?: 'уточняется';
}
$clientNameDisplay = trim($order['client_first_name'] . ' ' . $order['client_last_name']);
$clientPhoneDisplay = $order['client_phone'] ?: 'не указан';
if ($isOpenOrganizerPreview) {
    $clientNameDisplay = 'Клиент будет открыт после отклика';
    $clientPhoneDisplay = 'доступен после отклика';
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo escape($displayTitle); ?> - CRM</title>
    <meta name="robots" content="noindex">
    <link rel="stylesheet" href="<?php echo asset('assets/css/style.min.css'); ?>">
<?php include_once __DIR__ . '/../includes/favicon-links.php'; ?></head>
<body>
<?php include_once $headerFile; ?>
<main class="event-management-page">
    <div class="container">
        <div class="event-header">
            <div>
                <p class="eyebrow">Заказ</p>
                <h1><?php echo escape($displayTitle); ?></h1>
                <p><?php echo escape($clientNameDisplay); ?> · <?php echo $order['event_date'] ? formatRussianDate($order['event_date'], 'date_only') : 'дата уточняется'; ?></p>
            </div>
            <div class="header-actions">
                <?php if ($chat && $canManageOrder): ?><a href="/chat.php?id=<?php echo (int)$chat['id']; ?>" class="btn btn-primary">Чат клиента</a><?php endif; ?>
                <?php if (in_array(currentRole(), ['organizer', 'admin'], true) && empty($order['organizer_id'])): ?>
                    <form method="POST" class="inline-form">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="action" value="claim_order">
                        <button class="btn btn-primary" type="submit">Ответить на заказ</button>
                    </form>
                <?php endif; ?>
                <a href="/organizer/orders.php" class="btn btn-outline">К списку</a>
            </div>
        </div>
        <?php if ($error): ?><div class="alert alert-error"><?php echo escape($error); ?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert alert-success"><?php echo escape($success); ?></div><?php endif; ?>
        <?php if ($canManageOrder): ?>
        <nav class="workspace-tabs order-quick-links" aria-label="Рабочие разделы заказа">
            <a href="/organizer/order-workspace.php?id=<?php echo $orderId; ?>&section=documents">Документы</a>
            <?php if (can('view_finance')): ?><a href="/organizer/order-workspace.php?id=<?php echo $orderId; ?>&section=finance">Финансы</a><?php endif; ?>
            <?php if (can('manage_timeline')): ?><a href="/organizer/order-workspace.php?id=<?php echo $orderId; ?>&section=timeline">Таймлайн</a><?php endif; ?>
            <?php if (can('manage_concepts')): ?><a href="/organizer/order-workspace.php?id=<?php echo $orderId; ?>&section=concept">Концепция</a><?php endif; ?>
        </nav>
        <section class="project-command-center" aria-labelledby="project-overview-title">
            <div class="project-command-center__heading">
                <div><p class="eyebrow">Один экран проекта</p><h2 id="project-overview-title">Сегодня важно</h2></div>
                <div class="project-quick-actions" aria-label="Быстрые действия">
                    <?php if ($chat): ?><a href="/chat.php?id=<?php echo (int)$chat['id']; ?>">Написать клиенту</a><?php endif; ?>
                    <a href="#order-tasks">Добавить задачу</a>
                    <a href="/organizer/add-staff.php?<?php echo !empty($order['event_id']) ? 'event_id=' . (int)$order['event_id'] : 'order_id=' . $orderId; ?>">Добавить участника</a>
                    <a href="/organizer/calendar.php?order_id=<?php echo $orderId; ?>">Календарь</a>
                    <a href="/organizer/order-workspace.php?id=<?php echo $orderId; ?>&section=documents">Создать документ</a>
                    <a href="/organizer/order-workspace.php?id=<?php echo $orderId; ?>&section=concept">Концепция</a>
                    <?php if (can('view_finance')): ?><a href="/organizer/order-workspace.php?id=<?php echo $orderId; ?>&section=finance">Добавить расход</a><?php endif; ?>
                    <?php if (can('manage_timeline')): ?><a href="/organizer/order-workspace.php?id=<?php echo $orderId; ?>&section=timeline">Обновить таймлайн</a><?php endif; ?>
                </div>
            </div>
            <?php if ($projectOverview['attention']): ?>
                <div class="project-attention-list">
                    <?php foreach ($projectOverview['attention'] as $attention): ?>
                        <a class="project-attention-item is-<?php echo escape($attention['level']); ?>" href="<?php echo escape($attention['href']); ?>"><span aria-hidden="true"></span><strong><?php echo escape($attention['title']); ?></strong><small><?php echo escape($attention['detail']); ?></small></a>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="project-all-clear">Срочных сигналов нет. Ближайшие действия можно открыть в разделах проекта.</p>
            <?php endif; ?>
        </section>
        <section class="panel-section project-state" aria-labelledby="project-state-title">
            <div class="section-header"><div><p class="eyebrow">Состояние проекта</p><h2 id="project-state-title">Контрольные показатели</h2></div></div>
            <div class="project-state-grid">
                <a href="#order-tasks"><span>Задачи</span><strong><?php echo (int)$projectOverview['tasks']['open']; ?></strong><small><?php echo (int)$projectOverview['tasks']['overdue']; ?> просрочено</small></a>
                <a href="/organizer/calendar.php?order_id=<?php echo $orderId; ?>"><span>Календарь</span><strong><?php echo (int)$projectOverview['calendar']['upcoming']; ?></strong><small>предстоящих записей</small></a>
                <a href="#order-team"><span>Команда</span><strong><?php echo (int)$projectOverview['team']['total']; ?></strong><small><?php echo (int)$projectOverview['team']['pending']; ?> ждут ответа</small></a>
                <a href="/organizer/order-workspace.php?id=<?php echo $orderId; ?>&section=documents"><span>Документы</span><strong><?php echo (int)$projectOverview['documents']['total']; ?></strong><small><?php echo (int)$projectOverview['documents']['waiting']; ?> ждут решения</small></a>
                <a href="/organizer/order-workspace.php?id=<?php echo $orderId; ?>&section=concept"><span>Концепции</span><strong><?php echo (int)$projectOverview['concepts']['total']; ?></strong><small><?php echo (int)$projectOverview['concepts']['waiting']; ?> ждут решения</small></a>
                <a href="/organizer/order-workspace.php?id=<?php echo $orderId; ?>&section=timeline"><span>Таймлайн</span><strong><?php echo (int)$projectOverview['timeline']['upcoming']; ?></strong><small>предстоящих этапов</small></a>
                <?php if (can('view_finance')): ?><a href="/organizer/order-workspace.php?id=<?php echo $orderId; ?>&section=finance"><span>Платежи</span><strong><?php echo (int)$projectOverview['payments']['unpaid']; ?></strong><small><?php echo number_format((float)$projectOverview['payments']['amount_due'], 0, ',', ' '); ?> ₽ ожидается</small></a><?php endif; ?>
                <?php if ($chat): ?><a href="/chat.php?id=<?php echo (int)$chat['id']; ?>"><span>Сообщения</span><strong><?php echo (int)$projectOverview['messages']['unread']; ?></strong><small>непрочитанных</small></a><?php endif; ?>
            </div>
        </section>
        <?php endif; ?>
        <?php if (in_array(currentRole(), ['organizer', 'admin'], true) && empty($order['organizer_id'])): ?>
            <section class="claim-panel">
                <div>
                    <p class="eyebrow">Открытый заказ</p>
                    <h2>Менеджер уже собрал вводные</h2>
                    <p>Нажмите “Ответить на заказ”, чтобы закрепить заказ за собой, открыть чат с клиентом и начать планирование команды, материалов и тайминга.</p>
                </div>
                <form method="POST">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="claim_order">
                    <button class="btn btn-primary btn-lg" type="submit">Ответить на заказ</button>
                </form>
            </section>
        <?php endif; ?>
        <div class="event-content-grid">
            <section class="info-card">
                <h2>Информация</h2>
                <div class="info-list">
                    <div class="info-row"><div><div class="info-label">Статус</div><div class="info-value"><?php echo escape($statuses[$order['status']] ?? $order['status']); ?></div></div></div>
                    <div class="info-row"><div><div class="info-label">Телефон клиента</div><div class="info-value"><?php echo escape($clientPhoneDisplay); ?></div></div></div>
                    <div class="info-row"><div><div class="info-label">Площадка</div><div class="info-value"><?php echo escape($order['venue'] ?: 'Площадка уточняется'); ?></div></div></div>
                    <div class="info-row"><div><div class="info-label">Адрес</div><div class="info-value"><?php echo escape($order['address'] ?: 'Адрес уточняется'); ?></div></div></div>
                    <div class="info-row"><div><div class="info-label">Бюджет</div><div class="info-value"><?php echo escape($order['budget_range'] ?: 'не указан'); ?></div></div></div>
                    <div class="info-row"><div><div class="info-label">Комментарий клиента</div><div class="info-value"><?php echo nl2br(escape($order['client_comment'] ?: '')); ?></div></div></div>
                </div>
            </section>
            <section class="info-card">
                <?php if ($canManageOrder): ?>
                <h2>Сменить статус</h2>
                <form method="POST" class="stack-form">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="update_status">
                    <label for="order-status">Статус заказа</label>
                    <select id="order-status" name="status" class="form-control">
                        <?php foreach ($statuses as $key => $label): ?>
                            <option value="<?php echo escape($key); ?>" <?php echo $order['status'] === $key ? 'selected' : ''; ?>><?php echo escape($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label for="order-status-comment">Комментарий к изменению</label>
                    <input id="order-status-comment" type="text" name="comment" class="form-control" placeholder="Комментарий к изменению">
                    <button class="btn btn-primary" type="submit">Обновить</button>
                </form>
                <?php else: ?>
                    <h2>Текущий статус</h2>
                    <div class="read-only-status">
                        <span class="badge"><?php echo escape($statuses[$order['status']] ?? $order['status']); ?></span>
                        <p>После создания заказа менеджеру доступен обзор. Подготовкой, командой и статусами управляет ответственный организатор.</p>
                    </div>
                <?php endif; ?>
            </section>
        </div>
        <?php if ($canManageOrder): ?>
        <section class="panel-section venue-editor-panel">
            <div class="section-header"><div><p class="eyebrow">Локация события</p><h2>Площадка и адрес</h2></div></div>
            <form method="POST" class="event-form compact-form">
                <?php echo csrfField(); ?><input type="hidden" name="action" value="update_venue">
                <div class="form-row"><div class="form-group"><label for="order-venue">Название площадки</label><input id="order-venue" name="venue" class="form-control" maxlength="190" value="<?php echo escape($order['venue'] ?? ''); ?>" placeholder="Площадка уточняется"></div><div class="form-group"><label for="order-address">Адрес площадки</label><input id="order-address" name="address" class="form-control" maxlength="255" value="<?php echo escape($order['address'] ?? ''); ?>" placeholder="Адрес уточняется"></div></div>
                <button class="btn btn-primary" type="submit">Сохранить локацию</button>
            </form>
        </section>
        <?php endif; ?>
        <?php if ($canManageOrder): ?>
        <section class="panel-section" id="order-materials">
            <div class="section-header"><h2>Материалы заказа</h2></div>
            <form method="POST" enctype="multipart/form-data" class="event-form compact-form">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="add_material">
                <div class="form-row">
                    <div class="form-group"><label for="material-title">Название</label><input id="material-title" type="text" name="title" class="form-control" placeholder="Название" required></div>
                    <div class="form-group"><label for="material-type">Тип материала</label><select id="material-type" name="material_type" class="form-control">
                        <?php foreach (['pdf','photo','text','estimate','contract','concept','timeline','plan','moodboard','file'] as $type): ?><option value="<?php echo $type; ?>"><?php echo $type; ?></option><?php endforeach; ?>
                    </select></div>
                    <div class="form-group"><label for="material-visibility">Видимость</label><select id="material-visibility" name="visibility" class="form-control"><option value="internal">только внутренне</option><option value="client">показать клиенту</option></select></div>
                </div>
                <label for="material-body">Текстовый материал или примечание</label>
                <textarea id="material-body" name="body" class="form-control" rows="3" placeholder="Текстовый материал или примечание"></textarea>
                <label for="material-file">Файл материала</label>
                <input id="material-file" type="file" name="material_file" class="form-control" accept="image/jpeg,image/png,image/webp,application/pdf,text/plain">
                <button class="btn btn-primary" type="submit">Добавить материал</button>
            </form>
            <div class="materials-grid">
                <?php foreach ($materials as $material): ?>
                    <article class="material-card">
                        <span class="badge"><?php echo escape($material['visibility']); ?></span>
                        <h3><?php echo escape($material['title']); ?></h3>
                        <p><?php echo escape($material['material_type']); ?></p>
                        <?php if ($material['file_path']): ?><a href="<?php echo escape(secureFileUrl('material', $material['id'])); ?>" class="btn btn-sm btn-outline" target="_blank" rel="noopener">Файл</a><?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>
        <?php if ($leadReferences): ?>
        <section class="panel-section order-reference-panel">
            <div class="section-header"><div><p class="eyebrow">Из заявки</p><h2>Референсы клиента</h2></div><span class="badge"><?php echo count($leadReferences); ?></span></div>
            <div class="lead-reference-grid">
                <?php foreach ($leadReferences as $file): $fileUrl=secureFileUrl('lead',(int)$file['id']); ?>
                    <?php if(str_starts_with((string)$file['mime_type'],'image/')): ?><a class="lead-reference-card" href="<?php echo escape($fileUrl); ?>" target="_blank" rel="noopener"><img src="<?php echo escape($fileUrl.'&variant=thumb'); ?>" alt="<?php echo escape($file['file_name']?:'Референс клиента'); ?>" width="360" height="240" loading="lazy"><span><?php echo escape($file['file_name']?:'Референс'); ?></span></a><?php else: ?><a class="lead-reference-card lead-reference-file" href="<?php echo escape($fileUrl); ?>" target="_blank" rel="noopener"><span>Документ</span><strong><?php echo escape($file['file_name']?:'Открыть файл'); ?></strong></a><?php endif; ?>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>        <?php if ($canManageOrder && dbTableExists('order_checklist_items')): ?>
        <section class="panel-section" id="order-checklist">
            <div class="section-header"><h2>Чек-лист заказа</h2></div>
            <form method="POST" class="inline-form">
                <?php echo csrfField(); ?><input type="hidden" name="action" value="add_checklist">
                <label class="sr-only" for="checklist-title">Новый пункт чек-листа</label>
                <input id="checklist-title" name="checklist_title" class="form-control" maxlength="500" placeholder="Новый пункт" required>
                <button class="btn btn-primary" type="submit">Добавить</button>
            </form>
            <ul class="checklist order-checklist">
                <?php foreach ($checklistItems as $check): ?>
                <li class="<?php echo $check['is_done'] ? 'done' : ''; ?>">
                    <form method="POST" class="inline-form"><?php echo csrfField(); ?><input type="hidden" name="action" value="toggle_checklist"><input type="hidden" name="checklist_id" value="<?php echo (int)$check['id']; ?>"><input type="hidden" name="done" value="<?php echo $check['is_done'] ? '0' : '1'; ?>"><button type="submit" class="check-button" aria-label="Изменить выполнение"></button></form>
                    <span><?php echo escape($check['title']); ?></span>
                </li>
                <?php endforeach; ?>
            </ul>
        </section>
        <?php endif; ?>
        <?php if ($canManageOrder): ?>
        <section class="panel-section" id="order-tasks">
            <div class="section-header"><h2>Задачи</h2></div>
            <form method="POST" class="event-form compact-form">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="add_task">
                <div class="form-row">
                    <div class="form-group"><label for="task-title">Задача</label><input id="task-title" type="text" name="title" class="form-control" placeholder="Задача" required></div>
                    <div class="form-group"><label for="task-assignee">Исполнитель</label><select id="task-assignee" name="assigned_to" class="form-control">
                        <option value="">Без исполнителя</option>
                        <?php foreach ($workers as $worker): ?><option value="<?php echo (int)$worker['id']; ?>"><?php echo escape($worker['first_name'] . ' ' . $worker['last_name'] . ' · ' . $worker['specialty']); ?></option><?php endforeach; ?>
                    </select></div>
                    <div class="form-group"><label for="task-due-date">Срок</label><input id="task-due-date" type="date" name="due_date" class="form-control"></div>
                    <div class="form-group"><label for="task-visibility">Видимость</label><select id="task-visibility" name="visibility" class="form-control"><option value="internal">внутренне</option><option value="client">клиенту</option></select></div>
                </div>
                <label for="task-description">Описание</label>
                <textarea id="task-description" name="description" class="form-control" rows="2" placeholder="Описание"></textarea>
                <button class="btn btn-primary" type="submit">Добавить задачу</button>
            </form>
            <div class="task-list">
                <?php foreach ($tasks as $task): ?>
                    <article class="task-item"><span class="badge badge-<?php echo escape($task['status']); ?>"><?php echo escape(['todo'=>'К выполнению','in_progress'=>'В работе','done'=>'Выполнено','cancelled'=>'Отменено'][$task['status']] ?? $task['status']); ?></span><h3><?php echo escape($task['title']); ?></h3><p><?php echo escape($task['description']); ?></p><form method="POST" class="inline-form"><?php echo csrfField(); ?><input type="hidden" name="action" value="task_status"><input type="hidden" name="task_id" value="<?php echo (int)$task['id']; ?>"><label class="sr-only" for="task-status-<?php echo (int)$task['id']; ?>">Статус задачи <?php echo escape($task['title']); ?></label><select id="task-status-<?php echo (int)$task['id']; ?>" name="status" class="form-control form-control-sm"><?php foreach(['todo'=>'К выполнению','in_progress'=>'В работе','done'=>'Выполнено','cancelled'=>'Отменено'] as $key=>$label): ?><option value="<?php echo $key; ?>" <?php echo $task['status']===$key?'selected':''; ?>><?php echo $label; ?></option><?php endforeach; ?></select><button class="btn btn-sm btn-outline" type="submit">Сохранить</button></form></article>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>
        <?php if (in_array(currentRole(), ['organizer', 'admin'], true) && $canManageOrder): ?>
        <section class="panel-section" id="order-team">
            <div class="section-header"><div><h2>Состав команды</h2><p class="section-note">Сначала выберите роль, затем свободного сотрудника. Ставка берётся из профиля, а клиент видит назначенный состав автоматически.</p></div><a class="btn btn-primary" href="/organizer/add-staff.php?<?php echo !empty($order['event_id']) ? 'event_id=' . (int)$order['event_id'] : 'order_id=' . (int)$orderId; ?>">Добавить сотрудника</a></div>
            <div class="team-grid">
                <?php foreach ($team as $member): ?>
                    <article class="team-member-card">
                        <div class="member-header">
                            <div><h3><?php echo escape($member['first_name'] . ' ' . $member['last_name']); ?></h3><span class="badge"><?php echo escape($member['specialty'] ?: $member['role']); ?></span></div>
                        </div>
                        <div class="member-body">
                            <p>Ставка из профиля: <?php echo number_format((float)$member['hourly_rate'], 0, ',', ' '); ?> ₽/час · План: <?php echo (float)$member['planned_hours']; ?> ч</p>
                            <p><?php echo escape($member['tasks_summary']); ?></p>
                            <form method="POST" class="inline-form">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="team_visibility">
                                <input type="hidden" name="team_member_id" value="<?php echo (int)$member['id']; ?>">
                                <input type="hidden" name="visible" value="<?php echo !empty($member['show_to_client']) ? '0' : '1'; ?>">
                                <span class="muted"><?php echo !empty($member['show_to_client']) ? 'Показывается клиенту' : 'Скрыт от клиента'; ?></span>
                                <button class="btn btn-sm btn-outline" type="submit"><?php echo !empty($member['show_to_client']) ? 'Скрыть' : 'Показать'; ?></button>
                            </form>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>
        <?php if ($templates && $canManageOrder): ?>
        <section class="panel-section" id="order-templates">
            <div class="section-header"><div><p class="eyebrow">Быстрый старт</p><h2>Применить шаблон заказа</h2></div></div>
            <p class="section-note">Задачи, платежи, расходы и таймлайн копируются в этот заказ. Последующие изменения шаблона на заказ не повлияют.</p>
            <div class="order-template-preview-grid">
                <?php foreach ($templates as $template): ?>
                    <?php
                    $templatePayload = json_decode((string)($template['template_payload'] ?? ''), true);
                    $templatePayload = is_array($templatePayload) ? $templatePayload : [];
                    $templateCounts = [];
                    foreach (['tasks'=>'задач','checklists'=>'проверок','roles'=>'ролей','timeline'=>'этапов','expenses'=>'расходов','payments'=>'платежей','documents'=>'документов','notifications'=>'напоминаний'] as $payloadKey => $payloadLabel) {
                        $count = is_array($templatePayload[$payloadKey] ?? null) ? count($templatePayload[$payloadKey]) : 0;
                        if ($count > 0) {
                            $templateCounts[] = $count . ' ' . $payloadLabel;
                        }
                    }
                    ?>
                    <article class="order-template-preview">
                        <div><span class="badge"><?php echo escape($template['event_type'] ?: 'Проект'); ?></span><h3><?php echo escape($template['template_name']); ?></h3><p><?php echo escape($template['description'] ?: 'Готовый сценарий проекта'); ?></p></div>
                        <p class="order-template-preview__counts"><?php echo escape($templateCounts ? implode(' · ', $templateCounts) : 'Базовые данные проекта'); ?></p>
                        <form method="POST" data-template-apply data-template-name="<?php echo escape($template['template_name']); ?>">
                            <?php echo csrfField(); ?><input type="hidden" name="action" value="apply_template"><input type="hidden" name="template_id" value="<?php echo (int)$template['id']; ?>">
                            <button class="btn btn-primary btn-block" type="submit">Применить шаблон</button>
                        </form>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>
    </div>
</main>
<?php include_once __DIR__ . '/../includes/organizer-footer.php'; ?>
<script>
document.querySelectorAll('[data-template-apply]').forEach(function (form) {
    form.addEventListener('submit', function (event) {
        if (!window.confirm('Применить шаблон «' + form.dataset.templateName + '»? Все элементы будут скопированы в заказ один раз.')) {
            event.preventDefault();
        }
    });
});
</script>
</body>
</html>
