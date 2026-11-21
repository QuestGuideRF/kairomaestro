<?php
require_once __DIR__ . '/../config/config.php';
requireClientPanel();
$orderObj = new Order();
$unknownValue = 'уточняется';
$orderId = (int)($_GET['id'] ?? 0);
$order = $orderObj->getById($orderId);
if (!$order || !$orderObj->canAccess($order, $_SESSION['user_id'], currentRole())) {
    redirect('/account/');
}
$error = '';
$success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'document_decision') {
        $result = (new OrderDocument())->confirm((int)($_POST['document_id'] ?? 0), (int)$_SESSION['user_id'], $_POST['decision'] ?? '', $_POST['comment'] ?? null);
    } elseif ($action === 'document_comment') {
        $result = (new OrderDocument())->addComment((int)($_POST['document_id'] ?? 0), (int)$_SESSION['user_id'], $_POST['comment'] ?? '');
    } elseif ($action === 'concept_decision') {
        $result = (new OrderConcept())->decide((int)($_POST['version_id'] ?? 0), (int)$_SESSION['user_id'], $_POST['decision'] ?? '', $_POST['comment'] ?? null);
    } elseif ($action === 'concept_comment') {
        $result = (new OrderConcept())->comment((int)($_POST['version_id'] ?? 0), (int)$_SESSION['user_id'], $_POST['comment'] ?? '');
    } elseif ($action === 'review_submit') {
        $result = (new OrderReview())->submit((int)($_POST['review_id'] ?? 0), (int)$_SESSION['user_id'], $_POST, $_FILES['review_files'] ?? []);
    } else {
        $result = ['success' => false, 'error' => 'Недопустимое действие'];
    }
    $success = $result['success'] ? 'Спасибо, решение сохранено' : '';
    $error = $result['success'] ? '' : ($result['error'] ?? 'Не удалось сохранить');
}
$statuses = $orderObj->statuses();
$materials = $orderObj->getMaterials($orderId, true);
$tasks = $orderObj->getTasks($orderId, true);
$team = $orderObj->getTeam($orderId, true);
$history = $orderObj->getStatusHistory($orderId);
$chat = (new Chat())->getOrderClientChat($orderId);
$documents = dbTableExists('order_documents') ? (new OrderDocument())->getForOrder($orderId, true, (int)$_SESSION['user_id']) : [];
$concept = dbTableExists('order_concepts') ? (new OrderConcept())->getForOrder($orderId, true, (int)$_SESSION['user_id']) : null;
$timeline = dbTableExists('order_timeline_items') ? (new OrderTimeline())->getForOrder($orderId) : [];
$review = dbColumnExists('reviews', 'order_id') ? (new OrderReview())->getForOrder($orderId) : null;
$preparationTimeline = (new ClientOrderTimeline())->build($order, (int)$_SESSION['user_id']);
$clientFinanceService = new ClientOrderFinance();
$clientFinance = $clientFinanceService->present($order, (int)$_SESSION['user_id']);
$paymentStatusLabels = $clientFinanceService->statusLabels();
$organizerProfile = !empty($order['organizer_id'])
    ? (new PublicTeamProfile())->getForUser((int)$order['organizer_id'])
    : null;
if ($organizerProfile && empty($organizerProfile['is_published'])) {
    $organizerProfile = null;
}
$venueDisplay = $order['venue'] ?: $order['address'];
$venueDisplay = $venueDisplay ?: $unknownValue;
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo escape($order['title']); ?> - личный кабинет</title>
    <meta name="robots" content="noindex">
    <link rel="stylesheet" href="<?php echo asset('assets/css/style.min.css'); ?>">
