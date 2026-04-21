<?php
declare(strict_types=1);

final class WordsAdminSearchTest extends LL_Tools_TestCase
{
    public function test_words_admin_search_matches_turkish_case_in_titles(): void
    {
        $matching_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Çapa',
        ]);
        $other_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Kalem',
        ]);

        $results = $this->runWordsAdminSearch('çapa');

        $this->assertContains($matching_id, $results);
        $this->assertNotContains($other_id, $results);
    }

    public function test_words_admin_search_matches_translation_meta_with_turkish_characters(): void
    {
        $translation_match_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Anchor',
        ]);
        update_post_meta($translation_match_id, 'word_translation', 'Çapa');

        $translation_results = $this->runWordsAdminSearch('çapa');
        $this->assertContains($translation_match_id, $translation_results);
    }

    /**
     * @return array<int,int>
     */
    private function runWordsAdminSearch(string $search): array
    {
        global $pagenow, $wp_query, $wp_the_query;

        $original_get = $_GET;
        $original_request = $_REQUEST;
        $original_pagenow = $pagenow;
        $original_wp_query = $wp_query;
        $original_wp_the_query = $wp_the_query;
        $original_screen = $GLOBALS['current_screen'] ?? null;

        try {
            $_GET['post_type'] = 'words';
            $_REQUEST['post_type'] = 'words';
            $pagenow = 'edit.php';
            set_current_screen('edit-words');

            $query = new WP_Query();
            $wp_query = $query;
            $wp_the_query = $query;
            $query_args = [
                'post_type' => 'words',
                'post_status' => 'publish',
                'posts_per_page' => -1,
                'fields' => 'ids',
                'orderby' => 'ID',
                'order' => 'ASC',
                's' => $search,
            ];

            $query->init();
            $query->query = $query_args;
            $query->query_vars = $query_args;
            $query->parse_query($query_args);
            ll_tools_prepare_words_admin_search_query($query);
            $query->get_posts();

            return array_map('intval', (array) $query->posts);
        } finally {
            $_GET = $original_get;
            $_REQUEST = $original_request;
            $pagenow = $original_pagenow;
            $wp_query = $original_wp_query;
            $wp_the_query = $original_wp_the_query;
            $GLOBALS['current_screen'] = $original_screen;
        }
    }
}
