---
name: deprecate
description: >
  Carry out a deprecation declared in an existing change record: add the
  replacement, annotate and trigger the deprecation, migrate internal callers,
  write the changelog, upgrade and test entries the record names, then pass
  the gate. Use when deprecating an option, argument, method, or class in a
  Symfony component, or when implementing a CHG record whose change_type is
  deprecation.
---

# deprecate

Carry out a deprecation described by an existing change record.

## Inputs

The change record: a `.change/CHG-<n>.yml` with `change_type: deprecation`.
Take its path from the conversation, or from the text following `/deprecate`
if it was invoked by name. If no record was named:

```bash
grep -l 'change_type: deprecation' .change/CHG-*.yml
```

Exactly one match: use it. Several: ask which. Default when nobody answers:
the highest numbered one, and say so. None: say that no deprecation record
exists. Default when nobody answers: run the `plan-change` skill first, then
continue here with the record it produced.

Everything else comes from the record: `target.component`, `target.branch`,
`target.paths`, `deprecates`, and the test names under `acceptance`.

## Read first

- `conventions.yml`, record C1. It carries the rule, the measured evidence,
  the exceptions, and citations to the files each came from. It is the single
  source for what the gate checks; do not work from a paraphrase.
- The exemplar cited in C1. Copy its shape rather than inventing one.
- `src/Symfony/Component/<target.component>/composer.json` for the package
  name. The first argument to `trigger_deprecation` is that name, never a
  guess.

## Steps

1. **Add the replacement** alongside the old surface. Never change the old
   signature. Under the backward compatibility promise, a deprecation is an
   addition plus a warning.

2. **Annotate the old symbol.** A `@deprecated` line stating the version and
   the replacement by name.

3. **Emit the runtime deprecation** as the first statement of the deprecated
   method body:

   ```php
   trigger_deprecation('<package from composer.json>', '<target.branch>', '...');
   ```

   Do not mark the symbol `@internal` to avoid this. `@internal` means no
   caller outside the component can reach it, and claiming that falsely
   removes the warning from callers who exist.

4. **Migrate every internal caller.** Search the component for calls to the
   deprecated symbol and rewrite them against the replacement.

   This step is not optional and it is where this change is usually got wrong.
   A component that calls its own deprecated method emits the deprecation
   during its own test suite, and the PHPUnit bridge fails the build. Check
   the implementation of related methods too: an accessor built on the
   deprecated one is a caller even though it does not look like a call site.

5. **Handle existing tests that exercise the old symbol.** They will now emit
   the deprecation and fail the helper. Add `#[IgnoreDeprecations]` to those
   test methods, copying its usage from an existing test under `src/Symfony`
   rather than from memory.

6. **Add the changelog and upgrade entries.** C2 checks both:

   - `src/Symfony/Component/<target.component>/CHANGELOG.md`, an added line
     under the heading named after `target.branch`.
   - `UPGRADE-<target.branch>.md`, an added line under the heading named after
     the component.

   Each added line must mention the deprecation. An entry outside its section
   fails the check, because it reaches the wrong release notes.

7. **Write the tests named in the change record**, under exactly the names the
   record gives. Use `expectUserDeprecationMessage` for the deprecation
   assertion, which is the current API in this repository. Copy the shape from
   an existing test rather than from memory.

   A deprecation has two sides, and equivalence between old and new is not
   enough on its own. Write both:

   - a test that the deprecated path still emits the notice, with
     `expectUserDeprecationMessage`;
   - a separate plain test, not `#[Group('legacy')]` and not
     `#[IgnoreDeprecations]`, that calls the replacement and then
     `$this->addToAssertionCount(1)`. There is no assertion for the absence of
     a deprecation; this test fails under `SYMFONY_DEPRECATIONS_HELPER=max[self]=0`
     if the replacement still emits one.

   Why both, and the existing tests this shape is copied from:
   [references/rationale.md](references/rationale.md).

## Constraints

- Do not modify anything outside `target.paths`. C4 fails the change if you
  do.
- Do not add a dependency to any `composer.json`. That is an architectural
  decision for the component owner, not a step in a scoped change.
- Do not edit the change record to make the gate pass. If the record is wrong,
  say so and ask. Default when nobody answers: leave the record as it is, do
  not work around it, and report the discrepancy against the failing check in
  the Finish step.

## Finish: run the gate, the tests, then the gate again

This mirrors `.github/workflows/convention-gate.yml`, which runs the same
script twice for the same reason.

1. Gate before tests:

   ```bash
   php bin/check-change.php .change/CHG-<n>.yml --format=json
   ```

   If the run cannot find its default base ref (`origin/<target.branch>`),
   pass `--base <ref>` naming the branch the change will merge into.

   Read the JSON. `status: error` means the record failed to load or failed
   schema validation; `detail` lists why. Otherwise `checks[]` carries one
   entry per check, `C1` to `C5`, with a `status` of `pass`, `fail` or `skip`,
   and `violations[]` carries one entry per problem with a `fix` directive.
   Apply each `fix` and rerun until no violation is left that the steps above
   can address.

2. The component's test suite, writing the JUnit log the gate reads. `build/`
   is in `artifact_paths` in `conventions.yml`, so this output does not trip
   C4:

   ```bash
   mkdir -p build
   SYMFONY_DEPRECATIONS_HELPER='max[self]=0' ./phpunit src/Symfony/Component/<target.component> \
     --exclude-group tty --exclude-group benchmark --exclude-group intl-data --exclude-group integration --exclude-group transient \
     --log-junit build/junit.xml
   ```

   Show the result. A failing suite is reported as failing.

3. Gate after tests, so C5 verifies the named tests executed rather than
   merely exist:

   ```bash
   JUNIT_XML=build/junit.xml php bin/check-change.php .change/CHG-<n>.yml --format=json
   ```

Repeat until `status` is `pass`, or report which checks remain failing, by
ID, with the gate's own `message` for each and what would resolve it. C3
reports `skip` locally unless a pull request is open; `skip` is not `pass`
and is reported as skipped. Never describe a failing check as passing.

## If a hook denies an action

A denied read or shell command means the action was outside the boundary
declared in `conventions.yml`, not that the tool is broken. Follow
`.cursor/rules/boundary-recovery.mdc`: say which path was denied, do not
retry, do not route around it, do not disable the hook, and ask whether the
boundary should be widened. Widening it is the user's decision and it belongs
in the change record's `target.paths`. Default when nobody answers: do not
widen it; finish what can be done inside the boundary and report the denial
alongside the gate output.
