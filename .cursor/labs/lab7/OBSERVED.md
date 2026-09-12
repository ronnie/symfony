# Lab 7 · beforeFileEdit deny — observed

## Setup

- `.cursor/hooks.json` → `beforeFileEdit` → `.cursor/hooks/lab7-deny-edit.sh` (`failClosed: true`)
- Script returns `{"permission":"deny","userMessage":"..."}` for `.cursor/labs/lab7/probe.md` (smoke test: deny + exit 2)
- Probe start: `line: clean` / `marker: LAB7-UNCHANGED`

## Three observations

| # | Question | Result |
| --- | --- | --- |
| 1 | Does Cursor accept the event name at load? | **No.** Hooks log: `Unknown hook type: beforeFileEdit`. Valid list includes `beforeReadFile` and `afterFileEdit`, not `beforeFileEdit`. Whole project config fails to parse. |
| 2 | Does the file on disk stay unmodified? | **No.** `StrReplace` wrote through. mtime `1789171669` → `1789171771`. Content became `line: dirty` / `marker: LAB7-EDITED-BY-AGENT`. SHA `15f7dce1…` → `cc8cffbe…`. No `hook-fired.log` (script never ran). |
| 3 | Does the agent name the hook the way it did for `beforeReadFile`? | **No.** Lab 6 could say “a `beforeReadFile` hook denied …” because Cursor delivered a deny. Here there is no deny to name. Correct line is: Cursor rejected `beforeFileEdit` as unknown; the write was not blocked. |

## Requests 1 and 2

They do **not** hold. There is no pre-edit deny event, so Lab 5’s post-write revert is still the only file-edit block, and it stays necessary for the wrong reason (no pre-hook), not because a deny ran first.

## Load error (verbatim)

```
ERROR: Invalid project config: Unknown hook type: beforeFileEdit. Valid types are: beforeShellExecution, beforeMCPExecution, afterShellExecution, afterMCPExecution, beforeReadFile, afterFileEdit, beforeTabFileRead, afterTabFileEdit, stop, beforeSubmitPrompt, afterAgentResponse, afterAgentThought, sessionStart, sessionEnd, preCompact, subagentStart, subagentStop, preToolUse, postToolUse, postToolUseFailure, workspaceOpen
ERROR: Failed to parse project hooks configuration
No project hooks configuration found
```
