<?php
declare(strict_types=1);

final class ImportQuizCacheInvalidationTest extends LL_Tools_TestCase
{
    private function createCategory(string $name, string $slug): int
    {
        $term = wp_insert_term($name, 'word-category', ['slug' => $slug]);
        $this->assertFalse(is_wp_error($term));
        $this->assertIsArray($term);

        $term_id = (int) $term['term_id'];
        update_term_meta($term_id, 'll_quiz_prompt_type', 'text_title');
        update_term_meta($term_id, 'll_quiz_option_type', 'text_title');

        return $term_id;
    }

    private function createWordset(string $name, string $slug): int
    {
        $term = wp_insert_term($name, 'wordset', ['slug' => $slug]);
        $this->assertFalse(is_wp_error($term));
        $this->assertIsArray($term);

        return (int) $term['term_id'];
    }

    public function test_imported_text_only_words_invalidate_empty_quiz_rows_cache(): void
    {
        $suffix = strtolower(wp_generate_password(6, false, false));
        $category_name = 'Import Cache Text ' . $suffix;
        $category_slug = sanitize_title($category_name);
        $wordset_slug = 'import-cache-wordset-' . $suffix;
        $wordset_id = $this->createWordset('Import Cache Wordset ' . $suffix, $wordset_slug);
        $category_id = $this->createCategory($category_name, $category_slug);
        $config = [
            'prompt_type' => 'text_title',
            'option_type' => 'text_title',
        ];

        $this->assertSame([], ll_get_words_by_category($category_name, 'text_title', [$wordset_id], $config));
        $this->assertSame(0, ll_get_words_by_category_count($category_name, 'text_title', [$wordset_id], $config));
        $before_version = ll_tools_get_category_cache_version($category_id);

        $result = ll_tools_import_job_default_result();
        $word_state = ll_tools_import_default_word_import_state();

        ll_tools_import_upsert_words_chunk(
            [
                [
                    'slug' => 'import-cache-alpha-' . $suffix,
                    'title' => 'Cache Alpha',
                    'status' => 'publish',
                    'categories' => [$category_slug],
                    'wordsets' => [$wordset_slug],
                ],
                [
                    'slug' => 'import-cache-beta-' . $suffix,
                    'title' => 'Cache Beta',
                    'status' => 'publish',
                    'categories' => [$category_slug],
                    'wordsets' => [$wordset_slug],
                ],
            ],
            '',
            [$category_slug => $category_id],
            [
                'wordset_mode' => 'create_from_export',
                'wordset_map' => [$wordset_slug => $wordset_id],
            ],
            $word_state,
            $result
        );

        $this->assertEmpty((array) ($result['errors'] ?? []));
        $this->assertGreaterThan($before_version, ll_tools_get_category_cache_version($category_id));

        $rows = ll_get_words_by_category($category_name, 'text_title', [$wordset_id], $config);
        $this->assertCount(2, $rows);
        $this->assertSame(2, ll_get_words_by_category_count($category_name, 'text_title', [$wordset_id], $config));

        $titles = array_values(array_map(static function (array $row): string {
            return (string) ($row['title'] ?? '');
        }, $rows));
        sort($titles, SORT_STRING);
        $this->assertSame(['Cache Alpha', 'Cache Beta'], $titles);
    }
}
