document.addEventListener('DOMContentLoaded', function () {
    const revealTargets = document.querySelectorAll('[data-reveal], .section, .cta-band, .page-title, .page-hero');
    if ('IntersectionObserver' in window) {
        const revealObserver = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    entry.target.classList.add('is-visible');
                    revealObserver.unobserve(entry.target);
                }
            });
        }, { threshold: 0.05, rootMargin: '0px 0px 80px 0px' });
        revealTargets.forEach(function (target) {
            target.classList.add('reveal-on-scroll');
            revealObserver.observe(target);
        });
    } else {
        revealTargets.forEach(function (target) {
            target.classList.add('is-visible');
        });
    }
    const form = document.getElementById('quizForm');
    if (!form || form.dataset.quizVersion === 'visual') return;
    const steps = Array.from(form.querySelectorAll('.quiz-step'));
    const prev = document.getElementById('quizPrev');
    const next = document.getElementById('quizNext');
    const submit = document.getElementById('quizSubmit');
    const progress = document.getElementById('quizProgress');
    const progressLabel = document.getElementById('quizProgressLabel');
    const draftStatus = document.getElementById('quizDraftStatus');
    const draftTokenField = document.getElementById('draftToken');
    const csrfField = form.querySelector('input[name="csrf_token"]');
    let current = 0;
    let saveTimer = null;
    let draftToken = draftTokenField ? draftTokenField.value : '';
    const draftStorageKey = 'azbuka_decor_quiz_draft';
    function showStep(index) {
        steps.forEach((step, i) => step.classList.toggle('active', i === index));
        if (prev) prev.style.display = index === 0 ? 'none' : 'inline-flex';
        if (next) next.style.display = index === steps.length - 1 ? 'none' : 'inline-flex';
        if (submit) submit.style.display = index === steps.length - 1 ? 'inline-flex' : 'none';
        if (progress) {
            progress.style.width = `${((index + 1) / steps.length) * 100}%`;
        }
        if (progressLabel) {
            progressLabel.textContent = `Шаг ${index + 1} из ${steps.length}`;
        }
    }
    function showStepForField(field) {
        const step = field.closest('.quiz-step');
        const index = steps.indexOf(step);
        if (index >= 0 && index !== current) {
            current = index;
            showStep(current);
        }
    }
    function formatDateForMessage(value) {
        if (!value) return '';
        const parts = value.split('-');
        if (parts.length !== 3) return value;
        const date = new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]));
        return date
            .toLocaleDateString('ru-RU', { day: 'numeric', month: 'long', year: 'numeric' })
            .replace(/\s?г\.$/, '');
    }
    function stopOnInvalidField(field, message) {
        if (typeof field.setCustomValidity === 'function') {
            field.setCustomValidity(message);
        }
        showStepForField(field);
        window.setTimeout(function () {
            if (typeof field.reportValidity === 'function') field.reportValidity();
            field.focus();
        }, 0);
        return false;
    }
    function validateStep(index) {
        const controls = Array.from(steps[index].querySelectorAll('input, select, textarea'));
        for (const field of controls) {
            if (typeof field.setCustomValidity === 'function') {
                field.setCustomValidity('');
            }
            const isRequired = field.required || field.dataset.required === 'true';
            const emptyRequired = (field.type === 'checkbox' || field.type === 'radio')
                ? !field.checked
                : !field.value.trim();
            if (isRequired && emptyRequired) {
                return stopOnInvalidField(field, 'Заполните это поле');
            }
            if (field.type === 'email' && field.value && field.validity && field.validity.typeMismatch) {
                return stopOnInvalidField(field, 'Проверьте адрес электронной почты');
            }
            if (field.type === 'date' && field.min && field.value && field.value < field.min) {
                return stopOnInvalidField(
                    field,
                    `Дата мероприятия должна быть не раньше ${formatDateForMessage(field.min)}`
                );
            }
            const minLength = parseInt(field.dataset.minlength || '0', 10);
            if (minLength && field.value && field.value.length < minLength) {
                return stopOnInvalidField(field, `Минимум ${minLength} символов`);
            }
            if (field.type === 'file' && field.files) {
                if (field.files.length > 10) {
                    return stopOnInvalidField(field, 'Можно приложить не больше 10 файлов');
                }
                for (const file of field.files) {
                    if (file.size > 10 * 1024 * 1024) {
                        return stopOnInvalidField(field, 'Каждый файл должен быть не больше 10 МБ');
                    }
                }
            }
        }
        if (index === 0) {
            const method = form.elements.contact_method.value;
            const phone = form.elements.phone;
            const email = form.elements.email;
            const telegram = form.elements.telegram_username;
            if (!phone.value.trim() && !email.value.trim() && !telegram.value.trim()) {
                return stopOnInvalidField(phone, 'Укажите телефон, электронную почту или имя пользователя в Telegram');
            }
            if (['whatsapp', 'call', 'sms'].includes(method) && !phone.value.trim()) return stopOnInvalidField(phone, 'Для выбранного способа связи нужен телефон');
            if (method === 'email' && !email.value.trim()) return stopOnInvalidField(email, 'Укажите адрес электронной почты');
            if (method === 'telegram' && !telegram.value.trim() && !phone.value.trim()) return stopOnInvalidField(telegram, 'Укажите имя пользователя в Telegram или телефон');
        }
        if (index === 1) {
            const service = steps[index].querySelector('input[name="services_needed[]"]');
            const selected = steps[index].querySelector('input[name="services_needed[]"]:checked');
            if (service && !selected) return stopOnInvalidField(service, 'Выберите хотя бы одну нужную услугу');
        }
        return true;
    }
    function validateAllSteps() {
        for (let index = 0; index < steps.length; index += 1) {
            if (!validateStep(index)) {
                return false;
            }
        }
        return true;
    }
    if (prev) {
        prev.addEventListener('click', function () {
            current = Math.max(0, current - 1);
            showStep(current);
        });
    }
    if (next) {
        next.addEventListener('click', function () {
            if (!validateStep(current)) return;
            current = Math.min(steps.length - 1, current + 1);
            showStep(current);
            if (typeof window.trackMetrikaGoal === 'function') {
                window.trackMetrikaGoal(`quiz_step_${current + 1}`);
            }
        });
    }
    form.addEventListener('invalid', function (event) {
        showStepForField(event.target);
    }, true);
    form.addEventListener('submit', function (event) {
        if (!validateAllSteps()) {
            event.preventDefault();
            return;
        }
        if (submit) {
            submit.disabled = true;
            submit.textContent = 'Отправляем...';
        }
    });
    function draftPayload() {
        const data = {};
        const services = [];
        new FormData(form).forEach(function (value, key) {
            if (['password', 'csrf_token', 'submission_key', 'draft_token', 'references[]'].includes(key) || value instanceof File) return;
            if (key === 'services_needed[]') {
                services.push(value);
            } else {
                data[key] = value;
            }
        });
        if (services.length) data.services_needed = services;
        return data;
    }
    function setDraftStatus(message, error) {
        if (!draftStatus) return;
        draftStatus.textContent = message;
        draftStatus.classList.toggle('is-error', Boolean(error));
    }
    function saveDraft() {
        if (!csrfField) return;
        setDraftStatus('Сохраняем черновик...', false);
        fetch('/api/quiz-draft.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrfField.value},
            body: JSON.stringify({token: draftToken, data: draftPayload()})
        }).then(function (response) {
            return response.json().then(function (data) { return {ok: response.ok, data: data}; });
        }).then(function (result) {
            if (!result.ok || !result.data.success) throw new Error(result.data.error || 'Черновик не сохранён');
            draftToken = result.data.token;
            if (draftTokenField) draftTokenField.value = draftToken;
            try { localStorage.setItem(draftStorageKey, draftToken); } catch (error) {}
            setDraftStatus('Черновик сохранён', false);
        }).catch(function () {
            setDraftStatus('Черновик временно не сохраняется', true);
        });
    }
    function scheduleDraftSave(event) {
        if (event && event.target && (event.target.type === 'password' || event.target.type === 'file')) return;
        window.clearTimeout(saveTimer);
        saveTimer = window.setTimeout(saveDraft, 900);
    }
    function applyDraft(data) {
        Object.keys(data || {}).forEach(function (key) {
            if (key === 'services_needed' && Array.isArray(data[key])) {
                form.querySelectorAll('input[name="services_needed[]"]').forEach(function (field) {
                    field.checked = data[key].includes(field.value);
                });
                return;
            }
            const field = form.elements.namedItem(key);
            if (!field || field.type === 'password' || field.type === 'file') return;
            if (field.type === 'checkbox') field.checked = Boolean(data[key]);
            else field.value = data[key] == null ? '' : data[key];
        });
    }
    if (!draftToken) {
        try { draftToken = localStorage.getItem(draftStorageKey) || ''; } catch (error) {}
        if (draftToken) {
            fetch('/api/quiz-draft.php?token=' + encodeURIComponent(draftToken), {credentials: 'same-origin'})
                .then(function (response) { return response.json().then(function (data) { return {ok: response.ok, data: data}; }); })
                .then(function (result) {
                    if (!result.ok || !result.data.success) throw new Error('Черновик устарел');
                    applyDraft(result.data.data);
                    if (draftTokenField) draftTokenField.value = draftToken;
                    setDraftStatus('Незавершённый квиз восстановлен', false);
                }).catch(function () {
                    try { localStorage.removeItem(draftStorageKey); } catch (error) {}
                    draftToken = '';
                });
        }
    }
    form.addEventListener('input', scheduleDraftSave);
    form.addEventListener('change', scheduleDraftSave);
    showStep(current);
});
