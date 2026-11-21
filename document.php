<?php
require_once __DIR__ . '/config/config.php';
$documentObj = new OrderDocument();
$document = null;
if (!empty($_GET['token'])) {
    $document = $documentObj->getByPublicToken($_GET['token']);
} elseif (!empty($_GET['id']) && isLoggedIn()) {
    $document = $documentObj->getById((int)$_GET['id']);
    if ($document) {
        $order = (new Order())->getById($document['order_id']);
        $role = currentRole();
        $roleMayRead = in_array($role, ['admin', 'organizer', 'client'], true);
        $clientMayRead = $role !== 'client'
            || ((int)$document['client_id'] === (int)$_SESSION['user_id']
                && !in_array($document['status'], ['draft', 'cancelled'], true));
        if (!$roleMayRead
            || !$clientMayRead
            || !(new Order())->canCollaborate($order, (int)$_SESSION['user_id'], $role)) {
            $document = null;
        }
    }
}
if (!$document) {
    http_response_code(404);
    die('Документ не найден или ссылка устарела.');
}
?>
<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title><?php echo escape($document['title']); ?></title><link rel="stylesheet" href="<?php echo asset('assets/css/style.min.css'); ?>"><?php include_once __DIR__ . '/includes/favicon-links.php'; ?></head><body class="document-page"><header class="document-toolbar"><div><strong><?php echo escape($document['document_number']); ?></strong><span>Версия <?php echo (int)$document['version_no']; ?></span></div><button class="btn btn-primary" type="button" onclick="window.print()">Печать / сохранить PDF</button></header><main class="print-document"><h1><?php echo escape($document['title']); ?></h1><div class="document-meta">Создан <?php echo escape(formatRussianDate($document['created_at'], 'date_only')); ?></div><article class="document-content"><?php echo $document['body_html']; ?></article></main></body></html>
