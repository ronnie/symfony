#!/usr/bin/env php
<?php
/**
 * Measure the deprecation pairing convention across the whole monorepo.
 *
 *   php bin/survey.php
 *   php bin/survey.php src/Symfony/Component
 *   php bin/survey.php --examples=8
 *
 * Two jobs at once.
 *
 * 1. It answers whether C1 encodes a real convention. If methods annotated
 *    @deprecated overwhelmingly call trigger_deprecation, the rule is the
 *    project's, and the manifest can cite a measured rate rather than one
 *    exemplar. If the rate is low, C1 is wrong and needs redesigning before
 *    anything is built on it.
 *
 * 2. It is the real test of the token walker. Eleven synthetic fixtures prove
 *    very little. Several thousand files of production Symfony will surface
 *    every syntax shape the walker mishandles. A crash, or an absurd number,
 *    means the walker is broken and the fixtures were too kind.
 */

require __DIR__ . '/lib/tokens.php';

$root = 'src/Symfony';
$examples = 5;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--examples=')) { $examples = (int) substr($arg, 11); continue; }
    if (!str_starts_with($arg, '--')) { $root = rtrim($arg, '/'); }
}

if (!is_dir($root)) {
    fwrite(STDERR, "not a directory: $root\nRun this from the repository root.\n");
    exit(2);
}

$iter = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);

$files = 0;
$skippedFiles = [];
$byKind = [];
$paired = 0;
$unpaired = 0;
$unpairedExamples = [];
$perComponent = [];
$triggerCalls = 0;
$filesWithTrigger = 0;
$filesWithAnnotation = 0;
$internalSkipped = 0;

$t0 = microtime(true);

foreach ($iter as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }
    $path = $file->getPathname();
    if (str_contains($path, '/Tests/') || str_contains($path, '/vendor/')) {
        continue;
    }
    $files++;
    $code = @file_get_contents($path);
    if ($code === false) {
        $skippedFiles[] = $path;
        continue;
    }

    $nTrigger = preg_match_all('/\btrigger_deprecation\s*\(/', $code);
    $triggerCalls += $nTrigger;
    if ($nTrigger > 0) { $filesWithTrigger++; }

    if (!str_contains($code, '@deprecated')) {
        continue;
    }
    $filesWithAnnotation++;

    try {
        $entries = scan_deprecations($code);
    } catch (Throwable $e) {
        $skippedFiles[] = $path . '  (' . $e->getMessage() . ')';
        continue;
    }

    $component = component_of($path);
    foreach ($entries as $e) {
        $byKind[$e['kind']] = ($byKind[$e['kind']] ?? 0) + 1;
        if (!$e['checkable']) {
            continue;
        }
        $perComponent[$component] = ($perComponent[$component] ?? 0) + 1;
        if (!empty($e['internal'])) {
            $internalSkipped++;
            continue;
        }
        if ($e['hasTrigger']) {
            $paired++;
        } else {
            $unpaired++;
            if (count($unpairedExamples) < $examples) {
                $unpairedExamples[] = sprintf('%s:%d  %s()', $path, $e['docLine'], $e['name']);
            }
        }
    }
}

$elapsed = microtime(true) - $t0;
$checkable = $paired + $unpaired;

section('1. Coverage');
printf("php files scanned            %d  in %.1fs\n", $files, $elapsed);
printf("files containing @deprecated %d\n", $filesWithAnnotation);
printf("files calling trigger_deprecation %d\n", $filesWithTrigger);
printf("trigger_deprecation calls    %d\n", $triggerCalls);
if ($skippedFiles !== []) {
    printf("\nfiles the walker could not read or tokenize: %d\n", count($skippedFiles));
    foreach (array_slice($skippedFiles, 0, 5) as $s) { echo "  $s\n"; }
    echo "\nAny entry above is a walker bug. Investigate before trusting section 2.\n";
}

section('2. C1, the pairing convention, measured');
printf("methods and functions annotated @deprecated with a body  %d\n", $checkable);
if ($checkable > 0) {
    printf("  call trigger_deprecation in that body                  %d  (%.1f%%)\n",
        $paired, 100 * $paired / $checkable);
    printf("  do not                                                 %d  (%.1f%%)\n",
        $unpaired, 100 * $unpaired / $checkable);
}
printf("  excluded as @internal                                  %d\n", $internalSkipped);
echo "\nAn @internal symbol has no caller outside the component, so its user\n";
echo "facing deprecation is declared at the boundary the caller touches instead.\n";
echo "See HttpKernelExtension.php:30, DeprecatedCallableInfo.\n";
echo "\nHow to read this. Above roughly 90 percent the pairing is a real project\n";
echo "convention and C1 should cite the rate rather than one exemplar. Between 60\n";
echo "and 90, it is a norm with real exceptions, and C1 needs to know what they\n";
echo "are before it fires. Below 60, C1 is not Symfony's rule and should be cut.\n";

if ($unpairedExamples !== []) {
    echo "\nunpaired, read these by hand:\n";
    foreach ($unpairedExamples as $x) { echo "  $x\n"; }
    echo "\nA legitimate exception usually delegates: the method forwards to another\n";
    echo "that triggers, or the class constructor triggers once for the whole type.\n";
    echo "If most of these turn out legitimate, C1 needs a delegation escape hatch.\n";
}

section('3. Where deprecations actually live');
arsort($perComponent);
$top = array_slice($perComponent, 0, 12, true);
foreach ($top as $component => $n) {
    printf("  %-34s %d\n", $component, $n);
}
echo "\nThis is the list to pick a citation from. Console carries none, so C1's\n";
echo "exemplar is borrowed from whichever component leads here, and the manifest\n";
echo "records the borrowing rather than hiding it.\n";

section('4. Annotations by kind');
arsort($byKind);
foreach ($byKind as $kind => $n) {
    printf("  %-14s %d\n", $kind, $n);
}
echo "\nEverything outside method and function is what C1 skips by design, because\n";
echo "those declarations have no body to hold the call. The size of that group is\n";
echo "the honest measure of C1's blind spot.\n";

echo "\n";

function component_of(string $path): string
{
    if (preg_match('#src/Symfony/(Component|Bridge|Bundle)/([^/]+)#', $path, $m)) {
        return $m[1] . '/' . $m[2];
    }
    return dirname($path);
}

function section(string $title): void
{
    echo "\n" . $title . "\n" . str_repeat('=', strlen($title)) . "\n";
}
