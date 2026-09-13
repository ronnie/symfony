# Convention gate

Conventions that live only in prose are unenforceable, unmaintainable, and wrong
more often than anyone believes. This is the version that fires at authoring
time and again at the gate, from one source a human and an agent both read.

Scoped to the Console component and to one change type: declaring a deprecation.
See `DECISIONS.md` for why that scope, what was measured, and what was tried and
discarded.

## Verify the install

```bash
php bin/selftest.php          # 21 fixtures for the token walker
php bin/hook-guard.php allowance
```

`allowance` prints the boundary. It must not error. If it reports that
`conventions.yml` was not found, the manifest is in the wrong place: it belongs
at the repository root and nothing works without it.

Prove that for yourself, because it is the failure this artifact is about:

```bash
mv conventions.yml /tmp/ && php bin/hook-guard.php allowance; mv /tmp/conventions.yml .
```

Exit 2 with the path named. An earlier version fell back to hardcoded defaults
that happened to be correct, and every output looked right for three labs.

## Run the gate

```bash
php bin/check-change.php .change/CHG-0001.yml
php bin/check-change.php .change/CHG-0001.yml --format=json
```

Human format for a person. JSON with a `fix` field per violation for the agent,
which is the feedback channel: hooks cannot return anything to the model, so the
agent runs this as a shell command and reads stdout. Measured, see lab 8.

Exit 0 all passing, 1 a check failed, 2 usage or schema error.

## The five checks

| Check | Asserts |
| --- | --- |
| C1.pairing | A method annotated `@deprecated`, not `@internal`, calls `trigger_deprecation()` in its body |
| C1.package | First argument equals the owning component's composer name |
| C1.version | Second argument equals the version in the change record |
| C1.declared | A record declaring a deprecation has one in the diff |
| C2 | The deprecation carries a component changelog entry |
| C3 | Declared branch matches the pull request base. CI only |
| C4 | Diff stays inside declared paths and adds no composer require |
| C5 | Every acceptance criterion names a test that exists and ran |

Every one of them is derived from something citable in the repository, and each
carries its evidence in `conventions.yml`. Three exceptions are derived rather
than invented: `@internal` symbols, an empty package and version pair, and
bridges deprecating on behalf of a wrapped package. The manifest names the file
that justifies each.

## Where things live

```
conventions.yml                       manifest. Root, required.
DECISIONS.md                          evidence, decisions, known limits
.change/CHG-0001.yml                  change record
.cursor/hooks.json                    beforeReadFile, beforeShellExecution
.cursor/rules/boundary-recovery.mdc   behaviour after a denial
.cursor/commands/                     /plan-change, /deprecate
.cursor/labs/                         observed Cursor behaviour, lab notes
.github/workflows/convention-gate.yml the CI gate. Not conventions.yml.
bin/check-change.php                  the gate
bin/hook-guard.php                    the boundary
bin/pr-body.php                       record to pull request body
bin/pr-comment.php                    record to gate comment
bin/selftest.php                      fixtures
bin/survey.php, bin/survey-calls.php  how the citations were measured
bin/lib/                              walker, CODEOWNERS, record, git
```

`convention-gate.yml` and `conventions.yml` are different kinds of thing that
briefly shared a name. The first is a GitHub Actions workflow, the second is the
manifest.

## The workflow

```
verify layout -> locate change record -> install -> gate -> tests -> gate again
```

The gate runs twice on purpose. The first pass covers C1 to C4. The second runs
after the suite with `JUNIT_XML` set so C5 can verify the named tests executed
rather than merely existing. Same script both times, and the same script you run
locally. No drift between editor and pipeline is the point of the whole thing.

PHP 8.4 and 8.5. The test step excludes the same groups upstream's own
`unit-tests.yml` excludes: `tty`, `benchmark`, `intl-data`, `integration`,
`transient`. A hosted runner has no interactive stdin, which is what the
`tty` exclusion is for; Console has no tests in the other four groups today,
so listing them changes nothing yet, it just matches upstream's own
invocation. A name-based exclusion for one specific test used to sit
alongside these group exclusions, on the belief that the test errored
deterministically on a clean checkout. Re-measurement found no environment
where it actually failed, including this gate's own runner with the
exclusion removed, so the exclusion was removed. See DECISIONS.md.

