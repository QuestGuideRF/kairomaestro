<?php
require_once __DIR__ . '/config/config.php';
$roles = CandidateApplication::roles();
$experienceOptions = CandidateApplication::experienceOptions();
$error = '';
$sent = isset($_GET['sent']) && $_GET['sent'] === '1';
if (empty($_SESSION['candidate_submission_key'])) {
    $_SESSION['candidate_submission_key'] = bin2hex(random_bytes(32));
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $candidateApplication = new CandidateApplication();
    $result = $candidateApplication->submit(
        $_POST,
        (string)($_POST['submission_key'] ?? '')
    );
    if ($result['success']) {
        unset($_SESSION['candidate_submission_key']);
        redirect('/careers?sent=1');
    }
    $error = $result['error'];
}
$pageTitle = 'Работа флористом и в декоре событий — ' . SITE_NAME;
$pageDescription = 'Оставьте анкету для проектного сотрудничества флористом, монтажником, '
    . 'курьером или помощником на событиях в Москве и Подмосковье.';
$pageCanonical = appUrl('/careers');
$pageImage = appUrl('/assets/images/about/about-team-01.jpg');
$pageImageAlt = 'Команда Азбуки Декора за разработкой концепции события';
$pageImageWidth = 729;
$pageImageHeight = 876;
$breadcrumbs = [
    ['name' => 'Главная', 'url' => appUrl('/')],
    ['name' => 'Работа в команде', 'url' => $pageCanonical],
];
$pageSchemas = [
    PublicSeo::webPageNode(
        $pageCanonical,
        $pageTitle,
        $pageDescription,
        PublicSeo::imageData(
            $pageImage,
            $pageImageAlt,
            $pageImageWidth,
            $pageImageHeight
        )
    ),
    PublicStructuredData::breadcrumbNode($breadcrumbs),
];
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <?php include_once __DIR__ . '/includes/yandex-metrika-head.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php include_once __DIR__ . '/includes/public-meta.php'; ?>
    <title><?php echo escape($pageTitle); ?></title>
    <meta name="description" content="<?php echo escape($pageDescription); ?>">
    <link rel="canonical" href="<?php echo escape($pageCanonical); ?>">
    <?php include_once __DIR__ . '/includes/open-graph.php'; ?>
    <?php include_once __DIR__ . '/includes/structured-data.php'; ?>
    <link rel="stylesheet" href="<?php echo asset('assets/css/style.min.css'); ?>">
