<?php
require_once __DIR__ . '/../config/config.php';
requireWorkerPanel();
$paymentObj = new Payment();
$eventObj = new Event();
$userId = $_SESSION['user_id'];
$eventId = $_GET['event_id'] ?? 0;
$event = $eventObj->getById($eventId);
if (!$event) {
    redirect('/worker/dashboard.php');
}
$payments = $paymentObj->getByEvent($eventId);
$arrivalStmt = Database::getInstance()->getConnection()->prepare('SELECT arrived_at FROM event_staff WHERE event_id=? AND worker_id=? LIMIT 1');
$arrivalStmt->execute([(int)$eventId, (int)$userId]);
$canClaimTravel = (bool)$arrivalStmt->fetchColumn();
$existingPayment = null;
foreach ($payments as $payment) {
    if ($payment['worker_id'] == $userId) {
        $existingPayment = $payment;
        break;
    }
}
$error = '';
$success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $travelType = $_POST['travel_type'] ?? 'none';
    $data = [
        'event_id' => $eventId,
        'worker_id' => $userId,
        'hours_worked' => $_POST['hours_worked'] ?? 0,
        'travel_type' => $travelType,
        'travel_distance' => $_POST['travel_distance'] ?? null,
        'travel_cost' => $travelType === 'own_car' ? ($_POST['travel_cost_car'] ?? null) : ($_POST['travel_cost'] ?? null),
    ];
    $receiptFile = $travelType === 'own_car' ? ($_FILES['receipt_car'] ?? null) : ($_FILES['receipt'] ?? null);
    if (!empty($receiptFile['name'])) {
        $uploadResult = $paymentObj->uploadReceipt($receiptFile);
        if ($uploadResult['success']) {
            $data['travel_receipt'] = $uploadResult['file_path'];
        } else {
            $error = $uploadResult['error'];
        }
    }
    if ($error === '') {
        if ($existingPayment) {
            $result = $paymentObj->update($existingPayment['id'], $data);
        } else {
            $result = $paymentObj->create($data);
        }
    } else {
        $result = ['success' => false, 'error' => $error];
    }
    if ($result['success']) {
        $success = 'Данные об оплате сохранены!';
        $payments = $paymentObj->getByEvent($eventId);
        foreach ($payments as $payment) {
            if ($payment['worker_id'] == $userId) {
                $existingPayment = $payment;
                break;
            }
        }
    } else {
        $error = $result['error'];
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Оплата - Азбука Декора</title>
    <?php include_once __DIR__ . '/../includes/favicon-links.php'; ?>
    <link rel="stylesheet" href="<?php echo asset('assets/css/style.min.css'); ?>">
</head>
<body>
    <?php include_once __DIR__ . '/../includes/worker-header.php'; ?>
    <div class="payment-page">
        <div class="container">
            <h1>Оплата за мероприятие</h1>
            <h2><?php echo escape($event['title']); ?></h2>
            <p><?php echo date('d.m.Y', strtotime($event['event_date'])); ?></p>
            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo escape($error); ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="alert alert-success"><?php echo escape($success); ?></div>
            <?php endif; ?>
            <form method="POST" action="" enctype="multipart/form-data" class="payment-form">
                <?php echo csrfField(); ?>
                <div class="form-section">
                    <h3>Рабочее время</h3>
                    <div class="form-group">
                        <label for="hours_worked">Количество часов работы *</label>
                        <input type="number" id="hours_worked" name="hours_worked" class="form-control"
                               value="<?php echo $existingPayment['hours_worked'] ?? ''; ?>"
                               min="0" step="0.5" required>
                    </div>
                </div>
                <div class="form-section">
                    <h3>Транспортные расходы</h3>
                    <?php if (!$canClaimTravel): ?><p class="section-note">Транспорт можно указать после отметки прибытия на странице мероприятия.</p><?php endif; ?>
                    <div class="form-group">
                        <span class="form-label" id="payment-travel-label">Как добирались?</span>
                        <div class="radio-group">
                            <label class="radio-label" for="payment-travel-none">
                                <input id="payment-travel-none" type="radio" name="travel_type" value="none"
                                       <?php echo ($existingPayment['travel_type'] ?? 'none') === 'none' ? 'checked' : ''; ?>>
                                Пешком / Не требуется компенсация
                            </label>
                            <label class="radio-label" for="payment-travel-car">
                                <input id="payment-travel-car" type="radio" name="travel_type" value="own_car"
                                       <?php echo !$canClaimTravel ? 'disabled' : ''; ?>
                                       <?php echo ($existingPayment['travel_type'] ?? '') === 'own_car' ? 'checked' : ''; ?>>
                                На своём автомобиле
                            </label>
                            <label class="radio-label" for="payment-travel-taxi">
                                <input id="payment-travel-taxi" type="radio" name="travel_type" value="taxi"
                                       <?php echo !$canClaimTravel ? 'disabled' : ''; ?>
                                       <?php echo ($existingPayment['travel_type'] ?? '') === 'taxi' ? 'checked' : ''; ?>>
                                На такси
                            </label>
                        </div>
                    </div>
                    <div class="travel-details" id="carDetails" style="display: <?php echo ($existingPayment['travel_type'] ?? '') === 'own_car' ? 'block' : 'none'; ?>;">
                        <div class="form-group">
                            <label for="car_travel_cost">Стоимость транспорта (₽)</label>
                            <input type="number" id="car_travel_cost" name="travel_cost_car" class="form-control"
                                   value="<?php echo $existingPayment['travel_cost'] ?? ''; ?>"
                                   min="0" step="1">
                        </div>
                        <div class="form-group">
                            <label for="car_receipt">Чек или скриншот</label>
                            <input type="file" id="car_receipt" name="receipt_car" class="form-control" accept="image/*,.pdf">
                            <?php if ($existingPayment && $existingPayment['travel_receipt']): ?>
                            <small>
                                Текущий чек: <a href="<?php echo escape(secureFileUrl('receipt', $existingPayment['id'])); ?>" target="_blank" rel="noopener">Открыть</a>
                            </small>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="travel-details" id="taxiDetails" style="display: <?php echo ($existingPayment['travel_type'] ?? '') === 'taxi' ? 'block' : 'none'; ?>;">
                        <div class="form-group">
                            <label for="travel_cost">Стоимость транспорта (₽)</label>
                            <input type="number" id="travel_cost" name="travel_cost" class="form-control"
                                   value="<?php echo $existingPayment['travel_cost'] ?? ''; ?>"
                                   min="0" step="1">
                        </div>
                        <div class="form-group">
                            <label for="receipt">Чек (скриншот)</label>
                            <input type="file" id="receipt" name="receipt" class="form-control" accept="image/*,.pdf">
                            <?php if ($existingPayment && $existingPayment['travel_receipt']): ?>
                            <small>
                                Текущий чек: <a href="<?php echo escape(secureFileUrl('receipt', $existingPayment['id'])); ?>" target="_blank" rel="noopener">Открыть</a>
                            </small>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php if ($existingPayment): ?>
                <div class="payment-summary">
                    <h3>Расчёт оплаты</h3>
                    <div class="summary-item">
                        <span>Оплата за работу:</span>
                        <strong><?php echo number_format($existingPayment['work_payment'], 0, ',', ' '); ?> ₽</strong>
                    </div>
                    <?php if ($existingPayment['travel_cost'] > 0): ?>
                    <div class="summary-item">
                        <span>Компенсация проезда:</span>
                        <strong><?php echo number_format($existingPayment['travel_cost'], 0, ',', ' '); ?> ₽</strong>
                    </div>
                    <?php endif; ?>
                    <div class="summary-item summary-total">
                        <span>Итого к оплате:</span>
                        <strong><?php echo number_format($existingPayment['total_amount'], 0, ',', ' '); ?> ₽</strong>
                    </div>
                    <span class="badge badge-<?php echo $existingPayment['status']; ?>">
                        <?php
                        $statuses = [
                            'pending' => 'Ожидает подтверждения',
                            'approved' => 'Подтверждено',
                            'paid' => 'Оплачено'
                        ];
                        echo $statuses[$existingPayment['status']];
                        ?>
                    </span>
                </div>
                <?php endif; ?>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Сохранить</button>
                    <a href="/worker/event.php?id=<?php echo $eventId; ?>" class="btn btn-secondary">Назад</a>
                </div>
            </form>
        </div>
    </div>
    <?php include_once __DIR__ . '/../includes/worker-footer.php'; ?>
    <script>
        document.querySelectorAll('input[name="travel_type"]').forEach(radio => {
            radio.addEventListener('change', function() {
                document.getElementById('carDetails').style.display = 'none';
                document.getElementById('taxiDetails').style.display = 'none';
                if (this.value === 'own_car') {
                    document.getElementById('carDetails').style.display = 'block';
                } else if (this.value === 'taxi') {
                    document.getElementById('taxiDetails').style.display = 'block';
                }
            });
        });
    </script>
</body>
</html>
