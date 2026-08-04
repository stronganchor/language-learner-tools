<?php
declare(strict_types=1);

final class ExampleSentenceMigrationCapabilityTest extends LL_Tools_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->resetMigrationState();
    }

    protected function tearDown(): void
    {
        $this->resetMigrationState();
        parent::tearDown();
    }

    public function test_view_ll_tools_editor_cannot_trigger_sitewide_example_sentence_migration(): void
    {
        $fixture = $this->createMigrationFixture('Editor');
        $user_id = self::factory()->user->create(['role' => 'editor']);
        $user = get_user_by('id', $user_id);
        $this->assertInstanceOf(WP_User::class, $user);
        $user->add_cap('view_ll_tools');
        clean_user_cache($user_id);
        wp_set_current_user($user_id);

        $this->assertTrue(current_user_can('view_ll_tools'));
        $this->assertFalse(current_user_can('manage_options'));

        ll_tools_maybe_run_example_sentence_migration();

        $this->assertSame('Editor example sentence', (string) get_post_meta($fixture['word_id'], 'word_example_sentence', true));
        $this->assertSame('Editor example translation', (string) get_post_meta($fixture['word_id'], 'word_example_sentence_translation', true));
        $this->assertSame('', (string) get_post_meta($fixture['audio_id'], 'recording_text', true));
        $this->assertSame('', (string) get_post_meta($fixture['audio_id'], 'recording_translation', true));
        $this->assertFalse(get_option('ll_tools_example_sentence_migration_done', false));
    }

    public function test_administrator_can_run_example_sentence_migration(): void
    {
        $fixture = $this->createMigrationFixture('Administrator');
        $user_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($user_id);

        $this->assertTrue(current_user_can('manage_options'));

        ll_tools_maybe_run_example_sentence_migration();

        $this->assertSame('', (string) get_post_meta($fixture['word_id'], 'word_example_sentence', true));
        $this->assertSame('', (string) get_post_meta($fixture['word_id'], 'word_example_sentence_translation', true));
        $this->assertSame('Administrator example sentence', (string) get_post_meta($fixture['audio_id'], 'recording_text', true));
        $this->assertSame('Administrator example translation', (string) get_post_meta($fixture['audio_id'], 'recording_translation', true));
        $this->assertSame('1', (string) get_option('ll_tools_example_sentence_migration_done', ''));
    }

    /** @return array{word_id:int,audio_id:int} */
    private function createMigrationFixture(string $label): array
    {
        $term = term_exists('introduction', 'recording_type');
        if (!$term) {
            $term = wp_insert_term('Introduction', 'recording_type', ['slug' => 'introduction']);
        }
        $this->assertFalse(is_wp_error($term));

        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => $label . ' migration word',
        ]);
        update_post_meta($word_id, 'word_example_sentence', $label . ' example sentence');
        update_post_meta($word_id, 'word_example_sentence_translation', $label . ' example translation');

        $audio_id = self::factory()->post->create([
            'post_type' => 'word_audio',
            'post_status' => 'publish',
            'post_parent' => $word_id,
            'post_title' => $label . ' introduction recording',
        ]);
        $assigned = wp_set_object_terms($audio_id, ['introduction'], 'recording_type', false);
        $this->assertFalse(is_wp_error($assigned));

        return [
            'word_id' => (int) $word_id,
            'audio_id' => (int) $audio_id,
        ];
    }

    private function resetMigrationState(): void
    {
        delete_option(LL_TOOLS_EXAMPLE_SENTENCE_MIGRATION_DONE_OPTION);
        delete_option(LL_TOOLS_EXAMPLE_SENTENCE_MIGRATION_STATE_OPTION);
        delete_transient(LL_TOOLS_EXAMPLE_SENTENCE_MIGRATION_LOCK_TRANSIENT);
        wp_clear_scheduled_hook(LL_TOOLS_EXAMPLE_SENTENCE_MIGRATION_HOOK);
        remove_all_filters('ll_tools_example_sentence_migration_batch_limit');
    }
}
