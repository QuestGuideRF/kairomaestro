<?php
require_once __DIR__ . '/../config/config.php';
requireCrmPanel(['organizer', 'admin']);
requirePermission('manage_templates');
$templateObj = new OrderTemplate();
$documentObj = new OrderDocument();
$error = '';
$success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'preset') {
        $result = $templateObj->createPreset($_POST['preset'] ?? '', (int)$_SESSION['user_id']);
    } elseif ($action === 'document_template') {
        $result = $documentObj->saveTemplate(
            !empty($_POST['template_id']) ? (int)$_POST['template_id'] : null,
            (int)$_SESSION['user_id'],
            $_POST
        );
    } else {
        $result = $templateObj->save(
            !empty($_POST['template_id']) ? (int)$_POST['template_id'] : null,
            (int)$_SESSION['user_id'],
            $_POST
        );
    }
    $success = $result['success'] ? 'Шаблон сохранён.' : '';
    $error = $result['success'] ? '' : ($result['error'] ?? 'Не удалось сохранить шаблон');
}
$templates = dbColumnExists('event_templates', 'template_payload') ? $templateObj->all(false) : [];
$documentTemplates = dbTableExists('document_templates') ? $documentObj->templates(null, false) : [];
$editTemplate = null;
$editDocumentTemplate = null;
if (!empty($_GET['edit']) && ctype_digit((string)$_GET['edit'])) {
    foreach ($templates as $template) {
        if ((int)$template['id'] === (int)$_GET['edit']
            && (isAdminRole() || (int)$template['organizer_id'] === (int)$_SESSION['user_id'])) {
            $editTemplate = $template;
            break;
        }
    }
}
if (!empty($_GET['edit_doc']) && ctype_digit((string)$_GET['edit_doc'])) {
    foreach ($documentTemplates as $template) {
        if ((int)$template['id'] === (int)$_GET['edit_doc']) {
            $editDocumentTemplate = $template;
            break;
        }
    }
}
$payloadKeys = ['tasks', 'checklists', 'roles', 'timeline', 'expenses', 'payments', 'documents', 'notifications'];
$builderPayload = array_fill_keys($payloadKeys, []);
if ($editTemplate) {
    $decodedPayload = json_decode((string)($editTemplate['template_payload'] ?? ''), true);
    if (is_array($decodedPayload)) {
        foreach ($payloadKeys as $payloadKey) {
            if (isset($decodedPayload[$payloadKey]) && is_array($decodedPayload[$payloadKey])) {
                $builderPayload[$payloadKey] = $decodedPayload[$payloadKey];
            }
        }
    }
}
$documentBuilderOptions = [];
foreach ($documentTemplates as $template) {
    if (!empty($template['is_active'])) {
        $documentBuilderOptions[] = [
            'id' => (int)$template['id'],
            'name' => $template['name'],
            'type' => $template['document_type'],
        ];
    }
}
$defaultDocumentBody = '<h1>{{order_title}}</h1><p>Клиент: <strong>{{client_name}}</strong></p><p>Дата события: {{event_date}}</p>';
$documentBody = $editDocumentTemplate['body_html'] ?? $defaultDocumentBody;
$payloadLabels = [
    'tasks' => 'задач',
    'checklists' => 'пунктов чек-листа',
    'roles' => 'ролей команды',
    'timeline' => 'этапов тайминга',
    'expenses' => 'статей расходов',
    'payments' => 'платежей',
    'documents' => 'документов',
    'notifications' => 'уведомлений',
];
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Шаблоны заказов - Азбука Декора</title>
    <link rel="stylesheet" href="<?php echo asset('assets/css/style.min.css'); ?>">
