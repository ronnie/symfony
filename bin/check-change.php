#!/usr/bin/env php
<?php
/**
 * Convention gate. One script, three consumers: the engineer at a prompt, the
 * agent reading tool output, and GitHub Actions.
 *
 *   php bin/check-change.php .change/CHG-0001.yml
 *   php bin/check-change.php .change/CHG-0001.yml --format=json
 *   php bin/check-change.php .change/CHG-0001.yml --base=origin/8.2
 *
 * Exit codes: 0 all checks passed, 1 a check failed, 2 usage or schema error.
 *
 * The JSON format exists because hooks cannot deliver a payload to the agent:
 * afterFileEdit output is logged and dropped (measured, lab 8). Tool output is
 * the channel that works, so the agent runs this script and reads stdout.
 */

require __DIR__ . '/lib/tokens.php';
require __DIR__ . '/lib/codeowners.php';
require __DIR__ . '/lib/record.php';
require __DIR__ . '/lib/git.php';

$args = array_slice($argv, 1);
$recordPath = null;
$format = 'human';
$base = null;

foreach ($args as $arg) {
    if (str_starts_with($arg, '--format=')) { $format = substr($arg, 9); continue; }
    if (str_starts_with($arg, '--base=')) { $base = substr($arg, 7); continue; }
    if (str_starts_with($arg, '--')) { continue; }
    $recordPath = $arg;
}

if ($recordPath === null) {
    fwrite(STDERR, "usage: check-change.php <change-record.yml> [--format=json] [--base=ref]\n");
    exit(2);
}

try {
    $reader = null;
    $record = record_load($recordPath, $reader);
} catch (Throwable $e) {
    emit_fatal($format, $e->getMessage());
    exit(2);
}

$schemaErrors = record_validate($record);
if ($schemaErrors !== []) {
    emit_fatal($format, 'change record failed schema validation', $schemaErrors);
    exit(2);
}

$manifestRecord = [];
if (is_file('conventions.yml')) {
    try { $manifestRecord = record_load('conventions.yml'); } catch (Throwable) {}
}

$component = $record['target']['component'];
$declaredBranch = (string) $record['target']['branch'];
$paths = $record['target']['paths'];
$base ??= 'origin/' . $declaredBranch;
$componentRoot = 'src/Symfony/Component/' . $component;

$results = [];
$violations = [];

// ---------------------------------------------------------------- ownership
$rules = codeowners_load('.github/CODEOWNERS');
$owner = codeowners_for($rules, $componentRoot . '/');

// ------------------------------------------------------------------ changed
$changed = git_changed_files($base);
$phpFiles = array_values(array_filter(
    $changed,
    fn($f) => str_ends_with($f, '.php')
        && path_matches_any($f, $paths)
        && !str_contains($f, '/Tests/')
        && is_file($f)
));

// ----------------------------------------------------------------------- C1
//
// Three assertions, all derived from measurement across 6189 files in
// src/Symfony on branch 8.2:
//
//   A1 pairing   15 of 15 methods annotated @deprecated, excluding @internal,
//                call trigger_deprecation in their own body. The single
//                exception found was HttpKernelRuntime::renderHIncludeFragment,
//                which is @internal because its user facing deprecation is
//                declared at the Twig function boundary via
//                DeprecatedCallableInfo('symfony/twig-bridge', '8.2', ...).
//                The rule is that a deprecation is declared where the caller
//                is, and @internal marks a symbol with no such caller.
//
//   A2 package   The first argument is the owning component's composer name.
//                Measured at 119 of 137 across the monorepo. Every mismatch
//                read was systematic rather than accidental: test fixtures, and
//                bridges or bundles deprecating on behalf of a wrapped package.
//                Inside a single component none of those cases arise, which is
//                why this runs against the diff rather than the tree.
//
//   A3 version   The second argument is the version declared in the change
//                record. Contracts packages version independently of Symfony
//                branches, so this compares against the record and never
//                against a branch number.
//
// An empty package and version pair is legitimate and skipped: Symfony uses it
// when reporting a deprecation about third party code it does not own, as in
// ContainerBuilder when a user service depends on a deprecated class.

