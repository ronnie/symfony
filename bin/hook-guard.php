#!/usr/bin/env php
<?php
/**
 * Boundary enforcement for Cursor hooks.
 *
 *   php bin/hook-guard.php read     <- beforeReadFile
 *   php bin/hook-guard.php shell    <- beforeShellExecution
 *
 * Reads the hook payload as JSON on stdin and writes a decision as JSON on
 * stdout.
 *
 * WHY THESE TWO HOOKS AND NOT A THIRD
 *
 * There is no pre-edit hook. beforeFileEdit is rejected at load as an unknown
 * type, and the rejection discards the whole configuration file rather than the
 * one bad entry (lab 7). afterFileEdit runs after the write and its output is
 * logged and dropped, so it cannot tell the agent anything (lab 8).
 *
 * So the write boundary is not enforced in the editor. It is enforced by
 * bin/check-change.php, which the agent runs as a tool and whose stdout lands in
 * the agent's context, and by the CI gate, which cannot be bypassed. What these
 * hooks do is narrower and honest: deny reads outside the boundary, which is the
 * one file hook with a working deny channel, and close the shell path so an edit
 * cannot be laundered through sed.
 *
 * FIELD NAMES ARE NOT GUESSES, CHECK THEM
 *
 * The response shape below follows the lab 6 observation that beforeReadFile
 * delivers a visible deny the agent names. Confirm the exact keys against
 * .cursor/labs/lab6/OBSERVED.md before trusting this in a demo. A wrong key
 * means the hook silently allows.
 */

require __DIR__ . '/lib/record.php';

$mode = $argv[1] ?? '';
$root = dirname(__DIR__);

// A hook that blocks is worse than a hook that denies: it stalls the agent with
// no message and no way to tell the difference from a hang in the tool. If
// stdin is a terminal there is no payload coming, so fail fast and loudly.
if ($mode !== 'allowance' && stream_isatty(STDIN)) {
    fwrite(STDERR, "hook-guard expects a JSON payload on stdin.\n");
    fwrite(STDERR, "  echo '{\"file_path\":\"...\"}' | php bin/hook-guard.php read\n");
    fwrite(STDERR, "  php bin/hook-guard.php allowance   # print the derived boundary\n");
    exit(2);
}

$payload = $mode === 'allowance'
    ? []
    : (json_decode((string) stream_get_contents(STDIN), true) ?: []);
$manifestPath = $root . '/conventions.yml';

// The manifest is required, not optional. An earlier version defaulted to
// Console when the file was absent, which is the right answer by coincidence:
// the boundary looked correct in every output while coming from hardcoded
// values rather than from the manifest. That is a fail-quiet default inside the
// tool that argues against fail-quiet defaults, and it went unnoticed for three
// labs because conventions.yml was sitting in bin/ rather than at the root.
//
// No manifest means no declared boundary, so nothing can be judged against one
// and everything is denied. Fail closed and say why.
if (!is_file($manifestPath)) {
    if ($mode === 'allowance') {
        fwrite(STDERR, "conventions.yml not found at $manifestPath\n");
        fwrite(STDERR, "The manifest is the source of truth for the boundary. It belongs at the\n");
        fwrite(STDERR, "repository root, not in bin/.\n");
        exit(2);
    }
    respond_deny(
        "Boundary cannot be determined: conventions.yml not found at $manifestPath.",
        'The convention manifest is missing, so no boundary is declared and no read or command can be judged against one. Do not proceed and do not work around this. Tell the user that conventions.yml is absent from the repository root.'
    );
}

$manifest = load_manifest($manifestPath);

if (($manifest['scope']['component'] ?? null) === null) {
    if ($mode === 'allowance') {
        fwrite(STDERR, "conventions.yml at $manifestPath declares no scope.component\n");
        exit(2);
    }
    respond_deny(
        'Boundary cannot be determined: conventions.yml declares no scope.component.',
        'The manifest exists but does not declare a scope. Do not proceed. Tell the user the manifest is malformed.'
    );
}

