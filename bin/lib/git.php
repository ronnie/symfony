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

/**
 * The merge base of $base and HEAD.
 *
 * Everything below diffs from here to the WORKING TREE, with two dots rather
 * than three. That is deliberate and it took two bugs to arrive at.
 *
 * `base...HEAD` compares the merge base against the committed tip, which is
 * right for a pull request diff and blind to uncommitted work. Locally the
 * agent edits and does not commit, so the gate reported a changelog untouched
 * while the file was modified on disk.
 *
 * Unioning the two was the first fix and it was still wrong: line numbers from
 * the committed diff index a different version of the file than the one
 * scan_deprecations() reads, so one uncommitted insertion shifts every line
 * below it and the overlap test compares positions in two different files.
 *
 * merge-base to working tree is one coordinate system, matches what the walker
 * reads, and in CI, where everything is committed, it equals base...HEAD.
 */
function git_merge_base(string $base): string
{
    $out = git('merge-base ' . escapeshellarg($base) . ' HEAD', $code);
    return ($code === 0 && $out !== []) ? trim($out[0]) : $base;
}

function git_changed_files(string $base): array
{
    $mb = git_merge_base($base);
    $files = array_merge(
        git('diff --name-only ' . escapeshellarg($mb)),
        git('ls-files --others --exclude-standard')
    );
    return array_values(array_unique(array_filter($files)));
}

/** Line numbers added to $file between the merge base and the working tree. */
function git_added_lines(string $base, string $file): array
{
    $mb = git_merge_base($base);
    $out = git('diff -U0 ' . escapeshellarg($mb) . ' -- ' . escapeshellarg($file));
    if ($out === []) {
        // Untracked: every line is new.
        if (is_file($file)) {
            return range(1, max(1, count(file($file))));
        }
        return [];
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

/** Added lines of $file as text, used for the changelog content check. */
function git_added_text(string $base, string $file): array
{
    $mb = git_merge_base($base);
    $out = git('diff -U0 ' . escapeshellarg($mb) . ' -- ' . escapeshellarg($file));
    if ($out === [] && is_file($file)) {
        return file($file, FILE_IGNORE_NEW_LINES);
    }
    $added = [];
    foreach ($out as $line) {
        if (str_starts_with($line, '+') && !str_starts_with($line, '+++')) {
            $added[] = substr($line, 1);
        }
    }
    return $added;
}

/**
 * Committed and uncommitted counts for the run header. CI only ever sees what
 * was committed, so a locally green gate on uncommitted work is not a promise
 * about the pull request.
 */
function git_change_sources(string $base): array
{
    $mb = git_merge_base($base);
    return [
        'committed' => count(array_filter(git('diff --name-only ' . escapeshellarg($mb) . ' HEAD'))),
        'uncommitted' => count(array_unique(array_filter(array_merge(
            git('diff --name-only HEAD'),
            git('diff --name-only --cached'),
            git('ls-files --others --exclude-standard')
        )))),
    ];
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
