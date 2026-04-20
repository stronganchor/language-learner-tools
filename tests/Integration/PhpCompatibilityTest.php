<?php
declare(strict_types=1);

final class PhpCompatibilityTest extends LL_Tools_TestCase
{
    public function test_php_version_support_helper_rejects_unsupported_versions(): void
    {
        $this->assertFalse(ll_tools_is_supported_php_version('7.4.33'));
        $this->assertTrue(ll_tools_is_supported_php_version('8.0.0'));
        $this->assertTrue(ll_tools_is_supported_php_version('8.3.7'));
    }

    public function test_php_requirement_notice_mentions_required_and_current_versions(): void
    {
        $message = ll_tools_get_unsupported_php_notice_message('7.4.33');

        $this->assertStringContainsString('PHP 8.0', $message);
        $this->assertStringContainsString('PHP 7.4.33', $message);
        $this->assertStringContainsString('fatal error', strtolower($message));
    }

    public function test_array_is_list_helper_supports_php_80_runtime(): void
    {
        $this->assertTrue(ll_tools_array_is_list([]));
        $this->assertTrue(ll_tools_array_is_list(['alpha', 'beta']));
        $this->assertFalse(ll_tools_array_is_list([1 => 'alpha']));
        $this->assertFalse(ll_tools_array_is_list(['slug' => 'alpha']));
    }
}
