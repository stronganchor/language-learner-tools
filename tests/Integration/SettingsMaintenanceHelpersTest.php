<?php
declare(strict_types=1);

final class SettingsMaintenanceHelpersTest extends LL_Tools_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SERVER['REQUEST_METHOD'] = 'GET';
    }

    protected function tearDown(): void
    {
        $_POST = [];
        $_REQUEST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';

        delete_option('_transient_ll_wc_words_alpha');
        delete_option('_transient_timeout_ll_wc_words_alpha');
        delete_option('_transient_ll_wc_words_beta');
        delete_option('_transient_timeout_ll_wc_words_beta');
        delete_option('_transient_other_plugin_cache');

        parent::tearDown();
    }

    public function test_purge_legacy_word_audio_meta_only_removes_words_post_meta(): void
    {
        $wordId = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Legacy Meta Word',
        ]);
        $audioId = self::factory()->post->create([
            'post_type' => 'word_audio',
            'post_status' => 'publish',
            'post_title' => 'Recorded Child',
            'post_parent' => $wordId,
        ]);

        add_post_meta($wordId, 'word_audio_file', 'legacy-word-value.mp3');
        add_post_meta($audioId, 'word_audio_file', 'keep-audio-value.mp3');

        $result = ll_tools_purge_legacy_word_audio_meta();

        $this->assertSame(1, (int) ($result['count'] ?? 0));
        $this->assertSame(1, (int) ($result['deleted'] ?? 0));
        $this->assertSame('', (string) get_post_meta($wordId, 'word_audio_file', true));
        $this->assertSame('keep-audio-value.mp3', (string) get_post_meta($audioId, 'word_audio_file', true));
    }

    public function test_flush_quiz_word_caches_deletes_matching_transients_and_bumps_category_versions(): void
    {
        $alpha = wp_insert_term('Cache Alpha', 'word-category', ['slug' => 'cache-alpha']);
        $beta = wp_insert_term('Cache Beta', 'word-category', ['slug' => 'cache-beta']);
        $this->assertIsArray($alpha);
        $this->assertIsArray($beta);

        $alphaId = (int) $alpha['term_id'];
        $betaId = (int) $beta['term_id'];

        $alphaVersionBefore = ll_tools_get_category_cache_version($alphaId);
        $betaVersionBefore = ll_tools_get_category_cache_version($betaId);

        add_option('_transient_ll_wc_words_alpha', 'alpha cache');
        add_option('_transient_timeout_ll_wc_words_alpha', time() + HOUR_IN_SECONDS);
        add_option('_transient_ll_wc_words_beta', 'beta cache');
        add_option('_transient_timeout_ll_wc_words_beta', time() + HOUR_IN_SECONDS);
        add_option('_transient_other_plugin_cache', 'keep me');

        $allCategoryIds = get_terms([
            'taxonomy' => 'word-category',
            'hide_empty' => false,
            'fields' => 'ids',
        ]);
        $this->assertIsArray($allCategoryIds);

        $result = ll_tools_flush_quiz_word_caches();

        $this->assertGreaterThanOrEqual(4, (int) ($result['deleted'] ?? 0));
        $this->assertSame(count($allCategoryIds), (int) ($result['bumped'] ?? 0));
        $this->assertIsBool($result['object_cache_flushed'] ?? null);

        $this->assertFalse(get_option('_transient_ll_wc_words_alpha', false));
        $this->assertFalse(get_option('_transient_timeout_ll_wc_words_alpha', false));
        $this->assertFalse(get_option('_transient_ll_wc_words_beta', false));
        $this->assertFalse(get_option('_transient_timeout_ll_wc_words_beta', false));
        $this->assertSame('keep me', get_option('_transient_other_plugin_cache'));

        $this->assertSame($alphaVersionBefore + 1, ll_tools_get_category_cache_version($alphaId));
        $this->assertSame($betaVersionBefore + 1, ll_tools_get_category_cache_version($betaId));
    }

    public function test_settings_page_flush_action_requires_maintenance_capability(): void
    {
        $user_id = self::factory()->user->create(['role' => 'author']);
        $user = get_user_by('id', $user_id);
        $this->assertInstanceOf(WP_User::class, $user);
        $user->add_cap('view_ll_tools');
        clean_user_cache($user_id);
        wp_set_current_user($user_id);

        add_option('_transient_ll_wc_words_alpha', 'alpha cache');
        add_option('_transient_timeout_ll_wc_words_alpha', time() + HOUR_IN_SECONDS);

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'll_tools_flush_quiz_cache' => '1',
            'll_tools_flush_quiz_cache_nonce' => wp_create_nonce('ll_tools_flush_quiz_cache'),
        ];
        $_REQUEST = $_POST;

        ob_start();
        ll_render_settings_page();
        $output = (string) ob_get_clean();

        $this->assertSame('alpha cache', get_option('_transient_ll_wc_words_alpha'));
        $this->assertStringContainsString('You do not have permission to run maintenance actions.', $output);
        $this->assertStringNotContainsString('Flush Quiz Caches', $output);
    }

    public function test_settings_page_purge_action_requires_maintenance_capability(): void
    {
        $user_id = self::factory()->user->create(['role' => 'author']);
        $user = get_user_by('id', $user_id);
        $this->assertInstanceOf(WP_User::class, $user);
        $user->add_cap('view_ll_tools');
        clean_user_cache($user_id);
        wp_set_current_user($user_id);

        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Protected Legacy Meta Word',
        ]);
        add_post_meta($word_id, 'word_audio_file', 'legacy-protected.mp3');

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'll_tools_purge_legacy_audio' => '1',
            'll_tools_purge_legacy_audio_nonce' => wp_create_nonce('ll_tools_purge_legacy_audio'),
        ];
        $_REQUEST = $_POST;

        ob_start();
        ll_render_settings_page();
        $output = (string) ob_get_clean();

        $this->assertSame('legacy-protected.mp3', (string) get_post_meta($word_id, 'word_audio_file', true));
        $this->assertStringContainsString('You do not have permission to run maintenance actions.', $output);
        $this->assertStringNotContainsString('Purge Legacy Meta', $output);
    }
}
