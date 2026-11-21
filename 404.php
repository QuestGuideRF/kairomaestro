<?php require_once __DIR__ . '/config/config.php'; ?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>404 - Страница не найдена | Азбука Декора</title>
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
            color: var(--primary-color);
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
            <div class="error-code">404</div>
            <h1 class="error-title">Страница не найдена</h1>
            <p class="error-description">
                К сожалению, запрашиваемая страница не существует или была перемещена.
            </p>
            <a href="/" class="btn btn-primary btn-lg">Вернуться на главную</a>
        </div>
    </div>
</body>
</html>
