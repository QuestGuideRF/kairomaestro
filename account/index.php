<?php
require_once __DIR__ . '/../config/config.php';
requireClientPanel();
$orderObj = new Order();
$leadObj = new Lead();
$chatObj = new Chat();
$orders = $orderObj->getForClient($_SESSION['user_id']);
$leads = $leadObj->getForClient($_SESSION['user_id']);
$statuses = $orderObj->statuses();
$leadStatuses = $leadObj->statuses();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Личный кабинет - Азбука Декора</title>
    <meta name="robots" content="noindex">
    <link rel="stylesheet" href="<?php echo asset('assets/css/style.min.css'); ?>">
<?php include_once __DIR__ . '/../includes/favicon-links.php'; ?></head>
<body>
<?php include_once __DIR__ . '/../includes/client-header.php'; ?>
<main class="dashboard">
    <div class="container">
        <div class="dashboard-header">
            <div>
                <p class="eyebrow">Личный кабинет</p>
                <h1>Ваши заявки и заказы</h1>
            </div>
            <a href="/quiz" class="btn btn-primary">Новая заявка</a>
        </div>
        <section class="panel-section">
            <div class="section-header">
                <h2>Заказы</h2>
            </div>
            <?php if (!$orders): ?>
                <div class="empty-state"><p>Заказы появятся после обработки заявки менеджером.</p></div>
            <?php else: ?>
                <div class="orders-grid">
                    <?php foreach ($orders as $order): ?>
                        <?php
                        $locationDisplay = $order['venue'] ?: $order['address'];
                        $locationDisplay = $locationDisplay ?: 'Локация уточняется';
                        ?>
                        <article class="order-card">
                            <div>
                                <span class="badge badge-<?php echo escape($order['status']); ?>"><?php echo escape($statuses[$order['status']] ?? $order['status']); ?></span>
                                <h3><?php echo escape($order['title']); ?></h3>
                                <p><?php echo $order['event_date'] ? formatRussianDate($order['event_date'], 'date_only') : 'Дата уточняется'; ?></p>
                                <p><?php echo escape($locationDisplay); ?></p>
                            </div>
                            <a href="/account/order.php?id=<?php echo (int)$order['id']; ?>" class="btn btn-outline btn-block">Открыть заказ</a>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
        <section class="panel-section">
            <div class="section-header">
                <h2>Заявки</h2>
            </div>
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Дата</th><th>Тип</th><th>Статус</th><th>Следующий шаг</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($leads as $lead): ?>
                        <?php $leadChat = $chatObj->getLeadChat((int)$lead['id']); ?>
                        <tr>
                            <td><?php echo formatRussianDate($lead['created_at'], 'date_only'); ?></td>
                            <td><?php echo escape($lead['event_type']); ?></td>
                            <td><?php echo escape($leadStatuses[$lead['workflow_status']] ?? $lead['workflow_status']); ?></td>
                            <td><?php echo $lead['workflow_status'] === 'converted' ? 'Проект ведётся как заказ' : ($lead['manager_id'] ? 'Обсуждение с менеджером' : 'Менеджер изучает заявку'); ?></td>
                            <td>
                                <a href="/account/lead.php?id=<?php echo (int)$lead['id']; ?>" class="btn btn-sm btn-outline">Открыть заявку</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$leads): ?>
                        <tr><td colspan="5">Заявок пока нет.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</main>
<?php include_once __DIR__ . '/../includes/client-footer.php'; ?>
</body>
</html>
