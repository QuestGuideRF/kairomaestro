<?php
require_once __DIR__ . '/../config/config.php';
requireWorkerPanel();
$userObj = new User();
$eventObj = new Event();
$notificationObj = new Notification();
$paymentObj = new Payment();
$userId = (int)$_SESSION['user_id'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $result = ['success'=>false,'error'=>'Недопустимое действие'];
    if (($_POST['action'] ?? '') === 'assignment_response') {
        $result = $eventObj->respondToAssignment(
            (int)($_POST['assignment_id'] ?? 0),
            $userId,
            $_POST['decision'] ?? '',
            $_POST['decline_reason'] ?? ''
        );
    }
    if ($result['success']) {
        $message = !empty($result['unchanged'])
            ? 'Ответ уже был сохранён.'
            : 'Ответ организатору отправлен.';
        $_SESSION['worker_dashboard_flash'] = ['type' => 'success', 'message' => $message];
    } else {
        $_SESSION['worker_dashboard_flash'] = [
            'type' => 'error',
            'message' => $result['error'] ?? 'Не удалось сохранить ответ',
        ];
    }
    redirect('/worker/dashboard.php');
}
$flash = $_SESSION['worker_dashboard_flash'] ?? null;
unset($_SESSION['worker_dashboard_flash']);
$profile = $userObj->getWorkerProfile($userId);
$upcomingEvents = array_values(array_filter(
    $eventObj->getByWorker($userId, ['status'=>'active']),
    static fn($event) => strtotime((string)$event['event_date']) >= strtotime(date('Y-m-d'))
        && ($event['worker_status'] ?? '') !== 'declined'
));
$notifications = $notificationObj->getUnread($userId);
$statistics = $paymentObj->getWorkerStatistics($userId);
$currentYear = (int)date('Y');
$currentMonth = (int)date('m');
$calendar = $eventObj->getWorkerCalendar($userId, $currentYear, $currentMonth);
$statusLabels = [
    'pending'=>'Ожидает подтверждения',
    'confirmed'=>'Участие подтверждено',
    'on_location'=>'На площадке',
    'completed'=>'Работа завершена',
    'declined'=>'Участие отклонено',
];
?>
<!DOCTYPE html>
<html lang="ru">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Рабочий кабинет - <?php echo escape(SITE_NAME); ?></title><link rel="stylesheet" href="<?php echo asset('assets/css/style.min.css'); ?>"><?php include_once __DIR__ . '/../includes/favicon-links.php'; ?></head>
<body>
<?php include_once __DIR__ . '/../includes/worker-header.php'; ?>
<main class="dashboard worker-dashboard worker-dashboard-modern">
<div class="container">
    <header class="dashboard-header worker-dashboard__header"><div><p class="eyebrow">Рабочий кабинет</p><h1><?php echo escape($profile['first_name']); ?>, ваши события</h1><p class="dashboard-subtitle">Приглашения, задачи и доступность собраны в одном месте.</p></div><a href="/worker/calendar.php" class="btn btn-outline">Открыть календарь</a></header>
    <?php if($flash): ?><div class="alert alert-<?php echo $flash['type']==='success'?'success':'error'; ?>"><?php echo escape($flash['message']); ?></div><?php endif; ?>
    <section class="worker-overview-grid" aria-label="Рабочая сводка">
        <article><span>Мероприятий</span><strong><?php echo (int)($statistics['total_events']??0); ?></strong></article>
        <article><span>Часов работы</span><strong><?php echo number_format((float)($statistics['total_hours']??0),1,',',' '); ?></strong></article>
        <article><span>Начислено</span><strong><?php echo number_format((float)($statistics['total_earnings']??0),0,',',' '); ?> ₽</strong></article>
        <article><span>Новых уведомлений</span><strong><?php echo count($notifications); ?></strong></article>
    </section>
    <section class="worker-upcoming-section">
        <div class="section-header"><div><p class="eyebrow">Расписание</p><h2>Предстоящие мероприятия</h2></div><a href="/worker/calendar.php" class="quiet-link">Весь календарь</a></div>
        <?php if(!$upcomingEvents): ?><div class="empty-state worker-empty-state"><p>Новых назначений пока нет.</p></div><?php endif; ?>
        <div class="worker-event-grid">
        <?php foreach(array_slice($upcomingEvents,0,8) as $event):
            $clientName=trim((string)($event['client_first_name']??'').' '.(string)($event['client_last_name']??''));
            $venue=$event['venue']?:'';
            $address=$event['address']?:($event['location']?:'Адрес уточняется');
        ?>
            <article class="worker-event-card status-<?php echo escape($event['worker_status']); ?>">
                <div class="worker-event-date"><strong><?php echo date('d',strtotime($event['event_date'])); ?></strong><span><?php echo escape(formatRussianDate($event['event_date'],'month_short')); ?></span><small><?php echo date('Y',strtotime($event['event_date'])); ?></small></div>
                <div class="worker-event-card__body">
                    <div class="worker-event-card__heading"><div><span class="event-type"><?php echo escape($event['event_type']?:'Событие'); ?></span><h3><?php echo escape($clientName?:$event['title']); ?></h3></div><span class="badge badge-<?php echo escape($event['worker_status']); ?>"><?php echo escape($statusLabels[$event['worker_status']]??$event['worker_status']); ?></span></div>
                    <dl class="worker-event-facts"><div><dt>Время</dt><dd><?php echo escape(substr((string)$event['start_time'],0,5)); ?><?php echo $event['end_time']?' - '.escape(substr((string)$event['end_time'],0,5)):''; ?></dd></div><div><dt>Площадка</dt><dd><?php echo escape($venue?:'Уточняется'); ?></dd></div><div><dt>Адрес</dt><dd><?php echo escape($address); ?></dd></div></dl>
                    <div class="worker-event-actions">
                        <?php if($event['worker_status']==='pending'): ?>
                        <form method="POST"><?php echo csrfField(); ?><input type="hidden" name="action" value="assignment_response"><input type="hidden" name="assignment_id" value="<?php echo (int)$event['assignment_id']; ?>"><input type="hidden" name="decision" value="confirmed"><button class="btn btn-primary" type="submit">Подтвердить участие</button></form>
                        <button class="btn btn-outline decline-assignment" type="button" data-assignment-id="<?php echo (int)$event['assignment_id']; ?>" data-event-title="<?php echo escape($event['title']); ?>">Отклонить</button>
                        <?php endif; ?>
                        <a href="/worker/event.php?id=<?php echo (int)$event['id']; ?>" class="btn btn-outline">Подробнее</a>
                    </div>
                </div>
            </article>
        <?php endforeach; ?>
        </div>
    </section>
    <?php if($notifications): ?><section class="worker-notification-preview"><div class="section-header"><div><p class="eyebrow">Последнее</p><h2>Уведомления</h2></div><a class="quiet-link" href="/worker/notifications.php">Показать все</a></div><div class="worker-notification-list"><?php foreach(array_slice($notifications,0,3) as $notification): ?><a href="<?php echo escape($notification['link']?:'/worker/notifications.php'); ?>"><span><?php echo escape(formatRussianDateTime($notification['created_at'])); ?></span><strong><?php echo escape($notification['title']); ?></strong><p><?php echo escape($notification['message']); ?></p></a><?php endforeach; ?></div></section><?php endif; ?>
    <section class="mini-calendar-section"><div class="section-header"><div><p class="eyebrow">Доступность</p><h2><?php echo escape(formatRussianDate(sprintf('%04d-%02d-01',$currentYear,$currentMonth),'month_name')); ?> <?php echo $currentYear; ?></h2></div><a href="/worker/calendar.php" class="btn btn-sm btn-outline">Изменить доступность</a></div><div class="mini-calendar" id="miniCalendar"></div></section>
