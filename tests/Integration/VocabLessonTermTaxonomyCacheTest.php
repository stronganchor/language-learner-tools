<?php
declare(strict_types=1);

final class VocabLessonTermTaxonomyCacheTest extends LL_Tools_TestCase
{
    public function test_next_request_uses_replacement_wordset_taxonomy_identity_for_scoped_counts(): void
    {
        global $wpdb;

        $wordset_id = self::factory()->term->create(['taxonomy' => 'wordset']);
        $this->completeLlToolsSimulatedRequest();
        $old_taxonomy_id = ll_tools_vocab_lesson_get_wordset_term_taxonomy_id($wordset_id);
        $this->assertGreaterThan(0, $old_taxonomy_id);
        $this->assertSame($old_taxonomy_id, ll_tools_wordset_page_get_term_taxonomy_id($wordset_id, 'wordset'));

        // Model fixture rollback/recreation while the PHP process survives:
        // the same public term ID now points at a different taxonomy row.
        $this->assertSame(1, $wpdb->delete($wpdb->term_taxonomy, ['term_taxonomy_id' => $old_taxonomy_id], ['%d']));
        $this->assertSame(1, $wpdb->insert($wpdb->term_taxonomy, [
            'term_id' => $wordset_id,
            'taxonomy' => 'wordset',
            'description' => '',
            'parent' => 0,
            'count' => 0,
        ], ['%d', '%s', '%s', '%d', '%d']));
        $new_taxonomy_id = (int) $wpdb->insert_id;
        $this->assertNotSame($old_taxonomy_id, $new_taxonomy_id);
        clean_term_cache($wordset_id, 'wordset');
        $this->completeLlToolsSimulatedRequest();

        $category_id = self::factory()->term->create(['taxonomy' => 'word-category']);
        ll_tools_set_category_wordset_owner($category_id, $wordset_id);
        update_term_meta($category_id, 'll_quiz_prompt_type', 'text_title');
        update_term_meta($category_id, 'll_quiz_option_type', 'text_title');
        $word_id = self::factory()->post->create(['post_type' => 'words', 'post_status' => 'publish']);
        wp_set_object_terms($word_id, [$wordset_id], 'wordset');
        wp_set_object_terms($word_id, [$category_id], 'word-category');
        $this->completeLlToolsSimulatedRequest();

        $counts = ll_tools_get_vocab_lesson_deepest_counts_for_wordset($wordset_id);
        $this->assertTrue($counts['complete']);
        $this->assertSame([$category_id => 1], $counts['all']);
        $complete = false;
        $summaries = ll_tools_wordset_page_get_category_word_status_summaries($wordset_id, [$category_id], false, $complete);
        $this->assertTrue($complete);
        $this->assertSame(1, $summaries[$category_id]['publish']);
        $this->assertSame($new_taxonomy_id, ll_tools_vocab_lesson_get_wordset_term_taxonomy_id($wordset_id));
        $this->assertSame($new_taxonomy_id, ll_tools_wordset_page_get_term_taxonomy_id($wordset_id, 'wordset'));
    }

    public function test_missing_future_wordset_id_is_resolved_after_the_term_is_created(): void
    {
        global $wpdb;

        $table_status = $wpdb->get_row($wpdb->prepare(
            'SHOW TABLE STATUS WHERE Name = %s',
            $wpdb->terms
        ), ARRAY_A);
        $this->assertIsArray($table_status);
        $future_wordset_id = (int) ($table_status['Auto_increment'] ?? 0);
        $this->assertGreaterThan(0, $future_wordset_id);

        $lookup_complete = false;
        $this->assertSame(
            0,
            ll_tools_vocab_lesson_get_wordset_term_taxonomy_id($future_wordset_id, $lookup_complete)
        );
        $this->assertTrue($lookup_complete);

        $wordset = wp_insert_term(
            'Future Taxonomy Cache Wordset ' . $future_wordset_id,
            'wordset',
            ['slug' => 'future-taxonomy-cache-wordset-' . $future_wordset_id]
        );
        $this->assertIsArray($wordset);
        $this->assertSame($future_wordset_id, (int) ($wordset['term_id'] ?? 0));

        $raw_term_taxonomy_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE term_id = %d AND taxonomy = %s LIMIT 1",
            $future_wordset_id,
            'wordset'
        ));
        $this->assertGreaterThan(0, $raw_term_taxonomy_id);

        $lookup_complete = false;
        $this->assertSame(
            $raw_term_taxonomy_id,
            ll_tools_vocab_lesson_get_wordset_term_taxonomy_id($future_wordset_id, $lookup_complete)
        );
        $this->assertTrue($lookup_complete);
    }
}
