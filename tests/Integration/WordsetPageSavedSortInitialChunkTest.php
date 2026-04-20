<?php
declare(strict_types=1);

final class WordsetPageSavedSortInitialChunkTest extends LL_Tools_TestCase
{
    public function test_saved_alpha_sort_orders_initial_chunk_and_lazy_payload_from_server(): void
    {
        $fixture = $this->createManualWordsetFixture();
        $wordset_id = (int) $fixture['wordset_id'];
        $wordset_term = get_term($wordset_id, 'wordset');

        $this->assertInstanceOf(WP_Term::class, $wordset_term);

        $cookie_name = ll_tools_wordset_page_get_main_sort_cookie_name($wordset_id);
        $original_cookie = $_COOKIE;
        $original_get = $_GET;
        $original_post = $_POST;
        $original_request = $_REQUEST;
        $original_wordset_page = get_query_var('ll_wordset_page');
        $original_wordset_view = get_query_var('ll_wordset_view');

        $batch_size_filter = static function ($batch_size): int {
            return 6;
        };
        $bootstrap_filter = static function ($should_bootstrap, $view, $filter_wordset_id): bool {
            if ((int) $filter_wordset_id === 0) {
                return (bool) $should_bootstrap;
            }

            return (string) $view === 'progress';
        };

        add_filter('ll_tools_wordset_page_lazy_card_batch_size', $batch_size_filter);
        add_filter('ll_tools_wordset_page_bootstrap_analytics', $bootstrap_filter, 10, 4);

        $_COOKIE[$cookie_name] = 'alpha-asc';
        $_GET = [];
        set_query_var('ll_wordset_page', (string) $wordset_term->slug);
        set_query_var('ll_wordset_view', '');

        try {
            $html = ll_tools_render_wordset_page_content($wordset_id, [
                'show_title' => false,
                'wrapper_tag' => 'div',
            ]);

            preg_match_all('/<h2 class="ll-wordset-card__title">([^<]+)<\\/h2>/', $html, $matches);
            $titles = array_map('html_entity_decode', $matches[1] ?? []);
            $this->assertCount(6, $titles);
            $this->assertStringStartsWith('Animals', (string) $titles[0]);
            $this->assertStringStartsWith('Body', (string) $titles[1]);
            $this->assertStringStartsWith('Colors', (string) $titles[2]);
            $this->assertStringStartsWith('Fruit', (string) $titles[3]);
            $this->assertStringStartsWith('Numbers', (string) $titles[4]);
            $this->assertStringStartsWith('School', (string) $titles[5]);

            $localized = (string) wp_scripts()->get_data('ll-wordset-pages-js', 'data');
            $this->assertNotSame('', $localized);

            $config = $this->extractLocalizedConfig($localized);
            $this->assertSame('alpha-asc', (string) ($config['initialMainCategorySort'] ?? ''));
            $this->assertSame($cookie_name, (string) ($config['mainCategorySortCookieName'] ?? ''));
            $this->assertTrue((bool) ($config['lazyCards']['enabled'] ?? false));

            $_POST = [
                'nonce' => (string) ($config['lazyCards']['nonce'] ?? ''),
                'token' => (string) ($config['lazyCards']['token'] ?? ''),
                'wordset_id' => $wordset_id,
                'preview_limit' => 2,
                'offset' => 6,
                'count' => 2,
            ];
            $_REQUEST = $_POST;

            $response = $this->runJsonEndpoint(static function (): void {
                ll_tools_wordset_page_handle_lazy_cards_ajax();
            });

            $this->assertTrue((bool) ($response['success'] ?? false));
            $this->assertIsArray($response['data'] ?? null);
            $this->assertStringContainsString('Travel', (string) (($response['data']['html'] ?? '')));
            $this->assertStringContainsString('Weather', (string) (($response['data']['html'] ?? '')));
        } finally {
            $_COOKIE = $original_cookie;
            $_GET = $original_get;
            $_POST = $original_post;
            $_REQUEST = $original_request;
            set_query_var('ll_wordset_page', $original_wordset_page);
            set_query_var('ll_wordset_view', $original_wordset_view);
            remove_filter('ll_tools_wordset_page_lazy_card_batch_size', $batch_size_filter);
            remove_filter('ll_tools_wordset_page_bootstrap_analytics', $bootstrap_filter, 10);
        }
    }

