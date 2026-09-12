#!/usr/bin/env php
<?php
/**
 * Measure the trigger_deprecation call shape across the monorepo.
 *
 *   php bin/survey-calls.php
 *
 * C1 as built covers symbol deprecations, which happen 16 times in 6189 files.
 * This measures a candidate second check with a much larger denominator: the
 * call itself. The package argument should be the owning component's own
 * composer name, and the version argument should be a released or in
 * development branch.
 *
 * Purpose is to find out whether that is a real convention before anything is
 * built on it. A low match rate means the idea is wrong and gets dropped, the
 * same way the branch targeting table was dropped once the data contradicted it.
 */

$root = 'src/Symfony';
if (!is_dir($root)) {
    fwrite(STDERR, "not a directory: $root\nRun this from the repository root.\n");
    exit(2);
}

$iter = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);

$total = 0;
$literal = 0;
$dynamic = 0;
$match = 0;
$mismatch = [];
$noPackage = 0;
$versions = [];
$packageCache = [];

foreach ($iter as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }
    $path = $file->getPathname();
    if (str_contains($path, '/vendor/')) {
        continue;
    }
    $code = @file_get_contents($path);
    if ($code === false || !str_contains($code, 'trigger_deprecation')) {
        continue;
    }

    $count = preg_match_all('/\btrigger_deprecation\s*\(/', $code);
    $total += $count;

    preg_match_all(
        "/\\btrigger_deprecation\\s*\\(\\s*'([^']*)'\\s*,\\s*'([^']*)'/",
        $code,
        $m,
        PREG_SET_ORDER
    );
    $literal += count($m);
    $dynamic += $count - count($m);

    $owning = owning_package($path, $packageCache);
    foreach ($m as $hit) {
        [$all, $package, $version] = $hit;
        $versions[$version] = ($versions[$version] ?? 0) + 1;
        if ($owning === null) {
            $noPackage++;
            continue;
        }
        if ($package === $owning) {
            $match++;
        } else {
            $mismatch[] = [
                'path' => $path,
                'declared' => $package,
                'owning' => $owning,
            ];
        }
    }
}

$compared = $match + count($mismatch);

section('1. Calls found');
printf("trigger_deprecation calls              %d\n", $total);
printf("  both first arguments string literals  %d\n", $literal);
printf("  at least one argument dynamic         %d\n", $dynamic);
printf("  no owning composer.json found         %d\n", $noPackage);

section('2. Does the package argument match the owning component');
if ($compared === 0) {
    echo "nothing comparable. The check idea cannot be evaluated from this repo.\n";
} else {
    printf("comparable calls                       %d\n", $compared);
    printf("  package matches owning composer name  %d  (%.1f%%)\n",
        $match, 100 * $match / $compared);
    printf("  does not match                        %d  (%.1f%%)\n",
        count($mismatch), 100 * count($mismatch) / $compared);
}
echo "\nHow to read this. A high match rate means the package argument is\n";
echo "derivable and the check is cheap and correct. Mismatches are not\n";
echo "automatically wrong: a bridge or a bundle may legitimately deprecate on\n";
echo "behalf of the component it wraps. Read the examples before concluding.\n";

if ($mismatch !== []) {
    echo "\nmismatches, read these by hand:\n";
    foreach (array_slice($mismatch, 0, 12) as $x) {
        printf("  %s\n    says %s, owned by %s\n", $x['path'], $x['declared'], $x['owning']);
    }
}

section('3. Version argument distribution');
arsort($versions);
foreach (array_slice($versions, 0, 15, true) as $v => $n) {
    printf("  %-10s %d\n", $v, $n);
}
echo "\nAnything here that is not a Symfony branch number is a typo the current\n";
echo "tooling does not catch. A new deprecation on this branch should say 8.2,\n";
echo "and a value above the development branch is always wrong.\n";
echo "\n";

function owning_package(string $path, array &$cache): ?string
{
    $dir = dirname($path);
    for ($i = 0; $i < 10; $i++) {
        if (array_key_exists($dir, $cache)) {
            return $cache[$dir];
        }
        $candidate = $dir . '/composer.json';
        if (is_file($candidate)) {
            $json = json_decode((string) file_get_contents($candidate), true);
            $name = is_array($json) ? ($json['name'] ?? null) : null;
            $cache[$dir] = $name;
            return $name;
        }
        $parent = dirname($dir);
        if ($parent === $dir) {
            break;
        }
        $dir = $parent;
    }
    return null;
}

function section(string $title): void
{
    echo "\n" . $title . "\n" . str_repeat('=', strlen($title)) . "\n";
}
