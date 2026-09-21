---
name: plan-change
description: >
  Write the change record (.change/CHG-<n>.yml) that every change in this
  repository is gated against, before any code is written. Use when asked to
  plan a change, start work on an issue, scaffold or draft a CHG record, decide
  the target branch or paths for a change, or when a deprecation is requested
  and no record exists for it yet.
---

# plan-change

Write a change record. Do not write code, tests, or changelog entries in this
skill. The record is the contract everything downstream is checked against,
and writing it after the code defeats its purpose.

This skill runs with or without a human present. Every question below has a
default the agent takes when nobody answers, and every default taken is
written into the record as a YAML comment ending in `pending confirmation`,
so a reviewer can see what was assumed rather than decided.

## Inputs

Take these from the conversation, an issue body, or the text following
`/plan-change` if it was invoked by name:

- what is changing and why
- the issue reference, if one exists
- the target branch, if the user stated one

Anything missing is asked for in the steps below, each with its default.

## What to produce

A file at `.change/CHG-<n>.yml` matching the template for its change type:

- `.change/TEMPLATE-BUG-FIX.yml`
- `.change/TEMPLATE-FEATURE.yml`
- `.change/TEMPLATE-DEPRECATION.yml`

The templates carry the field by field notes, including which fields the
checks read and which `bin/pr-body.php` reads. Do not restate them here; copy
the template for the type and fill it in.

Use the next unused number. Records ship with the change that creates them and
leave the tree when that change merges, so the working tree alone
under-counts. Check both:

```bash
ls .change/CHG-*.yml 2>/dev/null
git log --all --diff-filter=A --name-only --format= -- '.change/CHG-*.yml' | sort -u
```

A record drafted from a GitHub issue uses the issue number instead, per the
template header.

## Steps

1. **Establish what is changing and why, in the user's words.** If they
   describe a solution, ask what breaks today if nothing changes. The answer
   goes in `release_note`, which a product manager reads and an engineer does
   not.

   Default when nobody answers: if the conversation or an issue body already
   describes the change, draft `release_note` from that and note
   `# drafted from <source>, pending confirmation` above it. If nothing
   describes the change at all, stop and say so. There is no default record
   for an unknown change.

2. **Decide `change_type`**: `bug_fix`, `feature`, or `deprecation`.

   Default when nobody answers: a request that names a symbol to replace or
   phase out is a `deprecation`; a report of behaviour that is wrong today is
   a `bug_fix`; anything else is a `feature`. State which was chosen and why.

3. **Determine `target.component`** from the paths involved. Set
   `target.paths` to the narrowest set that covers the work. Narrow is better:
   C4 fails anything outside these paths, and widening later is a deliberate
   act the reviewer sees.

   For a deprecation, `target.paths` must also declare `UPGRADE-<branch>.md`.
   C2 demands that file and C4 forbids anything undeclared, so both fail unless
   the record names it. The deprecation template shows the line.

4. **Resolve `owner`** by reading `.github/CODEOWNERS` and taking the most
   specific matching path. Do not type an owner from memory and do not carry
   one over from another record. `php bin/check-change.php` prints the owner
   it resolved and the pattern it matched; use that to cross-check yours.

5. **Ask the user which branch to target. Do not derive it.** The maintained
   branch set is not machine derivable and the decision is a judgement about
   where a bug was introduced. See [references/branch-choice.md](references/branch-choice.md)
   for why, and for what the project's own sources say.

   Default when nobody answers: write the currently checked out branch
   (`git branch --show-current`) and mark the line
   `# ASSUMED from the checked out branch, pending confirmation`. For a
   `bug_fix` say explicitly in the same comment that the oldest maintained
   branch containing the bug was not established.

6. **For a deprecation, fill `deprecates`** with the fully qualified symbol,
   its replacement, and the version. `since` and `target.branch` must match.

7. **Write `acceptance`.** Each criterion is one observable statement and the
   name of the test that will prove it, as
   `Fully\Qualified\Tests\ClassTest::testMethod`. The tests will not exist
   yet. Name them anyway: C5 fails until they do, which is the point.

## Finish: run the gate and read its output

```bash
php bin/check-change.php .change/CHG-<n>.yml --format=json
```

If the run cannot find its default base ref (`origin/<target.branch>`), pass
`--base <ref>` naming the branch the change will merge into.

Read the JSON:

- `status: error` means the record failed to load or failed schema
  validation. `detail` lists each problem. Fix the record and rerun. Nothing
  else is checked until this passes.
- `checks[]` carries one entry per check, `C1` to `C5`, each with a `status`
  of `pass`, `fail`, or `skip`.
- `violations[]` carries one entry per problem, each with a `fix` directive.

Apply every `fix` that is about the record itself: a test reference in the
wrong format, `deprecates.since` not matching `target.branch`, a missing
`UPGRADE-<branch>.md` in `target.paths`, an owner that does not match what
the gate resolved. Rerun after each change.

Do not apply a `fix` that asks for code, a changelog entry, or a test to be
written. Those belong to the change itself, not to its record, and for a
deprecation the `deprecate` skill carries them out.

Rerun until only failures of that second kind remain, then report each
remaining failing check by ID with the gate's own `message`. At this stage
expect C5 to fail because the named tests do not exist, and for a deprecation
expect C1 and C2 to fail because no code and no entries exist. C3 reports
`skip` locally unless a pull request is open; `skip` is not `pass`. Never
describe a failing check as passing. A record that passed the gate before any
work was done would mean it promises nothing.

## If a hook denies an action

Follow `.cursor/rules/boundary-recovery.mdc`: name the denied path, do not
retry or route around it, ask whether the boundary should be widened. Default
when nobody answers: do not widen it; finish what can be done inside the
boundary and report the denial.
