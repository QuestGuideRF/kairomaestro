from __future__ import annotations
import json
import os
import signal
import subprocess
import sys
import time
from datetime import datetime, timezone
from pathlib import Path
from dotenv import load_dotenv
try:
    import fcntl as _fcntl
except ImportError:
    _fcntl = None
try:
    import msvcrt as _msvcrt
except ImportError:
    _msvcrt = None
BOT_DIR = Path(__file__).resolve().parent
ROOT_DIR = BOT_DIR.parent
RUNTIME_DIR = BOT_DIR / "runtime"
LOG_DIR = RUNTIME_DIR
STATE_FILE = RUNTIME_DIR / "watchdog-state.json"
load_dotenv(ROOT_DIR / ".env", override=False)
def env_int(name: str, default: int, minimum: int, maximum: int) -> int:
    try:
        value = int(os.getenv(name, str(default)))
    except ValueError:
        value = default
    return max(minimum, min(maximum, value))
def rotate_log(path: Path) -> None:
    max_bytes = env_int("BOT_LOG_MAX_BYTES", 5_000_000, 100_000, 100_000_000)
    backups = env_int("BOT_LOG_BACKUP_COUNT", 5, 1, 20)
    try:
        if not path.exists() or path.stat().st_size < max_bytes:
            return
        oldest = path.with_name(f"{path.name}.{backups}")
        oldest.unlink(missing_ok=True)
        for index in range(backups - 1, 0, -1):
            source = path.with_name(f"{path.name}.{index}")
            if source.exists():
                source.replace(path.with_name(f"{path.name}.{index + 1}"))
        path.replace(path.with_name(f"{path.name}.1"))
    except OSError:
        return
def log(message: str) -> None:
    LOG_DIR.mkdir(parents=True, exist_ok=True)
    path = LOG_DIR / "watchdog.log"
    rotate_log(path)
    with path.open("a", encoding="utf-8") as handle:
        handle.write(f"{datetime.now(timezone.utc).isoformat()} {message}\n")
def acquire_lock() -> object | None:
    RUNTIME_DIR.mkdir(parents=True, exist_ok=True)
    handle = (RUNTIME_DIR / "watchdog.lock").open("a+", encoding="utf-8")
    try:
        if _fcntl is not None:
            _fcntl.flock(handle.fileno(), _fcntl.LOCK_EX | _fcntl.LOCK_NB)
        elif _msvcrt is not None:
            handle.seek(0)
            if handle.read(1) == "":
                handle.write("0")
                handle.flush()
            handle.seek(0)
            _msvcrt.locking(handle.fileno(), _msvcrt.LK_NBLCK, 1)
        else:
            raise RuntimeError("No supported file-lock implementation")
    except (BlockingIOError, OSError):
        handle.close()
        return None
    return handle
def read_pid() -> int | None:
    try:
        value = (RUNTIME_DIR / "bot.pid").read_text(encoding="ascii").strip()
        return int(value) if value.isdigit() else None
    except (OSError, ValueError):
        return None
def process_state(pid: int) -> str:
    try:
        os.kill(pid, 0)
    except OSError:
        return "dead"
    try:
        command = Path(f"/proc/{pid}/cmdline").read_bytes().replace(b"\x00", b" ").decode("utf-8", "replace")
        return "bot" if "bot/main.py" in command or command.rstrip().endswith("main.py") else "foreign"
    except OSError:
        return "unknown"
def heartbeat_is_fresh(max_age: int, expected_pid: int) -> bool:
    try:
        payload = json.loads((RUNTIME_DIR / "heartbeat.json").read_text(encoding="utf-8"))
        if int(payload.get("pid", 0)) != expected_pid:
            return False
        updated = datetime.fromisoformat(payload["updated_at"].replace("Z", "+00:00"))
        return (datetime.now(timezone.utc) - updated).total_seconds() <= max_age
    except (OSError, ValueError, TypeError, KeyError):
        return False
def read_restart_state() -> dict[str, int]:
    try:
        payload = json.loads(STATE_FILE.read_text(encoding="utf-8"))
        return {
            "last_start_at": max(0, int(payload.get("last_start_at", 0))),
            "counted_failed_start_at": max(0, int(payload.get("counted_failed_start_at", 0))),
            "consecutive_failures": max(0, int(payload.get("consecutive_failures", 0))),
            "next_start_at": max(0, int(payload.get("next_start_at", 0))),
        }
    except (OSError, ValueError, TypeError):
        return {
            "last_start_at": 0,
            "counted_failed_start_at": 0,
            "consecutive_failures": 0,
            "next_start_at": 0,
        }
