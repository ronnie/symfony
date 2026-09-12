<?php
/**
 * Deprecation scanner built on PHP's own tokenizer.
 *
 * PhpToken::tokenize() is the object API over the same tokenizer as
 * token_get_all(). No parser dependency, deliberately: a parser package would
 * need a require entry in a composer manifest, which is exactly what C4 fails
 * on, so the tooling would trip its own gate.
 *
 * Returns one entry per docblock carrying @deprecated:
 *   kind        method | function | class_like | constant | property | unknown
 *   name        best effort identifier
 *   docLine     first line of the docblock
 *   docEndLine  last line of the docblock
 *   bodyStart   first line of the body, or null when there is no body
 *   bodyEnd     last line of the body, or null
 *   hasTrigger  true when trigger_deprecation( appears inside the body
 *   checkable   true only for method and function with a body
 */

const MODIFIER_TOKENS = [
    T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_ABSTRACT, T_FINAL, T_VAR,
];

function tok_is(?PhpToken $t, int|string $what): bool
{
    if ($t === null) {
        return false;
    }
    return is_int($what) ? $t->id === $what : $t->text === $what;
}

function tok_skippable(PhpToken $t): bool
{
    return $t->is([T_WHITESPACE, T_COMMENT]);
}

/** Skip an attribute group starting at an T_ATTRIBUTE token. Returns index after it. */
function tok_skip_attribute(array $tokens, int $i): int
{
    $depth = 0;
    $n = count($tokens);
    for (; $i < $n; $i++) {
        $t = $tokens[$i];
        if ($t->id === T_ATTRIBUTE || $t->text === '[') {
            $depth++;
        } elseif ($t->text === ']') {
            $depth--;
            if ($depth <= 0) {
                return $i + 1;
            }
        }
    }
    return $n;
}

/** Modifier and readonly handling. readonly is contextual, so match by text too. */
function tok_is_modifier(PhpToken $t): bool
{
    if ($t->is(MODIFIER_TOKENS)) {
        return true;
    }
    // Matched by text so this works whether the tokenizer emits asymmetric
    // visibility as one token or as several. PHP 8.4 added public(set),
    // protected(set) and private(set); readonly has always been contextual.
    $text = strtolower(preg_replace('/\s+/', '', $t->text));
    return in_array($text, ['readonly', 'public(set)', 'protected(set)', 'private(set)'], true);
}

