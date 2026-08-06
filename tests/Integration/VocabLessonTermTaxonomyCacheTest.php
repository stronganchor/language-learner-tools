<?php
declare(strict_types=1);

final class VocabLessonTermTaxonomyCacheTest extends LL_Tools_TestCase
{
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
