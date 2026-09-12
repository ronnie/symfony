# Decisions and evidence

Everything here was measured against this repository on branch 8.2, or observed
by running Cursor against it. Nothing is asserted from memory. Where something
is unverified it says so.

This file exists because the reasoning is the deliverable as much as the code
is, and because a convention manifest nobody can re-derive is the failure the
manifest itself argues against.

---

## The claim

Conventions that live only in prose are unenforceable, unmaintainable, and wrong
more often than anyone believes.

Symfony proves all three at once. Its maintained branch set cannot be derived
from the branch list, because the repository carries 7.0 through 7.2 long after
support ended. It cannot be derived from the published release policy, because
5.4 outlived what that policy predicts. And it is contradicted by the project's
own release page, which lists 5.4 security support as ending November 2025 while
a sponsorship agreement extends it to February 2029.

Fifty of three hundred merged pull requests in the mined corpus target 5.4. All
fifty are correct. A check encoding the documented policy would have failed
every one.

**So: move convention feedback to authoring time where it costs seconds, and put
control at the gate where it cannot be bypassed, from one source a human and an
agent both read.**

Feedback and control, not two layers of enforcement. The editor layer cannot
block, and overstating it is the first thing a skeptic takes apart.

---

## Measurements

| What | Value | Source |
| --- | --- | --- |
| Corpus | 300 merged PRs, 2026-08-23 to 2026-09-11 | `mine_prs.sh`, `analyze_prs.py` |
| Files scanned by the walker | 6189 in 0.9s, zero tokenize failures | `bin/survey.php` |
| Deprecation pull requests | 13 of 293 with a parseable header, 4.4% | corpus section 3 |
| C1 pairing | 15 of 15, one excluded as `@internal` | `bin/survey.php` section 2 |
| Package argument matches owning component | 119 of 137 monorepo wide | `bin/survey-calls.php` |
| `@deprecated` by kind | 32 class-like, 18 method, 2 property | `bin/survey.php` section 4 |
| `expectUserDeprecationMessage` | 52 files, against 9 for the legacy trait | grep |
| `IgnoreDeprecations` | 86 files | grep |
| `InputOption` accessors to migrate | 16 call sites, 10 files, all inside Console | grep |
| Single test file | 0.24s | `./phpunit` |
| Console suite | 12s, 2048 tests | `./phpunit` |
| Pre-existing failure | 1, `InvokableCommandTest::testAskWithVariadicInputFilesCollectsAndStacks` | `./phpunit` |

**n is 16 for the C1 pairing rate. Quote the count, never the percentage.**

---

## What was tested and discarded

Four framings died, each to a specific measurement. This list is the honest
answer to "walk us through your thinking," and it is worth more in the room than
any surviving statistic.

**Ramp speed.** Median changes-requested rounds is 0.0 for insiders and
outsiders alike. Median time to merge is roughly one hour for insiders and six
for outsiders. There is no review rework cost at Symfony to attack. This died
once on `cli/cli` and again here.

**Violation rate as the business case.** C2 missed once in thirteen deprecation
pull requests. C3's original upper bound was 6.1% and included legitimate bugs
introduced on the development branch. Neither number survives a follow-up
question.

**Review archaeology as a source of conventions.** 41% of human comments are
merge acknowledgements from one maintainer. After stripping them, 78.5% of the
remainder will not cluster into objection classes. Symfony reviewers write
bespoke design discussion. Conventions came from repository artifacts instead,
which is a better source because it is citable.

**Maintainer absorption.** Attractive and unsupported. One person wrote 66.6% of
comments and also opened 178 of the 300 pull requests, so the concentration is
authorship rather than correction work.

---

## Cursor tool behaviour, observed

| Hook | Behaviour | Lab |
| --- | --- | --- |
| `beforeReadFile` | Denies visibly, agent names the hook | 6 |
| `afterFileEdit` | Runs after the write, reverts silently, does not block | 5 |
| `afterFileEdit` output | Logged and dropped. Cursor reports that no hook returned a valid response. Agent does not retry | 8 |
| `beforeFileEdit` | Does not exist. Rejected at load as an unknown type, and the rejection discards the entire configuration file | 7 |
| Load timing | Hooks not loaded at session start run unguarded. This was the cause of apparent intermittency | 1 to 6 |

**Consequence.** The Audit and Order pattern cannot be delivered through a hook,
because no file hook has a channel back to the agent. The channel that works is
tool output: the agent runs `bin/check-change.php` and its stdout lands in
context natively. Enforcement of writes belongs in CI.

---

## Decisions