<?php include_once __DIR__ . '/../includes/favicon-links.php'; ?></head>
<body>
<?php include_once __DIR__ . '/../includes/organizer-header.php'; ?>
<main class="dashboard template-page">
    <div class="container">
        <div class="dashboard-header template-page__header">
            <div>
                <p class="eyebrow">Стандарты подготовки</p>
                <h1>Шаблоны</h1>
                <p>Соберите повторяемый план заказа без технических полей. При применении создаётся независимая копия.</p>
            </div>
        </div>
        <?php if ($error): ?><div class="alert alert-error"><?php echo escape($error); ?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert alert-success"><?php echo escape($success); ?></div><?php endif; ?>
        <section class="panel-section">
            <div class="section-header">
                <div>
                    <p class="eyebrow">Быстрый старт</p>
                    <h2>Базовые сценарии заказа</h2>
                </div>
            </div>
            <div class="preset-grid">
                <?php foreach ([
                    'wedding' => 'Свадьба под ключ',
                    'decor' => 'Декор и флористика',
                    'chamber' => 'Камерное событие',
                    'corporate' => 'Корпоратив',
                    'birthday' => 'День рождения',
                    'graduation' => 'Выпускной',
                    'photozone' => 'Фотозона',
                    'restaurant' => 'Оформление ресторана',
                    'custom' => 'Индивидуальный проект',
                ] as $key => $label): ?>
                    <form method="POST" class="preset-card">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="action" value="preset">
                        <input type="hidden" name="preset" value="<?php echo escape($key); ?>">
                        <strong><?php echo escape($label); ?></strong>
                        <button class="btn btn-sm btn-outline" type="submit">Создать</button>
                    </form>
                <?php endforeach; ?>
            </div>
        </section>
        <section class="panel-section template-order-editor">
            <div class="section-header">
                <div>
                    <p class="eyebrow">Сценарий проекта</p>
                    <h2><?php echo $editTemplate ? 'Редактирование шаблона заказа' : 'Новый шаблон заказа'; ?></h2>
                    <p class="muted">Добавьте только те элементы, которые должны появляться в каждом заказе этого типа.</p>
                </div>
                <?php if ($editTemplate): ?><a class="btn btn-sm btn-outline" href="/organizer/templates.php">Отменить</a><?php endif; ?>
            </div>
            <form method="POST" class="event-form" id="orderTemplateForm">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="order_template">
                <?php if ($editTemplate): ?><input type="hidden" name="template_id" value="<?php echo (int)$editTemplate['id']; ?>"><?php endif; ?>
                <input type="hidden" id="template_payload" name="template_payload" value="<?php echo escape(json_encode($builderPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?>">
                <div class="form-row">
                    <div class="form-group">
                        <label for="template_name">Название шаблона</label>
                        <input id="template_name" name="template_name" class="form-control" maxlength="190" value="<?php echo escape($editTemplate['template_name'] ?? ''); ?>" placeholder="Например: Камерная свадьба" required>
                    </div>
                    <div class="form-group">
                        <label for="event_type">Тип мероприятия</label>
                        <input id="event_type" name="event_type" class="form-control" maxlength="120" value="<?php echo escape($editTemplate['event_type'] ?? ''); ?>" placeholder="Свадьба">
                    </div>
                    <div class="form-group">
                        <label for="duration_hours">Длительность, часов</label>
                        <input id="duration_hours" type="number" min="1" max="1000" name="duration_hours" class="form-control" value="<?php echo escape($editTemplate['duration_hours'] ?? ''); ?>" placeholder="12">
                    </div>
                </div>
                <div class="form-group">
                    <label for="description">Когда использовать шаблон</label>
                    <textarea id="description" name="description" class="form-control" rows="3" maxlength="10000" placeholder="Кратко опишите формат события и особенности подготовки"><?php echo escape($editTemplate['description'] ?? ''); ?></textarea>
                </div>
                <div class="template-builder" id="templateBuilder">
                    <div class="template-builder__intro">
                        <div>
                            <h3>Состав шаблона</h3>
                            <p>Каждый раздел можно оставить пустым. Пустые строки в заказ не копируются.</p>
                        </div>
                        <span class="badge">Без JSON</span>
                    </div>
                    <?php foreach ([
                        'tasks' => ['Задачи', 'Что нужно сделать и за сколько дней до события.'],
                        'checklists' => ['Чек-лист', 'Короткий список обязательных проверок.'],
                        'roles' => ['Команда', 'Какие исполнители и в каком количестве нужны.'],
                        'timeline' => ['Тайминг', 'Повторяемые этапы дня события.'],
                        'expenses' => ['Расходы', 'Типовые плановые статьи расходов.'],
                        'payments' => ['Платежи клиента', 'Суммы и сроки относительно даты события.'],
                        'documents' => ['Документы', 'Готовые шаблоны документов, которые нужно создать.'],
                        'notifications' => ['Напоминания', 'Кому, когда и что напомнить.'],
                    ] as $sectionKey => [$sectionTitle, $sectionDescription]): ?>
                        <section class="template-builder__section" data-template-section="<?php echo escape($sectionKey); ?>">
                            <div class="template-builder__section-heading">
                                <div>
                                    <h3><?php echo escape($sectionTitle); ?></h3>
                                    <p><?php echo escape($sectionDescription); ?></p>
                                </div>
                                <output data-template-count>0</output>
                            </div>
                            <div class="template-builder__items" data-template-items></div>
                            <button class="btn btn-sm btn-outline template-builder__add" type="button" data-add-template-item>
                                Добавить
                            </button>
                        </section>
                    <?php endforeach; ?>
                </div>
                <label class="settings-toggle" for="order-template-active">
                    <input id="order-template-active" type="checkbox" name="is_active" value="1" <?php echo !$editTemplate || !empty($editTemplate['is_active']) ? 'checked' : ''; ?>>
                    <span>
                        <strong>Доступен для использования</strong>
                        <small>Выключенный шаблон сохраняется, но не предлагается в новых заказах.</small>
                    </span>
                </label>
                <div class="form-actions template-form-actions">
                    <button class="btn btn-primary" type="submit"><?php echo $editTemplate ? 'Сохранить изменения' : 'Сохранить шаблон заказа'; ?></button>
                </div>
            </form>
        </section>
        <section class="panel-section document-template-section">
            <div class="section-header">
                <div>
                    <p class="eyebrow">Документы проекта</p>
                    <h2>Шаблоны документов</h2>
                    <p class="muted">Вставляйте данные заказа кнопками. При создании документа система подставит реальные значения.</p>
                </div>
            </div>
            <div class="workspace-split document-template-layout">
                <form method="POST" class="stack-form document-template-form" id="documentTemplateForm">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="document_template">
                    <?php if ($editDocumentTemplate): ?><input type="hidden" name="template_id" value="<?php echo (int)$editDocumentTemplate['id']; ?>"><?php endif; ?>
                    <div class="section-header">
                        <h3><?php echo $editDocumentTemplate ? 'Редактирование документа' : 'Новый шаблон документа'; ?></h3>
                        <?php if ($editDocumentTemplate): ?><a class="btn btn-sm btn-outline" href="/organizer/templates.php">Отменить</a><?php endif; ?>
                    </div>
                    <div class="form-group">
                        <label for="doc_name">Название</label>
                        <input id="doc_name" name="name" class="form-control" maxlength="190" value="<?php echo escape($editDocumentTemplate['name'] ?? ''); ?>" placeholder="Например: Договор с клиентом" required>
                    </div>
                    <div class="form-group">
                        <label for="doc_type">Тип документа</label>
                        <select id="doc_type" name="document_type" class="form-control">
                            <?php foreach ($documentObj->types() as $key => $label): ?>
                                <option value="<?php echo escape($key); ?>" <?php echo ($editDocumentTemplate['document_type'] ?? 'custom') === $key ? 'selected' : ''; ?>><?php echo escape($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="document-variable-library" aria-label="Данные заказа для вставки">
                        <span>Вставить данные заказа</span>
                        <?php foreach ([
                            '{{client_name}}' => 'Имя клиента',
                            '{{order_title}}' => 'Название заказа',
                            '{{event_date}}' => 'Дата события',
                            '{{venue}}' => 'Площадка',
                            '{{address}}' => 'Адрес',
                            '{{order_id}}' => 'Номер заказа',
                        ] as $variable => $label): ?>
                            <button type="button" class="variable-chip" data-document-variable="<?php echo escape($variable); ?>"><?php echo escape($label); ?></button>
                        <?php endforeach; ?>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="docVisualEditor">Текст документа</label>
                        <div class="document-visual-toolbar" role="toolbar" aria-label="Форматирование документа">
                            <button type="button" data-editor-command="formatBlock" data-editor-value="h2" title="Заголовок" aria-label="Заголовок">H2</button>
                            <button type="button" data-editor-command="bold" title="Полужирный" aria-label="Полужирный"><strong>B</strong></button>
                            <button type="button" data-editor-command="italic" title="Курсив" aria-label="Курсив"><em>I</em></button>
                            <button type="button" data-editor-command="insertUnorderedList" title="Маркированный список" aria-label="Маркированный список">•</button>
                            <button type="button" data-editor-command="formatBlock" data-editor-value="p" title="Обычный текст" aria-label="Обычный текст">P</button>
                        </div>
                        <textarea
                            id="docVisualEditor"
                            name="body_html"
                            class="document-visual-editor"
                            rows="14"
                            spellcheck="true"
                        ><?php echo escape($documentBody); ?></textarea>
                    </div>
                    <label class="settings-toggle" for="document-template-active">
                        <input id="document-template-active" type="checkbox" name="is_active" value="1" <?php echo !$editDocumentTemplate || !empty($editDocumentTemplate['is_active']) ? 'checked' : ''; ?>>
                        <span>
                            <strong>Доступен организаторам</strong>
                            <small>Шаблон можно выбрать при создании документа в заказе.</small>
                        </span>
                    </label>
                    <button class="btn btn-primary" type="submit"><?php echo $editDocumentTemplate ? 'Сохранить изменения' : 'Сохранить шаблон документа'; ?></button>
                </form>
                <div class="document-list">
                    <?php foreach ($documentTemplates as $template): ?>
                        <article class="document-row">
                            <div>
                                <span class="badge"><?php echo $template['is_active'] ? 'Доступен' : 'Выключен'; ?></span>
                                <h3><?php echo escape($template['name']); ?></h3>
                                <p><?php echo escape($documentObj->types()[$template['document_type']] ?? $template['document_type']); ?></p>
                            </div>
                            <div class="card-actions"><a class="btn btn-sm btn-outline" href="?edit_doc=<?php echo (int)$template['id']; ?>#doc_name">Редактировать</a></div>
                            <details>
                                <summary>Посмотреть документ</summary>
                                <div class="document-preview"><?php echo $template['body_html']; ?></div>
                            </details>
                        </article>
                    <?php endforeach; ?>
                    <?php if (!$documentTemplates): ?>
                        <div class="empty-state"><p>Шаблонов документов пока нет.</p></div>
                    <?php endif; ?>
                </div>
            </div>
        </section>
        <section class="panel-section">
            <div class="section-header">
                <div>
                    <p class="eyebrow">Библиотека</p>
                    <h2>Сохранённые шаблоны заказов</h2>
                </div>
            </div>
            <div class="materials-grid template-library">
                <?php foreach ($templates as $template): ?>
                    <?php
                    $templatePayload = json_decode((string)($template['template_payload'] ?? ''), true);
                    $templatePayload = is_array($templatePayload) ? $templatePayload : [];
                    ?>
                    <article class="material-card template-library__item">
                        <span class="badge"><?php echo $template['is_active'] ? 'Доступен' : 'Выключен'; ?></span>
                        <h3><?php echo escape($template['template_name']); ?></h3>
                        <p><?php echo escape($template['event_type'] ?: 'Тип не указан'); ?></p>
                        <ul class="template-summary-list">
                            <?php $hasItems = false; ?>
                            <?php foreach ($payloadLabels as $key => $label): ?>
                                <?php $count = isset($templatePayload[$key]) && is_array($templatePayload[$key]) ? count($templatePayload[$key]) : 0; ?>
                                <?php if ($count > 0): $hasItems = true; ?>
                                    <li><strong><?php echo $count; ?></strong> <?php echo escape($label); ?></li>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <?php if (!$hasItems): ?><li>Базовые данные без дополнительных элементов</li><?php endif; ?>
                        </ul>
                        <?php if (isAdminRole() || (int)$template['organizer_id'] === (int)$_SESSION['user_id']): ?>
                            <div class="card-actions"><a class="btn btn-sm btn-outline" href="?edit=<?php echo (int)$template['id']; ?>#template_name">Редактировать</a></div>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
                <?php if (!$templates): ?><div class="empty-state"><p>Шаблонов заказов пока нет.</p></div><?php endif; ?>
            </div>
        </section>
    </div>
</main>
<script id="templateBuilderInitial" type="application/json"><?php echo json_encode($builderPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP); ?></script>
<script id="templateDocumentOptions" type="application/json"><?php echo json_encode($documentBuilderOptions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP); ?></script>
<script src="<?php echo asset('assets/js/template-builder.js'); ?>"></script>
<?php include_once __DIR__ . '/../includes/organizer-footer.php'; ?>
</body>
</html>