$expectedPackage = 'symfony/' . strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $component));
$componentComposer = $componentRoot . '/composer.json';
if (is_file($componentComposer)) {
    $meta = json_decode((string) file_get_contents($componentComposer), true);
    if (is_array($meta) && !empty($meta['name'])) {
        $expectedPackage = $meta['name'];
    }
}

$checkable = 0;
$skipped = [];
$paired = 0;

foreach ($phpFiles as $file) {
    $added = git_added_lines($base, $file);
    if ($added === []) {
        continue;
    }
    $addedSet = array_flip($added);

    $entries = scan_deprecations(file_get_contents($file));
    foreach ($entries as $e) {
        $isNew = false;
        for ($line = $e['docLine']; $line <= $e['docEndLine']; $line++) {
            if (isset($addedSet[$line])) { $isNew = true; break; }
        }
        if (!$isNew) {
            continue;
        }
        if (!$e['checkable']) {
            $skipped[] = sprintf('%s:%d %s (%s)', $file, $e['docLine'], $e['kind'], $e['reason']);
            continue;
        }
        if (!empty($e['internal'])) {
            $skipped[] = sprintf(
                '%s:%d %s %s() (@internal, deprecation belongs at the caller boundary)',
                $file, $e['docLine'], $e['kind'], $e['name']
            );
            continue;
        }

        $checkable++;

        if (!$e['hasTrigger']) {
            $violations[] = [
                'check'   => 'C1.pairing',
                'path'    => $file,
                'line'    => $e['docLine'],
                'message' => sprintf(
                    '%s %s() is annotated @deprecated but its body never calls trigger_deprecation().',
                    $e['kind'], $e['name']
                ),
                'fix' => sprintf(
                    "Add trigger_deprecation('%s', '%s', '...') as the first statement of %s(), or mark the symbol @internal if no caller outside the component can reach it.",
                    $expectedPackage, $declaredBranch, $e['name']
                ),
            ];
            continue;
        }

        $paired++;
        $pkg = $e['package'];
        $ver = $e['version'];

        if ($pkg === '' && ($ver === '' || $ver === null)) {
            $skipped[] = sprintf(
                '%s:%d %s() empty package and version (deprecation raised on behalf of third party code)',
                $file, $e['docLine'], $e['name']
            );
            continue;
        }

        if ($pkg === null) {
            $skipped[] = sprintf('%s:%d %s() package argument is not a string literal, unverifiable',
                $file, $e['docLine'], $e['name']);
        } elseif ($pkg !== $expectedPackage) {
            $violations[] = [
                'check'   => 'C1.package',
                'path'    => $file,
                'line'    => $e['docLine'],
                'message' => sprintf('trigger_deprecation names package "%s" in a change scoped to %s.', $pkg, $expectedPackage),
                'fix'     => sprintf('Change the first argument to \'%s\'. A wrong package string is the usual result of copying a deprecation from another component.', $expectedPackage),
            ];
        }

        if ($ver === null) {
            $skipped[] = sprintf('%s:%d %s() version argument is not a string literal, unverifiable',
                $file, $e['docLine'], $e['name']);
        } elseif ($ver !== $declaredBranch) {
            $violations[] = [
                'check'   => 'C1.version',
                'path'    => $file,
                'line'    => $e['docLine'],
                'message' => sprintf('trigger_deprecation says version "%s", change record declares "%s".', $ver, $declaredBranch),
                'fix'     => sprintf('Change the second argument to \'%s\', or correct target.branch in the change record.', $declaredBranch),
            ];
        }
    }
}

if (($record['change_type'] ?? null) === 'deprecation' && $checkable === 0 && $skipped === []) {
    $violations[] = [
        'check'   => 'C1.declared',
        'path'    => $recordPath,
        'line'    => null,
        'message' => 'The change record declares a deprecation and the diff contains none.',
        'fix'     => 'Add the @deprecated annotation and the runtime call, or change change_type. A record that promises work nobody did is the same failure as a convention nobody enforces.',
    ];
}

