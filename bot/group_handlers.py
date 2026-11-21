from __future__ import annotations
import logging
from datetime import datetime
from typing import Any
from aiogram import Bot, F, Router
from aiogram.exceptions import TelegramBadRequest
from aiogram.filters import Command, CommandObject
from aiogram.types import (
    ChatJoinRequest,
    ChatMemberUpdated,
    Message,
)
from database import Database
from formatting import identifier_fingerprint
from settings import Settings
LOGGER = logging.getLogger("azbuka_bot.groups")
class GroupHandlers:
    def __init__(
        self,
        database: Database,
        settings: Settings,
    ) -> None:
        self.database = database
        self.settings = settings
    async def register_group(
        self,
        message: Message,
        command: CommandObject,
        bot: Bot,
    ) -> None:
        if not message.from_user:
            return
        if message.chat.type != "supergroup":
            await message.answer(
                "В пул можно добавить только приватную Telegram supergroup."
            )
            return
        user = await self.database.linked_user(message.from_user.id)
        if not user or user["role"] != "admin":
            await message.answer("Команда доступна только администратору.")
            return
        kind = (command.args or "").strip().lower()
        if kind not in {"client", "team"}:
            await message.answer("Использование: /register_group client или /register_group team")
            return
        me = await bot.get_me()
        member = await bot.get_chat_member(message.chat.id, me.id)
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
        required = (
            rights["is_admin"]
            and rights["can_invite_users"]
            and rights["can_delete_messages"]
            and rights["can_manage_chat"]
            and rights["can_change_info"]
            and rights["can_restrict_members"]
        )
        if not required:
            await message.answer(
                "Сначала выдайте боту права администратора: управление группой, "
                "приглашения, удаление сообщений, ограничение участников "
                "и изменение информации."
            )
            return
        success, text = await self.database.register_group(
            message.chat.id,
            kind,
            message.chat.title or "",
            rights,
        )
        await message.answer(text)
        if not success:
            return
    async def incoming_message(self, message: Message) -> None:
        if not message.from_user or message.from_user.is_bot:
            return
        if (message.text or "").startswith("/"):
            return
        binding = await self.database.binding_for_telegram_chat(message.chat.id)
        if not binding:
            return
        payload: dict[str, Any] = {
            "action": "receive_message",
            "telegram_chat_id": message.chat.id,
            "telegram_user_id": message.from_user.id,
            "telegram_message_id": message.message_id,
            "reply_to_telegram_message_id": (
                message.reply_to_message.message_id if message.reply_to_message else 0
            ),
            "text": message.text or message.caption or "",
            "media_group_id": message.media_group_id or "",
            "forwarded": bool(message.forward_origin),
        }
        media = self.media_descriptor(message)
        if media:
            payload["_media"] = media
        else:
            unsupported = self.unsupported_type(message)
            if unsupported:
                payload["unsupported_media_type"] = unsupported
        await self.database.enqueue_inbound_event(
            f"message:{message.chat.id}:{message.message_id}",
            "message",
            payload,
        )
    async def edited_message(self, message: Message) -> None:
        if not message.from_user or message.from_user.is_bot:
            return
        binding = await self.database.binding_for_telegram_chat(message.chat.id)
        if not binding:
            return
        text = message.text or message.caption or ""
        if not text:
            return
        payload = {
            "action": "edit_message",
            "telegram_chat_id": message.chat.id,
            "telegram_user_id": message.from_user.id,
            "telegram_message_id": message.message_id,
            "text": text,
        }
        edited_at = message.edit_date
        edit_marker = int(edited_at.timestamp()) if isinstance(edited_at, datetime) else 0
        await self.database.enqueue_inbound_event(
            f"edit:{message.chat.id}:{message.message_id}:{edit_marker}",
            "edit",
            payload,
        )
    async def join_request(self, request: ChatJoinRequest, bot: Bot) -> None:
        invite_link = (
            request.invite_link.invite_link
            if request.invite_link
            else ""
        )
        context = await self.database.join_request_context(
            request.chat.id,
            request.from_user.id,
            invite_link,
        )
        if not context:
            await bot.decline_chat_join_request(request.chat.id, request.from_user.id)
            return
        payload = {
            "action": "membership_event",
            "telegram_chat_id": request.chat.id,
            "telegram_user_id": request.from_user.id,
            "membership_event": "joined",
            "event_key": (
                f"membership:{request.chat.id}:{request.from_user.id}:joined:"
                f"{int(request.date.timestamp())}"
            ),
        }
        approved = False
        try:
            await bot.approve_chat_join_request(
                request.chat.id,
                request.from_user.id,
            )
            approved = True
            await self.database.membership_joined(
                int(context["membership_id"]),
                int(context["invite_id"]) if context.get("invite_id") else None,
                payload,
            )
        except Exception:
            if approved:
                try:
                    await bot.ban_chat_member(
                        request.chat.id,
                        request.from_user.id,
                    )
                    await bot.unban_chat_member(
                        request.chat.id,
                        request.from_user.id,
                        only_if_banned=True,
                    )
                except Exception:
                    LOGGER.exception(
                        "Unable to revoke failed join chat=%s user=%s",
                        identifier_fingerprint(request.chat.id),
                        identifier_fingerprint(request.from_user.id),
                    )
            else:
                try:
                    await bot.decline_chat_join_request(
                        request.chat.id,
                        request.from_user.id,
                    )
                except TelegramBadRequest as decline_error:
                    LOGGER.info(
                        "Join request was already unavailable chat=%s user=%s: %s",
                        identifier_fingerprint(request.chat.id),
                        identifier_fingerprint(request.from_user.id),
                        decline_error,
                    )
            try:
                await self.database.rollback_join_request(
                    int(context["membership_id"])
                )
                await self.database.queue_security_alert(
                    "join_approval_rolled_back",
                    request.chat.id,
                    request.from_user.id,
                )
            except Exception:
                LOGGER.exception(
                    "Unable to persist join rollback membership=%s",
                    context["membership_id"],
                )
            LOGGER.exception(
                "Telegram join was not committed membership=%s",
                context["membership_id"],
            )
            return
        if request.invite_link:
            try:
                await bot.revoke_chat_invite_link(
                    request.chat.id,
                    request.invite_link.invite_link,
                )
            except TelegramBadRequest as error:
                LOGGER.info(
                    "Invite was already revoked for membership %s: %s",
                    context["membership_id"],
                    error,
                )
    async def chat_member(self, update: ChatMemberUpdated, bot: Bot) -> None:
        old_status = update.old_chat_member.status
        new_status = update.new_chat_member.status
        user = update.new_chat_member.user
        if user.is_bot:
            return
        joined_now = (
            old_status in {"left", "kicked"}
            and new_status in {"member", "administrator", "creator", "restricted"}
        )
        if joined_now:
            membership = await self.database.membership_for_telegram_identity(
                update.chat.id,
                user.id,
            )
            allowed_roles = {
                "order_client": {"client", "organizer", "admin"},
                "order_team": {
                    "organizer",
                    "florist",
                    "courier",
                    "installer",
                    "helper",
                    "worker",
                    "admin",
                },
            }
            allowed = (
                membership is not None
                and str(membership.get("status") or "")
                in {"join_requested", "joined"}
                and str(membership.get("role") or "")
                in allowed_roles.get(str(membership.get("chat_kind") or ""), set())
            )
            if not allowed:
                try:
                    await bot.ban_chat_member(update.chat.id, user.id)
                    await bot.unban_chat_member(
                        update.chat.id,
                        user.id,
                        only_if_banned=True,
                    )
                except TelegramBadRequest:
                    LOGGER.exception(
                        "Unable to remove unexpected member chat=%s user=%s",
                        identifier_fingerprint(update.chat.id),
                        identifier_fingerprint(user.id),
                    )
                await self.database.queue_security_alert(
                    "unexpected_direct_join",
                    update.chat.id,
                    user.id,
                )
                return
        if old_status in {"member", "administrator"} and new_status in {"left", "kicked"}:
            payload = {
                "action": "membership_event",
                "telegram_chat_id": update.chat.id,
                "telegram_user_id": user.id,
                "membership_event": "left",
                "event_key": (
                    f"membership:{update.chat.id}:{user.id}:left:"
                    f"{int(update.date.timestamp())}"
                ),
            }
            await self.database.membership_left(
                update.chat.id,
                user.id,
                payload,
            )
    def media_descriptor(self, message: Message) -> dict[str, Any] | None:
        if message.photo:
            photo = message.photo[-1]
            return {
                "file_id": photo.file_id,
                "file_unique_id": photo.file_unique_id,
                "name": f"telegram-{photo.file_unique_id}.jpg",
                "mime": "image/jpeg",
                "limit": self.settings.max_photo_bytes,
                "width": photo.width,
                "height": photo.height,
            }
        if message.document:
            document = message.document
            mime = document.mime_type or ""
            if mime not in {"image/jpeg", "image/png", "image/webp", "application/pdf"}:
                return None
            return {
                "file_id": document.file_id,
                "file_unique_id": document.file_unique_id,
                "name": document.file_name or f"telegram-{document.file_unique_id}",
                "mime": mime,
                "limit": self.settings.max_document_bytes,
            }
        return None
    @staticmethod
    def unsupported_type(message: Message) -> str:
        for field in (
            "sticker",
            "animation",
            "video",
            "video_note",
            "voice",
            "audio",
            "contact",
            "location",
            "venue",
            "poll",
        ):
            if getattr(message, field, None) is not None:
                return field
        return ""
def build_group_router(
    database: Database,
    settings: Settings,
) -> Router:
    router = Router(name="groups")
    handlers = GroupHandlers(database, settings)
    group = F.chat.type.in_({"group", "supergroup"})
    router.message.register(handlers.register_group, Command("register_group"), group)
    router.edited_message.register(handlers.edited_message, group)
    router.chat_join_request.register(handlers.join_request)
    router.chat_member.register(handlers.chat_member)
    router.message.register(handlers.incoming_message, group)
    return router
