<?php
declare(strict_types=1);

final class VocabLessonCategorySettingsTest extends LL_Tools_TestCase
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
    }

    protected function tearDown(): void
    {
        $_GET = $this->getBackup;
        $_POST = $this->postBackup;
        $_SERVER = $this->serverBackup;
        parent::tearDown();
    }

    public function test_managed_lesson_page_renders_category_settings_panel(): void
    {
        $manager_id = $this->createManagerUser();
        wp_set_current_user($manager_id);

        $fixture = $this->createManagedLessonFixture($manager_id);

        $this->go_to('/?post_type=ll_vocab_lesson&p=' . $fixture['lesson_id']);
        $this->assertTrue(is_singular('ll_vocab_lesson'));

        ob_start();
        include LL_TOOLS_BASE_PATH . '/templates/vocab-lesson-template.php';
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('ll-vocab-lesson-category-settings-trigger', $html);
        $this->assertStringContainsString('name="ll_vocab_lesson_category_settings_action" value="save"', $html);
        $this->assertStringContainsString('name="ll_vocab_lesson_category_settings_revision" value="0"', $html);
        $this->assertStringContainsString('name="ll_vocab_lesson_category_settings_client_id"', $html);
        $this->assertStringContainsString('name="ll_vocab_lesson_category_settings_sequence" value="1"', $html);
        $this->assertStringContainsString('name="ll_vocab_lesson_quiz_prompt_type"', $html);
        $this->assertStringContainsString('name="ll_vocab_lesson_category_enabled_games[]"', $html);
        $this->assertStringContainsString('data-ll-category-lineup-ordering', $html);
        $this->assertStringNotContainsString('name="ll_vocab_lesson_category_lineup_word_ids"', $html);
        $this->assertStringContainsString('data-ll-category-settings-status', $html);
        $this->assertStringContainsString('data-ll-category-settings-delete', $html);
        $this->assertStringContainsString('Delete Category', $html);
        $this->assertStringContainsString('ll_editor_split_category=1', $html);
        $this->assertStringContainsString('ll_editor_category=' . (int) $fixture['category_id'], $html);
        $this->assertStringContainsString('#ll-wordset-editor-split', $html);
        $this->assertStringNotContainsString('ll-vocab-lesson-category-settings-save', $html);
    }

    public function test_managed_lesson_page_renders_add_word_button_for_editors(): void
    {
        $manager_id = $this->createManagerUser();
        wp_set_current_user($manager_id);

        $fixture = $this->createManagedLessonFixture($manager_id);

        $this->go_to('/?post_type=ll_vocab_lesson&p=' . $fixture['lesson_id']);
        $this->assertTrue(is_singular('ll_vocab_lesson'));

        ob_start();
        include LL_TOOLS_BASE_PATH . '/templates/vocab-lesson-template.php';
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('data-ll-add-lesson-word', $html);
        $this->assertStringContainsString('data-lesson-id="' . (int) $fixture['lesson_id'] . '"', $html);
        $this->assertStringContainsString('ll-vocab-lesson-add-word__button', $html);
    }

    public function test_managed_empty_lesson_page_renders_category_delete_shortcut_for_managers(): void
    {
        $manager_id = $this->createManagerUser();
        wp_set_current_user($manager_id);

        $fixture = $this->createManagedLessonFixture($manager_id, false);

        $this->go_to('/?post_type=ll_vocab_lesson&p=' . $fixture['lesson_id']);
        $this->assertTrue(is_singular('ll_vocab_lesson'));

        ob_start();
        include LL_TOOLS_BASE_PATH . '/templates/vocab-lesson-template.php';
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('ll-vocab-lesson-empty-actions', $html);
        $this->assertStringContainsString('ll-vocab-lesson-empty-category-delete', $html);
        $this->assertStringContainsString('name="ll_vocab_lesson_category_settings_action" value="delete"', $html);
        $this->assertStringContainsString('Delete empty category', $html);
    }

    public function test_category_delete_state_and_busy_notices_remain_actionable_after_redirect(): void
    {
        $_GET = [
            'll_vocab_lesson_category_settings' => 'error',
            'll_vocab_lesson_category_settings_error' => 'category_delete_state',
        ];
        $stateNotice = ll_tools_get_vocab_lesson_category_settings_notice();
        $this->assertIsArray($stateNotice);
        $this->assertSame('error', $stateNotice['type']);
        $this->assertSame('Category deletion progress could not be saved. Please try again.', $stateNotice['message']);

        $_GET['ll_vocab_lesson_category_settings_error'] = 'category_delete_busy';
        $busyNotice = ll_tools_get_vocab_lesson_category_settings_notice();
        $this->assertIsArray($busyNotice);
        $this->assertSame('error', $busyNotice['type']);
        $this->assertSame('Another category deletion batch is already running for this word set. Please try again shortly.', $busyNotice['message']);
    }

    public function test_wordset_manager_can_save_category_settings_from_lesson_page(): void
    {
        wp_insert_term('Isolation', 'recording_type', ['slug' => 'isolation']);
        wp_insert_term('Question', 'recording_type', ['slug' => 'question']);

        $manager_id = $this->createManagerUser();
        wp_set_current_user($manager_id);

        $fixture = $this->createManagedLessonFixture($manager_id);

        $this->go_to('/?post_type=ll_vocab_lesson&p=' . $fixture['lesson_id']);
        $this->assertTrue(is_singular('ll_vocab_lesson'));
        $this->assertTrue(ll_tools_user_can_manage_vocab_lesson_category_settings((int) $fixture['category_id'], (int) $fixture['wordset_id']));

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'll_vocab_lesson_category_settings_action' => 'save',
            'll_vocab_lesson_category_settings_lesson_id' => (string) $fixture['lesson_id'],
            'll_vocab_lesson_category_settings_wordset_id' => (string) $fixture['wordset_id'],
            'll_vocab_lesson_category_settings_category_id' => (string) $fixture['category_id'],
            'll_vocab_lesson_category_settings_nonce' => wp_create_nonce('ll_vocab_lesson_category_settings_' . $fixture['lesson_id']),
            'll_vocab_lesson_category_settings_revision' => '0',
            'll_vocab_lesson_category_settings_client_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'll_vocab_lesson_category_settings_sequence' => '1',
            'll_vocab_lesson_quiz_prompt_type' => 'text_translation',
            'll_vocab_lesson_quiz_option_type' => 'text_title',
            'll_vocab_lesson_grid_text_visibility' => 'hide',
            'll_vocab_lesson_category_enabled_games' => ['line-up', 'unscramble'],
            'll_vocab_lesson_desired_recording_types' => ['isolation', 'question'],
            'll_vocab_lesson_category_lineup_submitted' => '1',
            'll_vocab_lesson_category_lineup_replace' => '1',
            'll_vocab_lesson_category_lineup_direction' => 'rtl',
            'll_vocab_lesson_category_lineup_word_ids' => (string) $fixture['word_b_id'] . ',' . (string) $fixture['word_a_id'],
        ];

        $redirect_url = $this->captureRedirect(static function (): void {
            ll_tools_handle_vocab_lesson_category_settings_submit();
        });

        $query = [];
        parse_str((string) wp_parse_url($redirect_url, PHP_URL_QUERY), $query);

        $this->assertSame('ok', (string) ($query['ll_vocab_lesson_category_settings'] ?? ''));
        $this->assertSame('text_translation', (string) get_term_meta((int) $fixture['category_id'], 'll_quiz_prompt_type', true));
        $this->assertSame('text_title', (string) get_term_meta((int) $fixture['category_id'], 'll_quiz_option_type', true));
        $this->assertSame('1', (string) get_term_meta((int) $fixture['category_id'], 'use_word_titles_for_audio', true));
        $this->assertSame('hide', (string) get_term_meta((int) $fixture['category_id'], 'll_lesson_grid_text_visibility_override', true));
        $this->assertSame(['line-up', 'unscramble'], ll_tools_get_category_enabled_games((int) $fixture['category_id']));
        $this->assertSame(['isolation', 'question'], ll_tools_get_desired_recording_types_for_category((int) $fixture['category_id']));
        $this->assertSame('rtl', (string) get_term_meta((int) $fixture['category_id'], LL_TOOLS_CATEGORY_LINEUP_DIRECTION_META_KEY, true));
        $this->assertSame(
            [(int) $fixture['word_b_id'], (int) $fixture['word_a_id']],
            array_map('intval', (array) get_term_meta((int) $fixture['category_id'], LL_TOOLS_CATEGORY_LINEUP_WORD_ORDER_META_KEY, true))
        );
    }

    public function test_category_settings_autosave_preserves_paged_sequence_while_saving_direction(): void
    {
        $manager_id = $this->createManagerUser();
        wp_set_current_user($manager_id);
        $fixture = $this->createManagedLessonFixture($manager_id);
        $stored_order = [(int) $fixture['word_b_id'], (int) $fixture['word_a_id']];
        update_term_meta(
            (int) $fixture['category_id'],
            LL_TOOLS_CATEGORY_LINEUP_WORD_ORDER_META_KEY,
            $stored_order
        );
        update_term_meta(
            (int) $fixture['category_id'],
            LL_TOOLS_CATEGORY_LINEUP_DIRECTION_META_KEY,
            'rtl'
        );

        $result = ll_tools_save_vocab_lesson_category_settings_from_request([
            'll_vocab_lesson_category_settings_action' => 'save',
            'll_vocab_lesson_category_settings_lesson_id' => (string) $fixture['lesson_id'],
            'll_vocab_lesson_category_settings_wordset_id' => (string) $fixture['wordset_id'],
            'll_vocab_lesson_category_settings_category_id' => (string) $fixture['category_id'],
            'll_vocab_lesson_category_settings_nonce' => wp_create_nonce('ll_vocab_lesson_category_settings_' . $fixture['lesson_id']),
            'll_vocab_lesson_category_lineup_submitted' => '1',
            'll_vocab_lesson_category_lineup_direction' => 'ltr',
            'll_vocab_lesson_category_lineup_word_ids' => (string) $fixture['word_a_id'],
        ]);

        $this->assertIsArray($result);
        $this->assertSame(
            $stored_order,
            array_map(
                'intval',
                (array) get_term_meta(
                    (int) $fixture['category_id'],
                    LL_TOOLS_CATEGORY_LINEUP_WORD_ORDER_META_KEY,
                    true
                )
            )
        );
        $this->assertSame(
            'ltr',
            (string) get_term_meta(
                (int) $fixture['category_id'],
                LL_TOOLS_CATEGORY_LINEUP_DIRECTION_META_KEY,
                true
            )
        );
    }

    public function test_delayed_older_autosave_cannot_overwrite_a_newer_revision(): void
    {
        $manager_id = $this->createManagerUser();
        wp_set_current_user($manager_id);
        $fixture = $this->createManagedLessonFixture($manager_id);
        $category_id = (int) $fixture['category_id'];
        $nonce = wp_create_nonce('ll_vocab_lesson_category_settings_' . $fixture['lesson_id']);

        $base_request = [
            'll_vocab_lesson_category_settings_action' => 'save',
            'll_vocab_lesson_category_settings_lesson_id' => (string) $fixture['lesson_id'],
            'll_vocab_lesson_category_settings_wordset_id' => (string) $fixture['wordset_id'],
            'll_vocab_lesson_category_settings_category_id' => (string) $category_id,
            'll_vocab_lesson_category_settings_nonce' => $nonce,
            'll_vocab_lesson_category_settings_revision' => '0',
            'll_vocab_lesson_category_settings_client_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'll_vocab_lesson_quiz_option_type' => 'image',
        ];

        $newer_result = ll_tools_save_vocab_lesson_category_settings_from_request(array_merge($base_request, [
            'll_vocab_lesson_category_settings_sequence' => '2',
            'll_vocab_lesson_quiz_prompt_type' => 'text_translation',
            'll_vocab_lesson_grid_text_visibility' => 'hide',
            'll_vocab_lesson_category_enabled_games' => ['line-up'],
        ]));
        $this->assertIsArray($newer_result);
        $this->assertSame(1, (int) ($newer_result['revision'] ?? 0));

        // Model the original request reaching its commit point after the newer
        // queued request has already completed with the same base revision.
        $delayed_older_result = ll_tools_save_vocab_lesson_category_settings_from_request(array_merge($base_request, [
            'll_vocab_lesson_category_settings_sequence' => '1',
            'll_vocab_lesson_quiz_prompt_type' => 'audio',
            'll_vocab_lesson_grid_text_visibility' => 'show',
            'll_vocab_lesson_category_enabled_games' => ['unscramble'],
        ]));

        $this->assertWPError($delayed_older_result);
        $this->assertSame('revision_conflict', ll_tools_get_vocab_lesson_category_settings_error_code($delayed_older_result));
        $error_data = $delayed_older_result->get_error_data();
        $this->assertIsArray($error_data);
        $this->assertSame(1, (int) ($error_data['current_revision'] ?? 0));
        $this->assertSame(0, (int) ($error_data['same_client_predecessor'] ?? 0));
        $this->assertSame(1, ll_tools_get_vocab_lesson_category_settings_revision($category_id));
        $this->assertSame('text_translation', (string) get_term_meta($category_id, 'll_quiz_prompt_type', true));
        $this->assertSame('hide', (string) get_term_meta($category_id, 'll_lesson_grid_text_visibility_override', true));
        $this->assertSame(['line-up'], ll_tools_get_category_enabled_games($category_id));
    }

    public function test_same_client_sequence_cannot_regress_at_the_current_revision(): void
    {
        $manager_id = $this->createManagerUser();
        wp_set_current_user($manager_id);
        $fixture = $this->createManagedLessonFixture($manager_id);
        $category_id = (int) $fixture['category_id'];
        $client_id = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $base_request = [
            'll_vocab_lesson_category_settings_action' => 'save',
            'll_vocab_lesson_category_settings_lesson_id' => (string) $fixture['lesson_id'],
            'll_vocab_lesson_category_settings_wordset_id' => (string) $fixture['wordset_id'],
            'll_vocab_lesson_category_settings_category_id' => (string) $category_id,
            'll_vocab_lesson_category_settings_nonce' => wp_create_nonce('ll_vocab_lesson_category_settings_' . $fixture['lesson_id']),
            'll_vocab_lesson_category_settings_client_id' => $client_id,
            'll_vocab_lesson_quiz_option_type' => 'image',
        ];

        $first_result = ll_tools_save_vocab_lesson_category_settings_from_request(array_merge($base_request, [
            'll_vocab_lesson_category_settings_revision' => '0',
            'll_vocab_lesson_category_settings_sequence' => '5',
            'll_vocab_lesson_quiz_prompt_type' => 'text_translation',
            'll_vocab_lesson_grid_text_visibility' => 'hide',
        ]));
        $this->assertIsArray($first_result);
        $this->assertSame(1, (int) ($first_result['revision'] ?? 0));

        foreach (['5', '4'] as $stale_sequence) {
            $stale_result = ll_tools_save_vocab_lesson_category_settings_from_request(array_merge($base_request, [
                'll_vocab_lesson_category_settings_revision' => '1',
                'll_vocab_lesson_category_settings_sequence' => $stale_sequence,
                'll_vocab_lesson_quiz_prompt_type' => 'audio',
                'll_vocab_lesson_grid_text_visibility' => 'show',
            ]));
            $this->assertWPError($stale_result);
            $this->assertSame(
                'revision_conflict',
                ll_tools_get_vocab_lesson_category_settings_error_code($stale_result)
            );
            $stale_error_data = $stale_result->get_error_data();
            $this->assertIsArray($stale_error_data);
            $this->assertSame(1, (int) ($stale_error_data['current_revision'] ?? 0));
            $this->assertSame(0, (int) ($stale_error_data['same_client_predecessor'] ?? 0));
        }
        $this->assertSame('text_translation', (string) get_term_meta($category_id, 'll_quiz_prompt_type', true));
        $this->assertSame('hide', (string) get_term_meta($category_id, 'll_lesson_grid_text_visibility_override', true));

        $foreign_client_result = ll_tools_save_vocab_lesson_category_settings_from_request(array_merge($base_request, [
            'll_vocab_lesson_category_settings_revision' => '1',
            'll_vocab_lesson_category_settings_client_id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
            'll_vocab_lesson_category_settings_sequence' => '1',
            'll_vocab_lesson_quiz_prompt_type' => 'audio',
            'll_vocab_lesson_grid_text_visibility' => 'show',
        ]));
        $this->assertIsArray($foreign_client_result);
        $this->assertSame(2, (int) ($foreign_client_result['revision'] ?? 0));
        $this->assertSame('audio', (string) get_term_meta($category_id, 'll_quiz_prompt_type', true));
        $this->assertSame('show', (string) get_term_meta($category_id, 'll_lesson_grid_text_visibility_override', true));
    }

    public function test_malformed_or_partial_revision_fence_is_rejected_before_locking(): void
    {
        $manager_id = $this->createManagerUser();
        wp_set_current_user($manager_id);
        $fixture = $this->createManagedLessonFixture($manager_id);
        $base_request = [
            'll_vocab_lesson_category_settings_action' => 'save',
            'll_vocab_lesson_category_settings_lesson_id' => (string) $fixture['lesson_id'],
            'll_vocab_lesson_category_settings_wordset_id' => (string) $fixture['wordset_id'],
            'll_vocab_lesson_category_settings_category_id' => (string) $fixture['category_id'],
            'll_vocab_lesson_category_settings_nonce' => wp_create_nonce('ll_vocab_lesson_category_settings_' . $fixture['lesson_id']),
        ];
        $valid_client_id = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $invalid_requests = [
            'revision without client or sequence' => [
                'll_vocab_lesson_category_settings_revision' => '0',
            ],
            'client without revision or sequence' => [
                'll_vocab_lesson_category_settings_client_id' => $valid_client_id,
            ],
            'sequence without revision or client' => [
                'll_vocab_lesson_category_settings_sequence' => '1',
            ],
            'revision and client without sequence' => [
                'll_vocab_lesson_category_settings_revision' => '0',
                'll_vocab_lesson_category_settings_client_id' => $valid_client_id,
            ],
            'revision and sequence without client' => [
                'll_vocab_lesson_category_settings_revision' => '0',
                'll_vocab_lesson_category_settings_sequence' => '1',
            ],
            'malformed client' => [
                'll_vocab_lesson_category_settings_revision' => '0',
                'll_vocab_lesson_category_settings_client_id' => 'not-a-client-id',
                'll_vocab_lesson_category_settings_sequence' => '1',
            ],
            'malformed sequence' => [
                'll_vocab_lesson_category_settings_revision' => '0',
                'll_vocab_lesson_category_settings_client_id' => $valid_client_id,
                'll_vocab_lesson_category_settings_sequence' => '1.5',
            ],
        ];
        $lock_filter_called = false;
        $lock_filter = static function ($wait_seconds) use (&$lock_filter_called) {
            $lock_filter_called = true;
            return $wait_seconds;
        };
        add_filter('ll_tools_vocab_lesson_category_settings_lock_wait_seconds', $lock_filter);

        try {
            foreach ($invalid_requests as $label => $invalid_request) {
                $lock_filter_called = false;
                $result = ll_tools_save_vocab_lesson_category_settings_from_request(
                    array_merge($base_request, $invalid_request)
                );

                $this->assertWPError($result, $label);
                $this->assertSame(
                    'revision_request',
                    ll_tools_get_vocab_lesson_category_settings_error_code($result),
                    $label
                );
                $this->assertFalse($lock_filter_called, $label . ' reached the advisory-lock path');
            }
        } finally {
            remove_filter('ll_tools_vocab_lesson_category_settings_lock_wait_seconds', $lock_filter);
        }
    }

    public function test_same_page_timeout_conflict_allows_only_the_newer_sequence_to_replay(): void
    {
        $manager_id = $this->createManagerUser();
        wp_set_current_user($manager_id);
        $fixture = $this->createManagedLessonFixture($manager_id);
        $category_id = (int) $fixture['category_id'];
        $client_id = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $base_request = [
            'll_vocab_lesson_category_settings_action' => 'save',
            'll_vocab_lesson_category_settings_lesson_id' => (string) $fixture['lesson_id'],
            'll_vocab_lesson_category_settings_wordset_id' => (string) $fixture['wordset_id'],
            'll_vocab_lesson_category_settings_category_id' => (string) $category_id,
            'll_vocab_lesson_category_settings_nonce' => wp_create_nonce('ll_vocab_lesson_category_settings_' . $fixture['lesson_id']),
            'll_vocab_lesson_category_settings_client_id' => $client_id,
            'll_vocab_lesson_quiz_option_type' => 'image',
        ];

        $timed_out_result = ll_tools_save_vocab_lesson_category_settings_from_request(array_merge($base_request, [
            'll_vocab_lesson_category_settings_revision' => '0',
            'll_vocab_lesson_category_settings_sequence' => '1',
            'll_vocab_lesson_quiz_prompt_type' => 'audio',
            'll_vocab_lesson_grid_text_visibility' => 'show',
        ]));
        $this->assertIsArray($timed_out_result);
        $this->assertSame(1, (int) ($timed_out_result['revision'] ?? 0));

        $queued_result = ll_tools_save_vocab_lesson_category_settings_from_request(array_merge($base_request, [
            'll_vocab_lesson_category_settings_revision' => '0',
            'll_vocab_lesson_category_settings_sequence' => '2',
            'll_vocab_lesson_quiz_prompt_type' => 'text_translation',
            'll_vocab_lesson_grid_text_visibility' => 'hide',
        ]));
        $this->assertWPError($queued_result);
        $queued_error_data = $queued_result->get_error_data();
        $this->assertIsArray($queued_error_data);
        $this->assertSame(1, (int) ($queued_error_data['current_revision'] ?? 0));
        $this->assertSame(1, (int) ($queued_error_data['same_client_predecessor'] ?? 0));

        $retry_result = ll_tools_save_vocab_lesson_category_settings_from_request(array_merge($base_request, [
            'll_vocab_lesson_category_settings_revision' => '1',
            'll_vocab_lesson_category_settings_sequence' => '3',
            'll_vocab_lesson_quiz_prompt_type' => 'text_translation',
            'll_vocab_lesson_grid_text_visibility' => 'hide',
        ]));
        $this->assertIsArray($retry_result);
        $this->assertSame(2, (int) ($retry_result['revision'] ?? 0));
        $this->assertSame('text_translation', (string) get_term_meta($category_id, 'll_quiz_prompt_type', true));
        $this->assertSame('hide', (string) get_term_meta($category_id, 'll_lesson_grid_text_visibility_override', true));

        $revision_state = ll_tools_get_vocab_lesson_category_settings_revision_state($category_id);
        $this->assertSame(2, (int) $revision_state['revision']);
        $this->assertSame($client_id, (string) $revision_state['client_id']);
        $this->assertSame(3, (int) $revision_state['sequence']);
    }

    public function test_external_tab_conflict_is_not_retryable_and_preserves_the_newer_tab(): void
    {
        global $wpdb;

        $manager_id = $this->createManagerUser();
        wp_set_current_user($manager_id);
        $fixture = $this->createManagedLessonFixture($manager_id);
        $category_id = (int) $fixture['category_id'];
        $base_request = [
            'll_vocab_lesson_category_settings_action' => 'save',
            'll_vocab_lesson_category_settings_lesson_id' => (string) $fixture['lesson_id'],
            'll_vocab_lesson_category_settings_wordset_id' => (string) $fixture['wordset_id'],
            'll_vocab_lesson_category_settings_category_id' => (string) $category_id,
            'll_vocab_lesson_category_settings_nonce' => wp_create_nonce('ll_vocab_lesson_category_settings_' . $fixture['lesson_id']),
            'll_vocab_lesson_category_settings_revision' => '0',
            'll_vocab_lesson_category_settings_sequence' => '1',
            'll_vocab_lesson_quiz_option_type' => 'image',
        ];

        $newer_tab_result = ll_tools_save_vocab_lesson_category_settings_from_request(array_merge($base_request, [
            'll_vocab_lesson_category_settings_client_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'll_vocab_lesson_quiz_prompt_type' => 'text_translation',
            'll_vocab_lesson_grid_text_visibility' => 'hide',
        ]));
        $this->assertIsArray($newer_tab_result);

        $stale_tab_result = ll_tools_save_vocab_lesson_category_settings_from_request(array_merge($base_request, [
            'll_vocab_lesson_category_settings_client_id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
            'll_vocab_lesson_quiz_prompt_type' => 'audio',
            'll_vocab_lesson_grid_text_visibility' => 'show',
        ]));
        $this->assertWPError($stale_tab_result);
        $stale_error_data = $stale_tab_result->get_error_data();
        $this->assertIsArray($stale_error_data);
        $this->assertSame(0, (int) ($stale_error_data['same_client_predecessor'] ?? 0));
        $lock_is_free = $wpdb->get_var($wpdb->prepare(
            'SELECT IS_FREE_LOCK(%s)',
            ll_tools_vocab_lesson_category_settings_lock_name($category_id)
        ));
        $this->assertSame('1', (string) $lock_is_free);
        $this->assertSame(1, ll_tools_get_vocab_lesson_category_settings_revision($category_id));
        $this->assertSame('text_translation', (string) get_term_meta($category_id, 'll_quiz_prompt_type', true));
        $this->assertSame('hide', (string) get_term_meta($category_id, 'll_lesson_grid_text_visibility_override', true));
    }

    public function test_revision_state_reader_accepts_the_legacy_scalar_value(): void
    {
        $manager_id = $this->createManagerUser();
        $fixture = $this->createManagedLessonFixture($manager_id);
        $category_id = (int) $fixture['category_id'];
        update_term_meta($category_id, ll_tools_vocab_lesson_category_settings_revision_meta_key(), 7);

        $state = ll_tools_get_vocab_lesson_category_settings_revision_state($category_id);
        $this->assertSame(7, (int) $state['revision']);
        $this->assertSame('', (string) $state['client_id']);
        $this->assertSame(0, (int) $state['sequence']);
    }

    public function test_recording_type_source_failure_is_retryable_and_mutates_nothing(): void
    {
        $failure = $this->createCategorySettingsFailureFixture();
        $injected = false;
        $term_failure = static function ($terms, array $taxonomies) use (&$injected) {
            if (!$injected && in_array('recording_type', $taxonomies, true)) {
                $injected = true;
                return new WP_Error('ll_tools_test_recording_type_source_failure');
            }
            return $terms;
        };
        add_filter('get_terms', $term_failure, 10, 2);
        try {
            $result = ll_tools_save_vocab_lesson_category_settings_from_request($failure['request']);
        } finally {
            remove_filter('get_terms', $term_failure, 10);
        }

        $this->assertTrue($injected);
        $this->assertCategorySettingsRetryableFailure($result, 'recording_types_source');
        $this->assertCategorySettingsFailureFixtureUnchanged($failure);
    }

    public function test_quiz_config_source_failure_is_retryable_and_mutates_nothing(): void
    {
        global $wpdb;

        $failure = $this->createCategorySettingsFailureFixture();
        $category_id = (int) $failure['category_id'];
        $injected = false;
        $meta_failure = static function ($check, $object_id, $meta_key) use (&$injected, $category_id, $wpdb) {
            if (!$injected && (int) $object_id === $category_id && $meta_key === 'use_word_titles_for_audio') {
                $injected = true;
                $wpdb->last_error = 'll_tools_test_quiz_config_source_failure';
                return '';
            }
            return $check;
        };
        add_filter('get_term_metadata', $meta_failure, 10, 3);
        try {
            $result = ll_tools_save_vocab_lesson_category_settings_from_request($failure['request']);
        } finally {
            remove_filter('get_term_metadata', $meta_failure, 10);
            $wpdb->last_error = '';
        }

        $this->assertTrue($injected);
        $this->assertCategorySettingsRetryableFailure($result, 'quiz_config_source');
        $this->assertCategorySettingsFailureFixtureUnchanged($failure);
    }

    public function test_owner_source_failure_is_retryable_and_mutates_nothing(): void
    {
        global $wpdb;

        $failure = $this->createCategorySettingsFailureFixture();
        $categoryId = (int) $failure['category_id'];
        $injected = false;
        $ownerReadCount = 0;
        $metaFailure = static function ($check, $objectId, $metaKey) use (&$injected, &$ownerReadCount, $categoryId, $wpdb) {
            if (
                (int) $objectId === $categoryId
                && $metaKey === LL_TOOLS_CATEGORY_WORDSET_OWNER_META_KEY
            ) {
                $ownerReadCount++;
                if (!$injected && $ownerReadCount === 2) {
                    $injected = true;
                    $wpdb->last_error = 'll_tools_test_owner_source_failure';
                    return '';
                }
            }
            return $check;
        };
        add_filter('get_term_metadata', $metaFailure, 10, 3);
        try {
            $result = ll_tools_save_vocab_lesson_category_settings_from_request($failure['request']);
        } finally {
            remove_filter('get_term_metadata', $metaFailure, 10);
            $wpdb->last_error = '';
        }

        $this->assertTrue($injected);
        $this->assertGreaterThanOrEqual(2, $ownerReadCount);
        $this->assertCategorySettingsRetryableFailure($result, 'owner_source');
        $this->assertCategorySettingsFailureFixtureUnchanged($failure);
    }

    public function test_lesson_association_is_revalidated_after_acquiring_the_category_lock(): void
    {
        $failure = $this->createCategorySettingsFailureFixture();
        $request = $failure['request'];
        $lessonId = (int) $request['ll_vocab_lesson_category_settings_lesson_id'];
        $originalCategoryId = (int) $failure['category_id'];
        $otherCategory = wp_insert_term(
            'Concurrent Owner Change ' . wp_generate_password(6, false),
            'word-category'
        );
        $this->assertIsArray($otherCategory);
        $otherCategoryId = (int) $otherCategory['term_id'];
        $changedWhileWaiting = false;
        $lockFilter = static function ($waitSeconds) use (&$changedWhileWaiting, $lessonId, $otherCategoryId) {
            if (!$changedWhileWaiting) {
                $changedWhileWaiting = true;
                update_post_meta($lessonId, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, $otherCategoryId);
            }
            return $waitSeconds;
        };
        add_filter('ll_tools_vocab_lesson_category_settings_lock_wait_seconds', $lockFilter);
        try {
            $result = ll_tools_save_vocab_lesson_category_settings_from_request($request);
        } finally {
            remove_filter('ll_tools_vocab_lesson_category_settings_lock_wait_seconds', $lockFilter);
            update_post_meta($lessonId, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, $originalCategoryId);
        }

        $this->assertTrue($changedWhileWaiting);
        $this->assertWPError($result);
        $this->assertSame('wordset', ll_tools_get_vocab_lesson_category_settings_error_code($result));
        $this->assertCategorySettingsFailureFixtureUnchanged($failure);
    }

    public function test_lesson_association_source_failure_after_lock_is_retryable_and_mutates_nothing(): void
    {
        global $wpdb;

        $failure = $this->createCategorySettingsFailureFixture();
        $request = $failure['request'];
        $lessonId = (int) $request['ll_vocab_lesson_category_settings_lesson_id'];
        $wordsetReadCount = 0;
        $injected = false;
        $metaFailure = static function ($check, $objectId, $metaKey) use (
            &$wordsetReadCount,
            &$injected,
            $lessonId,
            $wpdb
        ) {
            if (
                (int) $objectId === $lessonId
                && $metaKey === LL_TOOLS_VOCAB_LESSON_WORDSET_META
            ) {
                $wordsetReadCount++;
                if (!$injected && $wordsetReadCount === 2) {
                    $injected = true;
                    $wpdb->last_error = 'll_tools_test_lesson_owner_source_failure';
                    return '';
                }
            }
            return $check;
        };
        add_filter('get_post_metadata', $metaFailure, 10, 3);
        try {
            $result = ll_tools_save_vocab_lesson_category_settings_from_request($request);
        } finally {
            remove_filter('get_post_metadata', $metaFailure, 10);
            $wpdb->last_error = '';
        }

        $this->assertTrue($injected);
        $this->assertGreaterThanOrEqual(2, $wordsetReadCount);
        $this->assertCategorySettingsRetryableFailure($result, 'lesson_source');
        $this->assertCategorySettingsFailureFixtureUnchanged($failure);
    }

    public function test_failed_settings_write_rolls_back_all_meta_and_revision_changes(): void
    {
        $failure = $this->createCategorySettingsFailureFixture();
        $category_id = (int) $failure['category_id'];
        $injected = false;
        $write_failure = static function ($check, $object_id, $meta_key) use (&$injected, $category_id) {
            if (
                !$injected
                && (int) $object_id === $category_id
                && $meta_key === LL_TOOLS_CATEGORY_ENABLED_GAMES_META_KEY
            ) {
                $injected = true;
                return false;
            }
            return $check;
        };
        add_filter('update_term_metadata', $write_failure, 10, 3);
        try {
            $result = ll_tools_save_vocab_lesson_category_settings_from_request($failure['request']);
        } finally {
            remove_filter('update_term_metadata', $write_failure, 10);
        }

        $this->assertTrue($injected);
        $this->assertCategorySettingsRetryableFailure($result, 'settings_write');
        $this->assertCategorySettingsFailureFixtureUnchanged($failure);
    }

    public function test_nontransactional_termmeta_fails_closed_before_mutation(): void
    {
        $failure = $this->createCategorySettingsFailureFixture();
        $nontransactional = static function (): bool {
            return false;
        };
        add_filter('ll_tools_vocab_lesson_category_settings_termmeta_is_transactional', $nontransactional);
        try {
            $result = ll_tools_save_vocab_lesson_category_settings_from_request($failure['request']);
        } finally {
            remove_filter('ll_tools_vocab_lesson_category_settings_termmeta_is_transactional', $nontransactional);
        }

        $this->assertWPError($result);
        $this->assertSame('transaction_storage', ll_tools_get_vocab_lesson_category_settings_error_code($result));
        $error_data = $result->get_error_data();
        $this->assertIsArray($error_data);
        $this->assertSame(503, (int) ($error_data['status'] ?? 0));
        $this->assertFalse((bool) ($error_data['retryable'] ?? true));
        $this->assertCategorySettingsFailureFixtureUnchanged($failure);
    }

    public function test_taxonomy_form_renders_revision_fence_and_registers_one_guarded_writer_before_sync(): void
    {
        global $wpdb;

        $adminId = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($adminId);
        $fixture = $this->createManagedLessonFixture($adminId, false);
        $categoryId = (int) $fixture['category_id'];
        update_term_meta(
            $categoryId,
            ll_tools_vocab_lesson_category_settings_revision_meta_key(),
            ['revision' => 7, 'client_id' => '', 'sequence' => 0]
        );

        ob_start();
        ll_tools_add_category_settings_revision_field();
        $addHtml = (string) ob_get_clean();
        ob_start();
        ll_tools_edit_category_settings_revision_field(get_term($categoryId, 'word-category'));
        $editHtml = (string) ob_get_clean();

        $this->assertStringContainsString('name="ll_vocab_lesson_category_settings_revision" value="0"', $addHtml);
        $this->assertStringContainsString('name="_ll_vocab_lesson_category_settings_render_complete" value="1"', $addHtml);
        $this->assertStringContainsString('name="ll_vocab_lesson_category_settings_revision" value="7"', $editHtml);
        $this->assertStringContainsString('name="_ll_vocab_lesson_category_settings_render_complete" value="1"', $editHtml);
        $this->assertSame(5, has_action('edited_word-category', 'll_tools_save_word_category_shared_settings'));
        $this->assertFalse(has_action('edited_word-category', 'll_save_quiz_prompt_option_fields'));
        $this->assertFalse(has_action('edited_word-category', 'll_tools_save_category_game_availability_field'));
        $this->assertFalse(has_action('edited_word-category', 'll_tools_save_category_lineup_field'));
        $this->assertFalse(has_action('edited_word-category', 'll_save_desired_recording_types_field'));
        $this->assertSame(15, has_action('edited_word-category', 'll_tools_handle_category_sync'));
        $this->assertSame(15, has_action('edited_word-category', 'll_tools_handle_vocab_lesson_category_sync'));

        $expectedLockName = 'll_vocab_cat_settings_' . substr(hash(
            'sha256',
            (defined('DB_NAME') ? (string) DB_NAME : '')
                . '|' . (string) $wpdb->termmeta
                . '|' . get_current_blog_id()
                . '|' . $categoryId
        ), 0, 32);
        $this->assertSame($expectedLockName, ll_tools_vocab_lesson_category_settings_lock_name($categoryId));
    }

    public function test_taxonomy_render_source_failure_cannot_submit_fallback_settings(): void
    {
        global $wpdb;

        $adminId = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($adminId);
        $fixture = $this->createManagedLessonFixture($adminId, false);
        $categoryId = (int) $fixture['category_id'];
        $injected = false;
        $metaFailure = static function ($check, $objectId, $metaKey) use (&$injected, $categoryId, $wpdb) {
            if (
                !$injected
                && (int) $objectId === $categoryId
                && $metaKey === LL_TOOLS_CATEGORY_ENABLED_GAMES_META_KEY
            ) {
                $injected = true;
                $wpdb->last_error = 'll_tools_test_taxonomy_render_failure';
                return [[]];
            }
            return $check;
        };
        add_filter('get_term_metadata', $metaFailure, 10, 3);
        try {
            ob_start();
            ll_tools_edit_category_settings_revision_field(get_term($categoryId, 'word-category'));
            $html = (string) ob_get_clean();
        } finally {
            remove_filter('get_term_metadata', $metaFailure, 10);
            $wpdb->last_error = '';
        }

        $this->assertTrue($injected);
        $this->assertStringContainsString('name="_ll_vocab_lesson_category_settings_render_complete" value="0"', $html);
        $this->assertStringContainsString('Category settings are temporarily unavailable.', $html);

        $lockFilterCalled = false;
        $lockFilter = static function ($waitSeconds) use (&$lockFilterCalled) {
            $lockFilterCalled = true;
            return $waitSeconds;
        };
        add_filter('ll_tools_vocab_lesson_category_settings_lock_wait_seconds', $lockFilter);
        try {
            $result = ll_tools_save_word_category_shared_settings_request(
                $categoryId,
                $this->buildTaxonomyCategorySettingsRequest($categoryId, 0, [
                    '_ll_vocab_lesson_category_settings_render_complete' => '0',
                ]),
                true
            );
        } finally {
            remove_filter('ll_tools_vocab_lesson_category_settings_lock_wait_seconds', $lockFilter);
        }

        $this->assertWPError($result);
        $this->assertSame('settings_source', ll_tools_get_vocab_lesson_category_settings_error_code($result));
        $this->assertFalse($lockFilterCalled);
        $this->assertSame(0, ll_tools_get_vocab_lesson_category_settings_revision($categoryId));
    }

    public function test_taxonomy_edit_bumps_revision_and_stale_lesson_save_preserves_admin_values(): void
    {
        $this->ensureRecordingType('Isolation', 'isolation');
        $adminId = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($adminId);
        $fixture = $this->createManagedLessonFixture($adminId);
        $categoryId = (int) $fixture['category_id'];

        $_POST = $this->buildTaxonomyCategorySettingsRequest($categoryId, 0);
        try {
            do_action('edited_word-category', $categoryId, 0);
        } finally {
            $_POST = [];
        }

        $state = ll_tools_get_vocab_lesson_category_settings_revision_state($categoryId);
        $this->assertSame(1, (int) $state['revision']);
        $this->assertSame('', (string) $state['client_id']);
        $this->assertSame(0, (int) $state['sequence']);
        $this->assertSame('text_translation', (string) get_term_meta($categoryId, 'll_quiz_prompt_type', true));
        $this->assertSame('hide', (string) get_term_meta($categoryId, 'll_lesson_grid_text_visibility_override', true));
        $this->assertSame(['line-up'], ll_tools_get_category_enabled_games($categoryId));

        $staleLessonResult = ll_tools_save_vocab_lesson_category_settings_from_request(
            $this->buildLessonCategorySettingsRequest(
                $fixture,
                0,
                '11111111-1111-4111-8111-111111111111',
                1,
                [
                    'll_vocab_lesson_quiz_prompt_type' => 'audio',
                    'll_vocab_lesson_grid_text_visibility' => 'show',
                    'll_vocab_lesson_category_enabled_games' => ['unscramble'],
                ]
            )
        );
        $this->assertWPError($staleLessonResult);
        $this->assertSame('revision_conflict', ll_tools_get_vocab_lesson_category_settings_error_code($staleLessonResult));
        $this->assertSame('text_translation', (string) get_term_meta($categoryId, 'll_quiz_prompt_type', true));
        $this->assertSame('hide', (string) get_term_meta($categoryId, 'll_lesson_grid_text_visibility_override', true));
        $this->assertSame(['line-up'], ll_tools_get_category_enabled_games($categoryId));
    }

    public function test_stale_taxonomy_edit_conflicts_after_lesson_save_and_preserves_lesson_values(): void
    {
        $this->ensureRecordingType('Isolation', 'isolation');
        $adminId = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($adminId);
        $fixture = $this->createManagedLessonFixture($adminId);
        $categoryId = (int) $fixture['category_id'];

        $lessonResult = ll_tools_save_vocab_lesson_category_settings_from_request(
            $this->buildLessonCategorySettingsRequest(
                $fixture,
                0,
                '22222222-2222-4222-8222-222222222222',
                1,
                [
                    'll_vocab_lesson_quiz_prompt_type' => 'image',
                    'll_vocab_lesson_quiz_option_type' => 'text_translation',
                    'll_vocab_lesson_grid_text_visibility' => 'show',
                    'll_vocab_lesson_category_enabled_games' => ['unscramble'],
                ]
            )
        );
        $this->assertIsArray($lessonResult);
        $this->assertSame(1, (int) ($lessonResult['revision'] ?? 0));

        $staleAdminResult = ll_tools_save_word_category_shared_settings_request(
            $categoryId,
            $this->buildTaxonomyCategorySettingsRequest($categoryId, 0),
            true
        );
        $this->assertWPError($staleAdminResult);
        $this->assertSame('revision_conflict', ll_tools_get_vocab_lesson_category_settings_error_code($staleAdminResult));
        $this->assertSame('image', (string) get_term_meta($categoryId, 'll_quiz_prompt_type', true));
        $this->assertSame('text_translation', (string) get_term_meta($categoryId, 'll_quiz_option_type', true));
        $this->assertSame('show', (string) get_term_meta($categoryId, 'll_lesson_grid_text_visibility_override', true));
        $this->assertSame(['unscramble'], ll_tools_get_category_enabled_games($categoryId));
    }

    public function test_lineup_ajax_mutation_bumps_revision_and_stale_lesson_snapshot_cannot_replace_it(): void
    {
        $this->ensureRecordingType('Isolation', 'isolation');
        $adminId = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($adminId);
        $fixture = $this->createManagedLessonFixture($adminId);
        $categoryId = (int) $fixture['category_id'];

        $lineupResult = ll_tools_apply_category_lineup_sequence_mutation(
            get_term($categoryId, 'word-category'),
            ['mutation' => 'add', 'word_id' => (string) $fixture['word_a_id']]
        );
        $this->assertIsArray($lineupResult);
        $this->assertSame(1, (int) ($lineupResult['revision'] ?? 0));
        $this->assertSame(
            [(int) $fixture['word_a_id']],
            array_map('intval', (array) get_term_meta($categoryId, LL_TOOLS_CATEGORY_LINEUP_WORD_ORDER_META_KEY, true))
        );

        $staleLessonResult = ll_tools_save_vocab_lesson_category_settings_from_request(
            $this->buildLessonCategorySettingsRequest(
                $fixture,
                0,
                '33333333-3333-4333-8333-333333333333',
                1,
                [
                    'll_vocab_lesson_category_lineup_submitted' => '1',
                    'll_vocab_lesson_category_lineup_replace' => '1',
                    'll_vocab_lesson_category_lineup_direction' => 'ltr',
                    'll_vocab_lesson_category_lineup_word_ids' => (string) $fixture['word_b_id'],
                ]
            )
        );
        $this->assertWPError($staleLessonResult);
        $this->assertSame('revision_conflict', ll_tools_get_vocab_lesson_category_settings_error_code($staleLessonResult));
        $this->assertSame(
            [(int) $fixture['word_a_id']],
            array_map('intval', (array) get_term_meta($categoryId, LL_TOOLS_CATEGORY_LINEUP_WORD_ORDER_META_KEY, true))
        );
    }

    public function test_existing_category_import_uses_revision_fence_and_ignores_imported_revision_state(): void
    {
        $managerId = $this->createManagerUser();
        wp_set_current_user($managerId);
        $fixture = $this->createManagedLessonFixture($managerId);
        $categoryId = (int) $fixture['category_id'];
        $existingOrder = [(int) $fixture['word_a_id']];
        update_term_meta($categoryId, LL_TOOLS_CATEGORY_LINEUP_WORD_ORDER_META_KEY, $existingOrder);

        $result = ll_tools_import_replace_term_meta_values($categoryId, [
            'll_quiz_prompt_type' => ['image'],
            'll_quiz_option_type' => ['text_title'],
            'use_word_titles_for_audio' => ['1'],
            'll_lesson_grid_text_visibility_override' => ['show'],
            LL_TOOLS_CATEGORY_ENABLED_GAMES_META_KEY => [['line-up', 'unscramble']],
            'll_desired_recording_types' => [['isolation']],
            LL_TOOLS_CATEGORY_LINEUP_DIRECTION_META_KEY => ['rtl'],
            ll_tools_vocab_lesson_category_settings_revision_meta_key() => [[
                'revision' => 999,
                'client_id' => 'ffffffff-ffff-4fff-8fff-ffffffffffff',
                'sequence' => 999,
            ]],
        ], 'word-category');

        $this->assertTrue($result);
        $this->assertSame('image', get_term_meta($categoryId, 'll_quiz_prompt_type', true));
        $this->assertSame('text_title', get_term_meta($categoryId, 'll_quiz_option_type', true));
        $this->assertSame('1', get_term_meta($categoryId, 'use_word_titles_for_audio', true));
        $this->assertSame('show', get_term_meta($categoryId, 'll_lesson_grid_text_visibility_override', true));
        $this->assertSame(['line-up', 'unscramble'], get_term_meta($categoryId, LL_TOOLS_CATEGORY_ENABLED_GAMES_META_KEY, true));
        $this->assertSame(['isolation'], get_term_meta($categoryId, 'll_desired_recording_types', true));
        $this->assertSame('rtl', get_term_meta($categoryId, LL_TOOLS_CATEGORY_LINEUP_DIRECTION_META_KEY, true));
        $this->assertSame($existingOrder, get_term_meta($categoryId, LL_TOOLS_CATEGORY_LINEUP_WORD_ORDER_META_KEY, true));
        $this->assertSame(1, ll_tools_get_vocab_lesson_category_settings_revision($categoryId));

        $staleSave = ll_tools_save_vocab_lesson_category_settings_from_request(
            $this->buildLessonCategorySettingsRequest(
                $fixture,
                0,
                '88888888-8888-4888-8888-888888888888',
                1,
                ['ll_vocab_lesson_quiz_prompt_type' => 'audio']
            )
        );
        $this->assertWPError($staleSave);
        $this->assertSame('revision_conflict', ll_tools_get_vocab_lesson_category_settings_error_code($staleSave));
        $this->assertSame('image', get_term_meta($categoryId, 'll_quiz_prompt_type', true));
    }

    public function test_existing_category_import_rejects_malformed_shared_setting_shapes_before_mutation(): void
    {
        $adminId = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($adminId);
        $category = wp_insert_term('Invalid Imported Settings ' . wp_generate_password(5, false), 'word-category');
        $this->assertIsArray($category);
        $categoryId = (int) $category['term_id'];
        update_term_meta($categoryId, 'll_quiz_prompt_type', 'audio');
        update_term_meta($categoryId, 'll_quiz_option_type', 'image');
        update_term_meta($categoryId, LL_TOOLS_CATEGORY_ENABLED_GAMES_META_KEY, ['unscramble']);

        $invalidMetaMaps = [
            ['ll_quiz_prompt_type' => [['image']]],
            ['ll_quiz_option_type' => [['text_title']]],
            ['use_word_titles_for_audio' => [['1']]],
            ['ll_lesson_grid_text_visibility_override' => [['show']]],
            [LL_TOOLS_CATEGORY_ENABLED_GAMES_META_KEY => [[['line-up']]]],
            [LL_TOOLS_CATEGORY_ENABLED_GAMES_META_KEY => [['not-a-game']]],
            ['ll_desired_recording_types' => [[['isolation']]]],
            [LL_TOOLS_CATEGORY_LINEUP_DIRECTION_META_KEY => [['rtl']]],
            ['ll_quiz_prompt_type' => 'image'],
        ];
        foreach ($invalidMetaMaps as $invalidMeta) {
            $result = ll_tools_import_replace_term_meta_values($categoryId, $invalidMeta, 'word-category');

            $this->assertWPError($result);
            $this->assertSame('ll_tools_import_category_settings_invalid', $result->get_error_code());
            $this->assertSame('audio', get_term_meta($categoryId, 'll_quiz_prompt_type', true));
            $this->assertSame('image', get_term_meta($categoryId, 'll_quiz_option_type', true));
            $this->assertSame(['unscramble'], get_term_meta($categoryId, LL_TOOLS_CATEGORY_ENABLED_GAMES_META_KEY, true));
            $this->assertSame(0, ll_tools_get_vocab_lesson_category_settings_revision($categoryId));
        }
    }

    public function test_category_details_preflights_all_meta_before_existing_category_mutation(): void
    {
        $suffix = strtolower(wp_generate_password(8, false));
        $originalSlug = 'category-preflight-original-' . $suffix;
        $importedSlug = 'category-preflight-imported-' . $suffix;
        $category = wp_insert_term(
            'Category Preflight Original ' . $suffix,
            'word-category',
            [
                'slug' => $originalSlug,
                'description' => 'Original category description',
            ]
        );
        $this->assertIsArray($category);
        $categoryId = (int) $category['term_id'];
        update_term_meta($categoryId, 'term_translation', 'Original translation');
        update_term_meta($categoryId, 'll_quiz_prompt_type', 'audio');

        $result = ll_tools_import_apply_category_details_chunk(
            [[
                'slug' => $importedSlug,
                'name' => 'Category Preflight Imported ' . $suffix,
                'description' => 'Imported category description',
                'meta' => [
                    'term_translation' => ['Imported translation'],
                    'll_quiz_prompt_type' => [['image']],
                ],
            ]],
            [$importedSlug => $categoryId]
        );

        $this->assertWPError($result);
        $this->assertSame('ll_tools_import_category_settings_invalid', $result->get_error_code());
        $storedCategory = get_term($categoryId, 'word-category');
        $this->assertInstanceOf(WP_Term::class, $storedCategory);
        $this->assertSame('Category Preflight Original ' . $suffix, $storedCategory->name);
        $this->assertSame($originalSlug, $storedCategory->slug);
        $this->assertSame('Original category description', $storedCategory->description);
        $this->assertSame('Original translation', get_term_meta($categoryId, 'term_translation', true));
        $this->assertSame('audio', get_term_meta($categoryId, 'll_quiz_prompt_type', true));
        $this->assertSame(0, ll_tools_get_vocab_lesson_category_settings_revision($categoryId));
    }

    public function test_category_details_preflights_the_whole_chunk_before_any_mutation(): void
    {
        $suffix = strtolower(wp_generate_password(8, false));
        $firstSlug = 'category-chunk-first-' . $suffix;
        $secondSlug = 'category-chunk-second-' . $suffix;
        $first = wp_insert_term('Category Chunk First Original ' . $suffix, 'word-category', [
            'slug' => $firstSlug,
            'description' => 'First original description',
        ]);
        $second = wp_insert_term('Category Chunk Second Original ' . $suffix, 'word-category', [
            'slug' => $secondSlug,
            'description' => 'Second original description',
        ]);
        $this->assertIsArray($first);
        $this->assertIsArray($second);
        $firstId = (int) $first['term_id'];
        $secondId = (int) $second['term_id'];
        update_term_meta($firstId, 'll_quiz_prompt_type', 'audio');
        update_term_meta($secondId, 'll_quiz_prompt_type', 'audio');

        $result = ll_tools_import_apply_category_details_chunk(
            [[
                'slug' => $firstSlug,
                'name' => 'Category Chunk First Imported ' . $suffix,
                'description' => 'First imported description',
                'meta' => ['ll_quiz_prompt_type' => ['image']],
            ], [
                'slug' => $secondSlug,
                'name' => 'Category Chunk Second Imported ' . $suffix,
                'description' => 'Second imported description',
                'meta' => ['ll_quiz_prompt_type' => [['image']]],
            ]],
            [$firstSlug => $firstId, $secondSlug => $secondId]
        );

        $this->assertWPError($result);
        $this->assertSame('ll_tools_import_category_settings_invalid', $result->get_error_code());
        $storedFirst = get_term($firstId, 'word-category');
        $storedSecond = get_term($secondId, 'word-category');
        $this->assertInstanceOf(WP_Term::class, $storedFirst);
        $this->assertInstanceOf(WP_Term::class, $storedSecond);
        $this->assertSame('Category Chunk First Original ' . $suffix, $storedFirst->name);
        $this->assertSame('First original description', $storedFirst->description);
        $this->assertSame('audio', get_term_meta($firstId, 'll_quiz_prompt_type', true));
        $this->assertSame('Category Chunk Second Original ' . $suffix, $storedSecond->name);
        $this->assertSame('Second original description', $storedSecond->description);
        $this->assertSame('audio', get_term_meta($secondId, 'll_quiz_prompt_type', true));
        $this->assertSame(0, ll_tools_get_vocab_lesson_category_settings_revision($firstId));
        $this->assertSame(0, ll_tools_get_vocab_lesson_category_settings_revision($secondId));
    }

    public function test_durable_import_preflights_category_metadata_beyond_the_first_write_chunk(): void
    {
        $suffix = strtolower(wp_generate_password(8, false, false));
        $firstSlug = 'durable-category-preflight-first-' . $suffix;
        $first = wp_insert_term('Durable Category First Original ' . $suffix, 'word-category', [
            'slug' => $firstSlug,
            'description' => 'Durable original description',
        ]);
        $this->assertIsArray($first);
        $firstId = (int) $first['term_id'];
        update_term_meta($firstId, 'll_quiz_prompt_type', 'audio');

        $categories = [[
            'slug' => $firstSlug,
            'name' => 'Durable Category First Imported ' . $suffix,
            'description' => 'Durable imported description',
            'meta' => ['ll_quiz_prompt_type' => ['image']],
        ]];
        for ($index = 2; $index <= 50; $index++) {
            $categories[] = [
                'slug' => 'durable-category-preflight-' . $index . '-' . $suffix,
                'name' => 'Durable Category ' . $index . ' ' . $suffix,
                'description' => '',
                'meta' => ['ll_quiz_prompt_type' => ['audio']],
            ];
        }
        $categories[] = [
            'slug' => 'durable-category-preflight-51-' . $suffix,
            'name' => 'Durable Category 51 ' . $suffix,
            'description' => '',
            'meta' => ['ll_quiz_prompt_type' => [['image']]],
        ];

        $tempRoot = trailingslashit(sys_get_temp_dir()) . 'll-tools-category-preflight-' . $suffix;
        $extractDir = trailingslashit($tempRoot) . 'extract';
        $jobDir = trailingslashit($tempRoot) . 'job';
        $this->assertTrue(wp_mkdir_p($extractDir));
        $payload = [
            'version' => 2,
            'bundle_type' => 'category_full',
            'categories' => $categories,
            'word_images' => [],
            'words' => [],
            'wordsets' => [],
        ];
        $encodedPayload = wp_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->assertIsString($encodedPayload);
        $this->assertNotFalse(file_put_contents(trailingslashit($extractDir) . 'data.json', $encodedPayload));

        try {
            $result = ll_tools_import_job_prepare_payload([
                'id' => 'category-preflight-' . $suffix,
                'extract_dir' => $extractDir,
                'job_dir' => $jobDir,
                'result' => ll_tools_import_job_default_result(),
            ]);
        } finally {
            ll_tools_import_job_delete_path($tempRoot);
        }

        $this->assertWPError($result);
        $this->assertSame('ll_tools_import_category_settings_invalid', $result->get_error_code());
        $storedFirst = get_term($firstId, 'word-category');
        $this->assertInstanceOf(WP_Term::class, $storedFirst);
        $this->assertSame('Durable Category First Original ' . $suffix, $storedFirst->name);
        $this->assertSame('Durable original description', $storedFirst->description);
        $this->assertSame('audio', get_term_meta($firstId, 'll_quiz_prompt_type', true));
        $this->assertFalse(get_term_by('slug', 'durable-category-preflight-2-' . $suffix, 'word-category'));
    }

    public function test_import_lineup_remap_only_mutates_categories_with_payload_order(): void
    {
        $adminId = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($adminId);
        $firstCategory = wp_insert_term('Imported Line-Up One ' . wp_generate_password(5, false), 'word-category');
        $secondCategory = wp_insert_term('Imported Line-Up Two ' . wp_generate_password(5, false), 'word-category');
        $this->assertIsArray($firstCategory);
        $this->assertIsArray($secondCategory);
        $firstCategoryId = (int) $firstCategory['term_id'];
        $secondCategoryId = (int) $secondCategory['term_id'];
        $firstWordId = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Imported Line-Up Word',
        ]);
        $secondWordId = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Local Line-Up Word',
        ]);
        wp_set_post_terms($firstWordId, [$firstCategoryId], 'word-category', false);
        wp_set_post_terms($secondWordId, [$secondCategoryId], 'word-category', false);
        update_term_meta($secondCategoryId, LL_TOOLS_CATEGORY_LINEUP_WORD_ORDER_META_KEY, [$secondWordId]);

        $result = ll_tools_import_remap_lineup_category_word_order(
            ['imported-one' => $firstCategoryId, 'imported-two' => $secondCategoryId],
            [321 => $firstWordId],
            [[
                'slug' => 'imported-one',
                'meta' => [LL_TOOLS_CATEGORY_LINEUP_WORD_ORDER_META_KEY => [[321]]],
            ], [
                'slug' => 'imported-two',
                'meta' => [],
            ]]
        );

        $this->assertTrue($result);
        $this->assertSame([$firstWordId], get_term_meta($firstCategoryId, LL_TOOLS_CATEGORY_LINEUP_WORD_ORDER_META_KEY, true));
        $this->assertSame([$secondWordId], get_term_meta($secondCategoryId, LL_TOOLS_CATEGORY_LINEUP_WORD_ORDER_META_KEY, true));
        $this->assertSame(1, ll_tools_get_vocab_lesson_category_settings_revision($firstCategoryId));
        $this->assertSame(0, ll_tools_get_vocab_lesson_category_settings_revision($secondCategoryId));
    }

    public function test_import_lineup_remap_rejects_nested_associative_and_noncanonical_ids(): void
    {
        $adminId = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($adminId);
        $category = wp_insert_term('Invalid Imported Line-Up ' . wp_generate_password(5, false), 'word-category');
        $this->assertIsArray($category);
        $categoryId = (int) $category['term_id'];
        $wordId = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Protected Local Line-Up Word',
        ]);
        wp_set_post_terms($wordId, [$categoryId], 'word-category', false);
        update_term_meta($categoryId, LL_TOOLS_CATEGORY_LINEUP_WORD_ORDER_META_KEY, [$wordId]);

        $invalidOrders = [
            [[321]],
            ['origin' => 321],
            ['0321'],
            [str_repeat('9', 20)],
            [321.0],
            [true],
            321.0,
            true,
        ];
        foreach ($invalidOrders as $invalidOrder) {
            $result = ll_tools_import_remap_lineup_category_word_order(
                ['invalid-imported-line-up' => $categoryId],
                [1 => $wordId, 321 => $wordId],
                [[
                    'slug' => 'invalid-imported-line-up',
                    'meta' => [LL_TOOLS_CATEGORY_LINEUP_WORD_ORDER_META_KEY => [$invalidOrder]],
                ]]
            );

            $this->assertWPError($result);
            $this->assertSame('ll_tools_import_lineup_settings_invalid', $result->get_error_code());
            $this->assertSame([$wordId], get_term_meta($categoryId, LL_TOOLS_CATEGORY_LINEUP_WORD_ORDER_META_KEY, true));
            $this->assertSame(0, ll_tools_get_vocab_lesson_category_settings_revision($categoryId));
        }
    }

    public function test_import_lineup_remap_preflights_every_category_before_mutation(): void
    {
        $firstCategory = wp_insert_term('Line-Up Preflight One ' . wp_generate_password(5, false), 'word-category');
        $secondCategory = wp_insert_term('Line-Up Preflight Two ' . wp_generate_password(5, false), 'word-category');
        $this->assertIsArray($firstCategory);
        $this->assertIsArray($secondCategory);
        $firstCategoryId = (int) $firstCategory['term_id'];
        $secondCategoryId = (int) $secondCategory['term_id'];
        $firstOriginalWordId = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'First preserved Line-Up word',
        ]);
        $firstImportedWordId = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'First candidate Line-Up word',
        ]);
        $secondOriginalWordId = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Second preserved Line-Up word',
        ]);
        wp_set_post_terms($firstOriginalWordId, [$firstCategoryId], 'word-category', false);
        wp_set_post_terms($firstImportedWordId, [$firstCategoryId], 'word-category', false);
        wp_set_post_terms($secondOriginalWordId, [$secondCategoryId], 'word-category', false);
        update_term_meta($firstCategoryId, LL_TOOLS_CATEGORY_LINEUP_WORD_ORDER_META_KEY, [$firstOriginalWordId]);
        update_term_meta($secondCategoryId, LL_TOOLS_CATEGORY_LINEUP_WORD_ORDER_META_KEY, [$secondOriginalWordId]);

        $result = ll_tools_import_remap_lineup_category_word_order(
            [
                'line-up-preflight-one' => $firstCategoryId,
                'line-up-preflight-two' => $secondCategoryId,
            ],
            [321 => $firstImportedWordId],
            [[
                'slug' => 'line-up-preflight-one',
                'meta' => [LL_TOOLS_CATEGORY_LINEUP_WORD_ORDER_META_KEY => [[321]]],
            ], [
                'slug' => 'line-up-preflight-two',
                'meta' => [LL_TOOLS_CATEGORY_LINEUP_WORD_ORDER_META_KEY => [[[654]]]],
            ]]
        );

        $this->assertWPError($result);
        $this->assertSame('ll_tools_import_lineup_settings_invalid', $result->get_error_code());
        $this->assertSame(
            [$firstOriginalWordId],
            get_term_meta($firstCategoryId, LL_TOOLS_CATEGORY_LINEUP_WORD_ORDER_META_KEY, true)
        );
        $this->assertSame(
            [$secondOriginalWordId],
            get_term_meta($secondCategoryId, LL_TOOLS_CATEGORY_LINEUP_WORD_ORDER_META_KEY, true)
        );
        $this->assertSame(0, ll_tools_get_vocab_lesson_category_settings_revision($firstCategoryId));
        $this->assertSame(0, ll_tools_get_vocab_lesson_category_settings_revision($secondCategoryId));
    }

    public function test_import_lineup_remap_rejects_an_unmapped_source_before_any_mutation(): void
    {
        $firstCategory = wp_insert_term('Line-Up Mapping One ' . wp_generate_password(5, false), 'word-category');
        $secondCategory = wp_insert_term('Line-Up Mapping Two ' . wp_generate_password(5, false), 'word-category');
        $this->assertIsArray($firstCategory);
        $this->assertIsArray($secondCategory);
        $firstCategoryId = (int) $firstCategory['term_id'];
        $secondCategoryId = (int) $secondCategory['term_id'];
        $firstOriginalWordId = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'First preserved mapping word',
        ]);
        $firstImportedWordId = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'First imported mapping word',
        ]);
        $secondOriginalWordId = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Second preserved mapping word',
        ]);
        wp_set_post_terms($firstOriginalWordId, [$firstCategoryId], 'word-category', false);
        wp_set_post_terms($firstImportedWordId, [$firstCategoryId], 'word-category', false);
        wp_set_post_terms($secondOriginalWordId, [$secondCategoryId], 'word-category', false);
        update_term_meta($firstCategoryId, LL_TOOLS_CATEGORY_LINEUP_WORD_ORDER_META_KEY, [$firstOriginalWordId]);
        update_term_meta($secondCategoryId, LL_TOOLS_CATEGORY_LINEUP_WORD_ORDER_META_KEY, [$secondOriginalWordId]);

        $result = ll_tools_import_remap_lineup_category_word_order(
            [
                'line-up-mapping-one' => $firstCategoryId,
                'line-up-mapping-two' => $secondCategoryId,
            ],
            [321 => $firstImportedWordId],
            [[
                'slug' => 'line-up-mapping-one',
                'meta' => [LL_TOOLS_CATEGORY_LINEUP_WORD_ORDER_META_KEY => [[321]]],
            ], [
                'slug' => 'line-up-mapping-two',
                'meta' => [LL_TOOLS_CATEGORY_LINEUP_WORD_ORDER_META_KEY => [[654]]],
            ]]
        );

        $this->assertWPError($result);
        $this->assertSame('ll_tools_import_lineup_settings_invalid', $result->get_error_code());
        $this->assertSame(
            [$firstOriginalWordId],
            get_term_meta($firstCategoryId, LL_TOOLS_CATEGORY_LINEUP_WORD_ORDER_META_KEY, true)
        );
        $this->assertSame(
            [$secondOriginalWordId],
            get_term_meta($secondCategoryId, LL_TOOLS_CATEGORY_LINEUP_WORD_ORDER_META_KEY, true)
        );
        $this->assertSame(0, ll_tools_get_vocab_lesson_category_settings_revision($firstCategoryId));
        $this->assertSame(0, ll_tools_get_vocab_lesson_category_settings_revision($secondCategoryId));
    }

    public function test_duplicate_new_category_mode_never_reuses_a_same_name_owned_category(): void
    {
        $wordset = wp_insert_term('Duplicate Strict Wordset ' . wp_generate_password(5, false), 'wordset');
        $this->assertIsArray($wordset);
        $wordsetId = (int) $wordset['term_id'];
        $name = 'Duplicate Strict Category ' . wp_generate_password(5, false);
        $existing = wp_insert_term($name, 'word-category', [
            'slug' => 'duplicate-strict-existing-' . strtolower(wp_generate_password(5, false)),
            'description' => 'Preserve this category',
        ]);
        $this->assertIsArray($existing);
        $existingId = (int) $existing['term_id'];
        ll_tools_set_category_wordset_owner($existingId, $wordsetId, $existingId);
        update_term_meta($existingId, 'll_quiz_prompt_type', 'audio');

        $requestedSlug = 'duplicate-strict-requested-' . strtolower(wp_generate_password(5, false));
        $result = ll_tools_duplicate_category_words_create_new_target_category(
            $name,
            $wordsetId,
            $requestedSlug
        );

        $this->assertWPError($result);
        $this->assertSame('ll_tools_duplicate_category_exists', $result->get_error_code());
        $storedExisting = get_term($existingId, 'word-category');
        $this->assertInstanceOf(WP_Term::class, $storedExisting);
        $this->assertSame('Preserve this category', $storedExisting->description);
        $this->assertSame('audio', get_term_meta($existingId, 'll_quiz_prompt_type', true));
        $desiredSlug = ll_tools_build_isolated_category_slug($requestedSlug, $wordsetId);
        $this->assertFalse(get_term_by('slug', $desiredSlug, 'word-category'));
    }

    public function test_strict_wordset_category_creator_rejects_core_duplicate_winner_without_mutating_it(): void
    {
        global $wpdb;

        $wordset = wp_insert_term('Strict Creator Wordset ' . wp_generate_password(5, false), 'wordset');
        $otherWordset = wp_insert_term('Strict Creator Other ' . wp_generate_password(5, false), 'wordset');
        $sentinel = wp_insert_term('Strict Creator Sentinel ' . wp_generate_password(5, false), 'word-category');
        $this->assertIsArray($wordset);
        $this->assertIsArray($otherWordset);
        $this->assertIsArray($sentinel);
        $wordsetId = (int) $wordset['term_id'];
        $otherWordsetId = (int) $otherWordset['term_id'];
        $sentinelId = (int) $sentinel['term_id'];
        ll_tools_set_category_wordset_owner($sentinelId, $otherWordsetId, $sentinelId);
        update_term_meta($sentinelId, 'll_quiz_prompt_type', 'audio');
        $sentinelTerm = get_term($sentinelId, 'word-category');
        $this->assertInstanceOf(WP_Term::class, $sentinelTerm);

        $rawLoserId = 0;
        $forceDuplicateWinner = static function ($duplicateTerm, $term, $taxonomy, $args = [], $termTaxonomyId = 0) use ($sentinelTerm, &$rawLoserId) {
            global $wpdb;
            if ((string) $taxonomy !== 'word-category' || !is_array($args) || empty($args['_ll_tools_new_category_token'])) {
                return $duplicateTerm;
            }
            $rawLoserId = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT term_id FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id = %d LIMIT 1",
                (int) $termTaxonomyId
            ));
            return $sentinelTerm;
        };

        add_filter('wp_insert_term_duplicate_term_check', $forceDuplicateWinner, 10, 5);
        try {
            $result = ll_tools_create_new_wordset_category(
                'Strict Creator Candidate ' . wp_generate_password(5, false),
                $wordsetId,
                ['slug' => 'strict-creator-candidate-' . strtolower(wp_generate_password(5, false))]
            );
        } finally {
            remove_filter('wp_insert_term_duplicate_term_check', $forceDuplicateWinner, 10);
        }

        $this->assertWPError($result);
        $this->assertSame('ll_tools_new_wordset_category_exists', $result->get_error_code());
        $this->assertGreaterThan(0, $rawLoserId);
        $this->assertNotSame($sentinelId, $rawLoserId);
        $this->assertNotInstanceOf(WP_Term::class, get_term($rawLoserId, 'word-category'));
        $this->assertSame(0, (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->termmeta} WHERE term_id = %d",
            $rawLoserId
        )));
        $this->assertSame($otherWordsetId, ll_tools_get_category_wordset_owner_id($sentinelId));
        $this->assertSame('audio', get_term_meta($sentinelId, 'll_quiz_prompt_type', true));
    }

    public function test_strict_wordset_category_creator_rolls_back_after_confidence_mapping_lookup_failure(): void
    {
        global $wpdb;

        $wordset = wp_insert_term('Confidence Lookup Wordset ' . wp_generate_password(5, false), 'wordset');
        $sentinel = wp_insert_term('Confidence Lookup Sentinel ' . wp_generate_password(5, false), 'word-category');
        $this->assertIsArray($wordset);
        $this->assertIsArray($sentinel);
        $sentinelId = (int) $sentinel['term_id'];
        update_term_meta($sentinelId, 'term_translation', 'Keep confidence winner');
        $sentinelTerm = get_term($sentinelId, 'word-category');
        $this->assertInstanceOf(WP_Term::class, $sentinelTerm);

        $captureLookupWasInvalidated = false;
        $rawTermId = 0;
        $rawTermTaxonomyId = 0;
        $forceDuplicateWinner = static function ($duplicateTerm, $term, $taxonomy, $args = []) use ($sentinelTerm) {
            if ((string) $taxonomy === 'word-category' && is_array($args) && !empty($args['_ll_tools_new_category_token'])) {
                return $sentinelTerm;
            }
            return $duplicateTerm;
        };
        $invalidateCaptureLookup = static function ($query) use ($wpdb, &$captureLookupWasInvalidated) {
            if (
                !$captureLookupWasInvalidated
                && stripos(ltrim((string) $query), 'SELECT term_id, taxonomy FROM') === 0
                && stripos((string) $query, (string) $wpdb->term_taxonomy) !== false
                && stripos((string) $query, 'WHERE term_taxonomy_id =') !== false
            ) {
                $captureLookupWasInvalidated = true;
                return "SELECT NULL AS term_id, 'word-category' AS taxonomy";
            }
            return $query;
        };
        $captureCreatedMapping = static function ($termId, $termTaxonomyId, $taxonomy, $args = []) use (&$rawTermId, &$rawTermTaxonomyId): void {
            if ((string) $taxonomy === 'word-category' && is_array($args) && !empty($args['_ll_tools_new_category_token'])) {
                $rawTermId = (int) $termId;
                $rawTermTaxonomyId = (int) $termTaxonomyId;
            }
        };

        add_filter('wp_insert_term_duplicate_term_check', $forceDuplicateWinner, 10, 4);
        add_filter('query', $invalidateCaptureLookup);
        add_action('create_term', $captureCreatedMapping, 10, 4);
        try {
            $result = ll_tools_create_new_wordset_category(
                'Confidence Lookup Candidate ' . wp_generate_password(5, false),
                (int) $wordset['term_id'],
                ['slug' => 'confidence-lookup-candidate-' . strtolower(wp_generate_password(5, false))]
            );
        } finally {
            remove_filter('wp_insert_term_duplicate_term_check', $forceDuplicateWinner, 10);
            remove_filter('query', $invalidateCaptureLookup);
            remove_action('create_term', $captureCreatedMapping, 10);
        }

        try {
            $this->assertTrue($captureLookupWasInvalidated);
            $this->assertGreaterThan(0, $rawTermId);
            $this->assertGreaterThan(0, $rawTermTaxonomyId);
            $this->assertWPError($result);
            $this->assertSame('ll_tools_new_wordset_category_mapping_failed', $result->get_error_code());
            $this->assertTrue((bool) ($result->get_error_data()['rollback_complete'] ?? false));
            $this->assertSame(0, (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->terms} WHERE term_id = %d",
                $rawTermId
            )));
            $this->assertSame(0, (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id = %d",
                $rawTermTaxonomyId
            )));
            $this->assertSame('Keep confidence winner', get_term_meta($sentinelId, 'term_translation', true));
        } finally {
            if ($rawTermTaxonomyId > 0) {
                $wpdb->delete($wpdb->term_relationships, ['term_taxonomy_id' => $rawTermTaxonomyId], ['%d']);
                $wpdb->delete($wpdb->term_taxonomy, ['term_taxonomy_id' => $rawTermTaxonomyId], ['%d']);
            }
            if ($rawTermId > 0) {
                $wpdb->delete($wpdb->termmeta, ['term_id' => $rawTermId], ['%d']);
                $wpdb->delete($wpdb->terms, ['term_id' => $rawTermId], ['%d']);
            }
        }
    }

    public function test_strict_wordset_category_creator_rolls_back_duplicate_loser_when_query_filter_blocks_core_cleanup(): void
    {
        global $wpdb;

        $wordset = wp_insert_term('Duplicate Cleanup Wordset ' . wp_generate_password(5, false), 'wordset');
        $sentinel = wp_insert_term('Duplicate Cleanup Sentinel ' . wp_generate_password(5, false), 'word-category');
        $this->assertIsArray($wordset);
        $this->assertIsArray($sentinel);
        $sentinelId = (int) $sentinel['term_id'];
        update_term_meta($sentinelId, 'term_translation', 'Keep duplicate winner');
        $sentinelTerm = get_term($sentinelId, 'word-category');
        $this->assertInstanceOf(WP_Term::class, $sentinelTerm);

        $rawLoserId = 0;
        $termsDeleteWasBlocked = false;
        $transactionRollbackWasBlocked = false;
        $forceDuplicateWinner = static function ($duplicateTerm, $term, $taxonomy, $args = [], $termTaxonomyId = 0) use ($sentinelTerm, &$rawLoserId) {
            global $wpdb;
            if ((string) $taxonomy !== 'word-category' || !is_array($args) || empty($args['_ll_tools_new_category_token'])) {
                return $duplicateTerm;
            }
            $rawLoserId = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT term_id FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id = %d LIMIT 1",
                (int) $termTaxonomyId
            ));
            return $sentinelTerm;
        };
        $blockTermsDelete = static function ($query) use (
            &$rawLoserId,
            &$termsDeleteWasBlocked,
            &$transactionRollbackWasBlocked,
            $wpdb
        ) {
            if ($rawLoserId > 0 && stripos(ltrim((string) $query), 'ROLLBACK') === 0) {
                $transactionRollbackWasBlocked = true;
                return 'SELECT 1';
            }
            if (
                $rawLoserId > 0
                && stripos(ltrim((string) $query), 'DELETE FROM') === 0
                && stripos((string) $query, (string) $wpdb->terms) !== false
                && preg_match('/`?term_id`?\s*=\s*[\'\"]?' . preg_quote((string) $rawLoserId, '/') . '\b/i', (string) $query)
            ) {
                $termsDeleteWasBlocked = true;
                return 'SELECT 1';
            }

            return $query;
        };

        add_filter('wp_insert_term_duplicate_term_check', $forceDuplicateWinner, 10, 5);
        add_filter('query', $blockTermsDelete);
        try {
            $result = ll_tools_create_new_wordset_category(
                'Duplicate Cleanup Candidate ' . wp_generate_password(5, false),
                (int) $wordset['term_id'],
                ['slug' => 'duplicate-cleanup-candidate-' . strtolower(wp_generate_password(5, false))]
            );
        } finally {
            remove_filter('wp_insert_term_duplicate_term_check', $forceDuplicateWinner, 10);
            remove_filter('query', $blockTermsDelete);
        }

        try {
            $this->assertWPError($result);
            $this->assertSame('ll_tools_new_wordset_category_exists', $result->get_error_code());
            $this->assertTrue((bool) ($result->get_error_data()['rollback_complete'] ?? false));
            $this->assertSame($rawLoserId, (int) ($result->get_error_data()['term_id'] ?? 0));
            $this->assertTrue($termsDeleteWasBlocked);
            $this->assertFalse($transactionRollbackWasBlocked, 'Rollback must bypass the filterable WPDB query path.');
            $this->assertGreaterThan(0, $rawLoserId);
            $this->assertSame(0, (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->terms} WHERE term_id = %d",
                $rawLoserId
            )));
            $this->assertSame(0, (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE term_id = %d",
                $rawLoserId
            )));
            $this->assertSame('Keep duplicate winner', get_term_meta($sentinelId, 'term_translation', true));
            $this->assertSame(0, (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->termmeta} WHERE term_id = %d",
                $rawLoserId
            )));
            $this->assertSame(0, (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE term_id = %d",
                $rawLoserId
            )));
        } finally {
            if ($rawLoserId > 0) {
                $wpdb->delete($wpdb->termmeta, ['term_id' => $rawLoserId], ['%d']);
                $wpdb->delete($wpdb->term_taxonomy, ['term_id' => $rawLoserId], ['%d']);
                $wpdb->delete($wpdb->terms, ['term_id' => $rawLoserId], ['%d']);
            }
        }
    }

    public function test_failed_category_rollback_keeps_mapping_recoverable_when_relationship_delete_is_blocked(): void
    {
        global $wpdb;

        $category = wp_insert_term('Relationship Rollback ' . wp_generate_password(5, false), 'word-category');
        $this->assertIsArray($category);
        $categoryId = (int) $category['term_id'];
        $termTaxonomyId = (int) $category['term_taxonomy_id'];
        $wordId = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
        ]);
        $assigned = wp_set_object_terms($wordId, [$categoryId], 'word-category');
        $this->assertIsArray($assigned);
        $this->assertContains($termTaxonomyId, array_map('intval', $assigned));

        $relationshipDeleteWasBlocked = false;
        $blockRelationshipDelete = static function ($query) use ($wpdb, $termTaxonomyId, &$relationshipDeleteWasBlocked) {
            if (
                stripos(ltrim((string) $query), 'DELETE FROM') === 0
                && stripos((string) $query, (string) $wpdb->term_relationships) !== false
                && preg_match('/term_taxonomy_id\s+IN\s*\([^)]*\b' . preg_quote((string) $termTaxonomyId, '/') . '\b[^)]*\)/i', (string) $query)
            ) {
                $relationshipDeleteWasBlocked = true;
                return 'SELECT 1';
            }
            return $query;
        };

        add_filter('query', $blockRelationshipDelete);
        try {
            $firstAttempt = ll_tools_rollback_failed_new_word_category($categoryId, $termTaxonomyId);
        } finally {
            remove_filter('query', $blockRelationshipDelete);
        }

        try {
            $this->assertTrue($relationshipDeleteWasBlocked);
            $this->assertFalse($firstAttempt);
            $this->assertSame(1, (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->terms} WHERE term_id = %d",
                $categoryId
            )));
            $this->assertSame(1, (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id = %d AND term_id = %d",
                $termTaxonomyId,
                $categoryId
            )));
            $this->assertSame(1, (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->term_relationships} WHERE term_taxonomy_id = %d AND object_id = %d",
                $termTaxonomyId,
                $wordId
            )));

            $undo = ll_tools_import_default_undo_payload();
            $undo['category_term_ids'] = [$categoryId];
            $undoResult = ll_tools_undo_import_entry(['undo' => $undo]);

            $this->assertTrue($undoResult['ok'], implode('; ', $undoResult['errors']));
            $this->assertSame(1, (int) $undoResult['stats']['categories_deleted']);
            $this->assertSame(0, (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->terms} WHERE term_id = %d",
                $categoryId
            )));
            $this->assertSame(0, (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id = %d",
                $termTaxonomyId
            )));
            $this->assertSame(0, (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->term_relationships} WHERE term_taxonomy_id = %d",
                $termTaxonomyId
            )));
        } finally {
            $wpdb->delete($wpdb->term_relationships, ['term_taxonomy_id' => $termTaxonomyId], ['%d']);
            $wpdb->delete($wpdb->termmeta, ['term_id' => $categoryId], ['%d']);
            $wpdb->delete($wpdb->term_taxonomy, ['term_taxonomy_id' => $termTaxonomyId], ['%d']);
            $wpdb->delete($wpdb->terms, ['term_id' => $categoryId], ['%d']);
        }
    }

    public function test_failed_term_rollback_refuses_a_replacement_mapping_with_a_different_taxonomy_id(): void
    {
        global $wpdb;

        $insert = wp_insert_term('Replacement Mapping Guard ' . wp_generate_password(5, false), 'word-category');
        $this->assertIsArray($insert);
        $termId = (int) $insert['term_id'];
        $originalTermTaxonomyId = (int) $insert['term_taxonomy_id'];
        $wpdb->delete(
            $wpdb->term_taxonomy,
            ['term_taxonomy_id' => $originalTermTaxonomyId],
            ['%d']
        );
        $this->assertSame(1, $wpdb->insert(
            $wpdb->term_taxonomy,
            [
                'term_id' => $termId,
                'taxonomy' => 'word-category',
                'description' => '',
                'parent' => 0,
                'count' => 0,
            ],
            ['%d', '%s', '%s', '%d', '%d']
        ));
        $replacementTermTaxonomyId = (int) $wpdb->insert_id;
        $this->assertGreaterThan(0, $replacementTermTaxonomyId);
        $this->assertNotSame($originalTermTaxonomyId, $replacementTermTaxonomyId);

        try {
            $this->assertFalse(ll_tools_rollback_failed_new_taxonomy_term(
                $termId,
                'word-category',
                $originalTermTaxonomyId
            ));
            $this->assertSame(1, (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->terms} WHERE term_id = %d",
                $termId
            )));
            $this->assertSame(1, (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id = %d AND term_id = %d AND taxonomy = %s",
                $replacementTermTaxonomyId,
                $termId,
                'word-category'
            )));
        } finally {
            $wpdb->delete($wpdb->term_relationships, ['term_taxonomy_id' => $replacementTermTaxonomyId], ['%d']);
            $wpdb->delete($wpdb->term_taxonomy, ['term_taxonomy_id' => $replacementTermTaxonomyId], ['%d']);
            $wpdb->delete($wpdb->termmeta, ['term_id' => $termId], ['%d']);
            $wpdb->delete($wpdb->terms, ['term_id' => $termId], ['%d']);
            clean_term_cache($termId, 'word-category');
        }
    }

    public function test_strict_wordset_category_creator_rolls_back_when_owner_metadata_cannot_be_verified(): void
    {
        global $wpdb;

        $wordset = wp_insert_term('Strict Owner Wordset ' . wp_generate_password(5, false), 'wordset');
        $this->assertIsArray($wordset);
        $wordsetId = (int) $wordset['term_id'];
        $rawCreatedId = 0;
        $captureCreated = static function ($termId, $termTaxonomyId, $taxonomy, $args = []) use (&$rawCreatedId): void {
            if ((string) $taxonomy === 'word-category' && is_array($args) && !empty($args['_ll_tools_new_category_token'])) {
                $rawCreatedId = (int) $termId;
            }
        };
        $rejectOwnerWrite = static function ($check, $objectId, $metaKey) {
            return (string) $metaKey === LL_TOOLS_CATEGORY_WORDSET_OWNER_META_KEY ? false : $check;
        };

        add_action('create_term', $captureCreated, PHP_INT_MIN + 1, 4);
        add_filter('update_term_metadata', $rejectOwnerWrite, 10, 3);
        try {
            $result = ll_tools_create_new_wordset_category(
                'Strict Owner Category ' . wp_generate_password(5, false),
                $wordsetId
            );
        } finally {
            remove_action('create_term', $captureCreated, PHP_INT_MIN + 1);
            remove_filter('update_term_metadata', $rejectOwnerWrite, 10);
        }

        $this->assertWPError($result);
        $this->assertSame('ll_tools_new_wordset_category_owner_failed', $result->get_error_code());
        $this->assertTrue((bool) ($result->get_error_data()['rollback_complete'] ?? false));
        $this->assertGreaterThan(0, $rawCreatedId);
        $this->assertNotInstanceOf(WP_Term::class, get_term($rawCreatedId, 'word-category'));
        $this->assertSame(0, (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->termmeta} WHERE term_id = %d",
            $rawCreatedId
        )));
    }

    public function test_strict_wordset_category_creator_rolls_back_when_owner_initialization_throws(): void
    {
        global $wpdb;

        $wordset = wp_insert_term('Strict Throw Wordset ' . wp_generate_password(5, false), 'wordset');
        $this->assertIsArray($wordset);
        $rawCreatedId = 0;
        $captureCreated = static function ($termId, $termTaxonomyId, $taxonomy, $args = []) use (&$rawCreatedId): void {
            if ((string) $taxonomy === 'word-category' && is_array($args) && !empty($args['_ll_tools_new_category_token'])) {
                $rawCreatedId = (int) $termId;
            }
        };
        $throwOwnerWrite = static function ($check, $objectId, $metaKey) {
            if ((string) $metaKey === LL_TOOLS_CATEGORY_WORDSET_OWNER_META_KEY) {
                throw new RuntimeException('Intentional owner initialization failure.');
            }
            return $check;
        };

        add_action('create_term', $captureCreated, PHP_INT_MIN + 1, 4);
        add_filter('update_term_metadata', $throwOwnerWrite, 10, 3);
        try {
            $result = ll_tools_create_new_wordset_category(
                'Strict Throw Category ' . wp_generate_password(5, false),
                (int) $wordset['term_id']
            );
        } finally {
            remove_action('create_term', $captureCreated, PHP_INT_MIN + 1);
            remove_filter('update_term_metadata', $throwOwnerWrite, 10);
        }

        $this->assertWPError($result);
        $this->assertSame('ll_tools_new_wordset_category_initialization_failed', $result->get_error_code());
        $this->assertTrue((bool) ($result->get_error_data()['rollback_complete'] ?? false));
        $this->assertGreaterThan(0, $rawCreatedId);
        $this->assertNotInstanceOf(WP_Term::class, get_term($rawCreatedId, 'word-category'));
        $this->assertSame(0, (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->termmeta} WHERE term_id = %d",
            $rawCreatedId
        )));
    }

    public function test_strict_wordset_category_creator_retains_cleanup_evidence_when_terms_row_delete_is_no_op(): void
    {
        global $wpdb;

        $wordset = wp_insert_term('Strict Orphan Wordset ' . wp_generate_password(5, false), 'wordset');
        $this->assertIsArray($wordset);
        $rawCreatedId = 0;
        $termsDeleteWasBlocked = false;
        $captureCreated = static function ($termId, $termTaxonomyId, $taxonomy, $args = []) use (&$rawCreatedId): void {
            if ((string) $taxonomy === 'word-category' && is_array($args) && !empty($args['_ll_tools_new_category_token'])) {
                $rawCreatedId = (int) $termId;
            }
        };
        $rejectOwnerWrite = static function ($check, $objectId, $metaKey) {
            return (string) $metaKey === LL_TOOLS_CATEGORY_WORDSET_OWNER_META_KEY ? false : $check;
        };
        $blockTermsDelete = static function ($query) use (&$rawCreatedId, &$termsDeleteWasBlocked, $wpdb) {
            if (
                $rawCreatedId > 0
                && stripos(ltrim((string) $query), 'DELETE FROM') === 0
                && stripos((string) $query, (string) $wpdb->terms) !== false
                && preg_match('/`?term_id`?\s*=\s*[\'\"]?' . preg_quote((string) $rawCreatedId, '/') . '\b/i', (string) $query)
            ) {
                $termsDeleteWasBlocked = true;
                return 'SELECT 1';
            }

            return $query;
        };

        add_action('create_term', $captureCreated, PHP_INT_MIN + 1, 4);
        add_filter('update_term_metadata', $rejectOwnerWrite, 10, 3);
        add_filter('query', $blockTermsDelete);
        try {
            $result = ll_tools_create_new_wordset_category(
                'Strict Orphan Category ' . wp_generate_password(5, false),
                (int) $wordset['term_id']
            );
        } finally {
            remove_action('create_term', $captureCreated, PHP_INT_MIN + 1);
            remove_filter('update_term_metadata', $rejectOwnerWrite, 10);
            remove_filter('query', $blockTermsDelete);
        }

        try {
            $this->assertWPError($result);
            $this->assertSame('ll_tools_wordset_category_cleanup_failed', $result->get_error_code());
            $this->assertFalse((bool) ($result->get_error_data()['rollback_complete'] ?? true));
            $this->assertTrue($termsDeleteWasBlocked);
            $this->assertGreaterThan(0, $rawCreatedId);
            $this->assertSame(1, (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->terms} WHERE term_id = %d",
                $rawCreatedId
            )));
            $this->assertSame(0, (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE term_id = %d",
                $rawCreatedId
            )));
            $this->assertSame(0, (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->termmeta} WHERE term_id = %d",
                $rawCreatedId
            )));
        } finally {
            if ($rawCreatedId > 0) {
                $wpdb->delete($wpdb->termmeta, ['term_id' => $rawCreatedId], ['%d']);
                $wpdb->delete($wpdb->term_taxonomy, ['term_id' => $rawCreatedId], ['%d']);
                $wpdb->delete($wpdb->terms, ['term_id' => $rawCreatedId], ['%d']);
            }
        }
    }

    public function test_strict_wordset_category_creator_fails_before_mutation_on_wordpress_older_than_6_1(): void
    {
        global $wp_version;

        $wordset = wp_insert_term('Legacy Hook Args Wordset ' . wp_generate_password(5, false), 'wordset');
        $this->assertIsArray($wordset);
        $categoryName = 'Legacy Hook Args Category ' . wp_generate_password(5, false);
        $originalVersion = $wp_version;
        try {
            $wp_version = '6.0.9';
            $result = ll_tools_create_new_wordset_category(
                $categoryName,
                (int) $wordset['term_id']
            );
        } finally {
            $wp_version = $originalVersion;
        }

        $this->assertWPError($result);
        $this->assertSame('ll_tools_new_wordset_category_wordpress_version', $result->get_error_code());
        $this->assertSame([], get_terms([
            'taxonomy' => 'word-category',
            'hide_empty' => false,
            'name' => $categoryName,
        ]));
    }

    public function test_duplicate_new_target_translation_failure_rolls_back_the_proven_created_category(): void
    {
        global $wpdb;

        $wordset = wp_insert_term('Duplicate Translation Wordset ' . wp_generate_password(5, false), 'wordset');
        $this->assertIsArray($wordset);
        $targetId = ll_tools_duplicate_category_words_create_new_target_category(
            'Duplicate Translation Target ' . wp_generate_password(5, false),
            (int) $wordset['term_id']
        );
        $this->assertIsInt($targetId);
        $rejectTranslationWrite = static function ($check, $objectId, $metaKey) use ($targetId) {
            return (int) $objectId === $targetId && (string) $metaKey === 'term_translation' ? false : $check;
        };

        add_filter('update_term_metadata', $rejectTranslationWrite, 10, 3);
        try {
            $writeResult = ll_tools_duplicate_category_words_write_new_target_translation($targetId, 'Çeviri');
        } finally {
            remove_filter('update_term_metadata', $rejectTranslationWrite, 10);
        }
        $this->assertWPError($writeResult);
        $failure = ll_tools_duplicate_category_words_rollback_new_target_after_error($targetId, $writeResult);

        $this->assertWPError($failure);
        $this->assertTrue((bool) ($failure->get_error_data()['rollback_complete'] ?? false));
        $this->assertNotInstanceOf(WP_Term::class, get_term($targetId, 'word-category'));
        $this->assertSame(0, (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->termmeta} WHERE term_id = %d",
            $targetId
        )));
    }

    public function test_duplicate_new_target_translation_throw_returns_wp_error_and_rolls_back(): void
    {
        global $wpdb;

        $wordset = wp_insert_term('Duplicate Translation Throw Wordset ' . wp_generate_password(5, false), 'wordset');
        $this->assertIsArray($wordset);
        $targetId = ll_tools_duplicate_category_words_create_new_target_category(
            'Duplicate Translation Throw Target ' . wp_generate_password(5, false),
            (int) $wordset['term_id']
        );
        $this->assertIsInt($targetId);
        $throwTranslationWrite = static function ($check, $objectId, $metaKey) use ($targetId) {
            if ((int) $objectId === $targetId && (string) $metaKey === 'term_translation') {
                throw new RuntimeException('Intentional translation write failure.');
            }
            return $check;
        };

        add_filter('update_term_metadata', $throwTranslationWrite, 10, 3);
        try {
            $writeResult = ll_tools_duplicate_category_words_write_new_target_translation($targetId, 'Çeviri');
        } finally {
            remove_filter('update_term_metadata', $throwTranslationWrite, 10);
        }

        $this->assertWPError($writeResult);
        $this->assertSame('ll_tools_duplicate_category_translation_write_failed', $writeResult->get_error_code());
        $this->assertSame(503, (int) ($writeResult->get_error_data()['status'] ?? 0));
        $failure = ll_tools_duplicate_category_words_rollback_new_target_after_error($targetId, $writeResult);

        $this->assertWPError($failure);
        $this->assertSame('ll_tools_duplicate_category_translation_write_failed', $failure->get_error_code());
        $this->assertTrue((bool) ($failure->get_error_data()['rollback_complete'] ?? false));
        $this->assertNotInstanceOf(WP_Term::class, get_term($targetId, 'word-category'));
        $this->assertSame(0, (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->termmeta} WHERE term_id = %d",
            $targetId
        )));
    }

    public function test_duplicate_source_translation_copy_failure_rolls_back_the_proven_created_category(): void
    {
        global $wpdb;

        $wordset = wp_insert_term('Duplicate Copy Wordset ' . wp_generate_password(5, false), 'wordset');
        $source = wp_insert_term('Duplicate Copy Source ' . wp_generate_password(5, false), 'word-category');
        $this->assertIsArray($wordset);
        $this->assertIsArray($source);
        $sourceId = (int) $source['term_id'];
        update_term_meta($sourceId, 'term_translation', 'Source translation');
        $targetId = ll_tools_duplicate_category_words_create_new_target_category(
            'Duplicate Copy Target ' . wp_generate_password(5, false),
            (int) $wordset['term_id']
        );
        $this->assertIsInt($targetId);
        $rejectTranslationWrite = static function ($check, $objectId, $metaKey) use ($targetId) {
            return (int) $objectId === $targetId && (string) $metaKey === 'term_translation' ? false : $check;
        };

        add_filter('update_term_metadata', $rejectTranslationWrite, 10, 3);
        try {
            $copyResult = ll_tools_duplicate_category_words_copy_source_category_settings($sourceId, $targetId, true);
        } finally {
            remove_filter('update_term_metadata', $rejectTranslationWrite, 10);
        }
        $this->assertWPError($copyResult);
        $failure = ll_tools_duplicate_category_words_rollback_new_target_after_error($targetId, $copyResult);

        $this->assertWPError($failure);
        $this->assertTrue((bool) ($failure->get_error_data()['rollback_complete'] ?? false));
        $this->assertNotInstanceOf(WP_Term::class, get_term($targetId, 'word-category'));
        $this->assertSame(0, (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->termmeta} WHERE term_id = %d",
            $targetId
        )));
    }

    public function test_duplicate_source_settings_throw_returns_wp_error_and_rolls_back(): void
    {
        global $wpdb;

        $wordset = wp_insert_term('Duplicate Settings Throw Wordset ' . wp_generate_password(5, false), 'wordset');
        $source = wp_insert_term('Duplicate Settings Throw Source ' . wp_generate_password(5, false), 'word-category');
        $this->assertIsArray($wordset);
        $this->assertIsArray($source);
        $sourceId = (int) $source['term_id'];
        update_term_meta($sourceId, 'll_quiz_prompt_type', 'image');
        $targetId = ll_tools_duplicate_category_words_create_new_target_category(
            'Duplicate Settings Throw Target ' . wp_generate_password(5, false),
            (int) $wordset['term_id']
        );
        $this->assertIsInt($targetId);
        $throwSettingsWrite = static function ($check, $objectId, $metaKey) use ($targetId) {
            if ((int) $objectId === $targetId && (string) $metaKey === 'll_quiz_prompt_type') {
                throw new RuntimeException('Intentional duplicate settings write failure.');
            }
            return $check;
        };

        add_filter('update_term_metadata', $throwSettingsWrite, 10, 3);
        try {
            $copyResult = ll_tools_duplicate_category_words_copy_source_category_settings($sourceId, $targetId, false);
        } finally {
            remove_filter('update_term_metadata', $throwSettingsWrite, 10);
        }

        $this->assertWPError($copyResult);
        $this->assertSame('ll_tools_duplicate_category_settings_failed', $copyResult->get_error_code());
        $this->assertSame(503, (int) ($copyResult->get_error_data()['status'] ?? 0));
        $failure = ll_tools_duplicate_category_words_rollback_new_target_after_error($targetId, $copyResult);

        $this->assertWPError($failure);
        $this->assertSame('ll_tools_duplicate_category_settings_failed', $failure->get_error_code());
        $this->assertTrue((bool) ($failure->get_error_data()['rollback_complete'] ?? false));
        $this->assertNotInstanceOf(WP_Term::class, get_term($targetId, 'word-category'));
        $this->assertSame(0, (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->termmeta} WHERE term_id = %d",
            $targetId
        )));
    }

    public function test_wordset_editor_new_target_is_create_only_and_copy_failure_rolls_it_back(): void
    {
        global $wpdb;

        $adminId = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($adminId);
        $wordset = wp_insert_term('Editor Strict Wordset ' . wp_generate_password(5, false), 'wordset');
        $this->assertIsArray($wordset);
        $wordsetId = (int) $wordset['term_id'];
        $name = 'Editor Strict Category ' . wp_generate_password(5, false);

        $created = ll_tools_wordset_editor_create_category_target($name, $wordsetId);
        $this->assertIsArray($created);
        $this->assertTrue((bool) ($created['created'] ?? false));
        $targetId = (int) ($created['category_id'] ?? 0);
        $this->assertGreaterThan(0, $targetId);

        $second = ll_tools_wordset_editor_create_category_target($name, $wordsetId);
        $this->assertWPError($second);
        $this->assertInstanceOf(WP_Term::class, get_term($targetId, 'word-category'));

        $copyResult = ll_tools_wordset_editor_copy_created_category_settings(0, $targetId);
        $this->assertWPError($copyResult);
        $this->assertTrue((bool) ($copyResult->get_error_data()['rollback_complete'] ?? false));
        $this->assertNotInstanceOf(WP_Term::class, get_term($targetId, 'word-category'));
        $this->assertSame(0, (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->termmeta} WHERE term_id = %d",
            $targetId
        )));
    }

    public function test_wordset_editor_settings_throw_returns_wp_error_and_rolls_back_created_target(): void
    {
        global $wpdb;

        $adminId = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($adminId);
        $wordset = wp_insert_term('Editor Settings Throw Wordset ' . wp_generate_password(5, false), 'wordset');
        $source = wp_insert_term('Editor Settings Throw Source ' . wp_generate_password(5, false), 'word-category');
        $this->assertIsArray($wordset);
        $this->assertIsArray($source);
        $sourceId = (int) $source['term_id'];
        update_term_meta($sourceId, 'll_quiz_prompt_type', 'image');

        $created = ll_tools_wordset_editor_create_category_target(
            'Editor Settings Throw Target ' . wp_generate_password(5, false),
            (int) $wordset['term_id']
        );
        $this->assertIsArray($created);
        $targetId = (int) ($created['category_id'] ?? 0);
        $this->assertGreaterThan(0, $targetId);
        $throwSettingsWrite = static function ($check, $objectId, $metaKey) use ($targetId) {
            if ((int) $objectId === $targetId && (string) $metaKey === 'll_quiz_prompt_type') {
                throw new RuntimeException('Intentional editor settings write failure.');
            }
            return $check;
        };

        add_filter('update_term_metadata', $throwSettingsWrite, 10, 3);
        try {
            $copyResult = ll_tools_wordset_editor_copy_created_category_settings($sourceId, $targetId);
        } finally {
            remove_filter('update_term_metadata', $throwSettingsWrite, 10);
        }

        $this->assertWPError($copyResult);
        $this->assertSame('ll_wordset_editor_category_settings_failed', $copyResult->get_error_code());
        $this->assertSame(503, (int) ($copyResult->get_error_data()['status'] ?? 0));
        $this->assertTrue((bool) ($copyResult->get_error_data()['rollback_complete'] ?? false));
        $this->assertNotInstanceOf(WP_Term::class, get_term($targetId, 'word-category'));
        $this->assertSame(0, (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->termmeta} WHERE term_id = %d",
            $targetId
        )));
    }

    public function test_reused_category_copy_helpers_serialize_overwrites_and_advance_revision(): void
    {
        $adminId = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($adminId);
        $source = wp_insert_term('Copy Settings Source ' . wp_generate_password(5, false), 'word-category');
        $duplicateTarget = wp_insert_term('Copy Settings Duplicate ' . wp_generate_password(5, false), 'word-category');
        $editorTarget = wp_insert_term('Copy Settings Editor ' . wp_generate_password(5, false), 'word-category');
        $this->assertIsArray($source);
        $this->assertIsArray($duplicateTarget);
        $this->assertIsArray($editorTarget);
        $sourceId = (int) $source['term_id'];
        $duplicateTargetId = (int) $duplicateTarget['term_id'];
        $editorTargetId = (int) $editorTarget['term_id'];
        update_term_meta($sourceId, 'll_quiz_prompt_type', 'image');
        update_term_meta($sourceId, 'll_quiz_option_type', 'text_title');
        update_term_meta($sourceId, LL_TOOLS_CATEGORY_ENABLED_GAMES_META_KEY, ['line-up']);
        update_term_meta($duplicateTargetId, 'll_quiz_prompt_type', 'audio');
        update_term_meta($editorTargetId, 'll_quiz_prompt_type', 'audio');

        $duplicateResult = ll_tools_duplicate_category_words_copy_source_category_settings(
            $sourceId,
            $duplicateTargetId,
            false
        );
        $editorResult = ll_tools_wordset_editor_copy_category_settings($sourceId, $editorTargetId);

        $this->assertTrue($duplicateResult);
        $this->assertTrue($editorResult);
        $this->assertSame('image', get_term_meta($duplicateTargetId, 'll_quiz_prompt_type', true));
        $this->assertSame('image', get_term_meta($editorTargetId, 'll_quiz_prompt_type', true));
        $this->assertSame(['line-up'], get_term_meta($editorTargetId, LL_TOOLS_CATEGORY_ENABLED_GAMES_META_KEY, true));
        $this->assertSame(1, ll_tools_get_vocab_lesson_category_settings_revision($duplicateTargetId));
        $this->assertSame(1, ll_tools_get_vocab_lesson_category_settings_revision($editorTargetId));
    }

    public function test_external_mutation_discards_prelock_term_meta_snapshot(): void
    {
        global $wpdb;

        $adminId = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($adminId);
        $fixture = $this->createManagedLessonFixture($adminId, false);
        $categoryId = (int) $fixture['category_id'];

        // Prime an empty object-cache snapshot, then emulate a writer that
        // committed directly while this request was waiting for the lock.
        $this->assertSame('', get_term_meta($categoryId, 'll_quiz_prompt_type', true));
        $this->assertSame('', get_term_meta($categoryId, 'll_quiz_option_type', true));
        $this->assertSame(1, $wpdb->insert($wpdb->termmeta, [
            'term_id' => $categoryId,
            'meta_key' => 'll_quiz_prompt_type',
            'meta_value' => 'audio',
        ]));
        $this->assertSame(1, $wpdb->insert($wpdb->termmeta, [
            'term_id' => $categoryId,
            'meta_key' => 'll_quiz_option_type',
            'meta_value' => 'image',
        ]));

        $result = ll_tools_wordset_page_maybe_set_manager_import_text_quiz_defaults($categoryId);

        $this->assertIsArray($result);
        $this->assertFalse((bool) ($result['changed'] ?? true));
        $this->assertSame(1, (int) ($result['revision'] ?? 0));
        $this->assertSame('audio', (string) get_term_meta($categoryId, 'll_quiz_prompt_type', true));
        $this->assertSame('image', (string) get_term_meta($categoryId, 'll_quiz_option_type', true));
    }

    public function test_lesson_save_discards_prelock_term_meta_snapshot(): void
    {
        global $wpdb;

        $this->ensureRecordingType('Isolation', 'isolation');
        $adminId = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($adminId);
        $fixture = $this->createManagedLessonFixture($adminId, false);
        $categoryId = (int) $fixture['category_id'];

        $this->assertSame('', get_term_meta($categoryId, 'use_word_titles_for_audio', true));
        $this->assertSame(1, $wpdb->insert($wpdb->termmeta, [
            'term_id' => $categoryId,
            'meta_key' => 'use_word_titles_for_audio',
            'meta_value' => '1',
        ]));

        $result = ll_tools_save_vocab_lesson_category_settings_from_request(
            $this->buildLessonCategorySettingsRequest(
                $fixture,
                0,
                '44444444-4444-4444-8444-444444444444',
                1,
                ['ll_vocab_lesson_quiz_option_type' => 'invalid-option']
            )
        );

        $this->assertIsArray($result);
        $this->assertSame(1, (int) ($result['revision'] ?? 0));
        $this->assertSame('text_title', (string) get_term_meta($categoryId, 'll_quiz_option_type', true));
        $this->assertSame('1', (string) get_term_meta($categoryId, 'use_word_titles_for_audio', true));
    }

    public function test_lesson_lineup_sequence_is_bounded_before_locking(): void
    {
        $managerId = $this->createManagerUser();
        wp_set_current_user($managerId);
        $fixture = $this->createManagedLessonFixture($managerId);
        $lockFilterCalled = false;
        $lockFilter = static function ($waitSeconds) use (&$lockFilterCalled) {
            $lockFilterCalled = true;
            return $waitSeconds;
        };
        add_filter('ll_tools_vocab_lesson_category_settings_lock_wait_seconds', $lockFilter);

        try {
            foreach ([str_repeat('1,', 5001), ['1']] as $invalidSequence) {
                $lockFilterCalled = false;
                $result = ll_tools_save_vocab_lesson_category_settings_from_request(
                    $this->buildLessonCategorySettingsRequest(
                        $fixture,
                        0,
                        '55555555-5555-4555-8555-555555555555',
                        1,
                        [
                            'll_vocab_lesson_category_lineup_submitted' => '1',
                            'll_vocab_lesson_category_lineup_replace' => '1',
                            'll_vocab_lesson_category_lineup_word_ids' => $invalidSequence,
                        ]
                    )
                );
                $this->assertWPError($result);
                $this->assertSame('lineup', ll_tools_get_vocab_lesson_category_settings_error_code($result));
                $this->assertFalse($lockFilterCalled);
            }
        } finally {
            remove_filter('ll_tools_vocab_lesson_category_settings_lock_wait_seconds', $lockFilter);
        }
    }

    public function test_lesson_settings_reject_malformed_or_unbounded_field_shapes_before_locking(): void
    {
        $managerId = $this->createManagerUser();
        wp_set_current_user($managerId);
        $fixture = $this->createManagedLessonFixture($managerId);
        $invalidOverrides = [
            ['ll_vocab_lesson_quiz_prompt_type' => ['audio']],
            ['ll_vocab_lesson_quiz_option_type' => str_repeat('x', 65)],
            ['ll_vocab_lesson_grid_text_visibility' => ['show']],
            ['ll_vocab_lesson_grid_text_visibility' => 'sometimes'],
            ['ll_vocab_lesson_category_enabled_games' => [[]]],
            ['ll_vocab_lesson_category_enabled_games' => array_fill(0, 33, 'line-up')],
            ['ll_vocab_lesson_category_enabled_games' => [str_repeat('x', 65)]],
            ['ll_vocab_lesson_desired_recording_types' => [[]]],
            ['ll_vocab_lesson_desired_recording_types' => array_fill(0, 101, 'isolation')],
            ['ll_vocab_lesson_desired_recording_types' => 'isolation'],
            [
                'll_vocab_lesson_category_lineup_submitted' => '1',
                'll_vocab_lesson_category_lineup_direction' => ['ltr'],
            ],
            [
                'll_vocab_lesson_category_lineup_submitted' => '1',
                'll_vocab_lesson_category_lineup_direction' => 'sideways',
            ],
            [
                'll_vocab_lesson_category_lineup_submitted' => '1',
                'll_vocab_lesson_category_lineup_replace' => ['1'],
            ],
        ];
        $lockFilterCalled = false;
        $lockFilter = static function ($waitSeconds) use (&$lockFilterCalled) {
            $lockFilterCalled = true;
            return $waitSeconds;
        };
        add_filter('ll_tools_vocab_lesson_category_settings_lock_wait_seconds', $lockFilter);

        try {
            foreach ($invalidOverrides as $invalidOverride) {
                $lockFilterCalled = false;
                $result = ll_tools_save_vocab_lesson_category_settings_from_request(
                    $this->buildLessonCategorySettingsRequest(
                        $fixture,
                        0,
                        '66666666-6666-4666-8666-666666666666',
                        1,
                        $invalidOverride
                    )
                );
                $this->assertWPError($result);
                $this->assertSame('settings_request', ll_tools_get_vocab_lesson_category_settings_error_code($result));
                $this->assertFalse($lockFilterCalled);
            }
        } finally {
            remove_filter('ll_tools_vocab_lesson_category_settings_lock_wait_seconds', $lockFilter);
        }
    }

    public function test_lesson_save_rejects_malformed_request_envelope_before_locking(): void
    {
        $managerId = $this->createManagerUser();
        wp_set_current_user($managerId);
        $fixture = $this->createManagedLessonFixture($managerId);
        $baseRequest = $this->buildLessonCategorySettingsRequest(
            $fixture,
            0,
            '77777777-7777-4777-8777-777777777777',
            1
        );
        $invalidRequests = [
            'action' => array_merge($baseRequest, ['ll_vocab_lesson_category_settings_action' => ['save']]),
            'request' => array_merge($baseRequest, ['ll_vocab_lesson_category_settings_lesson_id' => ['1']]),
            'request-overflow' => array_merge($baseRequest, ['ll_vocab_lesson_category_settings_wordset_id' => str_repeat('9', 20)]),
            'request-canonical' => array_merge($baseRequest, ['ll_vocab_lesson_category_settings_category_id' => '01']),
            'nonce' => array_merge($baseRequest, ['ll_vocab_lesson_category_settings_nonce' => ['nonce']]),
        ];
        $lockFilterCalled = false;
        $lockFilter = static function ($waitSeconds) use (&$lockFilterCalled) {
            $lockFilterCalled = true;
            return $waitSeconds;
        };
        add_filter('ll_tools_vocab_lesson_category_settings_lock_wait_seconds', $lockFilter);

        try {
            foreach ($invalidRequests as $expectedCode => $invalidRequest) {
                $lockFilterCalled = false;
                $result = ll_tools_save_vocab_lesson_category_settings_from_request($invalidRequest);
                $this->assertWPError($result);
                $this->assertSame(
                    str_starts_with($expectedCode, 'request') ? 'request' : $expectedCode,
                    ll_tools_get_vocab_lesson_category_settings_error_code($result)
                );
                $this->assertFalse($lockFilterCalled);
            }
        } finally {
            remove_filter('ll_tools_vocab_lesson_category_settings_lock_wait_seconds', $lockFilter);
        }
    }

    public function test_lesson_settings_panel_source_failure_omits_editable_form(): void
    {
        global $wpdb;

        $managerId = $this->createManagerUser();
        wp_set_current_user($managerId);
        $fixture = $this->createManagedLessonFixture($managerId);
        $categoryId = (int) $fixture['category_id'];
        $this->go_to('/?post_type=ll_vocab_lesson&p=' . $fixture['lesson_id']);
        $injected = false;
        $metaFailure = static function ($check, $objectId, $metaKey) use (&$injected, $categoryId, $wpdb) {
            if (
                !$injected
                && (int) $objectId === $categoryId
                && $metaKey === LL_TOOLS_CATEGORY_ENABLED_GAMES_META_KEY
            ) {
                $injected = true;
                $wpdb->last_error = 'll_tools_test_lesson_panel_failure';
                return [[]];
            }
            return $check;
        };
        add_filter('get_term_metadata', $metaFailure, 10, 3);
        try {
            ob_start();
            include LL_TOOLS_BASE_PATH . '/templates/vocab-lesson-template.php';
            $html = (string) ob_get_clean();
        } finally {
            remove_filter('get_term_metadata', $metaFailure, 10);
            $wpdb->last_error = '';
        }

        $this->assertTrue($injected);
        $this->assertStringContainsString('Category settings are temporarily unavailable.', $html);
        $this->assertStringContainsString('>Reload</a>', $html);
        $this->assertStringNotContainsString('name="ll_vocab_lesson_category_settings_action" value="save"', $html);
        $this->assertStringNotContainsString('data-ll-vocab-lesson-category-settings>', $html);
    }

    public function test_external_mutation_rejects_deleted_category_without_orphan_meta(): void
    {
        global $wpdb;

        $adminId = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($adminId);
        $fixture = $this->createManagedLessonFixture($adminId, false);
        $categoryId = (int) $fixture['category_id'];
        $this->assertNotFalse(wp_delete_term($categoryId, 'word-category'));
        $callbackCalled = false;

        $result = ll_tools_run_vocab_lesson_category_settings_external_mutation(
            $categoryId,
            static function () use (&$callbackCalled): array {
                $callbackCalled = true;
                return ['changed' => true];
            }
        );

        $this->assertWPError($result);
        $this->assertSame('category', ll_tools_get_vocab_lesson_category_settings_error_code($result));
        $this->assertFalse($callbackCalled);
        $remainingMetaRows = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_key, meta_value FROM {$wpdb->termmeta} WHERE term_id = %d ORDER BY meta_id ASC",
            $categoryId
        ), ARRAY_A);
        $this->assertSame('0', (string) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->termmeta} WHERE term_id = %d",
            $categoryId
        )), wp_json_encode($remainingMetaRows));
        $this->assertSame('1', (string) $wpdb->get_var($wpdb->prepare(
            'SELECT IS_FREE_LOCK(%s)',
            ll_tools_vocab_lesson_category_settings_lock_name($categoryId)
        )));
    }

    public function test_lineup_candidate_pages_fail_closed_on_post_query_errors(): void
    {
        global $wpdb;

        $adminId = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($adminId);
        $fixture = $this->createManagedLessonFixture($adminId);
        $categoryId = (int) $fixture['category_id'];
        update_term_meta(
            $categoryId,
            LL_TOOLS_CATEGORY_LINEUP_WORD_ORDER_META_KEY,
            [(int) $fixture['word_a_id']]
        );

        foreach (['sequence', 'candidates'] as $view) {
            $injected = false;
            $queryFailure = static function ($posts, WP_Query $query) use (&$injected, $wpdb) {
                if (
                    !$injected
                    && (string) $query->get('post_type') === 'words'
                    && !empty($query->get('tax_query'))
                ) {
                    $injected = true;
                    $wpdb->last_error = 'll_tools_test_lineup_candidate_query_failure';
                    return [];
                }
                return $posts;
            };
            add_filter('posts_pre_query', $queryFailure, 10, 2);
            try {
                $result = ll_tools_get_category_lineup_candidate_page($categoryId, ['view' => $view]);
            } finally {
                remove_filter('posts_pre_query', $queryFailure, 10);
                $wpdb->last_error = '';
            }

            $this->assertTrue($injected, $view);
            $this->assertWPError($result, $view);
            $this->assertSame('lineup_source_incomplete', $result->get_error_code(), $view);
            $this->assertSame(503, (int) (($result->get_error_data()['status'] ?? 0)), $view);
        }
    }

    public function test_native_lesson_save_requires_revision_fence(): void
    {
        $managerId = $this->createManagerUser();
        wp_set_current_user($managerId);
        $fixture = $this->createManagedLessonFixture($managerId);
        $categoryId = (int) $fixture['category_id'];
        update_term_meta($categoryId, 'll_quiz_prompt_type', 'audio');
        $this->go_to('/?post_type=ll_vocab_lesson&p=' . $fixture['lesson_id']);
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'll_vocab_lesson_category_settings_action' => 'save',
            'll_vocab_lesson_category_settings_lesson_id' => (string) $fixture['lesson_id'],
            'll_vocab_lesson_category_settings_wordset_id' => (string) $fixture['wordset_id'],
            'll_vocab_lesson_category_settings_category_id' => (string) $categoryId,
            'll_vocab_lesson_category_settings_nonce' => wp_create_nonce(
                'll_vocab_lesson_category_settings_' . $fixture['lesson_id']
            ),
            'll_vocab_lesson_quiz_prompt_type' => 'text_translation',
        ];

        $redirectUrl = $this->captureRedirect(static function (): void {
            ll_tools_handle_vocab_lesson_category_settings_submit();
        });
        $query = [];
        parse_str((string) wp_parse_url($redirectUrl, PHP_URL_QUERY), $query);

        $this->assertSame('error', (string) ($query['ll_vocab_lesson_category_settings'] ?? ''));
        $this->assertSame('revision_request', (string) ($query['ll_vocab_lesson_category_settings_error'] ?? ''));
        $this->assertSame('audio', (string) get_term_meta($categoryId, 'll_quiz_prompt_type', true));
        $this->assertNotFalse(term_exists($categoryId, 'word-category'));
    }

    public function test_taxonomy_write_failure_rolls_back_all_shared_values_and_releases_lock(): void
    {
        global $wpdb;

        $this->ensureRecordingType('Isolation', 'isolation');
        $adminId = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($adminId);
        $fixture = $this->createManagedLessonFixture($adminId, false);
        $categoryId = (int) $fixture['category_id'];
        update_term_meta($categoryId, 'll_quiz_prompt_type', 'audio');
        update_term_meta($categoryId, LL_TOOLS_CATEGORY_ENABLED_GAMES_META_KEY, ['unscramble']);
        $injected = false;
        $writeFailure = static function ($check, $objectId, $metaKey) use ($categoryId, &$injected) {
            if (!$injected && (int) $objectId === $categoryId && $metaKey === LL_TOOLS_CATEGORY_ENABLED_GAMES_META_KEY) {
                $injected = true;
                return false;
            }
            return $check;
        };
        add_filter('update_term_metadata', $writeFailure, 10, 3);
        try {
            $result = ll_tools_save_word_category_shared_settings_request(
                $categoryId,
                $this->buildTaxonomyCategorySettingsRequest($categoryId, 0),
                true
            );
        } finally {
            remove_filter('update_term_metadata', $writeFailure, 10);
        }

        wp_cache_delete($categoryId, 'term_meta');
        $this->assertTrue($injected);
        $this->assertWPError($result);
        $this->assertSame('settings_write', ll_tools_get_vocab_lesson_category_settings_error_code($result));
        $this->assertSame('audio', (string) get_term_meta($categoryId, 'll_quiz_prompt_type', true));
        $this->assertSame(['unscramble'], get_term_meta($categoryId, LL_TOOLS_CATEGORY_ENABLED_GAMES_META_KEY, true));
        $this->assertSame(0, ll_tools_get_vocab_lesson_category_settings_revision($categoryId));
        $this->assertSame('1', (string) $wpdb->get_var($wpdb->prepare(
            'SELECT IS_FREE_LOCK(%s)',
            ll_tools_vocab_lesson_category_settings_lock_name($categoryId)
        )));
    }

    public function test_failed_new_taxonomy_settings_roll_back_the_created_term_after_all_hooks(): void
    {
        $adminId = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($adminId);
        $categoryName = 'Failed Taxonomy Settings ' . wp_generate_password(8, false);
        $_POST = [
            '_ll_vocab_lesson_category_settings_taxonomy_submitted' => '1',
            '_ll_vocab_lesson_category_settings_render_complete' => '1',
            '_wpnonce_add-tag' => wp_create_nonce('add-tag'),
            'll_vocab_lesson_category_settings_revision' => '0',
            'll_quiz_prompt_type' => 'audio',
            'll_quiz_option_type' => 'image',
            'll_lesson_grid_text_visibility_override' => 'inherit',
        ];
        $storageFailure = static function (): bool {
            return false;
        };
        add_filter('ll_tools_vocab_lesson_category_settings_termmeta_is_transactional', $storageFailure);
        try {
            $inserted = wp_insert_term($categoryName, 'word-category');
        } finally {
            remove_filter('ll_tools_vocab_lesson_category_settings_termmeta_is_transactional', $storageFailure);
            $_POST = [];
        }

        $this->assertIsArray($inserted);
        $termId = (int) $inserted['term_id'];
        $term = get_term($termId, 'word-category');
        $this->assertTrue($term === null || is_wp_error($term));
        $this->assertInstanceOf(WP_Error::class, ll_tools_get_word_category_shared_settings_request_error());
        unset($GLOBALS['ll_tools_word_category_shared_settings_request_error']);
        $this->assertSame([], get_term_meta($termId));
        global $wpdb;
        $this->assertSame('0', (string) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE term_id = %d AND taxonomy = %s",
            $termId,
            'word-category'
        )));
        $this->assertSame('0', (string) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->termmeta} WHERE term_id = %d",
            $termId
        )));
    }

    public function test_named_lock_contention_and_storage_failure_are_distinct_and_fail_before_writes(): void
    {
        $this->ensureRecordingType('Isolation', 'isolation');
        $adminId = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($adminId);
        $fixture = $this->createManagedLessonFixture($adminId, false);
        $categoryId = (int) $fixture['category_id'];
        update_term_meta($categoryId, 'll_quiz_prompt_type', 'audio');
        $request = $this->buildTaxonomyCategorySettingsRequest($categoryId, 0);

        $contentionFilter = static function (string $query): string {
            return str_contains($query, 'GET_LOCK(') ? 'SELECT 0' : $query;
        };
        add_filter('query', $contentionFilter);
        try {
            $busyResult = ll_tools_save_word_category_shared_settings_request($categoryId, $request, true);
        } finally {
            remove_filter('query', $contentionFilter);
        }
        $this->assertWPError($busyResult);
        $this->assertSame('busy', ll_tools_get_vocab_lesson_category_settings_error_code($busyResult));
        $this->assertTrue((bool) (($busyResult->get_error_data()['retryable'] ?? false)));

        $storageFilter = static function (string $query): string {
            return str_contains($query, 'GET_LOCK(') ? 'SELECT NULL' : $query;
        };
        add_filter('query', $storageFilter);
        try {
            $storageResult = ll_tools_save_word_category_shared_settings_request($categoryId, $request, true);
        } finally {
            remove_filter('query', $storageFilter);
        }
        $this->assertWPError($storageResult);
        $this->assertSame('lock_storage', ll_tools_get_vocab_lesson_category_settings_error_code($storageResult));
        $this->assertFalse((bool) (($storageResult->get_error_data()['retryable'] ?? true)));
        $this->assertSame('audio', (string) get_term_meta($categoryId, 'll_quiz_prompt_type', true));
        $this->assertSame(0, ll_tools_get_vocab_lesson_category_settings_revision($categoryId));
    }

    public function test_wordset_manager_can_delete_category_from_lesson_page_without_deleting_words(): void
    {
        $manager_id = $this->createManagerUser();
        wp_set_current_user($manager_id);

        $fixture = $this->createManagedLessonFixture($manager_id);

        $this->go_to('/?post_type=ll_vocab_lesson&p=' . $fixture['lesson_id']);
        $this->assertTrue(is_singular('ll_vocab_lesson'));

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'll_vocab_lesson_category_settings_action' => 'delete',
            'll_vocab_lesson_category_settings_lesson_id' => (string) $fixture['lesson_id'],
            'll_vocab_lesson_category_settings_wordset_id' => (string) $fixture['wordset_id'],
            'll_vocab_lesson_category_settings_category_id' => (string) $fixture['category_id'],
            'll_vocab_lesson_category_settings_nonce' => wp_create_nonce('ll_vocab_lesson_category_settings_' . $fixture['lesson_id']),
        ];

        $redirect_url = $this->captureRedirect(static function (): void {
            ll_tools_handle_vocab_lesson_category_settings_submit();
        });

        $query = [];
        parse_str((string) wp_parse_url($redirect_url, PHP_URL_QUERY), $query);

        $this->assertSame('ok', (string) ($query['ll_wordset_inactive_category'] ?? ''));
        $this->assertSame('deleted', (string) ($query['ll_wordset_inactive_category_result'] ?? ''));

        $deleted_category = get_term((int) $fixture['category_id'], 'word-category');
        $this->assertTrue($deleted_category === null || is_wp_error($deleted_category));
        $this->assertNull(get_post((int) $fixture['lesson_id']));

        foreach ([(int) $fixture['word_a_id'], (int) $fixture['word_b_id']] as $word_id) {
            $this->assertSame('publish', get_post_status($word_id));

            $word_categories = wp_get_object_terms($word_id, 'word-category', ['fields' => 'ids']);
            $this->assertIsArray($word_categories);
            $this->assertNotContains((int) $fixture['category_id'], array_map('intval', $word_categories));

            $wordsets = wp_get_object_terms($word_id, 'wordset', ['fields' => 'ids']);
            $this->assertIsArray($wordsets);
            $this->assertContains((int) $fixture['wordset_id'], array_map('intval', $wordsets));
        }
    }

    public function test_lesson_page_delete_redirects_to_durable_progress_when_first_batch_deletes_current_lesson(): void
    {
        $manager_id = $this->createManagerUser();
        wp_set_current_user($manager_id);
        $fixture = $this->createManagedLessonFixture($manager_id);
        $this->go_to('/?post_type=ll_vocab_lesson&p=' . $fixture['lesson_id']);
        $this->assertTrue(is_singular('ll_vocab_lesson'));
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'll_vocab_lesson_category_settings_action' => 'delete',
            'll_vocab_lesson_category_settings_lesson_id' => (string) $fixture['lesson_id'],
            'll_vocab_lesson_category_settings_wordset_id' => (string) $fixture['wordset_id'],
            'll_vocab_lesson_category_settings_category_id' => (string) $fixture['category_id'],
            'll_vocab_lesson_category_settings_nonce' => wp_create_nonce('ll_vocab_lesson_category_settings_' . $fixture['lesson_id']),
        ];
        $batchSize = static function (): int {
            return 1;
        };
        add_filter('ll_tools_wordset_page_category_delete_batch_size', $batchSize);

        try {
            $redirect_url = $this->captureRedirect(static function (): void {
                ll_tools_handle_vocab_lesson_category_settings_submit();
            });
            $query = [];
            parse_str((string) wp_parse_url($redirect_url, PHP_URL_QUERY), $query);
            $this->assertSame('ok', (string) ($query['ll_wordset_inactive_category'] ?? ''));
            $this->assertSame('deleting', (string) ($query['ll_wordset_inactive_category_result'] ?? ''));
            $this->assertNull(get_post((int) $fixture['lesson_id']));
            $this->assertNotFalse(term_exists((int) $fixture['category_id'], 'word-category'));

            $job = ll_tools_wordset_page_get_category_delete_job((int) $fixture['category_id'], (int) $fixture['wordset_id']);
            $this->assertSame('running', (string) ($job['status'] ?? ''));
            $this->assertSame(1, (int) ($job['deleted_lesson_count'] ?? 0));
            for ($attempt = 0; $attempt < 6 && (string) ($job['status'] ?? '') !== 'complete'; $attempt++) {
                $job = ll_tools_wordset_page_run_category_delete_batch((int) $fixture['category_id'], (int) $fixture['wordset_id']);
                $this->assertIsArray($job);
            }
        } finally {
            remove_filter('ll_tools_wordset_page_category_delete_batch_size', $batchSize);
        }

        $this->assertSame('complete', (string) ($job['status'] ?? ''));
        $this->assertFalse((bool) term_exists((int) $fixture['category_id'], 'word-category'));
        foreach ([(int) $fixture['word_a_id'], (int) $fixture['word_b_id']] as $word_id) {
            $this->assertSame('publish', get_post_status($word_id));
        }
    }

    public function test_category_delete_fails_closed_when_linked_lesson_query_fails(): void
    {
        global $wpdb;

        $managerId = $this->createManagerUser();
        wp_set_current_user($managerId);
        $fixture = $this->createManagedLessonFixture($managerId);
        $injected = false;
        $queryFailure = static function ($posts, WP_Query $query) use (&$injected, $wpdb) {
            if (!$injected && (string) $query->get('post_type') === 'll_vocab_lesson') {
                $injected = true;
                $wpdb->last_error = 'll_tools_test_category_delete_lesson_query_failure';
                return [];
            }
            return $posts;
        };
        add_filter('posts_pre_query', $queryFailure, 10, 2);

        try {
            $result = ll_tools_wordset_page_run_category_delete_batch(
                (int) $fixture['category_id'],
                (int) $fixture['wordset_id']
            );
        } finally {
            remove_filter('posts_pre_query', $queryFailure, 10);
            $wpdb->last_error = '';
        }

        $this->assertTrue($injected);
        $this->assertWPError($result);
        $this->assertSame('delete_lesson_failed', $result->get_error_code());
        $this->assertNotFalse(term_exists((int) $fixture['category_id'], 'word-category'));
        $this->assertInstanceOf(WP_Post::class, get_post((int) $fixture['lesson_id']));
        $job = ll_tools_wordset_page_get_category_delete_job(
            (int) $fixture['category_id'],
            (int) $fixture['wordset_id']
        );
        $this->assertSame('lessons', (string) ($job['phase'] ?? ''));
    }

    public function test_category_lesson_blocker_treats_an_incomplete_query_as_present(): void
    {
        global $wpdb;

        $managerId = $this->createManagerUser();
        wp_set_current_user($managerId);
        $fixture = $this->createManagedLessonFixture($managerId, false);
        wp_delete_post((int) $fixture['lesson_id'], true);
        $injected = false;
        $queryFailure = static function ($posts, WP_Query $query) use (&$injected, $wpdb) {
            if (!$injected && (string) $query->get('post_type') === 'll_vocab_lesson') {
                $injected = true;
                $wpdb->last_error = 'll_tools_test_category_lesson_blocker_query_failure';
                return [];
            }
            return $posts;
        };
        add_filter('posts_pre_query', $queryFailure, 10, 2);

        try {
            $hasLessons = ll_tools_wordset_page_category_has_vocab_lessons(
                (int) $fixture['category_id'],
                (int) $fixture['wordset_id']
            );
        } finally {
            remove_filter('posts_pre_query', $queryFailure, 10);
            $wpdb->last_error = '';
        }

        $this->assertTrue($injected);
        $this->assertTrue($hasLessons);
    }

    public function test_category_delete_fails_closed_when_linked_word_query_fails(): void
    {
        global $wpdb;

        $managerId = $this->createManagerUser();
        wp_set_current_user($managerId);
        $fixture = $this->createManagedLessonFixture($managerId);
        $injected = false;
        $queryFailure = static function ($posts, WP_Query $query) use (&$injected, $wpdb) {
            if (!$injected && (string) $query->get('post_type') === 'words' && $query->get('fields') === 'ids') {
                $injected = true;
                $wpdb->last_error = 'll_tools_test_category_delete_word_query_failure';
                return [];
            }
            return $posts;
        };
        add_filter('posts_pre_query', $queryFailure, 10, 2);

        try {
            $result = ll_tools_wordset_page_run_category_delete_batch(
                (int) $fixture['category_id'],
                (int) $fixture['wordset_id']
            );
        } finally {
            remove_filter('posts_pre_query', $queryFailure, 10);
            $wpdb->last_error = '';
        }

        $this->assertTrue($injected);
        $this->assertWPError($result);
        $this->assertSame('category_delete', $result->get_error_code());
        $this->assertNotFalse(term_exists((int) $fixture['category_id'], 'word-category'));
        foreach ([(int) $fixture['word_a_id'], (int) $fixture['word_b_id']] as $wordId) {
            $categoryIds = wp_get_object_terms($wordId, 'word-category', ['fields' => 'ids']);
            $this->assertIsArray($categoryIds);
            $this->assertContains((int) $fixture['category_id'], array_map('intval', $categoryIds));
        }
        $job = ll_tools_wordset_page_get_category_delete_job(
            (int) $fixture['category_id'],
            (int) $fixture['wordset_id']
        );
        $this->assertSame('failed', (string) ($job['status'] ?? ''));
        $this->assertSame('words', (string) ($job['phase'] ?? ''));
    }

    public function test_category_delete_term_phase_fails_closed_and_saves_failure_when_summary_query_fails(): void
    {
        global $wpdb;

        $managerId = $this->createManagerUser();
        wp_set_current_user($managerId);
        $fixture = $this->createManagedLessonFixture($managerId, false);
        wp_delete_post((int) $fixture['lesson_id'], true);
        $this->assertTrue(ll_tools_wordset_page_save_category_delete_job(
            (int) $fixture['wordset_id'],
            (int) $fixture['category_id'],
            [
                'category_name' => 'Managed Settings Category',
                'status' => 'running',
                'phase' => 'term',
                'lesson_total' => 1,
                'word_total' => 0,
                'deleted_lesson_count' => 1,
                'detached_word_count' => 0,
                'error_code' => '',
                'error_message' => '',
                'started_at' => time(),
            ]
        ));

        $injected = false;
        $queryFailure = static function (string $query) use (&$injected, $wpdb): string {
            if (!$injected && str_contains($query, 'AS prompt_card_count')) {
                $injected = true;
                return "SELECT ll_tools_missing_category_delete_summary_column FROM {$wpdb->posts}";
            }
            return $query;
        };
        $previousSuppressErrors = $wpdb->suppress_errors(true);
        add_filter('query', $queryFailure);

        try {
            $result = ll_tools_wordset_page_run_category_delete_batch(
                (int) $fixture['category_id'],
                (int) $fixture['wordset_id']
            );
        } finally {
            remove_filter('query', $queryFailure);
            $wpdb->suppress_errors($previousSuppressErrors);
            $wpdb->last_error = '';
        }

        $this->assertTrue($injected);
        $this->assertWPError($result);
        $this->assertSame('category_delete', $result->get_error_code());
        $this->assertNotFalse(term_exists((int) $fixture['category_id'], 'word-category'));
        $job = ll_tools_wordset_page_get_category_delete_job(
            (int) $fixture['category_id'],
            (int) $fixture['wordset_id']
        );
        $this->assertSame('failed', (string) ($job['status'] ?? ''));
        $this->assertSame('term', (string) ($job['phase'] ?? ''));
    }

    public function test_category_delete_term_phase_fails_closed_when_owned_term_read_is_incomplete(): void
    {
        global $wpdb;

        $managerId = $this->createManagerUser();
        wp_set_current_user($managerId);
        $fixture = $this->createManagedLessonFixture($managerId, false);
        wp_delete_post((int) $fixture['lesson_id'], true);
        $this->assertTrue(ll_tools_wordset_page_save_category_delete_job(
            (int) $fixture['wordset_id'],
            (int) $fixture['category_id'],
            [
                'category_name' => 'Managed Settings Category',
                'status' => 'running',
                'phase' => 'term',
                'lesson_total' => 1,
                'word_total' => 0,
                'deleted_lesson_count' => 1,
                'detached_word_count' => 0,
                'error_code' => '',
                'error_message' => '',
                'started_at' => time(),
            ]
        ));
        clean_term_cache((int) $fixture['category_id'], 'word-category');

        $injected = false;
        $queryFailure = static function (string $query) use (&$injected, $wpdb, $fixture): string {
            if (
                !$injected
                && str_contains($query, "FROM {$wpdb->terms} AS t INNER JOIN {$wpdb->term_taxonomy} AS tt")
                && str_contains($query, 't.term_id = ' . (int) $fixture['category_id'])
            ) {
                $injected = true;
                return "SELECT ll_tools_missing_owned_category_column FROM {$wpdb->terms}";
            }
            return $query;
        };
        $previousSuppressErrors = $wpdb->suppress_errors(true);
        add_filter('query', $queryFailure);

        try {
            $result = ll_tools_wordset_page_run_category_delete_batch(
                (int) $fixture['category_id'],
                (int) $fixture['wordset_id']
            );
        } finally {
            remove_filter('query', $queryFailure);
            $wpdb->suppress_errors($previousSuppressErrors);
            $wpdb->last_error = '';
        }

        $this->assertTrue($injected);
        $this->assertWPError($result);
        $this->assertSame('category_delete', $result->get_error_code());
        $this->assertNotFalse(term_exists((int) $fixture['category_id'], 'word-category'));
        $job = ll_tools_wordset_page_get_category_delete_job(
            (int) $fixture['category_id'],
            (int) $fixture['wordset_id']
        );
        $this->assertSame('failed', (string) ($job['status'] ?? ''));
        $this->assertSame('term', (string) ($job['phase'] ?? ''));
    }

    public function test_category_delete_does_not_complete_when_taxonomy_delete_did_not_commit(): void
    {
        global $wpdb;

        $managerId = $this->createManagerUser();
        wp_set_current_user($managerId);
        $fixture = $this->createManagedLessonFixture($managerId, false);
        wp_delete_post((int) $fixture['lesson_id'], true);
        $this->assertTrue(ll_tools_wordset_page_save_category_delete_job(
            (int) $fixture['wordset_id'],
            (int) $fixture['category_id'],
            [
                'category_name' => 'Managed Settings Category',
                'status' => 'running',
                'phase' => 'term',
                'lesson_total' => 1,
                'word_total' => 0,
                'deleted_lesson_count' => 1,
                'detached_word_count' => 0,
                'error_code' => '',
                'error_message' => '',
                'started_at' => time(),
            ]
        ));

        $injected = false;
        $queryFailure = static function (string $query) use (&$injected, $wpdb): string {
            if (!$injected && str_starts_with(trim($query), "DELETE FROM `{$wpdb->term_taxonomy}`")) {
                $injected = true;
                return 'SELECT 1';
            }
            return $query;
        };
        add_filter('query', $queryFailure);

        try {
            $result = ll_tools_wordset_page_run_category_delete_batch(
                (int) $fixture['category_id'],
                (int) $fixture['wordset_id']
            );
        } finally {
            remove_filter('query', $queryFailure);
            $wpdb->last_error = '';
        }

        $this->assertTrue($injected);
        $this->assertWPError($result);
        $this->assertSame('category_delete', $result->get_error_code());
        $this->assertNotFalse(term_exists((int) $fixture['category_id'], 'word-category'));
        $job = ll_tools_wordset_page_get_category_delete_job(
            (int) $fixture['category_id'],
            (int) $fixture['wordset_id']
        );
        $this->assertSame('failed', (string) ($job['status'] ?? ''));
        $this->assertSame('term', (string) ($job['phase'] ?? ''));
    }

    public function test_category_delete_continuation_does_not_advance_when_remaining_count_query_fails(): void
    {
        global $wpdb;

        $managerId = $this->createManagerUser();
        wp_set_current_user($managerId);
        $fixture = $this->createManagedLessonFixture($managerId);
        $this->assertTrue(ll_tools_wordset_page_save_category_delete_job(
            (int) $fixture['wordset_id'],
            (int) $fixture['category_id'],
            [
                'category_name' => 'Managed Settings Category',
                'status' => 'running',
                'phase' => 'lessons',
                'lesson_total' => 1,
                'word_total' => 2,
                'deleted_lesson_count' => 0,
                'detached_word_count' => 0,
                'error_code' => '',
                'error_message' => '',
                'started_at' => time(),
            ]
        ));
        $beforeJob = ll_tools_wordset_page_get_category_delete_job(
            (int) $fixture['category_id'],
            (int) $fixture['wordset_id']
        );

        $injected = false;
        $queryFailure = static function (string $query) use (&$injected, $wpdb): string {
            if (
                !$injected
                && str_contains($query, 'COUNT(DISTINCT posts.ID)')
                && str_contains($query, "posts.post_type = 'll_vocab_lesson'")
            ) {
                $injected = true;
                return "SELECT ll_tools_missing_category_delete_count_column FROM {$wpdb->posts}";
            }
            return $query;
        };
        $previousSuppressErrors = $wpdb->suppress_errors(true);
        add_filter('query', $queryFailure);

        try {
            $result = ll_tools_wordset_page_run_category_delete_batch(
                (int) $fixture['category_id'],
                (int) $fixture['wordset_id']
            );
        } finally {
            remove_filter('query', $queryFailure);
            $wpdb->suppress_errors($previousSuppressErrors);
            $wpdb->last_error = '';
        }

        $this->assertTrue($injected);
        $this->assertWPError($result);
        $this->assertSame('category_delete', $result->get_error_code());
        $this->assertNotFalse(term_exists((int) $fixture['category_id'], 'word-category'));
        $this->assertInstanceOf(WP_Post::class, get_post((int) $fixture['lesson_id']));
        $afterJob = ll_tools_wordset_page_get_category_delete_job(
            (int) $fixture['category_id'],
            (int) $fixture['wordset_id']
        );
        $this->assertSame('running', (string) ($afterJob['status'] ?? ''));
        $this->assertSame('lessons', (string) ($afterJob['phase'] ?? ''));
        $this->assertSame((int) ($beforeJob['revision'] ?? 0), (int) ($afterJob['revision'] ?? -1));
    }

    public function test_category_delete_continuation_counts_legacy_source_category_lessons(): void
    {
        $managerId = $this->createManagerUser();
        wp_set_current_user($managerId);
        $fixture = $this->createManagedLessonFixture($managerId);
        $sourceCategory = wp_insert_term(
            'Managed Settings Source Category ' . wp_generate_password(5, false),
            'word-category'
        );
        $this->assertIsArray($sourceCategory);
        $sourceCategoryId = (int) $sourceCategory['term_id'];
        ll_tools_set_category_wordset_owner(
            (int) $fixture['category_id'],
            (int) $fixture['wordset_id'],
            $sourceCategoryId
        );
        update_post_meta(
            (int) $fixture['lesson_id'],
            LL_TOOLS_VOCAB_LESSON_CATEGORY_META,
            (string) $sourceCategoryId
        );
        $this->assertTrue(ll_tools_wordset_page_save_category_delete_job(
            (int) $fixture['wordset_id'],
            (int) $fixture['category_id'],
            [
                'category_name' => 'Managed Settings Category',
                'status' => 'running',
                'phase' => 'lessons',
                'lesson_total' => 1,
                'word_total' => 2,
                'deleted_lesson_count' => 0,
                'detached_word_count' => 0,
                'error_code' => '',
                'error_message' => '',
                'started_at' => time(),
            ]
        ));
        $oneItemBatch = static function (): int {
            return 1;
        };
        add_filter('ll_tools_wordset_page_category_delete_batch_size', $oneItemBatch);

        try {
            $result = ll_tools_wordset_page_run_category_delete_batch(
                (int) $fixture['category_id'],
                (int) $fixture['wordset_id']
            );
        } finally {
            remove_filter('ll_tools_wordset_page_category_delete_batch_size', $oneItemBatch);
        }

        $this->assertIsArray($result);
        $this->assertNull(get_post((int) $fixture['lesson_id']));
        $this->assertNotFalse(term_exists((int) $fixture['category_id'], 'word-category'));
        $this->assertSame('lessons', (string) ($result['phase'] ?? ''));
        $this->assertSame(1, (int) ($result['deleted_lesson_count'] ?? 0));
    }

    public function test_lesson_delete_state_failure_after_mutation_redirects_to_wordset_recovery(): void
    {
        $managerId = $this->createManagerUser();
        wp_set_current_user($managerId);
        $fixture = $this->createManagedLessonFixture($managerId);
        $this->go_to('/?post_type=ll_vocab_lesson&p=' . $fixture['lesson_id']);
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'll_vocab_lesson_category_settings_action' => 'delete',
            'll_vocab_lesson_category_settings_lesson_id' => (string) $fixture['lesson_id'],
            'll_vocab_lesson_category_settings_wordset_id' => (string) $fixture['wordset_id'],
            'll_vocab_lesson_category_settings_category_id' => (string) $fixture['category_id'],
            'll_vocab_lesson_category_settings_nonce' => wp_create_nonce('ll_vocab_lesson_category_settings_' . $fixture['lesson_id']),
        ];
        $stateSaveCount = 0;
        $failFinalStateSaves = static function ($check, int $objectId, string $metaKey) use ($fixture, &$stateSaveCount) {
            if ($objectId === (int) $fixture['wordset_id'] && $metaKey === 'll_wordset_category_delete_jobs') {
                $stateSaveCount++;
                if ($stateSaveCount >= 4) {
                    return false;
                }
            }
            return $check;
        };
        add_filter('update_term_metadata', $failFinalStateSaves, 10, 3);
        try {
            $redirectUrl = $this->captureRedirect(static function (): void {
                ll_tools_handle_vocab_lesson_category_settings_submit();
            });
        } finally {
            remove_filter('update_term_metadata', $failFinalStateSaves, 10);
        }

        $query = [];
        parse_str((string) wp_parse_url($redirectUrl, PHP_URL_QUERY), $query);
        $this->assertNull(get_post((int) $fixture['lesson_id']));
        $this->assertSame('error', (string) ($query['ll_wordset_inactive_category'] ?? ''));
        $this->assertSame('category_delete_state', (string) ($query['ll_wordset_inactive_category_error'] ?? ''));
        $this->assertSame('Category deletion progress could not be saved. Please try again.', (string) ($query['ll_wordset_inactive_category_message'] ?? ''));
    }

    public function test_wordset_manager_can_preserve_title_backed_audio_translation_answers(): void
    {
        $manager_id = $this->createManagerUser();
        wp_set_current_user($manager_id);

        $fixture = $this->createManagedLessonFixture($manager_id);
        update_term_meta((int) $fixture['category_id'], 'll_quiz_prompt_type', 'audio');
        update_term_meta((int) $fixture['category_id'], 'll_quiz_option_type', 'text_translation');
        update_term_meta((int) $fixture['category_id'], 'use_word_titles_for_audio', '1');

        $this->go_to('/?post_type=ll_vocab_lesson&p=' . $fixture['lesson_id']);
        $this->assertTrue(is_singular('ll_vocab_lesson'));

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'll_vocab_lesson_category_settings_action' => 'save',
            'll_vocab_lesson_category_settings_lesson_id' => (string) $fixture['lesson_id'],
            'll_vocab_lesson_category_settings_wordset_id' => (string) $fixture['wordset_id'],
            'll_vocab_lesson_category_settings_category_id' => (string) $fixture['category_id'],
            'll_vocab_lesson_category_settings_nonce' => wp_create_nonce('ll_vocab_lesson_category_settings_' . $fixture['lesson_id']),
            'll_vocab_lesson_category_settings_revision' => '0',
            'll_vocab_lesson_category_settings_client_id' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
            'll_vocab_lesson_category_settings_sequence' => '1',
            'll_vocab_lesson_quiz_prompt_type' => 'audio',
            'll_vocab_lesson_quiz_option_type' => 'text_translation',
            'll_vocab_lesson_grid_text_visibility' => 'inherit',
        ];

        $redirect_url = $this->captureRedirect(static function (): void {
            ll_tools_handle_vocab_lesson_category_settings_submit();
        });

        $query = [];
        parse_str((string) wp_parse_url($redirect_url, PHP_URL_QUERY), $query);

        $this->assertSame('ok', (string) ($query['ll_vocab_lesson_category_settings'] ?? ''));
        $this->assertSame('audio', (string) get_term_meta((int) $fixture['category_id'], 'll_quiz_prompt_type', true));
        $this->assertSame('text_translation', (string) get_term_meta((int) $fixture['category_id'], 'll_quiz_option_type', true));
        $this->assertSame('1', (string) get_term_meta((int) $fixture['category_id'], 'use_word_titles_for_audio', true));
    }

    public function test_wordset_manager_can_reset_text_visibility_and_disable_recording_types_from_lesson_page(): void
    {
        wp_insert_term('Isolation', 'recording_type', ['slug' => 'isolation']);

        $manager_id = $this->createManagerUser();
        wp_set_current_user($manager_id);

        $fixture = $this->createManagedLessonFixture($manager_id);

        update_term_meta((int) $fixture['category_id'], 'll_lesson_grid_text_visibility_override', 'hide');
        update_term_meta((int) $fixture['category_id'], 'll_desired_recording_types', ['isolation']);
        update_term_meta((int) $fixture['category_id'], 'use_word_titles_for_audio', '1');

        $this->go_to('/?post_type=ll_vocab_lesson&p=' . $fixture['lesson_id']);
        $this->assertTrue(is_singular('ll_vocab_lesson'));

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'll_vocab_lesson_category_settings_action' => 'save',
            'll_vocab_lesson_category_settings_lesson_id' => (string) $fixture['lesson_id'],
            'll_vocab_lesson_category_settings_wordset_id' => (string) $fixture['wordset_id'],
            'll_vocab_lesson_category_settings_category_id' => (string) $fixture['category_id'],
            'll_vocab_lesson_category_settings_nonce' => wp_create_nonce('ll_vocab_lesson_category_settings_' . $fixture['lesson_id']),
            'll_vocab_lesson_category_settings_revision' => '0',
            'll_vocab_lesson_category_settings_client_id' => 'dddddddd-dddd-4ddd-8ddd-dddddddddddd',
            'll_vocab_lesson_category_settings_sequence' => '1',
            'll_vocab_lesson_quiz_prompt_type' => 'audio',
            'll_vocab_lesson_quiz_option_type' => 'image',
            'll_vocab_lesson_grid_text_visibility' => 'inherit',
        ];

        $redirect_url = $this->captureRedirect(static function (): void {
            ll_tools_handle_vocab_lesson_category_settings_submit();
        });

        $query = [];
        parse_str((string) wp_parse_url($redirect_url, PHP_URL_QUERY), $query);

        $this->assertSame('ok', (string) ($query['ll_vocab_lesson_category_settings'] ?? ''));
        $this->assertSame('', (string) get_term_meta((int) $fixture['category_id'], 'll_lesson_grid_text_visibility_override', true));
        $this->assertSame(
            [LL_TOOLS_DESIRED_RECORDING_TYPES_DISABLED],
            array_values((array) get_term_meta((int) $fixture['category_id'], 'll_desired_recording_types', true))
        );
        $this->assertSame('', (string) get_term_meta((int) $fixture['category_id'], 'use_word_titles_for_audio', true));
    }

    public function test_success_redirect_uses_surviving_lesson_after_duplicate_cleanup(): void
    {
        $manager_id = $this->createManagerUser();
        $fixture = $this->createManagedLessonFixture($manager_id);
        $replacement_lesson_id = self::factory()->post->create([
            'post_type' => 'll_vocab_lesson',
            'post_status' => 'publish',
            'post_title' => 'Managed Settings Replacement Lesson',
        ]);
        update_post_meta($replacement_lesson_id, LL_TOOLS_VOCAB_LESSON_WORDSET_META, (int) $fixture['wordset_id']);
        update_post_meta($replacement_lesson_id, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, (int) $fixture['category_id']);
        wp_trash_post((int) $fixture['lesson_id']);

        $redirect_url = ll_tools_get_vocab_lesson_category_settings_success_redirect_url(
            (int) $fixture['wordset_id'],
            (int) $fixture['category_id'],
            (string) get_permalink((int) $fixture['lesson_id'])
        );

        $query = [];
        parse_str((string) wp_parse_url($redirect_url, PHP_URL_QUERY), $query);

        $this->assertSame('ok', (string) ($query['ll_vocab_lesson_category_settings'] ?? ''));
        $this->assertSame(get_permalink($replacement_lesson_id), strtok($redirect_url, '?'));
        $this->assertSame('publish', get_post_status($replacement_lesson_id));
    }

    public function test_unmanaged_user_cannot_save_category_settings_from_lesson_page(): void
    {
        $manager_id = $this->createManagerUser();
        $fixture = $this->createManagedLessonFixture($manager_id);

        $other_user_id = self::factory()->user->create(['role' => 'author']);
        $other_user = get_user_by('id', $other_user_id);
        $this->assertInstanceOf(WP_User::class, $other_user);
        $other_user->add_cap('view_ll_tools');
        clean_user_cache($other_user_id);
        wp_set_current_user($other_user_id);

        $this->go_to('/?post_type=ll_vocab_lesson&p=' . $fixture['lesson_id']);
        $this->assertTrue(is_singular('ll_vocab_lesson'));
        $this->assertFalse(ll_tools_user_can_manage_vocab_lesson_category_settings((int) $fixture['category_id'], (int) $fixture['wordset_id']));

        $original_prompt_type = get_term_meta((int) $fixture['category_id'], 'll_quiz_prompt_type', true);
        $original_option_type = get_term_meta((int) $fixture['category_id'], 'll_quiz_option_type', true);

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'll_vocab_lesson_category_settings_action' => 'save',
            'll_vocab_lesson_category_settings_lesson_id' => (string) $fixture['lesson_id'],
            'll_vocab_lesson_category_settings_wordset_id' => (string) $fixture['wordset_id'],
            'll_vocab_lesson_category_settings_category_id' => (string) $fixture['category_id'],
            'll_vocab_lesson_category_settings_nonce' => wp_create_nonce('ll_vocab_lesson_category_settings_' . $fixture['lesson_id']),
            'll_vocab_lesson_category_settings_revision' => '0',
            'll_vocab_lesson_category_settings_client_id' => 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee',
            'll_vocab_lesson_category_settings_sequence' => '1',
            'll_vocab_lesson_quiz_prompt_type' => 'text_translation',
            'll_vocab_lesson_quiz_option_type' => 'text_title',
        ];

        $redirect_url = $this->captureRedirect(static function (): void {
            ll_tools_handle_vocab_lesson_category_settings_submit();
        });

        $query = [];
        parse_str((string) wp_parse_url($redirect_url, PHP_URL_QUERY), $query);

        $this->assertSame('error', (string) ($query['ll_vocab_lesson_category_settings'] ?? ''));
        $this->assertSame('permission', (string) ($query['ll_vocab_lesson_category_settings_error'] ?? ''));
        $this->assertSame($original_prompt_type, get_term_meta((int) $fixture['category_id'], 'll_quiz_prompt_type', true));
        $this->assertSame($original_option_type, get_term_meta((int) $fixture['category_id'], 'll_quiz_option_type', true));
    }

    /**
     * @return array{category_id:int,request:array<string,mixed>,revision_state:array<string,int|string>}
     */
    private function createCategorySettingsFailureFixture(): array
    {
        if (!term_exists('isolation', 'recording_type')) {
            wp_insert_term('Isolation', 'recording_type', ['slug' => 'isolation']);
        }
        if (!term_exists('question', 'recording_type')) {
            wp_insert_term('Question', 'recording_type', ['slug' => 'question']);
        }
        $this->assertNotFalse(term_exists('isolation', 'recording_type'));
        $this->assertNotFalse(term_exists('question', 'recording_type'));

        $manager_id = $this->createManagerUser();
        wp_set_current_user($manager_id);
        $fixture = $this->createManagedLessonFixture($manager_id);
        $category_id = (int) $fixture['category_id'];
        $client_id = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $revision_state = [
            'revision' => 7,
            'client_id' => $client_id,
            'sequence' => 10,
        ];

        update_term_meta($category_id, 'll_quiz_prompt_type', 'audio');
        update_term_meta($category_id, 'll_quiz_option_type', 'image');
        delete_term_meta($category_id, 'use_word_titles_for_audio');
        update_term_meta($category_id, 'll_lesson_grid_text_visibility_override', 'show');
        update_term_meta($category_id, LL_TOOLS_CATEGORY_ENABLED_GAMES_META_KEY, ['unscramble']);
        update_term_meta($category_id, 'll_desired_recording_types', ['isolation']);
        update_term_meta($category_id, LL_TOOLS_CATEGORY_LINEUP_DIRECTION_META_KEY, 'rtl');
        update_term_meta(
            $category_id,
            ll_tools_vocab_lesson_category_settings_revision_meta_key(),
            $revision_state
        );

        return [
            'category_id' => $category_id,
            'revision_state' => $revision_state,
            'request' => [
                'll_vocab_lesson_category_settings_action' => 'save',
                'll_vocab_lesson_category_settings_lesson_id' => (string) $fixture['lesson_id'],
                'll_vocab_lesson_category_settings_wordset_id' => (string) $fixture['wordset_id'],
                'll_vocab_lesson_category_settings_category_id' => (string) $category_id,
                'll_vocab_lesson_category_settings_nonce' => wp_create_nonce('ll_vocab_lesson_category_settings_' . $fixture['lesson_id']),
                'll_vocab_lesson_category_settings_revision' => '7',
                'll_vocab_lesson_category_settings_client_id' => $client_id,
                'll_vocab_lesson_category_settings_sequence' => '11',
                'll_vocab_lesson_quiz_prompt_type' => 'text_translation',
                'll_vocab_lesson_quiz_option_type' => 'text_title',
                'll_vocab_lesson_grid_text_visibility' => 'hide',
                'll_vocab_lesson_category_enabled_games' => ['line-up'],
                'll_vocab_lesson_desired_recording_types' => ['question'],
                'll_vocab_lesson_category_lineup_submitted' => '1',
                'll_vocab_lesson_category_lineup_direction' => 'ltr',
            ],
        ];
    }

    /** @param mixed $result */
    private function assertCategorySettingsRetryableFailure($result, string $error_code): void
    {
        $this->assertWPError($result);
        $this->assertSame($error_code, ll_tools_get_vocab_lesson_category_settings_error_code($result));
        $this->assertSame(__('Unable to save category settings right now.', 'll-tools-text-domain'), $result->get_error_message());
        $error_data = $result->get_error_data();
        $this->assertIsArray($error_data);
        $this->assertSame(503, (int) ($error_data['status'] ?? 0));
        $this->assertTrue((bool) ($error_data['retryable'] ?? false));
    }

    /** @param array{category_id:int,revision_state:array<string,int|string>} $failure */
    private function assertCategorySettingsFailureFixtureUnchanged(array $failure): void
    {
        $category_id = (int) $failure['category_id'];
        wp_cache_delete($category_id, 'term_meta');

        $this->assertSame('audio', (string) get_term_meta($category_id, 'll_quiz_prompt_type', true));
        $this->assertSame('image', (string) get_term_meta($category_id, 'll_quiz_option_type', true));
        $this->assertSame('', (string) get_term_meta($category_id, 'use_word_titles_for_audio', true));
        $this->assertSame('show', (string) get_term_meta($category_id, 'll_lesson_grid_text_visibility_override', true));
        $this->assertSame(['unscramble'], get_term_meta($category_id, LL_TOOLS_CATEGORY_ENABLED_GAMES_META_KEY, true));
        $this->assertSame(['isolation'], get_term_meta($category_id, 'll_desired_recording_types', true));
        $this->assertSame('rtl', (string) get_term_meta($category_id, LL_TOOLS_CATEGORY_LINEUP_DIRECTION_META_KEY, true));
        $this->assertSame(
            $failure['revision_state'],
            ll_tools_get_vocab_lesson_category_settings_revision_state($category_id)
        );
    }

    /**
     * @param array<string,int> $fixture
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function buildLessonCategorySettingsRequest(
        array $fixture,
        int $revision,
        string $clientId,
        int $sequence,
        array $overrides = []
    ): array {
        return array_merge([
            'll_vocab_lesson_category_settings_action' => 'save',
            'll_vocab_lesson_category_settings_lesson_id' => (string) $fixture['lesson_id'],
            'll_vocab_lesson_category_settings_wordset_id' => (string) $fixture['wordset_id'],
            'll_vocab_lesson_category_settings_category_id' => (string) $fixture['category_id'],
            'll_vocab_lesson_category_settings_nonce' => wp_create_nonce(
                'll_vocab_lesson_category_settings_' . $fixture['lesson_id']
            ),
            'll_vocab_lesson_category_settings_revision' => (string) $revision,
            'll_vocab_lesson_category_settings_client_id' => $clientId,
            'll_vocab_lesson_category_settings_sequence' => (string) $sequence,
            'll_vocab_lesson_quiz_prompt_type' => 'audio',
            'll_vocab_lesson_quiz_option_type' => 'image',
            'll_vocab_lesson_grid_text_visibility' => 'inherit',
            'll_vocab_lesson_category_enabled_games' => [],
            'll_vocab_lesson_desired_recording_types' => ['isolation'],
        ], $overrides);
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function buildTaxonomyCategorySettingsRequest(
        int $categoryId,
        int $revision,
        array $overrides = []
    ): array {
        return array_merge([
            '_ll_vocab_lesson_category_settings_taxonomy_submitted' => '1',
            '_ll_vocab_lesson_category_settings_render_complete' => '1',
            '_wpnonce' => wp_create_nonce('update-tag_' . $categoryId),
            'll_vocab_lesson_category_settings_revision' => (string) $revision,
            'll_quiz_prompt_type' => 'text_translation',
            'll_quiz_option_type' => 'text_title',
            'll_lesson_grid_text_visibility_override' => 'hide',
            'll_category_enabled_games_submitted' => '1',
            'll_category_enabled_games' => ['line-up'],
            'll_category_lineup_config_submitted' => '1',
            'll_category_lineup_direction' => 'rtl',
            'll_desired_recording_types_submitted' => '1',
            'll_desired_recording_types' => ['isolation'],
        ], $overrides);
    }

    private function ensureRecordingType(string $name, string $slug): int
    {
        $existing = term_exists($slug, 'recording_type');
        if ($existing) {
            return (int) (is_array($existing) ? ($existing['term_id'] ?? 0) : $existing);
        }
        $term = wp_insert_term($name, 'recording_type', ['slug' => $slug]);
        $this->assertIsArray($term);
        return (int) $term['term_id'];
    }

    private function createManagerUser(): int
    {
        $manager_id = self::factory()->user->create(['role' => 'author']);
        $manager = get_user_by('id', $manager_id);
        $this->assertInstanceOf(WP_User::class, $manager);
        $manager->add_cap('view_ll_tools');
        clean_user_cache($manager_id);

        return $manager_id;
    }

    /**
     * @return array<string,int>
     */
    private function createManagedLessonFixture(int $manager_id, bool $with_words = true): array
    {
        $wordset = wp_insert_term('Managed Settings Wordset ' . wp_generate_password(4, false), 'wordset');
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];
        update_term_meta($wordset_id, 'manager_user_id', $manager_id);

        $category_slug = 'managed-settings-category-' . strtolower(wp_generate_password(4, false));
        $category = wp_insert_term('Managed Settings Category', 'word-category', ['slug' => $category_slug]);
        $this->assertIsArray($category);
        $category_id = (int) $category['term_id'];
        ll_tools_set_category_wordset_owner($category_id, $wordset_id, $category_id);

        $word_a_id = 0;
        $word_b_id = 0;
        if ($with_words) {
            $word_a_id = self::factory()->post->create([
                'post_type' => 'words',
                'post_status' => 'publish',
                'post_title' => 'Alpha',
            ]);
            wp_set_post_terms($word_a_id, [$category_id], 'word-category', false);
            wp_set_post_terms($word_a_id, [$wordset_id], 'wordset', false);

            $word_b_id = self::factory()->post->create([
                'post_type' => 'words',
                'post_status' => 'publish',
                'post_title' => 'Beta',
            ]);
            wp_set_post_terms($word_b_id, [$category_id], 'word-category', false);
            wp_set_post_terms($word_b_id, [$wordset_id], 'wordset', false);
        }

        $lesson_id = self::factory()->post->create([
            'post_type' => 'll_vocab_lesson',
            'post_status' => 'publish',
            'post_title' => 'Managed Settings Lesson',
        ]);
        update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_WORDSET_META, $wordset_id);
        update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, $category_id);

        return [
            'wordset_id' => $wordset_id,
            'category_id' => $category_id,
            'lesson_id' => $lesson_id,
            'word_a_id' => $word_a_id,
            'word_b_id' => $word_b_id,
        ];
    }

    private function captureRedirect(callable $callback): string
    {
        $redirect_url = '';
        $redirect_filter = static function ($location) use (&$redirect_url) {
            $redirect_url = (string) $location;
            throw new RuntimeException('redirect_intercepted');
        };

        add_filter('wp_redirect', $redirect_filter, 10, 1);

        try {
            $callback();
            $this->fail('Expected redirect.');
        } catch (RuntimeException $e) {
            $this->assertSame('redirect_intercepted', $e->getMessage());
        } finally {
            remove_filter('wp_redirect', $redirect_filter, 10);
        }

        $this->assertNotSame('', $redirect_url);
        return $redirect_url;
    }
}
