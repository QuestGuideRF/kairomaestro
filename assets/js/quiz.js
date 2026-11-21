document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('quizForm');
    if (!form || form.dataset.quizVersion !== 'visual') return;

    const steps = Array.from(form.querySelectorAll('.quiz-step'));
    const stageItems = Array.from(form.querySelectorAll('[data-quiz-stage]'));
    const previousButton = document.getElementById('quizPrev');
    const nextButton = document.getElementById('quizNext');
    const submitButton = document.getElementById('quizSubmit');
    const progress = document.getElementById('quizProgress');
    const progressCounter = document.getElementById('quizProgressCounter');
    const progressTitle = document.getElementById('quizProgressTitle');
    const draftStatus = document.getElementById('quizDraftStatus');
    const errorSummary = document.getElementById('quizErrorSummary');
    const draftTokenField = document.getElementById('draftToken');
    const currentStepField = form.elements.namedItem('quiz_current_step');
    const csrfField = form.querySelector('input[name="csrf_token"]');
    const fileInput = document.getElementById('references');
    const filePreview = document.getElementById('quizFilePreview');
    const reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const draftStorageKey = 'azbuka_decor_quiz_draft';
    let current = Math.max(0, Math.min(steps.length - 1, Number(currentStepField?.value || 0)));
    let draftToken = draftTokenField ? draftTokenField.value : '';
    let saveTimer = null;
    let selectedFiles = [];

    function fieldsByName(name) {
        return Array.from(form.querySelectorAll(`[name="${name}"], [name="${name}[]"]`));
    }

    function checkedValues(name) {
        return fieldsByName(name).filter((field) => field.checked && !field.disabled).map((field) => field.value);
    }

    function checkedValue(name) {
        return checkedValues(name)[0] || '';
    }

    function isWeddingEvent() {
        return ['Свадьба', 'Камерная свадьба', 'Выездная церемония'].includes(checkedValue('event_type_choice'));
    }

    function hasFloralService() {
        return checkedValues('services_needed').some((value) => ['декор', 'флористика', 'выездная церемония'].includes(value));
    }

    function conditionIsMet(condition) {
        const zones = checkedValues('decor_zones');
        const booked = checkedValues('already_booked');
        const conditions = {
            'event-other': checkedValue('event_type_choice') === 'Другое',
            'event-time-known': checkedValue('event_time_status') === 'known',
            'venue-booked': checkedValue('venue_status') === 'booked',
            'venue-options': checkedValue('venue_status') === 'options',
            'wedding-event': isWeddingEvent(),
            'ceremony-yes': isWeddingEvent() && checkedValue('ceremony_planned') === 'yes',
            'floral-service': hasFloralService(),
            'guest-tables': zones.includes('guest_tables'),
            'presidium': zones.includes('presidium'),
            'photo-zone': zones.includes('photo_zone'),
            'already-other': booked.includes('other'),
            'source-other': checkedValue('source') === 'other',
        };
        return Boolean(conditions[condition]);
    }

    function clearControl(field) {
        if (field.type === 'checkbox' || field.type === 'radio') {
            field.checked = false;
        } else if (!['hidden', 'file'].includes(field.type)) {
            field.value = '';
        }
    }

    function updateConditions(clearHiddenValues = true) {
        const conditionalBlocks = Array.from(form.querySelectorAll('[data-condition]'));
        conditionalBlocks.forEach((block) => {
            block.hidden = !conditionIsMet(block.dataset.condition);
        });
        conditionalBlocks.forEach((block) => {
            const effectivelyHidden = block.hidden || Boolean(block.parentElement?.closest('[data-condition][hidden]'));
            block.querySelectorAll('input, select, textarea').forEach((field) => {
                field.disabled = effectivelyHidden;
                if (effectivelyHidden && clearHiddenValues) clearControl(field);
            });
        });
    }

    function clearErrors(scope = form) {
        scope.querySelectorAll('.quiz-field-error').forEach((error) => error.remove());
        scope.querySelectorAll('[aria-invalid="true"]').forEach((field) => field.removeAttribute('aria-invalid'));
        if (errorSummary) {
            errorSummary.hidden = true;
            errorSummary.textContent = '';
        }
    }

    function showError(target, message) {
        const container = target.closest('.quiz-question, .form-group, .quiz-contact-panel, .privacy-consent, .quiz-upload') || target.parentElement;
        const error = document.createElement('p');
        error.className = 'quiz-field-error';
        error.textContent = message;
        container.appendChild(error);
        target.setAttribute('aria-invalid', 'true');
        if (errorSummary) {
            errorSummary.textContent = message;
            errorSummary.hidden = false;
        }
        target.focus({preventScroll: true});
        target.scrollIntoView({behavior: reducedMotion ? 'auto' : 'smooth', block: 'center'});
        return false;
    }

    function validateRequiredGroups(step) {
        for (const group of step.querySelectorAll('[data-required-group]:not([hidden])')) {
            if (group.closest('[data-condition][hidden]')) continue;
            const fields = fieldsByName(group.dataset.requiredGroup).filter((field) => !field.disabled);
            if (fields.length && !fields.some((field) => field.checked)) {
                return showError(fields[0], 'Выберите один из вариантов');
            }
        }
        return true;
    }

    function validateControls(step) {
        for (const field of step.querySelectorAll('input, select, textarea')) {
            if (field.disabled || field.type === 'hidden' || field.type === 'file') continue;
            const value = typeof field.value === 'string' ? field.value.trim() : '';
            if (field.required && ((field.type === 'checkbox' && !field.checked) || (field.type !== 'checkbox' && value === ''))) {
                return showError(field, field.type === 'checkbox' ? 'Подтвердите согласие на обработку данных' : 'Заполните это поле');
            }
            if (field.type === 'email' && value && !field.validity.valid) {
                return showError(field, 'Проверьте адрес электронной почты');
            }
            if (field.type === 'url' && value && !field.validity.valid) {
                return showError(field, 'Проверьте ссылку');
            }
            if (field.type === 'date' && field.min && value && value < field.min) {
                return showError(field, `Дата должна быть не раньше ${formatDate(field.min)}`);
            }
            if (field.type === 'number' && value && !field.validity.valid) {
                return showError(field, 'Проверьте число гостей');
            }
        }
        return true;
    }

    function validateStep(index) {
        const step = steps[index];
        clearErrors(step);
        updateConditions();
        if (!validateRequiredGroups(step) || !validateControls(step)) return false;

        if (index === 0) {
            const exactGuests = form.elements.namedItem('guests_count');
            if (!checkedValue('guests_range') && !exactGuests.value.trim()) {
                return showError(exactGuests, 'Укажите количество или диапазон гостей');
            }
            if (checkedValue('event_time_status') === 'known' && !form.elements.namedItem('event_time').value) {
                return showError(form.elements.namedItem('event_time'), 'Укажите время или выберите «Пока не определились»');
            }
            if (checkedValue('event_type_choice') === 'Другое' && !form.elements.namedItem('event_type_other').value.trim()) {
                return showError(form.elements.namedItem('event_type_other'), 'Коротко опишите событие');
            }
        }

        if (index === steps.length - 1) {
            const phone = form.elements.namedItem('phone');
            const email = form.elements.namedItem('email');
            const telegram = form.elements.namedItem('telegram_username');
            if (!phone.value.trim() && !email.value.trim() && !telegram.value.trim()) {
                return showError(phone, 'Укажите телефон, электронную почту или Telegram');
            }
            const method = checkedValue('contact_method');
            if (['call', 'whatsapp'].includes(method) && !phone.value.trim()) {
                return showError(phone, 'Для выбранного способа связи нужен телефон');
            }
            if (method === 'email' && !email.value.trim()) {
                return showError(email, 'Укажите адрес электронной почты');
            }
            if (method === 'telegram' && !telegram.value.trim() && !phone.value.trim()) {
                return showError(telegram, 'Укажите имя пользователя в Telegram или телефон');
            }
            if (!validateFiles()) return false;
        }
        return true;
    }

    function validateFiles() {
        if (selectedFiles.length > 10) return showError(fileInput, 'Можно приложить не больше 10 файлов');
        const allowedTypes = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
        const unsupported = selectedFiles.find((file) => !allowedTypes.includes(file.type));
        if (unsupported) return showError(fileInput, `Формат файла «${unsupported.name}» не поддерживается`);
        const invalid = selectedFiles.find((file) => file.size > 10 * 1024 * 1024);
        return invalid ? showError(fileInput, `Файл «${invalid.name}» больше 10 МБ`) : true;
    }

    function validateAllSteps() {
        for (let index = 0; index < steps.length; index += 1) {
            if (!validateStep(index)) {
                showStep(index, false);
                return false;
            }
        }
        return true;
    }

    function formatDate(value) {
        if (!value) return '';
        const [year, month, day] = value.split('-').map(Number);
        const date = new Date(year, month - 1, day);
        return date.toLocaleDateString('ru-RU', {day: 'numeric', month: 'long', year: 'numeric'}).replace(/\s?г\.$/, '');
    }

    function showStep(index, focusHeading = true) {
        current = Math.max(0, Math.min(steps.length - 1, index));
        steps.forEach((step, stepIndex) => {
            const active = stepIndex === current;
            step.hidden = !active;
            step.classList.toggle('active', active);
            step.setAttribute('aria-hidden', active ? 'false' : 'true');
        });
        stageItems.forEach((item, stepIndex) => {
            item.classList.toggle('is-active', stepIndex === current);
            item.classList.toggle('is-complete', stepIndex < current);
            if (stepIndex === current) item.setAttribute('aria-current', 'step');
            else item.removeAttribute('aria-current');
        });
        previousButton.hidden = current === 0;
        nextButton.hidden = current === steps.length - 1;
        submitButton.hidden = current !== steps.length - 1;
        progress.style.width = `${((current + 1) / steps.length) * 100}%`;
        progressCounter.textContent = `Шаг ${current + 1} из ${steps.length}`;
        progressTitle.textContent = steps[current].dataset.stepTitle;
        if (currentStepField) currentStepField.value = String(current);
        if (current === steps.length - 1) updateReview();
        if (focusHeading) {
            const heading = steps[current].querySelector('h2');
            if (heading) {
                heading.tabIndex = -1;
                heading.focus({preventScroll: true});
            }
            document.getElementById('quizWorkspace').scrollIntoView({behavior: reducedMotion ? 'auto' : 'smooth', block: 'start'});
        }
    }

    function optionLabel(name, value) {
        const field = fieldsByName(name).find((input) => input.value === value);
        const label = field?.closest('label');
        return label?.querySelector('strong')?.textContent.trim() || label?.querySelector('span')?.textContent.trim() || value;
    }

    function labelsFor(name) {
        return checkedValues(name).map((value) => optionLabel(name, value));
    }

    function addReviewItem(container, label, value) {
        if (!value) return;
        const item = document.createElement('div');
        const term = document.createElement('span');
        const detail = document.createElement('strong');
        term.textContent = label;
        detail.textContent = value;
        item.append(term, detail);
        container.appendChild(item);
    }

    function updateReview() {
        const review = document.getElementById('quizReview');
        if (!review) return;
        review.replaceChildren();
        const eventType = checkedValue('event_type_choice') === 'Другое'
            ? form.elements.namedItem('event_type_other').value.trim() || 'Другое событие'
            : labelsFor('event_type_choice')[0];
        const guestText = form.elements.namedItem('guests_count').value.trim()
            ? `${form.elements.namedItem('guests_count').value.trim()} гостей`
            : labelsFor('guests_range')[0];
        addReviewItem(review, 'Событие', [eventType, guestText].filter(Boolean).join(' · '));
        addReviewItem(review, 'Дата', formatDate(form.elements.namedItem('event_date').value));
        addReviewItem(review, 'Стиль', labelsFor('visual_styles').join(' · '));
        addReviewItem(review, 'Палитра', form.elements.namedItem('custom_colors').value.trim() || labelsFor('palette')[0]);
        addReviewItem(review, 'Услуги', labelsFor('services_needed').join(' · '));
        addReviewItem(review, 'Зоны', labelsFor('decor_zones').join(' · '));
        addReviewItem(review, 'Главное', form.elements.namedItem('main_priority').value.trim());
    }

    function enforceExclusiveChoice(changedField) {
        if (changedField.type !== 'checkbox' || !changedField.checked) return;
        const group = fieldsByName(changedField.name.replace(/\[\]$/, ''));
        if (changedField.dataset.exclusive === '1') {
            group.forEach((field) => { if (field !== changedField) field.checked = false; });
        } else {
            group.filter((field) => field.dataset.exclusive === '1').forEach((field) => { field.checked = false; });
        }
    }

    function enforceSelectionLimit(changedField) {
        if (changedField.type !== 'checkbox' || !changedField.checked) return true;
        const group = changedField.closest('[data-max-selected]');
        if (!group) return true;
        const maximum = Number(group.dataset.maxSelected || 0);
        const selected = group.querySelectorAll('input[type="checkbox"]:checked:not(:disabled)');
        if (maximum && selected.length > maximum) {
            changedField.checked = false;
            showError(changedField, `Можно выбрать не больше ${maximum}`);
            return false;
        }
        return true;
    }

    function formatFileSize(bytes) {
        return bytes < 1024 * 1024 ? `${Math.ceil(bytes / 1024)} КБ` : `${(bytes / 1024 / 1024).toFixed(1)} МБ`;
    }

    function syncFileInput() {
        if (typeof DataTransfer === 'undefined') return;
        const transfer = new DataTransfer();
        selectedFiles.forEach((file) => transfer.items.add(file));
        fileInput.files = transfer.files;
    }

    function renderFilePreview() {
        filePreview.replaceChildren();
        selectedFiles.forEach((file, index) => {
            const card = document.createElement('article');
            card.className = 'quiz-file-card';
            if (file.type.startsWith('image/')) {
                const image = document.createElement('img');
                const objectUrl = URL.createObjectURL(file);
                image.src = objectUrl;
                image.alt = '';
                image.onload = () => URL.revokeObjectURL(objectUrl);
                card.appendChild(image);
            } else {
                const marker = document.createElement('span');
                marker.className = 'quiz-file-card__document';
                marker.textContent = 'PDF';
                card.appendChild(marker);
            }
            const copy = document.createElement('div');
            const name = document.createElement('strong');
            const size = document.createElement('small');
            name.textContent = file.name;
            size.textContent = formatFileSize(file.size);
            copy.append(name, size);
            const remove = document.createElement('button');
            remove.type = 'button';
            remove.setAttribute('aria-label', `Удалить ${file.name}`);
            remove.textContent = '×';
            remove.addEventListener('click', () => {
                selectedFiles.splice(index, 1);
                syncFileInput();
                renderFilePreview();
            });
            card.append(copy, remove);
            filePreview.appendChild(card);
        });
    }

    function draftPayload() {
        const data = {quiz_current_step: current};
        new FormData(form).forEach((value, rawKey) => {
            if (value instanceof File || ['csrf_token', 'submission_key', 'draft_token', 'referer_url'].includes(rawKey) || rawKey.startsWith('utm_')) return;
            const key = rawKey.replace(/\[\]$/, '');
            if (rawKey.endsWith('[]')) {
                if (!Array.isArray(data[key])) data[key] = [];
                data[key].push(value);
            } else {
                data[key] = value;
            }
        });
        return data;
    }

    function setDraftStatus(message, isError = false) {
        if (!draftStatus) return;
        draftStatus.textContent = message;
        draftStatus.classList.toggle('is-error', isError);
    }

    function saveDraft() {
        if (!csrfField) return;
        setDraftStatus('Сохраняем...');
        fetch('/api/quiz-draft.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrfField.value},
            body: JSON.stringify({token: draftToken, data: draftPayload()}),
        }).then((response) => response.json().then((data) => ({ok: response.ok, data})))
            .then((result) => {
                if (!result.ok || !result.data.success) throw new Error(result.data.error || 'Черновик не сохранён');
                draftToken = result.data.token;
                if (draftTokenField) draftTokenField.value = draftToken;
                try { localStorage.setItem(draftStorageKey, draftToken); } catch (error) {}
                setDraftStatus('Черновик сохранён');
            }).catch(() => setDraftStatus('Черновик временно не сохраняется', true));
    }

    function scheduleDraftSave() {
        window.clearTimeout(saveTimer);
        saveTimer = window.setTimeout(saveDraft, 850);
    }

    function applyDraft(data) {
        Object.entries(data || {}).forEach(([key, value]) => {
            if (key === 'quiz_current_step') return;
            const fields = fieldsByName(key);
            if (!fields.length) return;
            if (Array.isArray(value)) {
                fields.forEach((field) => { field.checked = value.includes(field.value); });
            } else if (fields[0].type === 'radio' || fields[0].type === 'checkbox') {
                fields.forEach((field) => { field.checked = String(value) === field.value; });
            } else {
                fields[0].value = value == null ? '' : String(value);
            }
        });
        updateConditions();
        showStep(Number(data.quiz_current_step || 0), false);
    }

    previousButton.addEventListener('click', function () {
        clearErrors();
        showStep(current - 1);
        scheduleDraftSave();
    });
    nextButton.addEventListener('click', function () {
        if (!validateStep(current)) return;
        showStep(current + 1);
        scheduleDraftSave();
        if (typeof window.trackMetrikaGoal === 'function') window.trackMetrikaGoal(`quiz_step_${current + 1}`);
    });
    form.querySelectorAll('[data-go-step]').forEach((button) => {
        button.addEventListener('click', () => showStep(Number(button.dataset.goStep || 0)));
    });
    form.addEventListener('change', function (event) {
        const field = event.target;
        if (field.matches('input[type="checkbox"], input[type="radio"]')) {
            enforceExclusiveChoice(field);
            if (!enforceSelectionLimit(field)) return;
        }
        clearErrors(field.closest('.quiz-question, .form-group') || form);
        updateConditions();
        if (current === steps.length - 1) updateReview();
        if (field !== fileInput) scheduleDraftSave();
    });
    form.addEventListener('input', function (event) {
        if (event.target === fileInput) return;
        if (current === steps.length - 1) updateReview();
        scheduleDraftSave();
    });
    fileInput.addEventListener('change', function () {
        const chosenFiles = Array.from(fileInput.files || []);
        selectedFiles = chosenFiles.slice(0, 10);
        syncFileInput();
        renderFilePreview();
        if (chosenFiles.length > 10) {
            showError(fileInput, 'Можно приложить не больше 10 файлов. Добавлены первые 10.');
        } else {
            validateFiles();
        }
    });
    form.addEventListener('submit', function (event) {
        if (!validateAllSteps()) {
            event.preventDefault();
            return;
        }
        submitButton.disabled = true;
        submitButton.textContent = 'Отправляем бриф...';
    });

    updateConditions();
    showStep(current, false);
    if (draftToken) {
        setDraftStatus('Черновик восстановлен');
    } else {
        try { draftToken = localStorage.getItem(draftStorageKey) || ''; } catch (error) {}
        if (draftToken) {
            fetch(`/api/quiz-draft.php?token=${encodeURIComponent(draftToken)}`, {credentials: 'same-origin'})
                .then((response) => response.json().then((data) => ({ok: response.ok, data})))
                .then((result) => {
                    if (!result.ok || !result.data.success) throw new Error('Черновик устарел');
                    applyDraft(result.data.data);
                    if (draftTokenField) draftTokenField.value = draftToken;
                    setDraftStatus('Незавершённый бриф восстановлен');
                }).catch(() => {
                    try { localStorage.removeItem(draftStorageKey); } catch (error) {}
                    draftToken = '';
                });
        }
    }
});
