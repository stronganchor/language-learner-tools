<?php
declare(strict_types=1);

final class WordsetScopedCategoryLookupTest extends LL_Tools_TestCase
{
    private const ONE_PIXEL_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+tmP8AAAAASUVORK5CYII=';

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
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);

        parent::tearDown();
    }

    public function test_unscoped_selector_labels_duplicate_isolated_categories_and_scoped_selector_filters_to_one_wordset(): void
    {
        $fixture = $this->createScopedCategoryFixture();

        $all_rows = ll_tools_get_word_category_selector_rows(0, [
            'post_types' => ['words'],
            'post_statuses' => ['publish'],
        ]);
        $all_labels = array_values(array_map(static function (array $row): string {
            return (string) ($row['label'] ?? '');
        }, $all_rows));

        $this->assertContains('Shared Trees - Scope One', $all_labels);
        $this->assertContains('Shared Trees - Scope Two', $all_labels);

        $scoped_rows = ll_tools_get_word_category_selector_rows((int) $fixture['wordset_one_id'], [
            'post_types' => ['words'],
            'post_statuses' => ['publish'],
        ]);
        $scoped_ids = array_values(array_map(static function (array $row): int {
            return (int) ($row['id'] ?? 0);
        }, $scoped_rows));
        $scoped_labels = array_values(array_map(static function (array $row): string {
            return (string) ($row['label'] ?? '');
        }, $scoped_rows));

        $this->assertContains((int) $fixture['isolated_one_id'], $scoped_ids);
        $this->assertNotContains((int) $fixture['isolated_two_id'], $scoped_ids);
        $this->assertContains('Shared Trees', $scoped_labels);
    }

    public function test_legacy_category_slug_resolves_to_selected_wordset_copy_across_scoped_lookup_paths(): void
    {
        $fixture = $this->createScopedCategoryFixture();

        $editor_payload = ll_tools_editor_hub_get_category_items_payload(
            (int) $fixture['wordset_one_id'],
            (string) $fixture['source_category_slug']
        );
        $editor_items = is_array($editor_payload['items'] ?? null) ? $editor_payload['items'] : [];

        $this->assertSame((string) $fixture['isolated_one_slug'], (string) ($editor_payload['selected_category'] ?? ''));
        $this->assertCount(1, $editor_items);
        $this->assertSame((int) $fixture['word_one_id'], (int) ($editor_items[0]['word_id'] ?? 0));

        $context = ll_tools_word_grid_resolve_context([
            'category' => (string) $fixture['source_category_slug'],
            'wordset' => (string) $fixture['wordset_one_slug'],
        ]);
        $context_term = $context['category_term'] ?? null;

        $this->assertInstanceOf(WP_Term::class, $context_term);
        $this->assertSame((int) $fixture['isolated_one_id'], (int) $context_term->term_id);
        $this->assertSame((string) $fixture['isolated_one_slug'], (string) ($context['sanitized_category'] ?? ''));

        $lesson_id = self::factory()->post->create([
            'post_type' => 'll_vocab_lesson',
            'post_status' => 'publish',
            'post_title' => 'Scoped Lesson',
        ]);
        update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_WORDSET_META, (int) $fixture['wordset_one_id']);
        update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, (int) $fixture['isolated_one_id']);

        $resolved_lesson_id = ll_tools_find_vocab_lesson_post_id(
            (string) $fixture['wordset_one_slug'],
            (string) $fixture['source_category_slug']
        );

        $this->assertSame($lesson_id, $resolved_lesson_id);
    }

    public function test_bulk_translation_category_filter_scopes_to_selected_wordset(): void
    {
        $fixture = $this->createScopedCategoryFixture();
        $this->setCurrentUserWithViewCapability();

        $_GET = [
            'page' => 'll-bulk-translations',
            'wordset' => (string) $fixture['wordset_one_slug'],
        ];

        ob_start();
        ll_render_bulk_translations_page();
        $html = (string) ob_get_clean();

        $options = $this->extractSelectOptions($html, 'll-bulk-translations-category-filter');

        $this->assertArrayHasKey('0', $options);
        $this->assertArrayHasKey((string) $fixture['isolated_one_id'], $options);
        $this->assertArrayNotHasKey((string) $fixture['isolated_two_id'], $options);
        $this->assertSame('Shared Trees', (string) $options[(string) $fixture['isolated_one_id']]);
    }

    public function test_audio_image_matcher_category_filter_scopes_to_selected_wordset(): void
    {
        $fixture = $this->createScopedCategoryFixture();
        $this->setCurrentUserWithViewCapability();

        $_GET = [
            'page' => 'll-audio-image-matcher',
            'wordset_id' => (string) $fixture['wordset_one_id'],
        ];

        ob_start();
        ll_render_audio_image_matcher_page();
        $html = (string) ob_get_clean();

        $options = $this->extractSelectOptions($html, 'll-aim-category');

        $this->assertArrayHasKey('', $options);
        $this->assertArrayHasKey((string) $fixture['isolated_one_id'], $options);
        $this->assertArrayNotHasKey((string) $fixture['isolated_two_id'], $options);
        $this->assertSame('Shared Trees', (string) $options[(string) $fixture['isolated_one_id']]);
    }

    public function test_audio_image_matcher_limits_recorder_view_to_assigned_wordset(): void
    {
        $fixture = $this->createScopedCategoryFixture();
        $this->setCurrentRecorderForWordset((string) $fixture['wordset_one_slug']);

        $_GET = [
            'page' => 'll-audio-image-matcher',
        ];

        ob_start();
        ll_render_audio_image_matcher_page();
        $html = (string) ob_get_clean();

        $category_options = $this->extractSelectOptions($html, 'll-aim-category');
        $wordset_options = $this->extractSelectOptions($html, 'll-aim-wordset');

        $this->assertArrayHasKey((string) $fixture['isolated_one_id'], $category_options);
        $this->assertArrayNotHasKey((string) $fixture['isolated_two_id'], $category_options);
        $this->assertArrayHasKey((string) $fixture['wordset_one_id'], $wordset_options);
        $this->assertArrayNotHasKey((string) $fixture['wordset_two_id'], $wordset_options);
    }

    public function test_audio_image_matcher_ajax_rejects_recorder_unassigned_wordset(): void
    {
        $fixture = $this->createScopedCategoryFixture();
        $this->setCurrentRecorderForWordset((string) $fixture['wordset_one_slug']);

        $_GET = [
            'nonce' => wp_create_nonce('ll_aim_admin'),
            'wordset_id' => (string) $fixture['wordset_two_id'],
            'term_id' => (string) $fixture['isolated_two_id'],
        ];
        $_REQUEST = $_GET;

        $category_response = $this->runJsonEndpoint(static function (): void {
            ll_aim_get_category_options_handler();
        });
        $this->assertFalse((bool) ($category_response['success'] ?? true));
        $this->assertSame('Forbidden', (string) ($category_response['data'] ?? ''));

        $images_response = $this->runJsonEndpoint(static function (): void {
            ll_aim_get_images_handler();
        });
        $this->assertFalse((bool) ($images_response['success'] ?? true));
        $this->assertSame('Forbidden', (string) ($images_response['data'] ?? ''));

        $next_response = $this->runJsonEndpoint(static function (): void {
            ll_aim_get_next_handler();
        });
        $this->assertFalse((bool) ($next_response['success'] ?? true));
        $this->assertSame('Forbidden', (string) ($next_response['data'] ?? ''));
    }

    public function test_audio_image_matcher_assign_rejects_manager_unassigned_word_or_image(): void
    {
        $fixture = $this->createScopedCategoryFixture();
        $manager_id = $this->setCurrentManagerForWordset((int) $fixture['wordset_one_id']);

        $allowed_word_id = $this->createWordInScope(
            'Assignable Scope One Word',
            (int) $fixture['wordset_one_id'],
            (int) $fixture['isolated_one_id'],
            $manager_id
        );
        $blocked_word_id = $this->createWordInScope(
            'Assignable Scope Two Word',
            (int) $fixture['wordset_two_id'],
            (int) $fixture['isolated_two_id'],
            $manager_id
        );
        $allowed_image = $this->createWordImageInScope(
            'Assignable Scope One Image',
            (int) $fixture['wordset_one_id'],
            (int) $fixture['isolated_one_id'],
            $manager_id
        );
        $blocked_image = $this->createWordImageInScope(
            'Assignable Scope Two Image',
            (int) $fixture['wordset_two_id'],
            (int) $fixture['isolated_two_id'],
            $manager_id
        );

        $blocked_word_response = $this->runAssignRequest($blocked_word_id, (int) $allowed_image['image_id']);
        $this->assertFalse((bool) ($blocked_word_response['success'] ?? true));
        $this->assertSame('Forbidden', (string) ($blocked_word_response['data'] ?? ''));
        $this->assertSame(0, (int) get_post_thumbnail_id($blocked_word_id));

        $blocked_image_response = $this->runAssignRequest($allowed_word_id, (int) $blocked_image['image_id']);
        $this->assertFalse((bool) ($blocked_image_response['success'] ?? true));
        $this->assertSame('Forbidden', (string) ($blocked_image_response['data'] ?? ''));
        $this->assertSame(0, (int) get_post_thumbnail_id($allowed_word_id));

        $allowed_response = $this->runAssignRequest($allowed_word_id, (int) $allowed_image['image_id']);
        $this->assertTrue((bool) ($allowed_response['success'] ?? false));
        $this->assertSame((int) $allowed_image['attachment_id'], (int) get_post_thumbnail_id($allowed_word_id));
    }

    /**
     * @return array<string,int|string>
     */
    private function createScopedCategoryFixture(): array
    {
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);

        $wordset_one_id = $this->ensureTerm('wordset', 'Scope One', 'scope-one');
        $wordset_two_id = $this->ensureTerm('wordset', 'Scope Two', 'scope-two');
        $source_category_id = $this->ensureTerm('word-category', 'Shared Trees', 'shared-trees');

        $isolated_one_id = (int) ll_tools_get_or_create_isolated_category_copy($source_category_id, $wordset_one_id);
        $isolated_two_id = (int) ll_tools_get_or_create_isolated_category_copy($source_category_id, $wordset_two_id);

        $word_one_id = $this->createWordInScope('Scope One Tree', $wordset_one_id, $isolated_one_id);
        $this->createWordInScope('Scope Two Tree', $wordset_two_id, $isolated_two_id);

        $isolated_one = get_term($isolated_one_id, 'word-category');
        $this->assertInstanceOf(WP_Term::class, $isolated_one);

        return [
            'wordset_one_id' => $wordset_one_id,
            'wordset_one_slug' => 'scope-one',
            'isolated_one_id' => $isolated_one_id,
            'isolated_one_slug' => (string) $isolated_one->slug,
            'wordset_two_id' => $wordset_two_id,
            'wordset_two_slug' => 'scope-two',
            'isolated_two_id' => $isolated_two_id,
            'source_category_slug' => 'shared-trees',
            'word_one_id' => $word_one_id,
        ];
    }

    private function ensureTerm(string $taxonomy, string $name, string $slug): int
    {
        $existing = term_exists($slug, $taxonomy);
        if (is_array($existing) && !empty($existing['term_id'])) {
            return (int) $existing['term_id'];
        }
        if (is_int($existing) && $existing > 0) {
            return $existing;
        }

        $inserted = wp_insert_term($name, $taxonomy, ['slug' => $slug]);
        $this->assertIsArray($inserted);

        return (int) $inserted['term_id'];
    }

    private function createWordInScope(string $title, int $wordset_id, int $category_id, int $author_id = 0): int
    {
        $args = [
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => $title,
        ];
        if ($author_id > 0) {
            $args['post_author'] = $author_id;
        }

        $word_id = self::factory()->post->create($args);

        wp_set_object_terms($word_id, [$wordset_id], 'wordset', false);
        wp_set_object_terms($word_id, [$category_id], 'word-category', false);

        return $word_id;
    }

    private function setCurrentUserWithViewCapability(): void
    {
        $user_id = self::factory()->user->create(['role' => 'administrator']);
        $user = get_user_by('id', $user_id);
        $this->assertInstanceOf(WP_User::class, $user);
        $user->add_cap('view_ll_tools');
        clean_user_cache($user_id);
        wp_set_current_user($user_id);
    }

    private function setCurrentRecorderForWordset(string $wordsetSlug): void
    {
        ll_tools_register_or_refresh_audio_recorder_role();

        $user_id = self::factory()->user->create(['role' => 'audio_recorder']);
        update_user_meta($user_id, 'll_recording_config', [
            'wordset' => $wordsetSlug,
            'category' => '',
        ]);
        wp_set_current_user($user_id);
    }

    private function setCurrentManagerForWordset(int $wordset_id): int
    {
        ll_create_wordset_manager_role();
        if (function_exists('ll_ensure_wordset_manager_has_view_ll_tools_cap')) {
            ll_ensure_wordset_manager_has_view_ll_tools_cap();
        }

        $user_id = self::factory()->user->create(['role' => 'wordset_manager']);
        if (function_exists('ll_tools_cli_assign_wordset_manager')) {
            ll_tools_cli_assign_wordset_manager($wordset_id, $user_id);
        } else {
            update_term_meta($wordset_id, 'manager_user_id', $user_id);
            update_user_meta($user_id, 'managed_wordsets', [$wordset_id]);
        }
        wp_set_current_user($user_id);

        return (int) $user_id;
    }

    /**
     * @return array{image_id:int,attachment_id:int}
     */
    private function createWordImageInScope(string $title, int $wordset_id, int $category_id, int $author_id): array
    {
        $attachment_id = $this->createImageAttachment(sanitize_title($title) . '.png');
        $image_id = self::factory()->post->create([
            'post_type' => 'word_images',
            'post_status' => 'publish',
            'post_title' => $title,
            'post_author' => $author_id,
        ]);
        wp_set_object_terms($image_id, [$category_id], 'word-category', false);
        set_post_thumbnail($image_id, $attachment_id);
        if (function_exists('ll_tools_set_word_image_wordset_owner')) {
            ll_tools_set_word_image_wordset_owner((int) $image_id, $wordset_id, (int) $image_id);
        } elseif (defined('LL_TOOLS_WORD_IMAGE_WORDSET_OWNER_META_KEY')) {
            update_post_meta($image_id, LL_TOOLS_WORD_IMAGE_WORDSET_OWNER_META_KEY, $wordset_id);
        }

        return [
            'image_id' => (int) $image_id,
            'attachment_id' => (int) $attachment_id,
        ];
    }

    private function createImageAttachment(string $filename): int
    {
        $bytes = base64_decode(self::ONE_PIXEL_PNG_BASE64, true);
        $this->assertIsString($bytes);

        $upload = wp_upload_bits($filename, null, $bytes);
        $this->assertIsArray($upload);
        $this->assertSame('', (string) ($upload['error'] ?? ''));

        $file_path = (string) ($upload['file'] ?? '');
        $this->assertNotSame('', $file_path);
        $this->assertFileExists($file_path);

        $filetype = wp_check_filetype(basename($file_path), null);
        $attachment_id = wp_insert_attachment([
            'post_mime_type' => (string) ($filetype['type'] ?? 'image/png'),
            'post_title' => preg_replace('/\.[^.]+$/', '', basename($file_path)),
            'post_content' => '',
            'post_status' => 'inherit',
        ], $file_path);

        $this->assertIsInt($attachment_id);
        $this->assertGreaterThan(0, $attachment_id);

        $relative_path = function_exists('_wp_relative_upload_path')
            ? (string) _wp_relative_upload_path($file_path)
            : '';
        if ($relative_path === '') {
            $relative_path = ltrim((string) wp_normalize_path($file_path), '/');
        }
        update_post_meta($attachment_id, '_wp_attached_file', $relative_path);

        return (int) $attachment_id;
    }

    /**
     * @return array<string,mixed>
     */
    private function runAssignRequest(int $word_id, int $image_id): array
    {
        $_POST = [
            'nonce' => wp_create_nonce('ll_aim_admin'),
            'word_id' => (string) $word_id,
            'image_id' => (string) $image_id,
        ];
        $_REQUEST = $_POST;

        return $this->runJsonEndpoint(static function (): void {
            ll_aim_assign_handler();
        });
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
        $doing_ajax_filter = static function (): bool {
            return true;
        };

        add_filter('wp_die_handler', $die_filter);
        add_filter('wp_die_ajax_handler', $die_filter);
        add_filter('wp_doing_ajax', $doing_ajax_filter);

        ob_start();
        try {
            $callback();
            $this->fail('Expected wp_die to be called.');
        } catch (RuntimeException $e) {
            $this->assertSame('wp_die', $e->getMessage());
        } finally {
            $output = (string) ob_get_clean();
            remove_filter('wp_die_handler', $die_filter);
            remove_filter('wp_die_ajax_handler', $die_filter);
            remove_filter('wp_doing_ajax', $doing_ajax_filter);
        }

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded, 'Expected JSON response payload.');

        return $decoded;
    }

    /**
     * @return array<string,string>
     */
    private function extractSelectOptions(string $html, string $selectId): array
    {
        $pattern = '/<select[^>]*id="' . preg_quote($selectId, '/') . '"[^>]*>(.*?)<\/select>/si';
        $matches = [];
        $this->assertSame(1, preg_match($pattern, $html, $matches));

        $options = [];
        $option_matches = [];
        preg_match_all('/<option[^>]*value="([^"]*)"[^>]*>(.*?)<\/option>/si', (string) ($matches[1] ?? ''), $option_matches, PREG_SET_ORDER);
        foreach ($option_matches as $option_match) {
            $options[(string) ($option_match[1] ?? '')] = trim(html_entity_decode(wp_strip_all_tags((string) ($option_match[2] ?? '')), ENT_QUOTES, 'UTF-8'));
        }

        return $options;
    }
}
