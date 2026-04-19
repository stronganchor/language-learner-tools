<?php
declare(strict_types=1);

/**
 * WordPress' bundled test library still expects PHPUnit's removed legacy
 * annotation parser. Keep a minimal replacement in the plugin test bootstrap
 * and patch wordpress-tests-lib to call it when running on PHPUnit 12+.
 */
final class LL_Tools_PHPUnit_Compat
{
    /**
     * Recreates the legacy array shape that wordpress-tests-lib expects.
     *
     * @return array<string, array<string, array<int, string>>>
     */
    public static function parseTestMethodAnnotations(string $className, string $methodName): array
    {
        $class = new \ReflectionClass($className);

        $annotations = [
            'class'  => self::parseDocBlock((string) $class->getDocComment()),
            'method' => [],
        ];

        if ($class->hasMethod($methodName)) {
            $annotations['method'] = self::parseDocBlock((string) $class->getMethod($methodName)->getDocComment());
        }

        return $annotations;
    }

    /**
     * @return array<string, array<int, string>>
     */
    private static function parseDocBlock(string $docBlock): array
    {
        $annotations = [];

        foreach (preg_split('/\R/', $docBlock) ?: [] as $line) {
            if (!preg_match('/^\s*\*\s*@([A-Za-z_\\\\-]+)(?:\s+(.*?))?\s*$/', $line, $matches)) {
                continue;
            }

            $name = $matches[1];
            $value = trim($matches[2] ?? '');

            if (!array_key_exists($name, $annotations)) {
                $annotations[$name] = [];
            }

            $annotations[$name][] = $value;
        }

        return $annotations;
    }
}
