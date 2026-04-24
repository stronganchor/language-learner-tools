<?php
declare(strict_types=1);

final class PromptAudioImportAdminTest extends LL_Tools_TestCase
{
    protected function tearDown(): void
    {
        $_POST = [];
        $_REQUEST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';

        parent::tearDown();
    }

    public function test_prompt_audio_import_capability_defaults_to_manage_options_but_can_be_filtered(): void
    {
        ll_tools_register_or_refresh_audio_recorder_role();

        $recorder_id = self::factory()->user->create(['role' => 'audio_recorder']);
        $admin_id = self::factory()->user->create(['role' => 'administrator']);

        wp_set_current_user($recorder_id);
        $this->assertSame('manage_options', ll_tools_get_prompt_audio_import_capability());
        $this->assertFalse(ll_tools_current_user_can_prompt_audio_import());

        wp_set_current_user($admin_id);
        $this->assertTrue(ll_tools_current_user_can_prompt_audio_import());

        $filter = static function (): string {
            return 'view_ll_tools';
        };
        add_filter('ll_tools_prompt_audio_import_capability', $filter);

        try {
            wp_set_current_user($recorder_id);
            $this->assertSame('view_ll_tools', ll_tools_get_prompt_audio_import_capability());
            $this->assertTrue(ll_tools_current_user_can_prompt_audio_import());
        } finally {
            remove_filter('ll_tools_prompt_audio_import_capability', $filter);
        }
    }

    public function test_prompt_audio_import_submission_creates_draft_prompt_cards_for_recorder_queue(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $wordset_id = $this->ensureTerm('wordset', 'Prompt Audio Queue Set', 'prompt-audio-queue-set');
        $category_id = $this->ensureTerm('word-category', 'Prompt Audio Queue Category', 'prompt-audio-queue-category');

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'll_prompt_audio_import_nonce' => wp_create_nonce('ll_prompt_audio_import'),
            'll_prompt_audio_list' => "Listen and choose the matching answer.\tMatching answer prompt",
            'll_existing_wordset' => (string) $wordset_id,
            'll_existing_category' => (string) $category_id,
            'll_new_category' => '',
        ];
        $_REQUEST = $_POST;

        ob_start();
        ll_tools_render_prompt_audio_import_page();
        ob_end_clean();

        $prompt_cards = get_posts([
            'post_type' => LL_TOOLS_PROMPT_CARD_POST_TYPE,
            'post_status' => 'draft',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'meta_query' => [
                [
                    'key' => LL_TOOLS_PROMPT_CARD_PROMPT_TEXT_META_KEY,
                    'value' => 'Listen and choose the matching answer.',
                ],
            ],
        ]);

        $this->assertCount(1, $prompt_cards);
        $prompt_card_id = (int) $prompt_cards[0];
        $expected_category_id = function_exists('ll_tools_get_effective_category_id_for_wordset')
            ? (int) ll_tools_get_effective_category_id_for_wordset($category_id, $wordset_id, true)
            : $category_id;
        if ($expected_category_id <= 0) {
            $expected_category_id = $category_id;
        }
        $this->assertSame('Matching answer prompt', get_the_title($prompt_card_id));
        $this->assertTrue(has_term($wordset_id, 'wordset', $prompt_card_id));
        $this->assertTrue(has_term($expected_category_id, 'word-category', $prompt_card_id));
        $this->assertSame('', ll_tools_get_prompt_card_prompt_audio_url($prompt_card_id));

        $queue_items = ll_tools_get_recording_queue_items('', [$wordset_id], '', '', true, $admin_id);
        $target = null;
        foreach ($queue_items as $item) {
            if ((int) ($item['prompt_card_id'] ?? 0) === $prompt_card_id) {
                $target = $item;
                break;
            }
        }

        $this->assertIsArray($target);
        $this->assertTrue((bool) ($target['is_prompt_audio'] ?? false));
        $this->assertSame(['prompt'], array_values((array) ($target['missing_types'] ?? [])));
        $this->assertSame('prompt_card:' . $prompt_card_id, (string) ($target['hide_key'] ?? ''));
    }

    public function test_prompt_cards_with_prompt_audio_are_excluded_from_recorder_queue(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $wordset_id = $this->ensureTerm('wordset', 'Prompt Audio Recorded Set', 'prompt-audio-recorded-set');
        $prompt_card_id = self::factory()->post->create([
            'post_type' => LL_TOOLS_PROMPT_CARD_POST_TYPE,
            'post_status' => 'draft',
            'post_title' => 'Recorded prompt card',
        ]);
        wp_set_object_terms($prompt_card_id, [$wordset_id], 'wordset');
        update_post_meta($prompt_card_id, LL_TOOLS_PROMPT_CARD_PROMPT_TEXT_META_KEY, 'Already recorded prompt');
        update_post_meta($prompt_card_id, LL_TOOLS_PROMPT_CARD_PROMPT_AUDIO_URL_META_KEY, 'https://example.test/prompt.mp3');

        $queue_items = ll_tools_get_recording_queue_items('', [$wordset_id], '', '', true, $admin_id);
        foreach ($queue_items as $item) {
            $this->assertNotSame($prompt_card_id, (int) ($item['prompt_card_id'] ?? 0));
        }
    }

    private function ensureTerm(string $taxonomy, string $name, string $slug): int
    {
        $existing = term_exists($slug, $taxonomy);
        if ($existing) {
            return (int) (is_array($existing) ? $existing['term_id'] : $existing);
        }

        $created = wp_insert_term($name, $taxonomy, ['slug' => $slug]);
        $this->assertNotWPError($created);

        return (int) $created['term_id'];
    }
}
