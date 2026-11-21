document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('careerForm');
    if (!form) return;
    const steps = Array.from(form.querySelectorAll('.career-step'));
    const previousButton = document.getElementById('careerPrev');
    const nextButton = document.getElementById('careerNext');
    const submitButton = document.getElementById('careerSubmit');
    const progress = document.getElementById('careerProgress');
    const progressLabel = document.getElementById('careerProgressLabel');
    let currentStep = 0;
    function showStep(index) {
        steps.forEach(function (step, stepIndex) {
            step.classList.toggle('active', stepIndex === index);
        });
        previousButton.hidden = index === 0;
        nextButton.hidden = index === steps.length - 1;
        submitButton.hidden = index !== steps.length - 1;
        progress.style.width = (((index + 1) / steps.length) * 100) + '%';
        progressLabel.textContent = 'Шаг ' + (index + 1) + ' из ' + steps.length;
    }
    function showInvalid(field, message) {
        field.setCustomValidity(message || '');
        if (message) {
            field.reportValidity();
            field.focus();
            return false;
        }
        return true;
    }
    function validateStep(index) {
        const step = steps[index];
        const controls = Array.from(step.querySelectorAll('input, textarea, select'));
        controls.forEach(function (field) {
            field.setCustomValidity('');
        });
        if (index === 0) {
            const name = form.elements.full_name;
            const phone = form.elements.phone;
            const email = form.elements.email;
            const telegram = form.elements.telegram;
            if (name.value.trim().length < 3) return showInvalid(name, 'Укажите имя и фамилию');
            if (!phone.value.trim() && !email.value.trim() && !telegram.value.trim()) {
                return showInvalid(phone, 'Укажите телефон, электронную почту или имя пользователя в Telegram');
            }
        }
        for (const field of controls) {
            if (!field.checkValidity()) {
                field.reportValidity();
                field.focus();
                return false;
            }
        }
        return true;
    }
    previousButton.addEventListener('click', function () {
        currentStep = Math.max(0, currentStep - 1);
        showStep(currentStep);
    });
    nextButton.addEventListener('click', function () {
        if (!validateStep(currentStep)) return;
        currentStep = Math.min(steps.length - 1, currentStep + 1);
        showStep(currentStep);
    });
    form.addEventListener('submit', function (event) {
        for (let index = 0; index < steps.length; index += 1) {
            currentStep = index;
            showStep(currentStep);
            if (!validateStep(index)) {
                event.preventDefault();
                return;
            }
        }
        submitButton.disabled = true;
        submitButton.textContent = 'Отправляем...';
    });
    showStep(currentStep);
});
