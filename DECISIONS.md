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

Symfony is the strongest available test of that, because it has done the writing
better than almost anyone. It ships 1656 lines of agent-readable guidance in
nine skills under `.agents/skills`, covering triage, merging, PR authoring,
security review and translations. `pr-authoring/SKILL.md` tells an agent to
choose the target branch before the first commit, to check that changelog and
upgrade entries sit in the unreleased section, and to run one component's tests
at a time rather than the monorepo.

And it still does not hold. Two specific failures, both measured.

**The branch targeting procedure reads the wrong key.** `releases.json` is
machine readable and exposes three overlapping keys: `supported_versions`
`["6.4","7.4","8.1"]`, `maintained_versions` `["6.4","7.4","8.1","8.2"]`, and
`security_maintained_versions` `["5.4"]`. `security-triage/SKILL.md:110`
instructs the agent to intersect with `maintained_versions`, which excludes 5.4.
In the corpus, 50 of 300 merged pull requests target 5.4: 6 labelled Security
and a further 38 with hardening-shaped titles. That is exactly the "public
hardening" disposition the same skill defines at line 23 as a normal open pull
request with a changelog and no embargo. An agent following step 2 would find
6.4 as the floor and leave the security maintained branch untouched.

**Nothing tells an agent how to author a deprecation.** Every mention of
deprecation across the nine skills treats it as something to react to:
`bug-triage` reads `Deprecations?` from the header table to classify, `merge-up`
handles legacy test groups and config keys a newer branch removed. In a project
whose defining constraint is a backward compatibility promise, the authoring
side is absent. That gap is what C1 fills.

**So: prose aimed at an agent is still prose.** Move convention feedback to
authoring time where it costs seconds, and put control at the gate where it
cannot be bypassed, from one source a human and an agent both read.

Feedback and control, not two layers of enforcement. The editor layer cannot
block, and overstating it is the first thing a skeptic takes apart.

---

## Measurements

| What | Value | Source |
| --- | --- | --- |
| Corpus | 300 merged PRs, 2026-08-23 to 2026-09-11 | `mine_prs.sh`, `analyze_prs.py` |
| Agent skills shipped by Symfony | 9 skills, 1656 lines, `.agents/skills` | `wc -l` |
| Skills stating how to author a deprecation | 0 | grep across `.agents/skills` |
| PRs targeting 5.4 | 50 of 300. 6 labelled Security, 38 hardening-shaped titles, 6 other | corpus, title classification |
| `releases.json` keys | 3, disagreeing on 5.4 and 8.2 | read 2026-09-12 |
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

