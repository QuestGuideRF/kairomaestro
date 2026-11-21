<?php
require_once __DIR__ . '/config/config.php';
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$delivered = $id ? (new SecureFile())->deliverPublishedReviewImage((int)$id) : false;
if (!$delivered) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store');
    echo 'Изображение не найдено';
}
exit;
