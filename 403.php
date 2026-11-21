<?php require_once __DIR__ . '/config/config.php'; ?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>403 - Доступ запрещён | Азбука Декора</title>
    <?php
    $pageKeywords = '';
    $pageRobots = 'noindex, nofollow';
    include_once __DIR__ . '/includes/public-meta.php';
    ?>
    <link rel="stylesheet" href="<?php echo asset('assets/css/style.min.css'); ?>">
    <style>
        .error-page {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            padding: 2rem;
        }
        .error-content {
            max-width: 500px;
        }
        .error-code {
            font-size: 8rem;
            font-weight: 700;
            color: var(--error-color);
            line-height: 1;
            margin-bottom: 1rem;
        }
        .error-title {
            font-size: 2rem;
            margin-bottom: 1rem;
        }
        .error-description {
            font-size: 1.125rem;
            color: var(--text-light);
            margin-bottom: 2rem;
        }
    </style>
</head>
<body>
    <div class="error-page">
        <div class="error-content">
            <div class="error-code">403</div>
            <h1 class="error-title">Доступ запрещён</h1>
            <p class="error-description">
                У вас нет прав для доступа к этой странице.
            </p>
            <a href="/" class="btn btn-primary btn-lg">Вернуться на главную</a>
        </div>
    </div>
</body>
</html>
