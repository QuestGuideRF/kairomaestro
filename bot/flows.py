from __future__ import annotations
import re
from dataclasses import dataclass
from datetime import date, datetime, time, timedelta
from typing import Any
from zoneinfo import ZoneInfo
QUIZ_STEPS = (
    "event_type",
    "event_date",
    "event_time",
    "city",
    "venue",
    "address",
    "guests",
    "budget",
    "services",
    "style",
    "colors",
    "wishes",
    "phone",
    "email",
    "references",
)
JOB_STEPS = (
    "full_name",
    "phone",
    "email",
    "role",
    "experience",
    "salary",
    "about",
    "schedule",
    "portfolio_url",
    "portfolio_files",
)
OPTIONAL_QUIZ = {"event_time", "venue", "address", "style", "colors", "wishes", "phone", "email", "references"}
OPTIONAL_JOB = {"email", "about", "schedule", "portfolio_url", "portfolio_files"}
EVENT_TYPES = {
    "wedding": "Свадьба",
    "birthday": "День рождения",
    "chamber": "Камерное мероприятие",
    "other": "Другое",
}
SERVICES = {
    "full": "организация под ключ",
    "decor": "декор",
    "floristry": "флористика",
    "coordination": "координация",
    "ceremony": "выездная церемония",
    "installation": "монтаж",
    "other": "другое",
}
JOB_ROLES = {
    "florist": "Флорист",
    "courier": "Курьер",
    "installer": "Монтажник",
    "helper": "Помощник",
}
JOB_EXPERIENCE = {
    "none": "Без опыта",
    "under_year": "До 1 года",
    "one_to_three": "1–3 года",
    "over_three": "Более 3 лет",
}
@dataclass(frozen=True, slots=True)
class Prompt:
    text: str
    optional: bool = False
def quiz_prompt(step: str, index: int) -> Prompt:
    prompts = {
        "event_type": "Какое событие вы планируете?",
        "event_date": "Укажите дату события в формате ДД.ММ.ГГГГ.",
        "event_time": "Во сколько планируется начало? Формат ЧЧ:ММ.",
        "city": "В каком городе пройдёт событие?",
        "venue": "Как называется площадка, если она уже выбрана?",
        "address": "Укажите адрес площадки, если он известен.",
        "guests": "Сколько гостей вы ожидаете?",
        "budget": "Какой ориентировочный бюджет события?",
        "services": "Какие услуги нужны? Можно выбрать несколько.",
        "style": "Какой стиль или настроение вам близки?",
        "colors": "Какая цветовая гамма нравится?",
        "wishes": "Расскажите о пожеланиях и о том, чего точно не хочется.",
        "phone": "Оставьте телефон или отправьте контакт кнопкой ниже.",
        "email": "Укажите email для документов и связи.",
        "references": "Отправьте до 10 фото или PDF с референсами.",
    }
    return Prompt(
        f"Шаг {index + 1} из {len(QUIZ_STEPS)}\n\n{prompts[step]}",
        step in OPTIONAL_QUIZ,
    )
def job_prompt(step: str, index: int) -> Prompt:
    prompts = {
        "full_name": "Как вас зовут? Укажите имя и фамилию.",
        "phone": "Оставьте телефон или отправьте контакт кнопкой ниже.",
        "email": "Укажите email.",
        "role": "Кем вы хотите работать?",
        "experience": "Какой у вас опыт?",
        "salary": "Укажите желаемую ставку или оплату в рублях.",
        "about": "Коротко расскажите о себе и опыте.",
        "schedule": "Какой график вам удобен?",
        "portfolio_url": "Пришлите ссылку на портфолио.",
        "portfolio_files": "Отправьте до 10 фотографий или PDF портфолио.",
    }
    return Prompt(
        f"Анкета: шаг {index + 1} из {len(JOB_STEPS)}\n\n{prompts[step]}",
        step in OPTIONAL_JOB,
    )
def parse_date(value: str) -> str:
    try:
        day, month, year = (int(part) for part in value.strip().split("."))
        parsed = date(year, month, day)
    except ValueError as error:
        raise ValueError("Введите дату в формате ДД.ММ.ГГГГ.") from error
    minimum = datetime.now(ZoneInfo("Europe/Moscow")).date() + timedelta(days=7)
    if parsed < minimum:
        raise ValueError(f"Дата должна быть не раньше {minimum:%d.%m.%Y}.")
    return parsed.isoformat()
def parse_time(value: str) -> str:
    try:
        return time.fromisoformat(value.strip()).strftime("%H:%M")
    except ValueError as error:
        raise ValueError("Введите время в формате ЧЧ:ММ.") from error
def parse_phone(value: str) -> str:
    phone = value.strip()
    if not re.fullmatch(r"[0-9+() \-]{7,32}", phone):
        raise ValueError("Проверьте номер телефона.")
    return phone
def parse_email(value: str) -> str:
    email = value.strip().lower()
    parts = email.split("@")
    domain = parts[1] if len(parts) == 2 else ""
    invalid = (
        len(email) > 190
        or len(parts) != 2
        or not parts[0]
        or "." not in domain
        or domain.startswith(".")
        or domain.endswith(".")
        or any(character.isspace() for character in email)
    )
    if invalid:
        raise ValueError("Проверьте адрес электронной почты.")
    return email
def normalize_quiz(payload: dict[str, Any], telegram_name: str) -> dict[str, Any]:
    answers = payload.get("answers", {})
    return {
        "client_name": str(answers.get("client_name") or telegram_name).strip()[:190],
        "phone": str(answers.get("phone") or "").strip(),
        "email": str(answers.get("email") or "").strip(),
        "event_type": str(answers.get("event_type") or "Свадьба"),
        "event_date": str(answers.get("event_date") or ""),
        "event_time": str(answers.get("event_time") or ""),
        "location_city": str(answers.get("location_city") or ""),
        "location_details": str(answers.get("location_details") or ""),
        "venue": str(answers.get("venue") or ""),
        "guests_count": int(answers.get("guests_count") or 0),
        "budget_range": str(answers.get("budget_range") or ""),
        "services_needed": list(answers.get("services_needed") or []),
        "style": str(answers.get("style") or ""),
        "color_palette": str(answers.get("color_palette") or ""),
        "comment": str(answers.get("comment") or ""),
    }
def normalize_job(payload: dict[str, Any]) -> dict[str, Any]:
    answers = payload.get("answers", {})
    return {
        "full_name": str(answers.get("full_name") or ""),
        "phone": str(answers.get("phone") or ""),
        "email": str(answers.get("email") or ""),
        "telegram": str(answers.get("telegram") or ""),
        "desired_role": str(answers.get("desired_role") or ""),
        "experience": str(answers.get("experience") or ""),
        "desired_salary": str(answers.get("desired_salary") or "0"),
        "portfolio_url": str(answers.get("portfolio_url") or ""),
        "comment": str(answers.get("comment") or ""),
        "preferred_schedule": str(answers.get("preferred_schedule") or ""),
    }
