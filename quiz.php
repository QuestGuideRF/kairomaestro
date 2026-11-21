<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/quiz-components.php';

$error = '';
$minEventDate = date('Y-m-d', strtotime('+7 days'));
$utmKeys = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'];
foreach ($utmKeys as $utmKey) {
    if (!empty($_GET[$utmKey]) && empty($_SESSION['quiz_' . $utmKey])) {
        $_SESSION['quiz_' . $utmKey] = substr(trim((string)$_GET[$utmKey]), 0, 190);
    }
}
if (empty($_SESSION['quiz_submission_key'])) {
    $_SESSION['quiz_submission_key'] = bin2hex(random_bytes(32));
}

$draftToken = trim((string)($_GET['draft'] ?? $_POST['draft_token'] ?? ''));
$draftData = [];
if ($draftToken !== '') {
    $draftData = (new QuizDraft())->load($draftToken) ?: [];
}
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$formData = $requestMethod === 'POST' ? $_POST : $draftData;
$quizValue = static fn(string $key, $default = '') => $formData[$key] ?? $default;

if ($requestMethod === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        http_response_code(419);
        $error = 'Сессия устарела. Обновите страницу и попробуйте снова.';
    } else {
        $submissionData = QuizBrief::normalizeSubmission($_POST);
        $contactMethod = (string)($submissionData['contact_method'] ?? 'site_chat');
        $phone = trim((string)($submissionData['phone'] ?? ''));
        $email = trim((string)($submissionData['email'] ?? ''));
        $telegram = trim((string)($submissionData['telegram_username'] ?? ''));
        $eventDate = trim((string)($submissionData['event_date'] ?? ''));
        $guests = trim((string)($submissionData['guests_count'] ?? ''));
        $guestsRange = trim((string)($submissionData['guests_range'] ?? ''));
        $hasAnyContact = $phone !== '' || $email !== '' || $telegram !== '';
        $userObj = new User();

        if (trim((string)($submissionData['client_name'] ?? '')) === '') {
            $error = 'Укажите имя';
        } elseif (!$hasAnyContact) {
            $error = 'Укажите телефон, электронную почту или имя пользователя в Telegram';
        } elseif (in_array($contactMethod, ['whatsapp', 'call'], true) && $phone === '') {
            $error = 'Для выбранного способа связи нужен номер телефона';
        } elseif ($contactMethod === 'telegram' && $telegram === '' && $phone === '') {
            $error = 'Для Telegram укажите имя пользователя или телефон';
        } elseif ($contactMethod === 'email' && $email === '') {
            $error = 'Для связи по электронной почте укажите её адрес';
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Проверьте адрес электронной почты';
        } elseif (trim((string)($submissionData['event_type'] ?? '')) === '') {
            $error = 'Выберите тип мероприятия';
        } elseif ($eventDate === '') {
            $error = 'Укажите дату мероприятия';
        } elseif ($eventDate < $minEventDate) {
            $error = 'Дата мероприятия должна быть не раньше ' . formatRussianDate($minEventDate, 'date_only');
        } elseif ($guests === '' && $guestsRange === '') {
            $error = 'Укажите количество или диапазон гостей';
        } elseif (empty($submissionData['event_format'])) {
            $error = 'Выберите формат мероприятия';
        } elseif (empty($submissionData['services_needed'])) {
            $error = 'Выберите хотя бы одну нужную услугу';
        } elseif (empty($submissionData['privacy_consent'])) {
            $error = 'Для отправки заявки подтвердите согласие на обработку данных';
        } else {
            $existingByPhone = $phone !== '' ? $userObj->findByPhoneOrEmail($phone, null) : null;
            $existingByEmail = $email !== '' ? $userObj->findByPhoneOrEmail(null, $email) : null;
            $existingByTelegram = $telegram !== '' ? $userObj->findByTelegramUsername($telegram) : null;
            $existingIds = array_values(array_unique(array_map(
                static fn($user) => (int)$user['id'],
                array_filter([$existingByPhone, $existingByEmail, $existingByTelegram])
            )));
            if (count($existingIds) > 1) {
                $error = 'Указанные контакты относятся к разным аккаунтам. Проверьте данные.';
            }
            $existingUser = $existingByPhone ?: $existingByEmail ?: $existingByTelegram;
            if ($existingUser) {
                if (($existingUser['role'] ?? '') !== 'client') {
                    $error = 'Эти контакты принадлежат служебному аккаунту. Используйте другие контактные данные.';
                } elseif (!isLoggedIn() || (int)($_SESSION['user_id'] ?? 0) !== (int)$existingUser['id']) {
                    $error = 'По этим контактам уже есть кабинет. Войдите в него перед отправкой новой заявки.';
                }
            }
        }

        if ($error === '') {
            $result = (new Lead())->createFromQuiz($submissionData, $_FILES);
            if ($result['success']) {
                $_SESSION['last_lead_id'] = $result['lead_id'];
                $_SESSION['last_chat_id'] = $result['chat_id'] ?? null;
                $_SESSION['metrika_quiz_success_lead_id'] = (int)$result['lead_id'];
                $isNewClient = empty($result['client_existing']) && empty($result['duplicate']);
                $user = $userObj->getUserById($result['client_id']);
                if ($isNewClient && $user) {
                    (new LoginSecurity())->completeLogin($user);
                    $passwordSetupToken = (new PasswordSetup())->issue((int)$user['id']);
                    if ($passwordSetupToken !== null) {
                        $_SESSION['password_setup_url'] = '/set-password?token=' . rawurlencode($passwordSetupToken);
                    }
                }
                (new QuizDraft())->markSubmitted($_POST['draft_token'] ?? '');
                unset($_SESSION['quiz_submission_key']);
                foreach ($utmKeys as $utmKey) {
                    unset($_SESSION['quiz_' . $utmKey]);
                }
                $completionPath = '/thanks.php?lead=' . (int)$result['lead_id'];
                if ($isNewClient && empty($user['telegram_user_id'])) {
                    $_SESSION['telegram_confirmation_next'] = $completionPath;
                    redirect('/telegram-confirm.php');
                }
                redirect($completionPath);
            }
            $error = (string)($result['error'] ?? 'Не удалось сохранить заявку. Повторите попытку.');
        }
    }
}

