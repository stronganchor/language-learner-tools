<?php
declare(strict_types=1);

final class TemplateLoaderTest extends LL_Tools_TestCase
{
    private string $tmpRoot = '';

    /** @var callable|null */
    private $stylesheetDirFilter = null;

    /** @var callable|null */
    private $templateDirFilter = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . 'll-tools-template-loader-test-'
            . uniqid('', true);

        $this->mkdir($this->tmpRoot);
    }

    protected function tearDown(): void
    {
        if ($this->stylesheetDirFilter !== null) {
            remove_filter('stylesheet_directory', $this->stylesheetDirFilter);
            $this->stylesheetDirFilter = null;
        }
        if ($this->templateDirFilter !== null) {
            remove_filter('template_directory', $this->templateDirFilter);
            $this->templateDirFilter = null;
        }

        $this->rrmdir($this->tmpRoot);
        $this->tmpRoot = '';

        parent::tearDown();
    }

    public function test_locate_template_prefers_child_then_parent_then_plugin(): void
    {
        $relative = 'flashcard-widget-template.php';
        $childBase = $this->tmpRoot . DIRECTORY_SEPARATOR . 'child-theme';
        $parentBase = $this->tmpRoot . DIRECTORY_SEPARATOR . 'parent-theme';

        $childTemplate = $childBase . DIRECTORY_SEPARATOR . 'll-tools' . DIRECTORY_SEPARATOR . $relative;
        $parentTemplate = $parentBase . DIRECTORY_SEPARATOR . 'll-tools' . DIRECTORY_SEPARATOR . $relative;

        $this->writeFile($childTemplate, "<?php echo 'child';");
        $this->writeFile($parentTemplate, "<?php echo 'parent';");

        $this->stylesheetDirFilter = static function () use ($childBase): string {
            return $childBase;
        };
        $this->templateDirFilter = static function () use ($parentBase): string {
            return $parentBase;
        };
        add_filter('stylesheet_directory', $this->stylesheetDirFilter);
        add_filter('template_directory', $this->templateDirFilter);

        $this->assertSame($this->normalizePath($childTemplate), $this->normalizePath(ll_tools_locate_template($relative)));

        unlink($childTemplate);
        $this->assertSame($this->normalizePath($parentTemplate), $this->normalizePath(ll_tools_locate_template($relative)));

        unlink($parentTemplate);
        $this->assertSame(
            $this->normalizePath(ll_tools_templates_dir() . $relative),
            $this->normalizePath(ll_tools_locate_template($relative))
        );
    }

    public function test_locate_template_sanitizes_traversal_before_search_path_filter(): void
    {
        $seenRelative = null;
        $filter = static function ($candidates, $relative) use (&$seenRelative) {
            $seenRelative = $relative;
            return [
                null,
                ['invalid'],
                ll_tools_templates_dir() . (string) $relative,
            ];
        };

        add_filter('ll_tools_template_search_paths', $filter, 10, 2);
        try {
            $resolved = ll_tools_locate_template('../flashcard-widget-template.php');
        } finally {
            remove_filter('ll_tools_template_search_paths', $filter, 10);
        }

        $this->assertSame('flashcard-widget-template.php', $seenRelative);
        $this->assertSame(
            $this->normalizePath(ll_tools_templates_dir() . 'flashcard-widget-template.php'),
            $this->normalizePath($resolved)
        );
    }

    public function test_locate_template_rejects_empty_or_directory_like_relative_path(): void
    {
        $this->assertSame('', ll_tools_locate_template(''));
        $this->assertSame('', ll_tools_locate_template('../'));
        $this->assertSame('', ll_tools_locate_template('subdir/'));
    }

    private function mkdir(string $dir): void
    {
        if ($dir === '') {
            return;
        }
        if (is_dir($dir)) {
            return;
        }
        mkdir($dir, 0777, true);
    }

    private function writeFile(string $path, string $contents): void
    {
        $dir = dirname($path);
        $this->mkdir($dir);
        file_put_contents($path, $contents);
    }

    private function rrmdir(string $dir): void
    {
        if ($dir === '' || !is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if (!is_array($items)) {
            @rmdir($dir);
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->rrmdir($path);
                continue;
            }
            @unlink($path);
        }

        @rmdir($dir);
    }

    private function normalizePath(string $path): string
    {
        return str_replace('\\', '/', $path);
    }
}