function scan_deprecations(string $code): array
{
    $tokens = PhpToken::tokenize($code);
    $n = count($tokens);
    $out = [];

    // Brace depths at which a class-like body opened.
    $classBodyDepths = [];
    $depth = 0;
    $awaitingClassBody = false;

    for ($i = 0; $i < $n; $i++) {
        $t = $tokens[$i];

        if ($t->text === '{') {
            $depth++;
            if ($awaitingClassBody) {
                $classBodyDepths[] = $depth;
                $awaitingClassBody = false;
            }
            continue;
        }
        if ($t->text === '}') {
            if ($classBodyDepths && end($classBodyDepths) === $depth) {
                array_pop($classBodyDepths);
            }
            $depth--;
            continue;
        }

        if ($t->is([T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM])) {
            // Guard against ::class
            $prev = null;
            for ($k = $i - 1; $k >= 0; $k--) {
                if (!tok_skippable($tokens[$k])) { $prev = $tokens[$k]; break; }
            }
            if (!tok_is($prev, T_DOUBLE_COLON)) {
                $awaitingClassBody = true;
            }
            continue;
        }

        if ($t->id !== T_DOC_COMMENT) {
            continue;
        }
        if (!preg_match('/@deprecated\b/i', $t->text)) {
            continue;
        }

        $docLine = $t->line;
        $docEndLine = $docLine + substr_count($t->text, "\n");
        $isInternal = (bool) preg_match('/@internal\b/i', $t->text);

        // Walk forward past whitespace, comments, attributes and modifiers.
        $j = $i + 1;
        while ($j < $n) {
            $c = $tokens[$j];
            if (tok_skippable($c)) { $j++; continue; }
            if ($c->id === T_ATTRIBUTE) { $j = tok_skip_attribute($tokens, $j); continue; }
            if (tok_is_modifier($c)) { $j++; continue; }
            break;
        }
        if ($j >= $n) {
            continue;
        }

        $decl = $tokens[$j];
        $inClass = $classBodyDepths !== [];

        if ($decl->id === T_FUNCTION) {
            [$name, $bodyStart, $bodyEnd, $hasTrigger, $hasBody, $args] =
                inspect_function($tokens, $j);
            $out[] = [
                'kind'       => $inClass ? 'method' : 'function',
                'name'       => $name,
                'docLine'    => $docLine,
                'docEndLine' => $docEndLine,
                'internal'   => $isInternal,
                'bodyStart'  => $hasBody ? $bodyStart : null,
                'bodyEnd'    => $hasBody ? $bodyEnd : null,
                'hasTrigger' => $hasTrigger,
                'package'    => $args[0] ?? null,
                'version'    => $args[1] ?? null,
                'checkable'  => $hasBody,
                'reason'     => $hasBody ? null : 'declaration has no body',
            ];
            continue;
        }

        if ($decl->is([T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM])) {
            $out[] = deprecation_entry('class_like', name_after($tokens, $j),
                $docLine, $docEndLine, 'class-like declarations have no body');
            continue;
        }
        if ($decl->id === T_CONST) {
            $out[] = deprecation_entry('constant', name_after($tokens, $j),
                $docLine, $docEndLine, 'constants have no body');
            continue;
        }
        if ($decl->id === T_VARIABLE) {
            $out[] = deprecation_entry('property', $decl->text,
                $docLine, $docEndLine, 'properties have no body');
            continue;
        }

        // Typed property. Modifiers are already skipped, but a type still sits
        // between them and the variable: public ParameterBag $attributes.
        // Walk the type tokens to the variable, stopping at anything that means
        // this is not a property declaration.
        $k = $j;
        $steps = 0;
        while ($k < $n && $steps < 24) {
            $c = $tokens[$k];
            if (tok_skippable($c)) { $k++; continue; }
            if ($c->id === T_VARIABLE) {
                $out[] = deprecation_entry('property', $c->text,
                    $docLine, $docEndLine, 'properties have no body');
                continue 2;
            }
            $isTypeToken = $c->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED,
                                   T_NAME_RELATIVE, T_ARRAY, T_STATIC, T_CALLABLE])
                || in_array($c->text, ['?', '|', '&', '(', ')', '\\'], true);
            if (!$isTypeToken) { break; }
            $k++;
            $steps++;
        }

        $out[] = deprecation_entry('unknown', trim($decl->text),
            $docLine, $docEndLine, 'declaration kind not recognised');
    }

    return $out;
}

/** Advance to the comma ending the current argument, respecting nesting. */
function skip_to_next_argument(array $tokens, int $p, int $end): int
{
    $nest = 0;
    for (; $p <= $end; $p++) {
        $x = $tokens[$p]->text;
        if ($x === '(' || $x === '[') { $nest++; continue; }
        if ($x === ')' || $x === ']') {
            if ($nest === 0) { return $p - 1; }
            $nest--;
            continue;
        }
        if ($x === ',' && $nest === 0) { return $p; }
    }
    return $end;
}

function deprecation_entry(string $kind, string $name, int $docLine, int $docEndLine, string $reason): array
{
    return [
        'kind' => $kind, 'name' => $name,
        'docLine' => $docLine, 'docEndLine' => $docEndLine,
        'bodyStart' => null, 'bodyEnd' => null,
        'internal' => false,
        'hasTrigger' => false, 'package' => null, 'version' => null,
        'checkable' => false, 'reason' => $reason,
    ];
}

function name_after(array $tokens, int $i): string
{
    $n = count($tokens);
    for ($k = $i + 1; $k < $n; $k++) {
        if (tok_skippable($tokens[$k])) { continue; }
        if ($tokens[$k]->id === T_STRING) { return $tokens[$k]->text; }
        return '';
    }
    return '';
}

