(() => {
    const readJson = (id, fallback) => {
        const node = document.getElementById(id);
        if (!node) {
            return fallback;
        }
        try {
            return JSON.parse(node.textContent || '');
        } catch (error) {
            return fallback;
        }
    };
    const builder = document.getElementById('templateBuilder');
    const orderForm = document.getElementById('orderTemplateForm');
    const payloadField = document.getElementById('template_payload');
    const initialPayload = readJson('templateBuilderInitial', {});
    const documentOptions = readJson('templateDocumentOptions', []);
    let rowSequence = 0;
    const roleLabels = {
        florist: 'Флорист',
        courier: 'Курьер',
        installer: 'Монтажник',
        helper: 'Помощник'
    };
    const schemas = {
        tasks: {
            itemLabel: 'Задача',
            fields: [
                {name: 'title', label: 'Название задачи', placeholder: 'Подготовить композиции', wide: true},
                {name: 'description', label: 'Описание', type: 'textarea', placeholder: 'Что именно нужно сделать', wide: true},
                {name: 'days_before', label: 'За сколько дней', type: 'number', min: 0, max: 3650, value: 30},
                {
                    name: 'visibility',
                    label: 'Кому показывать',
                    type: 'select',
                    value: 'internal',
                    options: [
                        ['internal', 'Только команде'],
                        ['client', 'Клиенту и команде']
                    ]
                }
            ]
        },
        checklists: {
            itemLabel: 'Проверка',
            fields: [
                {name: 'title', label: 'Что проверить', placeholder: 'Площадка подтвердила время монтажа', wide: true}
            ]
        },
        roles: {
            itemLabel: 'Роль',
            fields: [
                {
                    name: 'role',
                    label: 'Исполнитель',
                    type: 'select',
                    value: 'florist',
                    options: Object.entries(roleLabels)
                },
                {name: 'quantity', label: 'Количество', type: 'number', min: 1, max: 100, value: 1},
                {name: 'notes', label: 'Требования', placeholder: 'Опыт свадебной флористики', wide: true}
            ]
        },
        timeline: {
            itemLabel: 'Этап',
            fields: [
                {name: 'title', label: 'Название этапа', placeholder: 'Начало монтажа', wide: true},
                {name: 'start_time', label: 'Начало', type: 'time', value: '10:00'},
                {name: 'end_time', label: 'Окончание', type: 'time', value: '11:00'},
                {
                    name: 'priority',
                    label: 'Приоритет',
                    type: 'select',
                    value: 'normal',
                    options: [
                        ['low', 'Низкий'],
                        ['normal', 'Обычный'],
                        ['high', 'Высокий'],
                        ['critical', 'Критичный']
                    ]
                },
                {name: 'description', label: 'Описание', type: 'textarea', placeholder: 'Порядок действий и важные детали', wide: true}
            ]
        },
        expenses: {
            itemLabel: 'Расход',
            fields: [
                {name: 'title', label: 'Статья расхода', placeholder: 'Живые цветы', wide: true},
                {
                    name: 'category',
                    label: 'Категория',
                    type: 'select',
                    value: 'other',
                    options: [
                        ['flowers', 'Цветы'],
                        ['decor', 'Декор'],
                        ['rent', 'Аренда'],
                        ['transport', 'Транспорт'],
                        ['installation', 'Монтаж'],
                        ['dismantling', 'Демонтаж'],
                        ['contractors', 'Подрядчики'],
                        ['staff', 'Команда'],
                        ['other', 'Другое']
                    ]
                },
                {name: 'amount', label: 'Плановая сумма, ₽', type: 'number', min: 0, step: '0.01', value: 0}
            ]
        },
        payments: {
            itemLabel: 'Платёж',
            fields: [
                {name: 'title', label: 'Название платежа', placeholder: 'Предоплата', wide: true},
                {name: 'amount', label: 'Сумма, ₽', type: 'number', min: 0, step: '0.01', value: 0},
                {name: 'days_before', label: 'За сколько дней до события', type: 'number', min: 0, max: 3650, value: 14}
            ]
        },
        documents: {
            itemLabel: 'Документ',
            fields: [
                {
                    name: 'template_id',
                    label: 'Шаблон документа',
                    type: 'select',
                    value: '',
                    options: [
                        ['', documentOptions.length ? 'Выберите шаблон' : 'Сначала создайте шаблон документа'],
                        ...documentOptions.map(option => [String(option.id), option.name])
                    ],
                    wide: true
                },
                {
                    name: 'document_type',
                    label: 'Тип документа',
                    type: 'select',
                    value: 'custom',
                    options: [
                        ['contract', 'Договор'],
                        ['estimate_appendix', 'Приложение к смете'],
                        ['invoice', 'Счёт'],
                        ['act', 'Акт'],
                        ['memo', 'Памятка'],
                        ['custom', 'Произвольный документ']
                    ]
                },
                {name: 'title', label: 'Название в заказе', placeholder: 'Договор на организацию события', wide: true},
                {
                    name: 'body_html',
                    label: 'Текст встроенного документа',
                    type: 'textarea',
                    placeholder: 'Можно оставить пустым, если выбран готовый шаблон документа.',
                    wide: true
                }
            ]
        },
        notifications: {
            itemLabel: 'Напоминание',
            fields: [
                {name: 'text', label: 'Текст напоминания', type: 'textarea', placeholder: 'Проверить готовность команды', wide: true},
                {name: 'days_before', label: 'За сколько дней', type: 'number', min: 0, max: 3650, value: 7},
                {name: 'send_time', label: 'Время отправки', type: 'time', value: '10:00'},
                {
                    name: 'recipient_type',
                    label: 'Получатель',
                    type: 'select',
                    value: 'organizer',
                    options: [
                        ['organizer', 'Организатор'],
                        ['client', 'Клиент']
                    ]
                }
            ]
        }
    };
    const createSelect = (definition, value) => {
        const select = document.createElement('select');
        select.className = 'form-control';
        for (const [optionValue, optionLabel] of definition.options || []) {
            const option = document.createElement('option');
            option.value = String(optionValue);
            option.textContent = optionLabel;
            option.selected = String(value ?? definition.value ?? '') === String(optionValue);
            select.appendChild(option);
        }
        return select;
    };
    const createControl = (definition, value) => {
        let control;
        if (definition.type === 'select') {
            control = createSelect(definition, value);
        } else if (definition.type === 'textarea') {
            control = document.createElement('textarea');
            control.className = 'form-control';
            control.rows = 3;
            control.value = String(value ?? definition.value ?? '');
        } else {
            control = document.createElement('input');
            control.className = 'form-control';
            control.type = definition.type || 'text';
            control.value = String(value ?? definition.value ?? '');
            if (definition.min !== undefined) {
                control.min = String(definition.min);
            }
            if (definition.max !== undefined) {
                control.max = String(definition.max);
            }
            if (definition.step !== undefined) {
                control.step = String(definition.step);
            }
        }
        control.dataset.templateField = definition.name;
        if (definition.placeholder) {
            control.placeholder = definition.placeholder;
        }
        return control;
    };
    const updateSection = section => {
        const items = section.querySelectorAll('[data-template-item]');
        const count = section.querySelector('[data-template-count]');
        if (count) {
            count.textContent = items.length ? String(items.length) : 'Пусто';
        }
        const empty = section.querySelector('.template-builder__empty');
        if (empty) {
            empty.hidden = items.length > 0;
        }
        items.forEach((item, index) => {
            const label = item.querySelector('[data-template-item-label]');
            if (label) {
                label.textContent = `${schemas[section.dataset.templateSection].itemLabel} ${index + 1}`;
            }
            item.querySelectorAll('[data-move-template-item]').forEach(button => {
                button.disabled = button.dataset.moveTemplateItem === 'up'
                    ? index === 0
                    : index === items.length - 1;
            });
        });
    };
    const moveItem = (item, direction) => {
        const section = item.closest('[data-template-section]');
        const container = item.parentElement;
        if (!section || !container) {
            return;
        }
        const items = Array.from(container.querySelectorAll('[data-template-item]'));
        const index = items.indexOf(item);
        const targetIndex = index + direction;
        if (index < 0 || targetIndex < 0 || targetIndex >= items.length) {
            return;
        }
        if (direction < 0) {
            container.insertBefore(item, items[targetIndex]);
        } else {
            container.insertBefore(items[targetIndex], item);
        }
        updateSection(section);
    };
    const createItem = (sectionKey, values = {}) => {
        const schema = schemas[sectionKey];
        const item = document.createElement('article');
        const rowId = ++rowSequence;
        item.className = 'template-builder__item';
        item.dataset.templateItem = '';
        const header = document.createElement('div');
        header.className = 'template-builder__item-heading';
        const title = document.createElement('strong');
        title.dataset.templateItemLabel = '';
        const actions = document.createElement('div');
        actions.className = 'template-builder__item-actions';
        const moveUp = document.createElement('button');
        moveUp.type = 'button';
        moveUp.className = 'template-builder__move';
        moveUp.textContent = '↑';
        moveUp.title = 'Переместить выше';
        moveUp.setAttribute('aria-label', 'Переместить элемент выше');
        moveUp.dataset.moveTemplateItem = 'up';
        moveUp.addEventListener('click', () => moveItem(item, -1));
        const moveDown = document.createElement('button');
        moveDown.type = 'button';
        moveDown.className = 'template-builder__move';
        moveDown.textContent = '↓';
        moveDown.title = 'Переместить ниже';
        moveDown.setAttribute('aria-label', 'Переместить элемент ниже');
        moveDown.dataset.moveTemplateItem = 'down';
        moveDown.addEventListener('click', () => moveItem(item, 1));
        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'template-builder__remove';
        remove.textContent = '×';
        remove.title = 'Удалить';
        remove.setAttribute('aria-label', 'Удалить элемент');
        remove.addEventListener('click', () => {
            const section = item.closest('[data-template-section]');
            item.remove();
            if (section) {
                updateSection(section);
            }
        });
        actions.append(moveUp, moveDown, remove);
        header.append(title, actions);
        const fields = document.createElement('div');
        fields.className = 'template-builder__fields';
        for (const definition of schema.fields) {
            const group = document.createElement('div');
            group.className = `form-group${definition.wide ? ' template-builder__field--wide' : ''}`;
            const label = document.createElement('label');
            const control = createControl(definition, values[definition.name]);
            control.id = `template-${sectionKey}-${rowId}-${definition.name}`;
            label.htmlFor = control.id;
            label.textContent = definition.label;
            group.append(label, control);
            fields.appendChild(group);
        }
        item.append(header, fields);
        return item;
    };
    const addItem = (section, values = {}) => {
        const sectionKey = section.dataset.templateSection;
        const items = section.querySelector('[data-template-items]');
        if (!sectionKey || !items || !schemas[sectionKey]) {
            return;
        }
        items.appendChild(createItem(sectionKey, values));
        updateSection(section);
    };
    const fieldValue = control => {
        if (control.type === 'number') {
            return control.value === '' ? null : Number(control.value);
        }
        return control.value.trim();
    };
    const serializeItem = (sectionKey, item) => {
        const result = {};
        item.querySelectorAll('[data-template-field]').forEach(control => {
            result[control.dataset.templateField] = fieldValue(control);
        });
        if (sectionKey === 'roles') {
            result.label = roleLabels[result.role] || 'Сотрудник';
        }
        if (sectionKey === 'documents' && result.template_id) {
            const option = documentOptions.find(entry => String(entry.id) === String(result.template_id));
            result.template_id = Number(result.template_id);
            result.document_type = option?.type || result.document_type || 'custom';
            if (!result.title && option) {
                result.title = option.name;
            }
        }
        return result;
    };
    const hasMeaningfulValue = (sectionKey, item) => {
        const primaryFields = {
            tasks: 'title',
            checklists: 'title',
            roles: 'role',
            timeline: 'title',
            expenses: 'title',
            payments: 'title',
            documents: 'template_id',
            notifications: 'text'
        };
        if (sectionKey === 'documents') {
            return String(item.template_id ?? '').trim() !== ''
                || (String(item.title ?? '').trim() !== '' && String(item.body_html ?? '').trim() !== '');
        }
        return String(item[primaryFields[sectionKey]] ?? '').trim() !== '';
    };
    if (builder && orderForm && payloadField) {
        builder.querySelectorAll('[data-template-section]').forEach(section => {
            const sectionKey = section.dataset.templateSection;
            const items = section.querySelector('[data-template-items]');
            const empty = document.createElement('p');
            empty.className = 'template-builder__empty';
            empty.textContent = 'Пока ничего не добавлено.';
            items?.appendChild(empty);
            const initialItems = Array.isArray(initialPayload[sectionKey]) ? initialPayload[sectionKey] : [];
            initialItems.forEach(item => addItem(section, typeof item === 'object' && item !== null ? item : {title: item}));
            updateSection(section);
            section.querySelector('[data-add-template-item]')?.addEventListener('click', () => {
                addItem(section);
                section.querySelector('[data-template-item]:last-child input, [data-template-item]:last-child textarea, [data-template-item]:last-child select')?.focus();
            });
        });
        orderForm.addEventListener('submit', () => {
            const payload = {};
            builder.querySelectorAll('[data-template-section]').forEach(section => {
                const sectionKey = section.dataset.templateSection;
                payload[sectionKey] = Array.from(section.querySelectorAll('[data-template-item]'))
                    .map(item => serializeItem(sectionKey, item))
                    .filter(item => hasMeaningfulValue(sectionKey, item));
            });
            payloadField.value = JSON.stringify(payload);
        });
    }
    const documentForm = document.getElementById('documentTemplateForm');
    const visualEditor = document.getElementById('docVisualEditor');
    const replaceEditorSelection = (before, after = '') => {
        if (!visualEditor) {
            return;
        }
        const start = visualEditor.selectionStart;
        const end = visualEditor.selectionEnd;
        const selected = visualEditor.value.slice(start, end);
        const replacement = `${before}${selected}${after}`;
        visualEditor.setRangeText(replacement, start, end, 'end');
        visualEditor.dispatchEvent(new Event('input', { bubbles: true }));
    };
    if (documentForm && visualEditor) {
        document.querySelectorAll('[data-editor-command]').forEach(button => {
            button.addEventListener('click', () => {
                visualEditor.focus();
                const command = button.dataset.editorCommand;
                const value = button.dataset.editorValue;
                if (command === 'bold') {
                    replaceEditorSelection('<strong>', '</strong>');
                } else if (command === 'italic') {
                    replaceEditorSelection('<em>', '</em>');
                } else if (command === 'insertUnorderedList') {
                    replaceEditorSelection('<ul><li>', '</li></ul>');
                } else if (command === 'formatBlock' && value === 'h2') {
                    replaceEditorSelection('<h2>', '</h2>');
                } else if (command === 'formatBlock') {
                    replaceEditorSelection('<p>', '</p>');
                }
            });
        });
        document.querySelectorAll('[data-document-variable]').forEach(button => {
            button.addEventListener('click', () => {
                visualEditor.focus();
                const variable = button.dataset.documentVariable || '';
                replaceEditorSelection(variable);
            });
        });
    }
})();
