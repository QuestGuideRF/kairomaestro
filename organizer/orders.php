<?php
require_once __DIR__ . '/../config/config.php';
requireCrmPanel();
$orderObj = new Order();
$statusFilter = trim((string)($_GET['status'] ?? ''));
$query = trim((string)($_GET['q'] ?? ''));
$filters = ['status' => $statusFilter, 'query' => $query];
$orders = $orderObj->getForCrmUser((int)$_SESSION['user_id'], (string)currentRole(), $filters);
$statuses = $orderObj->statuses();
$headerFile = __DIR__ . '/../includes/' . (currentRole() === 'manager' ? 'manager-header.php' : 'organizer-header.php');
?>
<!DOCTYPE html>
<html lang="ru">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Заказы - <?php echo escape(SITE_NAME); ?></title><link rel="stylesheet" href="<?php echo asset('assets/css/style.min.css'); ?>"><?php include_once __DIR__ . '/../includes/favicon-links.php'; ?></head>
<body>
<?php include_once $headerFile; ?>
<main class="events-page crm-orders-page">
<div class="container">
    <header class="page-header"><div><p class="eyebrow"><?php echo currentRole()==='manager' ? 'Просмотр проектов' : 'Проекты агентства'; ?></p><h1><?php echo currentRole()==='manager' ? 'Все заказы' : 'Заказы'; ?></h1><p><?php echo currentRole()==='manager' ? 'Текущий статус, дата и ответственный организатор. Управление проектом доступно организатору.' : 'Новые заказы и проекты, которые находятся у вас в работе.'; ?></p></div><?php if(currentRole()==='manager'): ?><a href="/manager/dashboard.php" class="btn btn-outline">К заявкам</a><?php endif; ?></header>
    <form method="GET" class="filter-form order-search-form">
        <div class="form-group"><label for="orderSearch">Поиск заказа</label><input id="orderSearch" name="q" class="form-control" value="<?php echo escape($query); ?>" placeholder="Номер, клиент, телефон, email или организатор"></div>
        <div class="form-group"><label for="orderStatusFilter">Статус</label><select id="orderStatusFilter" name="status" class="form-control"><option value="">Все статусы</option><?php foreach($statuses as $key=>$label): ?><option value="<?php echo escape($key); ?>" <?php echo $statusFilter===$key?'selected':''; ?>><?php echo escape($label); ?></option><?php endforeach; ?></select></div>
        <button class="btn btn-primary" type="submit">Найти</button><?php if($query!==''||$statusFilter!==''): ?><a class="btn btn-outline" href="/organizer/orders.php">Сбросить</a><?php endif; ?>
    </form>
    <div class="orders-grid crm-order-grid">
    <?php foreach($orders as $order):
        $isPoolPreview=currentRole()==='organizer'&&empty($order['organizer_id']);
        $clientDisplay=trim((string)($order['client_first_name']??'').' '.(string)($order['client_last_name']??''));
        $cardTitle=$order['title'];
        if($isPoolPreview){$cardTitle=($order['event_type']?:'Событие').' #'.(int)$order['id'];$clientDisplay='Контакты откроются после отклика';}
        $organizerName=trim((string)($order['organizer_first_name']??'').' '.(string)($order['organizer_last_name']??''));
    ?>
        <article class="order-card crm-order-card">
            <div class="crm-order-card__top"><span>Заказ №<?php echo (int)$order['id']; ?></span><span class="badge badge-<?php echo escape($order['status']); ?>"><?php echo escape($statuses[$order['status']]??$order['status']); ?></span></div>
            <h2><?php echo escape($cardTitle); ?></h2><p class="order-client-name"><?php echo escape($clientDisplay?:'Клиент не указан'); ?></p>
            <dl class="order-card-facts"><div><dt>Дата</dt><dd><?php echo $order['event_date']?escape(formatRussianDate($order['event_date'],'date_only')):'Уточняется'; ?></dd></div><div><dt>Площадка</dt><dd><?php echo escape($order['venue']?:'Уточняется'); ?></dd></div><div><dt>Организатор</dt><dd><?php echo escape($organizerName?:'Ещё не назначен'); ?></dd></div></dl>
            <a href="/organizer/order.php?id=<?php echo (int)$order['id']; ?>" class="btn <?php echo $isPoolPreview?'btn-primary':'btn-outline'; ?> btn-block"><?php echo $isPoolPreview?'Взять в работу':'Открыть'; ?></a>
        </article>
    <?php endforeach; ?>
    <?php if(!$orders): ?><div class="empty-state"><p>По выбранным условиям заказов нет.</p></div><?php endif; ?>
    </div>
</div>
</main>
<?php include_once __DIR__ . '/../includes/organizer-footer.php'; ?>
</body></html>
