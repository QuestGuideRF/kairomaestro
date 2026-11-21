from __future__ import annotations
import hashlib
import io
import logging
import secrets
from typing import Any
from aiogram import Bot, F, Router
from aiogram.exceptions import TelegramBadRequest
from aiogram.filters import Command, CommandObject, CommandStart
from aiogram.types import (
    BotCommand,
    BotCommandScopeChat,
    CallbackQuery,
    InlineKeyboardButton,
    InlineKeyboardMarkup,
    Message,
)
from database import Database
from flows import (
    EVENT_TYPES,
    JOB_EXPERIENCE,
    JOB_ROLES,
    JOB_STEPS,
    OPTIONAL_JOB,
    OPTIONAL_QUIZ,
    QUIZ_STEPS,
    SERVICES,
    job_prompt,
    normalize_job,
    normalize_quiz,
    parse_date,
    parse_email,
    parse_phone,
    parse_time,
    quiz_prompt,
)
from formatting import (
    identifier_fingerprint,
    russian_date,
    short_time,
    status_label,
)
from internal_api import InternalApiClient, InternalApiError
from keyboards import (
    client_menu,
    contact_keyboard,
    event_types,
    flow_navigation,
    job_experience,
    job_roles,
    references,
    remove_keyboard,
    role_menu,
    services,
)
from settings import Settings
LOGGER = logging.getLogger("azbuka_bot.handlers")


def start_argument(message_text: str | None, parsed_argument: str | None) -> str:
    argument = (parsed_argument or "").strip()
    if argument:
        return argument
    parts = (message_text or "").strip().split(maxsplit=1)
    if len(parts) != 2 or parts[0].split("@", 1)[0].lower() != "/start":
        return ""
    return parts[1].strip()