## Proof the gate catches something

On 2026-09-13, the changelog entry for the `InputOption::isValueRequired()`
deprecation was moved from the "8.2" section of
`src/Symfony/Component/Console/CHANGELOG.md` into the "8.1" section, and
nothing else was touched. Correct syntax, correct wording, correct file,
wrong version heading.

That is the break worth demonstrating because nothing else in the repository
notices it. Every existing test still passes: nothing asserts where a
changelog line sits. A reviewer scanning a fourteen file diff sees one line
move a few lines down inside a file that was already part of the change, and
waves it through. The entry ships under the wrong release, and the people who
read release notes by version are not the people who reviewed the diff.

C2 caught it. From the actual job log
(`gh run view 34731690881 --repo ronnie/symfony --log-failed`):

```
C2   changelog and upgrade  FAIL  C2.changelog outside section, C2.upgrade ok (1 line under "Console")

C2.changelog src/Symfony/Component/Console/CHANGELOG.md:51
  The entry sits outside the "8.2" section, which spans lines 4 to 27.
  fix: Move it under the "8.2" heading. An entry in the wrong section reaches the wrong release notes.
```

Moving the entry back restored 5 of 5. Both results are visible in the pull
request thread, but not as one comment edited twice: `bin/pr-comment.php`
switched from one sticky comment to one comment per run in between these two
runs, so the pull request carries two separate comments, one holding each
result, rather than a single comment overwritten in place. Checked directly
against the pull request (`gh pr view 1 --json comments`) rather than
assumed: it returns two comments matching this change record, not one.

| Run | PHP | URL |
| --- | --- | --- |
| Red, entry in the 8.1 section | 8.4 | https://github.com/ronnie/symfony/actions/runs/34731690881/job/103655524249 |
| Red, entry in the 8.1 section | 8.5 | https://github.com/ronnie/symfony/actions/runs/34731690881/job/103655524335 |
| Green, entry restored under 8.2 | 8.4 | https://github.com/ronnie/symfony/actions/runs/34732266326/job/103657106729 |
| Green, entry restored under 8.2 | 8.5 | https://github.com/ronnie/symfony/actions/runs/34732266326/job/103657106761 |

Two other checks are red on this pull request for reasons unrelated to this
change. See "Pre-existing failures on this fork" in `DECISIONS.md`.

## Extending it

**Add a convention.** Append a record to `conventions.yml` with an id, the rule,
the glob, the check, and a `source` citing the file in this repository the
convention was derived from. A record without a citation is an assertion, and
`verified: false` marks one that has not been confirmed against the repo.

Measure before you encode. `bin/survey.php` exists because C1 was a hypothesis
until it was 15 of 15 across 6189 files. Two checks in here were narrowed after
measurement contradicted the first design, and one was cut entirely.

**Add a check.** Implement it in `bin/check-change.php`, append to `$results`
with the same shape, and add fixtures to `bin/selftest.php` if it touches the
walker. Keep the output shape stable: `bin/pr-comment.php` reads it.

**Change the boundary.** Edit `boundary.read_allow` in the manifest. Do not
hardcode paths in `hook-guard.php`. Component dependencies resolve from the
component's own `composer.json`, so a new dependency widens the boundary
automatically and no list needs maintaining.

**Move to another component.** Change `scope` in the manifest. Owners resolve
from `.github/CODEOWNERS` by most specific match. Expect the rule text to need
per component variation, which is untested.

## Limits

`DECISIONS.md` carries these in full. The short version: the editor layer is
advisory because no pre-edit hook exists and post-edit hooks cannot talk back;
C1 covers methods only, so roughly two thirds of `@deprecated` annotations in
this framework are invisible to it; the shell guard is a denylist of command
shapes rather than a parser; and the agent can read the guard that constrains
it. CI is the control.

## Research tooling

The PR mining and the objection analysis are Python and live outside this
repository, at `~/dev/cursor-technical/research/`. They produced the citations in
the manifest and they never run in CI and never ship. Everything in here is PHP,
because the team that inherits it writes PHP.