**"The maintained set is not derivable."** Mine, and wrong. It is published at
`releases.json` and Symfony's own skills instruct agents to fetch it. The error
came from reasoning off the HTML release page and the branch list without
checking for a machine readable endpoint. The corrected finding is stronger than
the one it replaced, and it arrived by reading the repository's own agent
guidance rather than by reasoning harder.

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
| C3 checks self-consistency only | The set is derivable, and the canonical source exposes three overlapping keys. Choosing between them is a judgement a checker should not make silently, and Symfony's own security skill names the key that excludes 5.4. The branch decision goes to a human in `/plan-change`; C3 asserts only that the declaration matches the base |
| `.agents/**` inside the read boundary | Upstream Symfony ships nine agent skills in the repository. They are the agent's own instructions rather than codebase content, the same category as `.cursor` and `bin`, and denying them degrades the agent without protecting the code. Added after the agent was denied them and asked, which is the maintenance loop working once for real |
| Two checks enforce documented rules rather than new ones | C2 is stated in `pr-authoring` and `security-triage`; C3's rule is stated correctly in `pr-review-merge-prep:19`. Enforcing what a project already says is a stronger position than asserting something new, and it is the answer to "we already have an agent file" |
| Package and version folded into C1 rather than a separate check | 86.9% monorepo-wide looks weak and is strong inside a component: every mismatch read was a bridge or bundle deprecating for a package it wraps, or a test fixture. Scoping to a diff confined by C4 removes all of them |
| Empty package and version allowed | Symfony uses it when reporting a deprecation about third-party code it does not own. `ContainerBuilder.php:1206` and `:1223` |
| Read boundary as the primary editor control | The only file hook with a working deny channel |
| Revert off by default | It works and it desyncs the agent's model from disk. Kept as a demonstrated contrast rather than shipped behaviour |
| Gate on 8.4 and 8.5, not 8.6 | Covers the declared minimum and the local runtime. A token walker is exactly the tool that breaks on new syntax, and 8.6 is unreleased |
| Default dependency resolution | `low-deps` pulls older Symfony versions that emit their own deprecations, and the helper cannot separate those from the one under test |
| New comment on a status transition, edit in place otherwise | A rerun that reports the same result is not news; a status that flips from pass to fail or back is. Every rerun posting its own comment duplicates on every rerun with nothing to distinguish one from the next, and per-comment subscription does not exist on GitHub. Prior comments are found by the `<!-- change-record:ID -->` marker and the status is read back from the rendered comment body, so there is one source for what a comment says rather than a separate record that can drift from it |
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
- Two of Symfony's own skills restate the maintained set in illustrative
  comments rather than fetching it: `bug-triage:24` and `sync-translations:50`
  both show arrays containing 8.0, which is no longer in the live
  `maintained_versions`. Illustrative rather than executed, so the drift is
  harmless today, and it is the same failure mode this manifest guards against.
  Any value restated in prose goes stale; a fetched one does not.
- The change record reader falls back to a constrained YAML parser when the
  autoloader is absent. It has diverged from Symfony's component twice, on
  inline comments and on backslash escapes. It should be deleted.
- The corpus is three weeks and one repository. None of the rates above are
  quoted as a customer's cost, because Symfony cannot measure a private codebase.

---

## Pre-existing failures on this fork

Found while getting pull request #1 (CHG-0001) green, 2026-09-12. Neither is
caused by the artifact or by CHG-0001. Both were confirmed against
`symfony/symfony`'s own `8.2` branch at commit `2d43fa6935f95f69893b7dc2c150df245ac7b294`
— the exact commit this fork's `8.2` was branched from, verified as an
ancestor of this fork's `8.2` and as the live tip of upstream's `8.2` at the
time of the check.