/**
 * From a T_FUNCTION token index, find the name, locate the body, and look for
 * trigger_deprecation inside it.
 *
 * @return array{0:string,1:?int,2:?int,3:bool,4:bool,5:array}
 */
function inspect_function(array $tokens, int $i): array
{
    $n = count($tokens);
    $name = name_after($tokens, $i);

    // Walk to the end of the parameter list.
    $k = $i + 1;
    $paren = 0;
    $sawParams = false;
    for (; $k < $n; $k++) {
        $x = $tokens[$k]->text;
        if ($x === '(') { $paren++; $sawParams = true; continue; }
        if ($x === ')') { $paren--; if ($paren === 0) { $k++; break; } continue; }
    }
    if (!$sawParams) {
        return [$name, null, null, false, false, []];
    }

    // Then to the first { or ; at paren depth zero. Return types may use
    // parentheses for DNF types, so keep counting.
    $paren = 0;
    $bodyOpen = null;
    for (; $k < $n; $k++) {
        $x = $tokens[$k]->text;
        if ($x === '(') { $paren++; continue; }
        if ($x === ')') { $paren--; continue; }
        if ($paren !== 0) { continue; }
        if ($x === ';') {
            return [$name, null, null, false, false, []]; // abstract or interface
        }
        if ($x === '{') { $bodyOpen = $k; break; }
    }
    if ($bodyOpen === null) {
        return [$name, null, null, false, false, []];
    }

    // Match braces.
    $d = 0;
    $bodyClose = null;
    for ($m = $bodyOpen; $m < $n; $m++) {
        $x = $tokens[$m]->text;
        if ($x === '{') { $d++; continue; }
        if ($x === '}') { $d--; if ($d === 0) { $bodyClose = $m; break; } }
    }
    if ($bodyClose === null) {
        return [$name, null, null, false, false, []];
    }

    $hasTrigger = false;
    $args = [];
    for ($m = $bodyOpen; $m <= $bodyClose; $m++) {
        $tk = $tokens[$m];
        if ($tk->id !== T_STRING && $tk->id !== T_NAME_FULLY_QUALIFIED) {
            continue;
        }
        if (strtolower(ltrim($tk->text, '\\')) !== 'trigger_deprecation') {
            continue;
        }
        for ($p = $m + 1; $p < $n; $p++) {
            if (tok_skippable($tokens[$p])) { continue; }
            if ($tokens[$p]->text === '(') { $hasTrigger = true; }
            break;
        }
        if (!$hasTrigger) { continue; }

        // First two arguments, only when they are plain string literals.
        // A dynamic argument leaves the slot null and the checker reports it as
        // unverifiable rather than as a violation.
        $depth = 0;
        for ($p = $m + 1; $p <= $bodyClose && count($args) < 2; $p++) {
            $x = $tokens[$p];
            if ($x->text === '(') { $depth++; continue; }
            if ($x->text === ')') { $depth--; if ($depth === 0) { break; } continue; }
            if ($depth !== 1) { continue; }
            if ($x->text === ',' || tok_skippable($x)) { continue; }

            if ($x->id === T_CONSTANT_ENCAPSED_STRING) {
                // A literal counts only when the argument ends immediately
                // after it. 'symfony/' . $name is an expression, not a literal,
                // and reading its first half would silently compare the check
                // against a wrong value.
                $q = $p + 1;
                while ($q <= $bodyClose && tok_skippable($tokens[$q])) { $q++; }
                $next = $tokens[$q]->text ?? '';
                if ($next === ',' || $next === ')') {
                    $args[] = trim($x->text, "'\"");
                    $p = $q - 1;
                    continue;
                }
            }

            // Anything else in an argument slot is an expression we will not
            // evaluate. Record it as unverifiable and move to the next slot.
            $args[] = null;
            $p = skip_to_next_argument($tokens, $p, $bodyClose);
        }
        break;
    }

    return [
        $name,
        $tokens[$bodyOpen]->line,
        $tokens[$bodyClose]->line,
        $hasTrigger,
        true,
        $args,
    ];
}
