<?php
require_once __DIR__ . '/config/config.php';
if (!isLoggedIn()) {
    redirect('/login.php');
}
$chatObj = new Chat();
$userId = (int)$_SESSION['user_id'];
$isAdmin = currentRole() === 'admin';
$chats = $chatObj->getUserChats($userId);
$chatId = isset($_GET['id']) ? (int)$_GET['id'] : null;
$currentChat = null;
$messages = [];
if ($chatId) {
    foreach ($chats as $chat) {
        if ($chat['id'] == $chatId) {
            $currentChat = $chat;
            break;
        }
    }
    if ($currentChat) {
        $messages = $chatObj->getMessages($chatId, $userId);
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message'])) {
    requireCsrf();
    $message = $_POST['message'] ?? '';
    $chatId = $_POST['chat_id'] ?? 0;
    if ((trim($message) !== '' || !empty($_FILES['file']['name']))
        && $chatId
        && $chatObj->userCanAccessChat((int)$chatId, (int)$userId)) {
        $filePath = null;
        $fileType = null;
        if (!empty($_FILES['file']['name'])) {
            $uploadResult = $chatObj->uploadFile($_FILES['file']);
            if ($uploadResult['success']) {
                $filePath = $uploadResult;
                $fileType = $uploadResult['file_type'];
            }
        }
        $result = $chatObj->sendMessage(
            $chatId,
            $userId,
            $message,
            $filePath,
            $fileType,
            ['reply_to_message_id' => (int)($_POST['reply_to_message_id'] ?? 0)]
        );
        if ($result['success']) {
            redirect("/chat.php?id=$chatId");
        }
    }
}
$chatRoleNames = [
    'admin' => 'Администратор',
    'manager' => 'Менеджер',
    'organizer' => 'Организатор',
    'client' => 'Клиент',
    'florist' => 'Флорист',
    'courier' => 'Курьер',
    'installer' => 'Монтажник',
    'helper' => 'Помощник',
    'worker' => 'Сотрудник',
];
$chatPollSince = date('Y-m-d H:i:s');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Чаты - Азбука Декора</title>
    <?php include_once __DIR__ . '/includes/favicon-links.php'; ?>
    <link rel="stylesheet" href="<?php echo asset('assets/css/style.min.css'); ?>">
</head>
<body>
    <?php
    if (isWorkerRole()) {
        include_once __DIR__ . '/includes/worker-header.php';
    } elseif (isClientRole()) {
        include_once __DIR__ . '/includes/client-header.php';
    } elseif (currentRole() === 'manager') {
        include_once __DIR__ . '/includes/manager-header.php';
    } else {
        include_once __DIR__ . '/includes/organizer-header.php';
    }
    ?>
    <div class="chat-page">
        <button type="button" class="chat-overlay" id="chatOverlay" aria-label="Закрыть список чатов" onclick="document.getElementById('chatSidebar').classList.remove('show'); document.getElementById('chatOverlay').classList.remove('show');"></button>
        <div class="chat-container">
            <div class="chat-sidebar" id="chatSidebar">
                <div class="chat-sidebar-header">
                    <h2>Чаты</h2>
                    <button class="chat-sidebar-close" onclick="toggleChatSidebar()" title="Закрыть">✕</button>
                </div>
                <div class="chat-list">
                    <?php if (empty($chats)): ?>
                    <div class="empty-state">
                        <p>У вас пока нет чатов</p>
                    </div>
                    <?php else: ?>
                        <?php foreach ($chats as $chat): ?>
                        <a href="/chat.php?id=<?php echo $chat['id']; ?>"
                           class="chat-item <?php echo $chatId == $chat['id'] ? 'active' : ''; ?>"
                           onclick="if(window.innerWidth <= 767) { toggleChatSidebar(); }">
                            <div class="chat-item-info">
                                <strong>
                                    <?php
                                    if ($chat['type'] === 'personal' && isset($chat['chat_name'])) {
                                        echo '👤 ' . escape($chat['chat_name']);
                                    } elseif ($chat['type'] === 'event_group' && $chat['event_title']) {
                                        echo '💬 ' . escape($chat['event_title']) . ' (команда)';
                                    } elseif (isset($chat['chat_name'])) {
                                        echo escape($chat['chat_name']);
                                    } elseif ($chat['event_title']) {
                                        echo escape($chat['event_title']);
                                    } else {
                                        echo 'Чат';
                                    }
                                    ?>
                                </strong>
                                <?php if ($chat['last_message']): ?>
                                <p><?php echo escape(substr($chat['last_message'], 0, 50)) . (strlen($chat['last_message']) > 50 ? '...' : ''); ?></p>
                                <small><?php echo formatRussianDateTime($chat['last_message_time']); ?></small>
                                <?php endif; ?>
                            </div>
                            <?php if ($chat['unread_count'] > 0): ?>
                            <span class="badge"><?php echo $chat['unread_count']; ?></span>
                            <?php endif; ?>
                        </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            <div class="chat-main">
                <?php if ($currentChat): ?>
                <div class="chat-header">
                    <button class="chat-menu-toggle" onclick="toggleChatSidebar()" title="Список чатов">☰</button>
                    <div class="chat-header-info">
                        <h2>
                            <?php
                            if ($currentChat['type'] === 'personal' && isset($currentChat['chat_name'])) {
                                echo '👤 ' . escape($currentChat['chat_name']);
                            } elseif ($currentChat['type'] === 'event_group' && $currentChat['event_title']) {
                                echo '💬 ' . escape($currentChat['event_title']) . ' (команда)';
                            } elseif (isset($currentChat['chat_name'])) {
                                echo escape($currentChat['chat_name']);
                            } elseif ($currentChat['event_title']) {
                                echo escape($currentChat['event_title']);
                            } else {
                                echo 'Чат';
                            }
                            ?>
                        </h2>
                        <?php if ($currentChat['event_date']): ?>
                        <small><?php echo formatRussianDate($currentChat['event_date'], 'date_only'); ?></small>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="chat-messages" id="chatMessages">
                    <?php foreach ($messages as $msg): ?>
                    <?php $isSystemMessage = ($msg['message_type'] ?? 'user') === 'system'; ?>
                    <?php
                    $isDeleted = !empty($msg['deleted_at']);
                    $isOwn = !$isSystemMessage && (int)($msg['user_id'] ?? 0) === $userId;
                    $canMutate = !$isSystemMessage
                        && !$isDeleted
                        && ($isAdmin || ($isOwn && ($msg['source'] ?? 'site') !== 'telegram'));
                    $authorName = $isSystemMessage
                        ? SITE_NAME
                        : trim((string)($msg['first_name'] ?? '') . ' ' . (string)($msg['last_name'] ?? ''));
                    $authorName = $authorName !== '' ? $authorName : 'Участник';
                    $replyAuthor = trim((string)($msg['reply_first_name'] ?? '') . ' ' . (string)($msg['reply_last_name'] ?? ''));
                    $replyAuthor = $replyAuthor !== '' ? $replyAuthor : SITE_NAME;
                    $messageClass = 'message-other';
                    if ($isSystemMessage) {
                        $messageClass = 'message-system';
                    } elseif ($isOwn) {
                        $messageClass = 'message-own';
                    }
                    if ($isDeleted) {
                        $messageClass .= ' message-deleted';
                    }
                    ?>
                    <article id="message-<?php echo (int)$msg['id']; ?>"
                             class="message <?php echo escape($messageClass); ?>"
                             data-message-id="<?php echo (int)$msg['id']; ?>"
                             data-user-id="<?php echo (int)($msg['user_id'] ?? 0); ?>"
                             data-source="<?php echo escape((string)($msg['source'] ?? 'site')); ?>">
                        <div class="message-header">
                            <div>
                                <strong><?php echo escape($authorName); ?></strong>
                                <?php if (!$isSystemMessage && !empty($msg['user_role'])): ?>
                                <span><?php echo escape($chatRoleNames[$msg['user_role']] ?? 'Участник'); ?></span>
                                <?php endif; ?>
                                <?php if (($msg['source'] ?? 'site') === 'telegram'): ?>
                                <span class="message-source">Telegram</span>
                                <?php endif; ?>
                            </div>
                            <small>
                                <?php echo formatRussianDateTime($msg['created_at']); ?>
                                <?php if (!empty($msg['edited_at']) && !$isDeleted): ?>
                                <span class="message-edited">изменено</span>
                                <?php endif; ?>
                            </small>
                        </div>
                        <div class="message-body">
                            <?php if (!empty($msg['reply_to_message_id'])): ?>
                            <button type="button"
                                    class="message-reply-quote"
                                    data-scroll-message="<?php echo (int)$msg['reply_to_message_id']; ?>">
                                <strong><?php echo escape($replyAuthor); ?></strong>
                                <span><?php echo escape(utf8Substring((string)($msg['reply_message'] ?? 'Сообщение удалено'), 0, 180)); ?></span>
                            </button>
                            <?php endif; ?>
                            <div class="message-text"><?php echo nl2br(escape($isDeleted ? 'Сообщение удалено' : (string)$msg['message'])); ?></div>
                            <?php if (!$isDeleted && !empty($msg['file_path'])): ?>
                            <div class="message-file">
                                <?php if (strpos($msg['file_type'], 'image') !== false): ?>
                                <a href="<?php echo escape(secureFileUrl('message', $msg['id'])); ?>" target="_blank" rel="noopener">
                                    <img src="<?php echo escape(secureFileUrl('message', $msg['id'])); ?>" alt="Изображение" loading="lazy">
                                </a>
                                <?php else: ?>
                                <a href="<?php echo escape(secureFileUrl('message', $msg['id'])); ?>" target="_blank" rel="noopener" class="file-link">
                                    📎 Прикрепленный файл
                                </a>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                            <?php if (!$isDeleted && !empty($msg['unsupported_media_type'])): ?>
                            <p class="message-placeholder">
                                Это вложение пока нельзя показать на сайте.
                                <?php if (!empty($msg['telegram_deep_link'])): ?>
                                <a href="<?php echo escape($msg['telegram_deep_link']); ?>" target="_blank" rel="noopener">Открыть в Telegram</a>
                                <?php endif; ?>
                            </p>
                            <?php endif; ?>
                        </div>
                        <?php if (!$isSystemMessage && !$isDeleted): ?>
                        <div class="message-actions">
                            <button type="button"
                                    data-message-reply
                                    data-message-id="<?php echo (int)$msg['id']; ?>"
                                    data-message-author="<?php echo escape($authorName); ?>"
                                    data-message-text="<?php echo escape(utf8Substring((string)$msg['message'], 0, 180)); ?>">
                                Ответить
                            </button>
                            <?php if ($canMutate): ?>
                            <button type="button"
                                    data-message-edit
                                    data-message-id="<?php echo (int)$msg['id']; ?>"
                                    data-message-text="<?php echo escape((string)$msg['message']); ?>">
                                Изменить
                            </button>
                            <button type="button"
                                    data-message-delete
                                    data-message-id="<?php echo (int)$msg['id']; ?>">
                                Удалить
                            </button>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </article>
                    <?php endforeach; ?>
                </div>
                <div class="chat-input">
                    <form method="POST" action="" enctype="multipart/form-data" id="messageForm">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="chat_id" value="<?php echo $chatId; ?>">
                        <input type="hidden" name="send_message" value="1">
                        <input type="hidden" name="reply_to_message_id" id="replyToMessageId" value="">
                        <div class="chat-reply-preview" id="chatReplyPreview" hidden>
                            <div>
                                <strong id="chatReplyAuthor"></strong>
                                <span id="chatReplyText"></span>
                            </div>
                            <button type="button" id="cancelReply" aria-label="Отменить ответ">×</button>
                        </div>
                        <div class="chat-input-group">
                            <label for="file" class="file-btn" title="Прикрепить файл">
                                <span aria-hidden="true">+</span>
                                <input type="file" id="file" name="file" accept="image/*,video/*,.pdf" style="display:none;">
                            </label>
                            <label class="sr-only" for="messageInput">Сообщение</label>
                            <textarea name="message" id="messageInput" class="message-input"
                                      placeholder="Введите сообщение..." rows="1"></textarea>
                            <button type="submit" class="send-btn" aria-label="Отправить сообщение">→</button>
                        </div>
                        <div id="filePreview" class="file-preview"></div>
                        <output id="chatStatus" class="chat-status" aria-live="polite"></output>
                    </form>
                </div>
                <dialog class="message-edit-dialog" id="messageEditDialog">
                    <form method="dialog" id="messageEditForm">
                        <div class="dialog-heading">
                            <h2>Изменить сообщение</h2>
                            <p>Новая версия будет отмечена как изменённая.</p>
                        </div>
                        <input type="hidden" id="editMessageId" value="">
                        <label for="editMessageText">Текст сообщения</label>
                        <textarea id="editMessageText" class="form-control" rows="6" maxlength="10000" required></textarea>
                        <div class="message-edit-actions">
                            <button type="button" class="btn btn-outline" id="cancelEdit">Отмена</button>
                            <button type="submit" class="btn btn-primary">Сохранить</button>
                        </div>
                    </form>
                </dialog>
                <?php else: ?>
                <div class="empty-state">
                    <p>Выберите чат из списка</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php
    if (isWorkerRole()) {
        include_once __DIR__ . '/includes/worker-footer.php';
    } elseif (isClientRole()) {
        include_once __DIR__ . '/includes/client-footer.php';
    } elseif (currentRole() === 'manager') {
        include_once __DIR__ . '/includes/organizer-footer.php';
    } else {
        include_once __DIR__ . '/includes/organizer-footer.php';
    }
    ?>
    <script type="application/json" id="chatConfig"><?php
        echo json_encode([
            'chatId' => (int)$chatId,
            'userId' => $userId,
            'isAdmin' => $isAdmin,
            'siteName' => SITE_NAME,
            'csrfToken' => csrfToken(),
            'pollSince' => $chatPollSince,
            'roles' => $chatRoleNames,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
    ?></script>
    <script src="<?php echo asset('assets/js/chat.js'); ?>" defer></script>
</body>
</html>
