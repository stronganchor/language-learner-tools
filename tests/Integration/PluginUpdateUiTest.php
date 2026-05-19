<?php
declare(strict_types=1);

class LL_Tools_Test_Fake_Update_Checker
{
    /** @var list<string> */
    public array $branches = [];

    public bool $resetCalled = false;

    public bool $checkCalled = false;

    public function setBranch($branch): void
    {
        $this->branches[] = (string) $branch;
    }

    public function resetUpdateState(): void
    {
        $this->resetCalled = true;
    }

    public function checkForUpdates(): void
    {
        $this->checkCalled = true;
    }
}

final class LL_Tools_Test_Fake_Update_Checker_With_Api extends LL_Tools_Test_Fake_Update_Checker
{
    public LL_Tools_Test_Fake_Vcs_Api $api;

    public function __construct()
    {
        $this->api = new LL_Tools_Test_Fake_Vcs_Api();
    }

    public function getVcsApi(): LL_Tools_Test_Fake_Vcs_Api
    {
        return $this->api;
    }
}

final class LL_Tools_Test_Fake_Vcs_Api
{
    public int $releaseAssetCalls = 0;

    public function enableReleaseAssets($assetNameRegex, $strategy): void
    {
        $this->releaseAssetCalls++;
    }
}

final class PluginUpdateUiTest extends LL_Tools_TestCase
{
    /** @var mixed */
    private $originalUpdateChecker;

    protected function setUp(): void
    {
        parent::setUp();

        global $ll_tools_update_checker;
        $this->originalUpdateChecker = $ll_tools_update_checker;

        delete_option('ll_update_branch');
    }

    protected function tearDown(): void
    {
        global $ll_tools_update_checker;
        $ll_tools_update_checker = $this->originalUpdateChecker;

        delete_option('ll_update_branch');

        parent::tearDown();
    }

    public function test_update_branch_option_normalizes_to_supported_values(): void
    {
        update_option('ll_update_branch', 'dev');
        $this->assertSame('dev', ll_tools_get_update_branch());

        update_option('ll_update_branch', 'feature-preview');
        $this->assertSame('main', ll_tools_get_update_branch());
        $this->assertSame('main', ll_tools_normalize_update_branch('anything-else'));
    }

    public function test_update_branch_change_reconfigures_live_update_checker(): void
    {
        global $ll_tools_update_checker;

        $fakeChecker = new LL_Tools_Test_Fake_Update_Checker();
        $ll_tools_update_checker = $fakeChecker;

        do_action('update_option_ll_update_branch', 'main', 'dev', 'll_update_branch');

        $this->assertSame(['dev'], $fakeChecker->branches);
        $this->assertTrue($fakeChecker->resetCalled);
        $this->assertTrue($fakeChecker->checkCalled);
    }

    public function test_dev_update_channel_does_not_require_release_assets(): void
    {
        $fakeChecker = new LL_Tools_Test_Fake_Update_Checker_With_Api();

        ll_tools_configure_update_checker($fakeChecker, 'dev');

        $this->assertSame(['dev'], $fakeChecker->branches);
        $this->assertSame(0, $fakeChecker->api->releaseAssetCalls);
    }

    public function test_main_update_channel_requires_release_assets(): void
    {
        $fakeChecker = new LL_Tools_Test_Fake_Update_Checker_With_Api();

        ll_tools_configure_update_checker($fakeChecker, 'main');

        $this->assertSame(['main'], $fakeChecker->branches);
        $this->assertSame(1, $fakeChecker->api->releaseAssetCalls);
    }

    public function test_update_management_urls_require_capability_and_include_expected_params(): void
    {
        $this->assertSame('', ll_tools_get_plugin_update_action_url());
        $this->assertSame('', ll_tools_get_plugin_update_check_action_url(home_url('/return/')));

        $adminId = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($adminId);

        $updateUrl = html_entity_decode(ll_tools_get_plugin_update_action_url());
        $updateQuery = wp_parse_args((string) wp_parse_url($updateUrl, PHP_URL_QUERY));

        $this->assertSame('upgrade-plugin', (string) ($updateQuery['action'] ?? ''));
        $this->assertSame(plugin_basename(LL_TOOLS_MAIN_FILE), (string) ($updateQuery['plugin'] ?? ''));
        $this->assertSame(
            1,
            wp_verify_nonce((string) ($updateQuery['_wpnonce'] ?? ''), 'upgrade-plugin_' . plugin_basename(LL_TOOLS_MAIN_FILE))
        );

        $checkUrl = html_entity_decode(ll_tools_get_plugin_update_check_action_url(home_url('/return/')));
        $checkQuery = wp_parse_args((string) wp_parse_url($checkUrl, PHP_URL_QUERY));

        $this->assertSame('ll_tools_check_plugin_update', (string) ($checkQuery['action'] ?? ''));
        $this->assertSame(home_url('/return/'), (string) ($checkQuery['redirect_to'] ?? ''));
        $this->assertSame(
            1,
            wp_verify_nonce((string) ($checkQuery['_wpnonce'] ?? ''), 'll_tools_check_plugin_update')
        );
    }
}
