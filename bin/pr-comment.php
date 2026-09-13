#!/usr/bin/env php
<?php
/**
 * Render the gate result as one comment with three sections.
 *
 *   php bin/pr-comment.php build/gate.json .change/CHG-0001.yml
 *
 * One comment updated in place rather than three. Three would duplicate on
 * every rerun unless each were tracked separately, and the per role
 * subscription people imagine as the benefit does not exist on GitHub.
 *
 * The audiences are not decoration. Product needs to know what changes for
 * consumers and when. QA needs to know which tests prove which claim. DevOps
 * needs the branch, the dependency delta and the gate result. All three read
 * the same change record the engineer wrote, which is the point.
 */

require __DIR__ . '/lib/record.php';

[$gatePath, $recordPath] = [$argv[1] ?? '', $argv[2] ?? ''];
if (!is_file($gatePath) || !is_file($recordPath)) {
    fwrite(STDERR, "usage: pr-comment.php <gate.json> <change-record.yml>\n");
    exit(2);
}

$gate = json_decode((string) file_get_contents($gatePath), true) ?: [];
$record = record_load($recordPath);

$icon = fn(string $s) => ['pass' => 'passed', 'fail' => 'FAILED',
                          'skip' => 'skipped', 'todo' => 'todo'][$s] ?? $s;
$statusEmoji = fn(string $s) => ['pass' => '✅', 'fail' => '❌'][$s] ?? $icon($s);

$by = [];
foreach ($gate['checks'] ?? [] as $c) {
    $by[$c['id']] = $c;
}
$failed = array_filter($gate['checks'] ?? [], fn($c) => $c['status'] === 'fail');

echo "<!-- change-record:" . ($record['id'] ?? 'unknown') . " -->\n";
printf("## %s  %s  %s  targets %s\n\n",
    $record['id'] ?? '?', $record['change_type'] ?? '?',
    $record['target']['component'] ?? '?', $record['target']['branch'] ?? '?');

printf("**Gate: %s** (%d of %d checks passing)\n\n",
    ($gate['status'] ?? 'unknown') === 'pass' ? '✅' : '❌',
    count(array_filter($gate['checks'] ?? [], fn($c) => $c['status'] === 'pass')),
    count($gate['checks'] ?? []));

// ------------------------------------------------------------------ product
echo "### Product\n\n";
if (!empty($record['release_note'])) {
    echo trim($record['release_note']) . "\n\n";
}
if (!empty($record['deprecates'])) {
    printf("Deprecates `%s` in favour of `%s`, effective %s. Existing callers keep working until the next major and receive a runtime notice.\n\n",
        $record['deprecates']['symbol'], $record['deprecates']['replacement'],
        $record['deprecates']['since']);
}

// ----------------------------------------------------------------------- qa
echo "### QA\n\n";
foreach ($record['acceptance'] ?? [] as $ac) {
    printf("- %s %s\n  `%s`\n", $ac['id'] ?? '?', $ac['statement'] ?? '', $ac['test'] ?? '');
}
$c5 = $by['C5'] ?? null;
if ($c5) {
    printf("\nTest linkage: **%s**. %s\n\n", $icon($c5['status']), $c5['detail'] ?? '');
}

// ------------------------------------------------------------------- devops
echo "### DevOps\n\n";
printf("- Target branch `%s`, declared in the record and checked against the pull request base\n",
    $record['target']['branch'] ?? '?');
printf("- Paths `%s`\n", implode('`, `', $record['target']['paths'] ?? []));
printf("- Owner %s, resolved from CODEOWNERS\n",
    implode(' ', $gate['owner'] ?? []) ?: '(none)');
foreach (['C3' => 'Branch', 'C4' => 'Boundary and dependencies'] as $id => $label) {
    if (isset($by[$id])) {
        printf("- %s: **%s**. %s\n", $label, $icon($by[$id]['status']), $by[$id]['detail'] ?? '');
    }
}

// ------------------------------------------------------------------ checks
echo "\n### Checks\n\n";
echo "| Check | Title | Status | Detail |\n";
echo "| --- | --- | --- | --- |\n";
foreach ($gate['checks'] ?? [] as $c) {
    printf("| %s | %s | %s | %s |\n", $c['id'], $c['title'],
        $statusEmoji($c['status']), $c['detail'] ?? '');
}

// --------------------------------------------------------------- violations
if ($gate['violations'] ?? []) {
    echo "\n### Violations\n\n";
    foreach ($gate['violations'] as $v) {
        printf("**%s** `%s%s`\n%s\n_%s_\n\n", $v['check'], $v['path'],
            $v['line'] ? ':' . $v['line'] : '', $v['message'], $v['fix']);
    }
}

if (($by['C1']['skipped'] ?? []) !== []) {
    echo "\n<details><summary>What the gate did not check</summary>\n\n";
    foreach ($by['C1']['skipped'] as $s) {
        echo "- $s\n";
    }
    echo "\n</details>\n";
}
