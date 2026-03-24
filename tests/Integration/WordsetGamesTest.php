<?php
declare(strict_types=1);

final class WordsetGamesTest extends LL_Tools_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (function_exists('ll_tools_install_user_progress_schema')) {
            ll_tools_install_user_progress_schema();
        }
    }

    protected function tearDown(): void
    {
        set_query_var('ll_wordset_view', null);
        parent::tearDown();
    }

    public function test_games_view_url_normalization_and_rewrite_detection_work(): void
    {
        $term = wp_insert_term('Games View Wordset ' . wp_generate_password(6, false), 'wordset');
        $this->assertFalse(is_wp_error($term));
        $this->assertIsArray($term);

        $wordset = get_term((int) $term['term_id'], 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset);

        $slug = (string) $wordset->slug;
        update_option('rewrite_rules', [
            '^' . preg_quote($slug, '/') . '/?$' => 'index.php?ll_wordset_page=' . $slug,
            '^' . preg_quote($slug, '/') . '/progress/?$' => 'index.php?ll_wordset_page=' . $slug . '&ll_wordset_view=progress',
            '^' . preg_quote($slug, '/') . '/hidden-categories/?$' => 'index.php?ll_wordset_page=' . $slug . '&ll_wordset_view=hidden-categories',
            '^' . preg_quote($slug, '/') . '/settings/?$' => 'index.php?ll_wordset_page=' . $slug . '&ll_wordset_view=settings',
            '^' . preg_quote($slug, '/') . '/games/?$' => 'index.php?ll_wordset_page=' . $slug . '&ll_wordset_view=games',
        ]);

        set_query_var('ll_wordset_view', 'games');

        $gamesUrl = (string) ll_tools_get_wordset_page_view_url($wordset, 'games');
        $this->assertStringContainsString('ll_wordset_page=' . rawurlencode($slug), $gamesUrl);
        $this->assertStringContainsString('ll_wordset_view=games', $gamesUrl);
        $this->assertSame('games', ll_tools_get_wordset_page_view());
        $this->assertTrue(ll_tools_wordset_page_has_rewrite_routes($slug));
    }

    public function test_wordset_page_render_outputs_games_navigation_and_games_view_shell(): void
    {
        $term = wp_insert_term('Rendered Games Wordset ' . wp_generate_password(6, false), 'wordset');
        $this->assertFalse(is_wp_error($term));
        $this->assertIsArray($term);

        set_query_var('ll_wordset_view', '');
        $mainHtml = ll_tools_render_wordset_page_content((int) $term['term_id']);
        $this->assertStringContainsString('ll-wordset-link-chip--games', $mainHtml);
        $this->assertStringContainsString('ll_wordset_view=games', $mainHtml);

        set_query_var('ll_wordset_view', 'games');
        $gamesHtml = ll_tools_render_wordset_page_content((int) $term['term_id']);
        $this->assertStringContainsString('data-ll-wordset-games-root', $gamesHtml);
        $this->assertStringContainsString('data-ll-wordset-game-run-modal', $gamesHtml);
        $this->assertStringContainsString('data-ll-wordset-games-back', $gamesHtml);
        $this->assertStringContainsString('data-ll-wordset-games-back-label', $gamesHtml);
        $this->assertStringContainsString('data-ll-wordset-games-page-title', $gamesHtml);
        $this->assertStringContainsString('data-game-slug="space-shooter"', $gamesHtml);
        $this->assertStringContainsString('data-game-slug="bubble-pop"', $gamesHtml);
        $this->assertStringNotContainsString('data-ll-wordset-game-close', $gamesHtml);
    }

    public function test_space_shooter_pool_only_includes_studied_words_with_images_and_core_prompt_recordings_in_scope(): void
    {
        $fixture = $this->createGamesFixture(5);
        wp_set_current_user((int) $fixture['user_id']);

        $pool = ll_tools_wordset_games_build_space_shooter_pool((int) $fixture['wordset_id'], (int) $fixture['user_id']);
        $returnedIds = array_map(static function ($row): int {
            return is_array($row) ? (int) ($row['id'] ?? 0) : 0;
        }, (array) ($pool['words'] ?? []));
        sort($returnedIds);

        $expectedIds = array_map('intval', $fixture['eligible_word_ids']);
        sort($expectedIds);

        $this->assertSame(5, (int) ($pool['available_word_count'] ?? 0));
        $this->assertTrue((bool) ($pool['launchable'] ?? false));
        $this->assertSame($expectedIds, $returnedIds);
        $this->assertNotContains((int) $fixture['missing_image_word_id'], $returnedIds);
        $this->assertNotContains((int) $fixture['sentence_only_word_id'], $returnedIds);
        $this->assertNotContains((int) $fixture['mastered_word_id'], $returnedIds);
        $this->assertNotContains((int) $fixture['new_word_id'], $returnedIds);
        $this->assertNotContains((int) $fixture['out_of_scope_word_id'], $returnedIds);

        $firstWord = (array) (($pool['words'][0] ?? []));
        $this->assertArrayHasKey('option_blocked_ids', $firstWord);
        $this->assertArrayHasKey('similar_word_id', $firstWord);
        $this->assertArrayHasKey('practice_recording_types', $firstWord);
        $this->assertArrayHasKey('game_prompt_recording_types', $firstWord);
        $this->assertNotEmpty($firstWord['game_prompt_recording_types'] ?? []);
        $this->assertNotEmpty(array_intersect(
            ['question', 'isolation', 'introduction'],
            (array) ($firstWord['game_prompt_recording_types'] ?? [])
        ));
    }

    public function test_space_shooter_pool_is_unavailable_below_minimum_count(): void
    {
        $fixture = $this->createGamesFixture(3);
        wp_set_current_user((int) $fixture['user_id']);

        $pool = ll_tools_wordset_games_build_space_shooter_pool((int) $fixture['wordset_id'], (int) $fixture['user_id']);

        $this->assertSame(4, (int) ($pool['available_word_count'] ?? 0));
        $this->assertFalse((bool) ($pool['launchable'] ?? true));
        $this->assertSame(5, (int) ($pool['minimum_word_count'] ?? 0));
    }

    public function test_bubble_pop_pool_reuses_practice_mode_word_selection_rules(): void
    {
        $fixture = $this->createGamesFixture(5);
        wp_set_current_user((int) $fixture['user_id']);

        $spacePool = ll_tools_wordset_games_build_space_shooter_pool((int) $fixture['wordset_id'], (int) $fixture['user_id']);
        $bubblePool = ll_tools_wordset_games_build_bubble_pop_pool((int) $fixture['wordset_id'], (int) $fixture['user_id']);

        $spaceIds = array_values(array_filter(array_map(static function ($row): int {
            return is_array($row) ? (int) ($row['id'] ?? 0) : 0;
        }, (array) ($spacePool['words'] ?? []))));
        $bubbleIds = array_values(array_filter(array_map(static function ($row): int {
            return is_array($row) ? (int) ($row['id'] ?? 0) : 0;
        }, (array) ($bubblePool['words'] ?? []))));
        sort($spaceIds);
        sort($bubbleIds);

        $this->assertSame('space-shooter', (string) ($spacePool['slug'] ?? ''));
        $this->assertSame('bubble-pop', (string) ($bubblePool['slug'] ?? ''));
        $this->assertSame((int) ($spacePool['available_word_count'] ?? 0), (int) ($bubblePool['available_word_count'] ?? -1));
        $this->assertSame((int) ($spacePool['minimum_word_count'] ?? 0), (int) ($bubblePool['minimum_word_count'] ?? -1));
        $this->assertSame((string) ($spacePool['pool_source'] ?? ''), (string) ($bubblePool['pool_source'] ?? ''));
        $this->assertSame($spaceIds, $bubbleIds);
    }

    public function test_space_shooter_pool_caps_launch_words_but_preserves_full_available_count(): void
    {
        $fixture = $this->createGamesFixture(10);
        wp_set_current_user((int) $fixture['user_id']);

        $capFilter = static function (): int {
            return 6;
        };
        add_filter('ll_tools_wordset_games_space_shooter_launch_word_cap', $capFilter);

        try {
            $pool = ll_tools_wordset_games_build_space_shooter_pool((int) $fixture['wordset_id'], (int) $fixture['user_id']);
        } finally {
            remove_filter('ll_tools_wordset_games_space_shooter_launch_word_cap', $capFilter);
        }

        $returnedIds = array_values(array_filter(array_map(static function ($row): int {
            return is_array($row) ? (int) ($row['id'] ?? 0) : 0;
        }, (array) ($pool['words'] ?? []))));
        $expectedLaunchIds = array_values(array_intersect(
            array_map('intval', $fixture['eligible_word_ids']),
            $returnedIds
        ));
        sort($returnedIds);
        sort($expectedLaunchIds);

        $this->assertSame(10, (int) ($pool['available_word_count'] ?? 0));
        $this->assertSame(6, (int) ($pool['launch_word_cap'] ?? 0));
        $this->assertSame(6, (int) ($pool['launch_word_count'] ?? 0));
        $this->assertCount(6, (array) ($pool['words'] ?? []));
        $this->assertTrue((bool) ($pool['launchable'] ?? false));
        $this->assertSame($expectedLaunchIds, $returnedIds);
    }

    public function test_space_shooter_pool_falls_back_to_mastered_words_when_studied_words_are_below_minimum_count(): void
    {
        $userId = self::factory()->user->create(['role' => 'subscriber']);
        $wordset = wp_insert_term('Games Mastered Fallback ' . wp_generate_password(6, false), 'wordset');
        $category = wp_insert_term('Games Mastered Category ' . wp_generate_password(6, false), 'word-category');

        $this->assertFalse(is_wp_error($wordset));
        $this->assertFalse(is_wp_error($category));
        $this->assertIsArray($wordset);
        $this->assertIsArray($category);

        $wordsetId = (int) $wordset['term_id'];
        $categoryId = (int) $category['term_id'];

        update_term_meta($categoryId, 'll_quiz_prompt_type', 'audio');
        update_term_meta($categoryId, 'll_quiz_option_type', 'image');

        $expectedIds = [];
        for ($index = 1; $index <= 3; $index++) {
            $wordId = $this->createWordWithGameMedia(
                'Fallback Studied Word ' . $index,
                'Fallback Studied Translation ' . $index,
                $categoryId,
                $wordsetId,
                true,
                ['isolation' => 'Fallback studied ' . $index]
            );
            $this->seedWordProgressRow($userId, $wordId, $categoryId, $wordsetId, [
                'total_coverage' => 2,
                'coverage_practice' => 2,
                'correct_clean' => 1,
                'incorrect' => 1,
                'stage' => 1,
            ]);
            $expectedIds[] = $wordId;
        }

        for ($index = 1; $index <= 2; $index++) {
            $wordId = $this->createWordWithGameMedia(
                'Fallback Mastered Word ' . $index,
                'Fallback Mastered Translation ' . $index,
                $categoryId,
                $wordsetId,
                true,
                ['question' => 'Fallback mastered ' . $index]
            );
            $this->seedWordProgressRow($userId, $wordId, $categoryId, $wordsetId, [
                'total_coverage' => 6,
                'coverage_practice' => 6,
                'correct_clean' => 4,
                'incorrect' => 0,
                'lapse_count' => 0,
                'stage' => 6,
            ]);
            $expectedIds[] = $wordId;
        }

        $newWordId = $this->createWordWithGameMedia(
            'Fallback New Word',
            'Fallback New Translation',
            $categoryId,
            $wordsetId,
            true,
            ['introduction' => 'Fallback new']
        );

        wp_set_current_user($userId);
        $pool = ll_tools_wordset_games_build_space_shooter_pool($wordsetId, $userId);
        $returnedIds = array_values(array_filter(array_map(static function ($row): int {
            return is_array($row) ? (int) ($row['id'] ?? 0) : 0;
        }, (array) ($pool['words'] ?? []))));

        sort($expectedIds);
        sort($returnedIds);

        $this->assertSame('studied_mastered', (string) ($pool['pool_source'] ?? ''));
        $this->assertSame(5, (int) ($pool['available_word_count'] ?? 0));
        $this->assertTrue((bool) ($pool['launchable'] ?? false));
        $this->assertSame($expectedIds, $returnedIds);
        $this->assertNotContains($newWordId, $returnedIds);
    }

    public function test_space_shooter_pool_uses_lowest_frontier_categories_when_no_progress_exists(): void
    {
        $userId = self::factory()->user->create(['role' => 'subscriber']);
        $wordset = wp_insert_term('Games Frontier Fallback ' . wp_generate_password(6, false), 'wordset');
        $rootA = wp_insert_term('Games Frontier A ' . wp_generate_password(4, false), 'word-category');
        $rootB = wp_insert_term('Games Frontier B ' . wp_generate_password(4, false), 'word-category');
        $advanced = wp_insert_term('Games Frontier Advanced ' . wp_generate_password(4, false), 'word-category');

        $this->assertFalse(is_wp_error($wordset));
        $this->assertFalse(is_wp_error($rootA));
        $this->assertFalse(is_wp_error($rootB));
        $this->assertFalse(is_wp_error($advanced));
        $this->assertIsArray($wordset);
        $this->assertIsArray($rootA);
        $this->assertIsArray($rootB);
        $this->assertIsArray($advanced);

        $wordsetId = (int) $wordset['term_id'];
        $rootAId = (int) $rootA['term_id'];
        $rootBId = (int) $rootB['term_id'];
        $advancedId = (int) $advanced['term_id'];

        foreach ([$rootAId, $rootBId, $advancedId] as $categoryId) {
            update_term_meta($categoryId, 'll_quiz_prompt_type', 'audio');
            update_term_meta($categoryId, 'll_quiz_option_type', 'image');
        }

        update_term_meta($wordsetId, 'll_wordset_category_ordering_mode', 'prerequisite');
        update_term_meta($wordsetId, 'll_wordset_category_prerequisites', [
            $advancedId => [$rootAId],
        ]);

        $expectedIds = [];
        for ($index = 1; $index <= 3; $index++) {
            $expectedIds[] = $this->createWordWithGameMedia(
                'Frontier Root A Word ' . $index,
                'Frontier Root A Translation ' . $index,
                $rootAId,
                $wordsetId,
                true,
                ['question' => 'Frontier root A ' . $index]
            );
        }
        for ($index = 1; $index <= 2; $index++) {
            $expectedIds[] = $this->createWordWithGameMedia(
                'Frontier Root B Word ' . $index,
                'Frontier Root B Translation ' . $index,
                $rootBId,
                $wordsetId,
                true,
                ['isolation' => 'Frontier root B ' . $index]
            );
        }
        for ($index = 1; $index <= 2; $index++) {
            $this->createWordWithGameMedia(
                'Frontier Advanced Word ' . $index,
                'Frontier Advanced Translation ' . $index,
                $advancedId,
                $wordsetId,
                true,
                ['introduction' => 'Frontier advanced ' . $index]
            );
        }

        wp_set_current_user($userId);
        $pool = ll_tools_wordset_games_build_space_shooter_pool($wordsetId, $userId);
        $returnedIds = array_values(array_filter(array_map(static function ($row): int {
            return is_array($row) ? (int) ($row['id'] ?? 0) : 0;
        }, (array) ($pool['words'] ?? []))));
        $returnedCategoryIds = array_values(array_unique(array_filter(array_map(static function ($row): int {
            return is_array($row) ? (int) ($row['category_id'] ?? 0) : 0;
        }, (array) ($pool['words'] ?? [])))));

        sort($expectedIds);
        sort($returnedIds);
        sort($returnedCategoryIds);

        $this->assertSame('frontier_new', (string) ($pool['pool_source'] ?? ''));
        $this->assertSame(5, (int) ($pool['available_word_count'] ?? 0));
        $this->assertTrue((bool) ($pool['launchable'] ?? false));
        $this->assertSame($expectedIds, $returnedIds);
        $this->assertSame([$rootAId, $rootBId], $returnedCategoryIds);
    }

    public function test_games_bootstrap_ajax_enforces_login_and_permission_and_returns_catalog(): void
    {
        $fixture = $this->createGamesFixture(5);
        $nonce = wp_create_nonce('ll_user_study');

        wp_set_current_user(0);
        $_POST = [
            'nonce' => $nonce,
            'wordset_id' => (int) $fixture['wordset_id'],
        ];
        $_REQUEST = $_POST;

        try {
            $loggedOut = $this->runJsonEndpoint(static function (): void {
                ll_tools_wordset_games_bootstrap_ajax();
            });
        } finally {
            $_POST = [];
            $_REQUEST = [];
        }

        $this->assertFalse((bool) ($loggedOut['success'] ?? true));
        $this->assertSame('Login required.', (string) ($loggedOut['data']['message'] ?? ''));

        wp_set_current_user((int) $fixture['user_id']);
        $loggedInNonce = wp_create_nonce('ll_user_study');
        $denyAccess = static function (): bool {
            return false;
        };
        add_filter('ll_tools_allow_basic_user_study_access', $denyAccess);

        $_POST = [
            'nonce' => $loggedInNonce,
            'wordset_id' => (int) $fixture['wordset_id'],
        ];
        $_REQUEST = $_POST;

        try {
            $forbidden = $this->runJsonEndpoint(static function (): void {
                ll_tools_wordset_games_bootstrap_ajax();
            });
        } finally {
            $_POST = [];
            $_REQUEST = [];
            remove_filter('ll_tools_allow_basic_user_study_access', $denyAccess);
        }

        $this->assertFalse((bool) ($forbidden['success'] ?? true));
        $this->assertSame('You do not have permission.', (string) ($forbidden['data']['message'] ?? ''));

        $_POST = [
            'nonce' => $loggedInNonce,
            'wordset_id' => (int) $fixture['wordset_id'],
        ];
        $_REQUEST = $_POST;

        $capFilter = static function (): int {
            return 5;
        };
        add_filter('ll_tools_wordset_games_space_shooter_launch_word_cap', $capFilter);

        try {
            $allowed = $this->runJsonEndpoint(static function (): void {
                ll_tools_wordset_games_bootstrap_ajax();
            });
        } finally {
            $_POST = [];
            $_REQUEST = [];
            remove_filter('ll_tools_wordset_games_space_shooter_launch_word_cap', $capFilter);
        }

        $this->assertTrue((bool) ($allowed['success'] ?? false));
        $this->assertSame((int) $fixture['wordset_id'], (int) ($allowed['data']['wordset_id'] ?? 0));
        $this->assertIsArray($allowed['data']['games']['space-shooter'] ?? null);
        $this->assertSame(5, (int) ($allowed['data']['games']['space-shooter']['available_word_count'] ?? 0));
        $this->assertSame(5, (int) ($allowed['data']['games']['space-shooter']['launch_word_cap'] ?? 0));
        $this->assertSame(5, (int) ($allowed['data']['games']['space-shooter']['launch_word_count'] ?? 0));
        $this->assertCount(5, (array) ($allowed['data']['games']['space-shooter']['words'] ?? []));
        $this->assertTrue((bool) ($allowed['data']['games']['space-shooter']['launchable'] ?? false));
    }

    /**
     * @return array{
     *   user_id:int,
     *   wordset_id:int,
     *   eligible_word_ids:int[],
     *   missing_image_word_id:int,
     *   sentence_only_word_id:int,
     *   mastered_word_id:int,
     *   new_word_id:int,
     *   out_of_scope_word_id:int
     * }
     */
    private function createGamesFixture(int $eligibleCount): array
    {
        $userId = self::factory()->user->create(['role' => 'subscriber']);

        $wordset = wp_insert_term('Games Fixture Wordset ' . wp_generate_password(6, false), 'wordset');
        $otherWordset = wp_insert_term('Games Fixture Other Wordset ' . wp_generate_password(6, false), 'wordset');
        $category = wp_insert_term('Games Fixture Category ' . wp_generate_password(6, false), 'word-category');

        $this->assertFalse(is_wp_error($wordset));
        $this->assertFalse(is_wp_error($otherWordset));
        $this->assertFalse(is_wp_error($category));
        $this->assertIsArray($wordset);
        $this->assertIsArray($otherWordset);
        $this->assertIsArray($category);

        $wordsetId = (int) $wordset['term_id'];
        $otherWordsetId = (int) $otherWordset['term_id'];
        $categoryId = (int) $category['term_id'];

        update_term_meta($categoryId, 'll_quiz_prompt_type', 'audio');
        update_term_meta($categoryId, 'll_quiz_option_type', 'image');

        $eligibleWordIds = [];
        for ($index = 1; $index <= $eligibleCount; $index++) {
            $recordingMap = match ((($index - 1) % 5) + 1) {
                1 => ['question' => 'Eligible question ' . $index],
                2 => ['isolation' => 'Eligible isolation ' . $index],
                3 => ['introduction' => 'Eligible introduction ' . $index],
                4 => ['question' => 'Eligible question ' . $index, 'isolation' => 'Eligible isolation ' . $index],
                default => ['isolation' => 'Eligible isolation ' . $index, 'introduction' => 'Eligible introduction ' . $index],
            };
            $wordId = $this->createWordWithGameMedia(
                'Eligible Game Word ' . $index,
                'Eligible Translation ' . $index,
                $categoryId,
                $wordsetId,
                true,
                $recordingMap
            );
            $this->seedWordProgressRow($userId, $wordId, $categoryId, $wordsetId, [
                'total_coverage' => 3,
                'coverage_practice' => 3,
                'correct_clean' => 1,
                'incorrect' => 1,
                'lapse_count' => 1,
                'stage' => 1,
            ]);
            $eligibleWordIds[] = $wordId;
        }

        $missingImageWordId = $this->createWordWithGameMedia(
            'Missing Image Word',
            'Missing Image Translation',
            $categoryId,
            $wordsetId,
            false,
            ['isolation' => 'Missing image']
        );
        $this->seedWordProgressRow($userId, $missingImageWordId, $categoryId, $wordsetId, [
            'total_coverage' => 2,
            'coverage_practice' => 2,
            'correct_clean' => 1,
            'incorrect' => 1,
            'stage' => 1,
        ]);

        $sentenceOnlyWordId = $this->createWordWithGameMedia(
            'Sentence Only Word',
            'Sentence Only Translation',
            $categoryId,
            $wordsetId,
            true,
            ['sentence' => 'Sentence only']
        );
        $this->seedWordProgressRow($userId, $sentenceOnlyWordId, $categoryId, $wordsetId, [
            'total_coverage' => 2,
            'coverage_practice' => 2,
            'correct_clean' => 1,
            'incorrect' => 1,
            'stage' => 1,
        ]);

        $masteredWordId = $this->createWordWithGameMedia(
            'Mastered Word',
            'Mastered Translation',
            $categoryId,
            $wordsetId,
            true,
            ['isolation' => 'Mastered']
        );
        $this->seedWordProgressRow($userId, $masteredWordId, $categoryId, $wordsetId, [
            'total_coverage' => 6,
            'coverage_practice' => 6,
            'correct_clean' => 4,
            'incorrect' => 0,
            'lapse_count' => 0,
            'stage' => 6,
        ]);

        $newWordId = $this->createWordWithGameMedia(
            'New Word',
            'New Translation',
            $categoryId,
            $wordsetId,
            true,
            ['isolation' => 'New word']
        );

        $outOfScopeWordId = $this->createWordWithGameMedia(
            'Other Wordset Word',
            'Other Wordset Translation',
            $categoryId,
            $otherWordsetId,
            true,
            ['isolation' => 'Other wordset']
        );
        $this->seedWordProgressRow($userId, $outOfScopeWordId, $categoryId, $otherWordsetId, [
            'total_coverage' => 2,
            'coverage_practice' => 2,
            'correct_clean' => 1,
            'incorrect' => 1,
            'stage' => 1,
        ]);

        return [
            'user_id' => $userId,
            'wordset_id' => $wordsetId,
            'eligible_word_ids' => $eligibleWordIds,
            'missing_image_word_id' => $missingImageWordId,
            'sentence_only_word_id' => $sentenceOnlyWordId,
            'mastered_word_id' => $masteredWordId,
            'new_word_id' => $newWordId,
            'out_of_scope_word_id' => $outOfScopeWordId,
        ];
    }

    /**
     * @param array<string,string> $recordingTypes
     */
    private function createWordWithGameMedia(
        string $title,
        string $translation,
        int $categoryId,
        int $wordsetId,
        bool $withImage,
        array $recordingTypes
    ): int {
        $wordId = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => $title . ' ' . wp_generate_password(4, false),
        ]);

        wp_set_post_terms($wordId, [$categoryId], 'word-category', false);
        wp_set_post_terms($wordId, [$wordsetId], 'wordset', false);
        update_post_meta($wordId, 'word_translation', $translation);

        if ($withImage) {
            $attachmentId = $this->createImageAttachment(sanitize_title($title) . '.jpg');
            set_post_thumbnail($wordId, $attachmentId);
        }

        foreach ($recordingTypes as $slug => $recordingText) {
            $this->ensureRecordingTypeTerm($slug);
            $audioPostId = self::factory()->post->create([
                'post_type' => 'word_audio',
                'post_status' => 'publish',
                'post_parent' => $wordId,
                'post_title' => 'Audio ' . $slug . ' ' . $title,
            ]);
            update_post_meta($audioPostId, 'audio_file_path', '/wp-content/uploads/' . sanitize_title($title) . '-' . $slug . '.mp3');
            update_post_meta($audioPostId, 'recording_text', $recordingText);
            wp_set_post_terms($audioPostId, [$slug], 'recording_type', false);
        }

        return (int) $wordId;
    }

    private function createImageAttachment(string $filename): int
    {
        $attachmentId = self::factory()->post->create([
            'post_type' => 'attachment',
            'post_mime_type' => 'image/jpeg',
            'post_status' => 'inherit',
            'post_title' => $filename,
        ]);

        update_post_meta($attachmentId, '_wp_attached_file', '2026/03/' . $filename);

        return (int) $attachmentId;
    }

    private function ensureRecordingTypeTerm(string $slug): void
    {
        $existing = get_term_by('slug', $slug, 'recording_type');
        if ($existing instanceof WP_Term) {
            return;
        }

        $term = wp_insert_term(ucwords(str_replace('-', ' ', $slug)), 'recording_type', ['slug' => $slug]);
        $this->assertFalse(is_wp_error($term));
    }

    /**
     * @param array<string,int|string> $overrides
     */
    private function seedWordProgressRow(int $userId, int $wordId, int $categoryId, int $wordsetId, array $overrides): void
    {
        global $wpdb;
        $table = ll_tools_user_progress_table_names()['words'];
        $now = gmdate('Y-m-d H:i:s');

        $data = array_merge([
            'user_id' => $userId,
            'word_id' => $wordId,
            'category_id' => $categoryId,
            'wordset_id' => $wordsetId,
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'last_mode' => 'practice',
            'total_coverage' => 0,
            'coverage_learning' => 0,
            'coverage_practice' => 0,
            'coverage_listening' => 0,
            'coverage_gender' => 0,
            'coverage_self_check' => 0,
            'correct_clean' => 0,
            'correct_after_retry' => 0,
            'incorrect' => 0,
            'lapse_count' => 0,
            'stage' => 0,
            'updated_at' => $now,
        ], $overrides);

        $wpdb->replace($table, $data);
    }

    private function runJsonEndpoint(callable $callback): array
    {
        $dieHandler = static function (): void {
            throw new RuntimeException('wp_die');
        };
        $dieFilter = static function () use ($dieHandler) {
            return $dieHandler;
        };
        $doingAjaxFilter = static function (): bool {
            return true;
        };

        add_filter('wp_die_handler', $dieFilter);
        add_filter('wp_die_ajax_handler', $dieFilter);
        add_filter('wp_doing_ajax', $doingAjaxFilter);

        ob_start();
        try {
            $callback();
        } catch (RuntimeException $e) {
            $this->assertSame('wp_die', $e->getMessage());
        } finally {
            $output = (string) ob_get_clean();
            remove_filter('wp_die_handler', $dieFilter);
            remove_filter('wp_die_ajax_handler', $dieFilter);
            remove_filter('wp_doing_ajax', $doingAjaxFilter);
        }

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded, 'Expected JSON response payload.');
        return $decoded;
    }
}
