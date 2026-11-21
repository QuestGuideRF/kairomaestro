(function () {
    'use strict';
    var configNode = document.getElementById('chatConfig');
    var chatMessages = document.getElementById('chatMessages');
    if (!configNode || !chatMessages) {
        return;
    }
    var config;
    try {
        config = JSON.parse(configNode.textContent || '{}');
    } catch (error) {
        return;
    }
    var messageForm = document.getElementById('messageForm');
    var messageInput = document.getElementById('messageInput');
    var fileInput = document.getElementById('file');
    var filePreview = document.getElementById('filePreview');
    var chatStatus = document.getElementById('chatStatus');
    var replyInput = document.getElementById('replyToMessageId');
    var replyPreview = document.getElementById('chatReplyPreview');
    var replyAuthor = document.getElementById('chatReplyAuthor');
    var replyText = document.getElementById('chatReplyText');
    var editDialog = document.getElementById('messageEditDialog');
    var editForm = document.getElementById('messageEditForm');
    var editId = document.getElementById('editMessageId');
    var editText = document.getElementById('editMessageText');
    var pollSince = config.pollSince || '';
    var polling = false;
    var previewUrl = '';
    function element(tag, className, text) {
        var node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (text !== undefined && text !== null) {
            node.textContent = String(text);
        }
        return node;
    }
    function setStatus(message, isError) {
        if (!chatStatus) {
            return;
        }
        chatStatus.textContent = message || '';
        chatStatus.classList.toggle('is-error', Boolean(isError));
    }
    function formatDate(value) {
        if (!value) {
            return '';
        }
        var parsed = new Date(String(value).replace(' ', 'T'));
        if (Number.isNaN(parsed.getTime())) {
            return String(value);
        }
        return new Intl.DateTimeFormat('ru-RU', {
            day: 'numeric',
            month: 'long',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        }).format(parsed);
    }
    function authorName(message, systemMessage) {
        if (systemMessage) {
            return config.siteName || 'Азбука Декора';
        }
        var name = [message.first_name || '', message.last_name || ''].join(' ').trim();
        return name || 'Участник';
    }
    function messageDownloadUrl(messageId) {
        return '/download.php?type=message&id=' + encodeURIComponent(String(messageId));
    }
    function buildReplyQuote(message) {
        if (!Number(message.reply_to_message_id)) {
            return null;
        }
        var quote = element('button', 'message-reply-quote');
        quote.type = 'button';
        quote.dataset.scrollMessage = String(message.reply_to_message_id);
        var name = [message.reply_first_name || '', message.reply_last_name || ''].join(' ').trim();
        quote.appendChild(element('strong', '', name || config.siteName || 'Азбука Декора'));
        quote.appendChild(element('span', '', message.reply_message || 'Сообщение удалено'));
        return quote;
    }
    function buildAttachment(message) {
        if (!message.file_path || message.deleted_at) {
            return null;
        }
        var container = element('div', 'message-file');
        var link = element('a', (message.file_type || '').indexOf('image') >= 0 ? '' : 'file-link');
        link.href = messageDownloadUrl(message.id);
        link.target = '_blank';
        link.rel = 'noopener';
        if ((message.file_type || '').indexOf('image') >= 0) {
            var image = element('img');
            image.src = link.href;
            image.alt = 'Изображение из чата';
            image.loading = 'lazy';
            link.appendChild(image);
        } else {
            link.textContent = 'Открыть прикреплённый файл';
        }
        container.appendChild(link);
        return container;
    }
    function buildActions(message, systemMessage, deleted, name) {
        if (systemMessage || deleted) {
            return null;
        }
        var actions = element('div', 'message-actions');
        var reply = element('button', '', 'Ответить');
        reply.type = 'button';
        reply.dataset.messageReply = '';
        reply.dataset.messageId = String(message.id);
        reply.dataset.messageAuthor = name;
        reply.dataset.messageText = String(message.message || '').slice(0, 180);
        actions.appendChild(reply);
        var own = Number(message.user_id) === Number(config.userId);
        var canMutate = Boolean(config.isAdmin)
            || (own && (message.source || 'site') !== 'telegram');
        if (canMutate) {
            var edit = element('button', '', 'Изменить');
            edit.type = 'button';
            edit.dataset.messageEdit = '';
            edit.dataset.messageId = String(message.id);
            edit.dataset.messageText = String(message.message || '');
            actions.appendChild(edit);
            var remove = element('button', '', 'Удалить');
            remove.type = 'button';
            remove.dataset.messageDelete = '';
            remove.dataset.messageId = String(message.id);
            actions.appendChild(remove);
        }
        return actions;
    }
    function renderMessage(message) {
        var systemMessage = (message.message_type || 'user') === 'system';
        var own = !systemMessage && Number(message.user_id) === Number(config.userId);
        var deleted = Boolean(message.deleted_at);
        var name = authorName(message, systemMessage);
        var wrapper = element(
            'article',
            'message ' + (systemMessage ? 'message-system' : (own ? 'message-own' : 'message-other'))
                + (deleted ? ' message-deleted' : '')
        );
        wrapper.id = 'message-' + Number(message.id);
        wrapper.dataset.messageId = String(message.id);
        wrapper.dataset.userId = String(Number(message.user_id) || 0);
        wrapper.dataset.source = message.source || 'site';
        var header = element('div', 'message-header');
        var identity = element('div');
        identity.appendChild(element('strong', '', name));
        if (!systemMessage && message.user_role) {
            identity.appendChild(element('span', '', config.roles[message.user_role] || 'Участник'));
        }
        if ((message.source || 'site') === 'telegram') {
            identity.appendChild(element('span', 'message-source', 'Telegram'));
        }
        header.appendChild(identity);
        var time = element('small', '', formatDate(message.created_at));
        if (message.edited_at && !deleted) {
            time.appendChild(element('span', 'message-edited', 'изменено'));
        }
        header.appendChild(time);
        wrapper.appendChild(header);
        var body = element('div', 'message-body');
        var quote = buildReplyQuote(message);
        if (quote) {
            body.appendChild(quote);
        }
        body.appendChild(element('div', 'message-text', deleted ? 'Сообщение удалено' : (message.message || '')));
        var attachment = buildAttachment(message);
        if (attachment) {
            body.appendChild(attachment);
        }
        if (!deleted && message.unsupported_media_type) {
            var placeholder = element('p', 'message-placeholder', 'Это вложение пока нельзя показать на сайте. ');
            if (message.telegram_deep_link
                && String(message.telegram_deep_link).indexOf('https://t.me/') === 0) {
                var telegramLink = element('a', '', 'Открыть в Telegram');
                telegramLink.href = message.telegram_deep_link;
                telegramLink.target = '_blank';
                telegramLink.rel = 'noopener';
                placeholder.appendChild(telegramLink);
            }
            body.appendChild(placeholder);
        }
        wrapper.appendChild(body);
        var actions = buildActions(message, systemMessage, deleted, name);
        if (actions) {
            wrapper.appendChild(actions);
        }
        return wrapper;
    }
    function upsertMessage(message, appendOnly) {
        if (!message || !Number(message.id)) {
            return;
        }
        var current = document.getElementById('message-' + Number(message.id));
        if (current) {
            if (!appendOnly) {
                current.replaceWith(renderMessage(message));
            }
            return;
        }
        chatMessages.appendChild(renderMessage(message));
    }
    function maxMessageId() {
        var max = 0;
        chatMessages.querySelectorAll('[data-message-id]').forEach(function (node) {
            max = Math.max(max, Number(node.dataset.messageId) || 0);
        });
        return max;
    }
    function clearReply() {
        if (replyInput) {
            replyInput.value = '';
        }
        if (replyPreview) {
            replyPreview.hidden = true;
        }
    }
    function clearFile() {
        if (previewUrl) {
            URL.revokeObjectURL(previewUrl);
            previewUrl = '';
        }
        if (fileInput) {
            fileInput.value = '';
        }
        if (filePreview) {
            filePreview.replaceChildren();
        }
    }
    async function readJson(response) {
        var data;
        try {
            data = await response.json();
        } catch (error) {
            throw new Error('Сервер вернул некорректный ответ');
        }
        if (!response.ok || !data.success) {
            throw new Error(data.error || 'Операция не выполнена');
        }
        return data;
    }
    async function runMessageAction(action, messageId, message) {
        var payload = new FormData();
        payload.set('csrf_token', config.csrfToken || '');
        payload.set('action', action);
        payload.set('message_id', String(messageId));
        if (message !== undefined) {
            payload.set('message', message);
        }
        var response = await fetch('/api/message-action.php', {
            method: 'POST',
            body: payload,
            credentials: 'same-origin'
        });
        return readJson(response);
    }
    async function pollMessages() {
        if (polling || !Number(config.chatId) || document.hidden) {
            return;
        }
        polling = true;
        try {
            var query = new URLSearchParams({
                chat_id: String(config.chatId),
                last_id: String(maxMessageId()),
                since: pollSince
            });
            var response = await fetch('/api/get-new-messages.php?' + query.toString(), {
                credentials: 'same-origin',
                cache: 'no-store'
            });
            var data = await readJson(response);
            var shouldFollow = chatMessages.scrollHeight - chatMessages.scrollTop - chatMessages.clientHeight < 120;
            (data.messages || []).forEach(function (message) {
                upsertMessage(message, true);
            });
            (data.changed || []).forEach(function (message) {
                upsertMessage(message, false);
            });
            pollSince = data.server_time || pollSince;
            if (shouldFollow && (data.messages || []).length) {
                chatMessages.scrollTop = chatMessages.scrollHeight;
            }
        } catch (error) {
            setStatus(error.message, true);
        } finally {
            polling = false;
        }
    }
    window.toggleChatSidebar = function () {
        var sidebar = document.getElementById('chatSidebar');
        var overlay = document.getElementById('chatOverlay');
        if (sidebar && overlay) {
            sidebar.classList.toggle('show');
            overlay.classList.toggle('show');
        }
    };
    chatMessages.addEventListener('click', function (event) {
        var scrollButton = event.target.closest('[data-scroll-message]');
        if (scrollButton) {
            var target = document.getElementById('message-' + Number(scrollButton.dataset.scrollMessage));
            if (target) {
                target.scrollIntoView({ behavior: 'smooth', block: 'center' });
                target.classList.add('message-highlight');
                window.setTimeout(function () {
                    target.classList.remove('message-highlight');
                }, 1500);
            }
            return;
        }
        var replyButton = event.target.closest('[data-message-reply]');
        if (replyButton && replyInput && replyPreview) {
            replyInput.value = replyButton.dataset.messageId || '';
            replyAuthor.textContent = replyButton.dataset.messageAuthor || 'Участник';
            replyText.textContent = replyButton.dataset.messageText || 'Вложение';
            replyPreview.hidden = false;
            messageInput.focus();
            return;
        }
        var editButton = event.target.closest('[data-message-edit]');
        if (editButton && editDialog) {
            editId.value = editButton.dataset.messageId || '';
            editText.value = editButton.dataset.messageText || '';
            editDialog.showModal();
            editText.focus();
            return;
        }
        var deleteButton = event.target.closest('[data-message-delete]');
        if (deleteButton && window.confirm('Удалить это сообщение?')) {
            setStatus('Удаляем сообщение…', false);
            runMessageAction('delete', Number(deleteButton.dataset.messageId))
                .then(function () {
                    setStatus('', false);
                    return pollMessages();
                })
                .catch(function (error) {
                    setStatus(error.message, true);
                });
        }
    });
    if (document.getElementById('cancelReply')) {
        document.getElementById('cancelReply').addEventListener('click', clearReply);
    }
    if (document.getElementById('cancelEdit')) {
        document.getElementById('cancelEdit').addEventListener('click', function () {
            editDialog.close();
        });
    }
    if (editForm) {
        editForm.addEventListener('submit', function (event) {
            event.preventDefault();
            setStatus('Сохраняем изменения…', false);
            runMessageAction('edit', Number(editId.value), editText.value)
                .then(function () {
                    editDialog.close();
                    setStatus('', false);
                    return pollMessages();
                })
                .catch(function (error) {
                    setStatus(error.message, true);
                });
        });
    }
    if (messageInput) {
        messageInput.addEventListener('input', function () {
            this.style.height = 'auto';
            this.style.height = this.scrollHeight + 'px';
        });
    }
    if (fileInput) {
        fileInput.addEventListener('change', function () {
            var file = this.files && this.files[0];
            if (previewUrl) {
                URL.revokeObjectURL(previewUrl);
                previewUrl = '';
            }
            filePreview.replaceChildren();
            if (!file) {
                return;
            }
            var summary = element('span', '', file.name);
            if (file.type.indexOf('image/') === 0) {
                previewUrl = URL.createObjectURL(file);
                var image = element('img');
                image.src = previewUrl;
                image.alt = 'Предпросмотр вложения';
                filePreview.appendChild(image);
            }
            filePreview.appendChild(summary);
            var clear = element('button', '', 'Удалить вложение');
            clear.type = 'button';
            clear.addEventListener('click', clearFile);
            filePreview.appendChild(clear);
        });
    }
    if (messageForm) {
        messageForm.addEventListener('submit', async function (event) {
            event.preventDefault();
            var submit = messageForm.querySelector('[type="submit"]');
            if (submit) {
                submit.disabled = true;
            }
            setStatus('Отправляем…', false);
            try {
                var response = await fetch('/api/send-message.php', {
                    method: 'POST',
                    body: new FormData(messageForm),
                    credentials: 'same-origin'
                });
                var data = await readJson(response);
                if (data.message) {
                    upsertMessage(data.message, true);
                }
                messageInput.value = '';
                messageInput.style.height = 'auto';
                clearFile();
                clearReply();
                setStatus('', false);
                chatMessages.scrollTop = chatMessages.scrollHeight;
            } catch (error) {
                setStatus(error.message, true);
            } finally {
                if (submit) {
                    submit.disabled = false;
                }
            }
        });
    }
    chatMessages.scrollTop = chatMessages.scrollHeight;
    window.setInterval(pollMessages, 4000);
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) {
            pollMessages();
        }
    });
}());
