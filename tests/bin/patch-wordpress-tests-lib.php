#!/usr/bin/env php
<?php
declare(strict_types=1);

if ($argc < 2 || $argv[1] === '') {
    fwrite(STDERR, "Usage: patch-wordpress-tests-lib.php <wp-tests-dir>\n");
    exit(1);
}

$testsDir = rtrim($argv[1], "/\\");

$patches = [
    $testsDir . '/includes/abstract-testcase.php' => [
        [
            <<<'OLD'
			$annotations = \PHPUnit\Util\Test::parseTestMethodAnnotations(
				static::class,
				$this->getName( false )
			);
OLD,
            <<<'NEW'
			$annotations = method_exists( \PHPUnit\Util\Test::class, 'parseTestMethodAnnotations' )
				? \PHPUnit\Util\Test::parseTestMethodAnnotations(
					static::class,
					\LL_Tools_PHPUnit_Compat::getTestMethodName( $this )
				)
				: \LL_Tools_PHPUnit_Compat::parseTestMethodAnnotations(
					static::class,
					\LL_Tools_PHPUnit_Compat::getTestMethodName( $this )
				);
NEW,
        ],
        [
            <<<'OLD'
			$annotations = method_exists( \PHPUnit\Util\Test::class, 'parseTestMethodAnnotations' )
				? \PHPUnit\Util\Test::parseTestMethodAnnotations(
					static::class,
					$this->getName( false )
				)
				: \LL_Tools_PHPUnit_Compat::parseTestMethodAnnotations(
					static::class,
					$this->getName( false )
				);
OLD,
            <<<'NEW'
			$annotations = method_exists( \PHPUnit\Util\Test::class, 'parseTestMethodAnnotations' )
				? \PHPUnit\Util\Test::parseTestMethodAnnotations(
					static::class,
					\LL_Tools_PHPUnit_Compat::getTestMethodName( $this )
				)
				: \LL_Tools_PHPUnit_Compat::parseTestMethodAnnotations(
					static::class,
					\LL_Tools_PHPUnit_Compat::getTestMethodName( $this )
				);
NEW,
        ],
    ],
    $testsDir . '/includes/phpunit6/compat.php' => [
        [
            <<<'OLD'
			$annotations = PHPUnit\Util\Test::parseTestMethodAnnotations( $class_name, $method_name );
OLD,
            <<<'NEW'
			$annotations = method_exists( PHPUnit\Util\Test::class, 'parseTestMethodAnnotations' )
				? PHPUnit\Util\Test::parseTestMethodAnnotations( $class_name, $method_name )
				: LL_Tools_PHPUnit_Compat::parseTestMethodAnnotations( $class_name, $method_name );
NEW,
        ],
    ],
];

foreach ($patches as $path => $replacements) {
    if (!is_file($path)) {
        continue;
    }

    $contents = file_get_contents($path);
    if ($contents === false) {
        fwrite(STDERR, "Failed to read {$path}\n");
        exit(1);
    }

    foreach ($replacements as [$old, $new]) {
        if (strpos($contents, $new) !== false) {
            continue;
        }

        if (strpos($contents, $old) === false) {
            continue;
        }

        $updated = str_replace($old, $new, $contents);
        if ($updated === $contents) {
            continue;
        }

        $contents = $updated;
    }

    if (file_put_contents($path, $contents) === false) {
        fwrite(STDERR, "Failed to patch {$path}\n");
        exit(1);
    }
}
