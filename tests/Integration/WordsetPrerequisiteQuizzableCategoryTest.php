<?php
declare(strict_types=1);

final class WordsetPrerequisiteQuizzableCategoryTest extends LL_Tools_TestCase
{
    /** @var mixed */
    private $originalIsolationOption = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalIsolationOption = get_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, null);
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '0', false);
    }

    protected function tearDown(): void
    {
        $_POST = [];
        $_REQUEST = [];

        if ($this->originalIsolationOption === null) {
            delete_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION);
        } else {
            update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, $this->originalIsolationOption, false);
        }

        parent::tearDown();
    }

    public function test_admin_category_ordering_rows_only_include_quizzable_categories(): void
    {
        $fixture = $this->createMixedQuizzableFixture();

        $rows = ll_tools_wordset_get_admin_category_ordering_rows($fixture['wordset_id']);
        $row_ids = array_map('intval', wp_list_pluck($rows, 'id'));

        $this->assertContains($fixture['quizzable_category_id'], $row_ids);
        $this->assertNotContains($fixture['non_quizzable_category_id'], $row_ids);
    }

    public function test_wordset_save_discards_non_quizzable_prerequisite_ids(): void
    {
        $fixture = $this->createMixedQuizzableFixture();
        $wordset_id = (int) $fixture['wordset_id'];
        $quizzable_category_id = (int) $fixture['quizzable_category_id'];
        $non_quizzable_category_id = (int) $fixture['non_quizzable_category_id'];

        update_term_meta($wordset_id, 'll_wordset_category_prerequisites', [
            $quizzable_category_id => [$non_quizzable_category_id],
            $non_quizzable_category_id => [$quizzable_category_id],
        ]);

        $this->setAdministratorWordsetCaps();

        $_POST = [
            'll_wordset_meta_nonce' => wp_create_nonce('ll_wordset_meta'),
            'll_wordset_category_ordering_mode' => 'prerequisite',
            'll_wordset_category_order_category_ids' => implode(',', [$quizzable_category_id, $non_quizzable_category_id]),
            'll_wordset_category_prereqs_compact_mode' => 'json-v1',
            'll_wordset_category_prereqs_compact' => wp_json_encode([
                $quizzable_category_id => [$non_quizzable_category_id],
                $non_quizzable_category_id => [$quizzable_category_id],
            ]),
        ];

        ll_save_wordset_language($wordset_id);

        $this->assertSame('', get_term_meta($wordset_id, 'll_wordset_category_prerequisites', true));
        $this->assertSame(
            [],
            ll_tools_wordset_get_category_prereq_map($wordset_id, [$quizzable_category_id, $non_quizzable_category_id])
        );
    }

    public function test_lesson_prereq_ajax_ignores_non_quizzable_prerequisite_ids(): void
    {
        $fixture = $this->createMixedQuizzableFixture();
        $wordset_id = (int) $fixture['wordset_id'];
        $quizzable_category_id = (int) $fixture['quizzable_category_id'];
        $non_quizzable_category_id = (int) $fixture['non_quizzable_category_id'];

        update_term_meta($wordset_id, 'll_wordset_category_ordering_mode', 'prerequisite');
        update_term_meta($wordset_id, 'll_wordset_category_prerequisites', [
            $quizzable_category_id => [$non_quizzable_category_id],
        ]);

        $this->setAdministratorWordsetCaps();

        $_POST = [
            'nonce' => wp_create_nonce('ll_word_grid_edit'),
            'wordset_id' => $wordset_id,
            'category_id' => $quizzable_category_id,
            'prereq_ids' => [$non_quizzable_category_id],
        ];
        $_REQUEST = $_POST;

        $response = $this->runJsonEndpoint(static function (): void {
            ll_tools_word_grid_update_category_prereqs_handler();
        });

        $this->assertTrue($response['success']);
        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        $this->assertSame([], array_map('intval', (array) ($data['selected_ids'] ?? [])));
        $this->assertSame([], (array) ($data['selected'] ?? []));
        $this->assertSame([], ll_tools_wordset_get_category_prereq_map($wordset_id, [$quizzable_category_id]));
    }

    private function setAdministratorWordsetCaps(): void
    {
        $admin_role = get_role('administrator');
        $this->assertNotNull($admin_role);
        $admin_role->add_cap('edit_wordsets');
        $admin_role->add_cap('view_ll_tools');

        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);
    }

    /**
     * @return array{wordset_id:int,quizzable_category_id:int,non_quizzable_category_id:int}
     */
    private function createMixedQuizzableFixture(): array
    {
        $wordset = wp_insert_term('Prereq Scope Wordset ' . wp_generate_password(6, false), 'wordset');
        $this->assertIsArray($wordset);
        $this->assertFalse(is_wp_error($wordset));
        $wordset_id = (int) ($wordset['term_id'] ?? 0);

        $quizzable_term = wp_insert_term('Prereq Quizzable Category ' . wp_generate_password(6, false), 'word-category');
        $non_quizzable_term = wp_insert_term('Prereq Nonquizzable Category ' . wp_generate_password(6, false), 'word-category');
        $this->assertIsArray($quizzable_term);
        $this->assertIsArray($non_quizzable_term);
        $this->assertFalse(is_wp_error($quizzable_term));
        $this->assertFalse(is_wp_error($non_quizzable_term));

        $quizzable_category_id = (int) ($quizzable_term['term_id'] ?? 0);
        $non_quizzable_category_id = (int) ($non_quizzable_term['term_id'] ?? 0);

        update_term_meta($quizzable_category_id, 'll_quiz_prompt_type', 'audio');
        update_term_meta($quizzable_category_id, 'll_quiz_option_type', 'text_title');
        update_term_meta($non_quizzable_category_id, 'll_quiz_prompt_type', 'audio');
        update_term_meta($non_quizzable_category_id, 'll_quiz_option_type', 'text_title');

        for ($index = 1; $index <= 5; $index++) {
            $this->createWordWithAudio(
                'Prereq Quizzable Word ' . $index,
                'Prereq Quizzable Translation ' . $index,
                $quizzable_category_id,
                $wordset_id,
                'prereq-quizzable-' . $index . '.mp3'
            );
        }

        for ($index = 1; $index <= 2; $index++) {
            $this->createWordWithAudio(
                'Prereq Nonquizzable Word ' . $index,
                'Prereq Nonquizzable Translation ' . $index,
                $non_quizzable_category_id,
                $wordset_id,
                'prereq-nonquizzable-' . $index . '.mp3'
            );
        }

        return [
            'wordset_id' => $wordset_id,
            'quizzable_category_id' => $quizzable_category_id,
            'non_quizzable_category_id' => $non_quizzable_category_id,
        ];
    }

    private function createWordWithAudio(string $title, string $translation, int $category_id, int $wordset_id, string $audio_file_name): int
    {
        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => $title . ' ' . wp_generate_password(4, false),
        ]);
        wp_set_post_terms($word_id, [$category_id], 'word-category', false);
        wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);
        update_post_meta($word_id, 'word_translation', $translation);

        $audio_post_id = self::factory()->post->create([
            'post_type' => 'word_audio',
            'post_status' => 'publish',
            'post_parent' => $word_id,
            'post_title' => 'Audio ' . $title,
        ]);
        update_post_meta($audio_post_id, 'audio_file_path', '/wp-content/uploads/' . $audio_file_name);

        return (int) $word_id;
    }

    /**
     * @return array<string,mixed>
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
