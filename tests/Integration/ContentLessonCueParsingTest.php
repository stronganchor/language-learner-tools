<?php
declare(strict_types=1);

final class ContentLessonCueParsingTest extends LL_Tools_TestCase
{
    /** @var array<string,mixed> */
    private $postBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->postBackup = $_POST;
    }

    protected function tearDown(): void
    {
        $_POST = $this->postBackup;
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);

        parent::tearDown();
    }

    public function test_content_lesson_parses_supported_timing_formats(): void
    {
        $vtt = <<<VTT
WEBVTT

00:00:01.000 --> 00:00:03.500
First line

00:00:04.000 --> 00:00:06.000
Second line
VTT;

        $vtt_cues = ll_tools_content_lesson_parse_source($vtt, 'vtt');
        $this->assertIsArray($vtt_cues);
        $this->assertCount(2, $vtt_cues);
        $this->assertSame('First line', $vtt_cues[0]['text']);
        $this->assertSame(1000, $vtt_cues[0]['start_ms']);
        $this->assertSame(3500, $vtt_cues[0]['end_ms']);

        $tsv = <<<TSV
id\ttext_full\tstart_sec\tend_sec
1\tHello world\t1.168\t3.888
2\tSecond phrase\t4.000\t5.500
TSV;

        $tsv_cues = ll_tools_content_lesson_parse_source($tsv, 'tsv');
        $this->assertIsArray($tsv_cues);
        $this->assertCount(2, $tsv_cues);
        $this->assertSame('Hello world', $tsv_cues[0]['text']);
        $this->assertSame(1168, $tsv_cues[0]['start_ms']);

        $json = wp_json_encode([
            'lines' => [
                [
                    'start_sec' => 1.5,
                    'end_sec' => 2.75,
                    'text_projected' => 'Projected line',
                ],
            ],
        ]);

        $json_cues = ll_tools_content_lesson_parse_source((string) $json, 'json');
        $this->assertIsArray($json_cues);
        $this->assertCount(1, $json_cues);
        $this->assertSame('Projected line', $json_cues[0]['text']);
        $this->assertSame(1500, $json_cues[0]['start_ms']);
        $this->assertSame(2750, $json_cues[0]['end_ms']);
    }

    public function test_content_lesson_relationship_helpers_link_wordset_and_vocab_pages(): void
    {
        $wordset = wp_insert_term('Story Wordset', 'wordset', ['slug' => 'story-wordset']);
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];

        $category = wp_insert_term('Story Category', 'word-category', ['slug' => 'story-category']);
        $this->assertIsArray($category);
        $category_id = (int) $category['term_id'];

        update_option('ll_vocab_lesson_wordsets', [$wordset_id], false);

        $vocab_lesson_id = self::factory()->post->create([
            'post_type' => 'll_vocab_lesson',
            'post_status' => 'publish',
            'post_title' => 'Story Category Lesson',
        ]);
        update_post_meta($vocab_lesson_id, LL_TOOLS_VOCAB_LESSON_WORDSET_META, $wordset_id);
        update_post_meta($vocab_lesson_id, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, $category_id);

        $content_lesson_id = self::factory()->post->create([
            'post_type' => 'll_content_lesson',
            'post_status' => 'publish',
            'post_title' => 'Story Lesson',
            'post_excerpt' => 'Main story lesson.',
        ]);
        update_post_meta($content_lesson_id, LL_TOOLS_CONTENT_LESSON_WORDSET_META, $wordset_id);
        update_post_meta($content_lesson_id, LL_TOOLS_CONTENT_LESSON_MEDIA_TYPE_META, 'audio');
        update_post_meta($content_lesson_id, LL_TOOLS_CONTENT_LESSON_CATEGORY_IDS_META, [$category_id]);

        $wordset_lessons = ll_tools_get_content_lessons_for_wordset($wordset_id);
        $this->assertCount(1, $wordset_lessons);
        $this->assertSame($content_lesson_id, (int) $wordset_lessons[0]['id']);
        $this->assertSame(1, (int) $wordset_lessons[0]['category_count']);

        $related_to_vocab = ll_tools_get_content_lessons_for_vocab_lesson($wordset_id, $category_id);
        $this->assertCount(1, $related_to_vocab);
        $this->assertSame($content_lesson_id, (int) $related_to_vocab[0]['id']);

        $related_vocab_items = ll_tools_get_content_lesson_related_vocab_items($content_lesson_id);
        $this->assertCount(1, $related_vocab_items);
        $this->assertSame($category_id, (int) $related_vocab_items[0]['id']);
        $this->assertNotSame('', (string) $related_vocab_items[0]['url']);
    }

    public function test_content_lesson_category_rows_scope_to_selected_wordset(): void
    {
        $fixture = $this->createScopedCategoryFixture();

        $rows = ll_tools_get_content_lesson_selectable_category_rows(
            (int) $fixture['wordset_one_id'],
            [(int) $fixture['isolated_two_id']]
        );

        $row_ids = array_values(array_map(static function (array $row): int {
            return (int) ($row['id'] ?? 0);
        }, $rows));

        $this->assertContains((int) $fixture['isolated_one_id'], $row_ids);
        $this->assertNotContains((int) $fixture['isolated_two_id'], $row_ids);
    }

    public function test_content_lesson_save_remaps_selected_categories_into_current_wordset_sandbox(): void
    {
        $fixture = $this->createScopedCategoryFixture();
        $this->setCurrentUserWithViewCapability();

        $lesson_id = self::factory()->post->create([
            'post_type' => 'll_content_lesson',
            'post_status' => 'draft',
            'post_title' => 'Scoped Content Lesson',
        ]);
        $lesson = get_post($lesson_id);
        $this->assertInstanceOf(WP_Post::class, $lesson);

        $_POST = [
            'll_tools_content_lesson_nonce' => wp_create_nonce('ll_tools_content_lesson_save'),
            'll_content_lesson_wordset_id' => (string) $fixture['wordset_one_id'],
            'll_content_lesson_media_type' => 'audio',
            'll_content_lesson_media_url' => 'https://example.com/story.mp3',
            'll_content_lesson_transcript_format' => 'auto',
            'll_content_lesson_transcript_source' => '',
            'll_content_lesson_category_ids' => [(string) $fixture['isolated_two_id']],
        ];

        ll_tools_save_content_lesson_metabox($lesson_id, $lesson);

        $saved_category_ids = get_post_meta($lesson_id, LL_TOOLS_CONTENT_LESSON_CATEGORY_IDS_META, true);
        $saved_category_ids = is_array($saved_category_ids) ? array_values(array_map('intval', $saved_category_ids)) : [];

        $this->assertSame([(int) $fixture['isolated_one_id']], $saved_category_ids);
    }

    public function test_content_lesson_save_filters_mixed_grid_prerequisites_to_quizzable_wordset_lessons(): void
    {
        $fixture = $this->createMixedLessonFixture();
        $this->setCurrentUserWithViewCapability();

        $lesson_id = self::factory()->post->create([
            'post_type' => 'll_content_lesson',
            'post_status' => 'draft',
            'post_title' => 'Mixed Grid Story',
        ]);
        $lesson = get_post($lesson_id);
        $this->assertInstanceOf(WP_Post::class, $lesson);

        $_POST = [
            'll_tools_content_lesson_nonce' => wp_create_nonce('ll_tools_content_lesson_save'),
            'll_content_lesson_wordset_id' => (string) $fixture['wordset_id'],
            'll_content_lesson_media_type' => 'audio',
            'll_content_lesson_media_url' => 'https://example.com/mixed-grid-story.mp3',
            'll_content_lesson_transcript_format' => 'auto',
            'll_content_lesson_transcript_source' => '',
            'll_content_lesson_category_ids' => [(string) $fixture['category_c_id']],
            'll_content_lesson_show_in_mix' => '1',
            'll_content_lesson_prereq_category_ids' => [
                (string) $fixture['category_a_id'],
                (string) $fixture['non_quizzable_category_id'],
            ],
        ];

        ll_tools_save_content_lesson_metabox($lesson_id, $lesson);

        $this->assertTrue(ll_tools_get_content_lesson_show_in_mix($lesson_id));
        $this->assertSame(
            [(int) $fixture['category_a_id']],
            ll_tools_get_content_lesson_prereq_category_ids($lesson_id)
        );
    }

    public function test_content_lesson_save_filters_content_lesson_prerequisites_to_same_wordset_lessons(): void
    {
        $fixture = $this->createMixedLessonFixture();
        $this->setCurrentUserWithViewCapability();

        $valid_prereq_lesson_id = $this->createPublishedContentLesson(
            (int) $fixture['wordset_id'],
            'Earlier Story',
            [(int) $fixture['category_b_id']],
            [
                'show_in_mix' => true,
                'prereq_category_ids' => [(int) $fixture['category_a_id']],
            ]
        );

        $other_wordset = wp_insert_term('Other Content Wordset ' . wp_generate_password(6, false), 'wordset');
        $this->assertIsArray($other_wordset);
        $other_wordset_id = (int) ($other_wordset['term_id'] ?? 0);
        $invalid_prereq_lesson_id = $this->createPublishedContentLesson(
            $other_wordset_id,
            'Wrong Wordset Story',
            []
        );

        $lesson_id = self::factory()->post->create([
            'post_type' => 'll_content_lesson',
            'post_status' => 'draft',
            'post_title' => 'Follow-Up Story',
        ]);
        $lesson = get_post($lesson_id);
        $this->assertInstanceOf(WP_Post::class, $lesson);

        $_POST = [
            'll_tools_content_lesson_nonce' => wp_create_nonce('ll_tools_content_lesson_save'),
            'll_content_lesson_wordset_id' => (string) $fixture['wordset_id'],
            'll_content_lesson_media_type' => 'audio',
            'll_content_lesson_media_url' => 'https://example.com/follow-up-story.mp3',
            'll_content_lesson_transcript_format' => 'auto',
            'll_content_lesson_transcript_source' => '',
            'll_content_lesson_category_ids' => [(string) $fixture['category_c_id']],
            'll_content_lesson_show_in_mix' => '1',
            'll_content_lesson_prereq_lesson_ids' => [
                (string) $valid_prereq_lesson_id,
                (string) $invalid_prereq_lesson_id,
                (string) $lesson_id,
            ],
        ];

        ll_tools_save_content_lesson_metabox($lesson_id, $lesson);

        $this->assertSame(
            [$valid_prereq_lesson_id],
            ll_tools_get_content_lesson_prereq_lesson_ids($lesson_id)
        );
    }

    public function test_wordset_page_renders_mixed_content_lessons_between_vocab_cards(): void
    {
        $fixture = $this->createMixedLessonFixture();
        $wordset_id = (int) $fixture['wordset_id'];
        $wordset_term = get_term($wordset_id, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset_term);

        $content_lesson_id = self::factory()->post->create([
            'post_type' => 'll_content_lesson',
            'post_status' => 'publish',
            'post_title' => 'Bravo Story Bridge',
            'post_excerpt' => 'Review the story before the final vocab drill.',
            'menu_order' => 5,
        ]);
        update_post_meta($content_lesson_id, LL_TOOLS_CONTENT_LESSON_WORDSET_META, $wordset_id);
        update_post_meta($content_lesson_id, LL_TOOLS_CONTENT_LESSON_MEDIA_TYPE_META, 'audio');
        update_post_meta($content_lesson_id, LL_TOOLS_CONTENT_LESSON_CATEGORY_IDS_META, [$fixture['category_c_id']]);
        update_post_meta($content_lesson_id, LL_TOOLS_CONTENT_LESSON_SHOW_IN_MIX_META, '1');
        update_post_meta($content_lesson_id, LL_TOOLS_CONTENT_LESSON_PREREQ_CATEGORY_IDS_META, [
            $fixture['category_a_id'],
            $fixture['category_b_id'],
        ]);

        $bootstrap_filter = static function ($should_bootstrap, $view, $filter_wordset_id): bool {
            if ((int) $filter_wordset_id > 0 && (string) $view === 'main') {
                return false;
            }
            return (bool) $should_bootstrap;
        };
        add_filter('ll_tools_wordset_page_bootstrap_analytics', $bootstrap_filter, 10, 4);

        $original_get = $_GET;
        $original_wordset_page = get_query_var('ll_wordset_page');
        $original_wordset_view = get_query_var('ll_wordset_view');
        $_GET = [];
        set_query_var('ll_wordset_page', (string) $wordset_term->slug);
        set_query_var('ll_wordset_view', '');

        try {
            try {
                $html = ll_tools_render_wordset_page_content($wordset_id, [
                    'show_title' => false,
                    'wrapper_tag' => 'div',
                ]);
            } finally {
                $_GET = $original_get;
                set_query_var('ll_wordset_page', $original_wordset_page);
                set_query_var('ll_wordset_view', $original_wordset_view);
            }
        } finally {
            remove_filter('ll_tools_wordset_page_bootstrap_analytics', $bootstrap_filter, 10);
        }

        $alpha_pos = strpos($html, 'data-cat-id="' . (int) $fixture['category_a_id'] . '"');
        $bravo_pos = strpos($html, 'data-cat-id="' . (int) $fixture['category_b_id'] . '"');
        $content_pos = strpos($html, 'data-lesson-id="' . (int) $content_lesson_id . '"');
        $charlie_pos = strpos($html, 'data-cat-id="' . (int) $fixture['category_c_id'] . '"');

        $this->assertNotFalse($alpha_pos);
        $this->assertNotFalse($bravo_pos);
        $this->assertNotFalse($content_pos);
        $this->assertNotFalse($charlie_pos);
        $this->assertTrue($alpha_pos < $bravo_pos);
        $this->assertTrue($bravo_pos < $content_pos);
        $this->assertTrue($content_pos < $charlie_pos);
        $this->assertStringContainsString('ll-wordset-card ll-wordset-card--content', $html);
    }

    public function test_wordset_page_renders_content_lesson_prerequisites_in_sequence(): void
    {
        $fixture = $this->createMixedLessonFixture();
        $wordset_id = (int) $fixture['wordset_id'];
        $wordset_term = get_term($wordset_id, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset_term);

        $bridge_lesson_id = $this->createPublishedContentLesson(
            $wordset_id,
            'Bridge Story',
            [(int) $fixture['category_c_id']],
            [
                'show_in_mix' => true,
                'prereq_category_ids' => [(int) $fixture['category_b_id']],
                'menu_order' => 5,
                'excerpt' => 'Story first.',
            ]
        );
        $practice_lesson_id = $this->createPublishedContentLesson(
            $wordset_id,
            'Bridge Practice',
            [(int) $fixture['category_c_id']],
            [
                'show_in_mix' => true,
                'prereq_lesson_ids' => [$bridge_lesson_id],
                'menu_order' => 10,
                'excerpt' => 'Practice second.',
            ]
        );

        $bootstrap_filter = static function ($should_bootstrap, $view, $filter_wordset_id): bool {
            if ((int) $filter_wordset_id > 0 && (string) $view === 'main') {
                return false;
            }
            return (bool) $should_bootstrap;
        };
        add_filter('ll_tools_wordset_page_bootstrap_analytics', $bootstrap_filter, 10, 4);

        $original_get = $_GET;
        $original_wordset_page = get_query_var('ll_wordset_page');
        $original_wordset_view = get_query_var('ll_wordset_view');
        $_GET = [];
        set_query_var('ll_wordset_page', (string) $wordset_term->slug);
        set_query_var('ll_wordset_view', '');

        try {
            try {
                $html = ll_tools_render_wordset_page_content($wordset_id, [
                    'show_title' => false,
                    'wrapper_tag' => 'div',
                ]);
            } finally {
                $_GET = $original_get;
                set_query_var('ll_wordset_page', $original_wordset_page);
                set_query_var('ll_wordset_view', $original_wordset_view);
            }
        } finally {
            remove_filter('ll_tools_wordset_page_bootstrap_analytics', $bootstrap_filter, 10);
        }

        $bravo_pos = strpos($html, 'data-cat-id="' . (int) $fixture['category_b_id'] . '"');
        $bridge_pos = strpos($html, 'data-lesson-id="' . $bridge_lesson_id . '"');
        $practice_pos = strpos($html, 'data-lesson-id="' . $practice_lesson_id . '"');
        $charlie_pos = strpos($html, 'data-cat-id="' . (int) $fixture['category_c_id'] . '"');

        $this->assertNotFalse($bravo_pos);
        $this->assertNotFalse($bridge_pos);
        $this->assertNotFalse($practice_pos);
        $this->assertNotFalse($charlie_pos);
        $this->assertTrue($bravo_pos < $bridge_pos);
        $this->assertTrue($bridge_pos < $practice_pos);
        $this->assertTrue($practice_pos < $charlie_pos);
    }

    public function test_content_lesson_metabox_renders_translated_media_url_placeholder(): void
    {
        $messages = require LL_TOOLS_BASE_PATH . 'languages/ll-tools-text-domain-tr_TR.l10n.php';
        $this->assertIsArray($messages);
        $this->assertArrayHasKey('messages', $messages);
        $this->assertSame('Doğrudan medya URL\'sini buraya yapıştır.', $messages['messages']['Paste the direct media URL here.'] ?? null);
    }

    /**
     * @return array<string,int>
     */
    private function createScopedCategoryFixture(): array
    {
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);

        $wordset_one_id = $this->ensureTerm('wordset', 'Lesson Scope One', 'lesson-scope-one');
        $wordset_two_id = $this->ensureTerm('wordset', 'Lesson Scope Two', 'lesson-scope-two');
        $source_category_id = $this->ensureTerm('word-category', 'Lesson Shared Trees', 'lesson-shared-trees');

        $isolated_one_id = (int) ll_tools_get_or_create_isolated_category_copy($source_category_id, $wordset_one_id);
        $isolated_two_id = (int) ll_tools_get_or_create_isolated_category_copy($source_category_id, $wordset_two_id);

        $this->createWordInScope('Lesson Scope One Tree', $wordset_one_id, $isolated_one_id);
        $this->createWordInScope('Lesson Scope Two Tree', $wordset_two_id, $isolated_two_id);

        return [
            'wordset_one_id' => $wordset_one_id,
            'wordset_two_id' => $wordset_two_id,
            'isolated_one_id' => $isolated_one_id,
            'isolated_two_id' => $isolated_two_id,
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

    private function createWordInScope(string $title, int $wordset_id, int $category_id): int
    {
        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => $title,
        ]);

        wp_set_object_terms($word_id, [$wordset_id], 'wordset', false);
        wp_set_object_terms($word_id, [$category_id], 'word-category', false);

        return $word_id;
    }

    /**
     * @return array{wordset_id:int,category_a_id:int,category_b_id:int,category_c_id:int,non_quizzable_category_id:int}
     */
    private function createMixedLessonFixture(): array
    {
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '0', false);

        $wordset = wp_insert_term('Mixed Lesson Wordset ' . wp_generate_password(6, false), 'wordset');
        $this->assertIsArray($wordset);
        $this->assertFalse(is_wp_error($wordset));
        $wordset_id = (int) ($wordset['term_id'] ?? 0);
        update_option('ll_vocab_lesson_wordsets', [$wordset_id], false);

        $category_a_id = $this->ensureTerm('word-category', 'Alpha Lesson ' . wp_generate_password(4, false), 'alpha-lesson-' . wp_generate_password(4, false));
        $category_b_id = $this->ensureTerm('word-category', 'Bravo Lesson ' . wp_generate_password(4, false), 'bravo-lesson-' . wp_generate_password(4, false));
        $category_c_id = $this->ensureTerm('word-category', 'Charlie Lesson ' . wp_generate_password(4, false), 'charlie-lesson-' . wp_generate_password(4, false));
        $non_quizzable_category_id = $this->ensureTerm('word-category', 'Sparse Lesson ' . wp_generate_password(4, false), 'sparse-lesson-' . wp_generate_password(4, false));

        foreach ([$category_a_id, $category_b_id, $category_c_id, $non_quizzable_category_id] as $category_id) {
            update_term_meta($category_id, 'll_quiz_prompt_type', 'audio');
            update_term_meta($category_id, 'll_quiz_option_type', 'text_title');
        }

        $this->createVocabLessonFixturePosts($wordset_id, $category_a_id, 'Alpha');
        $this->createVocabLessonFixturePosts($wordset_id, $category_b_id, 'Bravo');
        $this->createVocabLessonFixturePosts($wordset_id, $category_c_id, 'Charlie');

        for ($index = 1; $index <= 2; $index++) {
            $this->createWordWithAudio(
                'Sparse Word ' . $index,
                'Sparse Translation ' . $index,
                $non_quizzable_category_id,
                $wordset_id,
                'sparse-word-' . $index . '.mp3'
            );
        }

        return [
            'wordset_id' => $wordset_id,
            'category_a_id' => $category_a_id,
            'category_b_id' => $category_b_id,
            'category_c_id' => $category_c_id,
            'non_quizzable_category_id' => $non_quizzable_category_id,
        ];
    }

    private function createVocabLessonFixturePosts(int $wordset_id, int $category_id, string $prefix): void
    {
        for ($index = 1; $index <= 5; $index++) {
            $this->createWordWithAudio(
                $prefix . ' Word ' . $index,
                $prefix . ' Translation ' . $index,
                $category_id,
                $wordset_id,
                strtolower($prefix) . '-word-' . $index . '.mp3'
            );
        }

        $result = ll_tools_get_or_create_vocab_lesson_page($category_id, $wordset_id);
        $this->assertIsArray($result);
        $this->assertNotEmpty((int) ($result['post_id'] ?? 0));
    }

    private function createPublishedContentLesson(int $wordset_id, string $title, array $category_ids, array $args = []): int
    {
        $lesson_id = self::factory()->post->create([
            'post_type' => 'll_content_lesson',
            'post_status' => 'publish',
            'post_title' => $title,
            'post_excerpt' => (string) ($args['excerpt'] ?? ''),
            'menu_order' => isset($args['menu_order']) ? (int) $args['menu_order'] : 0,
        ]);

        update_post_meta($lesson_id, LL_TOOLS_CONTENT_LESSON_WORDSET_META, $wordset_id);
        update_post_meta($lesson_id, LL_TOOLS_CONTENT_LESSON_MEDIA_TYPE_META, (string) ($args['media_type'] ?? 'audio'));
        update_post_meta($lesson_id, LL_TOOLS_CONTENT_LESSON_CATEGORY_IDS_META, array_values(array_map('intval', $category_ids)));

        if (!empty($args['show_in_mix'])) {
            update_post_meta($lesson_id, LL_TOOLS_CONTENT_LESSON_SHOW_IN_MIX_META, '1');
        }

        if (!empty($args['prereq_category_ids'])) {
            update_post_meta(
                $lesson_id,
                LL_TOOLS_CONTENT_LESSON_PREREQ_CATEGORY_IDS_META,
                array_values(array_map('intval', (array) $args['prereq_category_ids']))
            );
        }

        if (!empty($args['prereq_lesson_ids'])) {
            update_post_meta(
                $lesson_id,
                LL_TOOLS_CONTENT_LESSON_PREREQ_LESSON_IDS_META,
                array_values(array_map('intval', (array) $args['prereq_lesson_ids']))
            );
        }

        return $lesson_id;
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
}
