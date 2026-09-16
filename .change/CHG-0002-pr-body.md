| Q             | A
| ------------- | ---
| Branch?       | 8.2
| Bug fix?      | no
| New feature?  | no
| Deprecations? | yes
| Issues        | -
| License       | MIT

Finding out how a console option takes its value means knowing which of five yes-or-no questions to ask, and two of those questions are halves of the same thing. Callers get one method that returns the mode, and a warning if they still ask the old required-value question.

### What changes

`Symfony\Component\Console\Input\InputOption::isValueRequired()` is deprecated in 8.2. Use `Symfony\Component\Console\Input\InputOption::valueMode()` instead.

Existing callers keep working and receive a runtime deprecation notice. Removal is a future major.

### Verified

- A1 Calling isValueRequired() emits a deprecation naming valueMode()
  `Symfony\Component\Console\Tests\Input\InputOptionTest::testIsValueRequiredEmitsDeprecationNamingValueMode`
- A2 valueMode() returns the same answer isValueRequired() did
  `Symfony\Component\Console\Tests\Input\InputOptionTest::testValueModeMatchesIsValueRequired`
- A3 valueMode() executes without emitting a deprecation
  `Symfony\Component\Console\Tests\Input\InputOptionTest::testValueModeDoesNotEmitDeprecation`

### Checks run

- `php bin/check-change.php .change/CHG-0002.yml`, all assertions passing
- `./phpunit src/Symfony/Component/Console`
- Scope limited to `src/Symfony/Component/Console/**`, `UPGRADE-8.2.md`, no dependency changes

Change record: `.change/CHG-0002.yml`. Owner `@chalasr`, resolved from `.github/CODEOWNERS`.
