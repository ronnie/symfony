<?php
/**
 * Change record loading and schema validation.
 *
 * Prefers Symfony's own Yaml component when the repository autoloader is
 * present. Falls back to a constrained reader so the checker still runs inside
 * a hook before composer install has finished, which is when a fast local loop
 * matters most. The schema is deliberately flat enough for that fallback.
 */

function record_load(string $path, ?string &$readerUsed = null): array
{
    if (!is_file($path)) {
        throw new RuntimeException("change record not found: $path");
    }
    $raw = file_get_contents($path);

    $autoload = record_find_autoload($path);
    if ($autoload !== null) {
        require_once $autoload;
        if (class_exists('Symfony\\Component\\Yaml\\Yaml')) {
            $readerUsed = 'symfony/yaml';
            return (array) Symfony\Component\Yaml\Yaml::parse($raw);
        }
    }
    $readerUsed = 'builtin fallback';
    return yaml_lite_parse($raw);
}

function record_find_autoload(string $from): ?string
{
    $dir = realpath(dirname($from)) ?: dirname($from);
    for ($i = 0; $i < 8; $i++) {
        $candidate = $dir . '/vendor/autoload.php';
        if (is_file($candidate)) {
            return $candidate;
        }
        $parent = dirname($dir);
        if ($parent === $dir) {
            break;
        }
        $dir = $parent;
    }
    return null;
}

/** Constrained YAML reader: nested maps, lists of scalars, lists of maps. */
function yaml_lite_parse(string $raw): array
{
    $lines = [];
    foreach (explode("\n", str_replace("\r\n", "\n", $raw)) as $line) {
        if (trim($line) === '' || str_starts_with(trim($line), '#')) {
            continue;
        }
        $indent = strlen($line) - strlen(ltrim($line, ' '));
        $lines[] = ['indent' => $indent, 'text' => rtrim($line)];
    }
    $i = 0;
    return yaml_lite_block($lines, $i, 0);
}

function yaml_lite_block(array $lines, int &$i, int $indent)
{
    $isList = isset($lines[$i]) && str_starts_with(ltrim($lines[$i]['text']), '- ');
    $result = [];

    while ($i < count($lines)) {
        $line = $lines[$i];
        if ($line['indent'] < $indent) {
            break;
        }
        $text = ltrim($line['text']);

        if ($isList) {
            if (!str_starts_with($text, '- ') && !($text === '-')) {
                break;
            }
            $inner = trim(substr($text, 1));
            $i++;
            if ($inner === '') {
                $result[] = yaml_lite_block($lines, $i, $line['indent'] + 1);
                continue;
            }
            if (preg_match('/^([A-Za-z0-9_.-]+):\s*(.*)$/', $inner, $m)) {
                // list of maps, first key on the dash line
                $item = [];
                $item[$m[1]] = yaml_lite_scalar($m[2]);
                $childIndent = $line['indent'] + 2;
                while ($i < count($lines) && $lines[$i]['indent'] >= $childIndent
                       && !str_starts_with(ltrim($lines[$i]['text']), '- ')) {
                    $sub = ltrim($lines[$i]['text']);
                    if (preg_match('/^([A-Za-z0-9_.-]+):\s*(.*)$/', $sub, $mm)) {
                        $i++;
                        if ($mm[2] === '') {
                            $item[$mm[1]] = yaml_lite_block($lines, $i, $childIndent + 1);
                        } else {
                            $item[$mm[1]] = yaml_lite_scalar($mm[2]);
                        }
                    } else {
                        $i++;
                    }
                }
                $result[] = $item;
                continue;
            }
            $result[] = yaml_lite_scalar($inner);
            continue;
        }

        if (!preg_match('/^([A-Za-z0-9_.-]+):\s*(.*)$/', $text, $m)) {
            $i++;
            continue;
        }
        $key = $m[1];
        $value = $m[2];
        $i++;
        if ($value !== '') {
            $result[$key] = yaml_lite_scalar($value);
            continue;
        }
        if ($i < count($lines) && $lines[$i]['indent'] > $line['indent']) {
            $result[$key] = yaml_lite_block($lines, $i, $lines[$i]['indent']);
        } else {
            $result[$key] = null;
        }
    }
    return $result;
}

