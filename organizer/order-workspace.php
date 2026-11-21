<?php
require_once __DIR__ . '/../config/config.php';
requireCrmPanel();
if (currentRole() === 'manager') {
    http_response_code(403);
    require_once __DIR__ . '/../403.php';
    exit;
}
$orderId = (int)($_GET['id'] ?? 0);
$section = (string)($_GET['section'] ?? 'documents');
$allowedSections = ['documents', 'finance', 'timeline', 'concept'];
if (!in_array($section, $allowedSections, true)) {
    http_response_code(404);
    require_once __DIR__ . '/../404.php';
    exit;
}
$orderObj = new Order();
$order = $orderObj->getById($orderId);
if (!$order) {
    http_response_code(404);
    require_once __DIR__ . '/../404.php';
    exit;
}
if (!$orderObj->canCollaborate($order, (int)$_SESSION['user_id'], (string)currentRole())) {
    http_response_code(403);
    require_once __DIR__ . '/../403.php';
    exit;
}
$canManageOrder = $orderObj->canMutate($order, (int)$_SESSION['user_id'], (string)currentRole());
$error = '';
$success = '';
$publicDocumentUrl = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    try {
        $action = (string)($_POST['action'] ?? '');
        $result = ['success' => false, 'error' => 'Недопустимое действие'];
        if (!$canManageOrder) {
            $result = ['success' => false, 'error' => 'Этот заказ недоступен для изменения'];
        } elseif ($action === 'document_create' && can('manage_documents')) {
            $result = (new OrderDocument())->create($orderId, (int)$_SESSION['user_id'], $_POST);
        } elseif ($action === 'document_version' && can('manage_documents')) {
            $result = (new OrderDocument())->addVersion((int)($_POST['document_id'] ?? 0), (int)$_SESSION['user_id'], $_POST['body_html'] ?? '');
        } elseif ($action === 'document_send' && can('manage_documents')) {
            $result = (new OrderDocument())->sendToClient((int)($_POST['document_id'] ?? 0), (int)$_SESSION['user_id']);
        } elseif ($action === 'document_comment' && can('manage_documents')) {
            $result = (new OrderDocument())->addComment((int)($_POST['document_id'] ?? 0), (int)$_SESSION['user_id'], $_POST['comment'] ?? '');
        } elseif ($action === 'finance_plan' && can('manage_finance')) {
            $result = (new OrderFinance())->updatePlan($orderId, (int)$_SESSION['user_id'], $_POST);
        } elseif ($action === 'payment_add' && can('manage_finance')) {
            $result = (new OrderFinance())->addPayment($orderId, (int)$_SESSION['user_id'], $_POST, $_FILES['proof'] ?? null);
        } elseif ($action === 'payment_status' && can('manage_finance')) {
            $result = (new OrderFinance())->updatePaymentStatus((int)($_POST['payment_id'] ?? 0), (int)$_SESSION['user_id'], $_POST['status'] ?? 'planned', $_POST['paid_at'] ?? null);
        } elseif ($action === 'expense_add' && can('manage_finance')) {
            $result = (new OrderFinance())->addExpense($orderId, (int)$_SESSION['user_id'], $_POST, $_FILES['proof'] ?? null);
        } elseif ($action === 'timeline_create' && can('manage_timeline')) {
            $checklist = preg_split('/\r?\n/', trim((string)($_POST['checklist'] ?? '')), -1, PREG_SPLIT_NO_EMPTY);
            $result = (new OrderTimeline())->create($orderId, (int)$_SESSION['user_id'], $_POST, $_POST['participants'] ?? [], $checklist, $_FILES['timeline_files'] ?? []);
        } elseif ($action === 'timeline_status' && can('manage_timeline')) {
            $result = (new OrderTimeline())->changeStatus((int)($_POST['item_id'] ?? 0), $_POST['status'] ?? 'planned', (int)$_SESSION['user_id']);
        } elseif ($action === 'timeline_move' && can('manage_timeline')) {
            $result = (new OrderTimeline())->move((int)($_POST['item_id'] ?? 0), $_POST['direction'] ?? '', (int)$_SESSION['user_id']);
        } elseif ($action === 'timeline_check' && can('manage_timeline')) {
            $result = (new OrderTimeline())->toggleChecklist((int)($_POST['checklist_id'] ?? 0), (int)$_SESSION['user_id'], !empty($_POST['done']));
        } elseif ($action === 'concept_create' && can('manage_concepts')) {
            $result = (new OrderConcept())->createVersion($orderId, (int)$_SESSION['user_id'], $_POST, $_FILES['concept_files'] ?? []);
        } elseif ($action === 'concept_send' && can('manage_concepts')) {
            $result = (new OrderConcept())->send((int)($_POST['version_id'] ?? 0), (int)$_SESSION['user_id']);
        } elseif ($action === 'concept_comment' && can('manage_concepts')) {
            $result = (new OrderConcept())->comment((int)($_POST['version_id'] ?? 0), (int)$_SESSION['user_id'], $_POST['comment'] ?? '');
        }
        $success = !empty($result['success']) ? 'Изменения сохранены.' : '';
        $publicDocumentUrl = !empty($result['success']) && !empty($result['public_url']) ? (string)$result['public_url'] : '';
        $error = !empty($result['success']) ? '' : (string)($result['error'] ?? 'Не удалось сохранить изменения');
    } catch (Throwable $exception) {
        error_log('Order workspace POST failed for order ' . $orderId . ', section ' . $section . ': ' . $exception->getMessage());
        $error = publicExceptionMessage($exception, 'Не удалось сохранить изменения. Проверьте миграции базы данных.');
    }
}
$documents = [];
$documentTemplates = [];
$finance = [
    'total_cost'=>0, 'planned_revenue'=>0, 'prepayment'=>0, 'planned_expenses'=>0,
    'actual_revenue'=>0, 'actual_expenses'=>0, 'planned_worker_payments'=>0,
    'actual_worker_payments'=>0, 'client_debt'=>0, 'actual_profit'=>0, 'margin_percent'=>0,
];
$payments = [];
$expenses = [];
$timeline = [];
$timelineProgress = ['percent'=>0, 'total'=>0, 'completed'=>0, 'delayed'=>0];
$concept = null;
$timelinePeople = [];
try {
    if ($section === 'documents') {
        if (!dbTableExists('order_documents') || !dbTableExists('document_versions')) {
            throw new ConflictException('Раздел документов требует обновления базы данных');
        }
        $documentService = new OrderDocument();
        $documents = $documentService->getForOrder($orderId);
        $documentTemplates = dbTableExists('document_templates') ? $documentService->templates() : [];
    } elseif ($section === 'finance') {
        if (!can('view_finance')) {
            throw new AccessDeniedException('Финансовые данные недоступны вашей роли');
        }
        foreach (['order_finance', 'order_payments', 'order_expenses'] as $requiredTable) {
            if (!dbTableExists($requiredTable)) {
                throw new ConflictException('Раздел финансов требует обновления базы данных');
            }
        }
        $financeService = new OrderFinance();
        $finance = $financeService->summary($orderId);
        $payments = $financeService->payments($orderId, $_GET);
        $expenses = $financeService->expenses($orderId, $_GET);
    } elseif ($section === 'timeline') {
        if (!dbTableExists('order_timeline_items')) {
            throw new ConflictException('Раздел таймлайна требует обновления базы данных');
        }
        $timelineService = new OrderTimeline();
        $timeline = $timelineService->getForOrder($orderId);
        $timelineProgress = $timelineService->progress($orderId);
        $team = dbTableExists('order_team') ? $orderObj->getTeam($orderId) : [];
        foreach ([
            ['id'=>$order['organizer_id'] ?? null,'first_name'=>$order['organizer_first_name'] ?? '','last_name'=>$order['organizer_last_name'] ?? '','role_label'=>'Организатор'],
        ] as $person) {
            if (!empty($person['id'])) {
                $timelinePeople[(int)$person['id']] = $person;
            }
        }
        foreach ($team as $member) {
            $timelinePeople[(int)$member['worker_id']] = [
                'id'=>(int)$member['worker_id'],
                'first_name'=>$member['first_name'],
                'last_name'=>$member['last_name'],
                'role_label'=>$member['public_role'] ?: ($member['specialty'] ?? 'Сотрудник'),
            ];
        }
    } elseif ($section === 'concept') {
        if (!dbTableExists('order_concepts') || !dbTableExists('concept_versions')) {
            throw new ConflictException('Раздел концепции требует обновления базы данных');
        }
        $concept = (new OrderConcept())->getForOrder($orderId);
    }
} catch (Throwable $exception) {
    error_log('Order workspace load failed for order ' . $orderId . ', section ' . $section . ': ' . $exception->getMessage());
    if ($error === '') {
        $error = publicExceptionMessage($exception, 'Раздел временно недоступен. Проверьте миграции базы данных.');
    }
}
$headerFile = __DIR__ . '/../includes/organizer-header.php';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title><?php echo escape($order['title']); ?> - рабочее пространство</title>
    <link rel="stylesheet" href="<?php echo asset('assets/css/style.min.css'); ?>">