class PrivateHandlers:
    def __init__(
        self,
        database: Database,
        settings: Settings,
        api: InternalApiClient,
    ) -> None:
        self.database = database
        self.settings = settings
        self.api = api
    async def start(self, message: Message, command: CommandObject) -> None:
        if not message.from_user:
            return
        argument = start_argument(message.text, command.args)
        auth_purpose = None
        auth_token = ""
        if argument.startswith("login_"):
            auth_purpose = "login"
            auth_token = argument[6:]
        elif argument.startswith("reset_"):
            auth_purpose = "password_reset"
            auth_token = argument[6:]
        if auth_purpose is not None:
            LOGGER.info(
                "Telegram auth deep link opened: purpose=%s user=%s",
                auth_purpose,
                identifier_fingerprint(message.from_user.id),
            )
            valid_token = len(auth_token) == 48 and all(
                character in "0123456789abcdefABCDEF"
                for character in auth_token
            )
            if not valid_token:
                await message.answer(
                    "Ссылка подтверждения повреждена. Создайте новую на странице входа."
                )
                return
            success, text, request = await self.database.auth_request_preview(
                auth_token,
                auth_purpose,
                message.from_user.id,
            )
            if not success or request is None:
                await message.answer(text)
                return
            if request["approved_at"]:
                await message.answer(text)
                return
            purpose_code = "l" if auth_purpose == "login" else "r"
            action_text = (
                "вход в аккаунт"
                if auth_purpose == "login"
                else "сброс пароля"
            )
            keyboard = InlineKeyboardMarkup(inline_keyboard=[
                [InlineKeyboardButton(
                    text=(
                        "Подтвердить вход"
                        if auth_purpose == "login"
                        else "Подтвердить сброс пароля"
                    ),
                    callback_data=f"auth:confirm:{purpose_code}:{auth_token}",
                )],
                [InlineKeyboardButton(
                    text="Отменить запрос",
                    callback_data=f"auth:cancel:{purpose_code}:{auth_token}",
                )],
            ])
            await message.answer(
                f"Запрос: {action_text}\n"
                f"IP браузера: {request['request_ip'] or 'не определён'}\n"
                f"Создан: {request['created_at']}\n\n"
                "Подтверждайте только запрос, который вы создали сами.",
                reply_markup=keyboard,
            )
            return
        if argument.startswith("link_") and len(argument) > 20:
            success, text = await self.database.link_user(
                argument[5:],
                message.from_user.id,
                message.chat.id,
                message.from_user.username,
            )
            await message.answer(text)
            if success:
                await self.show_main_menu(message)
            return
        await self.database.delete_flow(message.from_user.id, message.chat.id)
        await self.show_main_menu(message)
    async def show_main_menu(self, message: Message) -> None:
        user = await self.database.linked_user(message.from_user.id) if message.from_user else None
        if user and user["role"] != "client":
            await self.set_chat_commands(message, staff=True)
            await message.answer(
                f"{self.settings.site_name} 🌿\n\nЗдравствуйте, {user['first_name']}.",
                reply_markup=role_menu(str(user["role"])),
            )
            return
        await self.set_chat_commands(message, staff=False, linked=bool(user))
        await message.answer(
            f"{self.settings.site_name} 🌿\n\nПоможем организовать ваше событие.",
            reply_markup=client_menu(),
        )
    async def set_chat_commands(
        self,
        message: Message,
        *,
        staff: bool,
        linked: bool = True,
    ) -> None:
        commands = [
            BotCommand(command="start", description="Главное меню"),
            BotCommand(command="help", description="Помощь"),
            BotCommand(command="cancel", description="Отменить текущий сценарий"),
        ]
        if staff:
            commands[1:1] = [
                BotCommand(command="my_orders", description="Мои события"),
                BotCommand(command="today", description="Сегодня"),
                BotCommand(command="status", description="Статус привязки"),
            ]
        else:
            commands.insert(1, BotCommand(command="job", description="Работа у нас"))
            if linked:
                commands[1:1] = [
                    BotCommand(command="my_orders", description="Мои события"),
                    BotCommand(command="status", description="Статус привязки"),
                ]
        bot = message.bot
        if bot is None:
            LOGGER.error(
                "Bot context is unavailable for chat=%s",
                identifier_fingerprint(message.chat.id),
            )
            return
        try:
            await bot.set_my_commands(
                commands,
                scope=BotCommandScopeChat(chat_id=message.chat.id),
            )
        except TelegramBadRequest as error:
            LOGGER.warning(
                "Unable to set command scope chat=%s: %s",
                identifier_fingerprint(message.chat.id),
                error,
            )
    async def help_command(self, message: Message) -> None:
        user = await self.database.linked_user(message.from_user.id) if message.from_user else None
        if user and user["role"] != "client":
            await message.answer(
                "/today — задачи и события на сегодня\n"
                "/my_orders — доступные события\n"
                "/status — состояние привязки\n"
                "/cancel — отменить текущий сценарий"
            )
        else:
            await message.answer(
                "Заказать мероприятие — кнопка в главном меню\n"
                "/my_orders — ваши события\n"
                "/job — работа у нас\n"
                "/cancel — отменить текущий сценарий"
            )
    async def cancel(self, message: Message) -> None:
        if not message.from_user:
            return
        await self.database.delete_flow(message.from_user.id, message.chat.id)
        await message.answer("Текущий сценарий отменён.", reply_markup=remove_keyboard())
        await self.show_main_menu(message)
    async def status(self, message: Message) -> None:
        user = await self.database.linked_user(message.from_user.id) if message.from_user else None
        if not user:
            await message.answer(
                "Telegram пока не привязан к аккаунту. "
                "Клиентский аккаунт будет создан после отправки заявки."
            )
            return
        await message.answer(
            f"Связь активна.\nРоль: {self.role_label(str(user['role']))}."
        )
    async def start_quiz(self, event: Message | CallbackQuery) -> None:
        message = event.message if isinstance(event, CallbackQuery) else event
        sender = event.from_user
        if not isinstance(message, Message) or not sender:
            return
        payload = {
            "index": 0,
            "answers": {"client_name": sender.full_name[:190]},
            "files": [],
            "submission_key": secrets.token_hex(32),
            "identity": {
                "telegram_user_id": sender.id,
                "telegram_username": sender.username or "",
                "full_name": sender.full_name,
            },
        }
        await self.database.save_flow(
            sender.id,
            message.chat.id,
            "quiz",
            QUIZ_STEPS[0],
            payload,
        )
        if isinstance(event, CallbackQuery):
            await event.answer()
        await self.send_flow_prompt(message, "quiz", payload)
    async def start_job(self, message: Message) -> None:
        if not message.from_user:
            return
        payload = {
            "index": 0,
            "answers": {
                "telegram": message.from_user.username or "",
            },
            "files": [],
            "submission_key": secrets.token_hex(32),
            "identity": {
                "telegram_user_id": message.from_user.id,
                "telegram_username": message.from_user.username or "",
                "full_name": message.from_user.full_name,
            },
        }
        await self.database.save_flow(
            message.from_user.id,
            message.chat.id,
            "job",
            JOB_STEPS[0],
            payload,
        )
        await message.answer(
            "Хотите работать с нами?\n\nЗаполните короткую анкету. "
            "Аккаунт сотрудника автоматически не создаётся."
        )
        await self.send_flow_prompt(message, "job", payload)
    async def start_staff(self, message: Message) -> None:
        if not message.from_user:
            return
        linked = await self.database.linked_user(message.from_user.id)
        if linked and linked["role"] != "client":
            await self.set_chat_commands(message, staff=True)
            await message.answer(
                f"Рабочий аккаунт уже подключён: {self.role_label(str(linked['role']))}.",
                reply_markup=role_menu(str(linked["role"])),
            )
            return
        payload = {"login": ""}
        await self.database.save_flow(
            message.from_user.id,
            message.chat.id,
            "staff",
            "staff_login",
            payload,
        )
        await message.answer(
            "Вход для сотрудников\n\nВведите email, телефон или Telegram-логин."
        )
    async def my_orders(self, event: Message | CallbackQuery) -> None:
        message = event.message if isinstance(event, CallbackQuery) else event
        sender = event.from_user
        if not message or not sender:
            return
        user = await self.database.linked_user(sender.id)
        if isinstance(event, CallbackQuery):
            await event.answer()
        if not user:
            await message.answer("Сначала отправьте заявку или привяжите аккаунт.")
            return
        orders = await self.database.orders_for_user(user)
        if not orders:
            if user["role"] == "client":
                leads = await self.database.client_leads(int(user["id"]))
                if leads:
                    lines = ["Ваши заявки:"]
                    for lead in leads:
                        lines.append(
                            f"#{lead['id']} · {lead['event_type']} · "
                            f"{russian_date(lead['event_date'])} · {status_label(lead['status'])}"
                        )
                    await message.answer("\n".join(lines))
                    return
            await message.answer("Доступных событий пока нет.")
            return
        from aiogram.types import InlineKeyboardButton, InlineKeyboardMarkup
        keyboard = InlineKeyboardMarkup(inline_keyboard=[
            [InlineKeyboardButton(
                text=f"#{order['id']} · {order['title'][:35]}",
                callback_data=f"order:details:{order['id']}",
            )]
            for order in orders
        ])
        await message.answer("Мои события", reply_markup=keyboard)
    async def order_details(self, callback: CallbackQuery) -> None:
        if not isinstance(callback.message, Message) or not callback.from_user:
            return
        data = callback.data
        if not isinstance(data, str):
            await callback.answer("Некорректный заказ", show_alert=True)
            return
        try:
            order_id = int(data.rsplit(":", 1)[1])
        except (ValueError, IndexError):
            await callback.answer("Некорректный заказ", show_alert=True)
            return
        user = await self.database.linked_user(callback.from_user.id)
        order = await self.database.order_for_user(user, order_id) if user else None
        if not order:
            await callback.answer("Заказ недоступен", show_alert=True)
            return
        organizer = " ".join(filter(None, [
            order.get("organizer_first_name"),
            order.get("organizer_last_name"),
        ])) or "Пока не назначен"
        lines = [
            str(order["title"]),
            "",
            f"Тип: {order.get('event_type') or 'Уточняется'}",
            f"Дата: {russian_date(order.get('event_date'))}",
            f"Время: {short_time(order.get('start_time'))}",
            f"Площадка: {order.get('venue') or 'Уточняется'}",
            f"Адрес: {order.get('address') or 'Уточняется'}",
            f"Статус: {status_label(order.get('status'))}",
            f"Организатор: {organizer}",
        ]
        markup = None
        if user and user["role"] == "client":
            from aiogram.types import InlineKeyboardButton, InlineKeyboardMarkup
            account_url = (
                f"{self.settings.app_url.rstrip('/')}/account/order.php?id={order_id}"
            )
            markup = InlineKeyboardMarkup(inline_keyboard=[
                [
                    InlineKeyboardButton(
                        text="Открыть событие",
                        url=account_url,
                    ),
                    InlineKeyboardButton(
                        text="Документы",
                        url=account_url + "#documents",
                    ),
                ],
                [
                    InlineKeyboardButton(
                        text="Концепция",
                        url=account_url + "#concept",
                    ),
                    InlineKeyboardButton(
                        text="Назад",
                        callback_data="orders:list",
                    ),
                ],
            ])
        await callback.answer()
        await callback.message.answer("\n".join(lines), reply_markup=markup)
    async def today(self, message: Message) -> None:
        user = await self.database.linked_user(message.from_user.id) if message.from_user else None
        if not user or user["role"] == "client":
            await message.answer("Команда доступна только сотрудникам.")
            return
        assignments = await self.database.staff_assignments(int(user["id"]))
        tasks = await self.database.staff_tasks(int(user["id"]), today_only=True)
        lines = ["Сегодня"]
        today = __import__("datetime").date.today()
        for item in assignments:
            if item.get("event_date") == today:
                lines.append(
                    f"\n{item['title']}\n{short_time(item.get('start_time'))} · "
                    f"{item['location']}"
                )
        for task in tasks:
            lines.append(f"\nЗадача: {task['title']} · {status_label(task['status'])}")
        if len(lines) == 1:
            lines.append("\nНа сегодня нет назначенных событий и задач.")
        await message.answer("\n".join(lines))
    async def callback(self, callback: CallbackQuery, bot: Bot) -> None:
        data = callback.data or ""
        if data.startswith(("auth:confirm:", "auth:cancel:")):
            await self.telegram_auth_callback(callback, data)
        elif data == "quiz:start":
            await self.start_quiz(callback)
        elif data == "orders:list":
            await self.my_orders(callback)
        elif data.startswith("order:details:"):
            await self.order_details(callback)
        elif data in {"flow:back", "flow:skip", "flow:cancel", "flow:files_done"}:
            await self.flow_callback(callback, data, bot)
        elif data.startswith("quiz:event:"):
            await self.quiz_option(
                callback,
                "event_type",
                data.rsplit(":", 1)[1],
                bot,
            )
        elif data.startswith("quiz:service:"):
            await self.toggle_service(callback, data.rsplit(":", 1)[1])
        elif data == "quiz:services_done":
            await self.complete_services(callback, bot)
        elif data.startswith("job:role:"):
            await self.job_option(
                callback,
                "desired_role",
                data.rsplit(":", 1)[1],
                bot,
            )
        elif data.startswith("job:experience:"):
            await self.job_option(
                callback,
                "experience",
                data.rsplit(":", 1)[1],
                bot,
            )
        elif data.startswith("assignment:"):
            await self.assignment_callback(callback, data)
        elif data.startswith(("staff:", "manager:")):
            await self.staff_menu_callback(callback, data)
    async def telegram_auth_callback(
        self,
        callback: CallbackQuery,
        data: str,
    ) -> None:
        if not callback.from_user:
            return
        parts = data.split(":", 3)
        if len(parts) != 4:
            await callback.answer("Некорректный запрос", show_alert=True)
            return
        _, action, purpose_code, token = parts
        purpose = {
            "l": "login",
            "r": "password_reset",
        }.get(purpose_code)
        valid_token = len(token) == 48 and all(
            character in "0123456789abcdefABCDEF"
            for character in token
        )
        if purpose is None or not valid_token:
            await callback.answer("Некорректный запрос", show_alert=True)
            return
        if action == "confirm":
            success, text = await self.database.approve_auth_request(
                token,
                purpose,
                callback.from_user.id,
            )
        elif action == "cancel":
            success, text = await self.database.cancel_auth_request(
                token,
                purpose,
                callback.from_user.id,
            )
        else:
            await callback.answer("Некорректное действие", show_alert=True)
            return
        await callback.answer(
            "Готово" if success else "Запрос не выполнен",
            show_alert=not success,
        )
        if isinstance(callback.message, Message):
            try:
                await callback.message.edit_text(text)
            except TelegramBadRequest:
                await callback.message.answer(text)
    async def active_flow_message(self, message: Message, bot: Bot) -> None:
        if not message.from_user:
            return
        session = await self.database.flow_session(message.from_user.id, message.chat.id)
        if not session:
            linked = await self.database.linked_user(message.from_user.id)
            await message.answer(
                "Выберите действие в меню или используйте /help.",
                reply_markup=(
                    role_menu(str(linked["role"]))
                    if linked and linked["role"] != "client"
                    else client_menu()
                ),
            )
            return
        flow_type = str(session["flow_type"])
        payload = dict(session["payload_json"] or {})
        state = str(session["state"])
        if flow_type == "staff":
            await self.staff_message(message, state, payload)
            return
        if state in {"references", "portfolio_files"}:
            await self.receive_flow_file(message, flow_type, payload)
            return
        value = message.text or ""
        if message.contact:
            if message.contact.user_id not in {None, message.from_user.id}:
                await message.answer("Можно отправить только собственный контакт.")
                return
            value = message.contact.phone_number
        try:
            self.apply_text_answer(flow_type, state, value, payload)
        except ValueError as error:
            await message.answer(str(error))
            return
        await self.advance(message, flow_type, payload, bot)
    async def staff_message(self, message: Message, state: str, payload: dict[str, Any]) -> None:
        if not message.from_user:
            return
        if state == "staff_login":
            login = (message.text or "").strip()
            if not login or len(login) > 190:
                await message.answer("Введите корректный логин.")
                return
            payload["login"] = login
            await self.database.save_flow(
                message.from_user.id,
                message.chat.id,
                "staff",
                "staff_password",
                payload,
            )
            await message.answer(
                "Введите пароль. После проверки сообщение будет удалено из этого чата."
            )
            return
        password = message.text or ""
        try:
            result = await self.api.post_json("/api/internal/telegram-staff-auth.php", {
                "action": "staff_auth",
                "login": payload.get("login", ""),
                "password": password,
                "telegram_user_id": message.from_user.id,
                "telegram_chat_id": message.chat.id,
                "telegram_username": message.from_user.username or "",
            })
        except InternalApiError as error:
            await message.answer(str(error))
            return
        finally:
            password = ""
            try:
                await message.delete()
            except TelegramBadRequest as error:
                LOGGER.warning(
                    "Unable to remove sensitive authentication message "
                    "chat=%s message=%s error=%s",
                    identifier_fingerprint(message.chat.id),
                    message.message_id,
                    type(error).__name__,
                )
        await self.database.delete_flow(message.from_user.id, message.chat.id)
        user = result["user"]
        await self.set_chat_commands(message, staff=True)
        await message.answer(
            f"Вход выполнен. Роль: {self.role_label(str(user['role']))}.",
            reply_markup=role_menu(str(user["role"])),
        )
    async def send_flow_prompt(
        self,
        message: Message,
        flow_type: str,
        payload: dict[str, Any],
    ) -> None:
        steps = QUIZ_STEPS if flow_type == "quiz" else JOB_STEPS
        index = int(payload.get("index", 0))
        step = steps[index]
        prompt = quiz_prompt(step, index) if flow_type == "quiz" else job_prompt(step, index)
        if step == "event_type":
            markup = event_types()
        elif step == "services":
            markup = services(set(payload.get("service_keys", [])))
        elif step == "role":
            markup = job_roles()
        elif step == "experience":
            markup = job_experience()
        elif step in {"references", "portfolio_files"}:
            markup = references(len(payload.get("files", [])))
        else:
            markup = flow_navigation(index > 0, prompt.optional)
        if step in {"phone"}:
            await message.answer(prompt.text, reply_markup=contact_keyboard())
            await message.answer("Навигация", reply_markup=markup)
        else:
            await message.answer(prompt.text, reply_markup=markup)
    async def flow_callback(self, callback: CallbackQuery, action: str, bot: Bot) -> None:
        if not isinstance(callback.message, Message) or not callback.from_user:
            return
        session = await self.database.flow_session(callback.from_user.id, callback.message.chat.id)
        if not session:
            await callback.answer("Сценарий уже завершён", show_alert=True)
            return
        flow_type = str(session["flow_type"])
        payload = dict(session["payload_json"] or {})
        if action == "flow:cancel":
            await self.database.delete_flow(callback.from_user.id, callback.message.chat.id)
            await callback.answer()
            await callback.message.answer("Сценарий отменён.", reply_markup=remove_keyboard())
            return
        if action == "flow:back":
            payload["index"] = max(0, int(payload.get("index", 0)) - 1)
            await self.persist_and_prompt(callback.message, callback.from_user.id, flow_type, payload)
            await callback.answer()
            return
        if action == "flow:files_done":
            await callback.answer()
            await self.advance(callback.message, flow_type, payload, bot)
            return
        steps = QUIZ_STEPS if flow_type == "quiz" else JOB_STEPS
        step = steps[int(payload.get("index", 0))]
        optional = OPTIONAL_QUIZ if flow_type == "quiz" else OPTIONAL_JOB
        if step not in optional:
            await callback.answer("Этот шаг нельзя пропустить", show_alert=True)
            return
        await callback.answer()
        await self.advance(callback.message, flow_type, payload, bot)
    async def quiz_option(
        self,
        callback: CallbackQuery,
        field: str,
        key: str,
        bot: Bot,
    ) -> None:
        if key not in EVENT_TYPES or not isinstance(callback.message, Message):
            await callback.answer("Неизвестный вариант", show_alert=True)
            return
        session = await self.database.flow_session(callback.from_user.id, callback.message.chat.id)
        if not session or session["state"] != "event_type":
            await callback.answer("Шаг уже завершён")
            return
        payload = dict(session["payload_json"])
        payload["answers"][field] = EVENT_TYPES[key]
        await callback.answer()
        await self.advance(callback.message, "quiz", payload, bot)
    async def toggle_service(self, callback: CallbackQuery, key: str) -> None:
        if key not in SERVICES or not isinstance(callback.message, Message):
            await callback.answer("Неизвестная услуга", show_alert=True)
            return
        session = await self.database.flow_session(callback.from_user.id, callback.message.chat.id)
        if not session or session["state"] != "services":
            await callback.answer("Шаг уже завершён")
            return
        payload = dict(session["payload_json"])
        selected = set(payload.get("service_keys", []))
        selected.symmetric_difference_update({key})
        payload["service_keys"] = sorted(selected)
        payload["answers"]["services_needed"] = [SERVICES[item] for item in sorted(selected)]
        await self.database.save_flow(
            callback.from_user.id,
            callback.message.chat.id,
            "quiz",
            "services",
            payload,
        )
        await callback.message.edit_reply_markup(reply_markup=services(selected))
        await callback.answer()
    async def complete_services(self, callback: CallbackQuery, bot: Bot) -> None:
        if not isinstance(callback.message, Message):
            return
        session = await self.database.flow_session(callback.from_user.id, callback.message.chat.id)
        payload = dict(session["payload_json"]) if session else {}
        if not payload.get("answers", {}).get("services_needed"):
            await callback.answer("Выберите хотя бы одну услугу", show_alert=True)
            return
        await callback.answer()
        await self.advance(callback.message, "quiz", payload, bot)
    async def job_option(
        self,
        callback: CallbackQuery,
        field: str,
        key: str,
        bot: Bot,
    ) -> None:
        options = JOB_ROLES if field == "desired_role" else JOB_EXPERIENCE
        if key not in options or not isinstance(callback.message, Message):
            await callback.answer("Неизвестный вариант", show_alert=True)
            return
        session = await self.database.flow_session(callback.from_user.id, callback.message.chat.id)
        if not session:
            await callback.answer("Сценарий завершён")
            return
        payload = dict(session["payload_json"])
        payload["answers"][field] = key
        await callback.answer()
        await self.advance(callback.message, "job", payload, bot)
    async def receive_flow_file(
        self,
        message: Message,
        flow_type: str,
        payload: dict[str, Any],
    ) -> None:
        if not message.from_user:
            return
        files = list(payload.get("files", []))
        if len(files) >= self.settings.max_reference_files:
            await message.answer(f"Можно приложить не более {self.settings.max_reference_files} файлов.")
            return
        item: dict[str, Any] | None = None
        if message.photo:
            photo = message.photo[-1]
            if (photo.file_size or 0) > self.settings.max_photo_bytes:
                await message.answer("Фотография превышает допустимый размер.")
                return
            item = {
                "file_id": photo.file_id,
                "file_unique_id": photo.file_unique_id,
                "name": f"telegram-photo-{len(files) + 1}.jpg",
                "mime": "image/jpeg",
                "size": photo.file_size or 0,
            }
        elif message.document:
            document = message.document
            mime = document.mime_type or ""
            if mime not in {"image/jpeg", "image/png", "image/webp", "application/pdf"}:
                await message.answer("Разрешены JPG, PNG, WebP и PDF.")
                return
            if (document.file_size or 0) > self.settings.max_document_bytes:
                await message.answer("Документ превышает допустимый размер.")
                return
            item = {
                "file_id": document.file_id,
                "file_unique_id": document.file_unique_id,
                "name": document.file_name or f"telegram-file-{len(files) + 1}",
                "mime": mime,
                "size": document.file_size or 0,
            }
        if item is None:
            await message.answer("Отправьте фотографию или PDF.")
            return
        if any(file["file_unique_id"] == item["file_unique_id"] for file in files):
            await message.answer("Этот файл уже добавлен.")
            return
        files.append(item)
        payload["files"] = files
        steps = QUIZ_STEPS if flow_type == "quiz" else JOB_STEPS
        state = steps[int(payload["index"])]
        await self.database.save_flow(
            message.from_user.id,
            message.chat.id,
            flow_type,
            state,
            payload,
        )
        await message.answer(
            f"Получено файлов: {len(files)}.",
            reply_markup=references(len(files)),
        )
    def apply_text_answer(
        self,
        flow_type: str,
        state: str,
        value: str,
        payload: dict[str, Any],
    ) -> None:
        value = value.strip()
        if not value:
            raise ValueError("Введите ответ или нажмите «Пропустить».")
        answers = payload.setdefault("answers", {})
        if flow_type == "quiz":
            if state == "event_date":
                answers["event_date"] = parse_date(value)
            elif state == "event_time":
                answers["event_time"] = parse_time(value)
            elif state == "city":
                answers["location_city"] = value[:120]
            elif state == "venue":
                answers["venue"] = value[:190]
            elif state == "address":
                answers["location_details"] = value[:255]
            elif state == "guests":
                try:
                    guests = int(value)
                except ValueError as error:
                    raise ValueError(
                        "Укажите количество гостей целым числом от 1 до 10 000."
                    ) from error
                if not 1 <= guests <= 10000:
                    raise ValueError("Укажите количество гостей от 1 до 10 000.")
                answers["guests_count"] = guests
            elif state == "budget":
                answers["budget_range"] = value[:120]
            elif state == "style":
                answers["style"] = value[:190]
            elif state == "colors":
                answers["color_palette"] = value[:255]
            elif state == "wishes":
                answers["comment"] = value[:10000]
            elif state == "phone":
                answers["phone"] = parse_phone(value)
            elif state == "email":
                answers["email"] = parse_email(value)
            else:
                raise ValueError("Используйте кнопки этого шага.")
        else:
            if state == "full_name":
                if len(value) < 3 or len(value) > 190:
                    raise ValueError("Укажите имя и фамилию.")
                answers["full_name"] = value
            elif state == "phone":
                answers["phone"] = parse_phone(value)
            elif state == "email":
                answers["email"] = parse_email(value)
            elif state == "salary":
                try:
                    number = float(value.replace(" ", "").replace(",", "."))
                except ValueError as error:
                    raise ValueError(
                        "Укажите желаемую оплату числом, например 80000."
                    ) from error
                if number < 0 or number > 100_000_000:
                    raise ValueError("Проверьте желаемую оплату.")
                answers["desired_salary"] = str(number)
            elif state == "about":
                answers["comment"] = value[:3000]
            elif state == "schedule":
                answers["preferred_schedule"] = value[:500]
            elif state == "portfolio_url":
                if not value.startswith(("https://", "http://")):
                    raise ValueError("Ссылка должна начинаться с https:// или http://.")
                answers["portfolio_url"] = value[:500]
            else:
                raise ValueError("Используйте кнопки этого шага.")
    async def advance(
        self,
        message: Message,
        flow_type: str,
        payload: dict[str, Any],
        bot: Bot,
    ) -> None:
        steps = QUIZ_STEPS if flow_type == "quiz" else JOB_STEPS
        next_index = int(payload.get("index", 0)) + 1
        if next_index >= len(steps):
            await self.finish_flow(message, flow_type, payload, bot)
            return
        payload["index"] = next_index
        await self.persist_and_prompt(message, message.chat.id, flow_type, payload)
    async def persist_and_prompt(
        self,
        message: Message,
        telegram_user_id: int,
        flow_type: str,
        payload: dict[str, Any],
    ) -> None:
        steps = QUIZ_STEPS if flow_type == "quiz" else JOB_STEPS
        state = steps[int(payload["index"])]
        await self.database.save_flow(
            telegram_user_id,
            message.chat.id,
            flow_type,
            state,
            payload,
        )
        await self.send_flow_prompt(message, flow_type, payload)
    async def finish_flow(
        self,
        message: Message,
        flow_type: str,
        payload: dict[str, Any],
        bot: Bot,
    ) -> None:
        identity = dict(payload.get("identity") or {})
        telegram_user_id = int(identity.get("telegram_user_id") or message.chat.id)
        telegram_username = str(identity.get("telegram_username") or "")
        full_name = str(identity.get("full_name") or payload.get("answers", {}).get("client_name") or "Клиент")
        try:
            if flow_type == "quiz":
                result = await self.api.post_json("/api/internal/telegram-lead.php", {
                    "action": "submit_quiz",
                    "identity": {
                        "telegram_user_id": telegram_user_id,
                        "telegram_chat_id": message.chat.id,
                        "telegram_username": telegram_username,
                    },
                    "answers": normalize_quiz(payload, full_name),
                    "submission_key": payload["submission_key"],
                })
                entity = "lead"
                entity_id = int(result["lead_id"])
            else:
                result = await self.api.post_json("/api/internal/telegram-candidate.php", {
                    "action": "submit_candidate",
                    "telegram_user_id": telegram_user_id,
                    "telegram_chat_id": message.chat.id,
                    "telegram_username": telegram_username,
                    "data": normalize_job(payload),
                    "submission_key": payload["submission_key"],
                })
                entity = "candidate"
                entity_id = int(result["candidate_id"])
            failed_files = await self.upload_flow_files(
                bot,
                payload.get("files", []),
                entity,
                entity_id,
                telegram_user_id,
            )
        except InternalApiError as error:
            await message.answer(str(error))
            return
        await self.database.delete_flow(telegram_user_id, message.chat.id)
        if flow_type == "quiz":
            text = (
                f"Спасибо.\n\nЗаявка №{entity_id} отправлена. "
                "Менеджер свяжется с вами, чтобы уточнить детали."
            )
            if result.get("password_setup_url"):
                text += (
                    "\n\nДля входа в кабинет задайте пароль по одноразовой ссылке:\n"
                    + str(result["password_setup_url"])
                )
            await message.answer(text, reply_markup=client_menu())
        else:
            await message.answer(
                "Спасибо!\n\nЗаявка отправлена команде «Азбуки Декора». "
                "Мы свяжемся с вами, если ваш опыт подойдёт.",
                reply_markup=remove_keyboard(),
            )
        if failed_files:
            await message.answer(
                f"Не удалось прикрепить файлов: {failed_files}. "
                "Заявка сохранена; материалы можно передать менеджеру отдельно."
            )
    async def upload_flow_files(
        self,
        bot: Bot,
        files: list[dict[str, Any]],
        entity: str,
        entity_id: int,
        telegram_user_id: int,
    ) -> int:
        failed = 0
        for item in files:
            try:
                buffer = io.BytesIO()
                await bot.download(item["file_id"], destination=buffer)
                content = buffer.getvalue()
                expected_limit = (
                    self.settings.max_photo_bytes
                    if str(item["mime"]).startswith("image/")
                    else self.settings.max_document_bytes
                )
                if not content or len(content) > expected_limit:
                    raise ValueError("File size mismatch")
                await self.api.post_file(
                    "/api/internal/telegram-file.php",
                    {
                        "entity": entity,
                        "entity_id": entity_id,
                        "telegram_user_id": telegram_user_id,
                        "telegram_file_id": item["file_id"],
                    },
                    str(item["name"])[:190],
                    content,
                    str(item["mime"]),
                )
            except Exception:
                failed += 1
                LOGGER.exception(
                    "Unable to attach Telegram file %s to %s %s",
                    hashlib.sha256(str(item.get("file_unique_id", "")).encode()).hexdigest()[:12],
                    entity,
                    entity_id,
                )
        return failed
    async def assignment_callback(self, callback: CallbackQuery, data: str) -> None:
        if not isinstance(callback.message, Message):
            return
        parts = data.split(":")
        if len(parts) != 3 or parts[1] not in {"accept", "decline"} or not parts[2].isdigit():
            await callback.answer("Некорректное приглашение", show_alert=True)
            return
        decision = "confirmed" if parts[1] == "accept" else "declined"
        try:
            await self.api.post_json("/api/internal/telegram-assignment.php", {
                "action": "respond_assignment",
                "telegram_user_id": callback.from_user.id,
                "assignment_id": int(parts[2]),
                "decision": decision,
                "reason": "",
            })
        except InternalApiError as error:
            await callback.answer(str(error), show_alert=True)
            return
        await callback.answer("Ответ сохранён")
        await callback.message.edit_reply_markup(reply_markup=None)
        await callback.message.answer(
            "Участие подтверждено. Приглашение в группу команды придёт отдельно."
            if decision == "confirmed"
            else "Вы отклонили приглашение."
        )
    async def staff_menu_callback(self, callback: CallbackQuery, data: str) -> None:
        if not isinstance(callback.message, Message):
            return
        user = await self.database.linked_user(callback.from_user.id)
        if not user or user["role"] == "client":
            await callback.answer("Рабочий доступ не подтверждён", show_alert=True)
            return
        await callback.answer()
        if data == "staff:today":
            assignments = await self.database.staff_assignments(int(user["id"]))
            tasks = await self.database.staff_tasks(int(user["id"]), today_only=True)
            lines = ["Сегодня"]
            today = __import__("datetime").date.today()
            lines.extend(
                f"\n{item['title']}\n{short_time(item.get('start_time'))} · {item['location']}"
                for item in assignments
                if item.get("event_date") == today
            )
            lines.extend(
                f"\nЗадача: {task['title']} · {status_label(task['status'])}"
                for task in tasks
            )
            if len(lines) == 1:
                lines.append("\nНа сегодня нет назначенных событий и задач.")
            await callback.message.answer("\n".join(lines))
        elif data == "staff:tasks":
            tasks = await self.database.staff_tasks(int(user["id"]))
            lines = ["Мои задачи"] + [
                f"\n{task['title']}\n{task['order_title']} · {status_label(task['status'])}"
                for task in tasks
            ]
            if len(lines) == 1:
                lines.append("\nНазначенных задач пока нет.")
            await callback.message.answer("\n".join(lines))
        elif data == "staff:calendar":
            await callback.message.answer(
                "Календарь доступен в кабинете:\n"
                f"{self.settings.app_url}/organizer/calendar.php"
            )
        elif data.startswith("manager:"):
            leads = await self.database.manager_leads(
                int(user["id"]),
                only_new=data == "manager:new",
            )
            lines = ["Новые заявки" if data == "manager:new" else "Мои заявки"]
            lines.extend(
                f"\n#{lead['id']} · {lead['client_name']} · "
                f"{lead['event_type']} · {russian_date(lead['event_date'])}"
                for lead in leads
            )
            if len(lines) == 1:
                lines.append("\nЗаявок нет.")
            await callback.message.answer("\n".join(lines))
    @staticmethod
    def role_label(role: str) -> str:
        return {
            "client": "Клиент",
            "manager": "Менеджер",
            "organizer": "Организатор",
            "florist": "Флорист",
            "courier": "Курьер",
            "installer": "Монтажник",
            "helper": "Помощник",
            "worker": "Сотрудник",
            "admin": "Администратор",
        }.get(role, "Пользователь")
def build_private_router(
    database: Database,
    settings: Settings,
    api: InternalApiClient,
) -> Router:
    router = Router(name="private")
    handlers = PrivateHandlers(database, settings, api)
    private = F.chat.type == "private"
    router.message.register(handlers.start, CommandStart(), private)
    router.message.register(handlers.help_command, Command("help"), private)
    router.message.register(handlers.cancel, Command("cancel"), private)
    router.message.register(handlers.start_job, Command("job"), private)
    router.message.register(handlers.start_staff, Command("staff"), private)
    router.message.register(handlers.my_orders, Command("my_orders"), private)
    router.message.register(handlers.status, Command("status"), private)
    router.message.register(handlers.today, Command("today"), private)
    router.callback_query.register(handlers.callback)
    router.message.register(handlers.active_flow_message, private)
    return router
