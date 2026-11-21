<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();

$viewerRole = currentRole();
$viewerId = (int)($_SESSION['user_id'] ?? 0);
if (!in_array($viewerRole, ['client', 'admin'], true)) {
    redirect(dashboardPathForRole($viewerRole));
}

$leadId = (int)($_GET['id'] ?? 0);
$leadService = new Lead();
$lead = $leadService->getById($leadId);
$isOwner = $lead && (int)$lead['client_id'] === $viewerId;
if (!$lead || (!$isOwner && $viewerRole !== 'admin')) {
    redirect(dashboardPathForRole($viewerRole));
}

$answers = $leadService->getAnswers($leadId);
$brief = (new LeadBriefPresenter())->present($lead, $answers);
$chat = (new Chat())->getLeadChat($leadId);
$workflowStatus = (string)$lead['workflow_status'];
$statusLabels = $leadService->statuses();
$managerName = trim((string)($lead['manager_first_name'] ?? '') . ' ' . (string)($lead['manager_last_name'] ?? ''));
$clientName = trim((string)($lead['first_name'] ?? ''));
$sourceLabel = ($lead['source'] ?? '') === 'short_form' ? 'Короткая заявка' : 'Подробный бриф';
$nextStep = match ($workflowStatus) {
    'converted' => 'Заказ создан. Дальнейшие этапы, документы и команда уже доступны в проекте.',
    'irrelevant' => 'Заявка закрыта. Если планы изменились, создайте новую заявку или напишите команде.',
    'in_work' => 'Менеджер уточнит детали и зафиксирует следующий шаг в переписке.',
    default => 'Менеджер изучает заявку и свяжется выбранным способом.',
};
$progressSteps = [
    'new' => ['title' => 'Заявка получена', 'done' => true],
    'in_work' => ['title' => 'Обсуждение', 'done' => in_array($workflowStatus, ['in_work', 'converted'], true)],
    'converted' => ['title' => 'Проект создан', 'done' => $workflowStatus === 'converted'],
];
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Моя заявка №<?php echo $leadId; ?> - Азбука Декора</title>
    <meta name="robots" content="noindex, nofollow">
    <link rel="stylesheet" href="<?php echo asset('assets/css/style.min.css'); ?>">
    <?php include_once __DIR__ . '/../includes/favicon-links.php'; ?>
</head>
<body>
<?php include_once __DIR__ . '/../includes/' . ($viewerRole === 'admin' ? 'admin-account-header.php' : 'client-header.php'); ?>
<main class="dashboard client-lead-page">
    <div class="container">
        <header class="dashboard-header client-lead-heading">
            <div>
                <p class="eyebrow"><?php echo escape($sourceLabel); ?> · №<?php echo $leadId; ?></p>
                <h1><?php echo $clientName ? escape($clientName) . ', ваша заявка принята' : 'Ваша заявка принята'; ?></h1>
                <p><?php echo escape($brief['summary']); ?></p>
            </div>
            <span class="status-badge"><?php echo escape($statusLabels[$workflowStatus] ?? $workflowStatus); ?></span>
        </header>

        <section class="client-lead-progress" aria-label="Статус заявки">
            <?php foreach ($progressSteps as $key => $step): ?>
                <div class="client-lead-progress__step <?php echo $step['done'] ? 'is-complete' : ''; ?> <?php echo $key === $workflowStatus ? 'is-current' : ''; ?>">
                    <span aria-hidden="true"><?php echo $step['done'] ? '✓' : '•'; ?></span>
                    <strong><?php echo escape($step['title']); ?></strong>
                </div>
            <?php endforeach; ?>
        </section>

        <div class="client-lead-layout">
            <div>
                <section class="panel-section">
                    <div class="section-header"><div><p class="eyebrow">Что мы получили</p><h2>Ключевые данные</h2></div></div>
                    <div class="client-lead-highlights">
                        <?php foreach ($brief['highlights'] as $item): ?>
                            <div><span><?php echo escape($item['label']); ?></span><strong><?php echo escape($item['value']); ?></strong></div>
                        <?php endforeach; ?>
                        <?php if (!$brief['highlights']): ?><p class="muted">Основные детали будут уточнены в разговоре.</p><?php endif; ?>
                    </div>
                </section>

                <?php foreach ($brief['sections'] as $section): ?>
                    <section class="panel-section client-brief-section">
                        <div class="section-header"><h2><?php echo escape($section['title']); ?></h2></div>
                        <dl>
                            <?php foreach ($section['items'] as $item): ?>
                                <div><dt><?php echo escape($item['label']); ?></dt><dd><?php echo nl2br(escape($item['value'])); ?></dd></div>
                            <?php endforeach; ?>
                        </dl>
                    </section>
                <?php endforeach; ?>
            </div>

            <aside class="client-lead-sidebar">
                <section class="panel-section client-next-step">
                    <p class="eyebrow">Следующий шаг</p>
                    <h2><?php echo $workflowStatus === 'converted' ? 'Проект уже создан' : 'Мы на связи'; ?></h2>
                    <p><?php echo escape($nextStep); ?></p>
                    <?php if ($workflowStatus === 'converted' && !empty($lead['order_id'])): ?>
                        <a class="btn btn-primary btn-block" href="/account/order.php?id=<?php echo (int)$lead['order_id']; ?>">Открыть проект</a>
                    <?php elseif ($chat): ?>
                        <a class="btn btn-primary btn-block" href="/chat.php?id=<?php echo (int)$chat['id']; ?>">Написать менеджеру</a>
                    <?php endif; ?>
                </section>

                <section class="panel-section client-manager-card">
                    <div class="client-manager-card__identity">
                        <?php if (!empty($lead['manager_avatar'])): ?>
                            <img src="/uploads/avatars/<?php echo escape(basename((string)$lead['manager_avatar'])); ?>" alt="<?php echo escape($managerName); ?>" width="64" height="64">
                        <?php else: ?>
                            <span class="worker-avatar-placeholder" aria-hidden="true"><?php echo escape(utf8Substring($managerName ?: 'А', 0, 1)); ?></span>
                        <?php endif; ?>
                        <div><span>Ваш менеджер</span><h2><?php echo escape($managerName ?: 'Команда Азбуки Декора'); ?></h2></div>
                    </div>
                    <p><?php echo $managerName ? 'Поможет уточнить задачи и подготовит следующий этап.' : 'Менеджер будет назначен после знакомства с заявкой.'; ?></p>
                </section>
            </aside>
        </div>
    </div>
</main>
<?php include_once __DIR__ . '/../includes/' . ($viewerRole === 'admin' ? 'organizer-footer.php' : 'client-footer.php'); ?>
</body>
</html>
