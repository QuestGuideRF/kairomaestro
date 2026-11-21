from __future__ import annotations
import hashlib
from datetime import date, datetime, time
from decimal import Decimal, DecimalException
from typing import Any
STATUS_LABELS = {
    "new": "Новая заявка",
    "new_lead": "Новый заказ",
    "discussion": "Обсуждение",
    "estimate": "Смета",
    "contract": "Документы",
    "prepayment": "Предоплата",
    "preparation": "Подготовка",
    "event_day": "Событие сегодня",
    "completed": "Завершён",
    "cancelled": "Отменён",
    "pending": "Ожидает ответа",
    "confirmed": "Участие подтверждено",
    "on_location": "На площадке",
    "declined": "Отклонено",
    "todo": "Нужно выполнить",
    "in_progress": "В работе",
    "done": "Готово",
}
def identifier_fingerprint(value: Any) -> str:
    return hashlib.sha256(str(value).encode("utf-8")).hexdigest()[:12]
def status_label(value: Any) -> str:
    key = str(value or "")
    return STATUS_LABELS.get(key, key.replace("_", " ").capitalize() or "Не указан")
def russian_date(value: Any) -> str:
    if isinstance(value, datetime):
        value = value.date()
    if isinstance(value, date):
        return value.strftime("%d.%m.%Y")
    text = str(value or "").strip()
    if not text:
        return "Дата уточняется"
    try:
        return datetime.fromisoformat(text).strftime("%d.%m.%Y")
    except ValueError:
        return text
def short_time(value: Any) -> str:
    if isinstance(value, time):
        return value.strftime("%H:%M")
    text = str(value or "")
    return text[:5] if text else "Время уточняется"
def money(value: Any) -> str:
    try:
        number = Decimal(str(value))
    except (DecimalException, ValueError):
        return str(value or "")
    return f"{number:,.0f}".replace(",", " ") + " ₽"
