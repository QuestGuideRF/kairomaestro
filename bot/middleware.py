from __future__ import annotations
from collections.abc import Awaitable, Callable
from typing import Any
from aiogram import BaseMiddleware
from aiogram.types import TelegramObject
from database import Database
class UpdateDedupeMiddleware(BaseMiddleware):
    def __init__(self, database: Database) -> None:
        self.database = database
    async def __call__(
        self,
        handler: Callable[[TelegramObject, dict[str, Any]], Awaitable[Any]],
        event: TelegramObject,
        data: dict[str, Any],
    ) -> Any:
        update = data.get("event_update")
        if update is not None:
            chat_id = None
            for item in (
                getattr(update, "message", None),
                getattr(update, "edited_message", None),
                getattr(update, "callback_query", None),
                getattr(update, "chat_member", None),
                getattr(update, "chat_join_request", None),
            ):
                if item is None:
                    continue
                message = getattr(item, "message", item)
                chat = getattr(message, "chat", None)
                if chat is not None:
                    chat_id = chat.id
                    break
            accepted = await self.database.claim_update(
                int(update.update_id),
                str(update.event_type or "unknown"),
                chat_id,
            )
            if not accepted:
                return None
        return await handler(event, data)
