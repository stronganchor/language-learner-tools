<?php
declare(strict_types=1);

final class PartOfSpeechSeedingTest extends LL_Tools_TestCase
{
    public function test_part_of_speech_defaults_seed_once_without_duplicates(): void
    {
        delete_option('ll_parts_of_speech_seeded');

        $existing_terms = get_terms([
            'taxonomy'   => 'part_of_speech',
            'hide_empty' => false,
        ]);
        if (!is_wp_error($existing_terms)) {
            foreach ($existing_terms as $term) {
                if ($term instanceof WP_Term) {
                    wp_delete_term((int) $term->term_id, 'part_of_speech');
                }
            }
        }

        ll_register_part_of_speech_taxonomy();

        $first_terms = get_terms([
            'taxonomy'   => 'part_of_speech',
            'hide_empty' => false,
        ]);
        $this->assertFalse(is_wp_error($first_terms));
        $this->assertIsArray($first_terms);

        $first_slugs = array_values(array_filter(array_map(static function ($term): string {
            return ($term instanceof WP_Term) ? (string) $term->slug : '';
        }, $first_terms)));
        sort($first_slugs, SORT_STRING);

        $expected_slugs = [
            'adjective',
            'adverb',
            'affix',
            'article',
            'classifier',
            'conjunction',
            'determiner',
            'idiom',
            'interjection',
            'noun',
            'numeral',
            'other',
            'particle',
            'phrase',
            'preposition',
            'pronoun',
            'verb',
        ];

        $this->assertSame($expected_slugs, $first_slugs);
        $this->assertTrue((bool) get_option('ll_parts_of_speech_seeded'));

        ll_register_part_of_speech_taxonomy();

        $second_terms = get_terms([
            'taxonomy'   => 'part_of_speech',
            'hide_empty' => false,
        ]);
        $this->assertFalse(is_wp_error($second_terms));
        $this->assertIsArray($second_terms);

        $second_slugs = array_values(array_filter(array_map(static function ($term): string {
            return ($term instanceof WP_Term) ? (string) $term->slug : '';
        }, $second_terms)));
        sort($second_slugs, SORT_STRING);

        $this->assertSame($expected_slugs, $second_slugs);
    }
}
