<?php
require_once __DIR__ . '/../config/config.php';
requireWorkerPanel();
$eventObj = new Event();
$availabilityObj = new Availability();
$userId = $_SESSION['user_id'];
$year = max(2000, min(2100, (int)($_GET['year'] ?? date('Y'))));
$month = max(1, min(12, (int)($_GET['month'] ?? date('m'))));
$error = '';
$calendar = $eventObj->getWorkerCalendar($userId, $year, $month);
$unavailableDays = $availabilityObj->getUnavailableDays($userId,
    "$year-$month-01",
    date('Y-m-t', strtotime("$year-$month-01"))
);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $date = $_POST['date'] ?? '';
    $action = $_POST['action'] ?? '';
    $reason = $_POST['reason'] ?? '';
    if ($action === 'mark_unavailable') {
        $result = $availabilityObj->markUnavailable($userId, $date, $reason);
    } elseif ($action === 'mark_available') {
        $result = $availabilityObj->markAvailable($userId, $date);
    } else {
        $result = ['success' => false, 'error' => 'Неизвестное действие'];
    }
    if ($result['success']) {
        redirect("/worker/calendar.php?year=$year&month=$month");
    }
    $error = $result['error'];
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Календарь - Азбука Декора</title>
    <?php include_once __DIR__ . '/../includes/favicon-links.php'; ?>
    <link rel="stylesheet" href="<?php echo asset('assets/css/style.min.css'); ?>">
