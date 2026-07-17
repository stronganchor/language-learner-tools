<?php
declare(strict_types=1);

final class WordsetCategoryOrderingAtomicSaveTest extends LL_Tools_TestCase
{
    /** @var array<string,mixed> */
    private $getBackup = [];

    /** @var array<string,mixed> */
    private $postBackup = [];

    /** @var array<string,mixed> */
    private $serverBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->getBackup = $_GET;
        $this->postBackup = $_POST;
        $this->serverBackup = $_SERVER;

        $adminRole = get_role('administrator');
        $this->assertNotNull($adminRole);
        $adminRole->add_cap('edit_wordsets');
        $adminRole->add_cap('view_ll_tools');
    }

    protected function tearDown(): void
    {
        $_GET = $this->getBackup;
        $_POST = $this->postBackup;
        $_SERVER = $this->serverBackup;
        set_query_var('ll_wordset_page', null);
        set_query_var('ll_wordset_view', null);
        parent::tearDown();
    }

    public function test_frontend_cycle_preserves_category_section_and_saves_other_advanced_fields(): void
    {
        $fixture = $this->createFixture();
        $wordsetId = $fixture['wordset_id'];
        $categoryIds = $fixture['category_ids'];
        $categoryNames = $fixture['category_names'];
        $wordsetTerm = $fixture['wordset_term'];
        $snapshot = $this->seedCategorySection($wordsetId, $categoryIds);

        $_GET = [];
        $_POST = [
            'll_wordset_manager_settings_action' => 'save',
            'll_wordset_manager_settings_wordset_id' => (string) $wordsetId,
            'll_wordset_manager_settings_nonce' => wp_create_nonce('ll_wordset_manager_settings_' . $wordsetId),
            'll_wordset_page' => (string) $wordsetTerm->slug,
            'll_wordset_view' => 'settings',
            'll_wordset_tool' => 'advanced',
            'll_wordset_profile_blurb' => 'This unrelated profile change should still be saved.',
            'll_wordset_games_image_size' => 'large',
            'll_wordset_category_ordering_mode' => 'prerequisite',
            'll_wordset_category_order_category_ids' => implode(',', $categoryIds),
            'll_wordset_category_manual_order' => implode(',', array_reverse($categoryIds)),
            'll_wordset_category_prereqs_compact_mode' => 'json-v1',
            'll_wordset_category_prereqs_compact' => (string) wp_json_encode([
                $categoryIds[0] => [$categoryIds[1]],
                $categoryIds[1] => [$categoryIds[0]],
            ]),
            'll_wordset_has_gender' => '1',
            'll_wordset_gender_options' => "Common\nNeuter",
        ];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = $this->requestUriFromUrl(
            ll_tools_get_wordset_settings_tool_url($wordsetTerm, 'advanced')
        );
        set_query_var('ll_wordset_page', (string) $wordsetTerm->slug);
        set_query_var('ll_wordset_view', 'settings');

        $redirectUrl = $this->captureRedirect(static function (): void {
            ll_tools_wordset_page_handle_manager_settings_action();
        });
        $query = $this->parseRedirectQuery($redirectUrl);

        $this->assertSame('partial', (string) ($query['ll_wordset_manager_settings'] ?? ''));
        $this->assertSame('prereq_cycle', (string) ($query['ll_wordset_manager_settings_error'] ?? ''));
        $message = (string) ($query['ll_wordset_manager_settings_message'] ?? '');
        $this->assertStringContainsString('Other word set settings were saved', $message);
        $this->assertStringContainsString($categoryNames[0], $message);
        $this->assertStringContainsString($categoryNames[1], $message);

        $_GET = $query;
        $notice = ll_tools_wordset_page_manager_settings_notice();
        $this->assertIsArray($notice);
        $this->assertSame('warning', (string) ($notice['type'] ?? ''));
        $this->assertSame($message, (string) ($notice['message'] ?? ''));
        $renderedNotice = ll_tools_wordset_page_render_settings_notice($notice);
        $this->assertStringContainsString('ll-wordset-progress-reset-notice--warning', $renderedNotice);
        $this->assertStringContainsString('role="status"', $renderedNotice);

        $this->assertSame(
            'This unrelated profile change should still be saved.',
            (string) get_term_meta($wordsetId, LL_TOOLS_WORDSET_PROFILE_BLURB_META_KEY, true)
        );
        $this->assertSame('large', (string) get_term_meta($wordsetId, LL_TOOLS_WORDSET_GAMES_IMAGE_SIZE_META_KEY, true));
        $this->assertSame('1', (string) get_term_meta($wordsetId, 'll_wordset_has_gender', true));
        $this->assertSame(['Common', 'Neuter'], array_values((array) get_term_meta($wordsetId, 'll_wordset_gender_options', true)));
        $this->assertCategorySectionSame($snapshot, $wordsetId);
    }

    public function test_taxonomy_malformed_payload_preserves_category_section_and_queues_warning(): void
    {
        $fixture = $this->createFixture();
        $wordsetId = $fixture['wordset_id'];
        $categoryIds = $fixture['category_ids'];
        $snapshot = $this->seedCategorySection($wordsetId, $categoryIds);

        $_POST = [
            'll_wordset_meta_nonce' => wp_create_nonce('ll_wordset_meta'),
            'll_wordset_games_image_size' => 'large',
            'll_wordset_category_ordering_mode' => 'prerequisite',
            'll_wordset_category_order_category_ids' => implode(',', $categoryIds),
            'll_wordset_category_manual_order' => implode(',', array_reverse($categoryIds)),
            'll_wordset_category_prereqs_compact_mode' => 'json-v1',
            'll_wordset_category_prereqs_compact' => '{malformed',
            'll_wordset_has_plurality' => '1',
            'll_wordset_plurality_options' => "Singular\nPlural",
        ];

        ll_save_wordset_language($wordsetId);

        $this->assertSame('large', (string) get_term_meta($wordsetId, LL_TOOLS_WORDSET_GAMES_IMAGE_SIZE_META_KEY, true));
        $this->assertSame('1', (string) get_term_meta($wordsetId, 'll_wordset_has_plurality', true));
        $this->assertSame(['Singular', 'Plural'], array_values((array) get_term_meta($wordsetId, 'll_wordset_plurality_options', true)));
        $this->assertCategorySectionSame($snapshot, $wordsetId);

        $notice = get_transient(ll_tools_wordset_category_order_notice_transient_key(get_current_user_id()));
        $this->assertIsArray($notice);
        $this->assertSame('warning', (string) ($notice['type'] ?? ''));
        $this->assertStringContainsString('Other word set settings were saved', (string) ($notice['message'] ?? ''));
        $this->assertStringContainsString('incomplete', (string) ($notice['message'] ?? ''));
    }

    public function test_unrelated_taxonomy_save_does_not_reset_category_section(): void
    {
        $fixture = $this->createFixture();
        $wordsetId = $fixture['wordset_id'];
        $snapshot = $this->seedCategorySection($wordsetId, $fixture['category_ids']);

        $_POST = [
            'll_wordset_meta_nonce' => wp_create_nonce('ll_wordset_meta'),
            'll_wordset_ipa_orthography_profile' => 'zazaki_genc_palu',
        ];

        ll_save_wordset_language($wordsetId);

        $this->assertSame(
            'zazaki_genc_palu',
            (string) get_term_meta($wordsetId, ll_tools_ipa_orthography_profile_meta_key(), true)
        );
        $this->assertCategorySectionSame($snapshot, $wordsetId);
    }

    public function test_missing_invalid_or_non_scalar_section_fields_are_rejected_before_writes(): void
    {
        $fixture = $this->createFixture();
        $wordsetId = $fixture['wordset_id'];
        $categoryIds = $fixture['category_ids'];
        $snapshot = $this->seedCategorySection($wordsetId, $categoryIds);

        $baseRequest = [
            'll_wordset_category_ordering_mode' => 'manual',
            'll_wordset_category_order_category_ids' => implode(',', $categoryIds),
            'll_wordset_category_manual_order' => implode(',', array_reverse($categoryIds)),
        ];
        $cases = [
            [array_diff_key($baseRequest, ['ll_wordset_category_ordering_mode' => true]), 'category_order_payload'],
            [array_merge($baseRequest, ['ll_wordset_category_ordering_mode' => 'unexpected']), 'category_order_payload'],
            [array_merge($baseRequest, ['ll_wordset_category_ordering_mode' => ['manual']]), 'category_order_payload'],
            [array_diff_key($baseRequest, ['ll_wordset_category_order_category_ids' => true]), 'category_registry'],
            [array_merge($baseRequest, ['ll_wordset_category_order_category_ids' => $categoryIds]), 'category_order_payload'],
            [array_merge($baseRequest, ['ll_wordset_category_manual_order' => $categoryIds]), 'category_order_payload'],
            [array_merge($baseRequest, [
                'll_wordset_category_prereqs_compact_mode' => 'unknown-v2',
                'll_wordset_category_prereqs_compact' => '{}',
            ]), 'category_order_payload'],
        ];

        foreach ($cases as [$request, $expectedCode]) {
            $result = ll_tools_wordset_save_category_ordering_settings($wordsetId, $request);
            $this->assertWPError($result);
            $this->assertSame($expectedCode, $result->get_error_code());
            $this->assertCategorySectionSame($snapshot, $wordsetId);
        }
    }

    public function test_stale_category_registry_is_rejected_before_any_category_write(): void
    {
        $fixture = $this->createFixture();
        $wordsetId = $fixture['wordset_id'];
        $categoryIds = $fixture['category_ids'];
        $snapshot = $this->seedCategorySection($wordsetId, $categoryIds);
        $staleId = max($categoryIds) + 100000;

        $result = ll_tools_wordset_save_category_ordering_settings($wordsetId, [
            'll_wordset_category_ordering_mode' => 'manual',
            'll_wordset_category_order_category_ids' => implode(',', array_merge($categoryIds, [$staleId])),
            'll_wordset_category_manual_order' => implode(',', array_merge(array_reverse($categoryIds), [$staleId])),
        ]);

        $this->assertWPError($result);
        $this->assertSame('category_registry', $result->get_error_code());
        $this->assertCategorySectionSame($snapshot, $wordsetId);
    }

    public function test_write_failure_restores_category_section_and_valid_retry_commits_all_keys(): void
    {
        $fixture = $this->createFixture();
        $wordsetId = $fixture['wordset_id'];
        $categoryIds = $fixture['category_ids'];
        $snapshot = $this->seedCategorySection($wordsetId, $categoryIds);
        $baselineOrder = (array) $snapshot['manual_order'];
        $nextOrder = array_reverse($baselineOrder);
        $request = [
            'll_wordset_category_ordering_mode' => 'prerequisite',
            'll_wordset_category_order_category_ids' => implode(',', $categoryIds),
            'll_wordset_category_manual_order' => implode(',', $nextOrder),
            'll_wordset_category_prereqs_compact_mode' => 'json-v1',
            'll_wordset_category_prereqs_compact' => (string) wp_json_encode([
                $categoryIds[0] => [$categoryIds[1]],
            ]),
        ];

        $failPrerequisiteWrite = static function ($check, int $objectId, string $metaKey) use ($wordsetId) {
            if ($objectId === $wordsetId && $metaKey === 'll_wordset_category_prerequisites') {
                return false;
            }
            return $check;
        };
        add_filter('update_term_metadata', $failPrerequisiteWrite, 10, 3);
        try {
            $result = ll_tools_wordset_save_category_ordering_settings($wordsetId, $request);
        } finally {
            remove_filter('update_term_metadata', $failPrerequisiteWrite, 10);
        }

        $this->assertWPError($result);
        $this->assertSame('category_order_write', $result->get_error_code());
        $this->assertCategorySectionSame($snapshot, $wordsetId);

        $retry = ll_tools_wordset_save_category_ordering_settings($wordsetId, $request);
        $this->assertTrue($retry);
        $this->assertSame('prerequisite', (string) get_term_meta($wordsetId, 'll_wordset_category_ordering_mode', true));
        $this->assertSame($nextOrder, array_values((array) get_term_meta($wordsetId, 'll_wordset_category_manual_order', true)));
        $this->assertSame(
            [$categoryIds[0] => [$categoryIds[1]]],
            (array) get_term_meta($wordsetId, 'll_wordset_category_prerequisites', true)
        );
    }

    /**
     * @return array{wordset_id:int,wordset_term:WP_Term,category_ids:int[],category_names:string[]}
     */
    private function createFixture(): array
    {
        $adminId = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($adminId);

        $insertedWordset = wp_insert_term('Atomic Settings ' . wp_generate_password(6, false), 'wordset');
        $this->assertIsArray($insertedWordset);
        $wordsetId = (int) $insertedWordset['term_id'];
        $wordsetTerm = get_term($wordsetId, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordsetTerm);

        $categoryIds = [];
        $categoryNames = [];
        foreach (['Alpha', 'Beta'] as $label) {
            $name = $label . ' Atomic Category ' . wp_generate_password(4, false);
            $insertedCategory = wp_insert_term($name, 'word-category');
            $this->assertIsArray($insertedCategory);
            $categoryId = (int) $insertedCategory['term_id'];
            update_term_meta($categoryId, 'll_quiz_prompt_type', 'text_title');
            update_term_meta($categoryId, 'll_quiz_option_type', 'text_title');
            ll_tools_set_category_wordset_owner($categoryId, $wordsetId, $categoryId);

            for ($wordIndex = 0; $wordIndex < LL_TOOLS_MIN_WORDS_PER_QUIZ; $wordIndex++) {
                $wordId = self::factory()->post->create([
                    'post_type' => 'words',
                    'post_status' => 'publish',
                    'post_title' => $label . ' Atomic Word ' . $wordIndex . ' ' . wp_generate_password(3, false),
                ]);
                wp_set_post_terms($wordId, [$categoryId], 'word-category', false);
                wp_set_post_terms($wordId, [$wordsetId], 'wordset', false);
                update_post_meta($wordId, 'word_translation', $label . ' translation ' . $wordIndex);
            }

            $categoryIds[] = $categoryId;
            $categoryNames[] = $name;
        }

        $rows = ll_tools_wordset_get_admin_category_ordering_rows($wordsetId);
        $this->assertSame($categoryIds, array_values(array_map('intval', wp_list_pluck($rows, 'id'))));

        return [
            'wordset_id' => $wordsetId,
            'wordset_term' => $wordsetTerm,
            'category_ids' => $categoryIds,
            'category_names' => $categoryNames,
        ];
    }

    /**
     * @param int[] $categoryIds
     * @return array{mode:mixed,manual_order:mixed,prerequisites:mixed}
     */
    private function seedCategorySection(int $wordsetId, array $categoryIds): array
    {
        $baselineOrder = ll_tools_wordset_get_default_manual_category_order($wordsetId, $categoryIds);
        update_term_meta($wordsetId, 'll_wordset_category_ordering_mode', 'manual');
        update_term_meta($wordsetId, 'll_wordset_category_manual_order', $baselineOrder);
        update_term_meta($wordsetId, 'll_wordset_category_prerequisites', [
            $categoryIds[1] => [$categoryIds[0]],
        ]);

        return $this->categorySectionSnapshot($wordsetId);
    }

    /**
     * @return array{mode:mixed,manual_order:mixed,prerequisites:mixed}
     */
    private function categorySectionSnapshot(int $wordsetId): array
    {
        return [
            'mode' => get_term_meta($wordsetId, 'll_wordset_category_ordering_mode', true),
            'manual_order' => get_term_meta($wordsetId, 'll_wordset_category_manual_order', true),
            'prerequisites' => get_term_meta($wordsetId, 'll_wordset_category_prerequisites', true),
        ];
    }

    /** @param array{mode:mixed,manual_order:mixed,prerequisites:mixed} $expected */
    private function assertCategorySectionSame(array $expected, int $wordsetId): void
    {
        $this->assertSame($expected, $this->categorySectionSnapshot($wordsetId));
    }

    private function requestUriFromUrl(string $url): string
    {
        $path = (string) wp_parse_url($url, PHP_URL_PATH);
        $query = (string) wp_parse_url($url, PHP_URL_QUERY);
        return $path . ($query !== '' ? ('?' . $query) : '');
    }

    /** @return array<string,string> */
    private function parseRedirectQuery(string $url): array
    {
        $query = (string) wp_parse_url($url, PHP_URL_QUERY);
        if ($query === '') {
            return [];
        }
        $parsed = [];
        parse_str($query, $parsed);
        return array_map('strval', $parsed);
    }

    private function captureRedirect(callable $callback): string
    {
        $redirectUrl = '';
        $redirectFilter = static function ($location) use (&$redirectUrl) {
            $redirectUrl = (string) $location;
            throw new RuntimeException('redirect_intercepted');
        };
        add_filter('wp_redirect', $redirectFilter, 10, 1);

        try {
            $callback();
            $this->fail('Expected redirect.');
        } catch (RuntimeException $e) {
            $this->assertSame('redirect_intercepted', $e->getMessage());
        } finally {
            remove_filter('wp_redirect', $redirectFilter, 10);
        }

        $this->assertNotSame('', $redirectUrl);
        return $redirectUrl;
    }
}
