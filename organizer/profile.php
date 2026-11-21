<?php
require_once __DIR__ . '/../config/config.php';
requireCrmPanel(['organizer', 'admin']);
$userObj = new User();
$userId = $_SESSION['user_id'];
$user = $userObj->getUserById($userId);
if (!$user) {
    redirect('/organizer/dashboard.php');
}
$error = '';
$success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_avatar'])) {
    requireCsrf();
    if (!empty($_FILES['avatar']['name'])) {
        $result = $userObj->uploadAvatar($_FILES['avatar']);
        if ($result['success']) {
            $updateResult = $userObj->updateProfile($userId, ['avatar' => $result['filename']]);
            if ($updateResult['success']) {
                $success = 'Фото профиля успешно загружено';
                $user = $userObj->getUserById($userId);
            } else {
                $error = 'Ошибка при сохранении фото: ' . $updateResult['error'];
            }
        } else {
            $error = $result['error'];
        }
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    requireCsrf();
    $data = [
        'first_name' => $_POST['first_name'] ?? '',
        'last_name' => $_POST['last_name'] ?? '',
        'middle_name' => $_POST['middle_name'] ?? ''
    ];
    if (empty($data['first_name']) || empty($data['last_name'])) {
        $error = 'Имя и фамилия обязательны';
    } else {
        $result = $userObj->updateProfile($userId, $data);
        if ($result['success']) {
            $success = 'Профиль успешно обновлен';
            $user = $userObj->getUserById($userId);
            $_SESSION['user_name'] = $data['first_name'] . ' ' . $data['last_name'];
        } else {
            $error = $result['error'];
        }
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    requireCsrf();
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
        $error = 'Заполните все поля для смены пароля';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'Новые пароли не совпадают';
    } elseif (utf8Length($newPassword) < 10) {
        $error = 'Пароль должен содержать минимум 10 символов';
    } else {
        $result = $userObj->changePassword($userId, $currentPassword, $newPassword, $confirmPassword);
        $success = $result['success'] ? 'Пароль успешно изменён' : '';
        $error = $result['success'] ? '' : $result['error'];
    }
}
$publicTeamService = new PublicTeamProfile();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'public_team_profile') {
    requireCsrf();
    $result = $publicTeamService->saveOwn((int)$userId, $_POST);
    $success = $result['success'] ? 'Публичный профиль сохранён.' : '';
    $error = $result['success'] ? '' : $result['error'];
}
$publicTeamProfile = $publicTeamService->getForUser((int)$userId);
$publicTeamFallback = [
    'public_role' => 'Организатор',
    'specialization' => 'Организация и координация событий',
    'public_description' => '',
    'public_quote' => '',
    'experience_years' => 0,
];
$avatarUrl = '';
if (!empty($user['avatar'])) {
    $avatarPath = UPLOAD_DIR . 'avatars/' . $user['avatar'];
    if (file_exists($avatarPath)) {
        $avatarUrl = UPLOAD_URL . 'avatars/' . $user['avatar'];
    }
}
if (empty($avatarUrl)) {
    $initial = utf8Upper(utf8Substring($user['first_name'] ?? 'O', 0, 1));
    $avatarUrl = 'data:image/svg+xml;base64,' . base64_encode(
        '<svg xmlns="http://www.w3.org/2000/svg" width="150" height="150"><rect width="150" height="150" fill="#10b981"/><text x="50%" y="50%" font-size="60" fill="white" text-anchor="middle" dominant-baseline="central" font-family="Arial, sans-serif" font-weight="bold">' . htmlspecialchars($initial) . '</text></svg>'
    );
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Профиль - Азбука Декора</title>
    <?php include_once __DIR__ . '/../includes/favicon-links.php'; ?>
    <link rel="stylesheet" href="<?php echo asset('assets/css/style.min.css'); ?>">
    <style>
        .profile-page {
            padding: 2rem 0;
        }
        .profile-header {
            display: flex;
            flex-direction: column;
            align-items: center;
            margin-bottom: 2rem;
            padding: 2rem;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 1rem;
        }
        @media (min-width: 768px) {
            .profile-header {
                flex-direction: row;
                align-items: flex-start;
            }
        }
        .avatar-container {
            position: relative;
            margin-bottom: 1rem;
        }
        @media (min-width: 768px) {
            .avatar-container {
                margin-right: 2rem;
                margin-bottom: 0;
            }
        }
        .avatar-preview {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid var(--primary-color);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }
        .avatar-upload-btn {
            position: absolute;
            bottom: 0;
            right: 0;
            width: 45px;
            height: 45px;
            border-radius: 50%;
            background: var(--primary-color);
            color: white;
            border: 3px solid var(--card-bg);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            transition: all 0.2s;
        }
        .avatar-upload-btn:hover {
            background: var(--secondary-color);
            transform: scale(1.1);
        }
        .profile-info h1 {
            margin-bottom: 0.5rem;
        }
        .profile-info p {
            color: var(--text-muted);
            margin-bottom: 0.25rem;
        }
        .profile-section {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            padding: 2rem;
            border-radius: 1rem;
            margin-bottom: 2rem;
        }
        .profile-section h2 {
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid var(--border-color);
        }
        .form-row {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1rem;
        }
        @media (min-width: 768px) {
            .form-row {
                grid-template-columns: repeat(2, 1fr);
            }
        }
    </style>
</head>
<body>
    <?php include_once __DIR__ . '/../includes/organizer-header.php'; ?>
    <div class="profile-page">
        <div class="container">
            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo escape($error); ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="alert alert-success"><?php echo escape($success); ?></div>
            <?php endif; ?>
            <div class="profile-header">
                <div class="avatar-container">
                    <img src="<?php echo escape($avatarUrl); ?>" alt="Аватар" class="avatar-preview" id="avatarPreview">
                    <form method="POST" enctype="multipart/form-data" style="display: none;" id="avatarForm">
                        <?php echo csrfField(); ?>
                        <input type="file" name="avatar" id="avatarFileInput" accept="image/jpeg,image/jpg,image/png,image/webp" onchange="uploadAvatar(this)">
                        <input type="hidden" name="upload_avatar" value="1">
                    </form>
                    <label for="avatarFileInput" class="avatar-upload-btn" title="Загрузить фото">
                        📷
                    </label>
                </div>
                <div class="profile-info">
                    <h1><?php echo escape($user['first_name'] . ' ' . $user['last_name']); ?></h1>
                    <p><strong>Телефон:</strong> <?php echo escape($user['phone']); ?></p>
                    <p><strong>Роль:</strong> Организатор</p>
                </div>
            </div>
            <div class="profile-section">
                <h2>Основная информация</h2>
                <form method="POST">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="update_profile" value="1">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="first_name">Имя *</label>
                            <input type="text" id="first_name" name="first_name" class="form-control"
                                   value="<?php echo escape($user['first_name'] ?? ''); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="last_name">Фамилия *</label>
                            <input type="text" id="last_name" name="last_name" class="form-control"
                                   value="<?php echo escape($user['last_name'] ?? ''); ?>" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="middle_name">Отчество</label>
                        <input type="text" id="middle_name" name="middle_name" class="form-control"
                               value="<?php echo escape($user['middle_name'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label for="phone">Номер телефона</label>
                        <input type="tel" id="phone" name="phone" class="form-control"
                               value="<?php echo escape($user['phone'] ?? ''); ?>" disabled>
                        <small>Для изменения номера телефона обратитесь в поддержку</small>
                    </div>
                    <button type="submit" class="btn btn-primary">Сохранить изменения</button>
                </form>
            </div>
            <?php if (dbTableExists('public_team_profiles')): ?>
                <?php include __DIR__ . '/../includes/public-team-profile-form.php'; ?>
            <?php endif; ?>
            <div class="profile-section">
                <h2>Смена пароля</h2>
                <form method="POST">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="change_password" value="1">
                    <div class="form-group">
                        <label for="current_password">Текущий пароль *</label>
                        <input type="password" id="current_password" name="current_password" class="form-control" required>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="new_password">Новый пароль *</label>
                            <input type="password" id="new_password" name="new_password" class="form-control"
                                   minlength="10" required autocomplete="new-password">
                        </div>
                        <div class="form-group">
                            <label for="confirm_password">Подтвердите новый пароль *</label>
                            <input type="password" id="confirm_password" name="confirm_password" class="form-control"
                                   minlength="10" required autocomplete="new-password">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary">Изменить пароль</button>
                </form>
            </div>
        </div>
    </div>
    <?php include_once __DIR__ . '/../includes/organizer-footer.php'; ?>
    <script>
        function uploadAvatar(input) {
            if (input.files && input.files[0]) {
                if (input.files[0].size > 5 * 1024 * 1024) {
                    alert('Файл слишком большой. Максимальный размер: 5MB');
                    input.value = '';
                    return;
                }
                const reader = new FileReader();
                reader.onload = function(e) {
                    document.getElementById('avatarPreview').src = e.target.result;
                };
                reader.readAsDataURL(input.files[0]);
                setTimeout(function() {
                    document.getElementById('avatarForm').submit();
                }, 100);
            }
        }
    </script>
</body>
</html>
