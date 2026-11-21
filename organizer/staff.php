<?php
require_once __DIR__ . '/../config/config.php';
requireCrmPanel(['organizer', 'admin']);
$userObj = new User();
$availabilityObj = new Availability();
$userId = $_SESSION['user_id'];
$specialtyFilter = $_GET['specialty'] ?? '';
$dateFilter = $_GET['date'] ?? '';
$availabilityFilter = $_GET['availability'] ?? '';
$filters = [];
if ($specialtyFilter) {
    $filters['specialty'] = $specialtyFilter;
}
$workers = $userObj->getAllWorkers($filters);
if ($dateFilter) {
    $workers = array_values(array_filter(array_map(function($worker) use ($availabilityObj, $dateFilter) {
        $status = $availabilityObj->isAvailable((int)$worker['id'], $dateFilter);
        $worker['date_status'] = 'unavailable';
        $worker['date_status_label'] = 'недоступен';
        if ($status['available']) {
            $worker['date_status'] = 'free';
            $worker['date_status_label'] = 'свободен';
        } elseif ($status['reason'] === 'busy') {
            $worker['date_status'] = 'busy';
            $worker['date_status_label'] = 'работает';
        }
        $worker['date_status_note'] = $status['event_title'] ?? '';
        return $worker;
    }, $workers), function($worker) use ($availabilityFilter) {
        return !$availabilityFilter || $worker['date_status'] === $availabilityFilter;
    }));
}
$db = Database::getInstance()->getConnection();
$stmt = $db->query("SELECT DISTINCT specialty FROM worker_profiles ORDER BY specialty");
$specialties = $stmt->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Подбор сотрудников - Азбука Декора</title>
    <?php include_once __DIR__ . '/../includes/favicon-links.php'; ?>
    <link rel="stylesheet" href="<?php echo asset('assets/css/style.min.css'); ?>">
</head>
<body>
    <?php include_once __DIR__ . '/../includes/organizer-header.php'; ?>
    <div class="staff-page">
        <div class="container">
            <div class="page-header">
                <div>
                    <p class="eyebrow">Команда</p>
                    <h1>Подбор сотрудников</h1>
                    <p class="page-subtitle">Фильтруйте специалистов по роли, дате и доступности</p>
                </div>
                <div class="page-header-badge">
                    <span class="total-count"><?php echo count($workers); ?></span>
                    <span class="total-label">сотрудников</span>
                </div>
            </div>
            <div class="filter-card">
                <form method="GET" action="" class="filter-form">
                    <div class="filter-group">
                        <label for="specialty" class="filter-label">
                            <span class="icon"><img src="/assets/icons/search.svg" alt="" aria-hidden="true"></span>
                            Фильтр по специальности
                        </label>
                        <select id="specialty" name="specialty" class="filter-select" onchange="this.form.submit()">
                            <option value="">Все специальности</option>
                            <?php foreach ($specialties as $specialty): ?>
                            <option value="<?php echo escape($specialty); ?>" <?php echo $specialtyFilter === $specialty ? 'selected' : ''; ?>>
                                <?php echo escape($specialty); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="date" class="filter-label">Дата заказа</label>
                        <input type="date" id="date" name="date" class="filter-select" value="<?php echo escape($dateFilter); ?>" onchange="this.form.submit()">
                    </div>
                    <div class="filter-group">
                        <label for="availability" class="filter-label">Доступность</label>
                        <select id="availability" name="availability" class="filter-select" onchange="this.form.submit()" <?php echo $dateFilter ? '' : 'disabled'; ?>>
                            <option value="">Любая</option>
                            <option value="free" <?php echo $availabilityFilter === 'free' ? 'selected' : ''; ?>>Свободен</option>
                            <option value="busy" <?php echo $availabilityFilter === 'busy' ? 'selected' : ''; ?>>Работает</option>
                            <option value="unavailable" <?php echo $availabilityFilter === 'unavailable' ? 'selected' : ''; ?>>Недоступен</option>
                        </select>
                    </div>
                    <?php if ($specialtyFilter || $dateFilter || $availabilityFilter): ?>
                    <a href="/organizer/staff.php" class="btn btn-outline btn-sm">Сбросить фильтр</a>
                    <?php endif; ?>
                </form>
            </div>
            <?php if (empty($workers)): ?>
            <div class="empty-state">
                <div class="empty-icon"><img src="/assets/icons/users.svg" alt="" aria-hidden="true"></div>
                <h3>Сотрудники не найдены</h3>
                <p>Попробуйте изменить фильтр или вернитесь позже</p>
            </div>
            <?php else: ?>
            <div class="workers-grid">
                <?php foreach ($workers as $worker): ?>
                <div class="worker-card">
                    <div class="worker-card-header">
                        <div class="worker-avatar">
                            <?php if ($worker['avatar']): ?>
                                <img src="/uploads/avatars/<?php echo escape($worker['avatar']); ?>" alt="Avatar">
                            <?php else: ?>
                                <div class="worker-avatar-placeholder">
                                    <?php echo escape(utf8Substring($worker['first_name'], 0, 1)); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="worker-info">
                            <h3><?php echo escape($worker['first_name'] . ' ' . $worker['last_name']); ?></h3>
                            <span class="worker-specialty"><?php echo escape($worker['specialty']); ?></span>
                        </div>
                    </div>
                    <div class="worker-card-body">
                        <div class="worker-detail">
                            <span class="detail-icon"><img src="/assets/icons/phone.svg" alt="" aria-hidden="true"></span>
                            <a href="tel:<?php echo escape($worker['phone']); ?>" class="detail-link">
                                <?php echo escape($worker['phone']); ?>
                            </a>
                        </div>
                        <div class="worker-detail">
                            <span class="detail-icon"><img src="/assets/icons/wallet.svg" alt="" aria-hidden="true"></span>
                            <span class="detail-text">
                                <?php echo number_format($worker['hourly_rate'], 0, ',', ' '); ?> ₽/час
                            </span>
                        </div>
                        <?php if ($worker['has_car']): ?>
                        <div class="worker-detail">
                            <span class="detail-icon"><img src="/assets/icons/car.svg" alt="" aria-hidden="true"></span>
                            <span class="detail-text">
                                <?php echo escape($worker['car_brand']); ?>
                            </span>
                        </div>
                        <?php endif; ?>
                        <?php if ($dateFilter): ?>
                        <div class="worker-detail availability-<?php echo escape($worker['date_status']); ?>">
                            <span class="detail-icon"><img src="/assets/icons/calendar.svg" alt="" aria-hidden="true"></span>
                            <span class="detail-text">
                                <?php echo escape($worker['date_status_label']); ?>
                                <?php if (!empty($worker['date_status_note'])): ?>
                                    <small><?php echo escape($worker['date_status_note']); ?></small>
                                <?php endif; ?>
                            </span>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="worker-card-footer">
                        <a href="/organizer/worker-profile.php?id=<?php echo $worker['id']; ?>" class="btn btn-primary btn-block">
                            Подробнее
                        </a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php include_once __DIR__ . '/../includes/organizer-footer.php'; ?>
</body>
</html>
