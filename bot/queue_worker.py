from __future__ import annotations
import asyncio
import json
import logging
import os
import socket
from datetime import datetime, timedelta, timezone
from pathlib import Path
from typing import Any
from aiogram import Bot
from aiogram.exceptions import (
    TelegramBadRequest,
    TelegramForbiddenError,
    TelegramNetworkError,
    TelegramRetryAfter,
    TelegramServerError,
)
from aiogram.types import ChatPermissions, FSInputFile, ReplyParameters
from database import Database
from formatting import russian_date, short_time
from keyboards import assignment_actions, login_code_copy
from settings import ROOT_DIR, RUNTIME_DIR, Settings
LOGGER = logging.getLogger("azbuka_bot.queue")
ROLE_LABELS = {
    "client": "Клиент",
    "manager": "Менеджер",
    "organizer": "Организатор",
    "florist": "Флорист",
    "courier": "Курьер",
    "installer": "Монтажник",
    "helper": "Помощник",
    "worker": "Сотрудник",
    "admin": "Администратор",
}
class RetryableOperation(RuntimeError):
    pass
class WaitingForGroup(RetryableOperation):
    pass
class PermanentOperation(RuntimeError):
    pass
def atomic_json(path: Path, payload: dict[str, Any]) -> None:
    temporary = path.with_suffix(".tmp")
    temporary.write_text(json.dumps(payload, ensure_ascii=False), encoding="utf-8")
    os.replace(temporary, path)
