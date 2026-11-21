<?php
require_once __DIR__ . '/../config/config.php';
requireWorkerPanel();
$eventObj = new Event();
$chatObj = new Chat();
$paymentObj = new Payment();
$userId = $_SESSION['user_id'];
$eventId = $_GET['id'] ?? 0;
$event = $eventObj->getById($eventId);
if (!$event) {
    redirect('/worker/dashboard.php');
}
$staff = $eventObj->getEventStaff($eventId);
$isAssigned = false;
$workerStatus = '';
foreach ($staff as $worker) {
    if ($worker['id'] == $userId) {
        $isAssigned = true;
        $workerStatus = $worker['status'];
        break;
    }
}
if (!$isAssigned) {
    redirect('/worker/dashboard.php');
}
$chat = $chatObj->getEventChat($eventId);
$organizerChat = $chatObj->getOrCreateOrganizerChat($userId, $event['organizer_id']);
$payments = $paymentObj->getByEvent($eventId);
$orderStmt = Database::getInstance()->getConnection()->prepare("SELECT id FROM orders WHERE event_id=? LIMIT 1");
$orderStmt->execute([(int)$eventId]);
$orderId = (int)$orderStmt->fetchColumn();
$orderObj = new Order();
$orderTasks = $orderId ? $orderObj->getTasks($orderId, false, $userId) : [];
$timelineObj = new OrderTimeline();
$timeline = $orderId && dbTableExists('order_timeline_items') ? $timelineObj->getForOrder($orderId, $userId, true) : [];
$myPayment = null;
foreach ($payments as $payment) {
    if ($payment['worker_id'] == $userId) {
        $myPayment = $payment;
        break;
    }
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'order_task_status') {
        $result = $orderObj->updateTaskStatus((int)($_POST['task_id'] ?? 0), $userId, $_POST['status'] ?? 'in_progress');
        $error = $result['success'] ? '' : $result['error'];
        $orderTasks = $orderId ? $orderObj->getTasks($orderId, false, $userId) : [];
    } elseif ($action === 'timeline_status') {
        $itemId = (int)($_POST['item_id'] ?? 0);
        if (!$timelineObj->workerCanAccess($itemId, $userId)) {
            $error = 'Этап недоступен';
        } else {
            $result = $timelineObj->changeStatus($itemId, $_POST['status'] ?? 'in_progress', $userId);
            $error = $result['success'] ? '' : $result['error'];
            $timeline = $timelineObj->getForOrder($orderId, $userId, true);
        }
    } elseif ($action === 'timeline_check') {
        $checkId = (int)($_POST['checklist_id'] ?? 0);
        $checkStmt = Database::getInstance()->getConnection()->prepare("SELECT timeline_item_id FROM timeline_checklist_items WHERE id=?");
        $checkStmt->execute([$checkId]);
        $itemId = (int)$checkStmt->fetchColumn();
        if (!$timelineObj->workerCanAccess($itemId, $userId)) {
            $error = 'Пункт недоступен';
        } else {
            $result = $timelineObj->toggleChecklist($checkId, $userId, !empty($_POST['done']));
            $error = $result['success'] ? '' : $result['error'];
            $timeline = $timelineObj->getForOrder($orderId, $userId, true);
        }
    } elseif ($action === 'confirm') {
        $statusResult = $eventObj->updateWorkerStatus($eventId, $userId, 'confirmed');
        if ($statusResult['success']) {
            redirect("/worker/event.php?id=$eventId");
        }
        $error = $statusResult['error'];
    } elseif ($action === 'arrive') {
        $travelType = $_POST['travel_type'] ?? 'none';
        $travelCost = (float)($_POST['travel_cost'] ?? 0);
        $receiptPath = $myPayment['travel_receipt'] ?? null;
        if ($travelType !== 'none') {
            if ($travelCost <= 0) {
                $error = 'Укажите стоимость транспорта';
            } elseif (empty($_FILES['receipt']['name']) && !$receiptPath) {
                $error = 'Приложите чек или скриншот поездки';
            } elseif (!empty($_FILES['receipt']['name'])) {
                $uploadResult = $paymentObj->uploadReceipt($_FILES['receipt']);
                if ($uploadResult['success']) {
                    $receiptPath = $uploadResult['file_path'];
                } else {
                    $error = $uploadResult['error'];
                }
            }
        }
        if ($error === '') {
            $arrivalResult = $eventObj->updateWorkerStatus($eventId, $userId, 'on_location');
            if (!$arrivalResult['success']) {
                $error = $arrivalResult['error'];
            }
        }
        if ($error === '') {
            $paymentData = [
                'event_id' => $eventId,
                'worker_id' => $userId,
                'hours_worked' => $myPayment['hours_worked'] ?? 0,
                'travel_type' => $travelType,
                'travel_distance' => null,
                'travel_cost' => $travelType === 'none' ? 0 : $travelCost,
                'travel_receipt' => $receiptPath,
            ];
            $paymentResult = $myPayment ? $paymentObj->update($myPayment['id'], $paymentData) : $paymentObj->create($paymentData);
            if ($paymentResult['success']) {
                redirect("/worker/event.php?id=$eventId");
            }
            $error = $paymentResult['error'];
        }
    } elseif ($action === 'complete') {
        $statusResult = $eventObj->updateWorkerStatus($eventId, $userId, 'completed');
        if ($statusResult['success']) {
            redirect("/worker/event.php?id=$eventId");
        }
        $error = $statusResult['error'];
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo escape($event['title']); ?> - Азбука Декора</title>
    <?php include_once __DIR__ . '/../includes/favicon-links.php'; ?>
    <link rel="stylesheet" href="<?php echo asset('assets/css/style.min.css'); ?>">
</head>
<body>
    <?php include_once __DIR__ . '/../includes/worker-header.php'; ?>
    <div class="event-page">
        <div class="container">
            <div class="event-header-card">
                <div class="event-header-content">
                    <h1><?php echo escape($event['title']); ?></h1>
                    <span class="status-badge badge-<?php echo $workerStatus; ?>">
                        <?php
                        $statuses = [
                            'pending' => 'Ожидает подтверждения',
                            'confirmed' => 'Подтверждено',
                            'on_location' => 'Вы на месте',
                            'completed' => 'Завершено'
                        ];
                        echo $statuses[$workerStatus] ?? $workerStatus;
                        ?>
                    </span>
                </div>
            </div>
            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo escape($error); ?></div>
            <?php endif; ?>
            <div class="event-content-grid">
                <div class="info-card">
                    <h2><span class="section-title-icon"><img src="/assets/icons/note.svg" alt="" aria-hidden="true"></span>Информация о мероприятии</h2>
                    <div class="info-list">
                        <div class="info-row">
                            <div class="detail-icon"><img src="/assets/icons/calendar.svg" alt="" aria-hidden="true"></div>
                            <div class="info-content">
                                <div class="info-label">Дата</div>
                                <div class="info-value"><?php echo formatRussianDate($event['event_date'], 'full'); ?></div>
                            </div>
                        </div>
                        <div class="info-row">
                            <div class="detail-icon"><img src="/assets/icons/clock.svg" alt="" aria-hidden="true"></div>
                            <div class="info-content">
                                <div class="info-label">Время</div>
                                <div class="info-value">
                                    <?php echo formatTime($event['start_time']); ?>
                                    <?php if ($event['end_time']): ?>
                                        - <?php echo formatTime($event['end_time']); ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="info-row">
                            <div class="detail-icon"><img src="/assets/icons/pin.svg" alt="" aria-hidden="true"></div>
                            <div class="info-content">
                                <div class="info-label">Место</div>
                                <div class="info-value"><?php echo escape($event['location']); ?></div>
                                <?php if ($event['latitude'] && $event['longitude']): ?>
                                <button onclick="openMap(<?php echo $event['latitude']; ?>, <?php echo $event['longitude']; ?>)" class="btn btn-sm btn-outline event-map-button">
                                    <span class="icon"><img src="/assets/icons/pin.svg" alt="" aria-hidden="true"></span>Открыть на карте
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="info-row">
                            <div class="detail-icon"><img src="/assets/icons/briefcase.svg" alt="" aria-hidden="true"></div>
                            <div class="info-content">
                                <div class="info-label">Организатор</div>
                                <div class="info-value">
                                    <?php echo escape($event['organizer_first_name'] . ' ' . $event['organizer_last_name']); ?>
                                    <br>
                                    <a href="tel:<?php echo escape($event['organizer_phone']); ?>" class="phone-link">
                                        <?php echo escape($event['organizer_phone']); ?>
                                    </a>
                                </div>
                            </div>
                        </div>
                        <?php if ($event['technical_task']): ?>
                        <div class="info-row">
                            <div class="info-icon"><img src="/assets/icons/note.svg" alt="" aria-hidden="true"></div>
                            <div class="info-content">
                                <div class="info-label">Техническое задание</div>
                                <div class="info-value"><?php echo nl2br(escape($event['technical_task'])); ?></div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="info-card">
                    <h2><span class="icon"><img src="/assets/icons/users.svg" alt="" aria-hidden="true"></span>Команда (<?php echo count($staff); ?>)</h2>
                    <div class="team-list">
                        <?php foreach ($staff as $worker): ?>
                        <div class="team-member-item">
                            <div class="team-member-avatar">
                                <?php if ($worker['avatar']): ?>
                                    <img src="/uploads/avatars/<?php echo escape($worker['avatar']); ?>" alt="Avatar">
                                <?php else: ?>
                                    <div class="team-avatar-placeholder">
                                        <?php echo escape(utf8Substring($worker['first_name'], 0, 1)); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="team-member-info">
                                <div class="team-member-name">
                                    <?php echo escape($worker['first_name'] . ' ' . $worker['last_name']); ?>
                                </div>
                                <div class="team-member-specialty">
                                    <?php echo escape($worker['specialty']); ?>
                                </div>
                            </div>
                            <span class="team-member-status badge-<?php echo $worker['status']; ?>">
                                <?php echo $statuses[$worker['status']] ?? $worker['status']; ?>
                            </span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php if ($orderTasks): ?>
            <section class="panel-section worker-order-tasks">
                <div class="section-header"><h2>Мои задачи</h2></div>
                <div class="task-list">
                    <?php foreach ($orderTasks as $task): ?>
                    <article class="task-item">
                        <span class="badge badge-<?php echo escape($task['status']); ?>"><?php echo escape(['todo'=>'К выполнению','in_progress'=>'В работе','done'=>'Выполнено','cancelled'=>'Отменено'][$task['status']] ?? $task['status']); ?></span>
                        <h3><?php echo escape($task['title']); ?></h3>
                        <p><?php echo escape($task['description']); ?></p>
                        <?php if (!in_array($task['status'], ['done','cancelled'], true)): ?><form method="POST" class="inline-form"><?php echo csrfField(); ?><input type="hidden" name="action" value="order_task_status"><input type="hidden" name="task_id" value="<?php echo (int)$task['id']; ?>"><?php if ($task['status'] === 'todo'): ?><button class="btn btn-sm btn-outline" name="status" value="in_progress" type="submit">Начать</button><?php endif; ?><button class="btn btn-sm btn-primary" name="status" value="done" type="submit">Выполнено</button></form><?php endif; ?>
                    </article>
                    <?php endforeach; ?>
                </div>
            </section>
            <?php endif; ?>
            <?php if ($timeline): ?>
            <section class="panel-section worker-timeline-mobile">
                <div class="section-header"><h2>Таймлайн и задачи</h2></div>
                <div class="event-timeline-list">
                    <?php foreach ($timeline as $item): ?>
                    <article class="event-timeline-item">
                        <time><?php echo escape(date('H:i', strtotime($item['starts_at']))); ?></time>
                        <div>
                            <span class="badge"><?php echo escape($timelineObj->statuses()[$item['status']] ?? $item['status']); ?></span>
                            <h3><?php echo escape($item['title']); ?></h3>
                            <p><?php echo escape($item['description']); ?></p>
                            <?php if ($item['location']): ?><p class="section-note"><?php echo escape($item['location']); ?></p><?php endif; ?>
                            <?php if (!empty($item['files'])): ?><div class="timeline-files"><?php foreach ($item['files'] as $file): ?><a href="<?php echo escape(secureFileUrl('timeline', $file['id'])); ?>" target="_blank" rel="noopener"><?php echo escape($file['file_name']); ?></a><?php endforeach; ?></div><?php endif; ?>
                            <ul class="checklist">
                                <?php foreach ($item['checklist'] as $check): ?>
                                <li class="<?php echo $check['is_done'] ? 'done' : ''; ?>"><form method="POST" class="inline-form"><?php echo csrfField(); ?><input type="hidden" name="action" value="timeline_check"><input type="hidden" name="checklist_id" value="<?php echo (int)$check['id']; ?>"><input type="hidden" name="done" value="<?php echo $check['is_done'] ? '0' : '1'; ?>"><button type="submit" class="check-button" aria-label="Изменить выполнение"></button></form><?php echo escape($check['title']); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                        <form method="POST" class="timeline-worker-actions"><?php echo csrfField(); ?><input type="hidden" name="action" value="timeline_status"><input type="hidden" name="item_id" value="<?php echo (int)$item['id']; ?>"><button class="btn btn-sm btn-outline" name="status" value="in_progress">Начать</button><button class="btn btn-sm btn-primary" name="status" value="completed">Выполнено</button></form>
                    </article>
                    <?php endforeach; ?>
                </div>
            </section>
            <?php endif; ?>
            <div class="actions-card">
                <h2>Действия</h2>
                <div class="actions-grid">
                    <?php if ($workerStatus === 'pending'): ?>
                    <form method="POST" action="">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="action" value="confirm">
                        <button type="submit" class="btn btn-success btn-lg btn-block">
                            Подтвердить участие
                        </button>
                    </form>
                    <?php endif; ?>
                    <?php if ($workerStatus === 'confirmed' && strtotime($event['event_date']) <= strtotime(date('Y-m-d'))): ?>
                    <form method="POST" action="" enctype="multipart/form-data" class="arrival-form">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="action" value="arrive">
                        <div class="form-group">
                            <span class="form-label" id="arrival-travel-label">Транспорт</span>
                            <div class="radio-group">
                                <label class="radio-label" for="arrival-travel-none"><input id="arrival-travel-none" type="radio" name="travel_type" value="none" checked> Без компенсации</label>
                                <label class="radio-label" for="arrival-travel-car"><input id="arrival-travel-car" type="radio" name="travel_type" value="own_car"> Автомобиль</label>
                                <label class="radio-label" for="arrival-travel-taxi"><input id="arrival-travel-taxi" type="radio" name="travel_type" value="taxi"> Такси</label>
                            </div>
                        </div>
                        <div class="travel-details" id="arrivalTransportDetails" hidden>
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="arrival_travel_cost">Стоимость транспорта</label>
                                    <input type="number" id="arrival_travel_cost" name="travel_cost" class="form-control" min="0" step="1" placeholder="Например: 850">
                                </div>
                                <div class="form-group">
                                    <label for="arrival_receipt">Чек или скриншот</label>
                                    <input type="file" id="arrival_receipt" name="receipt" class="form-control" accept="image/jpeg,image/png,image/webp,image/gif,application/pdf">
                                </div>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary btn-lg btn-block">
                            Я на месте
                        </button>
                    </form>
                    <?php endif; ?>
                    <?php if ($workerStatus === 'on_location'): ?>
                    <form method="POST" action="">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="action" value="complete">
                        <button type="submit" class="btn btn-success btn-lg btn-block">
                            Завершить работу
                        </button>
                    </form>
                    <?php endif; ?>
                    <?php if ($chat): ?>
                    <a href="/chat.php?id=<?php echo $chat['id']; ?>" class="btn btn-primary btn-lg btn-block">
                        Чат с командой
                    </a>
                    <?php endif; ?>
                    <?php if ($organizerChat['success'] && $organizerChat['chat_id']): ?>
                    <a href="/chat.php?id=<?php echo $organizerChat['chat_id']; ?>" class="btn btn-secondary btn-lg btn-block">
                        Чат с организатором
                    </a>
                    <?php endif; ?>
                    <?php if ($workerStatus === 'completed'): ?>
                    <a href="/worker/payment.php?event_id=<?php echo $eventId; ?>" class="btn btn-secondary btn-lg btn-block">
                        <span class="icon"><img src="/assets/icons/wallet.svg" alt="" aria-hidden="true"></span><?php echo $myPayment ? 'Просмотреть' : 'Добавить'; ?> оплату
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            <?php if ($myPayment): ?>
            <div class="payment-info-card">
                <h2><span class="section-title-icon"><img src="/assets/icons/wallet.svg" alt="" aria-hidden="true"></span>Оплата</h2>
                <div class="payment-details-grid">
                    <div class="payment-detail-item">
                        <div class="payment-label">Часы работы</div>
                        <div class="payment-value"><?php echo $myPayment['hours_worked']; ?> ч</div>
                    </div>
                    <div class="payment-detail-item">
                        <div class="payment-label">Оплата за работу</div>
                        <div class="payment-value"><?php echo number_format($myPayment['work_payment'], 0, ',', ' '); ?> ₽</div>
                    </div>
                    <?php if ($myPayment['travel_cost'] > 0): ?>
                    <div class="payment-detail-item">
                        <div class="payment-label">Компенсация проезда</div>
                        <div class="payment-value"><?php echo number_format($myPayment['travel_cost'], 0, ',', ' '); ?> ₽</div>
                    </div>
                    <?php endif; ?>
                    <div class="payment-detail-item payment-total-item">
                        <div class="payment-label">Итого</div>
                        <div class="payment-value-total"><?php echo number_format($myPayment['total_amount'], 0, ',', ' '); ?> ₽</div>
                    </div>
                </div>
                <div class="payment-status-badge">
                    <span class="badge badge-<?php echo $myPayment['status']; ?>">
                        <?php
                        $paymentStatuses = [
                            'pending' => 'Ожидает подтверждения',
                            'approved' => 'Подтверждено',
                            'paid' => 'Оплачено'
                        ];
                        echo $paymentStatuses[$myPayment['status']] ?? $myPayment['status'];
                        ?>
                    </span>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php include_once __DIR__ . '/../includes/worker-footer.php'; ?>
    <script>
        function openMap(lat, lng) {
            window.open(`https:
        }
        document.querySelectorAll('input[name="travel_type"]').forEach(function (radio) {
            radio.addEventListener('change', function () {
                const details = document.getElementById('arrivalTransportDetails');
                if (details) details.hidden = this.value === 'none';
            });
        });
    </script>
</body>
</html>
