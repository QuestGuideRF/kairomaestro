<?php
require_once __DIR__ . '/../config/config.php';
requireCrmPanel(['admin']);
$candidateApplication = new CandidateApplication();
$statuses = CandidateApplication::statuses();
$statusFilter = isset($statuses[$_GET['status'] ?? '']) ? $_GET['status'] : null;
$error = '';
$success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $result = $candidateApplication->updateStatus(
        (int)($_POST['candidate_id'] ?? 0),
        (string)($_POST['status'] ?? ''),
        (int)$_SESSION['user_id']
    );
    if ($result['success']) {
        $success = 'Статус заявки сохранён.';
    } else {
        $error = $result['error'];
    }
}
$candidates = $candidateApplication->all($statusFilter);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Заявки в команду - <?php echo escape(SITE_NAME); ?></title>
    <link rel="stylesheet" href="<?php echo asset('assets/css/style.min.css'); ?>">
<?php include_once __DIR__ . '/../includes/favicon-links.php'; ?></head>
<body>
    <?php include_once __DIR__ . '/../includes/organizer-header.php'; ?>
    <main class="dashboard">
        <div class="container">
            <div class="dashboard-header">
                <div>
                    <p class="eyebrow">Команда</p>
                    <h1>Заявки кандидатов</h1>
                </div>
                <form method="GET" class="inline-form">
                    <label class="sr-only" for="candidateStatusFilter">Фильтр по статусу</label>
                    <select class="form-control" id="candidateStatusFilter" name="status" onchange="this.form.submit()">
                        <option value="">Все статусы</option>
                        <?php foreach ($statuses as $value => $label): ?>
                            <option value="<?php echo escape($value); ?>" <?php echo $statusFilter === $value ? 'selected' : ''; ?>><?php echo escape($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>
            <?php if ($error): ?><div class="alert alert-error"><?php echo escape($error); ?></div><?php endif; ?>
            <?php if ($success): ?><div class="alert alert-success"><?php echo escape($success); ?></div><?php endif; ?>
            <div class="candidate-admin-grid">
                <?php foreach ($candidates as $candidate): ?>
                    <article class="candidate-admin-card">
                        <div class="candidate-admin-card__top">
                            <span class="badge badge-<?php echo escape($candidate['status']); ?>"><?php echo escape($statuses[$candidate['status']] ?? $candidate['status']); ?></span>
                            <time datetime="<?php echo escape($candidate['created_at']); ?>"><?php echo escape(date('d.m.Y H:i', strtotime($candidate['created_at']))); ?></time>
                        </div>
                        <h2><?php echo escape($candidate['full_name']); ?></h2>
                        <p><strong><?php echo escape(CandidateApplication::roles()[$candidate['desired_role']] ?? $candidate['desired_role']); ?></strong> · <?php echo escape(CandidateApplication::experienceOptions()[$candidate['experience']] ?? $candidate['experience']); ?></p>
                        <dl class="candidate-admin-details">
                            <?php if ($candidate['phone']): ?><div><dt>Телефон</dt><dd><a href="<?php echo escape(phoneLink($candidate['phone'])); ?>"><?php echo escape($candidate['phone']); ?></a></dd></div><?php endif; ?>
                            <?php if ($candidate['email']): ?><div><dt>Email</dt><dd><a href="mailto:<?php echo escape($candidate['email']); ?>"><?php echo escape($candidate['email']); ?></a></dd></div><?php endif; ?>
                            <?php if ($candidate['telegram']): ?><div><dt>Telegram</dt><dd><a href="https://t.me/<?php echo escape($candidate['telegram']); ?>" target="_blank" rel="noopener">@<?php echo escape($candidate['telegram']); ?></a></dd></div><?php endif; ?>
                            <div><dt>Оплата</dt><dd><?php echo number_format((float)$candidate['desired_salary'], 0, ',', ' '); ?> ₽</dd></div>
                        </dl>
                        <?php if ($candidate['portfolio_url']): ?><p><a class="home-text-link" href="<?php echo escape($candidate['portfolio_url']); ?>" target="_blank" rel="noopener noreferrer">Открыть портфолио</a></p><?php endif; ?>
                        <?php if ($candidate['preferred_schedule']): ?><p><strong>График:</strong> <?php echo nl2br(escape($candidate['preferred_schedule'])); ?></p><?php endif; ?>
                        <?php if ($candidate['comment']): ?><p><?php echo nl2br(escape($candidate['comment'])); ?></p><?php endif; ?>
                        <form method="POST" class="inline-form candidate-admin-card__form">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="candidate_id" value="<?php echo (int)$candidate['id']; ?>">
                            <label class="sr-only" for="candidateStatus<?php echo (int)$candidate['id']; ?>">Статус заявки</label>
                            <select class="form-control" id="candidateStatus<?php echo (int)$candidate['id']; ?>" name="status">
                                <?php foreach ($statuses as $value => $label): ?>
                                    <option value="<?php echo escape($value); ?>" <?php echo $candidate['status'] === $value ? 'selected' : ''; ?>><?php echo escape($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button class="btn btn-primary" type="submit">Сохранить</button>
                        </form>
                    </article>
                <?php endforeach; ?>
                <?php if (!$candidates): ?>
                    <div class="empty-state">Заявок с выбранным статусом пока нет.</div>
                <?php endif; ?>
            </div>
        </div>
    </main>
    <?php include_once __DIR__ . '/../includes/organizer-footer.php'; ?>
</body>
</html>
