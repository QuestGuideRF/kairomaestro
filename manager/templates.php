<?php
require_once __DIR__ . '/../config/config.php';
requireCrmPanel(['manager', 'admin']);
$templateService = new ManagerTemplate();
$userId = (int)$_SESSION['user_id'];
$error = '';
$success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = (string)($_POST['action'] ?? 'save');
    if ($action === 'delete') {
        $result = $templateService->delete((int)($_POST['template_id'] ?? 0), $userId);
        $success = $result['success'] ? 'Шаблон удалён.' : '';
    } else {
        $result = $templateService->save(
            (int)($_POST['template_id'] ?? 0),
            $userId,
            $_POST['title'] ?? '',
            $_POST['body'] ?? ''
        );
        $success = $result['success'] ? 'Шаблон сохранён.' : '';
    }
    if (!$result['success']) {
        $error = $result['error'];
    }
}
$templates = $templateService->allForUser($userId);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <title>Мои шаблоны - <?php echo escape(SITE_NAME); ?></title>
    <link rel="stylesheet" href="<?php echo asset('assets/css/style.min.css'); ?>">
<?php include_once __DIR__ . '/../includes/favicon-links.php'; ?></head>
<body>
<?php include_once __DIR__ . '/../includes/manager-header.php'; ?>
<main class="dashboard manager-templates-page">
    <div class="container">
        <header class="dashboard-header">
            <div><p class="eyebrow">Работа с заявками</p><h1>Мои шаблоны</h1><p>Заготовки для итогов разговора. Каждый менеджер видит только свои шаблоны.</p></div>
        </header>
        <?php if ($error): ?><div class="alert alert-error"><?php echo escape($error); ?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert alert-success"><?php echo escape($success); ?></div><?php endif; ?>
        <div class="manager-template-layout">
            <section class="panel-section manager-template-editor">
                <div class="section-header"><h2>Новый шаблон</h2></div>
                <form method="POST" class="stacked-form" id="managerTemplateForm">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="template_id" id="templateId" value="0">
                    <div class="form-group"><label for="templateTitle">Название</label><input class="form-control" id="templateTitle" name="title" maxlength="120" required placeholder="Например, первичный разговор"></div>
                    <div class="form-group"><label for="templateBody">Текст</label><textarea class="form-control" id="templateBody" name="body" rows="14" maxlength="12000" required placeholder="Дата и формат события:&#10;Площадка:&#10;Бюджет:&#10;Пожелания:&#10;Вопросы клиента:"></textarea></div>
                    <div class="form-actions"><button class="btn btn-primary" type="submit">Сохранить шаблон</button><button class="btn btn-outline" type="button" id="templateReset" hidden>Создать новый</button></div>
                </form>
            </section>
            <section class="panel-section manager-template-list">
                <div class="section-header"><h2>Сохранённые шаблоны</h2><span class="badge"><?php echo count($templates); ?></span></div>
                <?php if (!$templates): ?><div class="empty-state"><p>Шаблонов пока нет.</p></div><?php endif; ?>
                <?php foreach ($templates as $template): ?>
                    <article class="manager-template-card">
                        <div><h3><?php echo escape($template['title']); ?></h3><p><?php echo nl2br(escape(truncateText($template['body'], 300))); ?></p></div>
                        <div class="form-actions">
                            <button class="btn btn-sm btn-outline template-edit" type="button" data-id="<?php echo (int)$template['id']; ?>" data-title="<?php echo escape($template['title']); ?>" data-body="<?php echo escape($template['body']); ?>">Редактировать</button>
                            <form method="POST" onsubmit="return confirm('Удалить этот шаблон?');"><?php echo csrfField(); ?><input type="hidden" name="action" value="delete"><input type="hidden" name="template_id" value="<?php echo (int)$template['id']; ?>"><button class="btn btn-sm btn-outline" type="submit">Удалить</button></form>
                        </div>
                    </article>
                <?php endforeach; ?>
            </section>
        </div>
    </div>
</main>
<?php include_once __DIR__ . '/../includes/organizer-footer.php'; ?>
<script>
(function () {
    const id = document.getElementById('templateId');
    const title = document.getElementById('templateTitle');
    const body = document.getElementById('templateBody');
    const reset = document.getElementById('templateReset');
    document.querySelectorAll('.template-edit').forEach((button) => button.addEventListener('click', () => {
        id.value = button.dataset.id;
        title.value = button.dataset.title;
        body.value = button.dataset.body;
        reset.hidden = false;
        title.focus();
    }));
    reset.addEventListener('click', () => {
        id.value = '0';
        title.value = '';
        body.value = '';
        reset.hidden = true;
        title.focus();
    });
})();
</script>
</body>
</html>
