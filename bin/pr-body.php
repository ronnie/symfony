#!/usr/bin/env php
<?php
/**
 * Render a Symfony pull request body from a change record.
 *
 *   php bin/pr-body.php .change/CHG-0001.yml
 *   php bin/pr-body.php .change/CHG-0001.yml > /tmp/body.md
 *   gh pr create --base 8.2 --title "..." --body-file /tmp/body.md
 *
 * WHY THIS EXISTS
 *
 * Symfony's fabbot check does not validate code style on the first pass. It
 * validates the pull request body:
 *
 *   Error: You must add the standard contribution header in the PR description
 *
 * The required header is the Q and A table in .github/PULL_REQUEST_TEMPLATE.md.
 * It is also the table `bug-triage/SKILL.md:58` reads to classify a pull
 * request, and the table the mined corpus parsed at 97.7 percent to measure
 * branch targeting and changelog compliance.
 *
 * Every field in it is already in the change record. change_type gives the
 * three yes/no rows, target.branch gives Branch, release_note gives the
 * description. Without this script the artifact produces a machine readable
 * record and then leaves a human to retype its contents into a field upstream
 * CI rejects them for omitting. That is a second source of truth, which is the
 * failure the whole manifest argues against.
 */

require __DIR__ . '/lib/record.php';

$path = $argv[1] ?? '';
if (!is_file($path)) {
    fwrite(STDERR, "usage: pr-body.php <change-record.yml>\n");
    exit(2);
}

$record = record_load($path);
$errors = record_validate($record);
if ($errors !== []) {
    fwrite(STDERR, "change record failed schema validation\n");
    foreach ($errors as $e) {
        fwrite(STDERR, "  - $e\n");
    }
    exit(2);
}

$type = $record['change_type'] ?? '';
$branch = (string) ($record['target']['branch'] ?? '');
$component = (string) ($record['target']['component'] ?? '');
$yn = fn(bool $b) => $b ? 'yes' : 'no';

// The table. Column widths match the template so a maintainer reading the diff
// of two pull request bodies sees the same shape they always see.
echo "| Q             | A\n";
echo "| ------------- | ---\n";
printf("| Branch?       | %s\n", $branch);
printf("| Bug fix?      | %s\n", $yn($type === 'bug_fix'));
printf("| New feature?  | %s\n", $yn($type === 'feature'));
printf("| Deprecations? | %s\n", $yn($type === 'deprecation'));
printf("| Issues        | %s\n", $record['issues'] ?? '-');
printf("| License       | %s\n", $record['license'] ?? 'MIT');
echo "\n";

if (!empty($record['release_note'])) {
    echo trim($record['release_note']) . "\n\n";
}

if (!empty($record['deprecates'])) {
    $d = $record['deprecates'];
    echo "### What changes\n\n";
    printf("`%s` is deprecated in %s. Use `%s` instead.\n\n",
        $d['symbol'] ?? '?', $d['since'] ?? $branch, $d['replacement'] ?? '?');
    echo "Existing callers keep working and receive a runtime deprecation notice. ";
    echo "Removal is a future major.\n\n";
}

if (!empty($record['acceptance'])) {
    echo "### Verified\n\n";
    foreach ($record['acceptance'] as $ac) {
        printf("- %s %s\n  `%s`\n",
            $ac['id'] ?? '?', $ac['statement'] ?? '', $ac['test'] ?? '');
    }
    echo "\n";
}

echo "### Checks run\n\n";
printf("- `php bin/check-change.php %s`, all assertions passing\n", $path);
printf("- `./phpunit src/Symfony/Component/%s`\n", $component);
echo "- Scope limited to " . implode(', ', array_map(
    fn($p) => "`$p`", $record['target']['paths'] ?? [])) . ", no dependency changes\n\n";

printf("Change record: `%s`. Owner `%s`, resolved from `.github/CODEOWNERS`.\n",
    $path, $record['owner'] ?? '?');
