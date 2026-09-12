#!/usr/bin/env php
<?php
/**
 * Self test for the deprecation scanner. Run this BEFORE trusting the checker.
 *
 *   php bin/selftest.php
 *
 * Nine fixtures covering the cases that decide whether C1 fires on correct
 * code. The fourth through seventh are the ones that matter: a check that
 * demands a trigger_deprecation inside a bodyless declaration would fail valid
 * Symfony source, which is the fastest way to lose credibility in a demo.
 */

require __DIR__ . '/lib/tokens.php';

$cases = [];

$cases['method paired'] = [<<<'PHP'
<?php
class A {
    /**
     * @deprecated since Symfony 8.2, use newThing() instead.
     */
    public function oldThing(): void
    {
        trigger_deprecation('symfony/console', '8.2', 'oldThing() is deprecated.');
    }
}
PHP, ['kind' => 'method', 'checkable' => true, 'hasTrigger' => true]];

$cases['method unpaired'] = [<<<'PHP'
<?php
class A {
    /** @deprecated since Symfony 8.2 */
    public function oldThing(): void
    {
        return;
    }
}
PHP, ['kind' => 'method', 'checkable' => true, 'hasTrigger' => false]];

$cases['method paired via fully qualified call'] = [<<<'PHP'
<?php
class A {
    /** @deprecated since Symfony 8.2 */
    public function oldThing(): void
    {
        \trigger_deprecation('symfony/console', '8.2', 'x');
    }
}
PHP, ['kind' => 'method', 'checkable' => true, 'hasTrigger' => true]];

$cases['deprecated class is skipped'] = [<<<'PHP'
<?php
/** @deprecated since Symfony 8.2 */
final class Old {}
PHP, ['kind' => 'class_like', 'checkable' => false]];

$cases['deprecated interface method is skipped'] = [<<<'PHP'
<?php
interface I {
    /** @deprecated since Symfony 8.2 */
    public function gone(): void;
}
PHP, ['kind' => 'method', 'checkable' => false]];

$cases['deprecated abstract method is skipped'] = [<<<'PHP'
<?php
abstract class A {
    /** @deprecated since Symfony 8.2 */
    abstract protected function gone(): void;
}
PHP, ['kind' => 'method', 'checkable' => false]];

$cases['deprecated constant is skipped'] = [<<<'PHP'
<?php
class A {
    /** @deprecated since Symfony 8.2 */
    public const OLD = 1;
}
PHP, ['kind' => 'constant', 'checkable' => false]];

$cases['attributes between docblock and method'] = [<<<'PHP'
<?php
class A {
    /** @deprecated since Symfony 8.2 */
    #[Attr(name: 'x')]
    #[Other]
    public function oldThing(): void
    {
        trigger_deprecation('symfony/console', '8.2', 'x');
    }
}
PHP, ['kind' => 'method', 'checkable' => true, 'hasTrigger' => true]];

$cases['trigger inside a nested closure still counts'] = [<<<'PHP'
<?php
class A {
    /** @deprecated since Symfony 8.2 */
    public function oldThing(): void
    {
        $f = function () { trigger_deprecation('symfony/console', '8.2', 'x'); };
        $f();
    }
}
PHP, ['kind' => 'method', 'checkable' => true, 'hasTrigger' => true]];

$cases['undeprecated method with a trigger is ignored'] = [<<<'PHP'
<?php
class A {
    public function fine(): void
    {
        trigger_deprecation('symfony/console', '8.2', 'x');
    }
}
PHP, null];

$cases['DNF return type does not confuse body detection'] = [<<<'PHP'
<?php
class A {
    /** @deprecated since Symfony 8.2 */
    public function oldThing(): (Countable&ArrayAccess)|null
    {
        trigger_deprecation('symfony/console', '8.2', 'x');
        return null;
    }
}
PHP, ['kind' => 'method', 'checkable' => true, 'hasTrigger' => true]];

$cases['typed property is classified, not unknown'] = [<<<'PHP'
<?php
class A {
    /** @deprecated since Symfony 8.2 */
    public ParameterBag $attributes;
}
PHP, ['kind' => 'property', 'checkable' => false]];

