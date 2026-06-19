<?php
declare(strict_types=1);

final class QuizPagePostTypeTest extends LL_Tools_TestCase
{
    /** @var mixed */
    private $originalPermalinkStructure = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalPermalinkStructure = get_option('permalink_structure', '');
        update_option('permalink_structure', '/%postname%/');
        global $wp_rewrite;
        if ($wp_rewrite instanceof WP_Rewrite) {
            $wp_rewrite->set_permalink_structure('/%postname%/');
        }
        ll_tools_register_quiz_page_post_type();
        flush_rewrite_rules(false);
    }

    protected function tearDown(): void
    {
        if ($this->originalPermalinkStructure === null) {
            delete_option('permalink_structure');
        } else {
            update_option('permalink_structure', $this->originalPermalinkStructure);
        }
        global $wp_rewrite;
        if ($wp_rewrite instanceof WP_Rewrite) {
            $wp_rewrite->set_permalink_structure((string) $this->originalPermalinkStructure);
        }
        delete_option(LL_TOOLS_QUIZ_PAGE_STORAGE_OPTION);
        delete_option(LL_TOOLS_QUIZ_PAGE_REWRITE_OPTION);
        delete_transient('ll_tools_quiz_page_flush_rewrite');
        flush_rewrite_rules(false);

        parent::tearDown();
    }

    public function test_generated_quiz_page_uses_dedicated_post_type_and_public_quiz_path(): void
    {
        $fixture = $this->createQuizzableCategoryFixture('generated-cpt');

        $quiz_page_id = (int) ll_tools_get_or_create_quiz_page_for_category($fixture['category_id']);

        $this->assertGreaterThan(0, $quiz_page_id);
        $this->assertSame(LL_TOOLS_QUIZ_PAGE_POST_TYPE, get_post_type($quiz_page_id));
        $this->assertSame('0', (string) wp_get_post_parent_id($quiz_page_id));
        $this->assertSame((string) $fixture['category_id'], (string) get_post_meta($quiz_page_id, LL_TOOLS_QUIZ_PAGE_CATEGORY_META, true));
        $this->assertSame('/quiz/' . $fixture['category_slug'] . '/', (string) wp_parse_url((string) get_permalink($quiz_page_id), PHP_URL_PATH));

        $legacy_pages = get_posts([
            'post_type' => 'page',
            'post_status' => ['publish', 'draft', 'pending', 'private', 'trash'],
            'posts_per_page' => -1,
            'fields' => 'ids',
            'meta_key' => LL_TOOLS_QUIZ_PAGE_CATEGORY_META,
            'meta_value' => (string) $fixture['category_id'],
        ]);

        $this->assertSame([], array_values(array_map('intval', $legacy_pages)));
    }

    public function test_existing_legacy_child_page_converts_in_place_without_changing_public_path(): void
    {
        $fixture = $this->createQuizzableCategoryFixture('legacy-child');
        $parent_id = self::factory()->post->create([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_title' => 'Quiz',
            'post_name' => 'quiz',
        ]);
        $legacy_page_id = self::factory()->post->create([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_parent' => $parent_id,
            'post_title' => 'Legacy Quiz Page',
            'post_name' => $fixture['category_slug'],
            'post_content' => 'Legacy page content.',
        ]);
        update_post_meta($legacy_page_id, LL_TOOLS_QUIZ_PAGE_CATEGORY_META, (string) $fixture['category_id']);

        $quiz_page_id = (int) ll_tools_get_or_create_quiz_page_for_category($fixture['category_id']);

        $this->assertSame($legacy_page_id, $quiz_page_id);
        $this->assertSame(LL_TOOLS_QUIZ_PAGE_POST_TYPE, get_post_type($quiz_page_id));
        $this->assertSame('0', (string) wp_get_post_parent_id($quiz_page_id));
        $this->assertStringContainsString('ll-tools-quiz-wrapper', (string) get_post_field('post_content', $quiz_page_id));
        $this->assertSame('/quiz/' . $fixture['category_slug'] . '/', (string) wp_parse_url((string) get_permalink($quiz_page_id), PHP_URL_PATH));
    }

    public function test_generated_quiz_page_content_labels_iframe_and_loading_status(): void
    {
        $fixture = $this->createQuizzableCategoryFixture('iframe-accessibility');
        $term = get_term($fixture['category_id'], 'word-category');
        $this->assertInstanceOf(WP_Term::class, $term);

        $html = ll_tools_build_quiz_page_content($term);

        $this->assertStringContainsString('class="ll-tools-iframe-loading-status screen-reader-text"', $html);
        $this->assertStringContainsString('role="status"', $html);
        $this->assertStringContainsString('Loading quiz...', $html);
        $this->assertStringContainsString('class="ll-tools-quiz-iframe"', $html);
        $this->assertStringContainsString('title="Quiz Content"', $html);
    }

    public function test_legacy_quiz_page_migration_moves_pages_to_dedicated_post_type(): void
    {
        $fixture = $this->createQuizzableCategoryFixture('migration');
        $legacy_page_id = self::factory()->post->create([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_title' => 'Legacy Quiz Page Migration',
            'post_name' => $fixture['category_slug'],
        ]);
        update_post_meta($legacy_page_id, LL_TOOLS_QUIZ_PAGE_CATEGORY_META, (string) $fixture['category_id']);

        $migrated = ll_tools_migrate_legacy_quiz_pages_to_post_type();

        $this->assertGreaterThanOrEqual(1, $migrated);
        $this->assertSame(LL_TOOLS_QUIZ_PAGE_POST_TYPE, get_post_type($legacy_page_id));
        $this->assertSame('0', (string) wp_get_post_parent_id($legacy_page_id));
    }

    /**
     * @return array{wordset_id:int,category_id:int,category_slug:string}
     */
    private function createQuizzableCategoryFixture(string $slug_prefix): array
    {
        $suffix = strtolower(wp_generate_password(6, false));
        $wordset = wp_insert_term('Quiz Page CPT Wordset ' . $suffix, 'wordset', [
            'slug' => $slug_prefix . '-wordset-' . $suffix,
        ]);
        $category_slug = $slug_prefix . '-category-' . $suffix;
        $category = wp_insert_term('Quiz Page CPT Category ' . $suffix, 'word-category', [
            'slug' => $category_slug,
        ]);

        $this->assertIsArray($wordset);
        $this->assertIsArray($category);

        $wordset_id = (int) ($wordset['term_id'] ?? 0);
        $category_id = (int) ($category['term_id'] ?? 0);
        $this->assertGreaterThan(0, $wordset_id);
        $this->assertGreaterThan(0, $category_id);

        update_term_meta($category_id, 'll_quiz_prompt_type', 'text_title');
        update_term_meta($category_id, 'll_quiz_option_type', 'text_title');

        for ($index = 1; $index <= LL_TOOLS_MIN_WORDS_PER_QUIZ; $index++) {
            $word_id = self::factory()->post->create([
                'post_type' => 'words',
                'post_status' => 'publish',
                'post_title' => 'Quiz Page CPT Word ' . $suffix . ' ' . $index,
                'post_name' => 'quiz-page-cpt-word-' . $suffix . '-' . $index,
            ]);
            update_post_meta($word_id, 'word_translation', 'Quiz Page CPT Translation ' . $index);
            update_post_meta($word_id, 'word_english_meaning', 'Quiz Page CPT Meaning ' . $index);
            wp_set_post_terms($word_id, [$category_id], 'word-category', false);
            wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);
        }

        return [
            'wordset_id' => $wordset_id,
            'category_id' => $category_id,
            'category_slug' => $category_slug,
        ];
    }
}