$c1Failed = count(array_filter($violations, fn($v) => str_starts_with($v['check'], 'C1')));
$results[] = [
    'id'     => 'C1',
    'title'  => 'deprecation pairing',
    'status' => $c1Failed === 0 ? 'pass' : 'fail',
    'detail' => sprintf(
        '%d checked, %d paired, %d skipped. package expected %s, version expected %s',
        $checkable, $paired, count($skipped), $expectedPackage, $declaredBranch
    ),
    'skipped' => $skipped,
];

// ----------------------------------------------------------------------- C2
$changelog = $componentRoot . '/CHANGELOG.md';
$declaresDeprecation = ($record['change_type'] ?? null) === 'deprecation' || $checkable > 0;

if (!$declaresDeprecation) {
    $results[] = ['id' => 'C2', 'title' => 'changelog entry', 'status' => 'skip',
                  'detail' => 'no deprecation in this change'];
} elseif (!in_array($changelog, $changed, true)) {
    $violations[] = [
        'check' => 'C2', 'path' => $changelog, 'line' => null,
        'message' => 'This change introduces a deprecation and the component changelog was not modified.',
        'fix' => sprintf('Add an entry under the %s heading in %s.', $declaredBranch, $changelog),
    ];
    $results[] = ['id' => 'C2', 'title' => 'changelog entry', 'status' => 'fail',
                  'detail' => 'changelog not touched'];
} else {
    $addedText = git_added_text($base, $changelog);
    $mentions = array_filter($addedText, fn($l) => preg_match('/deprecat/i', $l));
    $headings = [];
    if (is_file($changelog)) {
        foreach (file($changelog, FILE_IGNORE_NEW_LINES) as $l) {
            if (preg_match('/^\s*#{1,3}\s*(.+)$/', $l, $m)) { $headings[] = trim($m[1]); }
        }
    }
    $hasHeading = in_array($declaredBranch, $headings, true);

    if ($mentions === []) {
        $violations[] = [
            'check' => 'C2', 'path' => $changelog, 'line' => null,
            'message' => 'The changelog was modified but no added line mentions a deprecation.',
            'fix' => sprintf('Describe the deprecation under the %s heading.', $declaredBranch),
        ];
        $results[] = ['id' => 'C2', 'title' => 'changelog entry', 'status' => 'fail',
                      'detail' => 'no deprecation wording in added lines'];
    } else {
        $results[] = [
            'id' => 'C2', 'title' => 'changelog entry', 'status' => 'pass',
            'detail' => sprintf(
                '%d added line%s mention a deprecation. Heading "%s" %s. VERIFY heading format: found [%s]',
                count($mentions), count($mentions) === 1 ? '' : 's',
                $declaredBranch,
                $hasHeading ? 'present' : 'NOT found',
                implode(', ', array_slice($headings, 0, 4))
            ),
        ];
    }
}

// ----------------------------------------------------------------------- C3
//
// Self consistency only. The declared branch must equal the base branch. This
// deliberately does NOT derive the maintained branch set, because that set is
// not derivable: 5.4 receives security fixes until February 2029 under a
// sponsorship agreement, while the project's own release page lists support as
// ended in November 2025. Fifty of three hundred merged pull requests in the
// mined corpus target 5.4 and every one is correct. A check that encoded the
// documented policy would have failed all fifty.

$actualBase = getenv('GITHUB_BASE_REF') ?: null;
if ($actualBase === null) {
    $upstream = git('rev-parse --abbrev-ref --symbolic-full-name @{u}');
    $actualBase = $upstream ? preg_replace('#^[^/]+/#', '', $upstream[0]) : null;
}

