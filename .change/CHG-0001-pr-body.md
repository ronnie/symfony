| Q             | A
| ------------- | ---
| Branch?       | 8.2
| Bug fix?      | no
| New feature?  | no
| Deprecations? | yes
| Issues        | -
| License       | MIT

Command options currently expose five separate yes-or-no methods for a single mode value, so a caller has to know which of the five answers the question, and two of them ask halves of the same one. Symfony 8.1 already deprecates ambiguous mode combinations. valueMode() returns the mode instead, and isValueRequired() is deprecated in favour of it.

### What changes

`Symfony\Component\Console\Input\InputOption::isValueRequired()` is deprecated in 8.2. Use `Symfony\Component\Console\Input\InputOption::valueMode()` instead.

Existing callers keep working and receive a runtime deprecation notice. Removal is a future major.

### Verified

- A1 Calling isValueRequired() emits a deprecation naming valueMode()
  `Symfony\Component\Console\Tests\Input\InputOptionTest::testIsValueRequiredIsDeprecated`
- A2 valueMode() returns the same answer isValueRequired() did
  `Symfony\Component\Console\Tests\Input\InputOptionTest::testValueModeMatchesIsValueRequired`
- A3 valueMode() executes without emitting a deprecation
  `Symfony\Component\Console\Tests\Input\InputOptionTest::testValueModeDoesNotTriggerDeprecation`

### Checks run

- `php bin/check-change.php .change/CHG-0001.yml`, all assertions passing
- `./phpunit src/Symfony/Component/Console`
- Scope limited to `src/Symfony/Component/Console/**`, `UPGRADE-8.2.md`, no dependency changes

Change record: `.change/CHG-0001.yml`. Owner `@chalasr`, resolved from `.github/CODEOWNERS`.