<?php include_once __DIR__ . '/../includes/favicon-links.php'; ?></head>
<body>
<?php include_once $headerFile; ?>
<main class="dashboard order-workspace">
    <div class="container">
        <header class="dashboard-header workspace-header">
            <div><p class="eyebrow">Заказ №<?php echo $orderId; ?></p><h1><?php echo escape($order['title']); ?></h1><p><?php echo escape($order['event_type'] ?: 'Событие'); ?> · <?php echo $order['event_date'] ? escape(formatRussianDate($order['event_date'], 'date_only')) : 'Дата уточняется'; ?></p></div>
            <a href="/organizer/order.php?id=<?php echo $orderId; ?>" class="btn btn-outline">Основная карточка</a>
        </header>
        <nav class="workspace-tabs" aria-label="Разделы заказа">
            <?php foreach (['documents'=>'Документы','finance'=>'Финансы','timeline'=>'Таймлайн','concept'=>'Концепция'] as $key=>$label): ?><a class="<?php echo $section===$key?'active':''; ?>" href="?id=<?php echo $orderId; ?>&section=<?php echo $key; ?>"><?php echo $label; ?></a><?php endforeach; ?>
        </nav>
        <?php if ($error): ?><div class="alert alert-error"><?php echo escape($error); ?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert alert-success"><?php echo escape($success); ?></div><?php endif; ?>
        <?php if ($publicDocumentUrl): ?><div class="alert alert-success">Безопасная ссылка действует 30 дней: <a href="<?php echo escape($publicDocumentUrl); ?>" target="_blank" rel="noopener"><?php echo escape($publicDocumentUrl); ?></a></div><?php endif; ?>
        <?php if ($section === 'documents'): ?>
            <section class="panel-section">
                <div class="section-header"><div><p class="eyebrow">Версионность</p><h2>Документы заказа</h2></div></div>
                <?php if (can('manage_documents') && $canManageOrder): ?>
                <form method="POST" class="event-form compact-form">
                    <?php echo csrfField(); ?><input type="hidden" name="action" value="document_create">
                    <div class="form-row">
                        <div class="form-group"><label for="document_type">Тип</label><select id="document_type" name="document_type" class="form-control"><?php foreach ((new OrderDocument())->types() as $key=>$label): ?><option value="<?php echo $key; ?>"><?php echo escape($label); ?></option><?php endforeach; ?></select></div>
                        <div class="form-group"><label for="document_title">Название</label><input id="document_title" name="title" class="form-control" required></div>
                        <div class="form-group"><label for="template_id">Шаблон</label><select id="template_id" name="template_id" class="form-control"><option value="">Без шаблона</option><?php foreach ($documentTemplates as $template): ?><option value="<?php echo (int)$template['id']; ?>"><?php echo escape($template['name']); ?></option><?php endforeach; ?></select></div>
                    </div>
                    <div class="form-group"><label for="body_html">Содержание HTML</label><textarea id="body_html" name="body_html" class="form-control document-editor" rows="10" placeholder="<h2>Предложение для {{client_name}}</h2><p>Заказ: {{order_title}}</p>"></textarea></div>
                    <button class="btn btn-primary" type="submit">Создать документ</button>
                </form>
                <?php endif; ?>
                <div class="document-list">
                    <?php foreach ($documents as $document): ?>
                    <article class="document-row">
                        <div><span class="badge"><?php echo escape((new OrderDocument())->statuses()[$document['status']] ?? $document['status']); ?></span><h3><?php echo escape($document['title']); ?></h3><p><?php echo escape($document['document_number']); ?> · версия <?php echo (int)$document['version_no']; ?></p></div>
                        <div class="row-actions"><a class="btn btn-sm btn-outline" href="/document.php?id=<?php echo (int)$document['id']; ?>" target="_blank">Просмотр и печать</a><?php if (can('manage_documents') && $canManageOrder): ?><form method="POST" class="inline-form"><?php echo csrfField(); ?><input type="hidden" name="action" value="document_send"><input type="hidden" name="document_id" value="<?php echo (int)$document['id']; ?>"><button class="btn btn-sm btn-primary" type="submit">Отправить клиенту</button></form><?php endif; ?></div><?php if (can('manage_documents') && $canManageOrder): ?><details><summary>Создать новую версию</summary><form method="POST" class="stack-form"><?php echo csrfField(); ?><input type="hidden" name="action" value="document_version"><input type="hidden" name="document_id" value="<?php echo (int)$document['id']; ?>"><label for="document-version-body-<?php echo (int)$document['id']; ?>">Содержание новой версии</label><textarea id="document-version-body-<?php echo (int)$document['id']; ?>" name="body_html" class="form-control" rows="8" required><?php echo escape($document['body_html']); ?></textarea><button class="btn btn-outline" type="submit">Сохранить новой версией</button></form></details><?php endif; ?>
                        <?php if (count($document['versions'] ?? []) > 1): ?><details class="version-history"><summary>История версий</summary><ul><?php foreach ($document['versions'] as $version): ?><li>Версия <?php echo (int)$version['version_no']; ?> · <?php echo escape(formatRussianDateTime($version['created_at'])); ?><?php if($version['first_name']): ?> · <?php echo escape(trim($version['first_name'].' '.$version['last_name'])); ?><?php endif; ?></li><?php endforeach; ?></ul></details><?php endif; ?>
                        <?php if (!empty($document['comments'])): ?><div class="document-comments"><?php foreach($document['comments'] as $comment): ?><p><strong><?php echo escape($comment['first_name']); ?>:</strong> <?php echo escape($comment['comment']); ?> <small>версия <?php echo (int)$comment['version_no']; ?></small></p><?php endforeach; ?></div><?php endif; ?>
                        <?php if (can('manage_documents') && $canManageOrder): ?><form method="POST" class="inline-form document-comment-form"><?php echo csrfField(); ?><input type="hidden" name="action" value="document_comment"><input type="hidden" name="document_id" value="<?php echo (int)$document['id']; ?>"><label class="sr-only" for="document-comment-<?php echo (int)$document['id']; ?>">Комментарий к документу</label><input id="document-comment-<?php echo (int)$document['id']; ?>" name="comment" class="form-control" placeholder="Комментарий к документу" required><button class="btn btn-sm btn-outline" type="submit">Отправить</button></form><?php endif; ?>
                    </article>
                    <?php endforeach; ?>
                    <?php if (!$documents): ?><div class="empty-state"><p>Документы ещё не созданы.</p></div><?php endif; ?>
                </div>
            </section>
        <?php elseif ($section === 'finance'): ?>
            <?php if (!can('view_finance')): ?><div class="alert alert-error">Финансовые данные недоступны вашей роли.</div><?php else: ?>
            <section class="finance-summary-grid">
                <?php foreach (['total_cost'=>'Стоимость','actual_revenue'=>'Получено','client_debt'=>'Задолженность','planned_worker_payments'=>'Команда, план','actual_worker_payments'=>'Команда, факт','actual_expenses'=>'Расходы','actual_profit'=>'Прибыль','margin_percent'=>'Маржа, %'] as $key=>$label): ?><article class="metric-card"><span><?php echo $label; ?></span><strong><?php echo $key==='margin_percent'?number_format((float)$finance[$key],1,',',' '):number_format((float)$finance[$key],0,',',' ').' ₽'; ?></strong></article><?php endforeach; ?>
            </section>
            <?php if (can('manage_finance') && $canManageOrder): ?>
            <section class="panel-section">
                <div class="section-header"><h2>Финансовый план</h2></div>
                <form method="POST" class="event-form compact-form">
                    <?php echo csrfField(); ?><input type="hidden" name="action" value="finance_plan">
                    <div class="form-row">
                        <div class="form-group"><label for="finance-total-cost">Общая стоимость</label><input id="finance-total-cost" type="number" min="0" step="0.01" name="total_cost" class="form-control" value="<?php echo escape($finance['total_cost']); ?>"></div>
                        <div class="form-group"><label for="finance-planned-revenue">Плановая выручка</label><input id="finance-planned-revenue" type="number" min="0" step="0.01" name="planned_revenue" class="form-control" value="<?php echo escape($finance['planned_revenue']); ?>"></div>
                        <div class="form-group"><label for="finance-prepayment">Предоплата</label><input id="finance-prepayment" type="number" min="0" step="0.01" name="prepayment" class="form-control" value="<?php echo escape($finance['prepayment']); ?>"></div>
                        <div class="form-group"><label for="finance-planned-expenses">Плановые расходы</label><input id="finance-planned-expenses" type="number" min="0" step="0.01" name="planned_expenses" class="form-control" value="<?php echo escape($finance['planned_expenses']); ?>"></div>
                    </div>
                    <button class="btn btn-primary" type="submit">Пересчитать</button>
                </form>
            </section>
            <div class="workspace-split">
                <section class="panel-section">
                    <div class="section-header"><h2>Добавить платёж</h2></div>
                    <form method="POST" enctype="multipart/form-data" class="stack-form">
                        <?php echo csrfField(); ?><input type="hidden" name="action" value="payment_add">
                        <label for="payment-title">Название платежа</label><input id="payment-title" name="title" class="form-control" placeholder="Название платежа" required>
                        <label for="payment-amount">Сумма</label><input id="payment-amount" type="number" min="0.01" step="0.01" name="amount" class="form-control" placeholder="Сумма" required>
                        <label for="payment-due-date">Срок оплаты</label><input id="payment-due-date" type="date" name="due_date" class="form-control">
                        <label for="payment-status">Статус</label><select id="payment-status" name="status" class="form-control"><option value="planned">Запланирован</option><option value="pending">Ожидается</option><option value="paid">Оплачен</option></select>
                        <label for="payment-method">Способ оплаты</label><input id="payment-method" name="payment_method" class="form-control" placeholder="Способ оплаты">
                        <label for="payment-comment">Комментарий</label><textarea id="payment-comment" name="comment" class="form-control" placeholder="Комментарий"></textarea>
                        <label for="payment-proof">Подтверждающий файл</label><input id="payment-proof" type="file" name="proof" class="form-control" accept="image/jpeg,image/png,image/webp,application/pdf">
                        <button class="btn btn-primary" type="submit">Добавить платёж</button>
                    </form>
                </section>
                <section class="panel-section">
                    <div class="section-header"><h2>Добавить расход</h2></div>
                    <form method="POST" enctype="multipart/form-data" class="stack-form">
                        <?php echo csrfField(); ?><input type="hidden" name="action" value="expense_add">
                        <label for="expense-category">Категория</label><select id="expense-category" name="category" class="form-control"><?php foreach (['flowers'=>'Цветы','decor'=>'Декор','rent'=>'Аренда','transport'=>'Транспорт','installation'=>'Монтаж','dismantling'=>'Демонтаж','contractors'=>'Подрядчики','staff'=>'Сотрудники','other'=>'Другое'] as $key=>$label): ?><option value="<?php echo escape($key); ?>"><?php echo escape($label); ?></option><?php endforeach; ?></select>
                        <label for="expense-title">Статья расхода</label><input id="expense-title" name="title" class="form-control" placeholder="Статья расхода" required>
                        <label for="expense-planned">Плановая сумма</label><input id="expense-planned" type="number" min="0" step="0.01" name="planned_amount" class="form-control" placeholder="План">
                        <label for="expense-actual">Фактическая сумма</label><input id="expense-actual" type="number" min="0" step="0.01" name="actual_amount" class="form-control" placeholder="Факт">
                        <label for="expense-date">Дата расхода</label><input id="expense-date" type="date" name="expense_date" class="form-control">
                        <label for="expense-comment">Комментарий</label><textarea id="expense-comment" name="comment" class="form-control" placeholder="Комментарий"></textarea>
                        <label for="expense-proof">Подтверждающий файл</label><input id="expense-proof" type="file" name="proof" class="form-control" accept="image/jpeg,image/png,image/webp,application/pdf">
                        <button class="btn btn-primary" type="submit">Добавить расход</button>
                    </form>
                </section>
            </div>
            <?php endif; ?>
            <form method="GET" class="filter-form finance-filter"><input type="hidden" name="id" value="<?php echo $orderId; ?>"><input type="hidden" name="section" value="finance"><label class="sr-only" for="finance-query">Поиск</label><input id="finance-query" name="query" class="form-control" value="<?php echo escape($_GET['query'] ?? ''); ?>" placeholder="Поиск по платежам и расходам"><label class="sr-only" for="finance-status-filter">Статус платежа</label><select id="finance-status-filter" name="status" class="form-control"><option value="">Все платежи</option><?php foreach(['planned'=>'Запланированы','pending'=>'Ожидаются','paid'=>'Оплачены','overdue'=>'Просрочены','cancelled'=>'Отменены'] as $key=>$label): ?><option value="<?php echo escape($key); ?>" <?php echo ($_GET['status']??'')===$key?'selected':''; ?>><?php echo escape($label); ?></option><?php endforeach; ?></select><label class="sr-only" for="finance-category-filter">Категория расхода</label><select id="finance-category-filter" name="category" class="form-control"><option value="">Все расходы</option><?php foreach(['flowers'=>'Цветы','decor'=>'Декор','rent'=>'Аренда','transport'=>'Транспорт','installation'=>'Монтаж','dismantling'=>'Демонтаж','contractors'=>'Подрядчики','staff'=>'Сотрудники','other'=>'Другое'] as $key=>$label): ?><option value="<?php echo escape($key); ?>" <?php echo ($_GET['category']??'')===$key?'selected':''; ?>><?php echo escape($label); ?></option><?php endforeach; ?></select><button class="btn btn-outline" type="submit">Применить</button></form>
            <section class="panel-section"><div class="section-header"><h2>Платежи</h2></div><div class="table-wrap"><table class="data-table"><thead><tr><th>Платёж</th><th>Срок</th><th>Сумма</th><th>Статус</th><th>Файл</th><th></th></tr></thead><tbody><?php foreach($payments as $payment): ?><tr><td><?php echo escape($payment['title']); ?><?php if($payment['comment']): ?><small><?php echo escape($payment['comment']); ?></small><?php endif; ?></td><td><?php echo escape($payment['due_date'] ?: '—'); ?></td><td><?php echo number_format((float)$payment['amount'],0,',',' '); ?> ₽</td><td><?php echo escape($payment['status']); ?></td><td><?php if($payment['proof_path']): ?><a href="<?php echo escape(secureFileUrl('finance_payment', $payment['id'])); ?>" target="_blank" rel="noopener">Открыть</a><?php else: ?>—<?php endif; ?></td><td><?php if(can('manage_finance') && $canManageOrder): ?><form method="POST" class="inline-form"><?php echo csrfField(); ?><input type="hidden" name="action" value="payment_status"><input type="hidden" name="payment_id" value="<?php echo (int)$payment['id']; ?>"><label class="sr-only" for="payment-row-status-<?php echo (int)$payment['id']; ?>">Статус платежа <?php echo escape($payment['title']); ?></label><select id="payment-row-status-<?php echo (int)$payment['id']; ?>" name="status" class="form-control form-control-sm"><?php foreach(['planned'=>'План','pending'=>'Ожидается','paid'=>'Оплачен','overdue'=>'Просрочен','cancelled'=>'Отменён'] as $key=>$label): ?><option value="<?php echo escape($key); ?>" <?php echo $payment['status']===$key?'selected':''; ?>><?php echo escape($label); ?></option><?php endforeach; ?></select><button class="btn btn-sm btn-outline">Изменить</button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div></section>
            <section class="panel-section"><div class="section-header"><h2>Расходы</h2></div><div class="table-wrap"><table class="data-table"><thead><tr><th>Категория</th><th>Статья</th><th>План</th><th>Факт</th><th>Дата</th><th>Файл</th></tr></thead><tbody><?php foreach($expenses as $expense): ?><tr><td><?php echo escape($expense['category']); ?></td><td><?php echo escape($expense['title']); ?><?php if($expense['comment']): ?><small><?php echo escape($expense['comment']); ?></small><?php endif; ?></td><td><?php echo number_format((float)$expense['planned_amount'],0,',',' '); ?> ₽</td><td><?php echo number_format((float)$expense['actual_amount'],0,',',' '); ?> ₽</td><td><?php echo escape($expense['expense_date'] ?: '—'); ?></td><td><?php if($expense['proof_path']): ?><a href="<?php echo escape(secureFileUrl('finance_expense', $expense['id'])); ?>" target="_blank" rel="noopener">Открыть</a><?php else: ?>—<?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div></section>
            <?php endif; ?>
        <?php elseif ($section === 'timeline'): ?>
            <section class="panel-section"><div class="section-header"><div><p class="eyebrow">Прогресс <?php echo (int)$timelineProgress['percent']; ?>%</p><h2>Таймлайн дня</h2></div></div><div class="progress-track"><span style="width:<?php echo (int)$timelineProgress['percent']; ?>%"></span></div>
            <?php if (can('manage_timeline') && $canManageOrder): ?>
                <form method="POST" enctype="multipart/form-data" class="event-form compact-form">
                    <?php echo csrfField(); ?><input type="hidden" name="action" value="timeline_create">
                    <div class="form-row">
                        <div class="form-group"><label for="timeline-title">Этап</label><input id="timeline-title" name="title" class="form-control" required></div>
                        <div class="form-group"><label for="timeline-start">Начало</label><input id="timeline-start" type="datetime-local" name="starts_at" class="form-control" required></div>
                        <div class="form-group"><label for="timeline-end">Окончание</label><input id="timeline-end" type="datetime-local" name="ends_at" class="form-control" required></div>
                        <div class="form-group"><label for="timeline-priority">Приоритет</label><select id="timeline-priority" name="priority" class="form-control"><option value="low">Низкий</option><option value="normal" selected>Обычный</option><option value="high">Высокий</option><option value="critical">Критичный</option></select></div>
                    </div>
                    <div class="form-row">
                        <div class="form-group"><label for="timeline-responsible">Ответственный</label><select id="timeline-responsible" name="responsible_id" class="form-control"><option value="">Не назначен</option><?php foreach($timelinePeople as $person): ?><option value="<?php echo (int)$person['id']; ?>"><?php echo escape(trim($person['first_name'].' '.$person['last_name']).' · '.$person['role_label']); ?></option><?php endforeach; ?></select></div>
                        <div class="form-group"><label for="timeline-participants">Участники</label><select id="timeline-participants" name="participants[]" class="form-control" multiple size="5"><?php foreach($timelinePeople as $person): ?><option value="<?php echo (int)$person['id']; ?>"><?php echo escape(trim($person['first_name'].' '.$person['last_name']).' · '.$person['role_label']); ?></option><?php endforeach; ?></select></div>
                        <div class="form-group"><label for="timeline-status">Статус</label><select id="timeline-status" name="status" class="form-control"><?php foreach((new OrderTimeline())->statuses() as $key=>$label): ?><option value="<?php echo escape($key); ?>"><?php echo escape($label); ?></option><?php endforeach; ?></select></div>
                    </div>
                    <div class="form-row">
                        <div class="form-group"><label for="timeline-description">Описание</label><textarea id="timeline-description" name="description" class="form-control" rows="3" placeholder="Описание"></textarea></div>
                        <div class="form-group"><label for="timeline-comments">Рабочие комментарии</label><textarea id="timeline-comments" name="comments" class="form-control" rows="3" placeholder="Рабочие комментарии"></textarea></div>
                        <div class="form-group"><label for="timeline-checklist">Чек-лист</label><textarea id="timeline-checklist" name="checklist" class="form-control" rows="3" placeholder="Пункты чек-листа, каждый с новой строки"></textarea></div>
                    </div>
                    <div class="form-row">
                        <div class="form-group"><label for="timeline-location">Место</label><input id="timeline-location" name="location" class="form-control" placeholder="Место"></div>
                        <div class="form-group"><label for="timeline-files">Файлы этапа</label><input id="timeline-files" type="file" name="timeline_files[]" class="form-control" accept="image/jpeg,image/png,image/webp,application/pdf,text/plain,application/vnd.openxmlformats-officedocument.wordprocessingml.document" multiple></div>
                    </div>
                    <button class="btn btn-primary" type="submit">Добавить этап</button>
                </form>
            <?php endif; ?>
            <div class="event-timeline-list">
                <?php foreach($timeline as $item): ?>
                <article class="event-timeline-item <?php echo strtotime($item['ends_at'])<time()&&!in_array($item['status'],['completed','cancelled'],true)?'is-delayed':''; ?>">
                    <time><?php echo escape(date('H:i',strtotime($item['starts_at']))); ?>–<?php echo escape(date('H:i',strtotime($item['ends_at']))); ?></time>
                    <div class="timeline-item-body">
                        <div class="timeline-meta"><span class="badge"><?php echo escape((new OrderTimeline())->statuses()[$item['status']]??$item['status']); ?></span><span><?php echo escape($item['priority']); ?></span><?php if($item['location']): ?><span><?php echo escape($item['location']); ?></span><?php endif; ?></div>
                        <h3><?php echo escape($item['title']); ?></h3>
                        <p><?php echo escape($item['description']); ?></p>
                        <?php if($item['comments']): ?><p class="section-note"><?php echo nl2br(escape($item['comments'])); ?></p><?php endif; ?>
                        <?php if(!empty($item['participants'])): ?><p class="timeline-participants"><strong>Участники:</strong> <?php echo escape(implode(', ', array_map(static fn($member) => trim($member['first_name'].' '.$member['last_name']), $item['participants']))); ?></p><?php endif; ?>
                        <?php if(!empty($item['files'])): ?><div class="timeline-files"><?php foreach($item['files'] as $file): ?><a href="<?php echo escape(secureFileUrl('timeline', $file['id'])); ?>" target="_blank" rel="noopener"><?php echo escape($file['file_name']); ?></a><?php endforeach; ?></div><?php endif; ?>
                        <ul class="checklist"><?php foreach($item['checklist'] as $check): ?><li class="<?php echo $check['is_done']?'done':''; ?>"><?php if(can('manage_timeline') && $canManageOrder): ?><form method="POST" class="inline-form"><?php echo csrfField(); ?><input type="hidden" name="action" value="timeline_check"><input type="hidden" name="checklist_id" value="<?php echo (int)$check['id']; ?>"><input type="hidden" name="done" value="<?php echo $check['is_done']?'0':'1'; ?>"><button class="check-button" aria-label="Изменить выполнение" type="submit"></button></form><?php endif; ?><?php echo escape($check['title']); ?></li><?php endforeach; ?></ul>
                    </div>
                    <?php if(can('manage_timeline') && $canManageOrder): ?><div class="timeline-item-actions"><form method="POST" class="inline-form"><?php echo csrfField(); ?><input type="hidden" name="action" value="timeline_move"><input type="hidden" name="item_id" value="<?php echo (int)$item['id']; ?>"><button class="btn btn-icon btn-outline" name="direction" value="up" title="Переместить выше" aria-label="Переместить выше">↑</button><button class="btn btn-icon btn-outline" name="direction" value="down" title="Переместить ниже" aria-label="Переместить ниже">↓</button></form><form method="POST" class="inline-form timeline-status-form"><?php echo csrfField(); ?><input type="hidden" name="action" value="timeline_status"><input type="hidden" name="item_id" value="<?php echo (int)$item['id']; ?>"><label class="sr-only" for="timeline-item-status-<?php echo (int)$item['id']; ?>">Статус этапа <?php echo escape($item['title']); ?></label><select id="timeline-item-status-<?php echo (int)$item['id']; ?>" name="status" class="form-control"><?php foreach((new OrderTimeline())->statuses() as $key=>$label): ?><option value="<?php echo escape($key); ?>" <?php echo $item['status']===$key?'selected':''; ?>><?php echo escape($label); ?></option><?php endforeach; ?></select><button class="btn btn-sm btn-outline">Сохранить</button></form></div><?php endif; ?>
                </article>
                <?php endforeach; ?>
            </div></section>
        <?php elseif ($section === 'concept'): ?>
            <section class="panel-section"><div class="section-header"><div><p class="eyebrow">Согласование</p><h2>Концепция оформления</h2></div></div>
            <?php if (can('manage_concepts') && $canManageOrder): ?>
                <form method="POST" enctype="multipart/form-data" class="event-form compact-form">
                    <?php echo csrfField(); ?><input type="hidden" name="action" value="concept_create">
                    <div class="form-row">
                        <div class="form-group"><label for="concept-title">Название</label><input id="concept-title" name="title" class="form-control" value="Концепция оформления"></div>
                        <div class="form-group"><label for="concept-style">Стилистика</label><input id="concept-style" name="style_name" class="form-control" placeholder="Стилистика"></div>
                        <div class="form-group"><label for="concept-palette">Цветовая палитра</label><input id="concept-palette" name="color_palette" class="form-control" placeholder="Цветовая палитра"></div>
                    </div>
                    <div class="form-group"><label for="concept-description">Общая концепция</label><textarea id="concept-description" name="description" class="form-control" rows="5" placeholder="Общая концепция"></textarea></div>
                    <div class="form-group"><label for="concept-composition">Композиции, схемы и детали</label><textarea id="concept-composition" name="composition_notes" class="form-control" rows="4" placeholder="Композиции, схемы и детали"></textarea></div>
                    <div class="form-group"><label for="concept-files">Файлы концепции</label><input id="concept-files" type="file" name="concept_files[]" class="form-control" accept="image/jpeg,image/png,image/webp,application/pdf" multiple></div>
                    <button class="btn btn-primary" type="submit">Создать новую версию</button>
                </form>
            <?php endif; ?>
            <?php if($concept): ?>
            <article class="concept-sheet">
                <div><span class="badge"><?php echo escape((new OrderConcept())->statuses()[$concept['status']]??$concept['status']); ?></span><p>Версия <?php echo (int)$concept['version_no']; ?></p><h3><?php echo escape($concept['title']); ?></h3><p><?php echo nl2br(escape($concept['description'])); ?></p><p><strong>Стиль:</strong> <?php echo escape($concept['style_name']); ?></p><p><strong>Палитра:</strong> <?php echo escape($concept['color_palette']); ?></p><p><?php echo nl2br(escape($concept['composition_notes'])); ?></p></div>
                <div class="concept-gallery"><?php foreach($concept['files'] as $file): ?><a href="<?php echo escape(secureFileUrl('concept', $file['id'])); ?>" target="_blank" rel="noopener"><?php if(str_starts_with($file['mime_type'],'image/')): ?><img src="<?php echo escape(secureFileUrl('concept', $file['id'])); ?>" alt="<?php echo escape($file['file_name']); ?>" loading="lazy"><?php else: ?><?php echo escape($file['file_name']); ?><?php endif; ?></a><?php endforeach; ?></div>
                <?php if(!empty($concept['comments'])): ?><div class="concept-comments"><?php foreach($concept['comments'] as $comment): ?><p><strong><?php echo escape($comment['first_name']); ?>:</strong> <?php echo escape($comment['comment']); ?></p><?php endforeach; ?></div><?php endif; ?>
                <?php if($canManageOrder && can('manage_concepts')): ?><form method="POST" class="inline-form concept-comment-form"><?php echo csrfField(); ?><input type="hidden" name="action" value="concept_comment"><input type="hidden" name="version_id" value="<?php echo (int)$concept['current_version_id']; ?>"><label class="sr-only" for="concept-comment">Комментарий к концепции</label><input id="concept-comment" name="comment" class="form-control" placeholder="Комментарий к текущей версии" required><button class="btn btn-outline" type="submit">Отправить</button></form><?php endif; ?>
                <?php if($canManageOrder && can('manage_concepts')&&in_array($concept['status'],['draft','changes_requested'],true)): ?><form method="POST"><?php echo csrfField(); ?><input type="hidden" name="action" value="concept_send"><input type="hidden" name="version_id" value="<?php echo (int)$concept['current_version_id']; ?>"><button class="btn btn-primary" type="submit">Отправить клиенту</button></form><?php endif; ?>
            </article>
            <?php if(count($concept['versions'] ?? []) > 1): ?><details class="version-history"><summary>История концепции</summary><div class="version-list"><?php foreach($concept['versions'] as $version): ?><article><strong>Версия <?php echo (int)$version['version_no']; ?></strong><span class="badge"><?php echo escape((new OrderConcept())->statuses()[$version['status']] ?? $version['status']); ?></span><p><?php echo escape($version['style_name']); ?> · <?php echo escape($version['color_palette']); ?></p></article><?php endforeach; ?></div></details><?php endif; ?>
            <?php else: ?><div class="empty-state"><p>Концепция ещё не создана.</p></div><?php endif; ?></section>
        <?php endif; ?>
    </div>
</main>
<?php include_once __DIR__ . '/../includes/organizer-footer.php'; ?>
</body>
</html>
