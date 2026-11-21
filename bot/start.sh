#!/usr/bin/env sh
set -eu

BOT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
ROOT_DIR=$(CDPATH= cd -- "$BOT_DIR/.." && pwd)
PYTHON=${BOT_PYTHON:-"$BOT_DIR/venv/bin/python"}

if [ ! -x "$PYTHON" ]; then
    PYTHON=$(command -v python3 || true)
fi

if [ -z "$PYTHON" ] || [ ! -x "$PYTHON" ]; then
    echo "Python 3 or bot/venv/bin/python was not found." >&2
    exit 1
fi

umask 027
cd "$ROOT_DIR"
exec "$PYTHON" "$BOT_DIR/main.py"
