from __future__ import annotations
import asyncio
import logging
import os
from contextlib import suppress
from logging.handlers import RotatingFileHandler
from pathlib import Path
from typing import Any
from aiogram import Bot, Dispatcher
from aiogram.exceptions import TelegramConflictError
from aiogram.types import BotCommand
from database import Database
from group_handlers import build_group_router
from handlers import build_private_router
from inbound_worker import InboundEventWorker
from internal_api import InternalApiClient
from middleware import UpdateDedupeMiddleware
from queue_worker import QueueWorker
from settings import LOG_DIR, RUNTIME_DIR, Settings, ensure_runtime_dirs
LOGGER = logging.getLogger("azbuka_bot")
try:
    import fcntl as _fcntl
except ImportError:
    _fcntl = None
try:
    import msvcrt as _msvcrt
except ImportError:
    _msvcrt = None
def configure_logging(level: str, max_bytes: int, backup_count: int) -> None:
    ensure_runtime_dirs()
    formatter = logging.Formatter("%(asctime)s %(levelname)s %(name)s: %(message)s")
    file_handler = RotatingFileHandler(
        LOG_DIR / "bot.log",
        maxBytes=max_bytes,
        backupCount=backup_count,
        encoding="utf-8",
    )
    file_handler.setFormatter(formatter)
    stream_handler = logging.StreamHandler()
    stream_handler.setFormatter(formatter)
    logging.basicConfig(
        level=getattr(logging, level, logging.INFO),
        handlers=[file_handler, stream_handler],
        force=True,
    )
class ProcessLock:
    def __init__(self, path: Path) -> None:
        self.path = path
        self.handle: Any = None
    def acquire(self) -> bool:
        self.path.parent.mkdir(parents=True, exist_ok=True)
        self.handle = self.path.open("a+", encoding="utf-8")
        try:
            if _fcntl is not None:
                _fcntl.flock(
                    self.handle.fileno(),
                    _fcntl.LOCK_EX | _fcntl.LOCK_NB,
                )
            elif _msvcrt is not None:
                self.handle.seek(0)
                if self.handle.read(1) == "":
                    self.handle.write("0")
                    self.handle.flush()
                self.handle.seek(0)
                _msvcrt.locking(self.handle.fileno(), _msvcrt.LK_NBLCK, 1)
            else:
                raise RuntimeError("No supported file-lock implementation")
        except (BlockingIOError, OSError):
            self.handle.close()
            self.handle = None
            return False
        self.handle.seek(0)
        self.handle.truncate()
        self.handle.write(str(os.getpid()))
        self.handle.flush()
        return True
    def release(self) -> None:
        if not self.handle:
            return
        with suppress(OSError):
            if _fcntl is not None:
                _fcntl.flock(self.handle.fileno(), _fcntl.LOCK_UN)
            elif _msvcrt is not None:
                self.handle.seek(0)
                _msvcrt.locking(self.handle.fileno(), _msvcrt.LK_UNLCK, 1)
        self.handle.close()
        self.handle = None
async def notify_admin(bot: Bot, chat_id: int | None, text: str) -> None:
    if not chat_id:
        return
    try:
        await bot.send_message(chat_id, text[:4096])
    except Exception:
        LOGGER.exception("Unable to notify admin")
async def run() -> None:
    settings = Settings.from_env()
    configure_logging(
        settings.log_level,
        settings.log_max_bytes,
        settings.log_backup_count,
    )
    lock = ProcessLock(RUNTIME_DIR / "bot.lock")
    if not lock.acquire():
        LOGGER.error("Another bot process already holds the lock")
        return
    pid_file = RUNTIME_DIR / "bot.pid"
    pid_file.write_text(str(os.getpid()), encoding="ascii")
    database = Database(settings)
    bot = Bot(settings.bot_token)
    worker: QueueWorker | None = None
    inbound_worker: InboundEventWorker | None = None
    tasks: list[asyncio.Task[Any]] = []
    try:
        await database.connect()
        identity = await bot.get_me()
        LOGGER.info("Bot @%s initialized, pid=%s", identity.username, os.getpid())
        await bot.set_my_commands([
            BotCommand(command="start", description="Главное меню"),
            BotCommand(command="job", description="Работа у нас"),
            BotCommand(command="help", description="Помощь"),
            BotCommand(command="cancel", description="Отменить текущий сценарий"),
        ])
        api = InternalApiClient(
            settings.app_url,
            settings.internal_secret,
        )
        dispatcher = Dispatcher()
        dispatcher.update.outer_middleware(UpdateDedupeMiddleware(database))
        dispatcher.include_router(build_private_router(database, settings, api))
        dispatcher.include_router(build_group_router(database, settings))
        worker = QueueWorker(bot, database, settings)
        inbound_worker = InboundEventWorker(bot, database, settings, api)
        tasks = [
            asyncio.create_task(worker.run(), name="notification-queue"),
            asyncio.create_task(inbound_worker.run(), name="telegram-inbound"),
            asyncio.create_task(
                worker.heartbeat(identity.username or "unknown"),
                name="heartbeat",
            ),
            asyncio.create_task(worker.maintenance(), name="maintenance"),
        ]
        await notify_admin(
            bot,
            settings.admin_chat_id,
            f"Бот {settings.site_name} запущен: @{identity.username}, PID {os.getpid()}",
        )
        await dispatcher.start_polling(
            bot,
            close_bot_session=False,
            tasks_concurrency_limit=50,
            handle_signals=True,
            allowed_updates=dispatcher.resolve_used_update_types(),
        )
    except TelegramConflictError as error:
        LOGGER.critical("Telegram polling conflict: %s", error)
        try:
            await database.queue_critical_error(
                "Обнаружен второй процесс Telegram-бота: " + str(error)
            )
        except Exception:
            LOGGER.exception("Unable to queue the polling conflict alert")
        await asyncio.sleep(15)
        raise
    except Exception as error:
        LOGGER.exception("Bot stopped after a critical error")
        queued = False
        try:
            queued = await database.queue_critical_error(
                f"{type(error).__name__}: {error}"
            )
        except Exception:
            LOGGER.exception("Unable to queue the critical bot error")
        if not queued:
            await notify_admin(
                bot,
                settings.admin_chat_id,
                f"Критическая ошибка Telegram-бота: {type(error).__name__}: {error}",
            )
        raise
    finally:
        if worker:
            worker.stop_event.set()
        if inbound_worker:
            inbound_worker.stop_event.set()
        for task in tasks:
            task.cancel()
        for task in tasks:
            with suppress(asyncio.CancelledError):
                await task
        await database.close()
        await bot.session.close()
        if pid_file.exists() and pid_file.read_text(encoding="ascii").strip() == str(os.getpid()):
            pid_file.unlink(missing_ok=True)
        lock.release()
if __name__ == "__main__":
    asyncio.run(run())
