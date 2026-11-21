<?php
require_once __DIR__ . '/config/config.php';
$disablePublicAnalytics = true;
$token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
$reviewObj = new OrderReview();
$review = $token !== '' ? $reviewObj->getByToken($token) : null;
$error = '';
$success = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    if (!$review) {
        $error = 'Ссылка устарела или отзыв уже отправлен.';
    } else {
        $result = $reviewObj->submit(
            (int)$review['id'],
            (int)$review['client_id'],
            $_POST,
            $_FILES['review_files'] ?? []
        );
        $success = (bool)$result['success'];
        $error = $success ? '' : $result['error'];
    }
}
if (!$review && !$success) {
    http_response_code(404);
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Отзыв о событии - Азбука Декора</title>
    <link rel="stylesheet" href="<?php echo asset('assets/css/style.min.css'); ?>">
<?php include_once __DIR__ . '/includes/favicon-links.php'; ?></head>
<body>
<?php include_once __DIR__ . '/includes/public-header.php'; ?>
<main>
    <section class="page-title">
        <div class="container">
            <p class="eyebrow">Обратная связь</p>
            <h1><?php echo $success ? 'Спасибо за ваш отзыв' : 'Расскажите, как прошло событие'; ?></h1>
        </div>
    </section>
    <section class="section">
        <div class="container quiz-shell">
            <?php if ($success): ?>
                <div class="alert alert-success">Отзыв сохранён и передан команде.</div>
            <?php elseif ($review): ?>
                <?php if ($error): ?><div class="alert alert-error"><?php echo escape($error); ?></div><?php endif; ?>
                <form method="POST" enctype="multipart/form-data" class="quiz-form">
                    <input type="hidden" name="token" value="<?php echo escape($token); ?>">
                    <?php echo csrfField(); ?>
                    <div class="rating-grid">
                        <?php foreach (['rating'=>'Общая оценка','service_rating'=>'Сервис','decor_rating'=>'Оформление','team_rating'=>'Команда'] as $key => $label): ?>
                            <div class="form-group">
                                <label for="review-<?php echo escape($key); ?>"><?php echo escape($label); ?></label>
                                <select id="review-<?php echo escape($key); ?>" name="<?php echo escape($key); ?>" class="form-control" required>
                                    <option value="">Выберите</option>
                                    <?php for ($rating = 5; $rating >= 1; $rating--): ?>
                                        <option value="<?php echo $rating; ?>"><?php echo $rating; ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="form-group">
                        <label for="review-text">Ваш отзыв</label>
                        <textarea id="review-text" name="text" class="form-control" rows="6" required></textarea>
                    </div>
                    <div class="form-group">
                        <label for="review-files">Фотографии</label>
                        <input id="review-files" type="file" name="review_files[]" class="form-control" accept="image/jpeg,image/png,image/webp" multiple>
                    </div>
                    <label class="checkbox-row" for="review-publication-consent"><input id="review-publication-consent" type="checkbox" name="publication_consent" value="1"> Разрешаю публикацию отзыва</label>
                    <label class="checkbox-row" for="review-photo-consent"><input id="review-photo-consent" type="checkbox" name="photo_consent" value="1"> Разрешаю использовать приложенные фотографии</label>
                    <div class="form-group">
                        <label for="review-display-name">Как показывать имя</label>
                        <select id="review-display-name" name="display_name_mode" class="form-control">
                            <option value="first_name">Показывать только имя</option>
                            <option value="full_name">Показывать имя и фамилию</option>
                            <option value="hidden">Скрыть имя</option>
                        </select>
                    </div>
                    <button class="btn btn-primary btn-lg" type="submit">Отправить отзыв</button>
                </form>
            <?php else: ?>
                <div class="alert alert-error">Ссылка устарела или отзыв уже отправлен.</div>
            <?php endif; ?>
        </div>
    </section>
</main>
<?php include_once __DIR__ . '/includes/public-footer.php'; ?>
</body>
</html>
