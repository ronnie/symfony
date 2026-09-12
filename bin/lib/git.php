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
    $out = git('diff --name-only ' . escapeshellarg($base . '...HEAD'), $code);
    if ($code !== 0 || $out === []) {
        // Fall back to the working tree, which is what a local hook run sees.
        $out = array_merge(
            git('diff --name-only HEAD'),
            git('diff --name-only --cached')
        );
    }
    return array_values(array_unique(array_filter($out)));
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
