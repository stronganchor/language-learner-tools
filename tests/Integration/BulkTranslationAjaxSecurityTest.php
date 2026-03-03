<?php
declare(strict_types=1);

final class BulkTranslationAjaxSecurityTest extends LL_Tools_TestCase
{
    /** @var array<string,mixed> */
    private $postBackup = [];

    /** @var array<string,mixed> */
    private $requestBackup = [];

    /** @var array<string,mixed> */
    private $getBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->postBackup = $_POST;
        $this->requestBackup = $_REQUEST;
        $this->getBackup = $_GET;
    }

    protected function tearDown(): void
    {
        $_POST = $this->postBackup;
        $_REQUEST = $this->requestBackup;
        $_GET = $this->getBackup;
        parent::tearDown();
    }

    public function test_fetch_requires_view_ll_tools_cap(): void
    {
        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'No Cap Word',
        ]);

        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($user_id);

        $_POST = [
            'nonce' => wp_create_nonce('ll-bulk-translations'),
            'ids' => [$word_id],
        ];
        $_REQUEST = $_POST;

        $response = $this->runJsonEndpoint(static function (): void {
            ll_ajax_bulk_translations_fetch();
        });

        $this->assertFalse((bool) ($response['success'] ?? true));
        $this->assertStringContainsString('permission', strtolower((string) (($response['data']['message'] ?? ''))));
    }

    public function test_fetch_rejects_selected_ids_when_none_are_editable(): void
    {
        $viewer_id = self::factory()->user->create(['role' => 'author']);
        $viewer = get_user_by('id', $viewer_id);
        $this->assertInstanceOf(WP_User::class, $viewer);
        $viewer->add_cap('view_ll_tools');
        clean_user_cache($viewer_id);

        $owner_id = self::factory()->user->create(['role' => 'author']);
        $other_word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_author' => $owner_id,
            'post_title' => 'Other Author Word',
        ]);

        wp_set_current_user($viewer_id);
        $_POST = [
            'nonce' => wp_create_nonce('ll-bulk-translations'),
            'ids' => [$other_word_id],
        ];
        $_REQUEST = $_POST;

        $response = $this->runJsonEndpoint(static function (): void {
            ll_ajax_bulk_translations_fetch();
        });

        $this->assertFalse((bool) ($response['success'] ?? true));
        $this->assertStringContainsString('selected items', strtolower((string) (($response['data']['message'] ?? ''))));
    }

    public function test_fetch_returns_only_editable_rows_from_mixed_selection(): void
    {
        $editor_id = self::factory()->user->create(['role' => 'author']);
        $editor = get_user_by('id', $editor_id);
        $this->assertInstanceOf(WP_User::class, $editor);
        $editor->add_cap('view_ll_tools');
        clean_user_cache($editor_id);

        $other_author_id = self::factory()->user->create(['role' => 'author']);
        $own_word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_author' => $editor_id,
            'post_title' => 'Own Editable Word',
        ]);
        $other_word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_author' => $other_author_id,
            'post_title' => 'Other Locked Word',
        ]);

        wp_set_current_user($editor_id);
        $_POST = [
            'nonce' => wp_create_nonce('ll-bulk-translations'),
            'ids' => [$own_word_id, $other_word_id],
        ];
        $_REQUEST = $_POST;

        $response = $this->runJsonEndpoint(static function (): void {
            ll_ajax_bulk_translations_fetch();
        });

        $this->assertTrue((bool) ($response['success'] ?? false));
        $rows = (array) (($response['data']['rows'] ?? []));
        $this->assertCount(1, $rows);
        $row = (array) ($rows[0] ?? []);
        $this->assertSame($own_word_id, (int) ($row['id'] ?? 0));
    }

    public function test_save_reports_skipped_for_non_editable_and_non_target_rows(): void
    {
        $editor_id = self::factory()->user->create(['role' => 'author']);
        $editor = get_user_by('id', $editor_id);
        $this->assertInstanceOf(WP_User::class, $editor);
        $editor->add_cap('view_ll_tools');
        clean_user_cache($editor_id);

        $other_author_id = self::factory()->user->create(['role' => 'author']);
        $own_word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_author' => $editor_id,
            'post_title' => 'Own Word Save',
        ]);
        $other_word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_author' => $other_author_id,
            'post_title' => 'Other Word Save',
        ]);
        $page_id = self::factory()->post->create([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_author' => $editor_id,
            'post_title' => 'Page Should Skip',
        ]);

        wp_set_current_user($editor_id);
        $_POST = [
            'nonce' => wp_create_nonce('ll-bulk-translations'),
            'rows' => [
                ['id' => $own_word_id, 'translation' => 'own translation'],
                ['id' => $other_word_id, 'translation' => 'other translation'],
                ['id' => $page_id, 'translation' => 'page translation'],
            ],
        ];
        $_REQUEST = $_POST;

        $response = $this->runJsonEndpoint(static function (): void {
            ll_ajax_bulk_translations_save();
        });

        $this->assertTrue((bool) ($response['success'] ?? false));
        $data = (array) ($response['data'] ?? []);
        $this->assertSame(1, (int) ($data['saved'] ?? 0));
        $this->assertSame(2, (int) ($data['skipped'] ?? 0));
        $this->assertSame('own translation', (string) get_post_meta($own_word_id, 'word_translation', true));
        $this->assertSame('', (string) get_post_meta($other_word_id, 'word_translation', true));
    }

    public function test_migrate_only_copies_legacy_meta_for_editable_posts(): void
    {
        $editor_id = self::factory()->user->create(['role' => 'author']);
        $editor = get_user_by('id', $editor_id);
        $this->assertInstanceOf(WP_User::class, $editor);
        $editor->add_cap('view_ll_tools');
        clean_user_cache($editor_id);

        $other_author_id = self::factory()->user->create(['role' => 'author']);
        $own_word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_author' => $editor_id,
            'post_title' => 'Own Legacy Word',
        ]);
        $other_word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_author' => $other_author_id,
            'post_title' => 'Other Legacy Word',
        ]);
        update_post_meta($own_word_id, 'word_english_meaning', 'legacy own');
        update_post_meta($other_word_id, 'word_english_meaning', 'legacy other');
        delete_post_meta($own_word_id, 'word_translation');
        delete_post_meta($other_word_id, 'word_translation');

        wp_set_current_user($editor_id);
        $_POST = [
            '_wpnonce' => wp_create_nonce('ll-bulk-translations'),
        ];
        $_REQUEST = $_POST;

        $redirect = $this->captureRedirect(static function (): void {
            ll_handle_bulk_translations_migrate();
        });
        $query = $this->parseRedirectQuery($redirect);

        $this->assertSame('1', (string) ($query['migrated'] ?? ''));
        $this->assertSame('legacy own', (string) get_post_meta($own_word_id, 'word_translation', true));
        $this->assertSame('', (string) get_post_meta($other_word_id, 'word_translation', true));
    }

    public function test_save_rejects_missing_nonce(): void
    {
        $editor_id = self::factory()->user->create(['role' => 'author']);
        $editor = get_user_by('id', $editor_id);
        $this->assertInstanceOf(WP_User::class, $editor);
        $editor->add_cap('view_ll_tools');
        clean_user_cache($editor_id);
        wp_set_current_user($editor_id);

        $_POST = [
            'rows' => [
                ['id' => 123, 'translation' => 'missing nonce value'],
            ],
        ];
        $_REQUEST = $_POST;

        $dieMessage = $this->runEndpointExpectWpDie(static function (): void {
            ll_ajax_bulk_translations_save();
        }, true);

        $this->assertSame('-1', $dieMessage);
    }

    /**
     * @return array<string,mixed>
     */
    private function runJsonEndpoint(callable $callback): array
    {
        $dieHandler = static function (): void {
            throw new RuntimeException('wp_die');
        };
        $dieFilter = static function () use ($dieHandler) {
            return $dieHandler;
        };
        $ajaxDieFilter = static function () use ($dieHandler) {
            return $dieHandler;
        };
        $doingAjaxFilter = static function (): bool {
            return true;
        };

        add_filter('wp_die_handler', $dieFilter);
        add_filter('wp_die_ajax_handler', $ajaxDieFilter);
        add_filter('wp_doing_ajax', $doingAjaxFilter);

        ob_start();
        try {
            $callback();
        } catch (RuntimeException $e) {
            $this->assertSame('wp_die', $e->getMessage());
        } finally {
            $output = (string) ob_get_clean();
            remove_filter('wp_die_handler', $dieFilter);
            remove_filter('wp_die_ajax_handler', $ajaxDieFilter);
            remove_filter('wp_doing_ajax', $doingAjaxFilter);
        }

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded, 'Expected JSON response payload.');
        return $decoded;
    }

    private function runEndpointExpectWpDie(callable $callback, bool $doingAjax): string
    {
        $captured = '';
        $dieHandler = static function ($message = '') use (&$captured): void {
            if (is_scalar($message)) {
                $captured = (string) $message;
            } else {
                $captured = '';
            }
            throw new RuntimeException('wp_die');
        };
        $dieFilter = static function () use ($dieHandler) {
            return $dieHandler;
        };
        $ajaxDieFilter = static function () use ($dieHandler) {
            return $dieHandler;
        };
        $doingAjaxFilter = static function () use ($doingAjax): bool {
            return $doingAjax;
        };

        add_filter('wp_die_handler', $dieFilter);
        add_filter('wp_die_ajax_handler', $ajaxDieFilter);
        add_filter('wp_doing_ajax', $doingAjaxFilter);

        try {
            $callback();
            $this->fail('Expected wp_die to be called.');
        } catch (RuntimeException $e) {
            $this->assertSame('wp_die', $e->getMessage());
        } finally {
            remove_filter('wp_die_handler', $dieFilter);
            remove_filter('wp_die_ajax_handler', $ajaxDieFilter);
            remove_filter('wp_doing_ajax', $doingAjaxFilter);
        }

        return $captured;
    }

    private function captureRedirect(callable $callback): string
    {
        $redirectUrl = '';
        $redirectFilter = static function ($location) use (&$redirectUrl) {
            $redirectUrl = (string) $location;
            throw new RuntimeException('redirect_intercepted');
        };
        add_filter('wp_redirect', $redirectFilter, 10, 1);

        try {
            $callback();
            $this->fail('Expected redirect.');
        } catch (RuntimeException $e) {
            $this->assertSame('redirect_intercepted', $e->getMessage());
        } finally {
            remove_filter('wp_redirect', $redirectFilter, 10);
        }

        $this->assertNotSame('', $redirectUrl);
        return $redirectUrl;
    }

    /**
     * @return array<string,string>
     */
    private function parseRedirectQuery(string $url): array
    {
        $query = (string) wp_parse_url($url, PHP_URL_QUERY);
        $decoded = [];
        parse_str($query, $decoded);
        return array_map('strval', $decoded);
    }
}
