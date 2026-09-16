| Q             | A
| ------------- | ---
| Branch?       | 8.2
| Bug fix?      | no
| New feature?  | no
| Deprecations? | yes
| Issues        | -
| License       | MIT

Callers who need to know how a Console option takes its value currently have to pick among five yes/no methods, two of which answer halves of the same question. 8.1 already started tightening option-mode semantics. This change adds one method that returns the mode, and warns on the old required-value check so existing callers keep working.

### What changes

`Symfony\Component\Console\Input\InputOption::isValueRequired()` is deprecated in 8.2. Use `Symfony\Component\Console\Input\InputOption::valueMode()` instead.

Existing callers keep working and receive a runtime deprecation notice. Removal is a future major.

### Verified

- AC1 Calling isValueRequired() emits a deprecation naming valueMode()
  `Symfony\Component\Console\Tests\Input\InputOptionTest::testIsValueRequiredIsDeprecated`
- AC2 valueMode() returns the same answer isValueRequired() did
  `Symfony\Component\Console\Tests\Input\InputOptionTest::testValueModeMatchesLegacyAccessor`
- AC3 valueMode() executes without emitting a deprecation
  `Symfony\Component\Console\Tests\Input\InputOptionTest::testValueModeEmitsNoDeprecation`

### Checks run

- `php bin/check-change.php .change/CHG-0001.yml`, all assertions passing
- `./phpunit src/Symfony/Component/Console`
- Scope limited to `src/Symfony/Component/Console/**`, `UPGRADE-8.2.md`, no dependency changes

Change record: `.change/CHG-0001.yml`. Owner `@chalasr`, resolved from `.github/CODEOWNERS`.