if ($actualBase === null) {
    $results[] = ['id' => 'C3', 'title' => 'declared branch', 'status' => 'skip',
                  'detail' => 'no base branch available outside a pull request'];
} elseif ($actualBase !== $declaredBranch) {
    $violations[] = [
        'check' => 'C3', 'path' => $recordPath, 'line' => null,
        'message' => sprintf('Change record declares branch "%s", pull request targets "%s".',
            $declaredBranch, $actualBase),
        'fix' => 'Retarget the pull request, or correct target.branch. Do not assume the development branch is always right: a bug fix belongs on the oldest maintained branch containing it, and that set is not machine derivable.',
    ];
    $results[] = ['id' => 'C3', 'title' => 'declared branch', 'status' => 'fail',
                  'detail' => sprintf('declared %s, actual %s', $declaredBranch, $actualBase)];
} else {
    $results[] = ['id' => 'C3', 'title' => 'declared branch', 'status' => 'pass',
                  'detail' => sprintf('declared and actual both %s', $declaredBranch)];
}

// ----------------------------------------------------------------------- C4
//
// Two assertions. The diff stays inside declared paths, and no composer
// manifest gains a require entry. The second is what stops an agent solving a
// problem by reaching for a new dependency, which is the cheapest way to turn a
// scoped change into a supply chain decision nobody reviewed.

$artifactPaths = $manifestRecord['artifact_paths'] ?? [];
$outside = [];
$artifactTouched = [];
foreach ($changed as $file) {
    if ($file === $recordPath) {
        continue;
    }
    if ($artifactPaths !== [] && path_matches_any($file, $artifactPaths)) {
        $artifactTouched[] = $file;
        continue;
    }
    if (!path_matches_any($file, $paths)) {
        $outside[] = $file;
    }
}

$newRequires = [];
foreach ($changed as $file) {
    if (!str_ends_with($file, 'composer.json')) {
        continue;
    }
    foreach (git_added_text($base, $file) as $line) {
        if (preg_match('/^\s*"([a-z0-9_.\/-]+)"\s*:\s*"[^"]*"\s*,?\s*$/i', $line, $m)
            && str_contains($m[1], '/')) {
            $newRequires[] = $file . ': ' . $m[1];
        }
    }
}

if ($outside === [] && $newRequires === []) {
    $results[] = ['id' => 'C4', 'title' => 'boundary', 'status' => 'pass',
                  'detail' => sprintf('%d changed file%s inside declared paths, no new dependency, %d artifact file%s skipped',
                      count($changed) - count($artifactTouched),
                      count($changed) - count($artifactTouched) === 1 ? '' : 's',
                      count($artifactTouched),
                      count($artifactTouched) === 1 ? '' : 's')];
} else {
    foreach ($outside as $file) {
        $violations[] = [
            'check' => 'C4', 'path' => $file, 'line' => null,
            'message' => 'Modified outside the paths declared in the change record.',
            'fix' => sprintf('Revert this file, or widen target.paths and say why. Declared: %s',
                implode(', ', $paths)),
        ];
    }
    foreach ($newRequires as $req) {
        $violations[] = [
            'check' => 'C4', 'path' => explode(':', $req)[0], 'line' => null,
            'message' => 'A dependency was added to a composer manifest.',
            'fix' => 'Remove it. Adding a dependency is an architectural decision for the component owner, not a step in a scoped change. ' . $req,
        ];
    }
    $results[] = ['id' => 'C4', 'title' => 'boundary', 'status' => 'fail',
                  'detail' => sprintf('%d outside declared paths, %d new dependencies, %d artifact file%s skipped',
                      count($outside), count($newRequires), count($artifactTouched),
                      count($artifactTouched) === 1 ? '' : 's')];
}

// ----------------------------------------------------------------------- C5
//
// The multi audience keystone. Every acceptance criterion names a test, that
// test must exist, and in CI it must also have executed. Without this the
// change record is a promise nobody checks, which is the same failure mode as
// a convention living only in prose.

$junit = getenv('JUNIT_XML') ?: 'build/junit.xml';
$executed = null;
if (is_file($junit)) {
    $xml = @simplexml_load_file($junit);
    if ($xml !== false) {
        $executed = [];
        foreach ($xml->xpath('//testcase') ?: [] as $case) {
            $executed[] = ((string) $case['class']) . '::' . ((string) $case['name']);
            $executed[] = (string) $case['name'];
        }
    }
}