$component = $manifest['scope']['component'];
$componentRoot = $manifest['scope']['root'] ?? "src/Symfony/Component/$component";

$readAllow = derive_read_allowance($root, $componentRoot, $manifest);
$writeAllow = $manifest['boundary']['write_allow'] ?? [$componentRoot . '/**'];

if ($mode === 'allowance') {
    // The boundary, derived rather than typed. Print it so the agent, the user
    // and the demo audience are all looking at the same list, and so a wrong
    // one is visible before it silently opens or closes the boundary.
    echo "component      $component\n";
    echo "component root $componentRoot\n\n";
    echo "readable:\n";
    foreach ($readAllow as $g) { echo "  $g\n"; }
    echo "\nwritable:\n";
    foreach ($writeAllow as $g) { echo "  $g\n"; }
    $composer = $root . '/' . $componentRoot . '/composer.json';
    if (is_file($composer)) {
        $meta = json_decode((string) file_get_contents($composer), true);
        echo "\nderived from " . $componentRoot . "/composer.json require:\n";
        foreach (array_keys($meta['require'] ?? []) as $package) {
            $dir = package_to_directory($root, (string) $package);
            printf("  %-34s %s\n", $package, $dir ?? '(not a monorepo path)');
        }
    }
    exit(0);
}

if ($mode === 'read') {
    $path = relative_path($root, (string) (
        $payload['file_path'] ?? $payload['filePath'] ?? $payload['path'] ?? ''
    ));
    if ($path === '' || allowed($path, $readAllow)) {
        respond_allow();
    }
    respond_deny(
        sprintf('Read denied: %s is outside the boundary declared for %s.', $path, $component),
        sprintf(
            "This change is scoped to %s. Readable: %s.\n" .
            "Do not look for another route to this file. If you need it, say which path and why, " .
            "and the boundary is widened in the change record by the user rather than by you.",
            $componentRoot, implode(', ', $readAllow)
        )
    );
}

if ($mode === 'shell') {
    $command = (string) ($payload['command'] ?? $payload['cmd'] ?? '');
    $hit = mutating_pattern($command);
    if ($hit === null) {
        respond_allow();
    }
    respond_deny(
        sprintf('Shell edit denied: this command modifies files through %s.', $hit),
        "File edits go through the edit tool, not the shell.\n" .
        "Enumerating egress paths matters more than enumerating tools: a boundary that only " .
        "covers the obvious tool is not a boundary. Make the change with the edit tool, then run " .
        "php bin/check-change.php <record> --format=json and read the violations."
    );
}

fwrite(STDERR, "usage: hook-guard.php read|shell\n");
exit(2);

// ---------------------------------------------------------------- decisions

/**
 * Commands that write to the filesystem in place. This is a denylist of shapes
 * rather than a shell parser, and it will not catch a determined bypass. It is
 * not trying to. The CI gate is the control; this closes the easy route and
 * tells the agent why.
 */
function mutating_pattern(string $command): ?string
{
    // Quoted segments are stripped before matching. A PHP codebase is full of
    // -> and =>, and a grep for a method call is not a file write. Blocking
    // legitimate work is worse than not blocking a determined bypass.
    $bare = preg_replace(['/"[^"]*"/', "/'[^']*'/"], ['""', "''"], $command) ?? $command;

    $patterns = [
        'sed -i'        => '/\bsed\b[^|;]*\s-i\b/',
        'perl -i'       => '/\bperl\b[^|;]*\s-i\b/',
        // Redirection only. Not -> or => or 2>&1.
        'output redirect' => '/(?<![-=0-9<>])>{1,2}(?![>=])\s*[^\s&|]/',
        'tee'           => '/\btee\b/',
        'cp'            => '/\bcp\b\s/',
        'mv'            => '/\bmv\b\s/',
        'patch'         => '/\bpatch\b\s/',
        'git apply'     => '/\bgit\s+apply\b/',
        'git checkout of a path' => '/\bgit\s+checkout\b[^|;]*\s--\s/',
        'git restore'   => '/\bgit\s+restore\b/',
        'truncate'      => '/\btruncate\b\s/',
        'php -r with file write' => '/\bphp\s+-r\b[^|;]*(file_put_contents|fwrite|rename|unlink)/',
    ];
    foreach ($patterns as $label => $regex) {
        if (preg_match($regex, $bare)) {
            return $label;
        }
    }
    return null;
}

