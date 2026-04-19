<?php
declare(strict_types=1);

final class LanguageTaxonomyBootstrapTest extends LL_Tools_TestCase
{
    protected function tearDown(): void
    {
        $this->deleteAllLanguageTerms();
        delete_option('ll_languages_populated');
        parent::tearDown();
    }

    public function test_register_language_taxonomy_does_not_seed_terms(): void
    {
        $this->deleteAllLanguageTerms();
        delete_option('ll_languages_populated');

        ll_register_language_taxonomy();

        $terms = get_terms([
            'taxonomy' => 'language',
            'hide_empty' => false,
            'fields' => 'ids',
        ]);

        $this->assertIsArray($terms);
        $this->assertSame([], $terms);
        $this->assertFalse((bool) get_option('ll_languages_populated', false));
    }

    public function test_manual_population_uses_seed_rows_filter(): void
    {
        $this->deleteAllLanguageTerms();
        delete_option('ll_languages_populated');

        $seed_rows = [
            'eng' => [
                'name' => 'English',
                'slug' => 'eng',
                'macrolanguage' => '',
            ],
            'spa' => [
                'name' => 'Spanish',
                'slug' => 'spa',
                'macrolanguage' => 'rom',
            ],
        ];

        $seed_filter = static function () use ($seed_rows): array {
            return $seed_rows;
        };
        add_filter('ll_tools_language_seed_rows', $seed_filter);

        try {
            $result = ll_tools_populate_language_taxonomy();
        } finally {
            remove_filter('ll_tools_language_seed_rows', $seed_filter);
        }

        $terms = get_terms([
            'taxonomy' => 'language',
            'hide_empty' => false,
        ]);

        $this->assertIsArray($terms);
        $this->assertCount(2, $terms);
        $this->assertSame(2, (int) ($result['created'] ?? 0));
        $this->assertTrue((bool) get_option('ll_languages_populated', false));

        $english = get_term_by('slug', 'eng', 'language');
        $spanish = get_term_by('slug', 'spa', 'language');

        $this->assertInstanceOf(WP_Term::class, $english);
        $this->assertInstanceOf(WP_Term::class, $spanish);
        $this->assertSame('English', $english->name);
        $this->assertSame('rom', $spanish->description);
    }

    private function deleteAllLanguageTerms(): void
    {
        $language_ids = get_terms([
            'taxonomy' => 'language',
            'hide_empty' => false,
            'fields' => 'ids',
        ]);

        if (!is_array($language_ids)) {
            return;
        }

        foreach ($language_ids as $language_id) {
            wp_delete_term((int) $language_id, 'language');
        }
    }
}