</div>
</main>
<dialog class="assignment-decline-dialog" id="assignmentDeclineDialog">
    <form method="POST">
        <?php echo csrfField(); ?><input type="hidden" name="action" value="assignment_response"><input type="hidden" name="decision" value="declined"><input type="hidden" name="assignment_id" id="declineAssignmentId" value="">
        <div class="dialog-heading"><div><p class="eyebrow">Ответ организатору</p><h2>Отклонить участие</h2></div><button type="button" class="dialog-close" aria-label="Закрыть">×</button></div>
        <p id="declineEventTitle"></p><div class="form-group"><label for="declineReason">Причина (необязательно)</label><textarea id="declineReason" name="decline_reason" class="form-control" rows="4" maxlength="1000" placeholder="Например, занят в это время"></textarea></div>
        <div class="form-actions"><button class="btn btn-outline" type="button" data-dialog-cancel>Отмена</button><button class="btn btn-primary" type="submit">Отклонить приглашение</button></div>
    </form>
</dialog>
<?php include_once __DIR__ . '/../includes/worker-footer.php'; ?>
<script src="<?php echo asset('assets/js/calendar.js'); ?>"></script>
<script>
const calendarData={workDays:<?php echo json_encode(array_keys($calendar['work_days'])); ?>,unavailableDays:<?php echo json_encode($calendar['unavailable_days']); ?>,year:<?php echo $currentYear; ?>,month:<?php echo $currentMonth; ?>};
renderMiniCalendar('miniCalendar',calendarData);
(function(){const dialog=document.getElementById('assignmentDeclineDialog');if(!dialog)return;document.querySelectorAll('.decline-assignment').forEach((button)=>button.addEventListener('click',()=>{document.getElementById('declineAssignmentId').value=button.dataset.assignmentId;document.getElementById('declineEventTitle').textContent=button.dataset.eventTitle;document.getElementById('declineReason').value='';dialog.showModal();}));dialog.querySelector('.dialog-close').addEventListener('click',()=>dialog.close());dialog.querySelector('[data-dialog-cancel]').addEventListener('click',()=>dialog.close());dialog.addEventListener('click',(event)=>{if(event.target===dialog)dialog.close();});})();
</script>
</body></html>