/**
 * Derived, never typed. The component root, whatever the component's own
 * composer.json requires that resolves inside src/Symfony, the contributing
 * docs, and the tooling the agent is expected to run.
 *
 * A hand maintained allowance would be a second source of truth about what the
 * component depends on, which is the failure this whole artifact argues against.
 */
function derive_read_allowance(string $root, string $componentRoot, array $manifest): array
{
    // The declared base comes from the manifest. Nothing is hardcoded here, so
    // editing conventions.yml changes the boundary and a missing entry shows up
    // as a denial rather than as a silent default.
    $allow = $manifest['boundary']['read_allow'] ?? [];
    if ($allow === []) {
        $allow = [$componentRoot . '/**'];
    }
    foreach ($manifest['artifact_paths'] ?? [] as $glob) {
        $allow[] = $glob;
    }

    $composer = $root . '/' . $componentRoot . '/composer.json';
    if (is_file($composer)) {
        $meta = json_decode((string) file_get_contents($composer), true);
        foreach (array_keys($meta['require'] ?? []) as $package) {
            $dir = package_to_directory($root, (string) $package);
            if ($dir !== null) {
                $allow[] = $dir . '/**';
            }
        }
    }
    return array_values(array_unique($allow));
}

/**
 * symfony/string           -> src/Symfony/Component/String
 * symfony/service-contracts -> src/Symfony/Contracts/Service
 *
 * Polyfills correctly resolve to nothing: they live in separate repositories,
 * so there is no monorepo path to allow and the agent reads them from vendor
 * only if the boundary is widened deliberately.
 */
function package_to_directory(string $root, string $package): ?string
{
    if (!str_starts_with($package, 'symfony/')) {
        return null;
    }
    $name = substr($package, 8);
    $studly = fn(string $n) => str_replace(' ', '', ucwords(str_replace('-', ' ', $n)));

    $candidates = ["src/Symfony/Component/{$studly($name)}"];
    if (str_ends_with($name, '-contracts')) {
        $candidates[] = 'src/Symfony/Contracts/' . $studly(substr($name, 0, -10));
    }
    $candidates[] = "src/Symfony/Contracts/{$studly($name)}";
    $candidates[] = "src/Symfony/Bridge/{$studly($name)}";
    $candidates[] = "src/Symfony/Bundle/{$studly($name)}";

    foreach ($candidates as $candidate) {
        if (is_dir($root . '/' . $candidate)) {
            return $candidate;
        }
    }
    return null;
}

function allowed(string $path, array $globs): bool
{
    foreach ($globs as $glob) {
        $regex = '#^' . str_replace(
            ['\*\*/', '\*\*', '\*'],
            ['(?:.*/)?', '.*', '[^/]*'],
            preg_quote($glob, '#')
        ) . '$#';
        if (preg_match($regex, $path)) {
            return true;
        }
    }
    return false;
}

function relative_path(string $root, string $path): string
{
    $real = realpath($path) ?: $path;
    $rootReal = realpath($root) ?: $root;
    if (str_starts_with($real, $rootReal . '/')) {
        return substr($real, strlen($rootReal) + 1);
    }
    return ltrim($path, '/');
}

function load_manifest(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    try {
        return record_load($path);
    } catch (Throwable) {
        return [];
    }
}

// ---------------------------------------------------------------- responses

function respond_allow(): never
{
    echo json_encode(['permission' => 'allow']) . "\n";
    exit(0);
}

function respond_deny(string $userMessage, string $agentMessage): never
{
    echo json_encode([
        'permission'    => 'deny',
        'userMessage'   => $userMessage,
        'agentMessage'  => $agentMessage,
        'user_message'  => $userMessage,
        'agent_message' => $agentMessage,
    ], JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}
