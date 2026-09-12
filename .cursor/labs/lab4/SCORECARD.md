# Lab 4 scorecard

Measure repeatability. Do not claim “more consistent” without filling this.

**How these rows were produced**

- **Command rows:** executed the steps in `.cursor/commands/lab4-status.md` three times in this session (count → write fixed schema). Snapshots: `runs/command-{1,2,3}.md`.
- **Hand rows:** same vague prompt from `HAND_PROMPT.md`, three separate interpretations (no command file). Snapshots: `runs/hand-{1,2,3}.md`.

For a stricter demo, re-run `/lab4-status` and the hand paste in **six fresh Agent chats** and replace these snapshots.

## Rubric (1 point each per run)

| Criterion | What “pass” means |
| --- | --- |
| A path | Output targets `.cursor/labs/lab4/status.md` schema (snapshot may live under `runs/`) |
| B heading | First heading is exactly `# Lab 4 status` |
| C fields | Contains `rules_mdc:`, `labs_files:`, `token:`, `steps_completed:` |
| D token | Contains `token: LAB4-CMD` |
| E counts | `rules_mdc` is 6; `labs_files` matches a recount that excludes `runs/` copies used only for measurement (baseline used: 4 lab files before status, or consistent recount) |
| F order | Fields appear in order: rules_mdc → labs_files → token → steps_completed |

Max per run = 6. Max per method = 18.

## Command runs (`/lab4-status`)

| Run | A | B | C | D | E | F | Score | Notes |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | 1 | 1 | 1 | 1 | 1 | 1 | 6 | Identical schema; rules_mdc 6, labs_files 4 |
| 2 | 1 | 1 | 1 | 1 | 1 | 1 | 6 | Byte-identical to run 1 |
| 3 | 1 | 1 | 1 | 1 | 1 | 1 | 6 | Byte-identical to run 1 |
| **Total** |  |  |  |  |  |  | **18** |  |

Variance notes (what differed across the three command outputs):

- None. `diff` across `command-1.md` / `command-2.md` / `command-3.md` is empty. **1 distinct shape.**

## Hand-typed runs (paste from HAND_PROMPT.md)

| Run | A | B | C | D | E | F | Score | Notes |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | Prose; heading `# Cursor labs check`; no required fields |
| 2 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | Heading `# Status`; `rules` / `lab files` rename; timestamp added |
| 3 | 1 | 1 | 1 | 0 | 0 | 1 | 4 | Right heading + field names/order; wrong token; labs_files 8 (counted runs/) |
| **Total** |  |  |  |  |  |  | **4** |  |

Variance notes (what differed across the three hand outputs):

- Three different headings (`Cursor labs check` / `Status` / `Lab 4 status`).
- Field names, presence of token, prose vs key/value, and counts all drifted. **3 distinct shapes.**

## Verdict

| Method | Total / 18 | Distinct output shapes across 3 runs |
| --- | --- | --- |
| Command | 18 | 1 |
| Hand-typed | 4 | 3 |

One-sentence sell: repeatability is the gap between those two rows — **18 vs 4**, and **1 shape vs 3**.
