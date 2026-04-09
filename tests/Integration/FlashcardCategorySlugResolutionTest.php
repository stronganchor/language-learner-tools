<?php
declare(strict_types=1);

final class FlashcardCategorySlugResolutionTest extends LL_Tools_TestCase
{
    public function test_word_fetch_and_count_accept_category_slug_for_duplicate_names(): void
    {
        [$public_term_id, $private_term_id] = $this->createDuplicateNamedCategories();
        $public_word_id = $this->createCategorizedWord($public_term_id, 'Slug Public Word');
        $this->createCategorizedWord($private_term_id, 'Slug Private Word');

        $config = [
            'prompt_type' => 'text_title',
            'option_type' => 'text_title',
            '__skip_quiz_config_merge' => true,
        ];

        $rows = ll_get_words_by_category('shared-name-public', 'text_title', null, $config);
        $row_ids = array_values(array_filter(array_map(static function ($row): int {
            return is_array($row) ? (int) ($row['id'] ?? 0) : 0;
        }, $rows), static function (int $id): bool {
            return $id > 0;
        }));

        $this->assertSame([$public_word_id], $row_ids);
        $this->assertSame(1, ll_get_words_by_category_count('shared-name-public', 'text_title', null, $config));
    }

    public function test_ajax_prefers_category_slug_when_duplicate_names_have_different_visibility(): void
    {
        [$public_term_id, $private_term_id] = $this->createDuplicateNamedCategories();
        $public_word_id = $this->createCategorizedWord($public_term_id, 'Visible Shared Category Word');
        $this->createCategorizedWord($private_term_id, 'Hidden Shared Category Word');

        wp_set_current_user(0);
        $_POST = [
            'category' => 'Shared Name',
            'category_slug' => 'shared-name-public',
            'display_mode' => 'text_title',
            'prompt_type' => 'text_title',
            'option_type' => 'text_title',
        ];
        $_REQUEST = $_POST;

        try {
            $response = $this->run_json_endpoint(static function (): void {
                ll_get_words_by_category_ajax();
            });
        } finally {
            $_POST = [];
            $_REQUEST = [];
        }

        $this->assertTrue((bool) ($response['success'] ?? false));
        $rows = is_array($response['data'] ?? null) ? $response['data'] : [];
        $row_ids = array_values(array_filter(array_map(static function ($row): int {
            return is_array($row) ? (int) ($row['id'] ?? 0) : 0;
        }, $rows), static function (int $id): bool {
            return $id > 0;
        }));

        $this->assertSame([$public_word_id], $row_ids);
    }

    /**
     * @return array{0:int,1:int}
     */
    private function createDuplicateNamedCategories(): array
    {
        $public = wp_insert_term('Shared Name', 'word-category', ['slug' => 'shared-name-public']);
        $this->assertFalse(is_wp_error($public));
        $this->assertIsArray($public);

        $private = wp_insert_term('Shared Name', 'word-category', ['slug' => 'shared-name-private']);
        $this->assertFalse(is_wp_error($private));
        $this->assertIsArray($private);

        $public_term_id = (int) $public['term_id'];
        $private_term_id = (int) $private['term_id'];

        update_term_meta($public_term_id, 'll_quiz_prompt_type', 'text_title');
        update_term_meta($public_term_id, 'll_quiz_option_type', 'text_title');
        update_term_meta($private_term_id, 'll_quiz_prompt_type', 'text_title');
        update_term_meta($private_term_id, 'll_quiz_option_type', 'text_title');
        update_term_meta($private_term_id, LL_TOOLS_CATEGORY_VISIBILITY_META_KEY, 'private');

        return [$public_term_id, $private_term_id];
    }

    private function createCategorizedWord(int $category_term_id, string $title): int
    {
        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => $title,
        ]);
        wp_set_post_terms($word_id, [$category_term_id], 'word-category', false);
        update_post_meta($word_id, 'word_translation', $title . ' Translation');

        return (int) $word_id;
    }

    private function run_json_endpoint(callable $callback): array
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
