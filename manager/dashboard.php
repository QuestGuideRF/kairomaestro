<?php
require_once __DIR__ . '/../config/config.php';
requireCrmPanel(['manager', 'admin']);
$leadObj = new Lead();
$filters = [
    'status' => trim((string)($_GET['status'] ?? '')),
    'query' => trim((string)($_GET['q'] ?? '')),
];
$leads = $leadObj->getAll($filters);
$statuses = $leadObj->statuses();
$columns = array_fill_keys(array_keys($statuses), []);
foreach ($leads as $lead) {
    $status = $lead['workflow_status'] ?? $leadObj->workflowStatus($lead);
    $columns[$status][] = $lead;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <title>Заявки - <?php echo escape(SITE_NAME); ?></title>
    <link rel="stylesheet" href="<?php echo asset('assets/css/style.min.css'); ?>">
<?php include_once __DIR__ . '/../includes/favicon-links.php'; ?></head>
<body>
<?php include_once __DIR__ . '/../includes/manager-header.php'; ?>
<main class="dashboard manager-dashboard">
    <div class="container">
        <header class="dashboard-header manager-dashboard-header">
            <div><p class="eyebrow">Рабочее место менеджера</p><h1>Заявки</h1><p>Позвоните клиенту, уточните вводные и передайте готовый заказ организаторам.</p></div>
            <a class="btn btn-outline" href="/organizer/orders.php">Все заказы</a>
        </header>
        <section class="manager-lead-metrics" aria-label="Сводка по заявкам">
            <?php foreach ($statuses as $status => $label): ?><article><span><?php echo escape($label); ?></span><strong><?php echo count($columns[$status]); ?></strong></article><?php endforeach; ?>
        </section>
        <form method="GET" class="filter-form manager-lead-filter">
            <div class="form-group"><label for="leadSearch">Поиск</label><input id="leadSearch" name="q" class="form-control" value="<?php echo escape($filters['query']); ?>" placeholder="Номер, имя, телефон или email"></div>
            <div class="form-group"><label for="leadStatus">Статус</label><select id="leadStatus" name="status" class="form-control"><option value="">Все статусы</option><?php foreach ($statuses as $key=>$label): ?><option value="<?php echo escape($key); ?>" <?php echo $filters['status']===$key?'selected':''; ?>><?php echo escape($label); ?></option><?php endforeach; ?></select></div>
            <button class="btn btn-primary" type="submit">Найти</button>
            <?php if ($filters['query'] !== '' || $filters['status'] !== ''): ?><a class="btn btn-outline" href="/manager/dashboard.php">Сбросить</a><?php endif; ?>
        </form>
        <div class="manager-kanban-simple">
            <?php foreach ($statuses as $status => $label): ?>
            <section class="manager-lead-column status-<?php echo escape($status); ?>">
                <header><div><span class="status-dot" aria-hidden="true"></span><h2><?php echo escape($label); ?></h2></div><strong><?php echo count($columns[$status]); ?></strong></header>
                <div class="manager-lead-list">
                    <?php foreach ($columns[$status] as $lead):
                        $assignedToCurrent = !empty($lead['manager_id']) && (int)$lead['manager_id'] === (int)$_SESSION['user_id'];
                        $assignedToOther = currentRole() === 'manager' && !empty($lead['manager_id']) && !$assignedToCurrent;
                        $actionLabel = 'Открыть';
                        if ($assignedToOther) {
                            $actionLabel = 'Просмотреть';
                        } elseif ($status === 'new') {
                            $actionLabel = 'Начать работу';
                        }
                    ?>
                    <article class="manager-lead-card <?php echo $assignedToOther ? 'is-readonly' : ''; ?>">
                        <div class="manager-lead-card__top"><span>№<?php echo (int)$lead['id']; ?> · <?php echo ($lead['source'] ?? '') === 'short_form' ? 'короткая форма' : 'подробный бриф'; ?></span><time><?php echo escape(formatRussianDate($lead['created_at'], 'date_only')); ?></time></div>
                        <h3><?php echo escape($lead['client_name']); ?></h3>
                        <p><?php echo escape($lead['event_type']); ?><?php echo $lead['event_date'] ? ' · ' . escape(formatRussianDate($lead['event_date'], 'date_only')) : ''; ?></p>
                        <dl><div><dt>Телефон</dt><dd><?php echo escape($lead['phone'] ?: 'Не указан'); ?></dd></div><div><dt>Гости</dt><dd><?php echo $lead['guests_count'] ? (int)$lead['guests_count'] : '—'; ?></dd></div></dl>
                        <?php if (!empty($lead['manager_id'])): ?><small>Менеджер: <?php echo escape(trim((string)$lead['manager_first_name'] . ' ' . (string)$lead['manager_last_name'])); ?></small><?php else: ?><small>Свободная заявка</small><?php endif; ?>
                        <?php if (!empty($lead['next_contact_at'])): ?><small>Следующий контакт: <?php echo escape(formatRussianDateTime($lead['next_contact_at'])); ?></small><?php endif; ?>
                        <a class="btn btn-sm <?php echo $status === 'new' && !$assignedToOther ? 'btn-primary' : 'btn-outline'; ?>" href="/manager/lead.php?id=<?php echo (int)$lead['id']; ?>"><?php echo escape($actionLabel); ?></a>
                    </article>
                    <?php endforeach; ?>
                    <?php if (!$columns[$status]): ?><div class="manager-column-empty">Нет заявок</div><?php endif; ?>
                </div>
            </section>
            <?php endforeach; ?>
        </div>
    </div>
</main>
<?php include_once __DIR__ . '/../includes/organizer-footer.php'; ?>
</body>
</html>
