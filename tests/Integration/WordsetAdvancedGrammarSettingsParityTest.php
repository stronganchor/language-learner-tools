<?php
declare(strict_types=1);

final class WordsetAdvancedGrammarSettingsParityTest extends LL_Tools_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        delete_option(LL_TOOLS_WORDSET_GRAMMAR_REWRITE_STATE_OPTION);
        delete_transient(LL_TOOLS_WORDSET_GRAMMAR_REWRITE_LOCK);
        wp_clear_scheduled_hook(LL_TOOLS_WORDSET_GRAMMAR_REWRITE_HOOK);
    }

    protected function tearDown(): void
    {
        delete_option(LL_TOOLS_WORDSET_GRAMMAR_REWRITE_STATE_OPTION);
        delete_transient(LL_TOOLS_WORDSET_GRAMMAR_REWRITE_LOCK);
        wp_clear_scheduled_hook(LL_TOOLS_WORDSET_GRAMMAR_REWRITE_HOOK);
        parent::tearDown();
    }

    public function test_taxonomy_and_manager_saves_keep_grammar_settings_in_parity(): void
    {
        $taxonomy_wordset_id = $this->createWordset('Taxonomy Grammar Parity');
        $manager_wordset_id = $this->createWordset('Manager Grammar Parity');

        $administrator_role = get_role('administrator');
        $this->assertNotNull($administrator_role);
        $administrator_role->add_cap('edit_wordsets');
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        $submission = [
            'll_wordset_has_gender' => '1',
            'll_wordset_gender_options' => "Animate\nInanimate",
            'll_wordset_gender_symbol_masculine' => " <strong>A</strong>\n",
            'll_wordset_gender_symbol_feminine' => "I\u{FE0F}",
            'll_wordset_gender_color_masculine' => '#ABCDEF',
            'll_wordset_gender_color_feminine' => 'not-a-color',
            'll_wordset_gender_color_other' => '#123abc',
            'll_wordset_has_plurality' => '1',
            'll_wordset_plurality_options' => "Singular\nDual\nPlural",
            'll_wordset_has_verb_tense' => '1',
            'll_wordset_verb_tense_options' => "Present\nPast",
            'll_wordset_has_verb_mood' => '1',
            'll_wordset_verb_mood_options' => "Indicative\nImperative",
        ];

        $original_post = $_POST;
        try {
            $_POST = array_merge($submission, [
                'll_wordset_meta_nonce' => wp_create_nonce('ll_wordset_meta'),
            ]);
            ll_save_wordset_language($taxonomy_wordset_id);

            $_POST = $submission;
            $manager_result = ll_tools_wordset_page_save_advanced_settings($manager_wordset_id);
            $this->assertTrue($manager_result);

            $this->assertGrammarMetaMatches($taxonomy_wordset_id, $manager_wordset_id);
            $this->assertGrammarRewriteTasksMatch($taxonomy_wordset_id, $manager_wordset_id);
            $this->assertCount(4, $this->getGrammarRewriteTaskEffects($taxonomy_wordset_id));
            $this->assertSame(
                'A',
                (string) get_term_meta($taxonomy_wordset_id, ll_tools_wordset_get_gender_symbol_meta_key('masculine'), true)
            );
            $this->assertSame(
                'I',
                (string) get_term_meta($taxonomy_wordset_id, ll_tools_wordset_get_gender_symbol_meta_key('feminine'), true)
            );
            $this->assertSame(
                (string) sanitize_hex_color('#ABCDEF'),
                (string) get_term_meta($taxonomy_wordset_id, 'll_wordset_gender_color_masculine', true)
            );
            $this->assertSame('', get_term_meta($taxonomy_wordset_id, 'll_wordset_gender_color_feminine', true));
            $this->assertSame(
                (string) sanitize_hex_color('#123abc'),
                (string) get_term_meta($taxonomy_wordset_id, 'll_wordset_gender_color_other', true)
            );

            $invalid_nonempty_submission = array_merge($submission, [
                'll_wordset_gender_options' => '<script></script>',
            ]);
            $_POST = array_merge($invalid_nonempty_submission, [
                'll_wordset_meta_nonce' => wp_create_nonce('ll_wordset_meta'),
            ]);
            ll_save_wordset_language($taxonomy_wordset_id);

            $_POST = $invalid_nonempty_submission;
            $manager_result = ll_tools_wordset_page_save_advanced_settings($manager_wordset_id);
            $this->assertTrue($manager_result);

            $this->assertGrammarMetaMatches($taxonomy_wordset_id, $manager_wordset_id);
            $this->assertGrammarRewriteTasksMatch($taxonomy_wordset_id, $manager_wordset_id);
            $this->assertCount(4, $this->getGrammarRewriteTaskEffects($taxonomy_wordset_id));
            $this->assertSame(
                ['Animate', 'Inanimate'],
                array_values((array) get_term_meta($taxonomy_wordset_id, 'll_wordset_gender_options', true))
            );

            $clear_submission = [
                'll_wordset_games_image_size' => 'default',
            ];
            $_POST = array_merge($clear_submission, [
                'll_wordset_meta_nonce' => wp_create_nonce('ll_wordset_meta'),
            ]);
            ll_save_wordset_language($taxonomy_wordset_id);

            $_POST = $clear_submission;
            $manager_result = ll_tools_wordset_page_save_advanced_settings($manager_wordset_id);
            $this->assertTrue($manager_result);

            $this->assertGrammarMetaMatches($taxonomy_wordset_id, $manager_wordset_id);
            $this->assertGrammarRewriteTasksMatch($taxonomy_wordset_id, $manager_wordset_id);
            $this->assertCount(8, $this->getGrammarRewriteTaskEffects($taxonomy_wordset_id));
            $this->assertSame('0', (string) get_term_meta($taxonomy_wordset_id, 'll_wordset_has_gender', true));
            $this->assertSame('', get_term_meta($taxonomy_wordset_id, 'll_wordset_gender_options', true));
            $this->assertSame('', get_term_meta($taxonomy_wordset_id, 'll_wordset_plurality_options', true));
            $this->assertSame('', get_term_meta($taxonomy_wordset_id, 'll_wordset_verb_tense_options', true));
            $this->assertSame('', get_term_meta($taxonomy_wordset_id, 'll_wordset_verb_mood_options', true));
        } finally {
            $_POST = $original_post;
        }
    }

    public function test_shared_saver_reads_the_explicit_request_instead_of_post_globals(): void
    {
        $wordset_id = $this->createWordset('Explicit Grammar Request');
        $original_post = $_POST;

        try {
            $_POST = [
                'll_wordset_gender_options' => 'Global value must not be used',
            ];

            ll_tools_wordset_save_advanced_grammar_settings($wordset_id, [
                'll_wordset_has_gender' => '1',
                'll_wordset_gender_options' => "Requested One\nRequested Two",
                'll_wordset_has_plurality' => '1',
                'll_wordset_plurality_options' => "One\nMany",
            ]);

            $this->assertSame(
                ['Requested One', 'Requested Two'],
                array_values((array) get_term_meta($wordset_id, 'll_wordset_gender_options', true))
            );
            $this->assertSame(
                ['One', 'Many'],
                array_values((array) get_term_meta($wordset_id, 'll_wordset_plurality_options', true))
            );
        } finally {
            $_POST = $original_post;
        }
    }

    private function assertGrammarMetaMatches(int $expected_wordset_id, int $actual_wordset_id): void
    {
        $meta_keys = [
            'll_wordset_has_gender',
            'll_wordset_gender_options',
            ll_tools_wordset_get_gender_symbol_meta_key('masculine'),
            ll_tools_wordset_get_gender_symbol_meta_key('feminine'),
            'll_wordset_gender_color_masculine',
            'll_wordset_gender_color_feminine',
            'll_wordset_gender_color_other',
            'll_wordset_has_plurality',
            'll_wordset_plurality_options',
            'll_wordset_has_verb_tense',
            'll_wordset_verb_tense_options',
            'll_wordset_has_verb_mood',
            'll_wordset_verb_mood_options',
        ];

        foreach ($meta_keys as $meta_key) {
            $this->assertSame(
                get_term_meta($expected_wordset_id, $meta_key, true),
                get_term_meta($actual_wordset_id, $meta_key, true),
                'Grammar meta drifted for ' . $meta_key
            );
        }
    }

    private function assertGrammarRewriteTasksMatch(int $expected_wordset_id, int $actual_wordset_id): void
    {
        $this->assertSame(
            $this->getGrammarRewriteTaskEffects($expected_wordset_id),
            $this->getGrammarRewriteTaskEffects($actual_wordset_id)
        );
    }

    private function getGrammarRewriteTaskEffects(int $wordset_id): array
    {
        $state = ll_tools_wordset_get_grammar_rewrite_state($wordset_id);

        return array_values(array_map(static function (array $task): array {
            return [
                'meta_key' => (string) ($task['meta_key'] ?? ''),
                'map' => (array) ($task['map'] ?? []),
                'strip_variation_selectors' => !empty($task['strip_variation_selectors']),
                'status' => (string) ($task['status'] ?? ''),
            ];
        }, array_filter((array) ($state['tasks'] ?? []), 'is_array')));
    }

    private function createWordset(string $label): int
    {
        $term = wp_insert_term($label . ' ' . wp_generate_password(8, false, false), 'wordset');
        $this->assertIsArray($term);
        $this->assertFalse(is_wp_error($term));

        $wordset_id = (int) ($term['term_id'] ?? 0);
        $this->assertGreaterThan(0, $wordset_id);

        return $wordset_id;
    }
}
