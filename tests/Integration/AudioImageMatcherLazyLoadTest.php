<?php
declare(strict_types=1);

final class AudioImageMatcherLazyLoadTest extends LL_Tools_TestCase
{
    /** @var array<string,mixed> */
    private $getBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->getBackup = $_GET;
    }

    protected function tearDown(): void
    {
        $_GET = $this->getBackup;
        parent::tearDown();
    }

    public function test_boot_payload_only_localizes_initial_wordset_categories(): void
    {
        $fixture = $this->createMatcherFixture();
        $this->setCurrentUserWithViewCapability();

        $_GET = [
            'page' => 'll-audio-image-matcher',
            'wordset_id' => (string) $fixture['wordset_one_id'],
        ];

        ll_aim_enqueue_admin_assets('tools_page_ll-audio-image-matcher');

        ob_start();
        ll_render_audio_image_matcher_page();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('id="ll-aim-category"', $html);
        $this->assertStringContainsString((string) $fixture['category_one_label'], $html);
        $this->assertStringNotContainsString((string) $fixture['category_two_label'], $html);

        $localized = (string) wp_scripts()->get_data('ll-audio-image-matcher', 'data');
        $this->assertStringContainsString('llAimData', $localized);
        $this->assertStringContainsString('initialCategoryRows', $localized);
        $this->assertStringContainsString((string) $fixture['category_one_label'], $localized);
        $this->assertStringNotContainsString('categoryOptionsByWordset', $localized);
        $this->assertStringNotContainsString((string) $fixture['category_two_label'], $localized);
    }

    public function test_category_options_ajax_returns_scoped_rows_for_selected_wordset(): void
    {
        $fixture = $this->createMatcherFixture();
        $this->setCurrentUserWithViewCapability();

        $_GET = [
            'nonce' => wp_create_nonce('ll_aim_admin'),
            'wordset_id' => (string) $fixture['wordset_two_id'],
        ];
        $_REQUEST = $_GET;

        $response = $this->runJsonEndpoint(static function (): void {
            ll_aim_get_category_options_handler();
        });

        $this->assertTrue((bool) ($response['success'] ?? false));
        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        $this->assertSame((int) $fixture['wordset_two_id'], (int) ($data['wordset_id'] ?? 0));

        $rows = is_array($data['rows'] ?? null) ? $data['rows'] : [];
        $labels = array_values(array_map(static function ($row): string {
            return (string) ($row['label'] ?? '');
        }, $rows));

        $this->assertContains((string) $fixture['category_two_label'], $labels);
        $this->assertNotContains((string) $fixture['category_one_label'], $labels);
    }

    /**
     * @return array{wordset_one_id:int,wordset_two_id:int,category_one_label:string,category_two_label:string}
     */
    private function createMatcherFixture(): array
    {
        $wordset_one = wp_insert_term('AIM Lazy Wordset One ' . wp_generate_password(6, false), 'wordset');
        $wordset_two = wp_insert_term('AIM Lazy Wordset Two ' . wp_generate_password(6, false), 'wordset');
        $category_one = wp_insert_term('AIM Lazy Category One ' . wp_generate_password(6, false), 'word-category');
        $category_two = wp_insert_term('AIM Lazy Category Two ' . wp_generate_password(6, false), 'word-category');

        $this->assertIsArray($wordset_one);
        $this->assertIsArray($wordset_two);
        $this->assertIsArray($category_one);
        $this->assertIsArray($category_two);

        $wordset_one_id = (int) ($wordset_one['term_id'] ?? 0);
        $wordset_two_id = (int) ($wordset_two['term_id'] ?? 0);
        $category_one_id = (int) ($category_one['term_id'] ?? 0);
        $category_two_id = (int) ($category_two['term_id'] ?? 0);

        $this->assertGreaterThan(0, $wordset_one_id);
        $this->assertGreaterThan(0, $wordset_two_id);
        $this->assertGreaterThan(0, $category_one_id);
        $this->assertGreaterThan(0, $category_two_id);

        $this->createWordWithAudio($wordset_one_id, $category_one_id, 'AIM Lazy Word One');
        $this->createWordWithAudio($wordset_two_id, $category_two_id, 'AIM Lazy Word Two');

        $category_one_term = get_term($category_one_id, 'word-category');
        $category_two_term = get_term($category_two_id, 'word-category');
        $this->assertInstanceOf(WP_Term::class, $category_one_term);
        $this->assertInstanceOf(WP_Term::class, $category_two_term);

        return [
            'wordset_one_id' => $wordset_one_id,
            'wordset_two_id' => $wordset_two_id,
            'category_one_label' => (string) $category_one_term->name,
            'category_two_label' => (string) $category_two_term->name,
        ];
    }

    private function createWordWithAudio(int $wordset_id, int $category_id, string $title): int
    {
        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => $title . ' ' . wp_generate_password(4, false),
        ]);

        wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);
        wp_set_post_terms($word_id, [$category_id], 'word-category', false);

        $audio_id = self::factory()->post->create([
            'post_type' => 'word_audio',
            'post_status' => 'publish',
            'post_parent' => $word_id,
            'post_title' => 'Audio ' . $title,
        ]);
        update_post_meta($audio_id, 'audio_file_path', '/wp-content/uploads/' . sanitize_title($title) . '.mp3');

        return (int) $word_id;
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
}