<?php include_once __DIR__ . '/../includes/favicon-links.php'; ?></head>
<body>
<?php include_once __DIR__ . '/../includes/client-header.php'; ?>
<main class="event-page">
    <div class="container">
        <div class="event-header-card">
            <div class="event-header-content">
                <p class="eyebrow">Заказ</p>
                <h1><?php echo escape($order['title']); ?></h1>
                <span class="status-badge badge-<?php echo escape($order['status']); ?>"><?php echo escape($statuses[$order['status']] ?? $order['status']); ?></span>
            </div>
            <?php if ($chat): ?>
                <a href="/chat.php?id=<?php echo (int)$chat['id']; ?>" class="btn btn-primary">Открыть чат</a>
            <?php endif; ?>
        </div>
        <?php if ($error): ?><div class="alert alert-error"><?php echo escape($error); ?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert alert-success"><?php echo escape($success); ?></div><?php endif; ?>
        <div class="event-content-grid event-content-grid--summary">
            <section class="info-card">
                <h2>Информация</h2>
                <div class="info-list">
                    <div class="info-row"><div><div class="info-label">Дата</div><div class="info-value"><?php echo $order['event_date'] ? formatRussianDate($order['event_date'], 'full') : $unknownValue; ?></div></div></div>
                    <div class="info-row"><div><div class="info-label">Площадка</div><div class="info-value"><?php echo escape($venueDisplay); ?></div></div></div>
                    <div class="info-row"><div><div class="info-label">Организатор</div><div class="info-value"><?php echo escape(trim(($order['organizer_first_name'] ?? '') . ' ' . ($order['organizer_last_name'] ?? '')) ?: 'будет назначен'); ?></div></div></div>
                    <div class="info-row"><div><div class="info-label">Гостей</div><div class="info-value"><?php echo $order['guests_count'] ? (int)$order['guests_count'] : $unknownValue; ?></div></div></div>
                    <div class="info-row"><div><div class="info-label">Стиль</div><div class="info-value"><?php echo escape($order['style'] ?: $unknownValue); ?></div></div></div>
                </div>
            </section>
            <section class="info-card client-order-next">
                <p class="eyebrow">Сейчас</p>
                <?php foreach ($preparationTimeline as $step): ?>
                    <?php if ($step['state'] === 'current'): ?>
                        <h2><?php echo escape($step['title']); ?></h2>
                        <p><?php echo escape($step['next_step'] ?: $step['description']); ?></p>
                    <?php endif; ?>
                <?php endforeach; ?>
                <?php if ($order['status'] === 'completed'): ?><h2>Проект завершён</h2><p>Итоговые материалы и отзыв доступны ниже.</p><?php endif; ?>
            </section>
        </div>
        <section class="panel-section client-preparation" aria-labelledby="preparation-title">
            <div class="section-header"><div><p class="eyebrow">Подготовка без сюрпризов</p><h2 id="preparation-title">Этапы вашего проекта</h2></div></div>
            <ol class="client-preparation__list">
                <?php foreach ($preparationTimeline as $step): ?>
                    <li class="client-preparation__item is-<?php echo escape($step['state']); ?>">
                        <span class="client-preparation__marker" aria-hidden="true"><?php echo $step['state'] === 'completed' ? '✓' : ''; ?></span>
                        <div>
                            <h3><?php echo escape($step['title']); ?></h3>
                            <p><?php echo escape($step['description']); ?></p>
                            <?php if ($step['completed_at']): ?><small>Готово: <?php echo escape(formatRussianDate($step['completed_at'], 'date_only')); ?></small><?php endif; ?>
                            <?php if ($step['next_step']): ?><strong class="client-preparation__next"><?php echo escape($step['next_step']); ?></strong><?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ol>
        </section>
        <section class="panel-section client-finance" id="finance">
            <div class="section-header"><div><p class="eyebrow">Только данные по вашему договору</p><h2>Оплата проекта</h2></div></div>
            <?php if ($clientFinance['total'] > 0 || $clientFinance['payments'] || $clientFinance['documents']): ?>
                <div class="client-finance__summary">
                    <div><span>Стоимость по проекту</span><strong><?php echo number_format($clientFinance['total'], 0, ',', ' '); ?> ₽</strong></div>
                    <div><span>Оплачено</span><strong><?php echo number_format($clientFinance['paid'], 0, ',', ' '); ?> ₽</strong></div>
                    <div><span>Осталось</span><strong><?php echo number_format($clientFinance['remaining'], 0, ',', ' '); ?> ₽</strong></div>
                </div>
                <?php if ($clientFinance['next_payment']): ?>
                    <div class="client-finance__next">
                        <div><span>Следующий платёж</span><h3><?php echo escape($clientFinance['next_payment']['title']); ?></h3></div>
                        <strong><?php echo number_format((float)$clientFinance['next_payment']['amount'], 0, ',', ' '); ?> ₽</strong>
                        <span><?php echo $clientFinance['next_payment']['due_date'] ? 'до ' . escape(formatRussianDate($clientFinance['next_payment']['due_date'], 'date_only')) : 'срок уточняется'; ?></span>
                    </div>
                <?php endif; ?>
                <?php if ($clientFinance['payments']): ?>
                    <div class="table-wrap"><table class="data-table"><thead><tr><th>Этап</th><th>Срок</th><th>Сумма</th><th>Статус</th><th></th></tr></thead><tbody>
                        <?php foreach ($clientFinance['payments'] as $payment): ?><tr>
                            <td><?php echo escape($payment['title']); ?></td>
                            <td><?php echo $payment['due_date'] ? escape(formatRussianDate($payment['due_date'], 'date_only')) : '—'; ?></td>
                            <td><?php echo number_format((float)$payment['amount'], 0, ',', ' '); ?> ₽</td>
                            <td><span class="badge badge-<?php echo escape($payment['status']); ?>"><?php echo escape($paymentStatusLabels[$payment['status']] ?? $payment['status']); ?></span></td>
                            <td><?php if ($payment['proof_path']): ?><a href="<?php echo escape(secureFileUrl('finance_payment', $payment['id'])); ?>" target="_blank" rel="noopener">Подтверждение</a><?php endif; ?></td>
                        </tr><?php endforeach; ?>
                    </tbody></table></div>
                <?php endif; ?>
                <?php if ($clientFinance['documents']): ?>
                    <div class="client-finance__documents">
                        <?php foreach ($clientFinance['documents'] as $document): ?><a class="btn btn-outline btn-sm" href="/document.php?id=<?php echo (int)$document['id']; ?>" target="_blank" rel="noopener"><?php echo escape($document['title']); ?></a><?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="empty-state"><p>График и документы появятся после согласования стоимости.</p></div>
            <?php endif; ?>
        </section>
        <section class="panel-section">
            <div class="section-header"><h2>Материалы заказа</h2></div>
            <div class="materials-grid">
                <?php foreach ($materials as $material): ?>
                    <article class="material-card">
                        <span class="badge"><?php echo escape($material['material_type']); ?></span>
                        <h3><?php echo escape($material['title']); ?></h3>
                        <?php if ($material['description']): ?><p><?php echo escape($material['description']); ?></p><?php endif; ?>
                        <?php if ($material['body']): ?><p><?php echo nl2br(escape($material['body'])); ?></p><?php endif; ?>
                        <?php if ($material['file_path']): ?><a href="<?php echo escape(secureFileUrl('material', $material['id'])); ?>" target="_blank" rel="noopener" class="btn btn-outline btn-sm">Открыть файл</a><?php endif; ?>
                    </article>
                <?php endforeach; ?>
                <?php if (!$materials): ?><div class="empty-state"><p>Материалы появятся после подготовки концепции, сметы или договора.</p></div><?php endif; ?>
            </div>
        </section>
        <?php if ($documents): ?>
        <section class="panel-section" id="documents">
            <div class="section-header"><h2>Документы и согласования</h2></div>
            <div class="document-list">
                <?php foreach ($documents as $document): ?>
                <article class="document-row">
                    <div><span class="badge"><?php echo escape((new OrderDocument())->statuses()[$document['status']] ?? $document['status']); ?></span><h3><?php echo escape($document['title']); ?></h3><p><?php echo escape($document['document_number']); ?> · версия <?php echo (int)$document['version_no']; ?></p></div>
                    <a class="btn btn-outline btn-sm" href="/document.php?id=<?php echo (int)$document['id']; ?>" target="_blank">Открыть документ</a>
                    <?php if (in_array($document['status'], ['sent','viewed','changes_requested'], true)): ?>
                    <form method="POST" class="document-decision-form">
                        <?php echo csrfField(); ?><input type="hidden" name="action" value="document_decision"><input type="hidden" name="document_id" value="<?php echo (int)$document['id']; ?>">
                        <label class="sr-only" for="document-decision-comment-<?php echo (int)$document['id']; ?>">Комментарий к решению</label>
                        <textarea id="document-decision-comment-<?php echo (int)$document['id']; ?>" name="comment" class="form-control" rows="2" placeholder="Комментарий к решению"></textarea>
                        <div class="row-actions"><button name="decision" value="approved" class="btn btn-primary" type="submit">Согласовать версию</button><button name="decision" value="changes_requested" class="btn btn-outline" type="submit">Запросить изменения</button><?php if ($document['document_type'] === 'contract'): ?><button name="decision" value="signed" class="btn btn-outline" type="submit">Подтвердить подписание</button><?php endif; ?></div>
                    </form>
                    <?php endif; ?>
                    <form method="POST" class="inline-form"><?php echo csrfField(); ?><input type="hidden" name="action" value="document_comment"><input type="hidden" name="document_id" value="<?php echo (int)$document['id']; ?>"><label class="sr-only" for="document-comment-<?php echo (int)$document['id']; ?>">Комментарий к документу</label><input id="document-comment-<?php echo (int)$document['id']; ?>" name="comment" class="form-control" placeholder="Комментарий" required><button class="btn btn-sm btn-outline">Отправить</button></form>
                    <?php if (!empty($document['comments'])): ?><div class="document-comments"><?php foreach ($document['comments'] as $comment): ?><p><strong><?php echo escape($comment['first_name']); ?>:</strong> <?php echo escape($comment['comment']); ?> <small>версия <?php echo (int)$comment['version_no']; ?></small></p><?php endforeach; ?></div><?php endif; ?>
                    <?php if (count($document['versions'] ?? []) > 1): ?><details class="version-history"><summary>История версий</summary><ul><?php foreach ($document['versions'] as $version): ?><li>Версия <?php echo (int)$version['version_no']; ?> · <?php echo escape(formatRussianDateTime($version['created_at'])); ?></li><?php endforeach; ?></ul></details><?php endif; ?>
                </article>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>
        <?php if ($concept): ?>
        <section class="panel-section" id="concept">
            <div class="section-header"><div><p class="eyebrow">Версия <?php echo (int)$concept['version_no']; ?></p><h2>Концепция оформления</h2></div><span class="badge"><?php echo escape((new OrderConcept())->statuses()[$concept['status']] ?? $concept['status']); ?></span></div>
            <article class="concept-sheet"><div><h3><?php echo escape($concept['title']); ?></h3><p><?php echo nl2br(escape($concept['description'])); ?></p><p><strong>Стилистика:</strong> <?php echo escape($concept['style_name']); ?></p><p><strong>Палитра:</strong> <?php echo escape($concept['color_palette']); ?></p><p><?php echo nl2br(escape($concept['composition_notes'])); ?></p></div><div class="concept-gallery"><?php foreach ($concept['files'] as $file): ?><a href="<?php echo escape(secureFileUrl('concept', $file['id'])); ?>" target="_blank" rel="noopener"><?php if (str_starts_with($file['mime_type'], 'image/')): ?><img src="<?php echo escape(secureFileUrl('concept', $file['id'])); ?>" alt="<?php echo escape($file['file_name']); ?>" loading="lazy"><?php else: ?><?php echo escape($file['file_name']); ?><?php endif; ?></a><?php endforeach; ?></div></article>
            <div class="concept-comments"><?php foreach ($concept['comments'] as $comment): ?><p><strong><?php echo escape($comment['first_name']); ?>:</strong> <?php echo escape($comment['comment']); ?></p><?php endforeach; ?></div>
            <?php if (count($concept['versions'] ?? []) > 1): ?><details class="version-history"><summary>Предыдущие версии</summary><div class="version-list"><?php foreach ($concept['versions'] as $version): ?><article><strong>Версия <?php echo (int)$version['version_no']; ?></strong><span class="badge"><?php echo escape((new OrderConcept())->statuses()[$version['status']] ?? $version['status']); ?></span><p><?php echo escape($version['style_name']); ?> · <?php echo escape($version['color_palette']); ?></p></article><?php endforeach; ?></div></details><?php endif; ?>
            <?php if (in_array($concept['status'], ['sent','viewed','changes_requested'], true)): ?><form method="POST" class="event-form compact-form"><?php echo csrfField(); ?><input type="hidden" name="action" value="concept_decision"><input type="hidden" name="version_id" value="<?php echo (int)$concept['current_version_id']; ?>"><label class="sr-only" for="concept-decision-comment">Комментарий к решению</label><textarea id="concept-decision-comment" name="comment" class="form-control" rows="3" placeholder="Комментарий"></textarea><div class="row-actions"><button name="decision" value="approved" class="btn btn-primary">Утвердить эту версию</button><button name="decision" value="changes_requested" class="btn btn-outline">Запросить изменения</button></div></form><?php endif; ?>
            <form method="POST" class="inline-form"><?php echo csrfField(); ?><input type="hidden" name="action" value="concept_comment"><input type="hidden" name="version_id" value="<?php echo (int)$concept['current_version_id']; ?>"><label class="sr-only" for="concept-comment">Комментарий к концепции</label><input id="concept-comment" name="comment" class="form-control" placeholder="Комментарий к концепции" required><button class="btn btn-outline">Отправить</button></form>
        </section>
        <?php endif; ?>
        <?php if ($timeline): ?>
        <section class="panel-section"><div class="section-header"><h2>Таймлайн мероприятия</h2></div><div class="event-timeline-list"><?php foreach($timeline as $item): ?><article class="event-timeline-item"><time><?php echo escape(date('H:i',strtotime($item['starts_at']))); ?></time><div><span class="badge"><?php echo escape((new OrderTimeline())->statuses()[$item['status']]??$item['status']); ?></span><h3><?php echo escape($item['title']); ?></h3><p><?php echo escape($item['description']); ?></p></div></article><?php endforeach; ?></div></section>
        <?php endif; ?>
        <?php if ($review && in_array($review['status'], ['pending','requested','opened'], true)): ?>
        <section class="panel-section" id="review">
            <div class="section-header"><div><p class="eyebrow">Обратная связь</p><h2>Расскажите, как прошло событие</h2></div></div>
            <form method="POST" enctype="multipart/form-data" class="event-form">
                <input type="hidden" name="action" value="review_submit">
                <input type="hidden" name="review_id" value="<?php echo (int)$review['id']; ?>">
                <?php echo csrfField(); ?>
                <div class="rating-grid">
                    <?php foreach(['rating'=>'Общая оценка','service_rating'=>'Сервис','decor_rating'=>'Оформление','team_rating'=>'Команда'] as $key=>$label): ?>
                        <div class="form-group">
                            <label for="order-review-<?php echo escape($key); ?>"><?php echo escape($label); ?></label>
                            <select id="order-review-<?php echo escape($key); ?>" name="<?php echo escape($key); ?>" class="form-control" required>
                                <option value="">Выберите</option>
                                <?php for($rating=5;$rating>=1;$rating--): ?><option value="<?php echo $rating; ?>"><?php echo $rating; ?></option><?php endfor; ?>
                            </select>
                        </div>
                    <?php endforeach; ?>
                </div>
                <label for="order-review-text">Ваш отзыв</label>
                <textarea id="order-review-text" name="text" class="form-control" rows="5" placeholder="Ваш отзыв" required></textarea>
                <label for="order-review-files">Фотографии</label>
                <input id="order-review-files" type="file" name="review_files[]" class="form-control" accept="image/jpeg,image/png,image/webp" multiple>
                <label class="checkbox-row" for="order-review-publication"><input id="order-review-publication" type="checkbox" name="publication_consent" value="1"> Разрешаю публикацию отзыва</label>
                <label class="checkbox-row" for="order-review-photos"><input id="order-review-photos" type="checkbox" name="photo_consent" value="1"> Разрешаю использовать приложенные фотографии</label>
                <label for="order-review-name-mode">Как показывать имя</label>
                <select id="order-review-name-mode" name="display_name_mode" class="form-control"><option value="first_name">Показывать только имя</option><option value="full_name">Показывать имя и фамилию</option><option value="hidden">Скрыть имя</option></select>
                <button class="btn btn-primary" type="submit">Отправить отзыв</button>
            </form>
        </section>
        <?php elseif ($review && in_array($review['status'], ['submitted','approved','published'], true)): ?><section class="panel-section"><div class="empty-state"><h2>Спасибо за отзыв</h2><p>Он сохранён и передан команде.</p></div></section><?php endif; ?>
        <section class="panel-section">
            <div class="section-header"><h2>Задачи и план</h2></div>
            <div class="task-list">
                <?php foreach ($tasks as $task): ?>
                    <article class="task-item">
                        <span class="badge badge-<?php echo escape($task['status']); ?>"><?php echo escape($task['status']); ?></span>
                        <h3><?php echo escape($task['title']); ?></h3>
                        <p><?php echo escape($task['description']); ?></p>
                    </article>
                <?php endforeach; ?>
                <?php if (!$tasks): ?><div class="empty-state"><p>План появится после брифа.</p></div><?php endif; ?>
            </div>
        </section>
        <?php if (!empty($order['organizer_id']) || $team): ?>
            <section class="panel-section">
                <div class="section-header"><div><p class="eyebrow">Люди, которые ведут проект</p><h2>Ваша команда</h2></div></div>
                <?php if (!empty($order['organizer_id'])): ?>
                    <?php $organizerName = trim(($order['organizer_first_name'] ?? '') . ' ' . ($order['organizer_last_name'] ?? '')); ?>
                    <article class="client-organizer-card">
                        <div class="worker-avatar">
                            <?php if (!empty($order['organizer_avatar'])): ?><img src="/uploads/avatars/<?php echo escape(basename((string)$order['organizer_avatar'])); ?>" alt="<?php echo escape($organizerName); ?>" width="80" height="80"><?php else: ?><div class="worker-avatar-placeholder"><?php echo escape(utf8Substring($organizerName ?: 'О', 0, 1)); ?></div><?php endif; ?>
                        </div>
                        <div><span class="worker-specialty"><?php echo escape($organizerProfile['public_role'] ?? 'Организатор проекта'); ?></span><h3><?php echo escape($organizerName ?: 'Организатор проекта'); ?></h3><p><?php echo escape($organizerProfile['public_description'] ?? 'Координирует подготовку, команду и все согласованные этапы проекта.'); ?></p></div>
                        <?php if ($chat): ?><a class="btn btn-outline" href="/chat.php?id=<?php echo (int)$chat['id']; ?>">Написать</a><?php endif; ?>
                    </article>
                <?php endif; ?>
                <?php if ($team): ?>
                <div class="workers-grid">
                    <?php foreach ($team as $member): ?>
                        <?php
                        $memberRole = $member['public_role'] ?: $member['profile_public_role'];
                        $memberRole = $memberRole ?: $member['specialty'];
                        ?>
                        <article class="worker-card public-worker">
                            <div class="worker-card-header">
                                <div class="worker-avatar">
                                    <?php if ($member['avatar']): ?><img src="/uploads/avatars/<?php echo escape(basename((string)$member['avatar'])); ?>" alt="<?php echo escape($member['first_name']); ?>"><?php else: ?><div class="worker-avatar-placeholder"><?php echo escape(utf8Substring($member['first_name'], 0, 1)); ?></div><?php endif; ?>
                                </div>
                                <div class="worker-info">
                                    <h3><?php echo escape($member['first_name'] . ' ' . $member['last_name']); ?></h3>
                                    <span class="worker-specialty"><?php echo escape($memberRole); ?></span>
                                </div>
                            </div>
                            <p><?php echo escape($member['public_description'] ?: 'Участник команды проекта.'); ?></p>
                            <?php if ((float)$member['experience_years'] > 0): ?><p class="muted">Опыт: <?php echo (float)$member['experience_years']; ?> лет</p><?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </div>
</main>
<?php include_once __DIR__ . '/../includes/client-footer.php'; ?>
</body>
</html>