$optionSets = QuizBrief::optionSets();
$visualAssets = QuizBrief::visualAssets();
$styleDescriptions = [
    'classic' => 'Светлые оттенки, симметрия и элегантные детали',
    'minimal' => 'Чистые линии, пространство и спокойная палитра',
    'romantic' => 'Воздушные ткани, свечи и нежная флористика',
    'garden' => 'Много зелени и ощущение живого сада',
    'italian' => 'Тёплый свет, длинные столы, фрукты и свечи',
    'colorful' => 'Акцентные цвета и необычные формы',
    'editorial' => 'Графичность, выразительные формы и смелые детали',
    'estate' => 'Усадебная архитектура и спокойная классика',
    'boho' => 'Природные фактуры, свободные формы и тёплые оттенки',
    'art' => 'Скульптурные композиции и индивидуальный художественный образ',
    'unsure' => 'Мы поможем найти направление по вашим ответам',
];
$floristryDescriptions = [
    'airy' => 'Свободные композиции и пространство между цветами',
    'lush' => 'Плотные выразительные композиции с большим объёмом',
    'greenery' => 'Главный акцент на растения, ветви и зелень',
    'minimal' => 'Небольшое число выразительных растений',
    'meadow' => 'Живая естественная композиция как цветущий луг',
    'mono' => 'Один вид цветка с чистым выразительным ритмом',
    'colorful' => 'Насыщенные цвета и контрастные сочетания',
    'pastel' => 'Нежные оттенки и мягкие переходы',
    'unsure' => 'Подберём флористику после знакомства с площадкой',
];
$serviceDescriptions = [
    'организация под ключ' => 'Концепция, площадка, команда, тайминг и подготовка',
    'декор' => 'Зал, столы, фотозоны, конструкции и детали пространства',
    'флористика' => 'Букет, композиции и флористические инсталляции',
    'координация' => 'Подрядчики, тайминг и спокойное проведение дня',
    'выездная церемония' => 'Зона церемонии и визуальная атмосфера момента',
    'монтаж' => 'Логистика, сборка и аккуратный демонтаж',
    'другое' => 'Опишите задачу, и мы предложим подходящий состав работ',
];
$eventDescriptions = [
    'Свадьба' => 'Полный свадебный день',
    'Камерная свадьба' => 'Небольшой круг близких',
    'Выездная церемония' => 'Отдельный сценарий церемонии',
    'День рождения' => 'Праздник с индивидуальной концепцией',
    'Юбилей' => 'Торжественный семейный вечер',
    'Корпоратив' => 'Событие для команды или партнёров',
    'Частный ужин' => 'Камерная сервировка и атмосфера',
    'Другое' => 'Расскажите о формате своими словами',
];
$volumeAssets = [
    'minimal' => 'floristry/minimal',
    'moderate' => 'floristry/airy',
    'lots' => 'floristry/lush',
    'maximal' => 'floristry/colorful',
];

