# Lab 13. The first real `/deprecate` run

**Date:** 2026-09-12
**Cursor version:** 3.20.17
**Model:** Cursor Grok 4.6
**Branch:** `change/CHG-0001-input-option`
**Change record:** `.change/CHG-0001.yml`

## Question

Labs up to this point tested the boundary and the gate against instructions
written to probe them: renames, moved changelog entries, deliberate breaks.
This is the first run of `/deprecate` against a real change record, with the
full configuration loaded and nothing staged to fail. Two things to find out:
does the command text in `.cursor/commands/deprecate.md` cause the four
behaviours it was written to cause, and what does the agent do that the
command text never asked for.

## Setup

| Thing | State |
| --- | --- |
| `.cursor/hooks.json` | present, loaded |
| `.cursor/rules/boundary-recovery.mdc` | present |
| `.cursor/commands/deprecate.md` | present, invoked as `/deprecate` |
| `conventions.yml` | present, `boundary.read_allow` includes `.agents/**` as of commit `ac9983533fa` (2026-09-12, same day) |
| `.change/CHG-0001.yml` | present, `change_type: deprecation`, names one symbol: `InputOption::isValueRequired()` |
| `.agents/skills/pr-authoring/SKILL.md` | present, readable under the boundary added earlier the same day |

## The run

**Prompt and response, verbatim.** Pasted as received, not reconstructed.

<!-- PASTE THE VERBATIM TRANSCRIPT BELOW THIS LINE. -->

```
[transcript pending]
```

<!-- PASTE ABOVE THIS LINE. -->

## Finding 1. `acceptValue()` was rewritten, not left calling the deprecated method

The command text says to add the replacement alongside the old surface and
never change the old signature. It says nothing about callers of the old
method within the same class, other than the general instruction to migrate
internal callers. `acceptValue()` is such a caller, and the agent rewrote it
against the new accessor's return type rather than leaving it calling
`isValueRequired()`:

```
$ git show 0259cc4f9a8 -- src/Symfony/Component/Console/Input/InputOption.php
```

```diff
     public function acceptValue(): bool
     {
-        return $this->isValueRequired() || $this->isValueOptional();
+        return self::VALUE_NONE !== $this->valueMode();
     }
```

This is correct: `isValueRequired()` now itself triggers the deprecation, so a
version that kept calling it would emit a deprecation notice from
`InputOption`'s own test suite, which is exactly the failure mode
`.cursor/commands/deprecate.md` step 4 warns about ("Check the implementation
of related methods too: an accessor built on the deprecated one is a caller
even though it does not look like a call site."). The agent applied that
warning rather than being told the specific line.

## Finding 2. `equals()` collapsed two comparisons into one, which the record never asked for

The same commit changes `equals()`:

```diff
             && $option->isValueRequired() === $this->isValueRequired()
-            && $option->isValueOptional() === $this->isValueOptional()
+            && $option->valueMode() === $this->valueMode()
```

Both the `isValueRequired()` comparison and the separate `isValueOptional()`
comparison were replaced by one `valueMode()` comparison. `.change/CHG-0001.yml`
names exactly one symbol as deprecated, `isValueRequired()`, and says nothing
about `isValueOptional()` or about `equals()`. The change is defensible on its
own terms, since the two original comparisons were redundant once `valueMode()`
exists, but it is a second internal caller migrated to the new accessor beyond
what the record's `deprecates` block describes, decided by the agent rather
than named in the change record.

## Finding 3. The new test matches the shape of the existing 8.1 deprecation test

```
$ git grep -n "Group('legacy')\|IgnoreDeprecations\|testIsValueRequiredIsDeprecated\|testCombiningInvalidModesIsDeprecated" change/CHG-0001-input-option -- src/Symfony/Component/Console/Tests/Input/InputOptionTest.php
```

```
134:    #[Group('legacy')]
135:    #[IgnoreDeprecations]
136:    public function testIsValueRequiredIsDeprecated()
...
184:    #[Group('legacy')]
185:    #[IgnoreDeprecations]
186:    public function testCombiningInvalidModesIsDeprecated(int $mode)
```

`testCombiningInvalidModesIsDeprecated` is the existing 8.1 deprecation test
already in this file. The new `testIsValueRequiredIsDeprecated` carries the
identical attribute pair, `#[Group('legacy')]` and `#[IgnoreDeprecations]`, in
the same order. `.cursor/commands/deprecate.md` step 7 says to copy the shape
from an existing test rather than from memory; this file had one to copy from,
and the new test's attributes match it exactly.

## Finding 4. Tests were written first, and the command text does not say to

`.cursor/commands/deprecate.md` step 7, "Write the tests named in the change
record," comes after steps 1 through 4 (add the replacement, annotate the old
symbol, emit the deprecation, migrate internal callers). It does not specify
that tests must be written and run red before those steps, only that they get
written at some point using the names the record gives.