/**
 * Strip a trailing comment that sits outside quotes.
 *
 * Symfony's Yaml component does this. This reader did not, which is how
 * "deprecation  # bug_fix | feature | deprecation" reached schema validation
 * as a single scalar. Two readers that disagree about a file are two sources of
 * truth, so if this fallback survives past Phase C it needs to agree with the
 * component on every shape the schema allows, not most of them.
 */
function yaml_lite_strip_comment(string $v): string
{
    $out = '';
    $quote = null;
    $len = strlen($v);
    for ($i = 0; $i < $len; $i++) {
        $c = $v[$i];
        if ($quote !== null) {
            $out .= $c;
            if ($c === $quote) { $quote = null; }
            continue;
        }
        if ($c === '"' || $c === "'") { $quote = $c; $out .= $c; continue; }
        if ($c === '#' && ($i === 0 || $v[$i - 1] === ' ' || $v[$i - 1] === "\t")) {
            break;
        }
        $out .= $c;
    }
    return rtrim($out);
}

function yaml_lite_scalar(string $v)
{
    $v = trim(yaml_lite_strip_comment($v));
    if ($v === '') { return null; }
    if (preg_match('/^"(.*)"$/s', $v, $m)) {
        // Symfony's Yaml component unescapes these. This reader did not, which
        // is how a class name reached the checker carrying doubled backslashes.
        return strtr($m[1], ['\\\\' => '\\', '\\"' => '"', '\\n' => "\n", '\\t' => "\t"]);
    }
    if (preg_match("/^'(.*)'$/s", $v, $m)) { return $m[1]; }
    if (str_starts_with($v, '[') && str_ends_with($v, ']')) {
        $inner = trim(substr($v, 1, -1));
        if ($inner === '') { return []; }
        return array_map('yaml_lite_scalar', array_map('trim', explode(',', $inner)));
    }
    $low = strtolower($v);
    if ($low === 'true') { return true; }
    if ($low === 'false') { return false; }
    if ($low === 'null' || $low === '~') { return null; }
    return $v;
}

/** @return string[] list of schema violations, empty when valid */
function record_validate(array $r): array
{
    $errors = [];
    $need = function (array $node, string $path, array $keys) use (&$errors) {
        foreach ($keys as $k) {
            if (!array_key_exists($k, $node) || $node[$k] === null || $node[$k] === '') {
                $errors[] = "missing required field: {$path}{$k}";
            }
        }
    };

    $need($r, '', ['id', 'change_type', 'target', 'acceptance']);

    $types = ['bug_fix', 'feature', 'deprecation'];
    if (isset($r['change_type']) && !in_array($r['change_type'], $types, true)) {
        $errors[] = "change_type must be one of " . implode(', ', $types);
    }

    if (isset($r['target']) && is_array($r['target'])) {
        $need($r['target'], 'target.', ['component', 'branch', 'paths']);
        if (isset($r['target']['paths']) && !is_array($r['target']['paths'])) {
            $errors[] = 'target.paths must be a list';
        }
    }

    if (($r['change_type'] ?? null) === 'deprecation') {
        if (!isset($r['deprecates']) || !is_array($r['deprecates'])) {
            $errors[] = 'deprecation changes must carry a deprecates block';
        } else {
            $need($r['deprecates'], 'deprecates.', ['symbol', 'replacement', 'since']);
        }
    }

    if (isset($r['acceptance'])) {
        if (!is_array($r['acceptance']) || $r['acceptance'] === []) {
            $errors[] = 'acceptance must be a non empty list';
        } else {
            foreach ($r['acceptance'] as $n => $ac) {
                if (!is_array($ac)) {
                    $errors[] = "acceptance[$n] must be a map";
                    continue;
                }
                $need($ac, "acceptance[$n].", ['id', 'statement', 'test']);
            }
        }
    }

    return $errors;
}
