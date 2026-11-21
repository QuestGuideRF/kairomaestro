(function () {
    'use strict';

    const root = document.documentElement;
    const themeButton = document.getElementById('passwordSetupThemeToggle');

    function currentTheme() {
        return root.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
    }

    function updateThemeButton() {
        if (!themeButton) {
            return;
        }
        const nextTheme = currentTheme() === 'dark' ? 'светлую' : 'тёмную';
        themeButton.setAttribute('aria-label', `Включить ${nextTheme} тему`);
    }

    if (themeButton) {
        updateThemeButton();
        themeButton.addEventListener('click', function () {
            const nextTheme = currentTheme() === 'dark' ? 'light' : 'dark';
            root.setAttribute('data-theme', nextTheme);
            try {
                localStorage.setItem('theme', nextTheme);
            } catch (error) {
            }
            updateThemeButton();
        });
    }

    document.querySelectorAll('[data-password-toggle]').forEach(function (button) {
        const input = document.getElementById(button.dataset.passwordToggle || '');
        if (!(input instanceof HTMLInputElement)) {
            return;
        }
        button.addEventListener('click', function () {
            const shouldShow = input.type === 'password';
            input.type = shouldShow ? 'text' : 'password';
            button.setAttribute('aria-pressed', shouldShow ? 'true' : 'false');
            button.setAttribute(
                'aria-label',
                `${shouldShow ? 'Скрыть' : 'Показать'} ${
                    input.id === 'password' ? 'новый пароль' : 'повтор пароля'
                }`
            );
            input.focus({ preventScroll: true });
        });
    });

    const form = document.getElementById('passwordSetupForm');
    const password = document.getElementById('password');
    const confirmation = document.getElementById('password_confirmation');
    if (!(form instanceof HTMLFormElement)
        || !(password instanceof HTMLInputElement)
        || !(confirmation instanceof HTMLInputElement)) {
        return;
    }

    const passwordError = document.getElementById('passwordError');
    const confirmationError = document.getElementById('passwordConfirmationError');
    const lengthCheck = document.querySelector('[data-password-check="length"]');
    const matchCheck = document.querySelector('[data-password-check="match"]');
    const submitButton = form.querySelector('button[type="submit"]');
    let submitted = false;
    let passwordTouched = password.getAttribute('aria-invalid') === 'true';
    let confirmationTouched = confirmation.getAttribute('aria-invalid') === 'true';

    function characterLength(value) {
        return Array.from(value).length;
    }

    function setError(input, output, message, touched) {
        if (output) {
            output.textContent = touched ? message : '';
        }
        input.setAttribute('aria-invalid', touched && message ? 'true' : 'false');
    }

    function validateFields() {
        const passwordLength = characterLength(password.value);
        const lengthIsValid = passwordLength >= 10 && passwordLength <= 1024;
        const valuesMatch = confirmation.value !== ''
            && password.value === confirmation.value;
        const lengthMessage = password.value !== '' && !lengthIsValid
            ? 'Пароль должен содержать от 10 до 1024 символов.'
            : '';
        const matchMessage = confirmation.value !== '' && !valuesMatch
            ? 'Пароли не совпадают.'
            : '';

        password.setCustomValidity(lengthMessage);
        confirmation.setCustomValidity(matchMessage);
        setError(password, passwordError, lengthMessage, passwordTouched);
        setError(
            confirmation,
            confirmationError,
            matchMessage,
            confirmationTouched
        );

        if (lengthCheck) {
            lengthCheck.classList.toggle('is-valid', lengthIsValid);
        }
        if (matchCheck) {
            matchCheck.classList.toggle('is-valid', valuesMatch);
        }
    }

    password.addEventListener('input', function () {
        passwordTouched = true;
        validateFields();
    });
    confirmation.addEventListener('input', function () {
        confirmationTouched = true;
        validateFields();
    });
    password.addEventListener('blur', function () {
        passwordTouched = true;
        validateFields();
    });
    confirmation.addEventListener('blur', function () {
        confirmationTouched = true;
        validateFields();
    });

    form.addEventListener('submit', function (event) {
        passwordTouched = true;
        confirmationTouched = true;
        validateFields();
        if (!form.checkValidity()) {
            event.preventDefault();
            form.reportValidity();
            return;
        }
        if (submitted) {
            event.preventDefault();
            return;
        }
        submitted = true;
        if (submitButton instanceof HTMLButtonElement) {
            submitButton.disabled = true;
            const label = submitButton.querySelector('[data-submit-label]');
            const loading = submitButton.querySelector('[data-loading-label]');
            if (label) {
                label.hidden = true;
            }
            if (loading) {
                loading.hidden = false;
            }
        }
    });

    validateFields();
})();
