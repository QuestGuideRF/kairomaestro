(function () {
    'use strict';

    const page = document.querySelector('[data-telegram-auth]');
    if (!page) {
        return;
    }

    const status = document.getElementById('telegramAuthStatus');
    const message = page.querySelector('[data-telegram-auth-message]');
    const button = document.getElementById('checkTelegramAuthStatus');
    const copyButton = page.querySelector('[data-telegram-auth-command]');
    const statusUrl = page.getAttribute('data-status-url');
    const successText = page.getAttribute('data-success-text') || 'Подтверждение получено.';
    let checking = false;
    let finished = false;

    function setMessage(text, state) {
        if (message) {
            message.textContent = text;
        }
        if (status) {
            status.setAttribute('data-state', state);
        }
    }

    async function checkTelegramAuth() {
        if (checking || finished || !statusUrl) {
            return;
        }
        checking = true;
        try {
            const response = await fetch(statusUrl, {
                credentials: 'same-origin',
                headers: {'Accept': 'application/json'},
                cache: 'no-store'
            });
            const result = await response.json();
            if (result.complete && result.redirect) {
                finished = true;
                setMessage(successText, 'success');
                window.location.replace(result.redirect);
                return;
            }
            if (result.error) {
                setMessage(result.error, 'error');
                if (result.terminal) {
                    finished = true;
                }
                return;
            }
            setMessage('Подтверждение пока не получено.', 'pending');
        } catch (error) {
            setMessage('Не удалось проверить статус. Повторите попытку.', 'error');
        } finally {
            checking = false;
        }
    }

    if (button) {
        button.addEventListener('click', checkTelegramAuth);
    }

    if (copyButton) {
        copyButton.addEventListener('click', async function () {
            const command = copyButton.getAttribute('data-telegram-auth-command') || '';
            const label = copyButton.querySelector('[data-telegram-auth-copy-label]');
            if (!command) {
                return;
            }
            try {
                await navigator.clipboard.writeText(command);
                if (label) {
                    label.textContent = 'Команда скопирована';
                    window.setTimeout(function () {
                        label.textContent = 'Скопировать команду';
                    }, 2200);
                }
            } catch (error) {
                setMessage('Не удалось скопировать команду. Откройте ссылку Telegram ещё раз.', 'error');
            }
        });
    }

    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') {
            checkTelegramAuth();
        }
    });
    window.addEventListener('focus', checkTelegramAuth);

    window.setInterval(function () {
        if (document.visibilityState === 'visible') {
            checkTelegramAuth();
        }
    }, 2500);
}());