</head>
<body>
    <?php include_once __DIR__ . '/../includes/worker-header.php'; ?>
    <main class="calendar-page worker-calendar-page">
        <div class="container">
            <?php if ($error): ?><div class="alert alert-error"><?php echo escape($error); ?></div><?php endif; ?>
            <div class="calendar-header">
                <a href="?year=<?php echo date('Y', strtotime("$year-$month-01 -1 month")); ?>&month=<?php echo date('m', strtotime("$year-$month-01 -1 month")); ?>" class="btn btn-icon" aria-label="Предыдущий месяц">←</a>
                <h1><?php
                    $months = [
                        1 => 'Январь', 2 => 'Февраль', 3 => 'Март', 4 => 'Апрель',
                        5 => 'Май', 6 => 'Июнь', 7 => 'Июль', 8 => 'Август',
                        9 => 'Сентябрь', 10 => 'Октябрь', 11 => 'Ноябрь', 12 => 'Декабрь'
                    ];
                    echo $months[(int)$month] . ' ' . $year;
                ?></h1>
                <a href="?year=<?php echo date('Y', strtotime("$year-$month-01 +1 month")); ?>&month=<?php echo date('m', strtotime("$year-$month-01 +1 month")); ?>" class="btn btn-icon" aria-label="Следующий месяц">→</a>
            </div>
            <div class="worker-calendar-shell">
                <div class="calendar-legend" aria-label="Обозначения календаря">
                    <div class="legend-item"><span class="color-box green"></span> Свободен</div>
                    <div class="legend-item"><span class="color-box red"></span> Назначена работа</div>
                    <div class="legend-item"><span class="color-box gray"></span> Недоступен</div>
                </div>
                <div class="calendar-grid worker-calendar-grid">
                <div class="calendar-weekday">Пн</div>
                <div class="calendar-weekday">Вт</div>
                <div class="calendar-weekday">Ср</div>
                <div class="calendar-weekday">Чт</div>
                <div class="calendar-weekday">Пт</div>
                <div class="calendar-weekday">Сб</div>
                <div class="calendar-weekday">Вс</div>
                <?php
                $firstDay = date('N', strtotime("$year-$month-01"));
                $daysInMonth = date('t', strtotime("$year-$month-01"));
                for ($i = 1; $i < $firstDay; $i++) {
                    echo '<div class="calendar-day empty"></div>';
                }
                for ($day = 1; $day <= $daysInMonth; $day++) {
                    $date = sprintf("%04d-%02d-%02d", $year, $month, $day);
                    $dateStr = date('Y-m-d', strtotime($date));
                    $class = 'calendar-day';
                    $status = 'free';
                    if (isset($calendar['work_days'][$dateStr])) {
                        $class .= ' work-day';
                        $status = 'work';
                    } elseif (in_array($dateStr, $calendar['unavailable_days'])) {
                        $class .= ' unavailable-day';
                        $status = 'unavailable';
                    } else {
                        $class .= ' free-day';
                    }
                    if ($dateStr === date('Y-m-d')) {
                        $class .= ' today';
                    }
                    $dayLabel = 'Открыть ' . date('d.m.Y', strtotime($dateStr));
                    echo '<button type="button" class="' . $class . '" data-date="' . $dateStr . '" data-status="' . $status . '" aria-label="' . $dayLabel . '" onclick="openDayModal(\'' . $dateStr . '\', \'' . $status . '\')">';
                    echo '<span class="day-number">' . $day . '</span>';
                    echo '</button>';
                }
                ?>
                </div>
            </div>
        </div>
    </main>
    <dialog id="dayModal" class="modal day-modal" aria-labelledby="modalDate">
        <div class="modal-content day-modal__panel">
            <button type="button" class="modal-close" aria-label="Закрыть окно" onclick="closeDayModal()">&times;</button>
            <h2 id="modalDate"></h2>
            <div id="modalContent" class="day-modal__body" aria-live="polite"></div>
        </div>
    </dialog>
    <?php include_once __DIR__ . '/../includes/worker-footer.php'; ?>
    <script>
        function openDayModal(date, status) {
            const modal = document.getElementById('dayModal');
            const modalDate = document.getElementById('modalDate');
            const modalContent = document.getElementById('modalContent');
            modalDate.textContent = new Date(date + 'T12:00:00').toLocaleDateString('ru-RU', {
                weekday: 'long',
                year: 'numeric',
                month: 'long',
                day: 'numeric'
            });
            if (status === 'work') {
                modalContent.innerHTML = '<div class="day-modal__loading">Загружаем назначенное событие...</div>';
                fetch(`/api/get-event-by-date.php?date=${date}`)
                    .then(res => {
                        if (!res.ok) {
                            throw new Error('Не удалось загрузить событие');
                        }
                        return res.json();
                    })
                    .then(data => {
                        if (data.success) {
                            modalContent.innerHTML = `
                                <div class="day-status day-status--work">
                                    <span>Назначена работа</span>
                                    <p>На этот день у вас есть событие.</p>
                                </div>
                                <div class="event-details day-modal__event">
                                    <h3>${escapeHtml(data.event.title)}</h3>
                                    <p><strong>Время:</strong> ${escapeHtml(data.event.start_time)} ${data.event.end_time ? '- ' + escapeHtml(data.event.end_time) : ''}</p>
                                    <p><strong>Место:</strong> ${escapeHtml(data.event.location)}</p>
                                    ${data.event.technical_task ? '<p><strong>Задание:</strong> ' + escapeHtml(data.event.technical_task) + '</p>' : ''}
                                    <div class="day-modal__actions">
                                        <a href="/worker/event.php?id=${Number(data.event.id)}" class="btn btn-primary">Открыть событие</a>
                                    </div>
                                </div>
                            `;
                        } else {
                            throw new Error(data.error || 'Событие не найдено');
                        }
                    })
                    .catch(error => {
                        modalContent.innerHTML = `<div class="alert alert-error">${escapeHtml(error.message)}</div>`;
                    });
            } else if (status === 'unavailable') {
                modalContent.innerHTML = `
                    <div class="day-status day-status--unavailable">
                        <span>Недоступен</span>
                        <p>Вы отметили этот день как нерабочий.</p>
                    </div>
                    <form method="POST" action="" class="day-modal__form">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="date" value="${date}">
                        <input type="hidden" name="action" value="mark_available">
                        <div class="day-modal__actions">
                            <button type="submit" class="btn btn-primary">Сделать день доступным</button>
                        </div>
                    </form>
                `;
            } else {
                modalContent.innerHTML = `
                    <div class="day-status day-status--free">
                        <span>Свободен</span>
                        <p>Организатор видит, что вас можно назначить на эту дату.</p>
                    </div>
                    <form method="POST" action="" class="day-modal__form">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="date" value="${date}">
                        <input type="hidden" name="action" value="mark_unavailable">
                        <div class="form-group">
                            <label for="reason">Причина (необязательно)</label>
                            <input type="text" id="reason" name="reason" class="form-control" maxlength="500" placeholder="Например: личные дела">
                        </div>
                        <div class="day-modal__actions">
                            <button type="submit" class="btn btn-outline">Отметить как недоступный</button>
                        </div>
                    </form>
                `;
            }
            if (!modal.open) {
                modal.showModal();
            }
        }
        function closeDayModal() {
            const modal = document.getElementById('dayModal');
            if (modal.open) {
                modal.close();
            }
        }
        function escapeHtml(value) {
            const node = document.createElement('div');
            node.textContent = String(value ?? '');
            return node.innerHTML;
        }
        window.addEventListener('click', function(event) {
            const modal = document.getElementById('dayModal');
            if (event.target === modal) {
                closeDayModal();
            }
        });
    </script>
</body>
</html>