</head>
<body class="career-page">
    <?php include_once __DIR__ . '/includes/public-header.php'; ?>
    <main>
        <?php include_once __DIR__ . '/includes/breadcrumbs.php'; ?>
        <section class="career-hero">
            <div class="container career-hero__content">
                <span class="career-hero__icon" aria-hidden="true"></span>
                <p class="eyebrow">Работа в команде</p>
                <h1>Работа и сотрудничество в сфере свадебного декора</h1>
                <p>Мы собираем команду под проекты в Москве и Подмосковье. Расскажите о своём опыте и желаемой роли, чтобы мы могли связаться при появлении подходящей задачи.</p>
            </div>
        </section>
        <section class="section section-soft" aria-labelledby="careerRolesTitle">
            <div class="container">
                <div class="section-heading section-heading--editorial">
                    <div>
                        <p class="eyebrow">Проектное сотрудничество</p>
                        <h2 id="careerRolesTitle">Работа на свадьбах и событиях</h2>
                    </div>
                    <p>Анкета не является откликом на конкретную открытую вакансию. Она помогает заранее познакомиться: мы свяжемся, когда появится проект, подходящий вашей роли, опыту и графику.</p>
                </div>
                <div class="package-grid">
                    <article class="package-card">
                        <p class="eyebrow">Флорист</p>
                        <h3>Работа флористом</h3>
                        <p class="muted">Подготовка букетов и композиций, работа с цветами на площадке и реализация согласованной флористической концепции.</p>
                    </article>
                    <article class="package-card">
                        <p class="eyebrow">Монтажник</p>
                        <h3>Монтаж декораций</h3>
                        <p class="muted">Сборка, установка и демонтаж арок, конструкций и элементов оформления по плану организатора.</p>
                    </article>
                    <article class="package-card">
                        <p class="eyebrow">Курьер</p>
                        <h3>Доставка на мероприятия</h3>
                        <p class="muted">Получение и доставка цветов, материалов и декора между складом и площадкой в согласованное время.</p>
                    </article>
                    <article class="package-card">
                        <p class="eyebrow">Помощник</p>
                        <h3>Помощь на событиях</h3>
                        <p class="muted">Расстановка деталей, подготовка зон и помощь флористам и монтажникам во время подготовки события.</p>
                    </article>
                </div>
            </div>
        </section>
        <section class="section">
            <div class="container career-shell">
                <?php if ($sent): ?>
                    <output class="career-success" aria-live="polite">
                        <span class="eyebrow">Заявка отправлена</span>
                        <strong class="career-success__title">Спасибо за знакомство</strong>
                        <span class="career-success__copy">Администратор получил вашу заявку. Мы свяжемся по указанному контакту, когда подберём подходящий формат сотрудничества.</span>
                        <a class="btn btn-outline" href="/">Вернуться на главную</a>
                    </output>
                <?php else: ?>
                    <div class="career-form-heading">
                        <div>
                            <p class="eyebrow">Короткая анкета</p>
                            <h2>Оставить анкету для сотрудничества</h2>
                        </div>
                        <div>
                            <span id="careerProgressLabel">Шаг 1 из 5</span>
                            <div class="career-progress" aria-hidden="true"><span id="careerProgress"></span></div>
                        </div>
                    </div>
                    <?php if ($error): ?><div class="alert alert-error" role="alert"><?php echo escape($error); ?></div><?php endif; ?>
                    <form id="careerForm" class="career-form ym-hide-content" method="POST" novalidate>
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="submission_key" value="<?php echo escape($_SESSION['candidate_submission_key']); ?>">
                        <label class="sr-only" for="candidateWebsite" aria-hidden="true">Не заполняйте это поле</label>
                        <input class="career-honeypot" id="candidateWebsite" type="text" name="website" tabindex="-1" autocomplete="off" aria-hidden="true">
                        <fieldset class="career-step active">
                            <legend>Контакты</legend>
                            <p>Оставьте хотя бы один удобный способ связи.</p>
                            <div class="form-group">
                                <label for="candidateFullName">ФИО</label>
                                <input class="form-control" id="candidateFullName" name="full_name" type="text" maxlength="190" autocomplete="name" required value="<?php echo escape($_POST['full_name'] ?? ''); ?>">
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="candidatePhone">Телефон</label>
                                    <input class="form-control" id="candidatePhone" name="phone" type="tel" maxlength="32" autocomplete="tel" value="<?php echo escape($_POST['phone'] ?? ''); ?>">
                                </div>
                                <div class="form-group">
                                    <label for="candidateEmail">Электронная почта</label>
                                    <input class="form-control" id="candidateEmail" name="email" type="email" maxlength="190" autocomplete="email" value="<?php echo escape($_POST['email'] ?? ''); ?>">
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="candidateTelegram">Имя пользователя в Telegram</label>
                                <input class="form-control" id="candidateTelegram" name="telegram" type="text" maxlength="120" placeholder="@username" value="<?php echo escape($_POST['telegram'] ?? ''); ?>">
                            </div>
                        </fieldset>
                        <fieldset class="career-step">
                            <legend>Кем хотите работать</legend>
                            <div class="career-choice-grid">
                                <?php foreach ($roles as $value => $label): ?>
                                    <label class="career-choice">
                                        <input type="radio" name="desired_role" value="<?php echo escape($value); ?>" required <?php echo ($_POST['desired_role'] ?? '') === $value ? 'checked' : ''; ?>>
                                        <span><?php echo escape($label); ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </fieldset>
                        <fieldset class="career-step">
                            <legend>Опыт работы</legend>
                            <div class="career-choice-grid career-choice-grid--two">
                                <?php foreach ($experienceOptions as $value => $label): ?>
                                    <label class="career-choice">
                                        <input type="radio" name="experience" value="<?php echo escape($value); ?>" required <?php echo ($_POST['experience'] ?? '') === $value ? 'checked' : ''; ?>>
                                        <span><?php echo escape($label); ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </fieldset>
                        <fieldset class="career-step">
                            <legend>Желаемая оплата</legend>
                            <p>Укажите ориентир в рублях за проект или смену. Детали формата обсудим лично.</p>
                            <div class="form-group">
                                <label for="candidateSalary">Сумма, ₽</label>
                                <input class="form-control" id="candidateSalary" name="desired_salary" type="number" min="0" max="100000000" step="500" inputmode="numeric" required value="<?php echo escape($_POST['desired_salary'] ?? ''); ?>">
                            </div>
                        </fieldset>
                        <fieldset class="career-step">
                            <legend>Дополнительная информация</legend>
                            <div class="form-group">
                                <label for="candidatePortfolio">Ссылка на портфолио</label>
                                <input class="form-control" id="candidatePortfolio" name="portfolio_url" type="url" maxlength="500" placeholder="https://..." value="<?php echo escape($_POST['portfolio_url'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label for="candidateSchedule">Удобный график</label>
                                <textarea class="form-control" id="candidateSchedule" name="preferred_schedule" rows="3" maxlength="500"><?php echo escape($_POST['preferred_schedule'] ?? ''); ?></textarea>
                            </div>
                            <div class="form-group">
                                <label for="candidateComment">Комментарий</label>
                                <textarea class="form-control" id="candidateComment" name="comment" rows="5" maxlength="3000"><?php echo escape($_POST['comment'] ?? ''); ?></textarea>
                            </div>
                            <label class="career-consent">
                                <input type="checkbox" name="privacy_consent" value="1" required <?php echo !empty($_POST['privacy_consent']) ? 'checked' : ''; ?>>
                                <span>Согласен на обработку данных для рассмотрения заявки в соответствии с <a href="/privacy" target="_blank" rel="noopener">политикой обработки данных</a>.</span>
                            </label>
                        </fieldset>
                        <div class="career-actions">
                            <button class="btn btn-outline" id="careerPrev" type="button">Назад</button>
                            <button class="btn btn-primary" id="careerNext" type="button">Далее</button>
                            <button class="btn btn-primary" id="careerSubmit" type="submit">Отправить заявку</button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </section>
    </main>
    <?php include_once __DIR__ . '/includes/public-footer.php'; ?>
    <script src="<?php echo asset('assets/js/careers.js'); ?>" defer></script>
</body>
</html>
