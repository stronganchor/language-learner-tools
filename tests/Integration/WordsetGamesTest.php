<?php
declare(strict_types=1);

final class WordsetGamesTest extends LL_Tools_TestCase
{
    /** @var array<string,mixed> */
    private $getBackup = [];

    /** @var array<string,mixed> */
    private $serverBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->getBackup = $_GET;
        $this->serverBackup = $_SERVER;
        if (function_exists('ll_tools_install_user_progress_schema')) {
            ll_tools_install_user_progress_schema();
        }
    }

    protected function tearDown(): void
    {
        $_GET = $this->getBackup;
        $_SERVER = $this->serverBackup;
        set_query_var('ll_wordset_view', null);
        set_query_var('ll_wordset_page', null);
        unset($GLOBALS['ll_tools_vocab_lesson_request']);
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
        $this->setValidWordsetRewriteRules($slug);

        set_query_var('ll_wordset_view', 'games');

        $gamesUrl = (string) ll_tools_get_wordset_page_view_url($wordset, 'games');
        $settingsUrl = (string) ll_tools_get_wordset_page_view_url($wordset, 'settings');
        $this->assertSame(ll_tools_wordset_page_get_pretty_view_url($wordset, 'games'), $gamesUrl);
        $this->assertSame(ll_tools_wordset_page_get_pretty_view_url($wordset, 'settings'), $settingsUrl);
        $this->assertSame('games', ll_tools_get_wordset_page_view());
        $this->assertTrue(ll_tools_wordset_page_has_rewrite_routes($slug));
    }

    public function test_games_and_settings_urls_fall_back_to_query_args_when_rewrite_targets_are_stale(): void
    {
        $term = wp_insert_term('Stale Games Routes ' . wp_generate_password(6, false), 'wordset');
        $this->assertFalse(is_wp_error($term));
        $this->assertIsArray($term);

        $wordset = get_term((int) $term['term_id'], 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset);

        $slug = (string) $wordset->slug;
        update_option('permalink_structure', '/%postname%/');
        update_option('rewrite_rules', [
            '^' . preg_quote($slug, '/') . '/?$' => 'index.php?ll_wordset_page=' . $slug,
            '^' . preg_quote($slug, '/') . '/progress/?$' => 'index.php?ll_wordset_page=' . $slug . '&ll_wordset_view=progress',
            '^' . preg_quote($slug, '/') . '/hidden-categories/?$' => 'index.php?ll_wordset_page=' . $slug . '&ll_wordset_view=hidden-categories',
            '^' . preg_quote($slug, '/') . '/settings/?$' => 'index.php?ll_wordset_page=' . $slug . '&ll_wordset_view=settings',
            '^' . preg_quote($slug, '/') . '/games/?$' => 'index.php?ll_wordset_page=' . $slug . '&ll_wordset_view=settings',
        ]);

        $gamesUrl = (string) ll_tools_get_wordset_page_view_url($wordset, 'games');
        $settingsUrl = (string) ll_tools_get_wordset_page_view_url($wordset, 'settings');

        $this->assertFalse(ll_tools_wordset_page_has_rewrite_routes($slug));
        $this->assertStringContainsString('ll_wordset_page=' . rawurlencode($slug), $gamesUrl);
        $this->assertStringContainsString('ll_wordset_view=games', $gamesUrl);
        $this->assertStringContainsString('ll_wordset_page=' . rawurlencode($slug), $settingsUrl);
        $this->assertStringContainsString('ll_wordset_view=settings', $settingsUrl);
    }

    public function test_query_wordset_subpage_requests_redirect_to_pretty_paths_when_rewrites_are_current(): void
    {
        $term = wp_insert_term('Canonical Games Routes ' . wp_generate_password(6, false), 'wordset');
        $this->assertFalse(is_wp_error($term));
        $this->assertIsArray($term);

        $wordset = get_term((int) $term['term_id'], 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset);

        $slug = (string) $wordset->slug;
        $this->setValidWordsetRewriteRules($slug);

        $expectedBack = ll_tools_get_wordset_page_view_url($wordset);
        $queryUrl = add_query_arg([
            'll_wordset_page' => $slug,
            'll_wordset_view' => 'settings',
            'll_wordset_tool' => 'transcription',
            'll_wordset_back' => $expectedBack,
        ], home_url('/'));

        $_GET = [
            'll_wordset_page' => $slug,
            'll_wordset_view' => 'settings',
            'll_wordset_tool' => 'transcription',
            'll_wordset_back' => $expectedBack,
        ];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = $this->requestUriFromUrl($queryUrl);
        set_query_var('ll_wordset_page', $slug);
        set_query_var('ll_wordset_view', 'settings');

        $redirectUrl = ll_tools_wordset_page_get_query_request_redirect_url();

        $this->assertStringStartsWith(ll_tools_wordset_page_get_pretty_view_url($wordset, 'settings'), $redirectUrl);
        $this->assertSame('transcription', $this->getQueryArgFromUrl($redirectUrl, 'll_wordset_tool'));
        $this->assertSame($expectedBack, $this->getQueryArgFromUrl($redirectUrl, 'll_wordset_back'));
    }

    public function test_reserved_wordset_subpage_slugs_are_not_treated_as_vocab_lesson_categories(): void
    {
        $term = wp_insert_term('Reserved Subpage Routes ' . wp_generate_password(6, false), 'wordset');
        $this->assertFalse(is_wp_error($term));
        $this->assertIsArray($term);

        $wordset = get_term((int) $term['term_id'], 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset);

        foreach (['progress', 'settings', 'games', 'hidden-categories'] as $view) {
            $queryVars = ll_tools_route_vocab_lesson_request([
                'll_vocab_lesson_wordset' => (string) $wordset->slug,
                'll_vocab_lesson_category' => $view,
            ]);

            $this->assertSame((string) $wordset->slug, (string) ($queryVars['ll_wordset_page'] ?? ''));
            $this->assertSame($view, (string) ($queryVars['ll_wordset_view'] ?? ''));
            $this->assertArrayNotHasKey('ll_vocab_lesson_wordset', $queryVars);
            $this->assertArrayNotHasKey('ll_vocab_lesson_category', $queryVars);
            $this->assertArrayNotHasKey('post_type', $queryVars);
            $this->assertArrayNotHasKey('error', $queryVars);
        }

        $this->assertArrayNotHasKey('ll_tools_vocab_lesson_request', $GLOBALS);
    }

    public function test_wordset_page_render_outputs_games_navigation_and_games_view_shell(): void
    {
        $term = wp_insert_term('Rendered Games Wordset ' . wp_generate_password(6, false), 'wordset');
        $this->assertFalse(is_wp_error($term));
        $this->assertIsArray($term);

        $wordset = get_term((int) $term['term_id'], 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset);
        $this->setValidWordsetRewriteRules((string) $wordset->slug);
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));
        $_SERVER['REQUEST_URI'] = $this->requestUriFromUrl(ll_tools_get_wordset_page_view_url($wordset));
        set_query_var('ll_wordset_page', (string) $wordset->slug);

        set_query_var('ll_wordset_view', '');
        $mainHtml = ll_tools_render_wordset_page_content((int) $term['term_id']);
        $expectedBack = ll_tools_get_wordset_page_view_url($wordset);
        $expectedGamesUrl = ll_tools_wordset_page_with_back_url(
            ll_tools_get_wordset_page_view_url($wordset, 'games'),
            $expectedBack
        );
        $expectedSettingsUrl = ll_tools_get_wordset_settings_tool_url($wordset, '', $expectedBack);
        $this->assertStringContainsString('ll-wordset-hero__action-links', $mainHtml);
        $this->assertStringContainsString('ll-wordset-link-chip--games', $mainHtml);
        $this->assertStringContainsString(
            'href="' . esc_url($expectedGamesUrl) . '"',
            $mainHtml
        );
        $this->assertStringContainsString(
            'href="' . esc_url(ll_tools_get_wordset_page_view_url($wordset, 'settings')),
            $mainHtml
        );
        $this->assertStringContainsString(
            'll_wordset_back=' . esc_url($expectedBack),
            $mainHtml
        );

        set_query_var('ll_wordset_view', 'games');
        $gamesHtml = ll_tools_render_wordset_page_content((int) $term['term_id']);
        $this->assertStringContainsString('data-ll-wordset-games-root', $gamesHtml);
        $this->assertStringContainsString('data-ll-wordset-game-run-modal', $gamesHtml);
        $this->assertStringContainsString('data-ll-wordset-games-back', $gamesHtml);
        $this->assertStringContainsString('data-ll-wordset-games-back-label', $gamesHtml);
        $this->assertStringContainsString('data-ll-wordset-games-page-title', $gamesHtml);
        $this->assertStringContainsString('data-ll-wordset-games-speaking-notice', $gamesHtml);
        $this->assertStringContainsString('data-game-slug="space-shooter"', $gamesHtml);
        $this->assertStringContainsString('data-game-slug="bubble-pop"', $gamesHtml);
        $this->assertStringNotContainsString('data-ll-wordset-game-close', $gamesHtml);
    }

    public function test_speaking_hidden_notice_is_only_returned_for_users_who_can_manage_wordset_settings(): void
    {
        $managerId = self::factory()->user->create(['role' => 'administrator']);
        $learnerId = self::factory()->user->create(['role' => 'subscriber']);
        $wordset = wp_insert_term('Speaking Notice Wordset ' . wp_generate_password(6, false), 'wordset');

        $this->assertFalse(is_wp_error($wordset));
        $this->assertIsArray($wordset);

        $wordsetId = (int) $wordset['term_id'];
        $settingsUrl = 'https://example.test/wordsets/speaking-notice/settings/?ll_wordset_tool=transcription';

        $managerNotice = ll_tools_wordset_games_get_speaking_hidden_notice($wordsetId, $managerId, [
            'settings_url' => $settingsUrl,
        ]);
        $learnerNotice = ll_tools_wordset_games_get_speaking_hidden_notice($wordsetId, $learnerId, [
            'settings_url' => $settingsUrl,
        ]);

        $this->assertTrue((bool) ($managerNotice['show'] ?? false));
        $this->assertSame('speaking_disabled', (string) ($managerNotice['reason_code'] ?? ''));
        $this->assertSame(
            'Speaking games are hidden because speaking practice is turned off for this word set.',
            (string) ($managerNotice['message'] ?? '')
        );
        $this->assertSame($settingsUrl, (string) ($managerNotice['settings_url'] ?? ''));
        $this->assertSame('Open speaking settings', (string) ($managerNotice['settings_label'] ?? ''));

        $this->assertFalse((bool) ($learnerNotice['show'] ?? false));
        $this->assertSame('', (string) ($learnerNotice['message'] ?? ''));
    }

    public function test_games_view_renders_speaking_hidden_notice_for_wordset_managers(): void
    {
        $managerId = self::factory()->user->create(['role' => 'administrator']);
        $wordset = wp_insert_term('Rendered Speaking Notice ' . wp_generate_password(6, false), 'wordset');

        $this->assertFalse(is_wp_error($wordset));
        $this->assertIsArray($wordset);

        wp_set_current_user($managerId);
        set_query_var('ll_wordset_view', 'games');

        $gamesHtml = ll_tools_render_wordset_page_content((int) $wordset['term_id']);

        $this->assertStringContainsString('data-ll-wordset-games-speaking-notice', $gamesHtml);
        $this->assertStringContainsString(
            'Speaking games are hidden because speaking practice is turned off for this word set.',
            $gamesHtml
        );
        $this->assertStringContainsString('ll_wordset_tool=transcription', $gamesHtml);
    }

    public function test_subpage_return_url_preserves_original_back_target_when_switching_subpages(): void
    {
        $term = wp_insert_term('Games Back Target ' . wp_generate_password(6, false), 'wordset');
        $this->assertFalse(is_wp_error($term));
        $this->assertIsArray($term);

        $wordset = get_term((int) $term['term_id'], 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset);

        $expectedBack = ll_tools_get_wordset_page_view_url($wordset);
        $settingsToolUrl = ll_tools_get_wordset_settings_tool_url($wordset, 'transcription', $expectedBack);

        $_GET = [
            'll_wordset_page' => (string) $wordset->slug,
            'll_wordset_view' => 'settings',
            'll_wordset_tool' => 'transcription',
            'll_wordset_back' => $expectedBack,
        ];
        $_SERVER['REQUEST_URI'] = $this->requestUriFromUrl($settingsToolUrl);
        set_query_var('ll_wordset_page', (string) $wordset->slug);
        set_query_var('ll_wordset_view', 'settings');

        $returnUrl = ll_tools_wordset_page_get_subpage_return_url($wordset);
        $gamesUrl = ll_tools_wordset_page_with_back_url(
            ll_tools_get_wordset_page_view_url($wordset, 'games'),
            $returnUrl
        );

        $this->assertSame($expectedBack, $returnUrl);
        $this->assertSame($expectedBack, $this->getQueryArgFromUrl($gamesUrl, 'll_wordset_back'));
    }

    public function test_subpage_return_url_falls_back_to_wordset_home_for_direct_subpage_requests(): void
    {
        $term = wp_insert_term('Games Direct Subpage ' . wp_generate_password(6, false), 'wordset');
        $this->assertFalse(is_wp_error($term));
        $this->assertIsArray($term);

        $wordset = get_term((int) $term['term_id'], 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset);

        $expectedBack = ll_tools_get_wordset_page_view_url($wordset);
        $settingsToolUrl = ll_tools_get_wordset_settings_tool_url($wordset, 'transcription');

        $_GET = [
            'll_wordset_page' => (string) $wordset->slug,
            'll_wordset_view' => 'settings',
            'll_wordset_tool' => 'transcription',
        ];
        $_SERVER['REQUEST_URI'] = $this->requestUriFromUrl($settingsToolUrl);
        set_query_var('ll_wordset_page', (string) $wordset->slug);
        set_query_var('ll_wordset_view', 'settings');

        $this->assertSame($expectedBack, ll_tools_wordset_page_get_subpage_return_url($wordset));
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

    private function requestUriFromUrl(string $url): string
    {
        $path = (string) wp_parse_url($url, PHP_URL_PATH);
        $query = (string) wp_parse_url($url, PHP_URL_QUERY);

        return $path . ($query !== '' ? ('?' . $query) : '');
    }

    private function setValidWordsetRewriteRules(string $slug): void
    {
        update_option('permalink_structure', '/%postname%/');
        update_option('rewrite_rules', [
            '^' . preg_quote($slug, '/') . '/?$' => 'index.php?ll_wordset_page=' . $slug,
            '^' . preg_quote($slug, '/') . '/progress/?$' => 'index.php?ll_wordset_page=' . $slug . '&ll_wordset_view=progress',
            '^' . preg_quote($slug, '/') . '/hidden-categories/?$' => 'index.php?ll_wordset_page=' . $slug . '&ll_wordset_view=hidden-categories',
            '^' . preg_quote($slug, '/') . '/settings/?$' => 'index.php?ll_wordset_page=' . $slug . '&ll_wordset_view=settings',
            '^' . preg_quote($slug, '/') . '/games/?$' => 'index.php?ll_wordset_page=' . $slug . '&ll_wordset_view=games',
        ]);
    }

    private function getQueryArgFromUrl(string $url, string $key): string
    {
        $query = (string) wp_parse_url($url, PHP_URL_QUERY);
        if ($query === '') {
            return '';
        }

        parse_str($query, $params);
        return isset($params[$key]) && is_string($params[$key]) ? $params[$key] : '';
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

    public function test_games_exclude_words_with_animated_webp_images_by_default(): void
    {
        $userId = self::factory()->user->create(['role' => 'subscriber']);
        $wordset = wp_insert_term('Animated Games Wordset ' . wp_generate_password(6, false), 'wordset');
        $category = wp_insert_term('Animated Games Category ' . wp_generate_password(6, false), 'word-category');

        $this->assertFalse(is_wp_error($wordset));
        $this->assertFalse(is_wp_error($category));
        $this->assertIsArray($wordset);
        $this->assertIsArray($category);

        $wordsetId = (int) $wordset['term_id'];
        $categoryId = (int) $category['term_id'];
        $categoryTerm = get_term($categoryId, 'word-category');
        $this->assertInstanceOf(WP_Term::class, $categoryTerm);

        update_term_meta($categoryId, 'll_quiz_prompt_type', 'audio');
        update_term_meta($categoryId, 'll_quiz_option_type', 'image');
        $this->setCategoryEnabledGames($categoryId);

        $expectedIds = [];
        for ($index = 1; $index <= 5; $index++) {
            $wordId = $this->createWordWithGameMedia(
                'Static Game Word ' . $index,
                'Static Translation ' . $index,
                $categoryId,
                $wordsetId,
                true,
                ['isolation' => 'Static word ' . $index]
            );
            $this->seedWordProgressRow($userId, $wordId, $categoryId, $wordsetId, [
                'total_coverage' => 3,
                'coverage_practice' => 3,
                'correct_clean' => 1,
                'incorrect' => 1,
                'stage' => 1,
            ]);
            $expectedIds[] = $wordId;
        }

        $animatedAttachmentId = $this->createAnimatedWebpAttachment('animated-game-word.webp');
        $animatedWordId = $this->createWordWithGameMedia(
            'Animated Game Word',
            'Animated Translation',
            $categoryId,
            $wordsetId,
            true,
            ['question' => 'Animated word'],
            $animatedAttachmentId
        );
        $this->seedWordProgressRow($userId, $animatedWordId, $categoryId, $wordsetId, [
            'total_coverage' => 3,
            'coverage_practice' => 3,
            'correct_clean' => 1,
            'incorrect' => 1,
            'stage' => 1,
        ]);

        $rows = ll_get_words_by_category((string) $categoryTerm->name, 'image', [$wordsetId], [
            'prompt_type' => 'audio',
            'option_type' => 'image',
            '__skip_quiz_config_merge' => true,
        ]);
        $animatedRow = null;
        foreach ($rows as $row) {
            if ((int) ($row['id'] ?? 0) !== $animatedWordId) {
                continue;
            }
            $animatedRow = $row;
            break;
        }

        $this->assertIsArray($animatedRow);
        $this->assertTrue((bool) ($animatedRow['image_is_animated_webp'] ?? false));

        wp_set_current_user($userId);
        $pool = ll_tools_wordset_games_build_space_shooter_pool($wordsetId, $userId);
        $returnedIds = array_values(array_filter(array_map(static function ($row): int {
            return is_array($row) ? (int) ($row['id'] ?? 0) : 0;
        }, (array) ($pool['words'] ?? []))));
        sort($expectedIds);
        sort($returnedIds);

        $this->assertSame(5, (int) ($pool['available_word_count'] ?? 0));
        $this->assertTrue((bool) ($pool['launchable'] ?? false));
        $this->assertSame($expectedIds, $returnedIds);
        $this->assertNotContains($animatedWordId, $returnedIds);
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
        $this->setCategoryEnabledGames($categoryId);

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
            $this->setCategoryEnabledGames($categoryId);
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

        $rootAId = $this->resolveEffectiveCategoryId($rootAId, $wordsetId);
        $rootBId = $this->resolveEffectiveCategoryId($rootBId, $wordsetId);

        sort($expectedIds);
        sort($returnedIds);
        sort($returnedCategoryIds);

        $this->assertSame('frontier_new', (string) ($pool['pool_source'] ?? ''));
        $this->assertSame(5, (int) ($pool['available_word_count'] ?? 0));
        $this->assertTrue((bool) ($pool['launchable'] ?? false));
        $this->assertSame($expectedIds, $returnedIds);
        $this->assertSame([$rootAId, $rootBId], $returnedCategoryIds);
    }

    public function test_categories_are_disabled_for_games_by_default_until_enabled(): void
    {
        $userId = self::factory()->user->create(['role' => 'subscriber']);
        $wordset = wp_insert_term('Games Disabled Default ' . wp_generate_password(6, false), 'wordset');
        $category = wp_insert_term('Games Disabled Category ' . wp_generate_password(6, false), 'word-category');

        $this->assertFalse(is_wp_error($wordset));
        $this->assertFalse(is_wp_error($category));
        $this->assertIsArray($wordset);
        $this->assertIsArray($category);

        $wordsetId = (int) $wordset['term_id'];
        $categoryId = (int) $category['term_id'];

        update_term_meta($categoryId, 'll_quiz_prompt_type', 'audio');
        update_term_meta($categoryId, 'll_quiz_option_type', 'image');

        for ($index = 1; $index <= 5; $index++) {
            $wordId = $this->createWordWithGameMedia(
                'Disabled Default Word ' . $index,
                'Disabled Default Translation ' . $index,
                $categoryId,
                $wordsetId,
                true,
                ['question' => 'Disabled default ' . $index]
            );
            $this->seedWordProgressRow($userId, $wordId, $categoryId, $wordsetId, [
                'total_coverage' => 3,
                'coverage_practice' => 3,
                'correct_clean' => 1,
                'incorrect' => 1,
                'stage' => 1,
            ]);
        }

        wp_set_current_user($userId);

        $this->assertSame([], ll_tools_get_category_enabled_games($categoryId));
        $this->assertFalse(ll_tools_is_category_enabled_for_game($categoryId, 'space-shooter'));
        $this->assertSame([], ll_tools_wordset_games_visible_category_ids($wordsetId, $userId, 'space-shooter'));

        $pool = ll_tools_wordset_games_build_space_shooter_pool($wordsetId, $userId);

        $this->assertSame(0, (int) ($pool['available_word_count'] ?? -1));
        $this->assertFalse((bool) ($pool['launchable'] ?? true));
        $this->assertSame([], (array) ($pool['words'] ?? []));
    }

    public function test_unscramble_category_upgrade_only_targets_legacy_default_game_selection(): void
    {
        $this->assertTrue(ll_tools_should_upgrade_category_enabled_games_for_unscramble([
            'space-shooter',
            'bubble-pop',
            'speaking-practice',
            'speaking-stack',
        ]));
        $this->assertTrue(ll_tools_should_upgrade_category_enabled_games_for_unscramble([], false));
        $this->assertFalse(ll_tools_should_upgrade_category_enabled_games_for_unscramble([
            'space-shooter',
            'unscramble',
            'speaking-practice',
        ]));
        $this->assertFalse(ll_tools_should_upgrade_category_enabled_games_for_unscramble([
            'space-shooter',
            'bubble-pop',
        ]));
    }

    public function test_category_game_availability_can_be_cherry_picked_per_game(): void
    {
        $userId = self::factory()->user->create(['role' => 'subscriber']);
        $wordset = wp_insert_term('Games Cherry Pick Wordset ' . wp_generate_password(6, false), 'wordset');
        $spaceCategory = wp_insert_term('Games Space Only ' . wp_generate_password(6, false), 'word-category');
        $bubbleCategory = wp_insert_term('Games Bubble Only ' . wp_generate_password(6, false), 'word-category');

        $this->assertFalse(is_wp_error($wordset));
        $this->assertFalse(is_wp_error($spaceCategory));
        $this->assertFalse(is_wp_error($bubbleCategory));
        $this->assertIsArray($wordset);
        $this->assertIsArray($spaceCategory);
        $this->assertIsArray($bubbleCategory);

        $wordsetId = (int) $wordset['term_id'];
        $spaceCategoryId = (int) $spaceCategory['term_id'];
        $bubbleCategoryId = (int) $bubbleCategory['term_id'];

        foreach ([$spaceCategoryId, $bubbleCategoryId] as $categoryId) {
            update_term_meta($categoryId, 'll_quiz_prompt_type', 'audio');
            update_term_meta($categoryId, 'll_quiz_option_type', 'image');
        }

        $this->setCategoryEnabledGames($spaceCategoryId, ['space-shooter']);
        $this->setCategoryEnabledGames($bubbleCategoryId, ['bubble-pop']);

        $spaceExpectedIds = [];
        $bubbleExpectedIds = [];

        for ($index = 1; $index <= 5; $index++) {
            $spaceWordId = $this->createWordWithGameMedia(
                'Space Only Word ' . $index,
                'Space Only Translation ' . $index,
                $spaceCategoryId,
                $wordsetId,
                true,
                ['question' => 'Space only ' . $index]
            );
            $this->seedWordProgressRow($userId, $spaceWordId, $spaceCategoryId, $wordsetId, [
                'total_coverage' => 3,
                'coverage_practice' => 3,
                'correct_clean' => 1,
                'incorrect' => 1,
                'stage' => 1,
            ]);
            $spaceExpectedIds[] = $spaceWordId;

            $bubbleWordId = $this->createWordWithGameMedia(
                'Bubble Only Word ' . $index,
                'Bubble Only Translation ' . $index,
                $bubbleCategoryId,
                $wordsetId,
                true,
                ['isolation' => 'Bubble only ' . $index]
            );
            $this->seedWordProgressRow($userId, $bubbleWordId, $bubbleCategoryId, $wordsetId, [
                'total_coverage' => 3,
                'coverage_practice' => 3,
                'correct_clean' => 1,
                'incorrect' => 1,
                'stage' => 1,
            ]);
            $bubbleExpectedIds[] = $bubbleWordId;
        }

        wp_set_current_user($userId);

        $spacePool = ll_tools_wordset_games_build_space_shooter_pool($wordsetId, $userId);
        $bubblePool = ll_tools_wordset_games_build_bubble_pop_pool($wordsetId, $userId);

        $spaceReturnedIds = array_values(array_filter(array_map(static function ($row): int {
            return is_array($row) ? (int) ($row['id'] ?? 0) : 0;
        }, (array) ($spacePool['words'] ?? []))));
        $bubbleReturnedIds = array_values(array_filter(array_map(static function ($row): int {
            return is_array($row) ? (int) ($row['id'] ?? 0) : 0;
        }, (array) ($bubblePool['words'] ?? []))));

        $spaceCategoryId = $this->resolveEffectiveCategoryId($spaceCategoryId, $wordsetId);
        $bubbleCategoryId = $this->resolveEffectiveCategoryId($bubbleCategoryId, $wordsetId);

        sort($spaceExpectedIds);
        sort($bubbleExpectedIds);
        sort($spaceReturnedIds);
        sort($bubbleReturnedIds);

        $this->assertSame([$spaceCategoryId], ll_tools_wordset_games_visible_category_ids($wordsetId, $userId, 'space-shooter'));
        $this->assertSame([$bubbleCategoryId], ll_tools_wordset_games_visible_category_ids($wordsetId, $userId, 'bubble-pop'));
        $this->assertSame(5, (int) ($spacePool['available_word_count'] ?? 0));
        $this->assertSame(5, (int) ($bubblePool['available_word_count'] ?? 0));
        $this->assertTrue((bool) ($spacePool['launchable'] ?? false));
        $this->assertTrue((bool) ($bubblePool['launchable'] ?? false));
        $this->assertSame($spaceExpectedIds, $spaceReturnedIds);
        $this->assertSame($bubbleExpectedIds, $bubbleReturnedIds);
        $this->assertSame([$spaceCategoryId], array_values(array_unique(array_map(static function ($row): int {
            return is_array($row) ? (int) ($row['category_id'] ?? 0) : 0;
        }, (array) ($spacePool['words'] ?? [])))));
        $this->assertSame([$bubbleCategoryId], array_values(array_unique(array_map(static function ($row): int {
            return is_array($row) ? (int) ($row['category_id'] ?? 0) : 0;
        }, (array) ($bubblePool['words'] ?? [])))));
    }

    public function test_lineup_is_disabled_by_default_and_only_launches_after_explicit_enable_with_valid_sequence(): void
    {
        $fixture = $this->createLineupFixture('en');
        wp_set_current_user((int) $fixture['user_id']);

        $this->setCategoryEnabledGames((int) $fixture['category_id']);

        $defaultCatalog = ll_tools_wordset_games_build_catalog((int) $fixture['wordset_id'], (int) $fixture['user_id']);
        $defaultLaunch = ll_tools_wordset_games_build_launch_entry('line-up', (int) $fixture['wordset_id'], (int) $fixture['user_id']);

        $this->assertFalse(ll_tools_is_category_enabled_for_game((int) $fixture['category_id'], 'line-up'));
        $this->assertNotContains('line-up', ll_tools_get_category_enabled_games((int) $fixture['category_id']));
        $this->assertArrayNotHasKey('line-up', $defaultCatalog);
        $this->assertNull($defaultLaunch);

        $this->setCategoryEnabledGames((int) $fixture['category_id'], ['line-up']);

        $enabledCatalog = ll_tools_wordset_games_build_catalog((int) $fixture['wordset_id'], (int) $fixture['user_id']);
        $enabledLaunch = ll_tools_wordset_games_build_launch_entry('line-up', (int) $fixture['wordset_id'], (int) $fixture['user_id']);

        $this->assertTrue(ll_tools_is_category_enabled_for_game((int) $fixture['category_id'], 'line-up'));
        $this->assertArrayHasKey('line-up', $enabledCatalog);
        $this->assertSame(1, (int) ($enabledCatalog['line-up']['available_sequence_count'] ?? 0));
        $this->assertTrue((bool) ($enabledCatalog['line-up']['launchable'] ?? false));
        $this->assertIsArray($enabledLaunch);
        $this->assertSame(1, (int) ($enabledLaunch['available_sequence_count'] ?? 0));
        $this->assertTrue((bool) ($enabledLaunch['launchable'] ?? false));
    }

    public function test_lineup_payload_preserves_saved_order_and_resolves_direction_from_wordset_and_category(): void
    {
        $rtlFixture = $this->createLineupFixture('he');
        wp_set_current_user((int) $rtlFixture['user_id']);
        $this->setCategoryEnabledGames((int) $rtlFixture['category_id'], ['line-up']);

        $rtlOrder = [
            $rtlFixture['word_ids'][2],
            $rtlFixture['word_ids'][0],
            $rtlFixture['word_ids'][1],
        ];
        update_term_meta((int) $rtlFixture['category_id'], 'll_category_lineup_word_order', $rtlOrder);

        $rtlCatalog = ll_tools_wordset_games_build_catalog((int) $rtlFixture['wordset_id'], (int) $rtlFixture['user_id']);
        $rtlLaunch = ll_tools_wordset_games_build_launch_entry('line-up', (int) $rtlFixture['wordset_id'], (int) $rtlFixture['user_id']);

        $this->assertArrayHasKey('line-up', $rtlCatalog);
        $this->assertSame('rtl', (string) ($rtlCatalog['line-up']['sequences'][0]['direction'] ?? ''));
        $this->assertSame($rtlOrder, $this->extractLineupSequenceWordIds((array) ($rtlCatalog['line-up']['sequences'][0]['words'] ?? [])));
        $this->assertSame('rtl', (string) ($rtlLaunch['sequences'][0]['direction'] ?? ''));
        $this->assertSame($rtlOrder, $this->extractLineupSequenceWordIds((array) ($rtlLaunch['sequences'][0]['words'] ?? [])));

        $forcedFixture = $this->createLineupFixture('en', 'rtl');
        wp_set_current_user((int) $forcedFixture['user_id']);
        $this->setCategoryEnabledGames((int) $forcedFixture['category_id'], ['line-up']);
        update_term_meta((int) $forcedFixture['category_id'], 'll_category_lineup_word_order', [
            $forcedFixture['word_ids'][1],
            $forcedFixture['word_ids'][0],
            $forcedFixture['word_ids'][2],
        ]);

        $forcedLaunch = ll_tools_wordset_games_build_launch_entry('line-up', (int) $forcedFixture['wordset_id'], (int) $forcedFixture['user_id']);

        $this->assertSame('rtl', (string) ($forcedLaunch['sequences'][0]['direction'] ?? ''));
        $this->assertSame([
            $forcedFixture['word_ids'][1],
            $forcedFixture['word_ids'][0],
            $forcedFixture['word_ids'][2],
        ], $this->extractLineupSequenceWordIds((array) ($forcedLaunch['sequences'][0]['words'] ?? [])));
    }

    public function test_lineup_with_incomplete_sequence_is_not_launchable(): void
    {
        $fixture = $this->createLineupFixture('en', null, 2);
        wp_set_current_user((int) $fixture['user_id']);
        $this->setCategoryEnabledGames((int) $fixture['category_id'], ['line-up']);

        update_term_meta((int) $fixture['category_id'], 'll_category_lineup_word_order', [
            $fixture['word_ids'][1],
            $fixture['word_ids'][0],
        ]);

        $pool = ll_tools_wordset_games_build_lineup_pool((int) $fixture['wordset_id'], (int) $fixture['user_id']);
        $catalog = ll_tools_wordset_games_build_catalog((int) $fixture['wordset_id'], (int) $fixture['user_id']);
        $launch = ll_tools_wordset_games_build_launch_entry('line-up', (int) $fixture['wordset_id'], (int) $fixture['user_id']);

        $this->assertSame(0, (int) ($pool['available_sequence_count'] ?? -1));
        $this->assertSame(1, (int) ($pool['enabled_category_count'] ?? 0));
        $this->assertSame(1, (int) ($pool['invalid_sequence_count'] ?? -1));
        $this->assertSame('lineup_not_configured', (string) ($pool['reason_code'] ?? ''));
        $this->assertArrayHasKey('line-up', $catalog);
        $this->assertFalse((bool) ($catalog['line-up']['launchable'] ?? true));
        $this->assertIsArray($launch);
        $this->assertFalse((bool) ($launch['launchable'] ?? true));
        $this->assertSame('lineup_not_configured', (string) ($launch['reason_code'] ?? ''));
    }

    public function test_unscramble_launch_uses_text_clues_when_available(): void
    {
        $fixture = $this->createUnscrambleFixture(false, 'Unscramble Clue', 5, ['unscramble']);
        wp_set_current_user((int) $fixture['user_id']);

        $catalog = ll_tools_wordset_games_build_catalog((int) $fixture['wordset_id'], (int) $fixture['user_id']);
        $launch = ll_tools_wordset_games_build_launch_entry('unscramble', (int) $fixture['wordset_id'], (int) $fixture['user_id']);

        $this->assertArrayHasKey('unscramble', $catalog);
        $this->assertIsArray($launch);
        $this->assertTrue((bool) ($launch['launchable'] ?? false));
        $this->assertSame(5, (int) ($launch['available_word_count'] ?? 0));
        $this->assertCount(5, (array) ($launch['words'] ?? []));

        $firstWord = (array) ($launch['words'][0] ?? []);
        $this->assertSame('text', (string) ($firstWord['unscramble_prompt_type'] ?? ''));
        $this->assertNotSame('', (string) ($firstWord['unscramble_prompt_text'] ?? ''));
        $this->assertNotSame(
            (string) ($firstWord['unscramble_answer_text'] ?? ''),
            (string) ($firstWord['unscramble_prompt_text'] ?? '')
        );
        $this->assertSame('rtl', (string) ($firstWord['unscramble_direction'] ?? ''));
        $this->assertGreaterThanOrEqual(3, (int) ($firstWord['unscramble_movable_unit_count'] ?? 0));
        $this->assertNotEmpty((array) ($firstWord['unscramble_units'] ?? []));
    }

    public function test_unscramble_answer_uses_learning_language_even_when_titles_are_translation_facing(): void
    {
        $previousTitleRole = get_option('ll_word_title_language_role', null);
        update_option('ll_word_title_language_role', 'translation');

        try {
            $fixture = $this->createUnscrambleFixture(false, 'Turkish', 5, ['unscramble']);
            update_term_meta(
                (int) $fixture['wordset_id'],
                defined('LL_TOOLS_WORDSET_WORD_TITLE_LANGUAGE_ROLE_META_KEY') ? LL_TOOLS_WORDSET_WORD_TITLE_LANGUAGE_ROLE_META_KEY : 'll_wordset_word_title_language_role',
                'translation'
            );
            foreach ((array) $fixture['word_ids'] as $index => $wordId) {
                wp_update_post([
                    'ID' => (int) $wordId,
                    'post_title' => 'Turkish ' . ($index + 1),
                ]);
                update_post_meta((int) $wordId, 'word_translation', 'zazaki' . ($index + 1));
                update_post_meta((int) $wordId, 'word_english_meaning', 'Turkish ' . ($index + 1));
            }
            wp_set_current_user((int) $fixture['user_id']);

            $launch = ll_tools_wordset_games_build_launch_entry('unscramble', (int) $fixture['wordset_id'], (int) $fixture['user_id']);

            $this->assertIsArray($launch);
            $this->assertTrue((bool) ($launch['launchable'] ?? false));

            $firstWord = (array) ($launch['words'][0] ?? []);
            $wordId = (int) ($firstWord['id'] ?? 0);
            $this->assertGreaterThan(0, $wordId);
            $this->assertSame(
                (string) get_post_meta($wordId, 'word_translation', true),
                (string) ($firstWord['unscramble_answer_text'] ?? '')
            );
            $this->assertSame(
                (string) get_post_field('post_title', $wordId),
                (string) ($firstWord['unscramble_prompt_text'] ?? '')
            );
        } finally {
            if ($previousTitleRole === null) {
                delete_option('ll_word_title_language_role');
            } else {
                update_option('ll_word_title_language_role', $previousTitleRole);
            }
        }
    }

    public function test_unscramble_prefers_image_clues_when_word_images_exist(): void
    {
        $fixture = $this->createUnscrambleFixture(true, 'Picture Clue', 5, ['unscramble']);
        wp_set_current_user((int) $fixture['user_id']);

        $launch = ll_tools_wordset_games_build_launch_entry('unscramble', (int) $fixture['wordset_id'], (int) $fixture['user_id']);

        $this->assertIsArray($launch);
        $this->assertTrue((bool) ($launch['launchable'] ?? false));

        $firstWord = (array) ($launch['words'][0] ?? []);
        $this->assertSame('image', (string) ($firstWord['unscramble_prompt_type'] ?? ''));
        $this->assertNotSame('', (string) ($firstWord['unscramble_prompt_image'] ?? ''));
    }

    public function test_unscramble_removes_punctuation_from_target_text(): void
    {
        $fixture = $this->createUnscrambleFixture(false, 'Prompt', 5, ['unscramble']);
        foreach ((array) $fixture['word_ids'] as $index => $wordId) {
            wp_update_post([
                'ID' => (int) $wordId,
                'post_title' => 'keq-piş ' . ($index + 1) . '!',
            ]);
        }

        wp_set_current_user((int) $fixture['user_id']);

        $launch = ll_tools_wordset_games_build_launch_entry('unscramble', (int) $fixture['wordset_id'], (int) $fixture['user_id']);

        $this->assertIsArray($launch);
        $this->assertTrue((bool) ($launch['launchable'] ?? false));

        $firstWord = (array) ($launch['words'][0] ?? []);
        $this->assertStringNotContainsString('-', (string) ($firstWord['unscramble_answer_text'] ?? ''));
        $this->assertStringNotContainsString('!', (string) ($firstWord['unscramble_answer_text'] ?? ''));

        $unitText = implode('', array_map(static function ($unit): string {
            return is_array($unit) ? (string) ($unit['text'] ?? '') : '';
        }, (array) ($firstWord['unscramble_units'] ?? [])));
        $this->assertStringNotContainsString('-', $unitText);
        $this->assertStringNotContainsString('!', $unitText);
    }

    public function test_unscramble_stays_hidden_when_all_words_are_too_short_to_scramble(): void
    {
        $userId = self::factory()->user->create(['role' => 'subscriber']);
        $wordset = wp_insert_term('Unscramble Short Wordset ' . wp_generate_password(6, false), 'wordset');
        $category = wp_insert_term('Unscramble Short Category ' . wp_generate_password(6, false), 'word-category');

        $this->assertFalse(is_wp_error($wordset));
        $this->assertFalse(is_wp_error($category));
        $this->assertIsArray($wordset);
        $this->assertIsArray($category);

        $wordsetId = (int) $wordset['term_id'];
        $categoryId = (int) $category['term_id'];
        update_term_meta($wordsetId, 'll_language', 'he');
        update_term_meta($categoryId, 'll_quiz_prompt_type', 'text_title');
        update_term_meta($categoryId, 'll_quiz_option_type', 'text_title');
        $this->setCategoryEnabledGames($categoryId, ['unscramble']);

        foreach (['א', 'ב', 'ג', 'ד', 'ה'] as $index => $title) {
            $wordId = self::factory()->post->create([
                'post_type' => 'words',
                'post_status' => 'publish',
                'post_title' => $title,
            ]);
            wp_set_post_terms($wordId, [$categoryId], 'word-category', false);
            wp_set_post_terms($wordId, [$wordsetId], 'wordset', false);
            update_post_meta($wordId, 'word_translation', $title);
            set_post_thumbnail($wordId, $this->createImageAttachment('unscramble-short-' . ($index + 1) . '.jpg'));
        }

        $fixture = [
            'user_id' => $userId,
            'wordset_id' => $wordsetId,
        ];
        wp_set_current_user((int) $fixture['user_id']);

        $catalog = ll_tools_wordset_games_build_catalog((int) $fixture['wordset_id'], (int) $fixture['user_id']);
        $launch = ll_tools_wordset_games_build_launch_entry('unscramble', (int) $fixture['wordset_id'], (int) $fixture['user_id']);

        $this->assertArrayNotHasKey('unscramble', $catalog);
        $this->assertNull($launch);
    }

    public function test_unscramble_excludes_words_that_are_too_wide_for_a_single_row(): void
    {
        $fixture = $this->createUnscrambleFixture(false, 'Fit Width', 4, ['unscramble']);
        $letters = array_fill(0, intdiv(ll_tools_wordset_games_unscramble_max_unit_count(), 2) + 1, 'A');
        $tooWideWordId = $this->createWordWithGameMedia(
            implode(' ', $letters),
            'Very wide clue',
            (int) $fixture['category_id'],
            (int) $fixture['wordset_id'],
            false,
            []
        );

        wp_set_current_user((int) $fixture['user_id']);

        $catalog = ll_tools_wordset_games_build_catalog((int) $fixture['wordset_id'], (int) $fixture['user_id']);
        $launch = ll_tools_wordset_games_build_launch_entry('unscramble', (int) $fixture['wordset_id'], (int) $fixture['user_id']);

        $this->assertArrayHasKey('unscramble', $catalog);
        $this->assertIsArray($launch);
        $this->assertTrue((bool) ($launch['launchable'] ?? false));
        $this->assertSame(4, (int) ($launch['available_word_count'] ?? 0));

        $launchWordIds = array_values(array_filter(array_map(static function ($word): int {
            return is_array($word) ? (int) ($word['id'] ?? 0) : 0;
        }, (array) ($launch['words'] ?? []))));

        $this->assertNotContains($tooWideWordId, $launchWordIds);
    }

    public function test_unscramble_hides_game_when_target_language_text_is_missing(): void
    {
        $previousTitleRole = get_option('ll_word_title_language_role', null);
        update_option('ll_word_title_language_role', 'translation');

        try {
            $fixture = $this->createUnscrambleFixture(false, 'Turkish Missing', 5, ['unscramble']);
            update_term_meta(
                (int) $fixture['wordset_id'],
                defined('LL_TOOLS_WORDSET_WORD_TITLE_LANGUAGE_ROLE_META_KEY') ? LL_TOOLS_WORDSET_WORD_TITLE_LANGUAGE_ROLE_META_KEY : 'll_wordset_word_title_language_role',
                'translation'
            );

            foreach ((array) $fixture['word_ids'] as $index => $wordId) {
                wp_update_post([
                    'ID' => (int) $wordId,
                    'post_title' => 'Turkish Missing ' . ($index + 1),
                ]);
                delete_post_meta((int) $wordId, 'word_translation');
                update_post_meta((int) $wordId, 'word_english_meaning', 'Turkish Missing ' . ($index + 1));
            }

            wp_set_current_user((int) $fixture['user_id']);

            $catalog = ll_tools_wordset_games_build_catalog((int) $fixture['wordset_id'], (int) $fixture['user_id']);
            $launch = ll_tools_wordset_games_build_launch_entry('unscramble', (int) $fixture['wordset_id'], (int) $fixture['user_id']);

            $this->assertArrayNotHasKey('unscramble', $catalog);
            $this->assertNull($launch);
        } finally {
            if ($previousTitleRole === null) {
                delete_option('ll_word_title_language_role');
            } else {
                update_option('ll_word_title_language_role', $previousTitleRole);
            }
        }
    }

    public function test_unscramble_shows_locked_card_when_fewer_than_minimum_words_are_ready(): void
    {
        $fixture = $this->createUnscrambleFixture(false, 'Few Clue', 3, ['unscramble']);
        wp_set_current_user((int) $fixture['user_id']);

        $catalog = ll_tools_wordset_games_build_catalog((int) $fixture['wordset_id'], (int) $fixture['user_id']);
        $launch = ll_tools_wordset_games_build_launch_entry('unscramble', (int) $fixture['wordset_id'], (int) $fixture['user_id']);

        $this->assertArrayHasKey('unscramble', $catalog);
        $this->assertFalse((bool) ($catalog['unscramble']['launchable'] ?? true));
        $this->assertSame('not_enough_words', (string) ($catalog['unscramble']['reason_code'] ?? ''));
        $this->assertIsArray($launch);
        $this->assertFalse((bool) ($launch['launchable'] ?? true));
        $this->assertSame(3, (int) ($launch['available_word_count'] ?? 0));
    }

    public function test_wordset_games_frontend_config_uses_three_cards_for_large_image_wordsets(): void
    {
        $wordset = wp_insert_term('Games Large Images ' . wp_generate_password(6, false), 'wordset');

        $this->assertFalse(is_wp_error($wordset));
        $this->assertIsArray($wordset);

        $wordsetId = (int) $wordset['term_id'];

        $defaultConfig = ll_tools_get_wordset_games_frontend_config($wordsetId);
        $this->assertSame(4, (int) ($defaultConfig['spaceShooter']['cardCount'] ?? 0));
        $this->assertSame(4, (int) ($defaultConfig['bubblePop']['cardCount'] ?? 0));

        update_term_meta($wordsetId, LL_TOOLS_WORDSET_GAMES_IMAGE_SIZE_META_KEY, 'large');

        $largeConfig = ll_tools_get_wordset_games_frontend_config($wordsetId);
        $this->assertSame('large', ll_tools_get_wordset_games_image_size($wordsetId));
        $this->assertSame(3, ll_tools_wordset_get_image_game_card_count($wordsetId));
        $this->assertSame(3, (int) ($largeConfig['spaceShooter']['cardCount'] ?? 0));
        $this->assertSame(3, (int) ($largeConfig['bubblePop']['cardCount'] ?? 0));
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

    public function test_launch_ajax_returns_full_game_pool_beyond_bootstrap_cap(): void
    {
        $fixture = $this->createGamesFixture(10);
        wp_set_current_user((int) $fixture['user_id']);

        $nonce = wp_create_nonce('ll_user_study');
        $capFilter = static function (): int {
            return 5;
        };
        add_filter('ll_tools_wordset_games_space_shooter_launch_word_cap', $capFilter);

        $_POST = [
            'nonce' => $nonce,
            'wordset_id' => (int) $fixture['wordset_id'],
            'game_slug' => 'space-shooter',
        ];
        $_REQUEST = $_POST;

        try {
            $response = $this->runJsonEndpoint(static function (): void {
                ll_tools_wordset_games_launch_ajax();
            });
        } finally {
            $_POST = [];
            $_REQUEST = [];
            remove_filter('ll_tools_wordset_games_space_shooter_launch_word_cap', $capFilter);
        }

        $this->assertTrue((bool) ($response['success'] ?? false));
        $this->assertSame((int) $fixture['wordset_id'], (int) ($response['data']['wordset_id'] ?? 0));
        $this->assertIsArray($response['data']['game'] ?? null);
        $this->assertSame('space-shooter', (string) ($response['data']['game']['slug'] ?? ''));
        $this->assertSame(10, (int) ($response['data']['game']['available_word_count'] ?? 0));
        $this->assertSame(10, (int) ($response['data']['game']['launch_word_cap'] ?? 0));
        $this->assertSame(10, (int) ($response['data']['game']['launch_word_count'] ?? 0));
        $this->assertCount(10, (array) ($response['data']['game']['words'] ?? []));
        $this->assertTrue((bool) ($response['data']['game']['launchable'] ?? false));
    }

    public function test_speaking_practice_catalog_only_uses_learned_text_prompt_words(): void
    {
        $userId = self::factory()->user->create(['role' => 'subscriber']);
        $wordset = wp_insert_term('Speaking Games Wordset ' . wp_generate_password(6, false), 'wordset');
        $category = wp_insert_term('Speaking Games Category ' . wp_generate_password(6, false), 'word-category');

        $this->assertFalse(is_wp_error($wordset));
        $this->assertFalse(is_wp_error($category));
        $this->assertIsArray($wordset);
        $this->assertIsArray($category);

        $wordsetId = (int) $wordset['term_id'];
        $categoryId = (int) $category['term_id'];
        update_term_meta($categoryId, 'll_quiz_prompt_type', 'text_title');
        update_term_meta($categoryId, 'll_quiz_option_type', 'text_title');
        $this->setCategoryEnabledGames($categoryId);
        update_term_meta($wordsetId, LL_TOOLS_WORDSET_LOCAL_TRANSCRIPTION_ENDPOINT_META_KEY, 'http://127.0.0.1:8765/transcribe');
        update_term_meta($wordsetId, LL_TOOLS_WORDSET_LOCAL_TRANSCRIPTION_TARGET_META_KEY, 'recording_ipa');
        update_term_meta($wordsetId, LL_TOOLS_WORDSET_SPEAKING_GAME_ENABLED_META_KEY, 1);
        update_term_meta($wordsetId, LL_TOOLS_WORDSET_SPEAKING_GAME_PROVIDER_META_KEY, 'local_browser');
        update_term_meta($wordsetId, LL_TOOLS_WORDSET_SPEAKING_GAME_TARGET_META_KEY, 'recording_ipa');

        $wordIds = [];
        for ($index = 1; $index <= 5; $index++) {
            $wordId = $this->createWordWithGameMedia(
                'Speaking Word ' . $index,
                'Speaking Prompt ' . $index,
                $categoryId,
                $wordsetId,
                false,
                ['isolation' => 'Speaking isolation ' . $index]
            );
            $audioPosts = get_posts([
                'post_type' => 'word_audio',
                'post_status' => 'publish',
                'post_parent' => $wordId,
                'posts_per_page' => -1,
            ]);
            $this->assertNotEmpty($audioPosts);
            foreach ($audioPosts as $audioPost) {
                $this->assertInstanceOf(WP_Post::class, $audioPost);
                update_post_meta((int) $audioPost->ID, 'recording_ipa', 'ipa ' . $index);
            }
            $this->seedWordProgressRow($userId, $wordId, $categoryId, $wordsetId, [
                'total_coverage' => 6,
                'coverage_practice' => 6,
                'correct_clean' => 4,
                'incorrect' => 0,
                'lapse_count' => 0,
                'stage' => 6,
            ]);
            $wordIds[] = $wordId;
        }

        $studiedWordId = $this->createWordWithGameMedia(
            'Speaking Studied Word',
            'Speaking Studied Prompt',
            $categoryId,
            $wordsetId,
            false,
            ['isolation' => 'Speaking studied isolation']
        );
        $studiedAudioPosts = get_posts([
            'post_type' => 'word_audio',
            'post_status' => 'publish',
            'post_parent' => $studiedWordId,
            'posts_per_page' => -1,
        ]);
        $this->assertNotEmpty($studiedAudioPosts);
        foreach ($studiedAudioPosts as $audioPost) {
            $this->assertInstanceOf(WP_Post::class, $audioPost);
            update_post_meta((int) $audioPost->ID, 'recording_ipa', 'ipa studied');
        }
        $this->seedWordProgressRow($userId, $studiedWordId, $categoryId, $wordsetId, [
            'total_coverage' => 2,
            'coverage_practice' => 2,
            'correct_clean' => 1,
            'incorrect' => 1,
            'stage' => 1,
        ]);

        $newWordId = $this->createWordWithGameMedia(
            'Speaking New Word',
            'Speaking New Prompt',
            $categoryId,
            $wordsetId,
            false,
            ['isolation' => 'Speaking new isolation']
        );
        $newAudioPosts = get_posts([
            'post_type' => 'word_audio',
            'post_status' => 'publish',
            'post_parent' => $newWordId,
            'posts_per_page' => -1,
        ]);
        $this->assertNotEmpty($newAudioPosts);
        foreach ($newAudioPosts as $audioPost) {
            $this->assertInstanceOf(WP_Post::class, $audioPost);
            update_post_meta((int) $audioPost->ID, 'recording_ipa', 'ipa new');
        }

        wp_set_current_user($userId);

        $firstAudio = ll_tools_wordset_games_get_audio_details((int) $wordIds[0], 'isolation');
        $this->assertSame('isolation', (string) ($firstAudio['recording_type'] ?? ''));
        $this->assertNotSame('', (string) ($firstAudio['url'] ?? ''));

        $catalog = ll_tools_wordset_games_build_catalog($wordsetId, $userId);
        $this->assertArrayHasKey('speaking-practice', $catalog);
        $this->assertSame(5, (int) ($catalog['speaking-practice']['available_word_count'] ?? 0));
        $this->assertTrue((bool) ($catalog['speaking-practice']['launchable'] ?? false));
        $this->assertCount(5, (array) ($catalog['speaking-practice']['words'] ?? []));
        $this->assertSame('recording_ipa', (string) ($catalog['speaking-practice']['target_field'] ?? ''));
        $this->assertSame('text', (string) ($catalog['speaking-practice']['words'][0]['speaking_prompt_type'] ?? ''));
        $returnedIds = array_values(array_filter(array_map(static function ($row): int {
            return is_array($row) ? (int) ($row['id'] ?? 0) : 0;
        }, (array) ($catalog['speaking-practice']['words'] ?? []))));
        sort($returnedIds);
        $expectedIds = array_map('intval', $wordIds);
        sort($expectedIds);
        $this->assertSame($expectedIds, $returnedIds);
        $this->assertNotContains($studiedWordId, $returnedIds);
        $this->assertNotContains($newWordId, $returnedIds);
        foreach ((array) ($catalog['speaking-practice']['words'] ?? []) as $wordRow) {
            $this->assertSame('mastered', (string) ($wordRow['progress_status'] ?? ''));
        }
    }

    public function test_speaking_practice_payload_preserves_recording_text_and_ipa_for_display(): void
    {
        $userId = self::factory()->user->create(['role' => 'subscriber']);
        $wordset = wp_insert_term('Speaking Payload Wordset ' . wp_generate_password(6, false), 'wordset');
        $category = wp_insert_term('Speaking Payload Category ' . wp_generate_password(6, false), 'word-category');

        $this->assertFalse(is_wp_error($wordset));
        $this->assertFalse(is_wp_error($category));
        $this->assertIsArray($wordset);
        $this->assertIsArray($category);

        $wordsetId = (int) $wordset['term_id'];
        $categoryId = (int) $category['term_id'];
        update_term_meta($categoryId, 'll_quiz_prompt_type', 'text_title');
        update_term_meta($categoryId, 'll_quiz_option_type', 'text_title');
        $this->setCategoryEnabledGames($categoryId);
        update_term_meta($wordsetId, LL_TOOLS_WORDSET_LOCAL_TRANSCRIPTION_ENDPOINT_META_KEY, 'http://127.0.0.1:8765/transcribe');
        update_term_meta($wordsetId, LL_TOOLS_WORDSET_LOCAL_TRANSCRIPTION_TARGET_META_KEY, 'recording_text');
        update_term_meta($wordsetId, LL_TOOLS_WORDSET_SPEAKING_GAME_ENABLED_META_KEY, 1);
        update_term_meta($wordsetId, LL_TOOLS_WORDSET_SPEAKING_GAME_PROVIDER_META_KEY, 'local_browser');
        update_term_meta($wordsetId, LL_TOOLS_WORDSET_SPEAKING_GAME_TARGET_META_KEY, 'recording_text');

        $wordId = $this->createWordWithGameMedia(
            'Speaking Payload Word',
            'Speaking Payload Translation',
            $categoryId,
            $wordsetId,
            false,
            ['isolation' => 'cat']
        );
        $audioPosts = get_posts([
            'post_type' => 'word_audio',
            'post_status' => 'publish',
            'post_parent' => $wordId,
            'posts_per_page' => -1,
        ]);
        $this->assertNotEmpty($audioPosts);
        foreach ($audioPosts as $audioPost) {
            $this->assertInstanceOf(WP_Post::class, $audioPost);
            update_post_meta((int) $audioPost->ID, 'recording_ipa', 'kæt');
        }

        $this->seedWordProgressRow($userId, $wordId, $categoryId, $wordsetId, [
            'total_coverage' => 6,
            'coverage_practice' => 6,
            'correct_clean' => 4,
            'incorrect' => 0,
            'lapse_count' => 0,
            'stage' => 6,
        ]);

        wp_set_current_user($userId);
        $catalog = ll_tools_wordset_games_build_catalog($wordsetId, $userId);

        $this->assertArrayHasKey('speaking-practice', $catalog);
        $this->assertSame('recording_text', (string) ($catalog['speaking-practice']['target_field'] ?? ''));
        $this->assertCount(1, (array) ($catalog['speaking-practice']['words'] ?? []));

        $wordRow = (array) ($catalog['speaking-practice']['words'][0] ?? []);
        $displayTexts = (array) ($wordRow['speaking_display_texts'] ?? []);
        $this->assertSame('cat', (string) ($wordRow['recording_text'] ?? ''));
        $this->assertSame('kæt', (string) ($wordRow['recording_ipa'] ?? ''));
        $this->assertSame('cat', (string) ($wordRow['speaking_target_text'] ?? ''));
        $this->assertSame('text', (string) ($wordRow['speaking_prompt_type'] ?? ''));
        $this->assertSame('cat', (string) ($displayTexts['target_text'] ?? ''));
        $this->assertSame('kæt', (string) ($displayTexts['ipa'] ?? ''));
    }

    public function test_speaking_stack_catalog_only_uses_learned_image_words(): void
    {
        $userId = self::factory()->user->create(['role' => 'subscriber']);
        $wordset = wp_insert_term('Speaking Stack Wordset ' . wp_generate_password(6, false), 'wordset');
        $category = wp_insert_term('Speaking Stack Category ' . wp_generate_password(6, false), 'word-category');

        $this->assertFalse(is_wp_error($wordset));
        $this->assertFalse(is_wp_error($category));
        $this->assertIsArray($wordset);
        $this->assertIsArray($category);

        $wordsetId = (int) $wordset['term_id'];
        $categoryId = (int) $category['term_id'];
        update_term_meta($categoryId, 'll_quiz_prompt_type', 'audio');
        update_term_meta($categoryId, 'll_quiz_option_type', 'image');
        $this->setCategoryEnabledGames($categoryId);
        update_term_meta($wordsetId, LL_TOOLS_WORDSET_LOCAL_TRANSCRIPTION_ENDPOINT_META_KEY, 'http://127.0.0.1:8765/transcribe');
        update_term_meta($wordsetId, LL_TOOLS_WORDSET_LOCAL_TRANSCRIPTION_TARGET_META_KEY, 'recording_ipa');
        update_term_meta($wordsetId, LL_TOOLS_WORDSET_SPEAKING_GAME_ENABLED_META_KEY, 1);
        update_term_meta($wordsetId, LL_TOOLS_WORDSET_SPEAKING_GAME_PROVIDER_META_KEY, 'local_browser');
        update_term_meta($wordsetId, LL_TOOLS_WORDSET_SPEAKING_GAME_TARGET_META_KEY, 'recording_ipa');

        $learnedWordIds = [];
        for ($index = 1; $index <= 5; $index++) {
            $wordId = $this->createWordWithGameMedia(
                'Speaking Stack Word ' . $index,
                'Stack Prompt ' . $index,
                $categoryId,
                $wordsetId,
                true,
                ['isolation' => 'Stack isolation ' . $index]
            );
            $audioPosts = get_posts([
                'post_type' => 'word_audio',
                'post_status' => 'publish',
                'post_parent' => $wordId,
                'posts_per_page' => -1,
            ]);
            $this->assertNotEmpty($audioPosts);
            foreach ($audioPosts as $audioPost) {
                $this->assertInstanceOf(WP_Post::class, $audioPost);
                update_post_meta((int) $audioPost->ID, 'recording_ipa', 'stack ipa ' . $index);
            }
            $this->seedWordProgressRow($userId, $wordId, $categoryId, $wordsetId, [
                'total_coverage' => 6,
                'coverage_practice' => 6,
                'correct_clean' => 4,
                'incorrect' => 0,
                'lapse_count' => 0,
                'stage' => 6,
            ]);
            $learnedWordIds[] = $wordId;
        }

        $imageLessWordId = $this->createWordWithGameMedia(
            'Speaking Stack No Image',
            'Stack Prompt No Image',
            $categoryId,
            $wordsetId,
            false,
            ['isolation' => 'Stack isolation no image']
        );
        $audioPosts = get_posts([
            'post_type' => 'word_audio',
            'post_status' => 'publish',
            'post_parent' => $imageLessWordId,
            'posts_per_page' => -1,
        ]);
        $this->assertNotEmpty($audioPosts);
        foreach ($audioPosts as $audioPost) {
            $this->assertInstanceOf(WP_Post::class, $audioPost);
            update_post_meta((int) $audioPost->ID, 'recording_ipa', 'stack ipa no image');
        }

        $studiedImageWordId = $this->createWordWithGameMedia(
            'Speaking Stack Studied Image',
            'Stack Prompt Studied Image',
            $categoryId,
            $wordsetId,
            true,
            ['isolation' => 'Stack isolation studied image']
        );
        $studiedAudioPosts = get_posts([
            'post_type' => 'word_audio',
            'post_status' => 'publish',
            'post_parent' => $studiedImageWordId,
            'posts_per_page' => -1,
        ]);
        $this->assertNotEmpty($studiedAudioPosts);
        foreach ($studiedAudioPosts as $audioPost) {
            $this->assertInstanceOf(WP_Post::class, $audioPost);
            update_post_meta((int) $audioPost->ID, 'recording_ipa', 'stack ipa studied image');
        }
        $this->seedWordProgressRow($userId, $studiedImageWordId, $categoryId, $wordsetId, [
            'total_coverage' => 2,
            'coverage_practice' => 2,
            'correct_clean' => 1,
            'incorrect' => 1,
            'stage' => 1,
        ]);

        wp_set_current_user($userId);

        $catalog = ll_tools_wordset_games_build_catalog($wordsetId, $userId);
        $this->assertArrayHasKey('speaking-stack', $catalog);
        $this->assertSame(5, (int) ($catalog['speaking-stack']['available_word_count'] ?? 0));
        $this->assertTrue((bool) ($catalog['speaking-stack']['launchable'] ?? false));
        $this->assertCount(5, (array) ($catalog['speaking-stack']['words'] ?? []));
        $this->assertSame('recording_ipa', (string) ($catalog['speaking-stack']['target_field'] ?? ''));
        $returnedIds = array_values(array_filter(array_map(static function ($row): int {
            return is_array($row) ? (int) ($row['id'] ?? 0) : 0;
        }, (array) ($catalog['speaking-stack']['words'] ?? []))));
        sort($returnedIds);
        sort($learnedWordIds);
        $this->assertSame($learnedWordIds, $returnedIds);
        $this->assertNotContains($imageLessWordId, $returnedIds);
        $this->assertNotContains($studiedImageWordId, $returnedIds);

        foreach ((array) ($catalog['speaking-stack']['words'] ?? []) as $wordRow) {
            $this->assertIsArray($wordRow);
            $this->assertNotSame('', trim((string) ($wordRow['image'] ?? '')));
            $this->assertSame('mastered', (string) ($wordRow['progress_status'] ?? ''));
        }
    }

    public function test_speaking_games_can_be_restricted_to_managers_and_hidden_from_learners(): void
    {
        $managerId = self::factory()->user->create(['role' => 'administrator']);
        $learnerId = self::factory()->user->create(['role' => 'subscriber']);
        $wordset = wp_insert_term('Speaking Access Wordset ' . wp_generate_password(6, false), 'wordset');
        $category = wp_insert_term('Speaking Access Category ' . wp_generate_password(6, false), 'word-category');

        $this->assertFalse(is_wp_error($wordset));
        $this->assertFalse(is_wp_error($category));
        $this->assertIsArray($wordset);
        $this->assertIsArray($category);

        $wordsetId = (int) $wordset['term_id'];
        $categoryId = (int) $category['term_id'];
        update_term_meta($categoryId, 'll_quiz_prompt_type', 'audio');
        update_term_meta($categoryId, 'll_quiz_option_type', 'image');
        $this->setCategoryEnabledGames($categoryId);
        update_term_meta($wordsetId, LL_TOOLS_WORDSET_SPEAKING_GAME_ENABLED_META_KEY, 1);
        update_term_meta($wordsetId, LL_TOOLS_WORDSET_SPEAKING_GAME_PROVIDER_META_KEY, 'audio_matcher');
        update_term_meta($wordsetId, LL_TOOLS_WORDSET_SPEAKING_GAME_TARGET_META_KEY, 'recording_text');
        update_term_meta($wordsetId, LL_TOOLS_WORDSET_SPEAKING_GAME_ACCESS_META_KEY, 'managers');

        for ($index = 1; $index <= 5; $index++) {
            $this->createWordWithGameMedia(
                'Speaking Access Word ' . $index,
                'Speaking Access Prompt ' . $index,
                $categoryId,
                $wordsetId,
                true,
                ['isolation' => 'Speaking access ' . $index]
            );
        }

        $wordsetTerm = get_term($wordsetId, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordsetTerm);
        $this->setValidWordsetRewriteRules((string) $wordsetTerm->slug);
        set_query_var('ll_wordset_page', (string) $wordsetTerm->slug);
        set_query_var('ll_wordset_view', 'games');

        $this->assertFalse(ll_tools_user_can_access_wordset_speaking_games($wordsetId, $learnerId));
        $this->assertTrue(ll_tools_user_can_access_wordset_speaking_games($wordsetId, $managerId));

        wp_set_current_user($learnerId);
        $_SERVER['REQUEST_URI'] = $this->requestUriFromUrl(ll_tools_get_wordset_page_view_url($wordsetTerm, 'games'));
        $learnerCatalog = ll_tools_wordset_games_build_catalog($wordsetId, $learnerId);
        $learnerHtml = ll_tools_render_wordset_page_content($wordsetId);

        $this->assertArrayNotHasKey('speaking-practice', $learnerCatalog);
        $this->assertArrayNotHasKey('speaking-stack', $learnerCatalog);
        $this->assertStringNotContainsString('data-game-slug="speaking-practice"', $learnerHtml);
        $this->assertStringNotContainsString('data-game-slug="speaking-stack"', $learnerHtml);

        wp_set_current_user($managerId);
        $_SERVER['REQUEST_URI'] = $this->requestUriFromUrl(ll_tools_get_wordset_page_view_url($wordsetTerm, 'games'));
        $managerCatalog = ll_tools_wordset_games_build_catalog($wordsetId, $managerId);
        $managerHtml = ll_tools_render_wordset_page_content($wordsetId);

        $this->assertArrayHasKey('speaking-practice', $managerCatalog);
        $this->assertArrayHasKey('speaking-stack', $managerCatalog);
        $this->assertStringContainsString('data-game-slug="speaking-practice"', $managerHtml);
        $this->assertStringContainsString('data-game-slug="speaking-stack"', $managerHtml);
    }

    public function test_speaking_score_endpoint_allows_study_users_when_wordset_access_is_learners(): void
    {
        $userId = self::factory()->user->create(['role' => 'subscriber']);
        $wordset = wp_insert_term('Speaking Endpoint Wordset ' . wp_generate_password(6, false), 'wordset');
        $category = wp_insert_term('Speaking Endpoint Category ' . wp_generate_password(6, false), 'word-category');

        $this->assertFalse(is_wp_error($wordset));
        $this->assertFalse(is_wp_error($category));
        $this->assertIsArray($wordset);
        $this->assertIsArray($category);

        $wordsetId = (int) $wordset['term_id'];
        $categoryId = (int) $category['term_id'];
        update_term_meta($categoryId, 'll_quiz_prompt_type', 'audio');
        update_term_meta($categoryId, 'll_quiz_option_type', 'image');
        $this->setCategoryEnabledGames($categoryId);
        update_term_meta($wordsetId, LL_TOOLS_WORDSET_SPEAKING_GAME_ENABLED_META_KEY, 1);
        update_term_meta($wordsetId, LL_TOOLS_WORDSET_SPEAKING_GAME_PROVIDER_META_KEY, 'audio_matcher');
        update_term_meta($wordsetId, LL_TOOLS_WORDSET_SPEAKING_GAME_TARGET_META_KEY, 'recording_text');

        $wordId = $this->createWordWithGameMedia(
            'Speaking Endpoint Word',
            'Speaking Endpoint Prompt',
            $categoryId,
            $wordsetId,
            true,
            ['isolation' => 'cat']
        );

        wp_set_current_user($userId);
        $nonce = wp_create_nonce('ll_user_study');
        $_POST = [
            'nonce' => $nonce,
            'wordset_id' => $wordsetId,
            'word_id' => $wordId,
            'transcript' => 'cat',
            'target_field' => 'recording_text',
        ];
        $_REQUEST = $_POST;

        try {
            $response = $this->runJsonEndpoint(static function (): void {
                ll_tools_wordset_games_score_attempt_ajax();
            });
        } finally {
            $_POST = [];
            $_REQUEST = [];
        }

        $this->assertTrue((bool) ($response['success'] ?? false));
        $this->assertSame($wordId, (int) ($response['data']['word_id'] ?? 0));
        $this->assertSame('right', (string) ($response['data']['bucket'] ?? ''));
    }

    public function test_speaking_score_treats_capitalization_only_text_differences_as_exact_matches(): void
    {
        $userId = self::factory()->user->create(['role' => 'subscriber']);
        $wordset = wp_insert_term('Speaking Case Wordset ' . wp_generate_password(6, false), 'wordset');
        $category = wp_insert_term('Speaking Case Category ' . wp_generate_password(6, false), 'word-category');

        $this->assertFalse(is_wp_error($wordset));
        $this->assertFalse(is_wp_error($category));
        $this->assertIsArray($wordset);
        $this->assertIsArray($category);

        $wordsetId = (int) $wordset['term_id'];
        $categoryId = (int) $category['term_id'];
        update_term_meta($categoryId, 'll_quiz_prompt_type', 'audio');
        update_term_meta($categoryId, 'll_quiz_option_type', 'image');
        $this->setCategoryEnabledGames($categoryId);
        update_term_meta($wordsetId, LL_TOOLS_WORDSET_SPEAKING_GAME_ENABLED_META_KEY, 1);
        update_term_meta($wordsetId, LL_TOOLS_WORDSET_SPEAKING_GAME_PROVIDER_META_KEY, 'hosted_api');
        update_term_meta($wordsetId, LL_TOOLS_WORDSET_SPEAKING_GAME_TARGET_META_KEY, 'recording_text');

        $wordId = $this->createWordWithGameMedia(
            'Speaking Case Word',
            'Speaking Case Prompt',
            $categoryId,
            $wordsetId,
            true,
            ['isolation' => 'Her']
        );

        wp_set_current_user($userId);

        $result = ll_tools_wordset_games_score_speaking_transcript($wordsetId, $wordId, 'her', 'recording_text');

        $this->assertIsArray($result);
        $this->assertSame('right', (string) ($result['bucket'] ?? ''));
        $this->assertSame(100.0, (float) ($result['score'] ?? 0.0));
        $this->assertSame('her', (string) ($result['normalized_target_text'] ?? ''));
        $this->assertSame('her', (string) ($result['normalized_transcript_text'] ?? ''));
    }

    public function test_best_speaking_match_scores_active_words_and_returns_no_match_for_distant_transcripts(): void
    {
        $userId = self::factory()->user->create(['role' => 'subscriber']);
        $wordset = wp_insert_term('Speaking Match Wordset ' . wp_generate_password(6, false), 'wordset');
        $category = wp_insert_term('Speaking Match Category ' . wp_generate_password(6, false), 'word-category');

        $this->assertFalse(is_wp_error($wordset));
        $this->assertFalse(is_wp_error($category));
        $this->assertIsArray($wordset);
        $this->assertIsArray($category);

        $wordsetId = (int) $wordset['term_id'];
        $categoryId = (int) $category['term_id'];
        update_term_meta($categoryId, 'll_quiz_prompt_type', 'audio');
        update_term_meta($categoryId, 'll_quiz_option_type', 'image');
        $this->setCategoryEnabledGames($categoryId);
        update_term_meta($wordsetId, LL_TOOLS_WORDSET_LOCAL_TRANSCRIPTION_ENDPOINT_META_KEY, 'http://127.0.0.1:8765/transcribe');
        update_term_meta($wordsetId, LL_TOOLS_WORDSET_LOCAL_TRANSCRIPTION_TARGET_META_KEY, 'recording_ipa');
        update_term_meta($wordsetId, LL_TOOLS_WORDSET_SPEAKING_GAME_ENABLED_META_KEY, 1);
        update_term_meta($wordsetId, LL_TOOLS_WORDSET_SPEAKING_GAME_PROVIDER_META_KEY, 'local_browser');
        update_term_meta($wordsetId, LL_TOOLS_WORDSET_SPEAKING_GAME_TARGET_META_KEY, 'recording_ipa');

        $targetWordId = $this->createWordWithGameMedia(
            'Speaking Match Target',
            'Target Prompt',
            $categoryId,
            $wordsetId,
            true,
            ['isolation' => 'Target isolation']
        );
        $distractorWordId = $this->createWordWithGameMedia(
            'Speaking Match Distractor',
            'Distractor Prompt',
            $categoryId,
            $wordsetId,
            true,
            ['isolation' => 'Distractor isolation']
        );

        foreach ([
            $targetWordId => 'aqa',
            $distractorWordId => 'rʉɛ',
        ] as $wordId => $ipa) {
            $audioPosts = get_posts([
                'post_type' => 'word_audio',
                'post_status' => 'publish',
                'post_parent' => $wordId,
                'posts_per_page' => -1,
            ]);
            $this->assertNotEmpty($audioPosts);
            foreach ($audioPosts as $audioPost) {
                $this->assertInstanceOf(WP_Post::class, $audioPost);
                update_post_meta((int) $audioPost->ID, 'recording_ipa', $ipa);
            }
        }

        wp_set_current_user($userId);

        $match = ll_tools_wordset_games_score_best_speaking_match($wordsetId, [$targetWordId, $distractorWordId], 'aka', 'recording_ipa');
        $this->assertIsArray($match);
        $this->assertTrue((bool) ($match['matched'] ?? false));
        $this->assertSame($targetWordId, (int) ($match['word_id'] ?? 0));
        $this->assertSame('right', (string) ($match['bucket'] ?? ''));

        $noMatch = ll_tools_wordset_games_score_best_speaking_match($wordsetId, [$targetWordId, $distractorWordId], 'zzz', 'recording_ipa');
        $this->assertIsArray($noMatch);
        $this->assertFalse((bool) ($noMatch['matched'] ?? true));
        $this->assertSame('wrong', (string) ($noMatch['bucket'] ?? ''));
    }

    public function test_ipa_similarity_score_gives_partial_credit_to_nearby_sounds(): void
    {
        $nearVowelScore = ll_tools_wordset_games_similarity_score('rʉɛ', 'rwɨ', 'recording_ipa');
        $farVowelScore = ll_tools_wordset_games_similarity_score('rʉɛ', 'saq', 'recording_ipa');
        $nearPlaceScore = ll_tools_wordset_games_similarity_score('aqa', 'aka', 'recording_ipa');
        $farPlaceScore = ll_tools_wordset_games_similarity_score('aqa', 'ata', 'recording_ipa');

        $this->assertGreaterThan(65.0, $nearVowelScore);
        $this->assertGreaterThan($farVowelScore, $nearVowelScore);
        $this->assertSame('close', ll_tools_wordset_games_score_bucket($nearVowelScore));

        $this->assertGreaterThan(80.0, $nearPlaceScore);
        $this->assertGreaterThan($farPlaceScore, $nearPlaceScore);
        $this->assertSame('right', ll_tools_wordset_games_score_bucket($nearPlaceScore));
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
        $this->setCategoryEnabledGames($categoryId);

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
     * @param string[]|null $games
     */
    private function setCategoryEnabledGames(int $categoryId, ?array $games = null): void
    {
        $games = $games ?? (
            function_exists('ll_tools_get_category_default_enabled_game_slugs')
                ? ll_tools_get_category_default_enabled_game_slugs()
                : ['space-shooter', 'bubble-pop', 'unscramble', 'speaking-practice', 'speaking-stack']
        );
        $normalizedGames = function_exists('ll_tools_normalize_category_enabled_games')
            ? ll_tools_normalize_category_enabled_games($games)
            : array_values(array_unique(array_filter(array_map('sanitize_key', $games))));

        update_term_meta($categoryId, LL_TOOLS_CATEGORY_ENABLED_GAMES_META_KEY, $normalizedGames);
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
        array $recordingTypes,
        int $attachmentId = 0
    ): int {
        $categoryId = $this->resolveEffectiveCategoryId($categoryId, $wordsetId);
        $wordId = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => $title . ' ' . wp_generate_password(4, false),
        ]);

        wp_set_post_terms($wordId, [$categoryId], 'word-category', false);
        wp_set_post_terms($wordId, [$wordsetId], 'wordset', false);
        update_post_meta($wordId, 'word_translation', $translation);

        if ($withImage) {
            if ($attachmentId <= 0) {
                $attachmentId = $this->createImageAttachment(sanitize_title($title) . '.jpg');
            }
            set_post_thumbnail($wordId, (int) $attachmentId);
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

    /**
     * @return array{user_id:int,wordset_id:int,category_id:int,word_ids:int[]}
     */
    private function createLineupFixture(
        string $wordsetLanguage,
        ?string $categoryDirection = null,
        int $wordCount = 3,
        ?array $enabledGames = null
    ): array
    {
        $userId = self::factory()->user->create(['role' => 'subscriber']);
        $wordset = wp_insert_term('Lineup Wordset ' . wp_generate_password(6, false), 'wordset');
        $category = wp_insert_term('Lineup Category ' . wp_generate_password(6, false), 'word-category');

        $this->assertFalse(is_wp_error($wordset));
        $this->assertFalse(is_wp_error($category));
        $this->assertIsArray($wordset);
        $this->assertIsArray($category);

        $wordsetId = (int) $wordset['term_id'];
        $categoryId = (int) $category['term_id'];

        update_term_meta($wordsetId, 'll_language', $wordsetLanguage);
        update_term_meta($categoryId, 'll_quiz_prompt_type', 'text_title');
        update_term_meta($categoryId, 'll_quiz_option_type', 'text_title');
        if ($categoryDirection !== null) {
            update_term_meta($categoryId, 'll_category_lineup_direction', $categoryDirection);
        }
        if ($enabledGames !== null) {
            $this->setCategoryEnabledGames($categoryId, $enabledGames);
        }

        $wordIds = [];
        for ($index = 1; $index <= $wordCount; $index++) {
            $wordIds[] = $this->createWordWithGameMedia(
                'Lineup Word ' . $index,
                'Lineup Translation ' . $index,
                $categoryId,
                $wordsetId,
                false,
                []
            );
        }

        return [
            'user_id' => $userId,
            'wordset_id' => $wordsetId,
            'category_id' => $categoryId,
            'word_ids' => $wordIds,
        ];
    }

    /**
     * @return array{user_id:int,wordset_id:int,category_id:int,word_ids:int[]}
     */
    private function createUnscrambleFixture(
        bool $withImage,
        string $translationPrefix = 'Unscramble Translation',
        int $wordCount = 5,
        ?array $enabledGames = null
    ): array {
        $userId = self::factory()->user->create(['role' => 'subscriber']);
        $wordset = wp_insert_term('Unscramble Wordset ' . wp_generate_password(6, false), 'wordset');
        $category = wp_insert_term('Unscramble Category ' . wp_generate_password(6, false), 'word-category');

        $this->assertFalse(is_wp_error($wordset));
        $this->assertFalse(is_wp_error($category));
        $this->assertIsArray($wordset);
        $this->assertIsArray($category);

        $wordsetId = (int) $wordset['term_id'];
        $categoryId = (int) $category['term_id'];

        update_term_meta($wordsetId, 'll_language', 'he');
        update_term_meta($categoryId, 'll_quiz_prompt_type', 'text_title');
        update_term_meta($categoryId, 'll_quiz_option_type', 'text_title');
        if ($enabledGames !== null) {
            $this->setCategoryEnabledGames($categoryId, $enabledGames);
        }

        $wordIds = [];
        for ($index = 1; $index <= $wordCount; $index++) {
            $wordIds[] = $this->createWordWithGameMedia(
                'Unscramble Word ' . $index,
                $translationPrefix === '' ? '' : ($translationPrefix . ' ' . $index),
                $categoryId,
                $wordsetId,
                $withImage,
                []
            );
        }

        return [
            'user_id' => $userId,
            'wordset_id' => $wordsetId,
            'category_id' => $categoryId,
            'word_ids' => $wordIds,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $words
     * @return int[]
     */
    private function extractLineupSequenceWordIds(array $words): array
    {
        return array_values(array_filter(array_map(static function ($word): int {
            return is_array($word) ? (int) ($word['id'] ?? 0) : 0;
        }, $words), static function (int $wordId): bool {
            return $wordId > 0;
        }));
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

    private function createAnimatedWebpAttachment(string $filename): int
    {
        $uploads = wp_upload_dir();
        $this->assertIsArray($uploads);

        $subdir = '/2026/03';
        $basedir = rtrim((string) ($uploads['basedir'] ?? ''), '/\\');
        $targetDir = $basedir . $subdir;
        wp_mkdir_p($targetDir);

        $path = $targetDir . '/' . ltrim($filename, '/');
        $bytes = "RIFF"
            . pack('V', 22)
            . "WEBP"
            . "ANIM"
            . pack('V', 6)
            . "\x00\x00\x00\x00\x00\x00"
            . "ANMF"
            . pack('V', 0);
        $this->assertNotFalse(file_put_contents($path, $bytes));

        $attachmentId = self::factory()->post->create([
            'post_type' => 'attachment',
            'post_mime_type' => 'image/webp',
            'post_status' => 'inherit',
            'post_title' => $filename,
        ]);

        update_post_meta($attachmentId, '_wp_attached_file', ltrim($subdir . '/' . $filename, '/'));

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
        $categoryId = $this->resolveEffectiveCategoryId($categoryId, $wordsetId);

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

    private function resolveEffectiveCategoryId(int $categoryId, int $wordsetId): int
    {
        if ($categoryId <= 0 || $wordsetId <= 0 || !function_exists('ll_tools_get_effective_category_id_for_wordset')) {
            return $categoryId;
        }

        $effectiveCategoryId = (int) ll_tools_get_effective_category_id_for_wordset($categoryId, $wordsetId, true);
        return $effectiveCategoryId > 0 ? $effectiveCategoryId : $categoryId;
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
