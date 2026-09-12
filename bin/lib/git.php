<?php
/**
 * Git helpers. Everything shells out to git and nothing parses .git directly.
 */

function git(string $args, ?int &$code = null): array
{
    $out = [];
    exec('git ' . $args . ' 2>/dev/null', $out, $code);
    return $out;
}

function git_changed_files(string $base): array
{
    // Union of four sources, never a fallback chain.
    //
    // The earlier version used base...HEAD and only consulted the working tree
    // when that came back empty. On a branch carrying one commit and a pile of
    // uncommitted edits, the committed diff was non-empty, so the fallback
    // never ran and every uncommitted file was invisible. The gate reported the
    // changelog untouched while CHANGELOG.md was modified on disk, and zero
    // deprecations while InputOption.php carried nineteen new lines.
    //
    // Locally the agent edits and does not commit. In CI everything is
    // committed and base...HEAD is the whole story. A union is correct in both
    // places; a fallback is correct in neither.
    $sources = [
        'committed' => git('diff --name-only ' . escapeshellarg($base . '...HEAD')),
        'unstaged'  => git('diff --name-only HEAD'),
        'staged'    => git('diff --name-only --cached'),
        'untracked' => git('ls-files --others --exclude-standard'),
    ];

    $all = [];
    foreach ($sources as $files) {
        foreach ($files as $file) {
            if ($file !== '') {
                $all[$file] = true;
            }
        }
    }
    return array_keys($all);
}

/**
 * Which sources contributed, for the run header. Uncommitted work is worth
 * naming: CI will only ever see what was committed, so a locally green gate on
 * uncommitted changes is not a promise about the pull request.
 */
function git_change_sources(string $base): array
{
    return [
        'committed' => count(git('diff --name-only ' . escapeshellarg($base . '...HEAD'))),
        'uncommitted' => count(array_unique(array_merge(
            git('diff --name-only HEAD'),
            git('diff --name-only --cached'),
            git('ls-files --others --exclude-standard')
        ))),
    ];
}

/** Line numbers added to $file relative to $base. */
function git_added_lines(string $base, string $file): array
{
    $cmd = 'diff -U0 ' . escapeshellarg($base . '...HEAD') . ' -- ' . escapeshellarg($file);
    $out = git($cmd, $code);
    if ($code !== 0 || $out === []) {
        $out = git('diff -U0 HEAD -- ' . escapeshellarg($file));
    }
    $lines = [];
    foreach ($out as $line) {
        if (!preg_match('/^@@ -\d+(?:,\d+)? \+(\d+)(?:,(\d+))? @@/', $line, $m)) {
            continue;
        }
        $start = (int) $m[1];
        $count = isset($m[2]) ? (int) $m[2] : 1;
        for ($k = 0; $k < $count; $k++) {
            $lines[] = $start + $k;
        }
    }
    return $lines;
}

/** Added lines of a file as text, used for changelog content checks. */
function git_added_text(string $base, string $file): array
{
    $out = git('diff -U0 ' . escapeshellarg($base . '...HEAD') . ' -- ' . escapeshellarg($file), $code);
    if ($code !== 0 || $out === []) {
        $out = git('diff -U0 HEAD -- ' . escapeshellarg($file));
    }
    $added = [];
    foreach ($out as $line) {
        if (str_starts_with($line, '+') && !str_starts_with($line, '+++')) {
            $added[] = substr($line, 1);
        }
    }
    return $added;
}

function path_matches_any(string $path, array $globs): bool
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
