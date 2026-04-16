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