$cases['nullable typed property'] = [<<<'PHP'
<?php
class A {
    /** @deprecated since Symfony 8.2 */
    protected ?string $old = null;
}
PHP, ['kind' => 'property', 'checkable' => false]];

$cases['internal deprecated method still reports as method'] = [<<<'PHP'
<?php
class A {
    /**
     * @internal
     *
     * @deprecated since Symfony 8.2, use renderFragment() instead
     */
    public function renderHIncludeFragment(string $uri): ?string
    {
        return $this->handler->render($uri, 'hinclude');
    }
}
PHP, ['kind' => 'method', 'checkable' => true, 'hasTrigger' => false]];

$cases['captures package and version literals'] = [<<<'PHP'
<?php
class A {
    /** @deprecated since Symfony 8.2 */
    public function oldThing(): void
    {
        trigger_deprecation('symfony/console', '8.2', 'oldThing() is deprecated.');
    }
}
PHP, ['kind' => 'method', 'hasTrigger' => true, 'package' => 'symfony/console', 'version' => '8.2']];

$cases['empty package and version are captured as empty'] = [<<<'PHP'
<?php
class A {
    /** @deprecated since Symfony 8.2 */
    public function oldThing(): void
    {
        trigger_deprecation('', '', 'The "%s" service relies on a deprecated class.', $id);
    }
}
PHP, ['kind' => 'method', 'hasTrigger' => true, 'package' => '', 'version' => '']];

$cases['dynamic package argument is null, not a false match'] = [<<<'PHP'
<?php
class A {
    /** @deprecated since Symfony 8.2 */
    public function oldThing(): void
    {
        trigger_deprecation($this->package, '8.2', 'x');
    }
}
PHP, ['kind' => 'method', 'hasTrigger' => true, 'package' => null, 'version' => '8.2']];

$cases['concatenated argument is not read as a literal'] = [<<<'PHP'
<?php
class A {
    /** @deprecated since Symfony 8.2 */
    public function oldThing(): void
    {
        trigger_deprecation('symfony/' . $name, '8.2', 'x');
    }
}
PHP, ['kind' => 'method', 'hasTrigger' => true, 'package' => null]];

$cases['nested call in the message does not steal the arguments'] = [<<<'PHP'
<?php
class A {
    /** @deprecated since Symfony 8.2 */
    public function oldThing(): void
    {
        trigger_deprecation('symfony/console', '8.2', sprintf('%s is gone', 'old'));
    }
}
PHP, ['kind' => 'method', 'package' => 'symfony/console', 'version' => '8.2']];

$cases['asymmetric visibility property is classified'] = [<<<'PHP'
<?php
class A {
    /** @deprecated since Symfony 8.2 */
    public private(set) ?ControllerArgumentsEvent $event;
}
PHP, ['kind' => 'property', 'checkable' => false]];

$cases['hooked property is still reported as a property'] = [<<<'PHP'
<?php
class A {
    /** @deprecated since Symfony 8.2 */
    public private(set) ?string $legacy {
        get => $this->value;
    }
}
PHP, ['kind' => 'property', 'checkable' => false]];

$fails = 0;
foreach ($cases as $name => [$code, $expect]) {
    $got = scan_deprecations($code);
    if ($expect === null) {
        $ok = $got === [];
        report($name, $ok, $ok ? '' : 'expected no entries, got ' . count($got));
        $fails += $ok ? 0 : 1;
        continue;
    }
    if ($got === []) {
        report($name, false, 'expected one entry, got none');
        $fails++;
        continue;
    }
    $e = $got[0];
    $bad = [];
    foreach ($expect as $k => $v) {
        if (($e[$k] ?? null) !== $v) {
            $bad[] = sprintf('%s expected %s got %s', $k,
                var_export($v, true), var_export($e[$k] ?? null, true));
        }
    }
    report($name, $bad === [], implode('; ', $bad));
    $fails += $bad === [] ? 0 : 1;
}

printf("\n%d of %d passed\n", count($cases) - $fails, count($cases));
exit($fails === 0 ? 0 : 1);

function report(string $name, bool $ok, string $detail): void
{
    printf("%-46s %s%s\n", $name, $ok ? 'ok' : 'FAIL', $detail ? '  ' . $detail : '');
}
