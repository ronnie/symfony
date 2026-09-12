<?php
/**
 * CODEOWNERS resolution. Most specific matching pattern wins, which is the
 * behaviour GitHub itself uses: the last matching rule in the file takes
 * precedence, and Symfony's file orders general before specific.
 *
 * Never maintain an owner list by hand. A second source of truth is the failure
 * this whole artifact argues against.
 */

function codeowners_load(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $rules = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        $parts = preg_split('/\s+/', $line);
        $pattern = array_shift($parts);
        $owners = array_values(array_filter($parts, fn($p) => str_starts_with($p, '@') || str_contains($p, '@')));
        $rules[] = ['pattern' => $pattern, 'owners' => $owners];
    }
    return $rules;
}

function codeowners_matches(string $pattern, string $path): bool
{
    $path = '/' . ltrim($path, '/');
    $p = $pattern;

    if (!str_starts_with($p, '/') && !str_contains($p, '/')) {
        // Bare name matches at any level.
        return fnmatch('*/' . $p, $path) || fnmatch('*/' . $p . '/*', $path);
    }
    if (!str_starts_with($p, '/')) {
        $p = '/' . $p;
    }
    if (str_ends_with($p, '/')) {
        return str_starts_with($path, $p);
    }
    if ($path === $p) {
        return true;
    }
    if (str_starts_with($path, rtrim($p, '/') . '/')) {
        return true;
    }
    return fnmatch($p, $path) || fnmatch($p . '/*', $path);
}

/**
 * @return array{owners: string[], pattern: ?string}
 */
function codeowners_for(array $rules, string $path): array
{
    $best = null;
    foreach ($rules as $rule) {
        if (!codeowners_matches($rule['pattern'], $path)) {
            continue;
        }
        // Later rules win; among equals, the longer pattern is more specific.
        if ($best === null
            || strlen($rule['pattern']) >= strlen($best['pattern'])) {
            $best = $rule;
        }
    }
    return $best === null
        ? ['owners' => [], 'pattern' => null]
        : ['owners' => $best['owners'], 'pattern' => $best['pattern']];
}