    /**
     * @return array{wordset_id:int}
     */
    private function createManualWordsetFixture(): array
    {
        $wordset = wp_insert_term('Saved Sort Wordset ' . wp_generate_password(6, false), 'wordset');
        $this->assertFalse(is_wp_error($wordset));
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];

        $categories = [
            'Travel',
            'Numbers',
            'Fruit',
            'Animals',
            'Body',
            'Colors',
            'School',
            'Weather',
        ];
        $category_ids = [];

        foreach ($categories as $label) {
            $category_term = wp_insert_term($label . ' ' . wp_generate_password(4, false), 'word-category');
            $this->assertFalse(is_wp_error($category_term));
            $this->assertIsArray($category_term);

            $category_id = (int) $category_term['term_id'];
            $category_ids[$label] = $category_id;
            update_term_meta($category_id, 'll_quiz_prompt_type', 'text_title');
            update_term_meta($category_id, 'll_quiz_option_type', 'text_title');

            for ($word_index = 1; $word_index <= 5; $word_index++) {
                $this->createWordWithAudio(
                    $label . ' Word ' . $word_index,
                    $label . ' Translation ' . $word_index,
                    $category_id,
                    $wordset_id,
                    sanitize_title($label) . '-' . $word_index . '.mp3'
                );
            }

            $effective_category_id = function_exists('ll_tools_get_effective_category_id_for_wordset')
                ? (int) ll_tools_get_effective_category_id_for_wordset($category_id, $wordset_id, true)
                : $category_id;

            $lesson_id = self::factory()->post->create([
                'post_type' => 'll_vocab_lesson',
                'post_status' => 'publish',
                'post_title' => $label . ' Lesson ' . wp_generate_password(4, false),
            ]);
            update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_WORDSET_META, (string) $wordset_id);
            update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, (string) $effective_category_id);
        }

        update_term_meta($wordset_id, 'll_wordset_category_ordering_mode', 'manual');
        update_term_meta($wordset_id, 'll_wordset_category_manual_order', [
            $category_ids['Travel'],
            $category_ids['Numbers'],
            $category_ids['Fruit'],
            $category_ids['Animals'],
            $category_ids['Body'],
            $category_ids['Colors'],
            $category_ids['School'],
            $category_ids['Weather'],
        ]);

        return [
            'wordset_id' => $wordset_id,
        ];
    }

    private function createWordWithAudio(string $title, string $translation, int $category_id, int $wordset_id, string $audio_file_name): int
    {
        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => $title . ' ' . wp_generate_password(4, false),
        ]);
        wp_set_post_terms($word_id, [$category_id], 'word-category', false);
        wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);
        update_post_meta($word_id, 'word_translation', $translation);

        $audio_post_id = self::factory()->post->create([
            'post_type' => 'word_audio',
            'post_status' => 'publish',
            'post_parent' => $word_id,
            'post_title' => 'Audio ' . $title,
        ]);
        update_post_meta($audio_post_id, 'audio_file_path', '/wp-content/uploads/' . $audio_file_name);

        return (int) $word_id;
    }

    /**
     * @return array<string,mixed>
     */
    private function extractLocalizedConfig(string $localized): array
    {
        preg_match('/var llWordsetPageData = (\{.*?\});/s', $localized, $matches);
        $this->assertArrayHasKey(1, $matches);

        $decoded = json_decode((string) $matches[1], true);
        $this->assertIsArray($decoded);

        return $decoded;
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