def write_restart_state(state: dict[str, int]) -> None:
    RUNTIME_DIR.mkdir(parents=True, exist_ok=True)
    temporary = STATE_FILE.with_suffix(".tmp")
    temporary.write_text(json.dumps(state, ensure_ascii=True), encoding="utf-8")
    os.replace(temporary, STATE_FILE)
def record_failed_start(state: dict[str, int], now: int) -> dict[str, int]:
    last_start = int(state.get("last_start_at", 0))
    if not last_start or int(state.get("counted_failed_start_at", 0)) == last_start:
        return state
    failure_window = env_int("BOT_RESTART_FAILURE_WINDOW_SECONDS", 900, 120, 86400)
    if now - last_start <= failure_window:
        state["consecutive_failures"] = int(state.get("consecutive_failures", 0)) + 1
    else:
        state["consecutive_failures"] = 1
    state["counted_failed_start_at"] = last_start
    failures = state["consecutive_failures"]
    if failures >= 2:
        maximum = env_int("BOT_RESTART_BACKOFF_MAX_SECONDS", 1800, 60, 21600)
        delay = min(maximum, 60 * (2 ** min(8, failures - 2)))
        state["next_start_at"] = now + delay
        log(f"Bot failed {failures} consecutive starts; delaying restart for {delay} seconds")
    return state
def stop_stale_process(pid: int) -> None:
    if process_state(pid) != "bot":
        return
    log(f"Stopping stale bot process pid={pid}")
    os.kill(pid, signal.SIGTERM)
    for _ in range(20):
        time.sleep(0.5)
        if process_state(pid) == "dead":
            return
    if process_state(pid) == "bot":
        os.kill(pid, signal.SIGKILL)
def python_executable() -> str:
    configured = os.getenv("BOT_PYTHON", "").strip()
    if configured:
        configured_path = Path(configured).expanduser()
        return str(configured_path if configured_path.is_absolute() else (ROOT_DIR / configured_path).resolve())
    virtualenv = (
        BOT_DIR / "venv" / ("Scripts" if os.name == "nt" else "bin") / "python"
    )
    return str(virtualenv) if virtualenv.exists() else sys.executable
def start_bot() -> int:
    LOG_DIR.mkdir(parents=True, exist_ok=True)
    process_log = LOG_DIR / "bot-process.log"
    rotate_log(process_log)
    output = process_log.open("ab", buffering=0)
    process_env = os.environ.copy()
    process_env.update({"OPENBLAS_NUM_THREADS": "1", "OMP_NUM_THREADS": "1", "PYTHONUNBUFFERED": "1"})
    try:
        process = subprocess.Popen(
            [python_executable(), str(BOT_DIR / "main.py")],
            cwd=str(ROOT_DIR),
            stdin=subprocess.DEVNULL,
            stdout=output,
            stderr=subprocess.STDOUT,
            start_new_session=True,
            close_fds=True,
            env=process_env,
        )
    finally:
        output.close()
    time.sleep(2)
    if process.poll() is not None:
        log(f"Bot exited during startup with code={process.returncode}")
        return 1
    log(f"Started bot process pid={process.pid}")
    return 0
def main() -> int:
    lock = acquire_lock()
    if lock is None:
        return 0
    stale_seconds = env_int("BOT_WATCHDOG_STALE_SECONDS", 180, 60, 86400)
    now = int(time.time())
    restart_state = read_restart_state()
    pid = read_pid()
    state = process_state(pid) if pid else "dead"
    if pid and state == "bot" and heartbeat_is_fresh(stale_seconds, pid):
        stable_seconds = env_int("BOT_RESTART_STABLE_SECONDS", 600, 120, 86400)
        if restart_state["consecutive_failures"] and now - restart_state["last_start_at"] >= stable_seconds:
            restart_state.update({"counted_failed_start_at": 0, "consecutive_failures": 0, "next_start_at": 0})
            write_restart_state(restart_state)
        return 0
    if pid and state == "unknown":
        log(f"Bot pid={pid} is alive, but its command line cannot be verified; refusing an unsafe restart")
        return 1
    if pid and state == "bot":
        log(f"Heartbeat is stale for bot pid={pid}; restarting")
        stop_stale_process(pid)
    elif pid:
        log(f"Removing stale pid file for non-matching pid={pid}")
        (RUNTIME_DIR / "bot.pid").unlink(missing_ok=True)
    else:
        log("Bot pid file is absent; starting")
    restart_state = record_failed_start(restart_state, now)
    write_restart_state(restart_state)
    if restart_state["next_start_at"] > now:
        return 0
    restart_state["last_start_at"] = now
    restart_state["next_start_at"] = 0
    write_restart_state(restart_state)
    result = start_bot()
    if result != 0:
        restart_state = record_failed_start(restart_state, int(time.time()))
        write_restart_state(restart_state)
    return result
if __name__ == "__main__":
    raise SystemExit(main())
