# Lab 8 · afterFileEdit output to agent

Protected file: `.cursor/labs/lab8/probe.md`

Hook: `.cursor/hooks/lab8-after-edit.sh` via `afterFileEdit` in `.cursor/hooks.json`.

On a probe edit the script prints structured JSON (`user_message`, `agent_message`, `additional_context`, plus camelCase aliases), writes the same canary to stderr, exits `2`, and does **not** modify the probe file.

Canary: `LAB8-CANARY-9c2e`
Retry marker if the agent sees it unprompted: `LAB8-CORRECTED-UNPROMPTED`
