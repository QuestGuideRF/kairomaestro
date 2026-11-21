<?php
require_once __DIR__ . '/../config/config.php';
requireCrmPanel(['manager', 'admin']);
$leadObj = new Lead();
$orderObj = new Order();
$leadId = (int)($_GET['id'] ?? 0);
$lead = $leadObj->getById($leadId);
if (!$lead) {
    http_response_code(404);
    require_once __DIR__ . '/../404.php';
    exit;
}
$error = '';
$success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'claim') {
        $result = $leadObj->claim($leadId, (int)$_SESSION['user_id']);
        $success = $result['success'] ? 'Заявка закреплена за вами.' : '';
    } elseif ($action === 'conversation') {
        $result = $leadObj->updateConversation($leadId, $_POST, (int)$_SESSION['user_id']);
        $success = $result['success'] ? 'Итог разговора сохранён.' : '';
    } elseif ($action === 'call_checklist') {
        $result = (new LeadCallChecklist())->setCompleted(
            $leadId,
            (string)($_POST['item_key'] ?? ''),
            !empty($_POST['is_done']),
            (int)$_SESSION['user_id']
        );
        $success = $result['success'] ? 'Чек-лист обновлён.' : '';
    } elseif ($action === 'follow_up') {
        $result = (new LeadFollowUpService())->schedule($leadId, (int)$_SESSION['user_id'], $_POST);
        if ($result['success'] && ($_POST['result'] ?? '') === 'irrelevant') {
            $result = $leadObj->updateStatus(
                $leadId,
                'irrelevant',
                (int)$_SESSION['user_id'],
                $_POST['note'] ?? 'Отмечено по результату контакта'
            );
        }
        $success = $result['success'] ? 'Результат контакта сохранён.' : '';
    } elseif ($action === 'irrelevant') {
        $result = $leadObj->updateStatus($leadId, 'irrelevant', (int)$_SESSION['user_id'], $_POST['status_comment'] ?? '');
        $success = $result['success'] ? 'Заявка отмечена как неактуальная.' : '';
    } elseif ($action === 'reopen') {
        $result = $leadObj->updateStatus($leadId, 'in_work', (int)$_SESSION['user_id'], $_POST['status_comment'] ?? 'Возвращена в работу');
        $success = $result['success'] ? 'Заявка возвращена в работу.' : '';
    } elseif ($action === 'create_order') {
        $result = $orderObj->createFromLead($leadId, $_POST, (int)$_SESSION['user_id']);
        if ($result['success']) {
            redirect('/organizer/order.php?id=' . (int)$result['order_id']);
        }
    } else {
        $result = ['success' => false, 'error' => 'Неизвестное действие'];
    }
    if (!$result['success']) {
        $error = $result['error'];
    }
    $lead = $leadObj->getById($leadId);
}
$answerRows = $leadObj->getAnswers($leadId);
$brief = (new LeadBriefPresenter())->present($lead, $answerRows);
$files = $leadObj->getFiles($leadId);
$callChecklist = (new LeadCallChecklist())->forLead($lead, $files);
$followUpService = new LeadFollowUpService();
$followUps = $followUpService->history($leadId);
$followUpLabels = $followUpService->labels();
$statusHistory = $leadObj->getStatusHistory($leadId);
$templates = (new ManagerTemplate())->allForUser((int)$_SESSION['user_id']);
$workflowStatus = $lead['workflow_status'] ?? $leadObj->workflowStatus($lead);
$isAssignedToCurrent = !empty($lead['manager_id']) && (int)$lead['manager_id'] === (int)$_SESSION['user_id'];
$isAssignedToOther = currentRole() === 'manager' && !empty($lead['manager_id']) && !$isAssignedToCurrent;
$canProcess = currentRole() === 'admin' || $isAssignedToCurrent || empty($lead['manager_id']);
$telegramConfirmed = !empty($lead['client_telegram_user_id']);
$chat = !$isAssignedToOther ? (new Chat())->getLeadChat($leadId) : null;
$services = json_decode((string)($lead['services_needed'] ?? '[]'), true);
if (!is_array($services)) {
    $services = array_filter(array_map('trim', explode(',', (string)($lead['services_needed'] ?? ''))));
}
$serviceOptions = [
    'организация под ключ' => 'Организация под ключ',
    'декор' => 'Декор',
    'флористика' => 'Флористика',
    'координация' => 'Координация',
    'выездная церемония' => 'Выездная церемония',
    'монтаж' => 'Монтаж',
    'другое' => 'Другое',
];
$contactMethods = ['site_chat'=>'Чат на сайте','telegram'=>'Telegram','whatsapp'=>'WhatsApp','call'=>'Звонок','sms'=>'SMS','email'=>'Email'];
$venueValue = (string)($lead['venue'] ?? '');
$addressValue = (string)($lead['location_details'] ?? '');
$notSpecified = 'Не указан';
$sourceLabel = ($lead['source'] ?? '') === 'short_form' ? 'Короткая заявка' : 'Подробный бриф';
$phoneHref = preg_replace('/[^0-9+]/', '', (string)($lead['phone'] ?? ''));
$telegramUsername = ltrim((string)($lead['telegram_username'] ?? $lead['client_telegram_username'] ?? ''), '@');
$emailHref = filter_var((string)($lead['email'] ?? ''), FILTER_VALIDATE_EMAIL) ?: '';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <title>Заявка №<?php echo $leadId; ?> - <?php echo escape(SITE_NAME); ?></title>
    <link rel="stylesheet" href="<?php echo asset('assets/css/style.min.css'); ?>">