The ordering the agent used, tests first, run red, then implementation, comes
from `.agents/skills/pr-authoring/SKILL.md`, line 56:

```
Use TDD: the failing test comes first, the implementation second, the full
suite of the touched component last.
```

The agent could read that file only because `.agents/**` was added to
`conventions.yml`'s `boundary.read_allow` in commit `ac9983533fa`, dated
2026-09-12, the same day as this run. Before that commit, this ordering
constraint was outside the boundary and unavailable to the agent regardless of
whether it exists in the repository.

## Finding 5. `isValueOptional()` was left untouched, with live callers

The change record's `deprecates` block names one symbol:
`InputOption::isValueRequired()`. `isValueOptional()` is not mentioned anywhere
in `.change/CHG-0001.yml`, and it was not deprecated, annotated, or touched
except for the two comparisons folded into `equals()` in Finding 2.

```
$ git grep -n -- "->isValueOptional(" change/CHG-0001-input-option -- src/Symfony/Component/Console
```

Six calls outside the test suite survive: two in `TextDescriptor.php`, one in
`ArgvInput.php`, one in `ArrayInput.php`, and two in `InputDefinition.php`. A
further nine calls exist inside `InputOptionTest.php` and
`InvokableCommandTest.php`.

The agent followed the record exactly: it deprecated the one symbol named and
left the other alone, which is the correct response to a change record that
names one method. The defect is that `isValueOptional()` sits in the same
accessor pair as `isValueRequired()`, was originally read alongside it in
`acceptValue()` and `equals()` before Finding 1 and Finding 2 rewrote those
methods, and no check in the gate can flag it, because the gate enforces what
the record declares rather than reviewing whether the record declared the
right thing. A record that names half of a pair produces a change that is
internally consistent and incomplete, and nothing in this pipeline catches
half a pair.

## What this does not establish

- That `/deprecate` always produces these four behaviours, or that a different
  run would rewrite `acceptValue()` and `equals()` the same way. One run, one
  model. Agent behaviour is nondeterministic; the reproducible parts are the
  committed diff and the gate result, not the prose that produced them.
- That the command text is deficient for not spelling out caller migration or
  test ordering. Step 4 already warns about accessors built on the deprecated
  method in general terms, and the ordering came from a skill file the agent
  could read, not from an absent instruction the agent had to invent.
- That Finding 5 is a defect the agent could have prevented. `/plan-change`
  writes the record before `/deprecate` ever runs; by the time `/deprecate`
  reads `.change/CHG-0001.yml`, which method the deprecation covers is already
  decided. This is the honest limit of the whole design: the gate and the
  command both enforce a record neither of them checks for completeness.
- Anything about other models. This run, like labs 10 through 12, is Cursor
  Grok 4.6. Whether Opus 5 or GPT-5.6 would rewrite `acceptValue()` and
  `equals()` the same way, or would ask before doing so, is untested.
