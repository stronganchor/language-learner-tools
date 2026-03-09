<?php
declare(strict_types=1);

final class SettingsMaintenanceHelpersTest extends LL_Tools_TestCase
{
    protected function tearDown(): void
    {
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
}