| Decision | Reason |
| --- | --- |
| Console as the target component | Only candidate with CODEOWNERS coverage and a deprecation call to cite. Carries zero `@deprecated` annotations, which is the argument rather than the problem: symbol deprecation happens 16 times in 6189 files, so no engineer anywhere has a nearby example to copy |
| Every checker in PHP | The platform team inherits it. Tooling in a language the codebase does not use is a maintainability wart |
| `PhpToken::tokenize` over a parser package | A parser dependency needs a require entry, which is what C4 fails on. The tooling would trip its own gate |
| C1 methods only | A deprecated class or interface has no body to hold the call. Demanding one fires on correct code |
| `@internal` excluded from C1 | `@internal` means no caller outside the component can reach it. The user-facing deprecation is declared where the caller is: `HttpKernelExtension.php:30` uses `DeprecatedCallableInfo('symfony/twig-bridge', '8.2', ...)` for exactly this method |
| C3 checks self-consistency only | The maintained set is not derivable. See the claim above |
| Package and version folded into C1 rather than a separate check | 86.9% monorepo-wide looks weak and is strong inside a component: every mismatch read was a bridge or bundle deprecating for a package it wraps, or a test fixture. Scoping to a diff confined by C4 removes all of them |
| Empty package and version allowed | Symfony uses it when reporting a deprecation about third-party code it does not own. `ContainerBuilder.php:1206` and `:1223` |
| Read boundary as the primary editor control | The only file hook with a working deny channel |
| Revert off by default | It works and it desyncs the agent's model from disk. Kept as a demonstrated contrast rather than shipped behaviour |
| Gate on 8.4 and 8.5, not 8.6 | Covers the declared minimum and the local runtime. A token walker is exactly the tool that breaks on new syntax, and 8.6 is unreleased |
| Default dependency resolution | `low-deps` pulls older Symfony versions that emit their own deprecations, and the helper cannot separate those from the one under test |
| One sticky PR comment | Three duplicate on every rerun. Per-comment subscription does not exist on GitHub |
| Owners from CODEOWNERS | A hand-maintained list is a second source of truth |

---

## Known limits

- C1 sees methods and functions with bodies. 32 of 52 `@deprecated` annotations
  in this framework are on classes, so roughly two thirds are invisible to it.
  The checker prints what it skipped on every run.
- PHP 8.4 property hooks give properties a body, so C1's justification for
  skipping them is eroding under the language rather than under the codebase.
  One instance exists at `HttpKernel/Event/ViewEvent.php:30`.
- `mutating_pattern` in the hook guard is a denylist of command shapes, not a
  shell parser. It closes the easy route and explains why. CI is the control.
- The agent can read the guard that constrains it. `bin/**` and `.cursor/**` are
  inside the read allowance because the agent has to run the checker and read the
  manifest, so `bin/hook-guard.php` and its denylist are both visible to it. The
  editor layer is a workflow control, not a security control: it closes the easy
  route, explains itself when it fires, and CI is the enforcement that cannot be
  read or reasoned around.
- The hook response field names follow the lab 6 observation. A wrong key fails
  open and silently.
- The manifest was in `bin/` for three labs, so `hook-guard.php` never found it
  and fell back to hardcoded values that happened to name Console. Every output
  looked correct. A fail-quiet default inside the tool that argues against
  fail-quiet defaults. Closed: a missing manifest is now exit 2 for `allowance`
  and a denial for `read` and `shell`, and the boundary is assembled from
  `boundary.read_allow` plus `artifact_paths` plus the component's own composer
  require block, with nothing hardcoded. Demonstrable in ten seconds by moving
  the file aside, which is worth showing rather than claiming.
- `git diff` does not see untracked files, so a new file written outside the
  boundary was invisible to C4 on a local run. CI never had the problem because
  everything is committed there, and a hole that exists only in the fast local
  loop is the one the engineer relies on. Closed by adding
  `git ls-files --others --exclude-standard` to the changed file set.
- C3 compares the declared branch against the pull request base, so outside a
  pull request there is nothing to compare and it skips. C3 is a CI only check.
- The change record reader falls back to a constrained YAML parser when the
  autoloader is absent. It has diverged from Symfony's component twice, on
  inline comments and on backslash escapes. It should be deleted.
- The corpus is three weeks and one repository. None of the rates above are
  quoted as a customer's cost, because Symfony cannot measure a private codebase.

---

## Open

- `InvokableCommandTest::testAskWithVariadicInputFilesCollectsAndStacks` errors
  on a clean checkout, deterministically, in isolation. Excluded by name in the
  workflow so any other failure still surfaces. Cause not yet established.
- Lab 9: the complete valid hook type list, still truncated in the lab 7 notes.
- Whether the rule text in `/deprecate` step 4 actually changes agent behaviour.
  Run the command with and without the sentence about related implementations
  and see whether the agent misses `acceptValue()`.

---

## Continuing this work

Tooling, the checker, and the call-site migration can be done anywhere with repo
access. Anything under `.cursor/` has to be exercised by running the Cursor agent
against it, because the observations in the table above only exist because the
tool was used rather than read about.
