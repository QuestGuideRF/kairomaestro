<?php
require_once __DIR__ . '/bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    adminRedirect('candidates.php', $adminRepository->updateCandidateStatus(
        (int)($_POST['candidate_id'] ?? 0),
        (string)($_POST['status'] ?? ''),
        (int)$_SESSION['user_id']
    ));
}
$adminTitle = 'Кандидаты';
$adminSection = 'candidates';
$rows = $adminRepository->candidates();
require_once __DIR__ . '/header.php';
?>
<div class="admin-table-wrap"><table class="admin-table">
<thead><tr><th>Кандидат</th><th>Роль</th><th>Опыт и ставка</th><th>Материалы</th><th>Источник</th><th>Статус</th></tr></thead>
<tbody><?php foreach ($rows as $row): ?><tr>
<td><strong><?php echo escape($row['full_name']); ?></strong><br><small><?php echo escape($row['phone'] ?: ($row['email'] ?: 'Контакт не указан')); ?></small></td>
<td><?php echo escape($row['desired_role']); ?></td>
<td><?php echo escape($row['experience']); ?><br><?php echo escape($row['desired_salary']); ?></td>
<td>
<?php if (empty($row['files'])): ?>
<span class="admin-muted">Нет файлов</span>
<?php else: ?>
<?php foreach ($row['files'] as $file): ?>
<a class="admin-file-link" href="<?php echo escape(secureFileUrl('candidate', $file['id'])); ?>" target="_blank" rel="noopener"><?php echo escape($file['file_name'] ?: 'Открыть файл'); ?></a>
<?php endforeach; ?>
<?php endif; ?>
</td>
<td><?php echo escape($row['source']); ?></td>
<td><form method="post" class="admin-inline-form"><?php echo csrfField(); ?><input type="hidden" name="candidate_id" value="<?php echo (int)$row['id']; ?>"><label class="sr-only" for="candidate-status-<?php echo (int)$row['id']; ?>">Статус кандидата <?php echo escape($row['full_name']); ?></label><select id="candidate-status-<?php echo (int)$row['id']; ?>" name="status"><?php foreach (CandidateApplication::statuses() as $key => $label): ?><option value="<?php echo escape($key); ?>" <?php echo $row['status'] === $key ? 'selected' : ''; ?>><?php echo escape($label); ?></option><?php endforeach; ?></select><button>Сохранить</button></form></td>
</tr><?php endforeach; ?></tbody>
</table></div>
<?php require_once __DIR__ . '/footer.php'; ?>