- **`Unit Tests (8.4, high-deps)`.**
  `Symfony\Bundle\FrameworkBundle\Tests\Routing\RedirectableCompiledUrlMatcherTest::testSchemeRedirect`
  fails: "Failed asserting that two arrays are equal", the actual match
  result carrying an extra `_scheme_redirect => true` key the expectation
  doesn't have. That test class exists nowhere in this checkout, only
  `RedirectableCompiledUrlMatcher.php` does, which is the first sign this
  isn't a code regression in this repository at all.

  Mechanism, not a flake. `unit-tests.yml:120-131`: for whichever branch is
  currently the highest maintained one, `high-deps` mode deliberately
  `git fetch`es and `git checkout -m`'s the *previous* branch mid-run, to
  test the current branch's patched components against the last stable
  branch's tree. `git ls-remote --heads` against the real
  `symfony/symfony` confirms `8.2` is that highest branch today, so every
  `high-deps` run on `8.2` performs this checkout, replacing the working
  tree with `8.1`'s. `8.1` still carries its own copy of
  `FrameworkBundle\Tests\Routing\RedirectableCompiledUrlMatcherTest`,
  confirmed by fetching it directly: `testSchemeRedirect` there still
  expects the pre-`_scheme_redirect` shape. That file was deleted from
  `8.2` on 2026-09-10 by `906471c2d42` (an unrelated FrameworkBundle
  wiring refactor), a full day before `_scheme_redirect` was introduced by
  PR #66025 (verified from #66025's own diff: `RouterListener.php`,
  `RedirectableUrlMatcher.php`, `CompiledUrlMatcherTrait.php`, and the
  matching `Routing` test updates; it never touches `FrameworkBundle`). So
  the CI job ends up running `8.1`'s stale test against `Routing`'s newer
  behaviour: nobody updated or removed `8.1`'s copy when `_scheme_redirect`
  shipped, because it only shipped on `8.2`.

  One link in this chain is confirmed by outcome but not by direct
  instrumentation: exactly how the newer `Routing` behaviour still reaches
  `8.1`'s checked-out test after the tree swap. `build-packages.php` only
  builds a locally patched package for a component whose diff against the
  tested commit's own parent is non-empty, which a Console-only commit
  never triggers for `Routing` or `FrameworkBundle`; the working theory is
  that both then resolve via Packagist's live `8.2.x-dev`/`8.1.x-dev`
  snapshots, which already reflect this exact state. Timestamped evidence
  that the resulting outcome is real and not incidental: PR #66025's own
  `Unit Tests (8.4, high-deps)` check, run before `_scheme_redirect`
  merged, passed
  (github.com/symfony/symfony/actions/runs/34630658335/job/103366392072);
  `2d43fa6935f9` — an unrelated commit, #66020's Console `LockableTrait`
  change, touching neither `Routing` nor `FrameworkBundle` — failed the
  same check on this exact assertion once `_scheme_redirect` existed
  upstream
  (github.com/symfony/symfony/actions/runs/34637505927/job/103388887434).

  Consequence: this is structural, not a one-off. It will reproduce on
  essentially every future commit to `8.2` under `high-deps` until `8.1`'s
  stale test is fixed or removed, or until `8.2` stops being the highest
  branch. Rebasing onto an earlier commit doesn't help, since the code
  under test (`Routing`, `FrameworkBundle`) is byte-identical between
  #66025's commit and `2d43fa6935f9` (`git diff`, empty) — the difference
  was never which commit, only whether `_scheme_redirect` existed yet. A
  composer constraint forcing a different resolution would mean editing
  `FrameworkBundle` or `Routing` `composer.json`, outside CHG-0001's
  Console-only scope and outside what this artifact governs, and it would
  mask the symptom rather than fix `8.1`'s stale test. Not excluded
  anywhere; neither `convention-gate.yml` nor `unit-tests.yml` names this
  test.

- **`x86 / minimal-exts / lowest-php` (Windows).**
  `Symfony\Component\HttpClient\Tests\AmpHttpClientTest::testTimeoutOnDestruct`
  fails: "Failed asserting that 1.1557400226593018 is less than 1.0", a
  wall-clock assertion that a destructor completes inside one second.
  Evidence it predates this fork and isn't a standing bug: the same job at
  `2d43fa6935f9` upstream is `success`
  (github.com/symfony/symfony/actions/runs/34637505911/job/103388888937), and
  a retry of the identical failed job on PR #1 also failed, on the same
  assertion, at a different elapsed time — consistent with CI-load timing
  noise on the Windows runner rather than a deterministic bug. Not excluded
  anywhere. A timing flake this shape is what a retry is for; a permanent
  exclusion in `windows.yml` would change CI for every future pull request
  against `8.2`, not just this one, so it was deliberately not added.

---

## Open

- Closed 2026-09-12. `InvokableCommandTest::testAskWithVariadicInputFilesCollectsAndStacks`
  was believed to error on a clean checkout, deterministically, in isolation,
  and was excluded by name in the workflow on that belief. Re-measurement
  found no environment where it actually fails: not in 5 isolated local runs,
  not in the full local Console suite, not with the exact upstream phpunit
  invocation, not on symfony/symfony's own Unit Tests (8.4) and (8.5) CI at
  the commit this fork's 8.2 was branched from, and not on the convention
  gate's own runner once `--filter` was removed
  (`gate (php 8.4)` and `gate (php 8.5)`, 1977 tests, 0 failures, both
  green). The `--filter` exclusion is removed from `convention-gate.yml`.
  What produced the original observation was never established; the claim
  itself did not survive contact with evidence.
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
