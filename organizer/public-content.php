<?php
require_once __DIR__ . '/../config/config.php';
requireRole(['admin']);
$db = Database::getInstance()->getConnection();
$error = '';
$success = '';
$contentTablesReady = dbTableExists('services')
    && dbTableExists('service_packages')
    && dbTableExists('portfolio_projects');
function publicContentSlug($value) {
    $slug = strtolower(trim((string)$value));
    if (!preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $slug)) {
        throw new ValidationException('Slug должен содержать латинские буквы, цифры и дефисы.');
    }
    return $slug;
}
function publicContentImage($file) {
    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    $result = FileStorage::store(
        $file,
        'media',
        ['image/jpeg', 'image/png', 'image/webp'],
        min(UPLOAD_LIMIT_BYTES, 2 * 1024 * 1024)
    );
    if (!$result['success']) {
        throw new FileStorageException($result['error']);
    }
    return $result['file_path'];
}
function publicContentRecord($db, $table, $id) {
    $allowed = ['services', 'service_packages', 'portfolio_projects'];
    if (!in_array($table, $allowed, true) || (int)$id < 1) {
        return null;
    }
    $stmt = $db->prepare("SELECT * FROM {$table} WHERE id=? LIMIT 1");
    $stmt->execute([(int)$id]);
    return $stmt->fetch() ?: null;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        requireCsrf();
        if (!$contentTablesReady) {
            throw new ValidationException('Таблицы публичного контента отсутствуют. Сначала обновите базу данных.');
        }
        $action = (string)($_POST['action'] ?? '');
        $id = max(0, (int)($_POST['id'] ?? 0));
        $slug = publicContentSlug($_POST['slug'] ?? '');
        $title = trim((string)($_POST['title'] ?? ''));
        $sortOrder = (int)($_POST['sort_order'] ?? 0);
        if ($title === '' || utf8Length($title) > 190) {
            throw new ValidationException('Укажите название длиной до 190 символов.');
        }
        if ($action === 'save_service') {
            $description = trim((string)($_POST['description'] ?? ''));
            $image = publicContentImage($_FILES['image'] ?? null);
            if ($id) {
                $sql = 'UPDATE services SET slug=?,title=?,short_description=?,sort_order=?,is_active=?'
                    . ($image ? ',image_url=?' : '') . ' WHERE id=?';
                $params = [$slug, $title, $description, $sortOrder, isset($_POST['is_active']) ? 1 : 0];
                if ($image) {
                    $params[] = $image;
                }
                $params[] = $id;
                $db->prepare($sql)->execute($params);
            } else {
                $stmt = $db->prepare('INSERT INTO services (slug,title,short_description,image_url,sort_order,is_active) VALUES (?,?,?,?,?,?)');
                $stmt->execute([$slug, $title, $description, $image, $sortOrder, isset($_POST['is_active']) ? 1 : 0]);
                $id = (int)$db->lastInsertId();
            }
            $entity = 'service';
        } elseif ($action === 'save_package') {
            $description = trim((string)($_POST['description'] ?? ''));
            $features = array_values(array_filter(array_map('trim', preg_split('/\R/u', (string)($_POST['features'] ?? '')))));
            $priceNote = trim((string)($_POST['price_note'] ?? ''));
            $payload = json_encode($features, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            if ($id) {
                $stmt = $db->prepare('UPDATE service_packages SET slug=?,title=?,description=?,features=?,price_note=?,sort_order=?,is_active=? WHERE id=?');
                $stmt->execute([$slug, $title, $description, $payload, $priceNote ?: null, $sortOrder, isset($_POST['is_active']) ? 1 : 0, $id]);
            } else {
                $stmt = $db->prepare('INSERT INTO service_packages (slug,title,description,features,price_note,sort_order,is_active) VALUES (?,?,?,?,?,?,?)');
                $stmt->execute([$slug, $title, $description, $payload, $priceNote ?: null, $sortOrder, isset($_POST['is_active']) ? 1 : 0]);
                $id = (int)$db->lastInsertId();
            }
            $entity = 'service_package';
        } elseif ($action === 'save_project') {
            $description = trim((string)($_POST['description'] ?? ''));
            $style = trim((string)($_POST['style'] ?? ''));
            $location = trim((string)($_POST['location'] ?? ''));
            $guests = trim((string)($_POST['guests_count'] ?? ''));
            $eventDate = trim((string)($_POST['event_date'] ?? ''));
            $alt = trim((string)($_POST['alt_text'] ?? ''));
            $image = publicContentImage($_FILES['image'] ?? null);
            $values = [
                $slug, $title, $style ?: null, $location ?: null,
                $guests === '' ? null : max(1, (int)$guests),
                $description, $alt ?: $title, $eventDate ?: null,
                isset($_POST['is_published']) ? 1 : 0, $sortOrder,
            ];
            if ($id) {
                $sql = 'UPDATE portfolio_projects SET slug=?,title=?,style=?,location=?,guests_count=?,description=?,alt_text=?,event_date=?,is_published=?,sort_order=?'
                    . ($image ? ',cover_image=?' : '') . ' WHERE id=?';
                if ($image) {
                    $values[] = $image;
                }
                $values[] = $id;
                $db->prepare($sql)->execute($values);
            } else {
                $stmt = $db->prepare('INSERT INTO portfolio_projects (slug,title,style,location,guests_count,description,cover_image,alt_text,event_date,is_published,sort_order) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
                $stmt->execute([
                    $slug, $title, $style ?: null, $location ?: null,
                    $guests === '' ? null : max(1, (int)$guests),
                    $description, $image, $alt ?: $title, $eventDate ?: null,
                    isset($_POST['is_published']) ? 1 : 0, $sortOrder,
                ]);
                $id = (int)$db->lastInsertId();
            }
            $entity = 'portfolio_project';
        } else {
            throw new ValidationException('Неизвестная операция.');
        }
        AuditLog::record('public_content.saved', $entity, $id, null, [
            'slug' => $slug,
            'published' => isset($_POST['is_active']) || isset($_POST['is_published']),
        ]);
        $success = 'Изменения сохранены.';
    } catch (Exception $e) {
        $error = publicExceptionMessage($e, 'Не удалось сохранить публичный контент.');
    }
}
$editType = (string)($_GET['type'] ?? '');
$editId = max(0, (int)($_GET['id'] ?? 0));
$tableByType = ['service' => 'services', 'package' => 'service_packages', 'project' => 'portfolio_projects'];
$editing = $contentTablesReady && isset($tableByType[$editType])
    ? publicContentRecord($db, $tableByType[$editType], $editId)
    : null;
$services = $contentTablesReady ? $db->query('SELECT * FROM services ORDER BY sort_order,id')->fetchAll() : [];
$packages = $contentTablesReady ? $db->query('SELECT * FROM service_packages ORDER BY sort_order,id')->fetchAll() : [];
$projects = $contentTablesReady ? $db->query('SELECT * FROM portfolio_projects ORDER BY sort_order,id')->fetchAll() : [];
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Публичный контент - <?php echo escape(SITE_NAME); ?></title>
    <link rel="stylesheet" href="<?php echo asset('assets/css/style.min.css'); ?>">
<?php include_once __DIR__ . '/../includes/favicon-links.php'; ?></head>
<body>
<?php include_once __DIR__ . '/../includes/organizer-header.php'; ?>
<main class="dashboard">
    <div class="container container-wide">
        <div class="dashboard-header">
            <div><p class="eyebrow">Сайт</p><h1>Публичный контент</h1></div>
            <a class="btn btn-outline" href="/" target="_blank" rel="noopener">Открыть сайт</a>
        </div>
        <?php if ($error): ?><div class="alert alert-error"><?php echo escape($error); ?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert alert-success"><?php echo escape($success); ?></div><?php endif; ?>
        <div class="workspace-tabs">
            <a href="#services">Услуги</a><a href="#packages">Пакеты</a><a href="#projects">Портфолио</a>
        </div>
        <section class="panel-section" id="services">
            <h2><?php echo $editType === 'service' && $editing ? 'Редактировать услугу' : 'Новая услуга'; ?></h2>
            <form method="POST" enctype="multipart/form-data" class="event-form">
                <?php echo csrfField(); ?><input type="hidden" name="action" value="save_service">
                <input type="hidden" name="id" value="<?php echo $editType === 'service' ? (int)$editId : 0; ?>">
                <div class="form-row">
                    <div class="form-group"><label for="service-title">Название</label><input id="service-title" class="form-control" name="title" required value="<?php echo escape($editType === 'service' ? ($editing['title'] ?? '') : ''); ?>"></div>
                    <div class="form-group"><label for="service-slug">Slug</label><input id="service-slug" class="form-control" name="slug" pattern="[a-z0-9]+(?:-[a-z0-9]+)*" required value="<?php echo escape($editType === 'service' ? ($editing['slug'] ?? '') : ''); ?>"></div>
                </div>
                <div class="form-group"><label for="service-description">Краткое описание</label><textarea id="service-description" class="form-control" name="description"><?php echo escape($editType === 'service' ? ($editing['short_description'] ?? '') : ''); ?></textarea></div>
                <div class="form-row">
                    <div class="form-group"><label for="service-image">Изображение JPEG, PNG или WebP до 2 МБ</label><input id="service-image" class="form-control" type="file" name="image" accept="image/jpeg,image/png,image/webp"></div>
                    <div class="form-group"><label for="service-sort-order">Порядок</label><input id="service-sort-order" class="form-control" type="number" name="sort_order" value="<?php echo (int)($editType === 'service' ? ($editing['sort_order'] ?? 0) : 0); ?>"></div>
                </div>
                <label class="checkbox-label" for="service-is-active"><input id="service-is-active" type="checkbox" name="is_active" <?php echo $editType !== 'service' || !isset($editing['is_active']) || $editing['is_active'] ? 'checked' : ''; ?>> Показывать на сайте</label>
                <button class="btn btn-primary" type="submit">Сохранить услугу</button>
            </form>
            <div class="content-admin-list"><?php foreach ($services as $item): ?><a href="?type=service&id=<?php echo (int)$item['id']; ?>#services"><strong><?php echo escape($item['title']); ?></strong><span><?php echo $item['is_active'] ? 'Опубликована' : 'Скрыта'; ?></span></a><?php endforeach; ?></div>
        </section>
        <section class="panel-section" id="packages">
            <h2><?php echo $editType === 'package' && $editing ? 'Редактировать пакет' : 'Новый пакет'; ?></h2>
            <form method="POST" class="event-form">
                <?php echo csrfField(); ?><input type="hidden" name="action" value="save_package">
                <input type="hidden" name="id" value="<?php echo $editType === 'package' ? (int)$editId : 0; ?>">
                <div class="form-row">
                    <div class="form-group"><label for="package-title">Название</label><input id="package-title" class="form-control" name="title" required value="<?php echo escape($editType === 'package' ? ($editing['title'] ?? '') : ''); ?>"></div>
                    <div class="form-group"><label for="package-slug">Slug</label><input id="package-slug" class="form-control" name="slug" pattern="[a-z0-9]+(?:-[a-z0-9]+)*" required value="<?php echo escape($editType === 'package' ? ($editing['slug'] ?? '') : ''); ?>"></div>
                </div>
                <div class="form-group"><label for="package-description">Описание</label><textarea id="package-description" class="form-control" name="description"><?php echo escape($editType === 'package' ? ($editing['description'] ?? '') : ''); ?></textarea></div>
                <div class="form-row">
                    <div class="form-group"><label for="package-features">Состав, один пункт на строку</label><textarea id="package-features" class="form-control" name="features"><?php $features = $editType === 'package' ? json_decode((string)($editing['features'] ?? '[]'), true) : []; echo escape(implode("\n", is_array($features) ? $features : [])); ?></textarea></div>
                    <div>
                        <div class="form-group"><label for="package-price-note">Ориентир стоимости</label><input id="package-price-note" class="form-control" name="price_note" value="<?php echo escape($editType === 'package' ? ($editing['price_note'] ?? '') : ''); ?>"></div>
                        <div class="form-group"><label for="package-sort-order">Порядок</label><input id="package-sort-order" class="form-control" type="number" name="sort_order" value="<?php echo (int)($editType === 'package' ? ($editing['sort_order'] ?? 0) : 0); ?>"></div>
                    </div>
                </div>
                <label class="checkbox-label" for="package-is-active"><input id="package-is-active" type="checkbox" name="is_active" <?php echo $editType !== 'package' || !isset($editing['is_active']) || $editing['is_active'] ? 'checked' : ''; ?>> Показывать на сайте</label>
                <button class="btn btn-primary" type="submit">Сохранить пакет</button>
            </form>
            <div class="content-admin-list"><?php foreach ($packages as $item): ?><a href="?type=package&id=<?php echo (int)$item['id']; ?>#packages"><strong><?php echo escape($item['title']); ?></strong><span><?php echo $item['is_active'] ? 'Опубликован' : 'Скрыт'; ?></span></a><?php endforeach; ?></div>
        </section>
        <section class="panel-section" id="projects">
            <h2><?php echo $editType === 'project' && $editing ? 'Редактировать проект' : 'Новый проект'; ?></h2>
            <form method="POST" enctype="multipart/form-data" class="event-form">
                <?php echo csrfField(); ?><input type="hidden" name="action" value="save_project">
                <input type="hidden" name="id" value="<?php echo $editType === 'project' ? (int)$editId : 0; ?>">
                <div class="form-row">
                    <div class="form-group"><label for="project-title">Название</label><input id="project-title" class="form-control" name="title" required value="<?php echo escape($editType === 'project' ? ($editing['title'] ?? '') : ''); ?>"></div>
                    <div class="form-group"><label for="project-slug">Slug</label><input id="project-slug" class="form-control" name="slug" pattern="[a-z0-9]+(?:-[a-z0-9]+)*" required value="<?php echo escape($editType === 'project' ? ($editing['slug'] ?? '') : ''); ?>"></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label for="project-style">Стиль</label><input id="project-style" class="form-control" name="style" value="<?php echo escape($editType === 'project' ? ($editing['style'] ?? '') : ''); ?>"></div>
                    <div class="form-group"><label for="project-location">Локация</label><input id="project-location" class="form-control" name="location" value="<?php echo escape($editType === 'project' ? ($editing['location'] ?? '') : ''); ?>"></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label for="project-guests-count">Гостей</label><input id="project-guests-count" class="form-control" type="number" min="1" name="guests_count" value="<?php echo escape($editType === 'project' ? ($editing['guests_count'] ?? '') : ''); ?>"></div>
                    <div class="form-group"><label for="project-event-date">Дата события</label><input id="project-event-date" class="form-control" type="date" name="event_date" value="<?php echo escape($editType === 'project' ? ($editing['event_date'] ?? '') : ''); ?>"></div>
                </div>
                <div class="form-group"><label for="project-description">Описание кейса</label><textarea id="project-description" class="form-control" name="description"><?php echo escape($editType === 'project' ? ($editing['description'] ?? '') : ''); ?></textarea></div>
                <div class="form-row">
                    <div class="form-group"><label for="project-image">Обложка JPEG, PNG или WebP до 2 МБ</label><input id="project-image" class="form-control" type="file" name="image" accept="image/jpeg,image/png,image/webp"></div>
                    <div class="form-group"><label for="project-alt-text">Alt изображения</label><input id="project-alt-text" class="form-control" name="alt_text" value="<?php echo escape($editType === 'project' ? ($editing['alt_text'] ?? '') : ''); ?>"></div>
                </div>
                <div class="form-group"><label for="project-sort-order">Порядок</label><input id="project-sort-order" class="form-control" type="number" name="sort_order" value="<?php echo (int)($editType === 'project' ? ($editing['sort_order'] ?? 0) : 0); ?>"></div>
                <label class="checkbox-label" for="project-is-published"><input id="project-is-published" type="checkbox" name="is_published" <?php echo $editType !== 'project' || !isset($editing['is_published']) || $editing['is_published'] ? 'checked' : ''; ?>> Опубликовать проект</label>
                <button class="btn btn-primary" type="submit">Сохранить проект</button>
            </form>
            <div class="content-admin-list"><?php foreach ($projects as $item): ?><a href="?type=project&id=<?php echo (int)$item['id']; ?>#projects"><strong><?php echo escape($item['title']); ?></strong><span><?php echo $item['is_published'] ? 'Опубликован' : 'Черновик'; ?></span></a><?php endforeach; ?></div>
        </section>
    </div>
</main>
<?php include_once __DIR__ . '/../includes/organizer-footer.php'; ?>
</body>
</html>
