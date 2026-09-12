#!/usr/bin/env bash
# Lab 7: beforeFileEdit — deny edits to .cursor/labs/lab7/probe.md
set -euo pipefail

input="$(cat)"
export LAB7_HOOK_INPUT="$input"

python3 - <<'PY'
import json, os, sys, time

SCOPE = ".cursor/labs/lab7/probe.md"
LOG = os.path.join(
    os.environ.get("CURSOR_PROJECT_DIR", ""),
    ".cursor/labs/lab7/hook-fired.log",
)
# Fallback: walk from this script to the project labs dir.
if not os.environ.get("CURSOR_PROJECT_DIR"):
    here = os.path.dirname(os.path.abspath(__file__))
    LOG = os.path.normpath(os.path.join(here, "..", "labs", "lab7", "hook-fired.log"))

raw = os.environ.get("LAB7_HOOK_INPUT", "")
try:
    data = json.loads(raw) if raw else {}
except json.JSONDecodeError:
    print(json.dumps({"permission": "allow"}))
    sys.exit(0)

path = (data.get("file_path") or "").replace("\\", "/")
denied = SCOPE in path
try:
    os.makedirs(os.path.dirname(LOG), exist_ok=True)
    with open(LOG, "a", encoding="utf-8") as f:
        f.write(json.dumps({"ts": time.time(), "path": path, "denied": denied}) + "\n")
except OSError:
    pass

if denied:
    msg = (
        "LAB7 hook denied edit: path is .cursor/labs/lab7/probe.md. "
        "The file must stay unmodified."
    )
    print(json.dumps({"permission": "deny", "userMessage": msg}))
    sys.stderr.write(msg + "\n")
    sys.exit(2)

print(json.dumps({"permission": "allow"}))
sys.exit(0)
PY
