from __future__ import annotations
import asyncio
import io
import logging
import os
import socket
from typing import Any
import aiohttp
from aiogram import Bot
from aiogram.exceptions import (
    TelegramBadRequest,
    TelegramForbiddenError,
    TelegramNetworkError,
    TelegramRetryAfter,
    TelegramServerError,
)
from database import Database
from formatting import identifier_fingerprint
from internal_api import InternalApiClient, InternalApiError
from settings import Settings
LOGGER = logging.getLogger("azbuka_bot.inbound")
class AttachmentTransferError(RuntimeError):
    pass
class InboundEventWorker:
    def __init__(
        self,
        bot: Bot,
        database: Database,
        settings: Settings,
        api: InternalApiClient,
    ) -> None:
        self.bot = bot
        self.database = database
        self.settings = settings
        self.api = api
        self.worker_id = f"{socket.gethostname()}:{os.getpid()}:inbound"
        self.stop_event = asyncio.Event()
    async def run(self) -> None:
        recovered = await self.database.recover_stale_inbound_events()
        if recovered:
            LOGGER.warning("Recovered %s stale inbound events", recovered)
        while not self.stop_event.is_set():
            try:
                event = await self.database.claim_inbound_event(self.worker_id)
                if not event:
                    await asyncio.sleep(min(1.0, self.settings.queue_poll_seconds))
                    continue
                await self.process(event)
            except asyncio.CancelledError:
                raise
            except Exception:
                LOGGER.exception("Inbound worker iteration failed")
                await asyncio.sleep(5)
    async def process(self, event: dict[str, Any]) -> None:
        payload = event.get("payload_json")
        payload = payload if isinstance(payload, dict) else {}
        site_message_id: int | None = None
        try:
            result = await self.deliver(payload)
            site_message_id = int(result.get("message_id") or 0) or None
            await self.database.inbound_event_completed(
                int(event["id"]),
                site_message_id,
            )
        except InternalApiError as error:
            if error.retryable:
                await self.database.inbound_event_failed(event, str(error))
                return
            await self.reject(event, error.status, str(error))
        except AttachmentTransferError as error:
            await self.attachment_unavailable(event, payload, str(error))
        except TelegramRetryAfter as error:
            await self.database.inbound_event_failed(
                event,
                f"Telegram retry after {error.retry_after}: {error}",
            )
        except (TelegramNetworkError, TelegramServerError, aiohttp.ClientError, TimeoutError) as error:
            await self.database.inbound_event_failed(event, str(error))
        except (TelegramBadRequest, TelegramForbiddenError) as error:
            await self.attachment_unavailable(event, payload, str(error))
        except Exception as error:
            LOGGER.exception("Inbound event %s failed", event.get("id"))
            await self.database.inbound_event_failed(event, str(error))
    async def deliver(self, payload: dict[str, Any]) -> dict[str, Any]:
        media = payload.get("_media")
        clean = {key: value for key, value in payload.items() if key != "_media"}
        if not isinstance(media, dict):
            return await self.api.post_json(
                "/api/internal/telegram-message.php",
                clean,
            )
        placeholder = dict(clean)
        placeholder["unsupported_media_type"] = "attachment_pending"
        result = await self.api.post_json(
            "/api/internal/telegram-message.php",
            placeholder,
        )
        content = await self.download(str(media.get("file_id") or ""))
        limit = max(1, int(media.get("limit") or 0))
        if len(content) > limit:
            raise AttachmentTransferError("Файл превышает допустимый размер")
        attachment_payload = dict(clean)
        attachment_payload["action"] = "attach_message"
        attachment_payload.update({
            "telegram_file_id": str(media.get("file_id") or ""),
            "telegram_file_unique_id": str(media.get("file_unique_id") or ""),
            "width": media.get("width"),
            "height": media.get("height"),
        })
        attached = await self.api.post_file(
            "/api/internal/telegram-message.php",
            attachment_payload,
            str(media.get("name") or "telegram-file"),
            content,
            str(media.get("mime") or "application/octet-stream"),
        )
        return attached if attached.get("message_id") else result
    async def attachment_unavailable(
        self,
        event: dict[str, Any],
        payload: dict[str, Any],
        reason: str,
    ) -> None:
        attempts = int(event.get("attempts") or 0) + 1
        maximum = max(1, int(event.get("max_attempts") or 20))
        if attempts < maximum and "превышает допустимый размер" not in reason.lower():
            await self.database.inbound_event_failed(event, reason)
            return
        clean = {key: value for key, value in payload.items() if key != "_media"}
        clean["action"] = "attachment_unavailable"
        clean["attachment_error"] = reason[:300]
        try:
            result = await self.api.post_json(
                "/api/internal/telegram-message.php",
                clean,
            )
        except InternalApiError as api_error:
            await self.database.inbound_event_failed(
                event,
                str(api_error),
                permanent=not api_error.retryable,
            )
            return
        await self.database.inbound_event_failed(
            event,
            reason,
            permanent=True,
            site_message_id=int(result.get("message_id") or 0) or None,
        )
        await self.database.queue_critical_error(
            "Не удалось загрузить вложение Telegram; создан безопасный placeholder. "
            f"Входящее событие #{event['id']}: {reason[:300]}"
        )
    async def reject(
        self,
        event: dict[str, Any],
        status: int,
        reason: str,
    ) -> None:
        transitioned = await self.database.inbound_event_failed(
            event,
            reason,
            permanent=True,
        )
        if not transitioned:
            return
        payload = event.get("payload_json")
        payload = payload if isinstance(payload, dict) else {}
        chat_id = int(payload.get("telegram_chat_id") or 0)
        message_id = int(payload.get("telegram_message_id") or 0)
        user_id = int(payload.get("telegram_user_id") or 0)
        if status in {401, 403}:
            await self.database.queue_security_alert(
                "rejected_group_message",
                chat_id,
                user_id,
            )
        if event.get("event_type") != "message" or chat_id == 0 or message_id <= 0:
            return
        try:
            await self.bot.delete_message(chat_id, message_id)
        except (TelegramBadRequest, TelegramForbiddenError) as error:
            LOGGER.warning(
                "Unable to delete rejected group message %s/%s: %s",
                identifier_fingerprint(chat_id),
                message_id,
                error,
            )
        event_key = str(
            event.get("idempotency_key")
            or f"inbound:{int(event.get('id') or 0)}"
        )
        await self.database.queue_rejected_message_notice(
            user_id,
            reason,
            event_key,
        )
    async def download(self, file_id: str) -> bytes:
        if not file_id:
            raise AttachmentTransferError("Telegram file id is missing")
        buffer = io.BytesIO()
        try:
            await self.bot.download(file_id, destination=buffer)
        except TelegramBadRequest as error:
            raise AttachmentTransferError(str(error)) from error
        return buffer.getvalue()
