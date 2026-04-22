<?php
declare(strict_types=1);

final class WordsetPageLazyCardsAjaxTest extends LL_Tools_TestCase
{
    public function test_main_wordset_route_localizes_lazy_cards_for_empty_view(): void
    {
        $fixture = $this->createWordsetFixture(7);
        $wordset_id = (int) $fixture['wordset_id'];
        $wordset_term = get_term($wordset_id, 'wordset');

        $this->assertInstanceOf(WP_Term::class, $wordset_term);

        $original_get = $_GET;
        $original_wordset_page = get_query_var('ll_wordset_page');
        $original_wordset_view = get_query_var('ll_wordset_view');
        $batch_size_filter = static function (): int {
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

        $_GET = [];
        set_query_var('ll_wordset_page', (string) $wordset_term->slug);
        set_query_var('ll_wordset_view', '');

        try {
            $html = ll_tools_render_wordset_page_content($wordset_id, [
                'show_title' => false,
                'wrapper_tag' => 'div',
            ]);

            $this->assertStringContainsString('data-ll-wordset-lazy-root', $html);

            $localized = (string) wp_scripts()->get_data('ll-wordset-pages-js', 'data');
            $this->assertNotSame('', $localized);
            $this->assertStringContainsString('var llWordsetPageData = ', $localized);

            $config = $this->extractLocalizedConfig($localized);
            $this->assertSame('main', (string) ($config['view'] ?? ''));
            $this->assertIsArray($config['lazyCards'] ?? null);
            $this->assertTrue((bool) ($config['lazyCards']['enabled'] ?? false));
            $this->assertSame(6, (int) ($config['lazyCards']['batchSize'] ?? 0));
            $this->assertGreaterThan(0, (int) ($config['lazyCards']['loaded'] ?? 0));
            $this->assertGreaterThan((int) ($config['lazyCards']['loaded'] ?? 0), (int) ($config['lazyCards']['total'] ?? 0));
            $this->assertNotSame('', (string) ($config['lazyCards']['token'] ?? ''));
        } finally {
            $_GET = $original_get;
            set_query_var('ll_wordset_page', $original_wordset_page);
            set_query_var('ll_wordset_view', $original_wordset_view);
            remove_filter('ll_tools_wordset_page_lazy_card_batch_size', $batch_size_filter);
            remove_filter('ll_tools_wordset_page_bootstrap_analytics', $bootstrap_filter, 10);
        }
    }

    public function test_ajax_rebuilds_lazy_cards_when_cached_payload_is_missing(): void
    {
        $fixture = $this->createWordsetFixture();
        $wordset_id = (int) $fixture['wordset_id'];

        $original_post = $_POST;
        $original_request = $_REQUEST;
        $_POST = [
            'nonce' => wp_create_nonce('ll_tools_wordset_page_lazy_cards'),
            'token' => 'missing-token',
            'wordset_id' => $wordset_id,
            'preview_limit' => 2,
            'offset' => 1,
            'count' => 1,
        ];
        $_REQUEST = $_POST;

        try {
            $response = $this->runJsonEndpoint(static function (): void {
                ll_tools_wordset_page_handle_lazy_cards_ajax();
            });
        } finally {
            $_POST = $original_post;
            $_REQUEST = $original_request;
        }

        $this->assertArrayHasKey('success', $response);
        $this->assertTrue((bool) $response['success']);
        $this->assertArrayHasKey('data', $response);
        $this->assertIsArray($response['data']);

        $data = $response['data'];
        $this->assertSame(2, (int) ($data['loaded'] ?? 0));
        $this->assertSame(2, (int) ($data['nextOffset'] ?? 0));
        $this->assertFalse((bool) ($data['hasMore'] ?? true));
        $this->assertStringContainsString('Lazy Ajax Category B', (string) ($data['html'] ?? ''));
    }

    public function test_guest_main_view_reuses_shared_lazy_cards_token_for_same_payload(): void
    {
        $fixture = $this->createWordsetFixture(7);
        $wordset_id = (int) $fixture['wordset_id'];
        $wordset_term = get_term($wordset_id, 'wordset');

        $this->assertInstanceOf(WP_Term::class, $wordset_term);

        $original_get = $_GET;
        $original_wordset_page = get_query_var('ll_wordset_page');
        $original_wordset_view = get_query_var('ll_wordset_view');
        $batch_size_filter = static function (): int {
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

        $_GET = [];
        set_query_var('ll_wordset_page', (string) $wordset_term->slug);
        set_query_var('ll_wordset_view', '');

        try {
            ll_tools_render_wordset_page_content($wordset_id, [
                'show_title' => false,
                'wrapper_tag' => 'div',
            ]);
            $first_config = $this->extractLocalizedConfig((string) wp_scripts()->get_data('ll-wordset-pages-js', 'data'));
            $first_token = (string) ($first_config['lazyCards']['token'] ?? '');

            ll_tools_render_wordset_page_content($wordset_id, [
                'show_title' => false,
                'wrapper_tag' => 'div',
            ]);
            $second_config = $this->extractLocalizedConfig((string) wp_scripts()->get_data('ll-wordset-pages-js', 'data'));
            $second_token = (string) ($second_config['lazyCards']['token'] ?? '');

            $this->assertStringStartsWith('shared_', $first_token);
            $this->assertSame($first_token, $second_token);

            $payload = ll_tools_wordset_page_get_lazy_cards_payload($first_token);
            $this->assertIsArray($payload);
            $this->assertSame(0, (int) ($payload['user_id'] ?? -1));
        } finally {
            $_GET = $original_get;
            set_query_var('ll_wordset_page', $original_wordset_page);
            set_query_var('ll_wordset_view', $original_wordset_view);
            remove_filter('ll_tools_wordset_page_lazy_card_batch_size', $batch_size_filter);
            remove_filter('ll_tools_wordset_page_bootstrap_analytics', $bootstrap_filter, 10);
        }
    }

    /**
     * @return array{wordset_id:int}
     */
    private function createWordsetFixture(int $category_count = 2): array
    {
        $category_count = max(2, $category_count);
        $wordset = wp_insert_term('Lazy Ajax Wordset ' . wp_generate_password(6, false), 'wordset');
        $this->assertFalse(is_wp_error($wordset));
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];

        for ($category_index = 0; $category_index < $category_count; $category_index++) {
            $letter = chr(ord('A') + $category_index);
            $category_term = wp_insert_term('Lazy Ajax Category ' . $letter . ' ' . wp_generate_password(6, false), 'word-category');
            $this->assertFalse(is_wp_error($category_term));
            $this->assertIsArray($category_term);

            $category_id = (int) $category_term['term_id'];
            update_term_meta($category_id, 'll_quiz_prompt_type', 'text_title');
            update_term_meta($category_id, 'll_quiz_option_type', 'text_title');

            for ($word_index = 1; $word_index <= 5; $word_index++) {
                $slug = strtolower($letter);
                $this->createWordWithAudio(
                    'Lazy Ajax ' . $letter . ' Word ' . $word_index,
                    'Lazy Ajax ' . $letter . ' Translation ' . $word_index,
                    $category_id,
                    $wordset_id,
                    'lazy-ajax-' . $slug . '-' . $word_index . '.mp3'
                );
            }

            $effective_category_id = function_exists('ll_tools_get_effective_category_id_for_wordset')
                ? (int) ll_tools_get_effective_category_id_for_wordset($category_id, $wordset_id, true)
                : $category_id;

            $lesson_id = self::factory()->post->create([
                'post_type' => 'll_vocab_lesson',
                'post_status' => 'publish',
                'post_title' => 'Lazy Ajax Lesson ' . $letter . ' ' . wp_generate_password(4, false),
            ]);
            update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_WORDSET_META, (string) $wordset_id);
            update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, (string) $effective_category_id);
        }

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

        $decoded = json_decode($matches[1], true);
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
