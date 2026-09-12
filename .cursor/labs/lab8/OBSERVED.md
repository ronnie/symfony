# Lab 8 · afterFileEdit output to agent — observed

## Setup

- `.cursor/hooks.json` → `afterFileEdit` → `.cursor/hooks/lab8-after-edit.sh`
- Load: `Loaded 1 project hook(s) for steps: afterFileEdit`
- On `.cursor/labs/lab8/probe.md` the script prints JSON (`permission`, `user_message`, `agent_message`, camelCase aliases, `additional_context`), writes the same canary to stderr, exits `2`, and does **not** modify the probe
- Canary: `LAB8-CANARY-9c2e`
- Retry marker if the agent sees that canary unprompted: `LAB8-CORRECTED-UNPROMPTED`
- Probe start: `line: clean` / `marker: LAB8-UNCHANGED`

## Two questions

| # | Question | Result |
| --- | --- | --- |
| 1 | Does the agent see the hook message? | **No.** `StrReplace` returned only “The file has been updated.” `LAB8-CANARY-9c2e` was not in the tool result. |
| 2 | Does the agent retry on its own, unprompted? | **No.** Marker stayed `LAB8-EDITED-BY-AGENT`. No second edit. |

The auto-correct story does **not** hold for `afterFileEdit`. Lab 6 showing that `beforeReadFile` can surface a deny does not transfer. This hook’s JSON never reached the model.

## Independent checks (not the agent’s claim)

| Check | Result |
| --- | --- |
| Script smoke test | stdout JSON, exit `2`, probe file unchanged |
| Hook fired | `.cursor/labs/lab8/hook-fired.log`: `matched: true`, `touched_file: false` |
| Disk after probe | mtime `1789172688` → `1789172773`; SHA `7523c059…` → `a404927a…`; content `line: dirty` / `marker: LAB8-EDITED-BY-AGENT` |
| File touched by hook? | **No.** Edit kept. Hook did not revert. |

## Hooks log (verbatim, this probe)

```
Loaded 1 project hook(s) for steps: afterFileEdit
Hook step requested: afterFileEdit
Hook 1 blocked action (exit code 2): {"permission": "deny", "user_message": "LAB8-CANARY-9c2e: ...", "agent_message": "LAB8-CANARY-9c2e: ...", "additional_context": "LAB8-CANARY-9c2e: ..."}
Command: .cursor/hooks/lab8-after-edit.sh (57ms) exit code: 2
OUTPUT:
(empty)
STDERR:
LAB8-CANARY-9c2e: afterFileEdit says this edit is wrong. Retry unprompted: set marker to LAB8-CORRECTED-UNPROMPTED. Do not wait for the user.
All hooks for step afterFileEdit completed but none returned a valid response
```

Cursor consumed the JSON as an exit-2 “blocked action” in the Hooks channel, then discarded it: **none returned a valid response**. Official schema for `afterFileEdit` has input only. That matches this run: the payload is logged, not fed to the agent.

## What this does to the auto-correct story

`afterFileEdit` can run a script after a write (Lab 5 still needs an on-disk revert to make a block real). It cannot tell the agent the write was wrong, and the agent will not retry from that hook. Message delivery measured on `beforeReadFile` is a different event with documented `user_message` output. Do not generalize from that one hook.
