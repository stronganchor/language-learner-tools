<?php
declare(strict_types=1);

final class WordGridBulkEditStateTest extends LL_Tools_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (function_exists('ll_tools_register_words_post_type')) {
            ll_tools_register_words_post_type();
        }
        if (function_exists('ll_tools_register_word_category_taxonomy')) {
            ll_tools_register_word_category_taxonomy();
        }
        if (function_exists('ll_tools_register_wordset_taxonomy')) {
            ll_tools_register_wordset_taxonomy();
        }
        register_taxonomy_for_object_type('word-category', 'words');
        register_taxonomy_for_object_type('wordset', 'words');

        if (function_exists('ll_tools_rebuild_specific_wrong_answer_owner_map')) {
            ll_tools_rebuild_specific_wrong_answer_owner_map();
        }
    }

    public function test_bulk_defaults_helper_returns_shared_values_for_applicable_words(): void
    {
        ll_register_part_of_speech_taxonomy();

        $wordset_id = $this->createWordset();
        $category_id = $this->createCategory('Bulk Defaults');
        $this->enableBulkWordsetMeta($wordset_id);

        $noun_term_id = $this->ensurePartOfSpeechTerm('noun', 'Noun');
        $verb_term_id = $this->ensurePartOfSpeechTerm('verb', 'Verb');

        $word_one = $this->createWord($wordset_id, $category_id, 'Bulk Noun One');
        wp_set_object_terms($word_one, [$noun_term_id], 'part_of_speech', false);
        update_post_meta($word_one, 'll_grammatical_gender', 'masculine');
        update_post_meta($word_one, 'll_grammatical_plurality', 'singular');

        $word_two = $this->createWord($wordset_id, $category_id, 'Bulk Noun Two');
        wp_set_object_terms($word_two, [$noun_term_id], 'part_of_speech', false);
        update_post_meta($word_two, 'll_grammatical_gender', 'Masculine');
        update_post_meta($word_two, 'll_grammatical_plurality', 'Singular');

        $word_three = $this->createWord($wordset_id, $category_id, 'Bulk Verb One');
        wp_set_object_terms($word_three, [$verb_term_id], 'part_of_speech', false);
        update_post_meta($word_three, 'll_verb_tense', 'present');
        update_post_meta($word_three, 'll_verb_mood', 'Indicative');

        $defaults = ll_tools_word_grid_get_bulk_control_defaults($wordset_id, [$word_one, $word_two, $word_three]);

        $this->assertSame('', (string) ($defaults['part_of_speech'] ?? ''));
        $this->assertSame('Masculine', (string) ($defaults['grammatical_gender'] ?? ''));
        $this->assertSame('Singular', (string) ($defaults['grammatical_plurality'] ?? ''));
        $this->assertSame('Present', (string) ($defaults['verb_tense'] ?? ''));
        $this->assertSame('Indicative', (string) ($defaults['verb_mood'] ?? ''));
    }

    public function test_word_grid_meta_payload_normalizes_case_insensitive_meta_values(): void
    {
        ll_register_part_of_speech_taxonomy();

        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        $admin = get_user_by('id', $admin_id);
        $this->assertInstanceOf(WP_User::class, $admin);
        $admin->add_cap('view_ll_tools');
        clean_user_cache($admin_id);
        wp_set_current_user($admin_id);

        $wordset_id = $this->createWordset();
        $category_id = $this->createCategory('Bulk Render');
        $this->enableBulkWordsetMeta($wordset_id);

        $noun_term_id = $this->ensurePartOfSpeechTerm('noun', 'Noun');
        $verb_term_id = $this->ensurePartOfSpeechTerm('verb', 'Verb');

        $noun_word_id = $this->createWord($wordset_id, $category_id, 'Render Noun');
        wp_set_object_terms($noun_word_id, [$noun_term_id], 'part_of_speech', false);
        update_post_meta($noun_word_id, 'll_grammatical_gender', 'masculine');
        update_post_meta($noun_word_id, 'll_grammatical_plurality', 'singular');

        $verb_word_id = $this->createWord($wordset_id, $category_id, 'Render Verb');
        wp_set_object_terms($verb_word_id, [$verb_term_id], 'part_of_speech', false);
        update_post_meta($verb_word_id, 'll_verb_tense', 'present');
        update_post_meta($verb_word_id, 'll_verb_mood', 'indicative');

        $noun_payload = ll_tools_word_grid_get_word_meta_payload($noun_word_id, $wordset_id);
        $verb_payload = ll_tools_word_grid_get_word_meta_payload($verb_word_id, $wordset_id);

        $this->assertSame('Masculine', (string) ($noun_payload['grammatical_gender']['value'] ?? ''));
        $this->assertSame('Singular', (string) ($noun_payload['grammatical_plurality']['value'] ?? ''));
        $this->assertSame('Present', (string) ($verb_payload['verb_tense']['value'] ?? ''));
        $this->assertSame('Indicative', (string) ($verb_payload['verb_mood']['value'] ?? ''));
    }

    public function test_bulk_undo_handler_restores_previous_word_meta_state(): void
    {
        ll_register_part_of_speech_taxonomy();

        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        $admin = get_user_by('id', $admin_id);
        $this->assertInstanceOf(WP_User::class, $admin);
        $admin->add_cap('view_ll_tools');
        clean_user_cache($admin_id);
        wp_set_current_user($admin_id);

        $wordset_id = $this->createWordset();
        $category_id = $this->createCategory('Bulk Undo');
        $this->enableBulkWordsetMeta($wordset_id);

        $noun_term_id = $this->ensurePartOfSpeechTerm('noun', 'Noun');
        $verb_term_id = $this->ensurePartOfSpeechTerm('verb', 'Verb');
        $adjective_term_id = $this->ensurePartOfSpeechTerm('adjective', 'Adjective');

        $word_one = $this->createWord($wordset_id, $category_id, 'Undo Noun');
        wp_set_object_terms($word_one, [$noun_term_id], 'part_of_speech', false);
        update_post_meta($word_one, 'll_grammatical_gender', 'Masculine');
        update_post_meta($word_one, 'll_grammatical_plurality', 'Singular');

        $word_two = $this->createWord($wordset_id, $category_id, 'Undo Verb');
        wp_set_object_terms($word_two, [$verb_term_id], 'part_of_speech', false);
        update_post_meta($word_two, 'll_verb_tense', 'Present');
        update_post_meta($word_two, 'll_verb_mood', 'Indicative');

        wp_set_object_terms($word_one, [$adjective_term_id], 'part_of_speech', false);
        wp_set_object_terms($word_two, [$adjective_term_id], 'part_of_speech', false);
        delete_post_meta($word_one, 'll_grammatical_gender');
        delete_post_meta($word_one, 'll_grammatical_plurality');
        delete_post_meta($word_two, 'll_verb_tense');
        delete_post_meta($word_two, 'll_verb_mood');

        $this->assertSame([$wordset_id], wp_get_post_terms($word_one, 'wordset', ['fields' => 'ids']));
        $this->assertSame([$category_id], wp_get_post_terms($word_one, 'word-category', ['fields' => 'ids']));

        $lesson_word_ids = ll_tools_get_lesson_word_ids_for_transcription($wordset_id, $category_id);
        $this->assertEqualsCanonicalizing([$word_one, $word_two], array_values(array_map('intval', $lesson_word_ids)));

        $nonce = wp_create_nonce('ll_word_grid_edit');
        $_POST = [
            'nonce' => $nonce,
            'wordset_id' => $wordset_id,
            'category_id' => $category_id,
            'mode' => 'pos',
            'snapshot' => wp_json_encode([
                [
                    'word_id' => $word_one,
                    'part_of_speech' => 'noun',
                    'grammatical_gender' => 'Masculine',
                    'grammatical_plurality' => 'Singular',
                    'verb_tense' => '',
                    'verb_mood' => '',
                ],
                [
                    'word_id' => $word_two,
                    'part_of_speech' => 'verb',
                    'grammatical_gender' => '',
                    'grammatical_plurality' => '',
                    'verb_tense' => 'Present',
                    'verb_mood' => 'Indicative',
                ],
            ]),
        ];
        $_REQUEST = $_POST;

        try {
            $response = $this->runJsonEndpoint(static function (): void {
                ll_tools_word_grid_bulk_undo_handler();
            });
        } finally {
            $_POST = [];
            $_REQUEST = [];
        }

        $this->assertTrue((bool) ($response['success'] ?? false), wp_json_encode($response));
        $this->assertSame(2, (int) ($response['data']['count'] ?? 0));

        $word_one_meta = ll_tools_word_grid_get_word_meta_payload($word_one, $wordset_id);
        $word_two_meta = ll_tools_word_grid_get_word_meta_payload($word_two, $wordset_id);

        $this->assertSame('noun', (string) ($word_one_meta['part_of_speech']['slug'] ?? ''));
        $this->assertSame('Masculine', (string) ($word_one_meta['grammatical_gender']['value'] ?? ''));
        $this->assertSame('Singular', (string) ($word_one_meta['grammatical_plurality']['value'] ?? ''));

        $this->assertSame('verb', (string) ($word_two_meta['part_of_speech']['slug'] ?? ''));
        $this->assertSame('Present', (string) ($word_two_meta['verb_tense']['value'] ?? ''));
        $this->assertSame('Indicative', (string) ($word_two_meta['verb_mood']['value'] ?? ''));
    }

    private function createWordset(): int
    {
        $term = wp_insert_term('Bulk Test Wordset ' . wp_generate_password(6, false, false), 'wordset');
        $this->assertFalse(is_wp_error($term));
        $this->assertIsArray($term);
        return (int) $term['term_id'];
    }

    private function createCategory(string $label): int
    {
        $term = wp_insert_term($label . ' ' . wp_generate_password(5, false, false), 'word-category');
        $this->assertFalse(is_wp_error($term));
        $this->assertIsArray($term);
        $term_id = (int) $term['term_id'];
        update_term_meta($term_id, 'll_quiz_prompt_type', 'text_title');
        update_term_meta($term_id, 'll_quiz_option_type', 'text_title');
        return $term_id;
    }

    private function createWord(int $wordset_id, int $category_id, string $title): int
    {
        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'draft',
            'post_title' => $title,
        ]);
        wp_set_object_terms($word_id, [$wordset_id], 'wordset', false);
        wp_set_object_terms($word_id, [$category_id], 'word-category', false);
        wp_update_post([
            'ID' => $word_id,
            'post_status' => 'publish',
        ]);
        return $word_id;
    }

    private function enableBulkWordsetMeta(int $wordset_id): void
    {
        update_term_meta($wordset_id, 'll_wordset_has_gender', 1);
        update_term_meta($wordset_id, 'll_wordset_gender_options', ['Masculine', 'Feminine']);
        update_term_meta($wordset_id, 'll_wordset_has_plurality', 1);
        update_term_meta($wordset_id, 'll_wordset_plurality_options', ['Singular', 'Plural']);
        update_term_meta($wordset_id, 'll_wordset_has_verb_tense', 1);
        update_term_meta($wordset_id, 'll_wordset_verb_tense_options', ['Present', 'Past']);
        update_term_meta($wordset_id, 'll_wordset_has_verb_mood', 1);
        update_term_meta($wordset_id, 'll_wordset_verb_mood_options', ['Indicative', 'Imperative']);
    }

    private function ensurePartOfSpeechTerm(string $slug, string $label): int
    {
        $existing = term_exists($slug, 'part_of_speech');
        if (is_array($existing) && isset($existing['term_id'])) {
            return (int) $existing['term_id'];
        }
        if (is_int($existing)) {
            return $existing;
        }

        $term = wp_insert_term($label, 'part_of_speech', ['slug' => $slug]);
        $this->assertFalse(is_wp_error($term));
        $this->assertIsArray($term);
        return (int) $term['term_id'];
    }

    /**
     * @return array<string, mixed>
     */
    private function runJsonEndpoint(callable $callback): array
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
