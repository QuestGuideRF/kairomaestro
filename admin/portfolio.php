<?php
require_once __DIR__ . '/bootstrap.php';

$portfolioAdmin = new PortfolioProjectAdmin();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    requireCsrf();
    $action = (string)($_POST['action'] ?? 'save');
    if ($action === 'remove_image') {
        $result = $portfolioAdmin->removeImage(
            (int)($_POST['image_id'] ?? 0),
            (int)$_SESSION['user_id']
        );
    } else {
        $result = $portfolioAdmin->save(
            $_POST,
            $_FILES,
            (int)$_SESSION['user_id']
        );
    }
    $editId = (int)($result['id'] ?? $_POST['id'] ?? 0);
    $target = 'portfolio.php' . ($editId > 0 ? '?edit=' . $editId : '');
    adminRedirect($target, $result);
}

$adminTitle = 'Проекты портфолио';
$adminSection = 'portfolio';
$editId = max(0, (int)($_GET['edit'] ?? 0));
$editing = $portfolioAdmin->project($editId);
$projects = $portfolioAdmin->projects();
require_once __DIR__ . '/header.php';
?>
<?php if (!$portfolioAdmin->isReady()): ?>
    <section class="admin-panel">
        <p>Таблицы <code>portfolio_projects</code> и <code>portfolio_images</code> недоступны.</p>
    </section>
<?php else: ?>
    <div class="admin-actions">
        <a class="admin-button" href="/admin/portfolio.php">Новый проект</a>
        <a class="admin-button is-secondary" href="/portfolio" target="_blank" rel="noopener">Открыть портфолио</a>
    </div>

    <section class="admin-panel">
        <h2><?php echo $editing ? 'Редактировать проект' : 'Создать проект'; ?></h2>
        <form method="post" enctype="multipart/form-data" class="admin-form admin-form--portfolio">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?php echo (int)($editing['id'] ?? 0); ?>">

            <label>
                Название
                <input name="title" maxlength="190" required
                       value="<?php echo escape($editing['title'] ?? ''); ?>">
            </label>
            <label>
                Slug
                <input name="slug" maxlength="120"
                       pattern="[a-z0-9]+(?:-[a-z0-9]+)*"
                       placeholder="vesennyaya-svadba" required
                       value="<?php echo escape($editing['slug'] ?? ''); ?>">
            </label>
            <label>
                Стиль
                <input name="style" maxlength="120"
                       placeholder="Флористика и сервировка"
                       value="<?php echo escape($editing['style'] ?? ''); ?>">
            </label>
            <label>
                Локация
                <input name="location" maxlength="190"
                       placeholder="Москва"
                       value="<?php echo escape($editing['location'] ?? ''); ?>">
            </label>
            <label>
                Количество гостей
                <input type="number" name="guests_count" min="1" max="100000"
                       value="<?php echo escape($editing['guests_count'] ?? ''); ?>">
            </label>
            <label>
                Дата события
                <input type="date" name="event_date"
                       value="<?php echo escape($editing['event_date'] ?? ''); ?>">
            </label>
            <label class="admin-form__wide">
                Описание проекта
                <textarea name="description" rows="6" maxlength="12000" required><?php
                    echo escape($editing['description'] ?? '');
                ?></textarea>
            </label>
            <label>
                Обложка JPEG, PNG или WebP до 5 МБ
                <input type="file" name="cover"
                       accept="image/jpeg,image/png,image/webp"
                       <?php echo $editing ? '' : 'required'; ?>>
            </label>
            <label>
                Alt обложки
                <input name="alt_text" maxlength="190"
                       placeholder="Описание того, что видно на фотографии"
                       value="<?php echo escape($editing['alt_text'] ?? ''); ?>">
            </label>
            <label>
                Галерея, до 12 фотографий за раз
                <input type="file" name="gallery[]" multiple
                       accept="image/jpeg,image/png,image/webp">
            </label>
            <label>
                Основа alt для галереи
                <input name="gallery_alt_prefix" maxlength="170"
                       placeholder="Флористическое оформление проекта">
            </label>
            <label>
                Порядок
                <input type="number" name="sort_order" min="-10000" max="10000"
                       value="<?php echo (int)($editing['sort_order'] ?? 0); ?>">
            </label>
            <label class="admin-check">
                <input type="checkbox" name="is_published" value="1"
                    <?php echo !$editing || !empty($editing['is_published']) ? 'checked' : ''; ?>>
                Опубликовать на сайте
            </label>
            <div class="admin-form__wide admin-actions">
                <button class="admin-button" type="submit">
                    <?php echo $editing ? 'Сохранить проект' : 'Создать проект'; ?>
                </button>
                <?php if ($editing && !empty($editing['is_published'])): ?>
                    <a class="admin-button is-secondary"
                       href="/portfolio/<?php echo escape($editing['slug']); ?>"
                       target="_blank" rel="noopener">Посмотреть проект</a>
                <?php endif; ?>
            </div>
        </form>
    </section>

    <?php if ($editing && !empty($editing['cover_image'])): ?>
        <section class="admin-panel">
            <h2>Текущая обложка</h2>
            <img class="admin-portfolio-cover"
                 src="/<?php echo escape(ltrim((string)$editing['cover_image'], '/')); ?>"
                 alt="<?php echo escape($editing['alt_text'] ?: $editing['title']); ?>">
        </section>
    <?php endif; ?>

    <?php if ($editing && !empty($editing['images'])): ?>
        <section class="admin-panel">
            <h2>Галерея</h2>
            <div class="admin-portfolio-gallery">
                <?php foreach ($editing['images'] as $galleryItem): ?>
                    <?php
                    $galleryUrl = '/' . ltrim((string)$galleryItem['image_url'], '/');
                    $galleryAlt = (string)($galleryItem['alt_text'] ?? '');
                    ?>
                    <article>
                        <img src="<?php echo escape($galleryUrl); ?>"
                             alt="<?php echo escape($galleryAlt); ?>"
                             loading="lazy" decoding="async">
                        <p><?php echo escape($galleryAlt ?: 'Без описания'); ?></p>
                        <form method="post">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="action" value="remove_image">
                            <input type="hidden" name="id" value="<?php echo (int)$editing['id']; ?>">
                            <input type="hidden" name="image_id" value="<?php echo (int)$galleryItem['id']; ?>">
                            <button class="admin-button is-danger" type="submit">Удалить фото</button>
                        </form>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Проект</th>
                    <th>Статус</th>
                    <th>Галерея</th>
                    <th>Порядок</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($projects as $project): ?>
                    <tr>
                        <td>
                            <strong><?php echo escape($project['title']); ?></strong><br>
                            <small>/portfolio/<?php echo escape($project['slug']); ?></small>
                        </td>
                        <td><?php echo $project['is_published'] ? 'Опубликован' : 'Черновик'; ?></td>
                        <td><?php echo (int)$project['image_count']; ?> фото</td>
                        <td><?php echo (int)$project['sort_order']; ?></td>
                        <td>
                            <a class="admin-button is-secondary"
                               href="/admin/portfolio.php?edit=<?php echo (int)$project['id']; ?>">
                                Изменить
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$projects): ?>
                    <tr><td colspan="5">Проектов в базе пока нет.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
<?php require_once __DIR__ . '/footer.php'; ?>