$missing = [];
$notRun = [];
foreach ($record['acceptance'] as $ac) {
    $ref = (string) ($ac['test'] ?? '');
    [$class, $method] = array_pad(explode('::', $ref, 2), 2, '');
    $relative = 'src/' . strtr(trim($class, '\\'), ['\\' => '/']) . '.php';
    $relative = preg_replace('#/+#', '/', $relative);

    $found = is_file($relative)
        && ($method === '' || str_contains((string) file_get_contents($relative), $method));

    if (!$found) {
        $missing[] = sprintf('%s names %s, which was not found at %s',
            $ac['id'] ?? '?', $ref, $relative);
        continue;
    }
    if ($executed !== null && $method !== '' && !in_array($method, $executed, true)) {
        $notRun[] = sprintf('%s names %s, which exists but did not run', $ac['id'] ?? '?', $ref);
    }
}

foreach ($missing as $m) {
    $violations[] = ['check' => 'C5', 'path' => $recordPath, 'line' => null,
                     'message' => $m, 'fix' => 'Write the test, or correct the reference in the change record.'];
}
foreach ($notRun as $m) {
    $violations[] = ['check' => 'C5', 'path' => $recordPath, 'line' => null,
                     'message' => $m, 'fix' => 'Check the test is not skipped or filtered out of this run.'];
}

$results[] = [
    'id' => 'C5', 'title' => 'test linkage',
    'status' => ($missing === [] && $notRun === []) ? 'pass' : 'fail',
    'detail' => sprintf('%d criteri%s, %d missing, %s',
        count($record['acceptance']), count($record['acceptance']) === 1 ? 'on' : 'a',
        count($missing),
        $executed === null ? 'execution not verified, no junit xml' : count($notRun) . ' did not run'),
];

// ---------------------------------------------------------------------- out
$failed = array_filter($results, fn($r) => $r['status'] === 'fail');
$status = $failed === [] ? 'pass' : 'fail';

if ($format === 'json') {
    echo json_encode([
        'status'     => $status,
        'record'     => $record['id'] ?? basename($recordPath),
        'component'  => $component,
        'owner'      => $owner['owners'],
        'base'       => $base,
        'reader'     => $reader,
        'checks'     => $results,
        'violations' => $violations,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit($status === 'pass' ? 0 : 1);
}

printf("%s  %s  %s  targets %s\n", $record['id'] ?? '?', $record['change_type'], $component, $declaredBranch);
printf("owner %s  from %s\n", implode(' ', $owner['owners']) ?: '(none)', $owner['pattern'] ?? 'no match');
printf("base %s  record read via %s  %d changed file%s in scope\n\n",
    $base, $reader, count($phpFiles), count($phpFiles) === 1 ? '' : 's');

foreach ($results as $r) {
    printf("%-4s %-22s %-5s %s\n", $r['id'], $r['title'], strtoupper($r['status']), $r['detail']);
    foreach ($r['skipped'] ?? [] as $s) {
        printf("       skipped: %s\n", $s);
    }
}

if ($violations !== []) {
    echo "\n";
    foreach ($violations as $v) {
        printf("%s %s%s\n  %s\n  fix: %s\n",
            $v['check'], $v['path'], $v['line'] ? ':' . $v['line'] : '',
            $v['message'], $v['fix']);
    }
}

$pass = count(array_filter($results, fn($r) => $r['status'] === 'pass'));
printf("\n%d passed, %d failed\n", $pass, count($failed));
exit($status === 'pass' ? 0 : 1);

function emit_fatal(string $format, string $message, array $detail = []): void
{
    if ($format === 'json') {
        echo json_encode([
            'status' => 'error', 'message' => $message, 'detail' => $detail,
        ], JSON_PRETTY_PRINT) . "\n";
        return;
    }
    fwrite(STDERR, "error: $message\n");
    foreach ($detail as $d) {
        fwrite(STDERR, "  - $d\n");
    }
}
