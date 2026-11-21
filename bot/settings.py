from __future__ import annotations
import os
from dataclasses import dataclass
from pathlib import Path
from dotenv import load_dotenv
ROOT_DIR = Path(__file__).resolve().parents[1]
BOT_DIR = ROOT_DIR / "bot"
RUNTIME_DIR = BOT_DIR / "runtime"
LOG_DIR = RUNTIME_DIR
load_dotenv(ROOT_DIR / ".env", override=False)
def _required(name: str) -> str:
    value = os.getenv(name, "").strip()
    if not value:
        raise RuntimeError(f"Required environment variable {name} is empty")
    return value
def _int(name: str, default: int) -> int:
    try:
        return int(os.getenv(name, str(default)))
    except ValueError:
        return default
@dataclass(frozen=True, slots=True)
class Settings:
    bot_token: str
    db_host: str
    db_port: int
    db_name: str
    db_user: str
    db_password: str
    app_url: str
    site_name: str
    internal_secret: str
    flow_ttl_hours: int
    max_reference_files: int
    max_photo_bytes: int
    max_document_bytes: int
    invite_ttl_minutes: int
    group_pool_low_threshold: int
    admin_chat_id: int | None
    manager_chat_id: int | None
    organizer_chat_id: int | None
    queue_poll_seconds: float
    heartbeat_seconds: int
    watchdog_stale_seconds: int
    log_level: str
    log_max_bytes: int
    log_backup_count: int
    @classmethod
    def from_env(cls) -> Settings:
        def chat_id(name: str) -> int | None:
            value = os.getenv(name, "").strip()
            return int(value) if value and value.lstrip("-").isdigit() else None
        return cls(
            bot_token=_required("TELEGRAM_BOT_TOKEN"),
            db_host=_required("DB_HOST"),
            db_port=_int("DB_PORT", 3306),
            db_name=_required("DB_NAME"),
            db_user=_required("DB_USER"),
            db_password=os.getenv("DB_PASS", ""),
            app_url=_required("APP_URL").rstrip("/"),
            site_name=os.getenv("SITE_NAME", "Азбука Декора").strip() or "Азбука Декора",
            internal_secret=_required("BOT_INTERNAL_SECRET"),
            flow_ttl_hours=max(1, min(168, _int("BOT_FLOW_TTL_HOURS", 24))),
            max_reference_files=max(1, min(20, _int("BOT_MAX_REFERENCE_FILES", 10))),
            max_photo_bytes=max(1, min(50, _int("TELEGRAM_MAX_PHOTO_MB", 15))) * 1024 * 1024,
            max_document_bytes=max(1, min(50, _int("TELEGRAM_MAX_DOCUMENT_MB", 30))) * 1024 * 1024,
            invite_ttl_minutes=max(5, min(1440, _int("BOT_INVITE_TTL_MINUTES", 60))),
            group_pool_low_threshold=max(0, min(100, _int("BOT_GROUP_POOL_LOW_THRESHOLD", 2))),
            admin_chat_id=chat_id("TELEGRAM_ADMIN_CHAT_ID"),
            manager_chat_id=chat_id("TELEGRAM_MANAGER_CHAT_ID"),
            organizer_chat_id=chat_id("TELEGRAM_ORGANIZER_CHAT_ID"),
            queue_poll_seconds=max(0.5, float(os.getenv("BOT_QUEUE_POLL_SECONDS", "2"))),
            heartbeat_seconds=max(10, _int("BOT_HEARTBEAT_SECONDS", 30)),
            watchdog_stale_seconds=max(60, _int("BOT_WATCHDOG_STALE_SECONDS", 180)),
            log_level=os.getenv("BOT_LOG_LEVEL", "INFO").upper(),
            log_max_bytes=max(100_000, _int("BOT_LOG_MAX_BYTES", 5_000_000)),
            log_backup_count=max(1, min(20, _int("BOT_LOG_BACKUP_COUNT", 5))),
        )
def ensure_runtime_dirs() -> None:
    RUNTIME_DIR.mkdir(parents=True, exist_ok=True)
