<?php
declare(strict_types=1);

final class DictionaryInlineEditTest extends LL_Tools_TestCase
{
    /** @var array<string,mixed> */
    private $getBackup = [];

    /** @var array<string,mixed> */
    private $postBackup = [];

    /** @var array<string,mixed> */
    private $requestBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->getBackup = $_GET;
        $this->postBackup = $_POST;
        $this->requestBackup = $_REQUEST;
    }

    protected function tearDown(): void
    {
        $_GET = $this->getBackup;
        $_POST = $this->postBackup;
        $_REQUEST = $this->requestBackup;

        parent::tearDown();
    }

    public function test_admin_detail_view_renders_inline_entry_editor_but_public_view_does_not(): void
    {
        $admin_id = $this->createDictionaryAdminUser();
        $entry_id = $this->createDictionaryEntryFixture('Dar', 'tree');

        wp_set_current_user($admin_id);
        $_GET = [
            'll_dictionary_entry' => (string) $entry_id,
        ];

        $admin_html = do_shortcode('[ll_dictionary]');
        $this->assertStringContainsString('data-ll-dictionary-inline-editor', $admin_html);
        $this->assertStringContainsString('data-ll-dictionary-review-state', $admin_html);
        $this->assertStringContainsString('Open in admin', $admin_html);

        wp_set_current_user(0);
        $_GET = [
            'll_dictionary_entry' => (string) $entry_id,
        ];

        $public_html = do_shortcode('[ll_dictionary]');
        $this->assertStringNotContainsString('data-ll-dictionary-inline-editor', $public_html);
        $this->assertStringNotContainsString('data-ll-dictionary-review-state', $public_html);
        $this->assertStringNotContainsString('Open in admin', $public_html);
    }

    public function test_inline_entry_update_handler_updates_title_without_changing_slug(): void
    {
        $admin_id = $this->createDictionaryAdminUser();
        $entry_id = $this->createDictionaryEntryFixture('Dar', 'tree');
        $original_slug = (string) get_post_field('post_name', $entry_id);

        wp_set_current_user($admin_id);
        $_POST = [
            'entry_id' => $entry_id,
            'nonce' => wp_create_nonce('ll_dictionary_entry_inline_edit_' . $entry_id),
            'update_type' => 'title',
            'title' => 'Dara',
        ];
        $_REQUEST = $_POST;

        $response = $this->runJsonEndpoint(static function (): void {
            ll_tools_dictionary_handle_entry_update();
        });

        $this->assertTrue((bool) ($response['success'] ?? false));
        $this->assertSame('Dara', (string) get_the_title($entry_id));
        $this->assertSame($original_slug, (string) get_post_field('post_name', $entry_id));
        $this->assertFalse(ll_tools_dictionary_entry_has_review_flag($entry_id));
        $this->assertSame('dara', (string) get_post_meta($entry_id, LL_TOOLS_DICTIONARY_ENTRY_LOOKUP_TITLE_META_KEY, true));

        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        $this->assertSame('Dara', (string) ($data['title'] ?? ''));
        $this->assertFalse((bool) ($data['needs_review'] ?? false));
        $this->assertSame('Reviewed', (string) ($data['review_label'] ?? ''));
    }

    public function test_inline_entry_update_handler_toggles_review_flag(): void
    {
        $admin_id = $this->createDictionaryAdminUser();
        $entry_id = $this->createDictionaryEntryFixture('Dar', 'tree');

        wp_set_current_user($admin_id);
        $_POST = [
            'entry_id' => $entry_id,
            'nonce' => wp_create_nonce('ll_dictionary_entry_inline_edit_' . $entry_id),
            'update_type' => 'review',
            'needs_review' => '1',
        ];
        $_REQUEST = $_POST;

        $response = $this->runJsonEndpoint(static function (): void {
            ll_tools_dictionary_handle_entry_update();
        });

        $this->assertTrue((bool) ($response['success'] ?? false));
        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        $this->assertTrue((bool) ($data['needs_review'] ?? false));
        $this->assertSame('Needs review', (string) ($data['review_label'] ?? ''));
        $this->assertTrue(ll_tools_dictionary_entry_has_review_flag($entry_id));
        $this->assertSame('needs_review', (string) (ll_tools_get_dictionary_entry_senses($entry_id)[0]['needs_review'] ?? ''));
    }

    public function test_inline_entry_update_handler_updates_definition_in_structured_senses(): void
    {
        $admin_id = $this->createDictionaryAdminUser();
        $entry_id = $this->createDictionaryEntryFixture('Dar', 'tree');

        wp_set_current_user($admin_id);
        $_POST = [
            'entry_id' => $entry_id,
            'nonce' => wp_create_nonce('ll_dictionary_entry_inline_edit_' . $entry_id),
            'update_type' => 'sense',
            'sense_index' => '0',
            'language' => 'en',
            'value' => 'A tall tree',
        ];
        $_REQUEST = $_POST;

        $response = $this->runJsonEndpoint(static function (): void {
            ll_tools_dictionary_handle_entry_update();
        });

        $this->assertTrue((bool) ($response['success'] ?? false));

        $senses = ll_tools_get_dictionary_entry_senses($entry_id);
        $this->assertSame('A tall tree', (string) ($senses[0]['definition'] ?? ''));
        $this->assertSame('A tall tree', (string) (($senses[0]['translations'] ?? [])['en'] ?? ''));
        $this->assertStringContainsString('A tall tree', trim((string) get_post_field('post_content', $entry_id)));
        $this->assertSame('A tall tree', (string) get_post_meta($entry_id, LL_TOOLS_DICTIONARY_ENTRY_TRANSLATION_META_KEY, true));

        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        $this->assertSame('A tall tree', (string) ($data['value'] ?? ''));
        $this->assertSame('A tall tree', (string) ($data['summary'] ?? ''));
    }

    public function test_view_ll_tools_user_without_edit_cap_cannot_update_dictionary_entry(): void
    {
        $admin_id = $this->createDictionaryAdminUser();
        $entry_id = $this->createDictionaryEntryFixture('Dar', 'tree');
        $viewer_id = self::factory()->user->create(['role' => 'subscriber']);
        $viewer = get_user_by('id', $viewer_id);

        $this->assertInstanceOf(WP_User::class, $viewer);
        $viewer->add_cap('view_ll_tools');
        clean_user_cache($viewer_id);

        wp_set_current_user($viewer_id);
        $_POST = [
            'entry_id' => $entry_id,
            'nonce' => wp_create_nonce('ll_dictionary_entry_inline_edit_' . $entry_id),
            'update_type' => 'title',
            'title' => 'Blocked',
        ];
        $_REQUEST = $_POST;

        $response = $this->runJsonEndpoint(static function (): void {
            ll_tools_dictionary_handle_entry_update();
        });

        $this->assertFalse((bool) ($response['success'] ?? true));
        $this->assertSame('Dar', (string) get_the_title($entry_id));
        $this->assertFalse(ll_tools_dictionary_entry_has_review_flag($entry_id));

        wp_set_current_user($admin_id);
    }

    private function createDictionaryAdminUser(): int
    {
        $user_id = self::factory()->user->create(['role' => 'administrator']);
        $user = get_user_by('id', $user_id);

        $this->assertInstanceOf(WP_User::class, $user);
        $user->add_cap('view_ll_tools');
        clean_user_cache($user_id);

        return $user_id;
    }

    private function createDictionaryEntryFixture(string $title, string $definition): int
    {
        $entry_id = self::factory()->post->create([
            'post_type' => 'll_dictionary_entry',
            'post_status' => 'publish',
            'post_title' => $title,
            'post_content' => $definition,
        ]);

        update_post_meta($entry_id, LL_TOOLS_DICTIONARY_ENTRY_SENSES_META_KEY, [[
            'definition' => $definition,
            'entry_type' => 'noun',
            'entry_lang' => 'zza',
            'def_lang' => 'en',
            'needs_review' => '',
        ]]);

        ll_tools_dictionary_refresh_entry_search_meta($entry_id);
        ll_tools_dictionary_entry_set_review_flag($entry_id, false);

        return $entry_id;
    }

    /**
     * @return array<string,mixed>
     */
    private function runJsonEndpoint(callable $callback): array
    {
        $die_handler = static function (): void {
            throw new RuntimeException('wp_die');
        };
        $die_filter = static function () use ($die_handler) {
            return $die_handler;
        };
        $die_ajax_filter = static function () use ($die_handler) {
            return $die_handler;
        };
        $doing_ajax_filter = static function (): bool {
            return true;
        };

        add_filter('wp_die_handler', $die_filter);
        add_filter('wp_die_ajax_handler', $die_ajax_filter);
        add_filter('wp_doing_ajax', $doing_ajax_filter);

        ob_start();
        try {
            $callback();
        } catch (RuntimeException $e) {
            $this->assertSame('wp_die', $e->getMessage());
        } finally {
            $output = (string) ob_get_clean();
            remove_filter('wp_die_handler', $die_filter);
            remove_filter('wp_die_ajax_handler', $die_ajax_filter);
            remove_filter('wp_doing_ajax', $doing_ajax_filter);
        }

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded, 'Expected JSON response payload.');

        return $decoded;
    }
}
