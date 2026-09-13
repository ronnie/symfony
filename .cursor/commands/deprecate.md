# /deprecate

Carry out a deprecation described by an existing change record.

Requires a `.change/CHG-<n>.yml` with `change_type: deprecation`. If none
exists, stop and tell the user to run `/plan-change` first.

## Read first

- `conventions.yml`, record C1. It carries the rule, the measured evidence, the
  exceptions, and citations to the files each came from.
- The exemplar cited in C1. Copy its shape rather than inventing one.
- `src/Symfony/Component/<Component>/composer.json` for the package name. The
  first argument to `trigger_deprecation` is that name, never a guess.

## Steps

1. **Add the replacement** alongside the old surface. Never change the old
   signature. Under the backward compatibility promise, a deprecation is an
   addition plus a warning.

2. **Annotate the old symbol.** A `@deprecated` line stating the version and the
   replacement by name.

3. **Emit the runtime deprecation** as the first statement of the deprecated
   method body:

   ```php
   trigger_deprecation('<package from composer.json>', '<target.branch>', '...');
   ```

   Do not mark the symbol `@internal` to avoid this. `@internal` means no caller
   outside the component can reach it, and claiming that falsely removes the
   warning from callers who exist.

4. **Migrate every internal caller.** Search the component for calls to the
   deprecated symbol and rewrite them against the replacement.

   This step is not optional and it is where this change is usually got wrong.
   A component that calls its own deprecated method emits the deprecation during
   its own test suite, and the PHPUnit bridge fails the build. Check the
   implementation of related methods too: an accessor built on the deprecated
   one is a caller even though it does not look like a call site.

5. **Handle existing tests that exercise the old symbol.** They will now emit
   the deprecation and fail the helper. Add `#[IgnoreDeprecations]` to those
   test methods. This attribute appears in 86 files across `src/Symfony`, so
   copy its usage from one of them.

6. **Add the changelog entry** under the target version heading in the
   component's `CHANGELOG.md`.

7. **Write the tests named in the change record**, under exactly the names the
   record gives. Use `expectUserDeprecationMessage` for the deprecation
   assertion, which is the current API at 52 files against 9 for the legacy
   trait. Copy the shape from an existing test rather than from memory.

   A deprecation has two sides, and equivalence between old and new is not
   enough on its own: assert that the deprecated path still emits the notice,
   and separately that the replacement executes without emitting one.
   Equivalence alone passes a replacement implemented by calling the method it
   replaces, which then emits a deprecation at every call site that migrated
   to it, silently turning "use the replacement" into "get warned forever."

   There is no dedicated assertion for the absence of a deprecation.
   `expectUserDeprecationMessage` only asserts one is emitted. The established
   shape, seen in `DotenvTest::testNoDeprecationWarning`,
   `ResolveReferencesToAliasesPassTest::testNoDeprecationNoticeWhenReferencedByDeprecatedAlias`,
   and `ResponseTest::testNoDeprecationsAreTriggered`, is a plain test method,
   not `#[Group('legacy')]` and not `#[IgnoreDeprecations]`, that calls the
   replacement and then `$this->addToAssertionCount(1)`. The check itself is
   environmental rather than an API call: `SYMFONY_DEPRECATIONS_HELPER=max[self]=0`
   fails the run if a self-triggered deprecation surfaces in a test not marked
   legacy, so a replacement that still calls the deprecated method underneath
   fails this test even though it asserts nothing about deprecations directly.

## Constraints

- Do not modify anything outside `target.paths`. C4 fails the change if you do.
- Do not add a dependency to any `composer.json`. That is an architectural
  decision for the component owner, not a step in a scoped change.
- Do not edit the change record to make the gate pass. If the record is wrong,
  say so and ask.

## Finish

```bash
php bin/check-change.php .change/CHG-<n>.yml --format=json
```

Read the `violations` array. Each carries a `fix` field. Apply the fixes and run
again until the status is `pass`. Then run the component's test suite and show
the result.

## If a hook denies an action

A denied read or shell command means the action was outside the boundary
declared in `conventions.yml`, not that the tool is broken. Do not retry the
same action, do not look for another route to the same file, and do not disable
the hook. Say which path was denied and ask whether the boundary should be
widened. Widening it is the user's decision and it belongs in the change record.
