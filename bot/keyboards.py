from __future__ import annotations
from aiogram.types import (
    CopyTextButton,
    InlineKeyboardButton,
    InlineKeyboardMarkup,
    KeyboardButton,
    ReplyKeyboardMarkup,
    ReplyKeyboardRemove,
)
from flows import EVENT_TYPES, JOB_EXPERIENCE, JOB_ROLES, SERVICES


def login_code_copy(code: str) -> InlineKeyboardMarkup:
    return InlineKeyboardMarkup(inline_keyboard=[[
        InlineKeyboardButton(
            text="Скопировать код",
            copy_text=CopyTextButton(text=code),
        ),
    ]])
def client_menu() -> InlineKeyboardMarkup:
    return InlineKeyboardMarkup(inline_keyboard=[
        [InlineKeyboardButton(text="Заказать мероприятие", callback_data="quiz:start")],
        [InlineKeyboardButton(text="Мои события", callback_data="orders:list")],
    ])
def flow_navigation(can_back: bool, can_skip: bool) -> InlineKeyboardMarkup:
    row: list[InlineKeyboardButton] = []
    if can_back:
        row.append(InlineKeyboardButton(text="Назад", callback_data="flow:back"))
    if can_skip:
        row.append(InlineKeyboardButton(text="Пропустить", callback_data="flow:skip"))
    rows = [row] if row else []
    rows.append([InlineKeyboardButton(text="Отменить", callback_data="flow:cancel")])
    return InlineKeyboardMarkup(inline_keyboard=rows)
def event_types() -> InlineKeyboardMarkup:
    return InlineKeyboardMarkup(inline_keyboard=[
        [InlineKeyboardButton(text=label, callback_data=f"quiz:event:{key}")]
        for key, label in EVENT_TYPES.items()
    ])
def services(selected: set[str]) -> InlineKeyboardMarkup:
    rows = []
    for key, label in SERVICES.items():
        marker = "✓ " if key in selected else ""
        rows.append([InlineKeyboardButton(
            text=marker + label.capitalize(),
            callback_data=f"quiz:service:{key}",
        )])
    rows.append([InlineKeyboardButton(text="Продолжить", callback_data="quiz:services_done")])
    rows.append([InlineKeyboardButton(text="Назад", callback_data="flow:back")])
    rows.append([InlineKeyboardButton(text="Отменить", callback_data="flow:cancel")])
    return InlineKeyboardMarkup(inline_keyboard=rows)
def contact_keyboard() -> ReplyKeyboardMarkup:
    return ReplyKeyboardMarkup(
        keyboard=[[KeyboardButton(text="Отправить мой телефон", request_contact=True)]],
        resize_keyboard=True,
        one_time_keyboard=True,
        input_field_placeholder="Или введите номер вручную",
    )
def remove_keyboard() -> ReplyKeyboardRemove:
    return ReplyKeyboardRemove()
def references(count: int) -> InlineKeyboardMarkup:
    rows = []
    if count:
        rows.append([InlineKeyboardButton(text=f"Продолжить · файлов: {count}", callback_data="flow:files_done")])
    else:
        rows.append([InlineKeyboardButton(text="Пропустить", callback_data="flow:skip")])
    rows.append([InlineKeyboardButton(text="Назад", callback_data="flow:back")])
    rows.append([InlineKeyboardButton(text="Отменить", callback_data="flow:cancel")])
    return InlineKeyboardMarkup(inline_keyboard=rows)
def job_roles() -> InlineKeyboardMarkup:
    return InlineKeyboardMarkup(inline_keyboard=[
        [InlineKeyboardButton(text=label, callback_data=f"job:role:{key}")]
        for key, label in JOB_ROLES.items()
    ])
def job_experience() -> InlineKeyboardMarkup:
    return InlineKeyboardMarkup(inline_keyboard=[
        [InlineKeyboardButton(text=label, callback_data=f"job:experience:{key}")]
        for key, label in JOB_EXPERIENCE.items()
    ])
def role_menu(role: str) -> InlineKeyboardMarkup:
    if role == "organizer":
        labels = [("Мои заказы", "orders:list"), ("Сегодня", "staff:today"), ("Календарь", "staff:calendar")]
    elif role == "manager":
        labels = [("Новые заявки", "manager:new"), ("Мои заявки", "manager:mine")]
    elif role == "courier":
        labels = [("Сегодня", "staff:today"), ("Доставки", "staff:tasks"), ("Мои события", "orders:list")]
    else:
        labels = [("Сегодня", "staff:today"), ("Мои события", "orders:list"), ("Мои задачи", "staff:tasks")]
    return InlineKeyboardMarkup(inline_keyboard=[
        [InlineKeyboardButton(text=text, callback_data=callback)]
        for text, callback in labels
    ])
def assignment_actions(assignment_id: int) -> InlineKeyboardMarkup:
    return InlineKeyboardMarkup(inline_keyboard=[[
        InlineKeyboardButton(text="Подтвердить участие", callback_data=f"assignment:accept:{assignment_id}"),
        InlineKeyboardButton(text="Отклонить", callback_data=f"assignment:decline:{assignment_id}"),
    ]])