$pageTitle = 'Рассчитать свадьбу или событие в Москве — ' . SITE_NAME;
$pageDescription = 'Соберите визуальный бриф свадьбы или события: формат, площадка, стиль, палитра, флористика, зоны оформления, услуги и бюджет.';
$pageCanonical = appUrl('/quiz');
$pageImage = appUrl('/assets/images/hero/home-hero-light-1440.webp');
$pageImageAlt = 'Свадебная церемония с цветочным оформлением';
$pageImageWidth = 1440;
$pageImageHeight = 810;
$breadcrumbs = [
    ['name' => 'Главная', 'url' => appUrl('/')],
    ['name' => 'Рассчитать событие', 'url' => $pageCanonical],
];
$pageSchemas = [
    PublicSeo::webPageNode(
        $pageCanonical,
        $pageTitle,
        $pageDescription,
        PublicSeo::imageData($pageImage, $pageImageAlt, $pageImageWidth, $pageImageHeight)
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
    <link rel="stylesheet" href="<?php echo asset('assets/css/quiz.min.css'); ?>">
    <script src="<?php echo asset('assets/js/quiz.min.js'); ?>" defer></script>
</head>
<body class="quiz-page">
<?php include_once __DIR__ . '/includes/public-header.php'; ?>
<main>
    <?php include_once __DIR__ . '/includes/breadcrumbs.php'; ?>
    <section class="quiz-intro">
        <div class="container quiz-intro__inner">
            <div>
                <p class="eyebrow">Визуальный бриф</p>
                <h1>Соберите образ вашего события</h1>
            </div>
            <div class="quiz-intro__aside">
                <p>Выбирайте фотографии и короткие варианты ответа. За 3-6 минут мы поймём формат, настроение и приоритеты до первого разговора.</p>
                <p class="quiz-intro__alternative">Не хотите заполнять подробный бриф?</p>
                <div class="quiz-intro__actions">
                    <a class="btn btn-outline btn-sm" href="/short-request">Оставить короткую заявку</a>
                    <?php $quizTelegramBot = preg_replace('/[^A-Za-z0-9_]/', '', ltrim((string)env('TELEGRAM_BOT_USERNAME', ''), '@')); ?>
                    <?php if ($quizTelegramBot): ?><a class="quiet-link" href="https://t.me/<?php echo escape($quizTelegramBot); ?>" target="_blank" rel="noopener">Написать в Telegram</a><?php endif; ?>
                    <?php if (PUBLIC_PHONE): ?><a class="quiet-link" href="tel:<?php echo escape(preg_replace('/[^\d+]/', '', PUBLIC_PHONE)); ?>">Позвонить</a><?php endif; ?>
                </div>
            </div>
        </div>
    </section>
    <section class="quiz-section" aria-labelledby="quiz-form-title">
        <div class="container">
            <h2 class="sr-only" id="quiz-form-title">Визуальный бриф события</h2>
            <?php if ($error): ?>
                <div class="alert alert-error quiz-server-error" role="alert"><?php echo escape($error); ?></div>
            <?php endif; ?>
            <noscript><div class="alert alert-error">Для пошагового брифа необходимо включить JavaScript.</div></noscript>
            <form method="POST" action="/quiz" enctype="multipart/form-data" class="quiz-form ym-hide-content" id="quizForm" data-quiz-version="visual" data-draft-token="<?php echo escape($draftToken); ?>" novalidate>
                <?php echo csrfField(); ?>
                <input type="hidden" name="submission_key" value="<?php echo escape($_SESSION['quiz_submission_key']); ?>">
                <input type="hidden" name="draft_token" id="draftToken" value="<?php echo escape($draftToken); ?>">
                <input type="hidden" name="quiz_current_step" value="<?php echo max(0, min(6, (int)$quizValue('quiz_current_step', 0))); ?>">
                <input type="hidden" name="referer_url" value="<?php echo escape($_SERVER['HTTP_REFERER'] ?? ''); ?>">
                <?php foreach ($utmKeys as $utmKey): ?><input type="hidden" name="<?php echo escape($utmKey); ?>" value="<?php echo escape($_SESSION['quiz_' . $utmKey] ?? ''); ?>"><?php endforeach; ?>

                <div class="quiz-layout">
                    <aside class="quiz-progress-panel" aria-label="Этапы брифа">
                        <div class="quiz-progress-panel__top">
                            <span id="quizProgressCounter">Шаг 1 из 7</span>
                            <strong id="quizProgressTitle">Событие</strong>
                        </div>
                        <div class="quiz-progress" aria-hidden="true"><span id="quizProgress"></span></div>
                        <ol class="quiz-stage-list">
                            <?php foreach (['Событие', 'Площадка', 'Стиль', 'Цветы', 'Зоны', 'Бюджет', 'Контакты'] as $index => $stage): ?>
                                <li data-quiz-stage="<?php echo $index; ?>"<?php echo $index === 0 ? ' class="is-active" aria-current="step"' : ''; ?>><span><?php echo $index + 1; ?></span><strong><?php echo escape($stage); ?></strong></li>
                            <?php endforeach; ?>
                        </ol>
                        <p class="quiz-draft-status" id="quizDraftStatus" aria-live="polite"></p>
                    </aside>

                    <div class="quiz-workspace" id="quizWorkspace">
                        <div class="quiz-error-summary" id="quizErrorSummary" role="alert" aria-live="assertive" hidden></div>

                        <section class="quiz-step active" data-step-title="Событие" aria-labelledby="quiz-step-1-title">
                            <header class="quiz-step__header">
                                <p class="eyebrow">01 · Основные параметры</p>
                                <h2 id="quiz-step-1-title">Расскажите о событии</h2>
                                <p>Сначала соберём основу: формат, дата и число гостей.</p>
                            </header>
                            <fieldset class="quiz-question" data-required-group="event_type_choice">
                                <legend>Какое событие вы планируете?</legend>
                                <div class="quiz-choice-grid quiz-choice-grid--four">
                                    <?php quizBriefChoiceOptions('event_type_choice', $optionSets['event_type_choice'], $formData, 'radio', $eventDescriptions); ?>
                                </div>
                            </fieldset>
                            <div class="form-group quiz-conditional" data-condition="event-other" hidden>
                                <label for="event_type_other">Расскажите, что это за событие</label>
                                <input type="text" id="event_type_other" name="event_type_other" class="form-control" maxlength="120" value="<?php echo escape($quizValue('event_type_other')); ?>" placeholder="Например, семейный праздник или презентация">
                            </div>
                            <div class="quiz-question-grid">
                                <div class="form-group">
                                    <label for="event_date">Дата мероприятия *</label>
                                    <input type="date" id="event_date" name="event_date" class="form-control" value="<?php echo escape($quizValue('event_date')); ?>" min="<?php echo escape($minEventDate); ?>" required>
                                </div>
                                <fieldset class="quiz-question quiz-question--compact" data-required-group="event_time_status">
                                    <legend>Время начала</legend>
                                    <div class="quiz-segmented">
                                        <?php quizBriefChipOptions('event_time_status', $optionSets['event_time_status'], $formData, 'radio'); ?>
                                    </div>
                                </fieldset>
                            </div>
                            <div class="form-group quiz-conditional" data-condition="event-time-known" hidden>
                                <label for="event_time">Во сколько начинается мероприятие?</label>
                                <input type="time" id="event_time" name="event_time" class="form-control" value="<?php echo escape($quizValue('event_time')); ?>">
                            </div>
                            <fieldset class="quiz-question" data-required-group="guests_range">
                                <legend>Сколько гостей вы ожидаете?</legend>
                                <div class="quiz-choice-grid quiz-choice-grid--three">
                                    <?php quizBriefChoiceOptions('guests_range', $optionSets['guests_range'], $formData); ?>
                                </div>
                                <div class="form-group quiz-inline-detail">
                                    <label for="guests_count">Точное число, если уже известно <span>Необязательно</span></label>
                                    <input type="number" id="guests_count" name="guests_count" class="form-control" value="<?php echo escape($quizValue('guests_count')); ?>" min="1" max="10000" inputmode="numeric" placeholder="Например, 68">
                                </div>
                            </fieldset>
                            <fieldset class="quiz-question" data-required-group="event_format">
                                <legend>Каким вы представляете формат?</legend>
                                <div class="quiz-choice-grid quiz-choice-grid--three">
                                    <?php quizBriefChoiceOptions('event_format', $optionSets['event_format'], $formData); ?>
                                </div>
                            </fieldset>
                        </section>

                        <section class="quiz-step" data-step-title="Площадка" aria-labelledby="quiz-step-2-title" hidden>
                            <header class="quiz-step__header">
                                <p class="eyebrow">02 · Состав работ и пространство</p>
                                <h2 id="quiz-step-2-title">Что нужно сделать и где</h2>
                                <p>Выберем объём помощи и поймём, что уже известно о площадке.</p>
                            </header>
                            <fieldset class="quiz-question" data-required-group="services_needed">
                                <legend>Какие услуги вам нужны?</legend>
                                <div class="quiz-choice-grid quiz-choice-grid--two">
                                    <?php quizBriefChoiceOptions('services_needed', $optionSets['services_needed'], $formData, 'checkbox', $serviceDescriptions); ?>
                                </div>
                            </fieldset>
                            <fieldset class="quiz-question" data-required-group="venue_status">
                                <legend>Площадка уже выбрана?</legend>
                                <div class="quiz-choice-grid quiz-choice-grid--two">
                                    <?php quizBriefChoiceOptions('venue_status', $optionSets['venue_status'], $formData); ?>
                                </div>
                            </fieldset>
                            <div class="quiz-conditional quiz-fields-band" data-condition="venue-booked" hidden>
                                <div class="form-row">
                                    <div class="form-group"><label for="venue_name">Название площадки</label><input id="venue_name" name="venue_name" class="form-control" maxlength="190" value="<?php echo escape($quizValue('venue_name')); ?>"></div>
                                    <div class="form-group"><label for="venue_address">Адрес</label><input id="venue_address" name="venue_address" class="form-control" maxlength="255" value="<?php echo escape($quizValue('venue_address')); ?>"></div>
                                </div>
                                <div class="form-group"><label for="venue_url">Ссылка на площадку <span class="quiz-optional">Необязательно</span></label><input type="url" id="venue_url" name="venue_url" class="form-control" maxlength="500" value="<?php echo escape($quizValue('venue_url')); ?>" placeholder="https://..."></div>
                            </div>
                            <div class="form-group quiz-conditional" data-condition="venue-options" hidden>
                                <label for="venue_options">Какие варианты рассматриваете?</label>
                                <textarea id="venue_options" name="venue_options" class="form-control" rows="3" maxlength="1200" placeholder="Названия площадок или ссылки"><?php echo escape($quizValue('venue_options')); ?></textarea>
                            </div>
                            <div class="form-group">
                                <label for="location_city">Город или район</label>
                                <input id="location_city" name="location_city" class="form-control" maxlength="120" value="<?php echo escape($quizValue('location_city', 'Москва')); ?>" placeholder="Москва, Подмосковье или другой город">
                            </div>
                            <fieldset class="quiz-question">
                                <legend>Какой тип пространства рассматриваете? <span class="quiz-optional">Необязательно</span></legend>
                                <div class="quiz-visual-grid quiz-visual-grid--compact">
                                    <?php quizBriefVisualOptions('venue_type', $optionSets['venue_type'], $formData, $visualAssets['venue_type'], [], 'radio'); ?>
                                </div>
                            </fieldset>
                            <div class="quiz-conditional" data-condition="wedding-event" hidden>
                                <fieldset class="quiz-question" data-required-group="ceremony_planned">
                                    <legend>Планируется выездная церемония?</legend>
                                    <div class="quiz-choice-grid quiz-choice-grid--three"><?php quizBriefChoiceOptions('ceremony_planned', $optionSets['ceremony_planned'], $formData); ?></div>
                                </fieldset>
                                <div class="quiz-conditional" data-condition="ceremony-yes" hidden>
                                    <fieldset class="quiz-question">
                                        <legend>Где она будет?</legend>
                                        <div class="quiz-choice-grid quiz-choice-grid--three"><?php quizBriefChoiceOptions('ceremony_location', $optionSets['ceremony_location'], $formData); ?></div>
                                    </fieldset>
                                    <fieldset class="quiz-question">
                                        <legend>Какое оформление церемонии вам ближе?</legend>
                                        <div class="quiz-visual-grid quiz-visual-grid--three">
                                            <?php quizBriefVisualOptions('ceremony_style', $optionSets['ceremony_style'], $formData, $visualAssets['ceremony_style'], [], 'radio'); ?>
                                        </div>
                                    </fieldset>
                                </div>
                            </div>
                        </section>

                        <section class="quiz-step" data-step-title="Стиль" aria-labelledby="quiz-step-3-title" hidden>
                            <header class="quiz-step__header">
                                <p class="eyebrow">03 · Визуальное направление</p>
                                <h2 id="quiz-step-3-title">Какую атмосферу вы хотите создать?</h2>
                                <p>Не обязательно знать название стиля. Выберите до трёх фотографий, которые вам ближе.</p>
                            </header>
                            <fieldset class="quiz-question" data-required-group="visual_styles" data-max-selected="3">
                                <legend>Визуальное направление</legend>
                                <div class="quiz-visual-grid quiz-visual-grid--three">
                                    <?php quizBriefVisualOptions('visual_styles', $optionSets['visual_styles'], $formData, $visualAssets['visual_styles'], $styleDescriptions); ?>
                                </div>
                                <p class="quiz-selection-hint">Можно выбрать до 3 вариантов</p>
                            </fieldset>
                            <fieldset class="quiz-question" data-max-selected="4">
                                <legend>Какими словами вы бы описали событие?</legend>
                                <div class="quiz-chip-grid"><?php quizBriefChipOptions('mood', $optionSets['mood'], $formData); ?></div>
                                <p class="quiz-selection-hint">До 4 вариантов</p>
                            </fieldset>
                            <fieldset class="quiz-question" data-max-selected="5">
                                <legend>Что вам особенно нравится в оформлении?</legend>
                                <div class="quiz-visual-grid quiz-visual-grid--three"><?php quizBriefVisualOptions('decor_preferences', $optionSets['decor_preferences'], $formData, $visualAssets['decor_preferences']); ?></div>
                            </fieldset>
                        </section>

                        <section class="quiz-step" data-step-title="Цветы" aria-labelledby="quiz-step-4-title" hidden>
                            <header class="quiz-step__header">
                                <p class="eyebrow">04 · Палитра и флористика</p>
                                <h2 id="quiz-step-4-title">Цвет, цветы и свет</h2>
                                <p>Палитра и объём флористики сильно влияют и на ощущение события, и на бюджет.</p>
                            </header>
                            <fieldset class="quiz-question" data-required-group="palette">
                                <legend>Какие оттенки вам ближе?</legend>
                                <div class="quiz-palette-grid"><?php quizBriefPaletteOptions($optionSets['palette'], $formData); ?></div>
                            </fieldset>
                            <div class="form-row">
                                <div class="form-group"><label for="custom_colors">Есть конкретные цвета? <span class="quiz-optional">Необязательно</span></label><input id="custom_colors" name="custom_colors" class="form-control" maxlength="255" value="<?php echo escape($quizValue('custom_colors')); ?>" placeholder="Молочный, оливковый и винный"></div>
                                <div class="form-group"><label for="excluded_colors">Каких цветов точно не должно быть? <span class="quiz-optional">Необязательно</span></label><input id="excluded_colors" name="excluded_colors" class="form-control" maxlength="800" value="<?php echo escape($quizValue('excluded_colors')); ?>" placeholder="Например, ярко-розового и золота"></div>
                            </div>
                            <div class="quiz-conditional" data-condition="floral-service" hidden>
                                <fieldset class="quiz-question" data-max-selected="3">
                                    <legend>Какая флористика вам ближе?</legend>
                                    <div class="quiz-visual-grid quiz-visual-grid--three">
                                        <?php quizBriefVisualOptions('floristry_styles', $optionSets['floristry_styles'], $formData, $visualAssets['floristry_styles'], $floristryDescriptions); ?>
                                    </div>
                                    <p class="quiz-selection-hint">Можно выбрать до 3 вариантов</p>
                                </fieldset>
                                <fieldset class="quiz-question">
                                    <legend>Насколько много цветов вы представляете?</legend>
                                    <div class="quiz-visual-grid quiz-visual-grid--four">
                                        <?php quizBriefVisualOptions('floristry_volume', $optionSets['floristry_volume'], $formData, $volumeAssets, [], 'radio'); ?>
                                    </div>
                                </fieldset>
                                <fieldset class="quiz-question" data-max-selected="3">
                                    <legend>Хотите использовать свечи и декоративный свет?</legend>
                                    <div class="quiz-visual-grid quiz-visual-grid--three"><?php quizBriefVisualOptions('lighting_preferences', $optionSets['lighting_preferences'], $formData, $visualAssets['lighting_preferences']); ?></div>
                                </fieldset>
                            </div>
                        </section>

                        <section class="quiz-step" data-step-title="Зоны" aria-labelledby="quiz-step-5-title" hidden>
                            <header class="quiz-step__header">
                                <p class="eyebrow">05 · Пространство</p>
                                <h2 id="quiz-step-5-title">Что нужно оформить?</h2>
                                <p>Выберите зоны. Дополнительные вопросы появятся только для нужных элементов.</p>
                            </header>
                            <fieldset class="quiz-question">
                                <legend>Зоны оформления</legend>
                                <div class="quiz-chip-grid quiz-chip-grid--zones"><?php quizBriefChipOptions('decor_zones', $optionSets['decor_zones'], $formData); ?></div>
                            </fieldset>
                            <div class="quiz-conditional quiz-detail-panel" data-condition="guest-tables" hidden>
                                <fieldset class="quiz-question">
                                    <legend>Какая рассадка планируется?</legend>
                                    <div class="quiz-choice-grid quiz-choice-grid--three"><?php quizBriefChoiceOptions('table_layout', $optionSets['table_layout'], $formData); ?></div>
                                </fieldset>
                                <fieldset class="quiz-question" data-max-selected="6">
                                    <legend>Что нравится на столах?</legend>
                                    <div class="quiz-visual-grid quiz-visual-grid--four"><?php quizBriefVisualOptions('table_decor', $optionSets['table_decor'], $formData, $visualAssets['table_decor']); ?></div>
                                </fieldset>
                            </div>
                            <fieldset class="quiz-question quiz-conditional quiz-detail-panel" data-condition="presidium" hidden>
                                <legend>Какой формат президиума вам ближе?</legend>
                                <div class="quiz-visual-grid quiz-visual-grid--three"><?php quizBriefVisualOptions('presidium_style', $optionSets['presidium_style'], $formData, $visualAssets['presidium_style'], [], 'radio'); ?></div>
                            </fieldset>
                            <fieldset class="quiz-question quiz-conditional quiz-detail-panel" data-condition="photo-zone" hidden>
                                <legend>Какой формат фотозоны нравится?</legend>
                                <div class="quiz-visual-grid quiz-visual-grid--three"><?php quizBriefVisualOptions('photo_zone_style', $optionSets['photo_zone_style'], $formData, $visualAssets['photo_zone_style'], [], 'radio'); ?></div>
                            </fieldset>
                            <fieldset class="quiz-question">
                                <legend>Нужны элементы оформления на бумаге? <span class="quiz-optional">Необязательно</span></legend>
                                <div class="quiz-chip-grid"><?php quizBriefChipOptions('stationery', $optionSets['stationery'], $formData); ?></div>
                            </fieldset>
                        </section>

                        <section class="quiz-step" data-step-title="Бюджет" aria-labelledby="quiz-step-6-title" hidden>
                            <header class="quiz-step__header">
                                <p class="eyebrow">06 · Готовность и приоритеты</p>
                                <h2 id="quiz-step-6-title">На каком этапе вы сейчас?</h2>
                                <p>Это поможет предложить реалистичный объём работ и правильно расставить акценты.</p>
                            </header>
                            <fieldset class="quiz-question">
                                <legend>Что у вас уже готово?</legend>
                                <div class="quiz-chip-grid"><?php quizBriefChipOptions('already_booked', $optionSets['already_booked'], $formData); ?></div>
                            </fieldset>
                            <div class="form-group quiz-conditional" data-condition="already-other" hidden>
                                <label for="already_booked_other">Что ещё уже готово?</label>
                                <input id="already_booked_other" name="already_booked_other" class="form-control" maxlength="500" value="<?php echo escape($quizValue('already_booked_other')); ?>">
                            </div>
                            <fieldset class="quiz-question">
                                <legend>На каком этапе подготовка?</legend>
                                <div class="quiz-choice-grid quiz-choice-grid--two"><?php quizBriefChoiceOptions('planning_stage', $optionSets['planning_stage'], $formData); ?></div>
                            </fieldset>
                            <fieldset class="quiz-question">
                                <legend>Какой общий бюджет мероприятия рассматриваете?</legend>
                                <div class="quiz-choice-grid quiz-choice-grid--two"><?php quizBriefChoiceOptions('budget_range', $optionSets['budget_range'], $formData); ?></div>
                            </fieldset>
                            <fieldset class="quiz-question quiz-conditional" data-condition="floral-service" hidden>
                                <legend>Какой бюджет планируете на оформление и флористику?</legend>
                                <div class="quiz-choice-grid quiz-choice-grid--two"><?php quizBriefChoiceOptions('decor_budget', $optionSets['decor_budget'], $formData); ?></div>
                            </fieldset>
                            <fieldset class="quiz-question" data-max-selected="3">
                                <legend>На чём вам важнее всего не экономить?</legend>
                                <div class="quiz-chip-grid"><?php quizBriefChipOptions('budget_priorities', $optionSets['budget_priorities'], $formData); ?></div>
                                <p class="quiz-selection-hint">До 3 вариантов</p>
                            </fieldset>
                            <fieldset class="quiz-question">
                                <legend>Насколько бюджет может быть гибким?</legend>
                                <div class="quiz-choice-grid quiz-choice-grid--two"><?php quizBriefChoiceOptions('budget_flexibility', $optionSets['budget_flexibility'], $formData); ?></div>
                            </fieldset>
                        </section>

                        <section class="quiz-step" data-step-title="Контакты" aria-labelledby="quiz-step-7-title" hidden>
                            <header class="quiz-step__header">
                                <p class="eyebrow">07 · Пожелания и связь</p>
                                <h2 id="quiz-step-7-title">Последние важные детали</h2>
                                <p>Добавьте то, что нельзя потерять, и оставьте удобный контакт.</p>
                            </header>
                            <div class="form-group">
                                <label for="main_priority">Что для вас самое важное в этом событии? <span class="quiz-optional">Необязательно</span></label>
                                <textarea id="main_priority" name="main_priority" class="form-control" rows="4" maxlength="3000" placeholder="Например: уютный вечер, много общения и естественное оформление"><?php echo escape($quizValue('main_priority')); ?></textarea>
                            </div>
                            <div class="form-group">
                                <label for="dislikes">Есть что-то, чего вы точно не хотите? <span class="quiz-optional">Необязательно</span></label>
                                <textarea id="dislikes" name="dislikes" class="form-control" rows="4" maxlength="3000" placeholder="Например: искусственные цветы, золото или слишком плотные композиции"><?php echo escape($quizValue('dislikes')); ?></textarea>
                            </div>
                            <div class="form-group">
                                <label for="comment">Дополнительные пожелания <span class="quiz-optional">Необязательно</span></label>
                                <textarea id="comment" name="comment" class="form-control" rows="4" maxlength="5000" placeholder="Всё, что ещё важно учесть"><?php echo escape($quizValue('comment')); ?></textarea>
                            </div>
                            <div class="quiz-upload">
                                <div>
                                    <label for="references">Покажите, что вам нравится</label>
                                    <p>До 10 фотографий, скриншотов или PDF. Каждый файл до 10 МБ.</p>
                                </div>
                                <input type="file" id="references" name="references[]" accept="image/jpeg,image/png,image/webp,application/pdf" multiple>
                                <label class="quiz-upload__button" for="references">Выбрать референсы</label>
                                <div class="quiz-file-preview" id="quizFilePreview" aria-live="polite"></div>
                            </div>
                            <fieldset class="quiz-question">
                                <legend>Как вы о нас узнали?</legend>
                                <div class="quiz-chip-grid"><?php quizBriefChipOptions('source', $optionSets['source'], $formData, 'radio'); ?></div>
                            </fieldset>
                            <div class="form-group quiz-conditional" data-condition="source-other" hidden>
                                <label for="source_other">Расскажите, откуда узнали</label>
                                <input id="source_other" name="source_other" class="form-control" maxlength="190" value="<?php echo escape($quizValue('source_other')); ?>">
                            </div>
                            <div class="quiz-contact-panel">
                                <div class="quiz-contact-panel__heading"><p class="eyebrow">Обсудим бриф</p><h3>Как с вами связаться?</h3></div>
                                <div class="form-row">
                                    <div class="form-group"><label for="client_name">Имя *</label><input type="text" id="client_name" name="client_name" class="form-control" maxlength="190" value="<?php echo escape($quizValue('client_name')); ?>" autocomplete="name" required></div>
                                    <div class="form-group"><label for="phone">Телефон</label><input type="tel" id="phone" name="phone" class="form-control" maxlength="40" value="<?php echo escape($quizValue('phone')); ?>" placeholder="+7 (___) ___-__-__" autocomplete="tel"></div>
                                </div>
                                <div class="form-row">
                                    <div class="form-group"><label for="email">Электронная почта</label><input type="email" id="email" name="email" class="form-control" maxlength="190" value="<?php echo escape($quizValue('email')); ?>" autocomplete="email"></div>
                                    <div class="form-group"><label for="telegram_username">Имя пользователя в Telegram</label><input type="text" id="telegram_username" name="telegram_username" class="form-control" maxlength="120" value="<?php echo escape($quizValue('telegram_username')); ?>" placeholder="@username"></div>
                                </div>
                                <fieldset class="quiz-question" data-required-group="contact_method">
                                    <legend>Удобный способ связи</legend>
                                    <div class="quiz-choice-grid quiz-choice-grid--two"><?php quizBriefChoiceOptions('contact_method', $optionSets['contact_method'], $formData); ?></div>
                                </fieldset>
                                <fieldset class="quiz-question">
                                    <legend>Когда удобнее получить сообщение или звонок?</legend>
                                    <div class="quiz-segmented"><?php quizBriefChipOptions('contact_time', $optionSets['contact_time'], $formData, 'radio'); ?></div>
                                </fieldset>
                                <div class="form-group"><label for="contact_time_comment">Комментарий ко времени <span class="quiz-optional">Необязательно</span></label><input id="contact_time_comment" name="contact_time_comment" class="form-control" maxlength="500" value="<?php echo escape($quizValue('contact_time_comment')); ?>" placeholder="Например, лучше написать после 18:00"></div>
                            </div>
                            <section class="quiz-review" aria-labelledby="quiz-review-title">
                                <div class="quiz-review__heading"><div><p class="eyebrow">Ваше событие</p><h3 id="quiz-review-title">Проверьте главное</h3></div><button type="button" class="quiz-review__edit" data-go-step="0">Изменить ответы</button></div>
                                <div class="quiz-review__content" id="quizReview"></div>
                            </section>
                            <label class="checkbox-row privacy-consent"><input id="privacy-consent" type="checkbox" name="privacy_consent" value="1"<?php echo $quizValue('privacy_consent') ? ' checked' : ''; ?> required> <span>Я согласен(на) на обработку персональных данных для подготовки предложения и связи по заявке в соответствии с <a href="/privacy" target="_blank" rel="noopener">политикой обработки данных</a>.</span></label>
                        </section>

                        <div class="quiz-actions">
                            <button type="button" class="btn btn-outline" id="quizPrev" hidden>Назад</button>
                            <button type="button" class="btn btn-primary" id="quizNext">Продолжить</button>
                            <button type="submit" class="btn btn-primary" id="quizSubmit" hidden>Отправить заявку</button>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </section>
</main>
<?php include_once __DIR__ . '/includes/public-footer.php'; ?>
<script src="<?php echo asset('assets/js/phone-mask.js'); ?>"></script>
</body>
</html>
