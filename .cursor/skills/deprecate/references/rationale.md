# Why the deprecate steps are shaped the way they are

The procedure is in `SKILL.md`. This file holds the reasoning and the
citations, so the procedure stays short. Quoted numbers carry the date they
were measured and will drift; the command to recount is given next to each.

## The rule and its evidence

`conventions.yml`, record C1, is the single source: the rule, the three
assertions (`C1.pairing`, `C1.package`, `C1.version`), the exceptions, the
measured pairing rate, the known blind spot for class and property
deprecations, and the files each of those was derived from. Read it there.

## Step 1: never change the old signature

Symfony's backward compatibility promise (`https://symfony.com/bc`, linked
from `.github/PULL_REQUEST_TEMPLATE.md`) is why a deprecation is an addition
plus a warning rather than a modification. The old symbol keeps working
unchanged until the next major.

## Step 3: why `@internal` is not an escape

C1 exempts `@internal` symbols because `@internal` means no caller outside the
component can reach the symbol, so its user facing deprecation is declared
where the caller actually is. `conventions.yml` cites
`src/Symfony/Bridge/Twig/Extension/HttpKernelRuntime.php:54` and
`HttpKernelExtension.php:30` for that pattern. Adding `@internal` to a public
symbol to dodge the check claims that falsely and silences the warning for
real callers.

## Step 4: related implementations are callers

The first real run of this procedure (`.cursor/labs/lab13/OBSERVED.md`,
Finding 1) deprecated `InputOption::isValueRequired()`. The accessor
`acceptValue()` was built on it and would have emitted the deprecation from
the component's own test suite. The sentence in step 4 about related
implementations exists because of that case. Whether the sentence itself
changes agent behaviour is an open question listed in `DECISIONS.md`.

## Step 5: `#[IgnoreDeprecations]`

The original command text put the attribute at 86 files across `src/Symfony`
(measured 2026-09-11). A plain filename grep is a looser count and returned 93
on 2026-09-21; either way there is no shortage of usages to copy from:

```bash
grep -rl 'IgnoreDeprecations' src/Symfony | wc -l
```

## Step 6: what C2 actually looks for

`bin/check-change.php`, the C2 section, checks two files and, for each, that
it was modified in the diff, that an added line mentions a deprecation, that
the expected heading exists, and that the added lines sit inside that
heading's range. The heading it looks for is `target.branch` in the
component's `CHANGELOG.md` and the component name in `UPGRADE-<branch>.md`.
The requirement itself comes from `.github/PULL_REQUEST_TEMPLATE.md`:
"if yes, also update UPGRADE-*.md and src/**/CHANGELOG.md".

## Step 7: the deprecation assertion

`conventions.yml` C5 records `expectUserDeprecationMessage` at 52 files
against 9 for the legacy `ExpectDeprecationTrait` (measured 2026-09-11), and
cites `src/Symfony/Component/Console/Tests/Input/InputOptionTest.php` as the
exemplar. A plain filename grep on 2026-09-21 returned 59 and 11; the ratio,
not the count, is the point. Recount with:

```bash
grep -rl 'expectUserDeprecationMessage' src/Symfony | wc -l
grep -rl 'ExpectDeprecationTrait' src/Symfony | wc -l
```

## Step 7: why equivalence alone is not enough

Equivalence between the old and new paths passes a replacement implemented by
calling the method it replaces. That replacement then emits a deprecation at
every call site that migrated to it, silently turning "use the replacement"
into "get warned forever". So the second test asserts that the replacement
runs clean.

There is no dedicated assertion for the absence of a deprecation;
`expectUserDeprecationMessage` only asserts one is emitted. The established
shape is a plain test method, not `#[Group('legacy')]` and not
`#[IgnoreDeprecations]`, that calls the replacement and then
`$this->addToAssertionCount(1)`. Existing instances:

- `DotenvTest::testNoDeprecationWarning`
- `ResolveReferencesToAliasesPassTest::testNoDeprecationNoticeWhenReferencedByDeprecatedAlias`
- `ResponseTest::testNoDeprecationsAreTriggered`

The check is environmental rather than an API call:
`SYMFONY_DEPRECATIONS_HELPER=max[self]=0` fails the run if a self-triggered
deprecation surfaces in a test not marked legacy. The CI gate sets exactly
that value (`.github/workflows/convention-gate.yml`, "Component test suite"),
which is why the Finish step sets it locally too.

## The Finish step: why the gate runs twice

`ARTIFACT.md`, "The workflow": the first pass covers C1 to C4 and C5 can only
confirm the named tests exist. The second pass runs with `JUNIT_XML` pointing
at the log `./phpunit --log-junit` wrote, so C5 verifies the tests executed.
Same script, same manifest, same logic locally and in CI.