<?php include_once __DIR__ . '/../includes/favicon-links.php'; ?></head>
<body>
<?php include_once __DIR__ . '/../includes/manager-header.php'; ?>
<main class="event-management-page manager-lead-page">
    <div class="container">
        <header class="event-header manager-lead-hero">
            <div>
                <p class="eyebrow">Заявка №<?php echo $leadId; ?> · <?php echo escape($sourceLabel); ?></p>
                <div class="lead-heading-line"><h1><?php echo escape($lead['client_name']); ?></h1><span class="badge lead-status status-<?php echo escape($workflowStatus); ?>"><?php echo escape($leadObj->statusLabel($lead)); ?></span></div>
                <p><?php echo escape($lead['event_type']); ?> · <?php echo $lead['event_date'] ? escape(formatRussianDate($lead['event_date'], 'date_only')) : 'Дата уточняется'; ?></p>
                <?php if (!empty($lead['manager_id'])): ?><p class="lead-owner">Ответственный: <?php echo escape(trim((string)$lead['manager_first_name'] . ' ' . (string)$lead['manager_last_name'])); ?></p><?php endif; ?>
            </div>
            <div class="header-actions">
                <?php if ($chat): ?><a href="/chat.php?id=<?php echo (int)$chat['id']; ?>" class="btn btn-primary">Чат с клиентом</a><?php endif; ?>
                <a href="/manager/dashboard.php" class="btn btn-outline">К заявкам</a>
            </div>
        </header>
        <?php if ($error): ?><div class="alert alert-error"><?php echo escape($error); ?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert alert-success"><?php echo escape($success); ?></div><?php endif; ?>
        <?php if ($isAssignedToOther): ?><div class="alert alert-info">Заявка закреплена за другим менеджером и открыта только для просмотра.</div><?php endif; ?>
        <?php if (!$isAssignedToOther): ?>
        <nav class="lead-action-bar" aria-label="Быстрые действия с заявкой">
            <?php if ($phoneHref): ?><a class="btn btn-outline" href="tel:<?php echo escape($phoneHref); ?>">Позвонить</a><?php endif; ?>
            <?php if ($telegramUsername): ?><a class="btn btn-outline" href="https://t.me/<?php echo escape(rawurlencode($telegramUsername)); ?>" target="_blank" rel="noopener">Telegram</a><?php endif; ?>
            <?php if ($emailHref): ?><a class="btn btn-outline" href="mailto:<?php echo escape($emailHref); ?>">Электронная почта</a><?php endif; ?>
            <?php if ($chat): ?><a class="btn btn-primary" href="/chat.php?id=<?php echo (int)$chat['id']; ?>">Внутренний чат</a><?php endif; ?>
        </nav>
        <?php endif; ?>
        <?php if (empty($lead['manager_id']) && $workflowStatus !== 'converted'): ?>
        <section class="lead-claim-panel">
            <div><p class="eyebrow">Новая заявка</p><h2>Начать работу с клиентом</h2><p>После подтверждения заявка закрепится за вами. Другой менеджер не сможет перехватить её.</p></div>
            <form method="POST"><?php echo csrfField(); ?><input type="hidden" name="action" value="claim"><button class="btn btn-primary" type="submit">Начать работу</button></form>
        </section>
        <?php endif; ?>
        <div class="lead-summary-grid">
            <section class="info-card">
                <div class="section-header"><h2>Клиент</h2></div>
                <dl class="lead-facts">
                    <div><dt>Телефон</dt><dd><?php echo escape($lead['phone'] ?: $notSpecified); ?></dd></div>
                    <div><dt>Email</dt><dd><?php echo escape($lead['email'] ?: $notSpecified); ?></dd></div>
                    <div><dt>Связь</dt><dd><?php echo escape($contactMethods[$lead['contact_method']] ?? $lead['contact_method']); ?><?php echo $lead['telegram_username'] ? ' · @' . escape(ltrim($lead['telegram_username'], '@')) : ''; ?></dd></div>
                    <div><dt>Telegram</dt><dd><?php echo $telegramConfirmed ? 'Подтверждён' : 'Ожидает подтверждения'; ?></dd></div>
                    <div><dt>Создана</dt><dd><?php echo escape(formatRussianDateTime($lead['created_at'])); ?></dd></div>
                </dl>
            </section>
            <section class="info-card">
                <div class="section-header"><h2>Событие</h2></div>
                <dl class="lead-facts">
                    <div><dt>Формат</dt><dd><?php echo escape($lead['event_type'] ?: $notSpecified); ?></dd></div>
                    <div><dt>Дата и время</dt><dd><?php echo $lead['event_date'] ? escape(formatRussianDate($lead['event_date'], 'date_only')) : 'Уточняется'; ?><?php echo $lead['event_time'] ? ' · ' . escape(substr($lead['event_time'], 0, 5)) : ''; ?></dd></div>
                    <div><dt>Гости</dt><dd><?php echo $lead['guests_count'] ? (int)$lead['guests_count'] : 'Уточняется'; ?></dd></div>
                    <div><dt>Бюджет</dt><dd><?php echo escape($lead['budget_range'] ?: $notSpecified); ?></dd></div>
                </dl>
            </section>
        </div>
        <?php if (!$isAssignedToOther): ?>
        <section class="panel-section lead-brief-overview">
            <div class="section-header"><div><p class="eyebrow">Визуальный бриф</p><h2>Коротко о задаче</h2></div></div>
            <p class="lead-brief-overview__summary"><?php echo escape($brief['summary']); ?></p>
            <?php if ($brief['highlights']): ?>
            <dl class="lead-brief-highlights">
                <?php foreach ($brief['highlights'] as $item): ?><div><dt><?php echo escape($item['label']); ?></dt><dd><?php echo escape($item['value']); ?></dd></div><?php endforeach; ?>
            </dl>
            <?php endif; ?>
            <?php if ($brief['visual_groups'] || $brief['palette']): ?>
            <div class="lead-brief-visuals">
                <?php foreach ($brief['visual_groups'] as $group): ?>
                <section class="lead-brief-visual-group">
                    <h3><?php echo escape($group['title']); ?></h3>
                    <div class="lead-brief-visual-grid">
                        <?php foreach ($group['items'] as $item):
                            $base = 'assets/images/quiz/' . $item['asset'];
                        ?>
                        <article class="lead-brief-visual-card">
                            <picture>
                                <source type="image/avif" srcset="<?php echo escape(asset($base . '-320.avif')); ?>">
                                <img src="<?php echo escape(asset($base . '-320.webp')); ?>" alt="<?php echo escape($item['label']); ?>" width="320" height="240" loading="lazy" decoding="async">
                            </picture>
                            <strong><?php echo escape($item['label']); ?></strong>
                        </article>
                        <?php endforeach; ?>
                    </div>
                </section>
                <?php endforeach; ?>
                <?php if ($brief['palette']): ?>
                <section class="lead-brief-visual-group">
                    <h3>Палитра</h3>
                    <div class="lead-brief-palette">
                        <span class="lead-brief-palette__colors" aria-hidden="true">
                            <?php foreach ($brief['palette']['colors'] as $index => $color): ?><i class="lead-brief-palette__swatch lead-brief-palette__swatch--<?php echo escape($brief['palette']['value'] . '-' . ($index + 1)); ?>"></i><?php endforeach; ?>
                        </span>
                        <strong><?php echo escape($brief['palette']['label']); ?></strong>
                    </div>
                </section>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <p class="section-note lead-brief-empty-note">Визуальные предпочтения в этой заявке не заполнены.</p>
            <?php endif; ?>
        </section>
        <?php endif; ?>
        <?php if ($canProcess && $workflowStatus !== 'converted'): ?>
        <div class="manager-call-grid">
            <section class="panel-section manager-call-checklist">
                <div class="section-header"><div><p class="eyebrow">Собрано из брифа</p><h2>Чек-лист первого звонка</h2></div><span class="badge"><?php echo count(array_filter($callChecklist, static fn($item) => !empty($item['is_done']))); ?>/<?php echo count($callChecklist); ?></span></div>
                <div class="manager-call-checklist__items">
                    <?php foreach ($callChecklist as $item): ?>
                        <form method="POST" class="manager-check-item <?php echo $item['is_done'] ? 'is-complete' : ''; ?>">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="action" value="call_checklist">
                            <input type="hidden" name="item_key" value="<?php echo escape($item['key']); ?>">
                            <input type="hidden" name="is_done" value="<?php echo $item['is_done'] ? '0' : '1'; ?>">
                            <button type="submit" aria-label="<?php echo $item['is_done'] ? 'Вернуть пункт в работу' : 'Отметить пункт выполненным'; ?>">
                                <span aria-hidden="true"><?php echo $item['is_done'] ? '✓' : ''; ?></span>
                                <strong><?php echo escape($item['title']); ?></strong>
                            </button>
                        </form>
                    <?php endforeach; ?>
                </div>
            </section>
            <section class="panel-section manager-follow-up">
                <div class="section-header"><div><p class="eyebrow">Не держать в голове</p><h2>Следующий контакт</h2></div></div>
                <form method="POST" class="event-form">
                    <?php echo csrfField(); ?><input type="hidden" name="action" value="follow_up">
                    <div class="form-group"><label for="followUpResult">Результат</label><select id="followUpResult" name="result" class="form-control"><?php foreach ($followUpLabels as $value => $label): ?><option value="<?php echo escape($value); ?>"><?php echo escape($label); ?></option><?php endforeach; ?></select></div>
                    <div class="form-group"><label for="followUpAt">Дата и время следующего контакта</label><input id="followUpAt" type="datetime-local" name="scheduled_at" class="form-control" value="<?php echo !empty($lead['next_contact_at']) ? escape(date('Y-m-d\TH:i', strtotime($lead['next_contact_at']))) : ''; ?>"></div>
                    <div class="form-group"><label for="followUpNote">Короткое напоминание</label><textarea id="followUpNote" name="note" class="form-control" rows="3" maxlength="500"></textarea></div>
                    <button class="btn btn-primary" type="submit">Сохранить контакт</button>
                </form>
                <?php if ($followUps): ?><details class="manager-follow-up__history"><summary>Последние отметки</summary><?php foreach ($followUps as $followUp): ?><div><strong><?php echo escape($followUpLabels[$followUp['result']] ?? $followUp['result']); ?></strong><span><?php echo $followUp['scheduled_at'] ? escape(formatRussianDateTime($followUp['scheduled_at'])) : escape(formatRussianDateTime($followUp['created_at'])); ?></span><?php if ($followUp['note']): ?><p><?php echo escape($followUp['note']); ?></p><?php endif; ?></div><?php endforeach; ?></details><?php endif; ?>
            </section>
        </div>
        <section class="panel-section conversation-panel">
            <div class="section-header"><div><p class="eyebrow">После звонка</p><h2>Итог разговора с клиентом</h2></div></div>
            <?php if ($templates): ?>
            <div class="template-insert-bar">
                <label for="conversationTemplate">Шаблон менеджера</label>
                <select id="conversationTemplate" class="form-control"><option value="">Выберите шаблон</option><?php foreach ($templates as $template): ?><option value="<?php echo (int)$template['id']; ?>" data-body="<?php echo escape($template['body']); ?>"><?php echo escape($template['title']); ?></option><?php endforeach; ?></select>
                <button class="btn btn-outline" id="insertConversationTemplate" type="button">Вставить шаблон</button>
                <a href="/manager/templates.php" class="quiet-link">Управление шаблонами</a>
            </div>
            <?php else: ?><p class="section-note">Личные заготовки можно создать в разделе <a href="/manager/templates.php">«Мои шаблоны»</a>.</p><?php endif; ?>
            <form method="POST" class="event-form conversation-form">
                <?php echo csrfField(); ?><input type="hidden" name="action" value="conversation">
                <div class="form-row">
                    <div class="form-group"><label for="conversationDate">Уточнённая дата</label><input id="conversationDate" type="date" name="event_date" class="form-control" value="<?php echo escape($lead['event_date'] ?? ''); ?>"></div>
                    <div class="form-group"><label for="conversationGuests">Количество гостей</label><input id="conversationGuests" type="number" min="1" max="5000" name="guests_count" class="form-control" value="<?php echo escape($lead['guests_count'] ?? ''); ?>"></div>
                    <div class="form-group"><label for="conversationBudget">Бюджет клиента</label><input id="conversationBudget" name="budget_range" maxlength="120" class="form-control" value="<?php echo escape($lead['budget_range'] ?? ''); ?>" placeholder="Например, до 900 000 ₽"></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label for="conversationVenue">Площадка</label><input id="conversationVenue" name="venue" maxlength="190" class="form-control" value="<?php echo escape($venueValue); ?>" placeholder="Название площадки"></div>
                    <div class="form-group"><label for="conversationAddress">Адрес площадки</label><input id="conversationAddress" name="location_details" maxlength="255" class="form-control" value="<?php echo escape($addressValue); ?>" placeholder="Адрес уточняется"></div>
                </div>
                <fieldset class="service-choice-fieldset"><legend>Услуги</legend><div class="lead-service-options"><?php foreach ($serviceOptions as $value => $label): ?><label class="service-choice"><input type="checkbox" name="services_needed[]" value="<?php echo escape($value); ?>" <?php echo in_array($value, $services, true) ? 'checked' : ''; ?>><span><?php echo escape($label); ?></span></label><?php endforeach; ?></div></fieldset>
                <div class="conversation-text-grid">
                    <div class="form-group"><label for="clientWishes">Пожелания</label><textarea id="clientWishes" name="client_wishes" class="form-control" rows="4"><?php echo escape($lead['client_wishes'] ?? ''); ?></textarea></div>
                    <div class="form-group"><label for="clientLikes">Что нравится</label><textarea id="clientLikes" name="client_likes" class="form-control" rows="4"><?php echo escape($lead['client_likes'] ?? ''); ?></textarea></div>
                    <div class="form-group"><label for="clientDislikes">Что не нравится</label><textarea id="clientDislikes" name="client_dislikes" class="form-control" rows="4"><?php echo escape($lead['client_dislikes'] ?? ''); ?></textarea></div>
                    <div class="form-group"><label for="clientAdditional">Дополнительные комментарии клиента</label><textarea id="clientAdditional" name="client_additional_comments" class="form-control" rows="4"><?php echo escape($lead['client_additional_comments'] ?? $lead['comment'] ?? ''); ?></textarea></div>
                    <div class="form-group"><label for="clientQuestions">Вопросы клиента</label><textarea id="clientQuestions" name="client_questions" class="form-control" rows="5"><?php echo escape($lead['client_questions'] ?? ''); ?></textarea></div>
                    <div class="form-group"><label for="managerAnswers">Ответы менеджера</label><textarea id="managerAnswers" name="manager_answers" class="form-control" rows="5"><?php echo escape($lead['manager_answers'] ?? ''); ?></textarea></div>
                </div>
                <div class="form-group"><label for="conversationSummary">Итог разговора</label><textarea id="conversationSummary" name="conversation_summary" class="form-control" rows="8" placeholder="Кратко зафиксируйте договорённости и то, что должен знать организатор."><?php echo escape($lead['conversation_summary'] ?? ''); ?></textarea></div>
                <div class="form-actions"><button class="btn btn-primary" type="submit">Сохранить итог разговора</button></div>
            </form>
        </section>
        <?php endif; ?>
        <?php if (!$isAssignedToOther): ?>
        <section class="panel-section lead-brief-details">
            <div class="section-header"><div><p class="eyebrow">Полный бриф</p><h2>Ответы клиента</h2></div></div>
            <div class="lead-brief-sections">
                <?php foreach ($brief['sections'] as $section): ?>
                <section class="lead-brief-section">
                    <h3><?php echo escape($section['title']); ?></h3>
                    <dl>
                        <?php foreach ($section['items'] as $answer): ?><div><dt><?php echo escape($answer['label']); ?></dt><dd><?php echo nl2br(escape($answer['value'])); ?></dd></div><?php endforeach; ?>
                    </dl>
                </section>
                <?php endforeach; ?>
                <?php if (!$brief['sections']): ?><div class="empty-state"><p>Ответы квиза не найдены.</p></div><?php endif; ?>
            </div>
        </section>
        <section class="panel-section">
            <div class="section-header"><h2>Референсы</h2><span class="badge"><?php echo count($files); ?></span></div>
            <div class="lead-reference-grid" id="referenceGallery">
                <?php foreach ($files as $index => $file): $isImage = str_starts_with((string)$file['mime_type'], 'image/'); $originalUrl = secureFileUrl('lead', $file['id']); ?>
                    <?php if ($isImage): ?><button class="lead-reference-card" type="button" data-full="<?php echo escape($originalUrl); ?>" data-name="<?php echo escape($file['file_name'] ?: 'Референс'); ?>" data-index="<?php echo (int)$index; ?>"><img src="<?php echo escape($originalUrl . '&variant=thumb'); ?>" alt="<?php echo escape($file['file_name'] ?: 'Референс клиента'); ?>" loading="lazy" width="360" height="240"><span><?php echo escape($file['file_name'] ?: 'Референс'); ?></span></button><?php else: ?><a class="lead-reference-card lead-reference-file" href="<?php echo escape($originalUrl); ?>" target="_blank" rel="noopener"><span>Документ</span><strong><?php echo escape($file['file_name'] ?: 'Открыть файл'); ?></strong></a><?php endif; ?>
                <?php endforeach; ?>
                <?php if (!$files): ?><div class="empty-state"><p>Файлы не прикреплены.</p></div><?php endif; ?>
            </div>
        </section>
        <?php endif; ?>
        <?php if (in_array($workflowStatus, ['new', 'in_work'], true) && $canProcess): ?>
        <section class="panel-section create-order-panel">
            <div class="section-header"><div><p class="eyebrow">Передача организатору</p><h2>Создать заказ</h2></div></div>
            <p class="section-note">Проверьте основные сведения. Заказ создаётся один раз и становится доступен организаторам.</p>
            <?php if (!$telegramConfirmed): ?>
                <div class="alert alert-info">
                    Клиент ещё завершает регистрацию в Telegram. После подтверждения
                    здесь станет доступно создание заказа и персональное приглашение
                    в группу события.
                </div>
            <?php else: ?>
            <form method="POST" class="event-form" id="createOrderForm">
                <?php echo csrfField(); ?><input type="hidden" name="action" value="create_order">
                <div class="form-row">
                    <div class="form-group"><label for="orderTitle">Название заказа</label><input id="orderTitle" name="title" class="form-control" maxlength="255" value="<?php echo escape($lead['event_type'] . ' для ' . $lead['client_name']); ?>" required></div>
                    <div class="form-group"><label for="orderDate">Дата события</label><input id="orderDate" name="event_date" type="date" class="form-control" value="<?php echo escape($lead['event_date'] ?? ''); ?>"></div>
                    <div class="form-group"><label for="orderTime">Время начала</label><input id="orderTime" name="start_time" type="time" class="form-control" value="<?php echo escape(isset($lead['event_time']) ? substr((string)$lead['event_time'], 0, 5) : ''); ?>"></div>
                </div>
                <div class="form-row"><div class="form-group"><label for="orderVenue">Площадка</label><input id="orderVenue" name="venue" class="form-control" maxlength="190" value="<?php echo escape($venueValue); ?>"></div><div class="form-group"><label for="orderAddress">Адрес</label><input id="orderAddress" name="address" class="form-control" maxlength="255" value="<?php echo escape($addressValue); ?>"></div></div>
                <input type="hidden" name="internal_notes" value="">
                <button class="btn btn-primary btn-lg" type="submit">Создать заказ</button>
            </form>
            <?php endif; ?>
            <form method="POST" class="mark-irrelevant-form" onsubmit="return confirm('Отметить заявку как неактуальную?');"><?php echo csrfField(); ?><input type="hidden" name="action" value="irrelevant"><button class="btn btn-outline" type="submit">Неактуальна</button></form>
        </section>
        <?php elseif ($workflowStatus === 'irrelevant' && $canProcess): ?>
        <section class="panel-section"><form method="POST"><?php echo csrfField(); ?><input type="hidden" name="action" value="reopen"><button class="btn btn-outline" type="submit">Вернуть в работу</button></form></section>
        <?php elseif ($workflowStatus === 'converted'): ?>
        <section class="lead-converted-panel"><div><p class="eyebrow">Передано организаторам</p><h2>Заказ уже создан</h2></div><a class="btn btn-primary" href="/organizer/order.php?id=<?php echo (int)$lead['order_id']; ?>">Открыть заказ</a></section>
        <?php endif; ?>
        <section class="panel-section">
            <div class="section-header"><h2>История заявки</h2></div>
            <div class="timeline-list"><?php foreach ($statusHistory as $item): ?><article class="timeline-row"><time><?php echo escape(formatRussianDateTime($item['created_at'])); ?></time><strong><?php echo escape(($item['old_status'] ? $leadObj->statusLabel($item['old_status']) : 'Заявка создана') . ' → ' . $leadObj->statusLabel($item['new_status'])); ?></strong><p><?php echo escape($item['comment'] ?? ''); ?></p></article><?php endforeach; ?><?php if (!$statusHistory): ?><div class="empty-state"><p>История пока пуста.</p></div><?php endif; ?></div>
        </section>
    </div>