class QueueWorker:
    def __init__(self, bot: Bot, database: Database, settings: Settings) -> None:
        self.bot = bot
        self.database = database
        self.settings = settings
        self.worker_id = f"{socket.gethostname()}:{os.getpid()}"
        self.stop_event = asyncio.Event()
        self.receipt_dir = RUNTIME_DIR / "delivery_receipts"
        self.receipt_dir.mkdir(parents=True, exist_ok=True)
    async def run(self) -> None:
        duplicates = (
            await self.database.cancel_duplicate_suspicious_login_notifications()
        )
        if duplicates:
            LOGGER.warning(
                "Cancelled %s duplicate suspicious-login notifications",
                duplicates,
            )
        recovered = await self.database.recover_stale_notifications()
        if recovered:
            LOGGER.warning("Recovered %s stale queue items", recovered)
        while not self.stop_event.is_set():
            try:
                item = await self.database.claim_notification(self.worker_id)
                if not item:
                    await asyncio.sleep(self.settings.queue_poll_seconds)
                    continue
                await self.process(item)
            except asyncio.CancelledError:
                raise
            except Exception:
                LOGGER.exception("Queue worker iteration failed")
                await asyncio.sleep(5)
    async def process(self, item: dict[str, Any]) -> None:
        payload = item.get("payload_json")
        if isinstance(payload, str):
            payload = json.loads(payload)
        payload = payload if isinstance(payload, dict) else {}
        operation_id = int(payload.get("operation_id") or 0) or None
        try:
            event_type = str(item["event_type"])
            telegram_message_id = None
            if event_type == "telegram.chat.provision":
                await self.provision_group(payload)
            elif event_type == "telegram.group.register":
                await self.register_pool_group(payload)
            elif event_type == "event_assignment_invite":
                await self.send_assignment_invite(item, payload)
            elif event_type == "telegram.invite.create":
                await self.create_invite(payload)
            elif event_type == "telegram.message.send":
                telegram_message_id = await self.send_site_message(payload)
            elif event_type == "telegram.message.edit":
                await self.edit_site_message(payload)
            elif event_type == "telegram.message.delete":
                await self.delete_site_message(payload)
            elif event_type == "telegram.membership.remove":
                await self.remove_membership(payload)
            else:
                await self.send_notification(item, payload)
            await self.database.operation_complete(operation_id, telegram_message_id)
            await self.database.notification_sent(int(item["id"]))
            self.remove_operation_receipt(operation_id)
            self.remove_queue_receipt(int(item["id"]))
        except TelegramRetryAfter as error:
            await self.database.operation_failed(operation_id, str(error))
            await self.database.notification_failed(item, str(error), int(error.retry_after) + 1)
        except WaitingForGroup as error:
            await self.database.operation_waiting_for_group(operation_id, str(error))
            await self.database.notification_failed(item, str(error))
        except (TelegramNetworkError, TelegramServerError, RetryableOperation) as error:
            await self.database.operation_failed(operation_id, str(error))
            await self.database.notification_failed(item, str(error))
        except (TelegramForbiddenError, PermanentOperation) as error:
            await self.database.operation_cancelled(operation_id, str(error))
            await self.database.notification_cancelled(int(item["id"]), str(error))
        except TelegramBadRequest as error:
            await self.database.operation_cancelled(operation_id, str(error))
            await self.database.notification_cancelled(int(item["id"]), str(error))
        except Exception as error:
            LOGGER.exception("Queue item %s failed", item["id"])
            await self.database.operation_failed(operation_id, str(error))
            await self.database.notification_failed(item, str(error))
    async def send_notification(
        self,
        item: dict[str, Any],
        payload: dict[str, Any],
    ) -> None:
        queue_id = int(item["id"])
        event_type = str(item.get("event_type") or "")
        receipt_chat_ids = self.queue_receipt_chat_ids(queue_id, event_type)
        if receipt_chat_ids is None:
            return
        stored_chat_ids = payload.get("_sent_chat_ids")
        sent_chat_ids = {
            int(chat_id)
            for chat_id in stored_chat_ids
            if str(chat_id).lstrip("-").isdigit()
        } if isinstance(stored_chat_ids, list) else set()
        sent_chat_ids.update(receipt_chat_ids)
        text = str(payload.get("text", "")).strip()[:4096]
        if not text:
            raise PermanentOperation("Notification payload has no text")
        chat_ids = await self.database.resolve_chat_ids(item)
        if not chat_ids:
            raise PermanentOperation("Recipient has no linked Telegram chat")
        for chat_id in chat_ids:
            if int(chat_id) in sent_chat_ids:
                continue
            parse_mode = "HTML" if event_type == "privileged_login_code" else None
            reply_markup = None
            if event_type == "privileged_login_code":
                copy_text = str(payload.get("copy_text") or "").strip()
                if len(copy_text) == 6 and copy_text.isdigit():
                    reply_markup = login_code_copy(copy_text)
            await self.bot.send_message(
                chat_id=chat_id,
                text=text,
                parse_mode=parse_mode,
                reply_markup=reply_markup,
                disable_web_page_preview=True,
            )
            sent_chat_ids.add(int(chat_id))
            try:
                self.write_queue_receipt(queue_id, event_type, sent_chat_ids)
            except OSError:
                LOGGER.exception(
                    "Unable to write queue receipt for notification %s",
                    queue_id,
                )
            await self.database.notification_recipients_delivered(
                queue_id,
                sent_chat_ids,
            )
    async def send_assignment_invite(
        self,
        item: dict[str, Any],
        payload: dict[str, Any],
    ) -> None:
        queue_id = int(item["id"])
        event_type = str(item.get("event_type") or "")
        sent_chat_ids = self.queue_receipt_chat_ids(queue_id, event_type)
        if sent_chat_ids is None:
            return
        chat_ids = await self.database.resolve_chat_ids(item)
        if not chat_ids:
            raise PermanentOperation("Worker has not linked Telegram")
        assignment_id = int(payload.get("assignment_id") or 0)
        if assignment_id <= 0:
            raise PermanentOperation("Assignment id is missing")
        text = (
            "Вас пригласили на мероприятие\n\n"
            f"{payload.get('title') or 'Событие'}\n"
            f"{russian_date(payload.get('event_date'))} · "
            f"{short_time(payload.get('start_time'))}\n\n"
            f"Площадка: {payload.get('location') or 'Уточняется'}"
        )
        for chat_id in chat_ids:
            if int(chat_id) in sent_chat_ids:
                continue
            await self.bot.send_message(
                chat_id,
                text,
                reply_markup=assignment_actions(assignment_id),
            )
            sent_chat_ids.add(int(chat_id))
            self.write_queue_receipt(queue_id, event_type, sent_chat_ids)
    async def provision_group(self, payload: dict[str, Any]) -> None:
        order_id = int(payload.get("order_id") or 0)
        chat_kind = str(payload.get("chat_kind") or "")
        if order_id <= 0 or chat_kind not in {"order_client", "order_team"}:
            raise PermanentOperation("Invalid group provisioning payload")
        binding = await self.database.reserve_group(order_id, chat_kind)
        if not binding:
            await self.notify_pool_shortage(order_id, chat_kind)
            raise WaitingForGroup("No available Telegram group in pool")
        if binding.get("status") == "archived":
            raise PermanentOperation(
                "An archived Telegram group binding cannot be reactivated"
            )
        try:
            telegram_chat_id = int(binding["telegram_chat_id"])
            me = await self.bot.get_me()
            member = await self.bot.get_chat_member(telegram_chat_id, me.id)
            required = (
                member.status in {"administrator", "creator"}
                and bool(getattr(member, "can_invite_users", False))
                and bool(getattr(member, "can_delete_messages", False))
                and bool(getattr(member, "can_manage_chat", False))
                and bool(getattr(member, "can_change_info", False))
                and bool(getattr(member, "can_restrict_members", False))
            )
            if not required:
                raise RetryableOperation(
                    "Bot has insufficient group administrator rights"
                )
            title = self.group_title(binding)
            description = (
                f"Рабочая группа заказа #{order_id}. "
                "Доступ выдаётся персонально через бота. Не пересылайте приглашения."
            )[:255]
            await self.bot.set_chat_title(telegram_chat_id, title)
            await self.bot.set_chat_description(telegram_chat_id, description)
            await self.bot.set_chat_permissions(
                telegram_chat_id,
                ChatPermissions(
                    can_send_messages=True,
                    can_send_photos=True,
                    can_send_documents=True,
                ),
            )
            await self.database.activate_binding(int(binding["binding_id"]))
        except Exception as error:
            await self.database.binding_error(
                int(binding["binding_id"]),
                f"{type(error).__name__}: {error}",
            )
            raise
    def group_title(self, binding: dict[str, Any]) -> str:
        client_name = " ".join(
            str(binding.get(field) or "").strip()
            for field in ("client_first_name", "client_last_name")
        ).strip()
        client_name = " ".join(client_name.split())
        chat_kind = str(binding.get("chat_kind") or "")
        label = client_name or "Клиент" if chat_kind == "order_client" else "Команда"
        return (
            f"{self.settings.site_name} · Заказ #{int(binding['order_id'])} · {label}"
        )[:128]
    async def register_pool_group(self, payload: dict[str, Any]) -> None:
        telegram_chat_id = int(payload.get("telegram_chat_id") or 0)
        kind = str(payload.get("kind") or "").strip().lower()
        if telegram_chat_id >= 0 or kind not in {"client", "team"}:
            raise PermanentOperation("Invalid Telegram group registration payload")
        chat = await self.bot.get_chat(telegram_chat_id)
        if chat.type != "supergroup":
            raise PermanentOperation("Only Telegram supergroups can enter the pool")
        me = await self.bot.get_me()
        member = await self.bot.get_chat_member(telegram_chat_id, me.id)
        rights = {
            "is_admin": member.status in {"administrator", "creator"},
            "can_invite_users": bool(getattr(member, "can_invite_users", False)),
            "can_delete_messages": bool(getattr(member, "can_delete_messages", False)),
            "can_manage_chat": bool(getattr(member, "can_manage_chat", False)),
            "can_change_info": bool(getattr(member, "can_change_info", False)),
            "can_restrict_members": bool(
                getattr(member, "can_restrict_members", False)
            ),
        }
        if not all(rights.values()):
            raise PermanentOperation(
                "Bot lacks one or more required supergroup administrator rights"
            )
        success, message = await self.database.register_group(
            telegram_chat_id,
            kind,
            chat.title or "",
            rights,
        )
        if not success:
            raise RetryableOperation(message)
        requested_by = int(payload.get("requested_by") or 0)
        if requested_by > 0:
            await self.database.execute(
                "INSERT INTO notifications (user_id,type,title,message,link) "
                "VALUES (%s,'telegram_group_registered','Telegram-группа добавлена',%s,"
                "'/admin/group-pool.php')",
                (requested_by, message),
            )
    async def notify_pool_shortage(self, order_id: int, chat_kind: str) -> None:
        key = hashlib_key(f"pool-shortage:{order_id}:{chat_kind}")
        text = (
            f"В пуле нет свободной Telegram-группы типа {chat_kind} "
            f"для заказа #{order_id}. Сайт-чат продолжает работать."
        )
        inserted = await self.database.execute(
            "INSERT INTO notification_queue "
            "(recipient_type,telegram_chat_id,event_type,payload_json,status,priority,"
            "next_attempt_at,idempotency_key) VALUES "
            "('admin',%s,'telegram_pool_shortage',%s,'pending',2,NOW(),%s) "
            "ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)",
            (
                self.settings.admin_chat_id,
                json.dumps({"text": text}, ensure_ascii=False),
                key,
            ),
        )
        if inserted:
            await self.database.execute(
                "INSERT INTO notifications (user_id,type,title,message,link) "
                "SELECT id,'telegram_pool_shortage','Нет свободной Telegram-группы',%s,"
                "'/admin/group-pool.php' FROM users "
                "WHERE role='admin' AND is_active=1",
                (text,),
            )
    async def create_invite(self, payload: dict[str, Any]) -> None:
        order_id = int(payload.get("order_id") or 0)
        user_id = int(payload.get("user_id") or 0)
        chat_kind = str(payload.get("chat_kind") or "")
        operation_id = int(payload.get("operation_id") or 0)
        if operation_id <= 0:
            raise PermanentOperation("Invite operation id is missing")
        if self.operation_receipt(operation_id, "invite_sent"):
            return
        binding = await self.database.invite_context(order_id, user_id, chat_kind)
        if not binding:
            raise RetryableOperation("Binding is not active or user has not linked Telegram")
        invite_ttl_minutes = await self.database.integer_setting(
            "telegram.invite_ttl_minutes",
            self.settings.invite_ttl_minutes,
            5,
            1440,
        )
        pending = await self.database.pending_invites(int(binding["id"]), user_id)
        invite_id = 0
        invite_link = ""
        if pending and pending[0].get("invite_link"):
            invite_id = int(pending[0]["id"])
            invite_link = str(pending[0]["invite_link"])
        else:
            expire_at = datetime.now(timezone.utc) + timedelta(
                minutes=invite_ttl_minutes
            )
            invite_name = f"order-{order_id}-user-{user_id}-{secrets_token(8)}"
            invite = await self.bot.create_chat_invite_link(
                int(binding["telegram_chat_id"]),
                name=invite_name[:32],
                expire_date=expire_at,
                creates_join_request=True,
            )
            try:
                invite_id = await self.database.save_invite(
                    binding,
                    user_id,
                    invite.invite_link,
                    invite_name,
                    invite_ttl_minutes,
                )
                invite_link = invite.invite_link
            except Exception:
                try:
                    await self.bot.revoke_chat_invite_link(
                        int(binding["telegram_chat_id"]),
                        invite.invite_link,
                    )
                except TelegramBadRequest as revoke_error:
                    LOGGER.warning(
                        "Unable to revoke unpersisted Telegram invite for order %s: %s",
                        order_id,
                        revoke_error,
                    )
                raise
        target_chat = int(binding.get("telegram_chat_id") or 0)
        private_chat = int(binding.get("telegram_chat_id") or 0)
        user_row = await self.database.fetch_one(
            "SELECT telegram_chat_id FROM users WHERE id=%s",
            (user_id,),
        )
        if user_row and user_row.get("telegram_chat_id"):
            private_chat = int(user_row["telegram_chat_id"])
        if private_chat == target_chat:
            raise RetryableOperation("User private Telegram chat is unavailable")
        await self.bot.send_message(
            private_chat,
            (
                f"Персональное приглашение в группу заказа #{order_id}:\n"
                f"{invite_link}\n\nСсылка действует "
                f"{invite_ttl_minutes} минут."
            ),
            disable_web_page_preview=True,
        )
        atomic_json(
            self.receipt_path(operation_id),
            {
                "operation_id": operation_id,
                "receipt_type": "invite_sent",
                "invite_id": invite_id,
                "created_at": datetime.now(timezone.utc).isoformat(),
            },
        )
    async def send_site_message(self, payload: dict[str, Any]) -> int:
        message_id = int(payload.get("site_message_id") or 0)
        operation_id = int(payload.get("operation_id") or 0)
        if operation_id <= 0:
            raise PermanentOperation("Message sync operation id is missing")
        message = await self.database.message_sync_context(message_id)
        if not message:
            raise RetryableOperation("Message binding is not active")
        if message.get("mapped_telegram_message_id"):
            self.remove_delivery_receipt(operation_id)
            return int(message["mapped_telegram_message_id"])
        receipt = self.delivery_receipt(operation_id, message_id)
        if receipt:
            telegram_message_id = int(receipt["telegram_message_id"])
            await self.database.message_map(
                message,
                telegram_message_id,
                "site_to_telegram",
            )
            self.remove_delivery_receipt(operation_id)
            return telegram_message_id
        reply = (
            ReplyParameters(message_id=int(message["reply_telegram_message_id"]))
            if message.get("reply_telegram_message_id")
            else None
        )
        body = str(message.get("message") or "")
        if (
            reply is None
            and message.get("reply_to_message_id")
            and message.get("reply_message")
        ):
            excerpt = " ".join(str(message["reply_message"]).split())[:180]
            body = f"Ответ на: «{excerpt}»\n\n{body}"
        text = self.signed_message_text(message, body, 4096)
        attachments = list(message.get("attachments") or [])
        if attachments:
            attachment = attachments[0]
            path = self.safe_storage_path(str(attachment.get("storage_path") or ""))
            mime = str(attachment.get("mime_type") or "")
            if mime.startswith("image/"):
                sent = await self.bot.send_photo(
                    int(message["telegram_chat_id"]),
                    FSInputFile(path, filename=str(attachment.get("original_name") or path.name)),
                    caption=text[:1024] or None,
                    reply_parameters=reply,
                )
            else:
                sent = await self.bot.send_document(
                    int(message["telegram_chat_id"]),
                    FSInputFile(path, filename=str(attachment.get("original_name") or path.name)),
                    caption=text[:1024] or None,
                    reply_parameters=reply,
                )
        else:
            sent = await self.bot.send_message(
                int(message["telegram_chat_id"]),
                text or "Сообщение",
                reply_parameters=reply,
                disable_web_page_preview=True,
            )
        atomic_json(
            self.receipt_path(operation_id),
            {
                "operation_id": operation_id,
                "site_message_id": message_id,
                "telegram_chat_id": int(message["telegram_chat_id"]),
                "telegram_message_id": int(sent.message_id),
                "created_at": datetime.now(timezone.utc).isoformat(),
            },
        )
        map_error: Exception | None = None
        for attempt in range(1, 6):
            try:
                await self.database.message_map(
                    message,
                    sent.message_id,
                    "site_to_telegram",
                )
                map_error = None
                self.remove_delivery_receipt(operation_id)
                break
            except Exception as error:
                map_error = error
                LOGGER.exception(
                    "Unable to persist Telegram mapping for site message %s "
                    "(attempt %s/5)",
                    message_id,
                    attempt,
                )
                await asyncio.sleep(min(8, attempt * 2))
        if map_error is not None:
            raise RetryableOperation(
                "Telegram accepted the message, but its mapping could not be saved"
            ) from map_error
        return int(sent.message_id)
    def receipt_path(self, operation_id: int) -> Path:
        return self.receipt_dir / f"{max(0, int(operation_id))}.json"
    def delivery_receipt(
        self,
        operation_id: int,
        site_message_id: int,
    ) -> dict[str, Any] | None:
        path = self.receipt_path(operation_id)
        if not path.is_file():
            return None
        try:
            payload = json.loads(path.read_text(encoding="utf-8"))
            valid = (
                isinstance(payload, dict)
                and int(payload.get("operation_id") or 0) == operation_id
                and int(payload.get("site_message_id") or 0) == site_message_id
                and int(payload.get("telegram_message_id") or 0) > 0
            )
            if valid:
                return payload
            LOGGER.error("Invalid delivery receipt quarantined: %s", path.name)
        except (OSError, ValueError, TypeError):
            LOGGER.exception("Unable to read delivery receipt %s", path.name)
        quarantine = path.with_suffix(".invalid")
        try:
            os.replace(path, quarantine)
        except OSError:
            LOGGER.exception("Unable to quarantine delivery receipt %s", path.name)
        return None
    def remove_delivery_receipt(self, operation_id: int) -> None:
        path = self.receipt_path(operation_id)
        try:
            path.unlink(missing_ok=True)
        except OSError:
            LOGGER.exception("Unable to remove delivery receipt %s", path.name)
    def operation_receipt(self, operation_id: int, receipt_type: str) -> bool:
        path = self.receipt_path(operation_id)
        if not path.is_file():
            return False
        try:
            payload = json.loads(path.read_text(encoding="utf-8"))
            return (
                isinstance(payload, dict)
                and int(payload.get("operation_id") or 0) == operation_id
                and payload.get("receipt_type") == receipt_type
            )
        except (OSError, ValueError, TypeError):
            LOGGER.exception("Unable to read operation receipt %s", path.name)
            return False
    def queue_receipt_path(self, queue_id: int) -> Path:
        return self.receipt_dir / f"queue-{max(0, int(queue_id))}.json"
    def queue_receipt_chat_ids(
        self,
        queue_id: int,
        event_type: str,
    ) -> set[int] | None:
        path = self.queue_receipt_path(queue_id)
        if not path.is_file():
            return set()
        try:
            payload = json.loads(path.read_text(encoding="utf-8"))
            valid = (
                isinstance(payload, dict)
                and int(payload.get("queue_id") or 0) == queue_id
                and payload.get("event_type") == event_type
            )
            if not valid:
                raise ValueError("Queue receipt identity mismatch")
            sent_chat_ids = payload.get("sent_chat_ids")
            if sent_chat_ids is None:
                return None
            if not isinstance(sent_chat_ids, list):
                raise TypeError("Queue receipt recipients are invalid")
            return {
                int(chat_id)
                for chat_id in sent_chat_ids
                if str(chat_id).lstrip("-").isdigit()
            }
        except (OSError, ValueError, TypeError):
            LOGGER.exception("Unable to read queue receipt %s", path.name)
            quarantine = path.with_suffix(".invalid")
            try:
                os.replace(path, quarantine)
            except OSError:
                LOGGER.exception("Unable to quarantine queue receipt %s", path.name)
            return set()
    def write_queue_receipt(
        self,
        queue_id: int,
        event_type: str,
        sent_chat_ids: set[int],
    ) -> None:
        atomic_json(
            self.queue_receipt_path(queue_id),
            {
                "queue_id": queue_id,
                "event_type": event_type,
                "sent_chat_ids": sorted(sent_chat_ids),
                "created_at": datetime.now(timezone.utc).isoformat(),
            },
        )
    def remove_queue_receipt(self, queue_id: int) -> None:
        path = self.queue_receipt_path(queue_id)
        try:
            path.unlink(missing_ok=True)
        except OSError:
            LOGGER.exception("Unable to remove queue receipt %s", path.name)
    def remove_operation_receipt(self, operation_id: int | None) -> None:
        if operation_id:
            self.remove_delivery_receipt(operation_id)
    async def edit_site_message(self, payload: dict[str, Any]) -> None:
        mapped = await self.database.mapped_message(int(payload.get("site_message_id") or 0))
        if not mapped or mapped.get("binding_status") != "active":
            raise RetryableOperation("Telegram message mapping is unavailable")
        try:
            if mapped.get("attachment_type"):
                await self.bot.edit_message_caption(
                    self.signed_message_text(
                        mapped,
                        str(mapped["message"]),
                        1024,
                    ) or None,
                    chat_id=int(mapped["telegram_chat_id"]),
                    message_id=int(mapped["telegram_message_id"]),
                )
            else:
                await self.bot.edit_message_text(
                    self.signed_message_text(
                        mapped,
                        str(mapped["message"]),
                        4096,
                    ),
                    chat_id=int(mapped["telegram_chat_id"]),
                    message_id=int(mapped["telegram_message_id"]),
                )
        except TelegramBadRequest as error:
            if "message is not modified" not in str(error).lower():
                raise
        await self.database.execute(
            "UPDATE telegram_message_map SET revision=%s,sync_status='mapped',last_error=NULL "
            "WHERE id=%s",
            (int(mapped.get("revision") or 1), mapped["id"]),
        )
    @staticmethod
    def signed_message_text(
        message: dict[str, Any],
        body: str,
        limit: int,
    ) -> str:
        name = " ".join(
            part
            for part in (
                str(message.get("sender_first_name") or "").strip(),
                str(message.get("sender_last_name") or "").strip(),
            )
            if part
        )
        name = " ".join(name.split())[:120]
        role = ROLE_LABELS.get(str(message.get("sender_role") or ""), "")
        if name and role:
            sender = f"{name} · {role}"
        else:
            sender = name or role or "Азбука Декора"
        content = str(body or "").strip()
        text = f"{sender}\n\n{content}" if content else sender
        return text[:max(1, limit)]
    async def delete_site_message(self, payload: dict[str, Any]) -> None:
        mapped = await self.database.mapped_message(int(payload.get("site_message_id") or 0))
        if not mapped:
            return
        try:
            await self.bot.delete_message(
                int(mapped["telegram_chat_id"]),
                int(mapped["telegram_message_id"]),
            )
        except TelegramBadRequest as error:
            error_text = str(error).lower()
            if "message to delete not found" in error_text:
                await self.database.execute(
                    "UPDATE telegram_message_map "
                    "SET sync_status='deleted',deleted_at=NOW(),last_error=NULL "
                    "WHERE id=%s",
                    (mapped["id"],),
                )
                return
            if (
                "message can't be deleted" in error_text
                or "message cannot be deleted" in error_text
            ):
                await self.database.execute(
                    "UPDATE telegram_message_map "
                    "SET sync_status='partial',last_error='delete_window_expired' "
                    "WHERE id=%s",
                    (mapped["id"],),
                )
                return
            await self.database.execute(
                "UPDATE telegram_message_map SET sync_status='delete_failed',last_error=%s "
                "WHERE id=%s",
                (str(error)[:2000], mapped["id"]),
            )
            raise PermanentOperation(
                "Telegram did not allow message deletion; the site tombstone remains"
            ) from error
        await self.database.execute(
            "UPDATE telegram_message_map SET sync_status='deleted',deleted_at=NOW(),last_error=NULL "
            "WHERE id=%s",
            (mapped["id"],),
        )
    async def remove_membership(self, payload: dict[str, Any]) -> None:
        context = await self.database.removal_context(
            int(payload.get("order_id") or 0),
            int(payload.get("user_id") or 0),
            str(payload.get("chat_kind") or "order_team"),
        )
        if not context:
            return
        chat_id = int(context["telegram_chat_id"])
        telegram_user_id = int(context["telegram_user_id"])
        try:
            await self.bot.ban_chat_member(chat_id, telegram_user_id)
            await self.bot.unban_chat_member(chat_id, telegram_user_id, only_if_banned=True)
        except TelegramBadRequest as error:
            if "user not found" not in str(error).lower():
                raise
        if context.get("invite_id"):
            invite = await self.database.fetch_one(
                "SELECT invite_link FROM telegram_invites WHERE id=%s",
                (context["invite_id"],),
            )
            if invite and invite.get("invite_link"):
                try:
                    await self.bot.revoke_chat_invite_link(chat_id, str(invite["invite_link"]))
                except TelegramBadRequest as error:
                    LOGGER.info(
                        "Membership invite %s was already unavailable: %s",
                        context.get("invite_id"),
                        error,
                    )
        await self.database.membership_removed(int(context["membership_id"]))
    def safe_storage_path(self, stored_path: str) -> Path:
        normalized = stored_path.replace("\\", "/").lstrip("/")
        if not normalized.startswith("uploads/") or ".." in normalized.split("/"):
            raise PermanentOperation("Unsafe attachment path")
        candidate = (ROOT_DIR / normalized).resolve()
        uploads = (ROOT_DIR / "uploads").resolve()
        if uploads not in candidate.parents or not candidate.is_file():
            raise PermanentOperation("Attachment file is unavailable")
        return candidate
    async def heartbeat(self, bot_username: str) -> None:
        path = RUNTIME_DIR / "heartbeat.json"
        while not self.stop_event.is_set():
            state = {
                "pid": os.getpid(),
                "host": socket.gethostname(),
                "bot": bot_username,
                "updated_at": self.database.utc_now(),
            }
            atomic_json(path, state)
            try:
                await self.database.heartbeat(state)
            except Exception:
                LOGGER.exception("Database heartbeat failed")
            await asyncio.sleep(self.settings.heartbeat_seconds)
    async def maintenance(self) -> None:
        while not self.stop_event.is_set():
            try:
                stats = await self.database.maintenance()
                stats["expired_invites"] = await self.expire_invites()
                stats["reconciled_memberships"] = await self.reconcile_memberships()
                stats["renamed_groups"] = await self.reconcile_group_titles()
                stats["archived_bindings"] = await self.archive_completed_bindings()
                stats["group_pool_alerts"] = await self.database.check_group_pool_level()
                if any(stats.values()):
                    LOGGER.info("Maintenance: %s", stats)
            except Exception:
                LOGGER.exception("Maintenance task failed")
            await asyncio.sleep(3600)
    async def expire_invites(self) -> int:
        rows = await self.database.expired_invites()
        for row in rows:
            if row.get("invite_link"):
                try:
                    await self.bot.revoke_chat_invite_link(
                        int(row["telegram_chat_id"]),
                        str(row["invite_link"]),
                    )
                except TelegramBadRequest as error:
                    LOGGER.info("Expired invite %s could not be revoked: %s", row["id"], error)
            await self.database.invite_expired(
                int(row["id"]),
                int(row["membership_id"]) if row.get("membership_id") else None,
            )
        return len(rows)
    async def reconcile_memberships(self) -> int:
        changed = 0
        for row in await self.database.memberships_for_reconciliation():
            try:
                member = await self.bot.get_chat_member(
                    int(row["telegram_chat_id"]),
                    int(row["telegram_user_id"]),
                )
            except TelegramBadRequest:
                continue
            actual = (
                "joined"
                if member.status in {"member", "administrator", "creator", "restricted"}
                else "left"
            )
            if actual != row["status"] and not (
                row["status"] in {"invited", "join_requested"} and actual == "left"
            ):
                await self.database.reconcile_membership_status(int(row["id"]), actual)
                changed += 1
        return changed
    async def reconcile_group_titles(self) -> int:
        changed = 0
        for binding in await self.database.active_bindings_for_title_reconciliation():
            expected = self.group_title(binding)
            try:
                chat = await self.bot.get_chat(int(binding["telegram_chat_id"]))
                if (chat.title or "") != expected:
                    await self.bot.set_chat_title(
                        int(binding["telegram_chat_id"]),
                        expected,
                    )
                    changed += 1
            except (TelegramBadRequest, TelegramForbiddenError) as error:
                LOGGER.warning(
                    "Unable to reconcile title for Telegram binding %s: %s",
                    binding["id"],
                    error,
                )
        return changed
    async def archive_completed_bindings(self) -> int:
        rows = await self.database.bindings_to_archive()
        for row in rows:
            pending = await self.database.fetch_all(
                "SELECT invite_link FROM telegram_invites "
                "WHERE binding_id=%s AND status='pending'",
                (row["id"],),
            )
            for invite in pending:
                if not invite.get("invite_link"):
                    continue
                try:
                    await self.bot.revoke_chat_invite_link(
                        int(row["telegram_chat_id"]),
                        str(invite["invite_link"]),
                    )
                except TelegramBadRequest as error:
                    LOGGER.info(
                        "Archive invite for binding %s could not be revoked: %s",
                        row["id"],
                        error,
                    )
            try:
                await self.bot.set_chat_description(
                    int(row["telegram_chat_id"]),
                    f"Архив заказа #{row['order_id']}. Новые сообщения не синхронизируются.",
                )
                await self.bot.set_chat_permissions(
                    int(row["telegram_chat_id"]),
                    ChatPermissions(can_send_messages=False),
                )
            except TelegramBadRequest as error:
                LOGGER.warning(
                    "Unable to mark Telegram binding %s as archived: %s",
                    row["id"],
                    error,
                )
            await self.database.archive_binding(int(row["id"]), int(row["group_pool_id"]))
        return len(rows)
def hashlib_key(value: str) -> str:
    import hashlib
    return hashlib.sha256(value.encode()).hexdigest()
def secrets_token(length: int) -> str:
    import secrets
    return secrets.token_hex(max(1, length // 2))
