| Q             | A
| ------------- | ---
| Branch?       | 8.2
| Bug fix?      | no
| New feature?  | no
| Deprecations? | yes
| Issues        | -
| License       | MIT

A caller who wants to know what mode a console option is in has to know which of five boolean methods answers that question, and two of them are halves of the same question. Symfony 8.1 already started tightening ambiguous mode values. This continues that work by returning the mode itself instead of asking one yes-or-no question about it.

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