</main>
<dialog class="reference-lightbox" id="referenceLightbox" aria-label="Просмотр референса">
    <button class="reference-lightbox__close" type="button" aria-label="Закрыть">×</button>
    <button class="reference-lightbox__nav reference-lightbox__prev" type="button" aria-label="Предыдущее изображение">‹</button>
    <figure><img id="referenceLightboxImage" src="" alt=""><figcaption id="referenceLightboxCaption"></figcaption></figure>
    <button class="reference-lightbox__nav reference-lightbox__next" type="button" aria-label="Следующее изображение">›</button>
</dialog>
<?php include_once __DIR__ . '/../includes/organizer-footer.php'; ?>
<script>
(function () {
    const templateSelect = document.getElementById('conversationTemplate');
    const insertTemplate = document.getElementById('insertConversationTemplate');
    const summary = document.getElementById('conversationSummary');
    if (templateSelect && insertTemplate && summary) {
        insertTemplate.addEventListener('click', () => {
            const option = templateSelect.options[templateSelect.selectedIndex];
            if (!option || !option.dataset.body) return;
            summary.value = summary.value.trim() ? `${summary.value.trim()}\n\n${option.dataset.body}` : option.dataset.body;
            summary.focus();
        });
    }
    const createForm = document.getElementById('createOrderForm');
    if (createForm) {
        createForm.addEventListener('submit', (event) => {
            if (!confirm('Создать заказ и передать его организаторам?')) {
                event.preventDefault();
                return;
            }
            const button = createForm.querySelector('button[type="submit"]');
            button.disabled = true;
            button.textContent = 'Создаём заказ...';
        });
    }
    const cards = Array.from(document.querySelectorAll('.lead-reference-card[data-full]'));
    const lightbox = document.getElementById('referenceLightbox');
    if (!lightbox || cards.length === 0) return;
    const image = document.getElementById('referenceLightboxImage');
    const caption = document.getElementById('referenceLightboxCaption');
    let currentIndex = 0;
    const show = (index) => {
        currentIndex = (index + cards.length) % cards.length;
        image.src = cards[currentIndex].dataset.full;
        image.alt = cards[currentIndex].dataset.name;
        caption.textContent = cards[currentIndex].dataset.name;
        lightbox.showModal();
        document.body.classList.add('modal-open');
        lightbox.querySelector('.reference-lightbox__close').focus();
    };
    const close = () => {
        lightbox.close();
        image.src = '';
        document.body.classList.remove('modal-open');
    };
    cards.forEach((card, index) => card.addEventListener('click', () => show(index)));
    lightbox.querySelector('.reference-lightbox__close').addEventListener('click', close);
    lightbox.querySelector('.reference-lightbox__prev').addEventListener('click', () => show(currentIndex - 1));
    lightbox.querySelector('.reference-lightbox__next').addEventListener('click', () => show(currentIndex + 1));
    lightbox.addEventListener('click', (event) => { if (event.target === lightbox) close(); });
    document.addEventListener('keydown', (event) => {
        if (!lightbox.open) return;
        if (event.key === 'Escape') close();
        if (event.key === 'ArrowLeft') show(currentIndex - 1);
        if (event.key === 'ArrowRight') show(currentIndex + 1);
    });
})();
</script>
</body>
</html>
