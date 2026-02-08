<?php
declare(strict_types=1);

final class ApiSettingsCapabilityTest extends LL_Tools_TestCase
{
    public function test_default_api_settings_capability(): void
    {
        $this->assertTrue(function_exists('ll_tools_api_settings_capability'));
        $this->assertSame('manage_options', ll_tools_api_settings_capability());
    }

    public function test_api_settings_capability_filter_is_applied(): void
    {
        $filter = static function (): string {
            return 'view_ll_tools';
        };

        add_filter('ll_tools_api_settings_capability', $filter);
        try {
            $this->assertSame('view_ll_tools', ll_tools_api_settings_capability());
        } finally {
            remove_filter('ll_tools_api_settings_capability', $filter);
        }
    }
}
