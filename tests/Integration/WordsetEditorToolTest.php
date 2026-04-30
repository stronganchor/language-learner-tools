<?php
declare(strict_types=1);

final class WordsetEditorToolTest extends LL_Tools_TestCase
{
    private const ONE_PIXEL_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+tmP8AAAAASUVORK5CYII=';

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
        delete_option(LL_TOOLS_WORDSET_EDITOR_HISTORY_OPTION);
    }

    protected function tearDown(): void
    {
        $_GET = $this->getBackup;
        $_POST = $this->postBackup;
        $_SERVER = $this->serverBackup;
        set_query_var('ll_wordset_page', null);
        set_query_var('ll_wordset_view', null);
        delete_option(LL_TOOLS_WORDSET_EDITOR_HISTORY_OPTION);
        wp_set_current_user(0);
        parent::tearDown();
    }

    public function test_wordset_editor_tool_renders_searchable_filterable_word_table(): void
    {
        $this->loginEditor();
        $fixture = $this->createFixture('wordset-editor-render');
        $wordset_term = get_term((int) $fixture['wordset_id'], 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset_term);
        $lesson_id = self::factory()->post->create([
            'post_type' => 'll_vocab_lesson',
            'post_status' => 'publish',
            'post_title' => 'Alpha Lesson',
        ]);
        update_post_meta((int) $lesson_id, LL_TOOLS_VOCAB_LESSON_WORDSET_META, (string) $fixture['wordset_id']);
        update_post_meta((int) $lesson_id, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, (string) $fixture['category_a_id']);
        set_post_thumbnail((int) $fixture['alpha_word_id'], $this->createImageAttachment('wordset-editor-alpha.png'));
        update_term_meta((int) $fixture['wordset_id'], 'll_wordset_has_gender', '1');
        update_term_meta((int) $fixture['wordset_id'], 'll_wordset_gender_options', ['Masculine', 'Feminine']);
        update_term_meta((int) $fixture['wordset_id'], 'll_wordset_has_plurality', '1');
        update_term_meta((int) $fixture['wordset_id'], 'll_wordset_plurality_options', ['Singular', 'Plural']);
        $noun_id = $this->ensureTerm('part_of_speech', 'Noun', 'noun');
        wp_set_object_terms((int) $fixture['alpha_word_id'], [$noun_id], 'part_of_speech', false);
        update_post_meta((int) $fixture['alpha_word_id'], 'll_grammatical_gender', 'Masculine');
        update_post_meta((int) $fixture['alpha_word_id'], 'll_grammatical_plurality', 'Singular');
        update_post_meta((int) $fixture['alpha_word_id'], 'word_example_sentence', 'Alpha example sentence');
        update_post_meta((int) $fixture['alpha_word_id'], 'word_example_sentence_translation', 'Alpha example translation');
        update_post_meta((int) $fixture['alpha_word_id'], 'll_word_usage_note', 'Alpha usage note');
        update_post_meta((int) $fixture['alpha_recording_id'], 'recording_text', 'Alpha spoken form');
        update_post_meta((int) $fixture['alpha_recording_id'], 'recording_translation', 'Alpha recording translation');
        update_post_meta((int) $fixture['alpha_recording_id'], 'recording_ipa', 'al.fa');

        $_GET = [
            'll_wordset_tool' => 'editor',
            'll_editor_q' => 'Alpha Translation',
            'll_editor_sort' => 'recording',
            'll_editor_dir' => 'desc',
        ];
        $_SERVER['REQUEST_URI'] = $this->requestUriFromUrl(ll_tools_get_wordset_settings_tool_url($wordset_term, 'editor'));
        set_query_var('ll_wordset_page', (string) $wordset_term->slug);
        set_query_var('ll_wordset_view', 'settings');

        $html = ll_tools_render_wordset_page_content((int) $fixture['wordset_id']);

        $this->assertStringContainsString('data-ll-wordset-editor', $html);
        $this->assertStringContainsString('name="ll_editor_q"', $html);
        $this->assertStringContainsString('name="ll_editor_exact"', $html);
        $this->assertStringContainsString('Exact letters + diacritics', $html);
        $this->assertStringContainsString('name="ll_editor_category[]"', $html);
        $this->assertStringContainsString('data-ll-wordset-editor-category-filter', $html);
        $this->assertStringContainsString('name="ll_editor_status"', $html);
        $this->assertStringContainsString('name="ll_editor_image"', $html);
        $this->assertStringContainsString('name="ll_editor_recording"', $html);
        $this->assertStringContainsString('name="ll_editor_sort"', $html);
        $this->assertStringContainsString('ll_editor_sort=word', $html);
        $this->assertStringContainsString('ll_editor_sort=translation', $html);
        $this->assertStringContainsString('ll_wordset_editor_all_filtered', $html);
        $this->assertStringContainsString('All 1 filtered word', $html);
        $this->assertStringContainsString('Alpha Word', $html);
        $this->assertStringContainsString('Alpha Translation', $html);
        $this->assertStringContainsString('ll-wordset-editor-word-details', $html);
        $this->assertStringContainsString('Word text', $html);
        $this->assertStringContainsString('Example', $html);
        $this->assertStringContainsString('Alpha example sentence', $html);
        $this->assertStringContainsString('Example translation', $html);
        $this->assertStringContainsString('Alpha example translation', $html);
        $this->assertStringContainsString('Alpha usage note', $html);
        $this->assertStringContainsString('data-ll-wordset-editor-meta="part_of_speech"', $html);
        $this->assertStringContainsString('Noun', $html);
        $this->assertStringContainsString('data-ll-wordset-editor-meta="grammatical_gender"', $html);
        $this->assertStringContainsString('Masculine', $html);
        $this->assertStringContainsString('data-ll-wordset-editor-meta="grammatical_plurality"', $html);
        $this->assertStringContainsString('Singular', $html);
        $this->assertStringContainsString('data-ll-wordset-editor-thumb', $html);
        $this->assertStringContainsString('class="ll-wordset-editor-recording-play ll-study-recording-btn', $html);
        $this->assertStringContainsString('data-ll-wordset-editor-audio', $html);
        $this->assertStringContainsString('wordset-editor-render-alpha.wav', $html);
        $this->assertStringContainsString('data-ll-wordset-editor-recording-field="recording_text"', $html);
        $this->assertStringContainsString('Alpha spoken form', $html);
        $this->assertStringContainsString('data-ll-wordset-editor-recording-field="recording_translation"', $html);
        $this->assertStringContainsString('Alpha recording translation', $html);
        $this->assertStringContainsString('data-ll-wordset-editor-recording-field="recording_ipa"', $html);
        $this->assertStringContainsString('al.fa', $html);
        $this->assertStringContainsString('ll_wordset_editor_recording_id', $html);
        $this->assertStringNotContainsString('ll_wordset_editor_target_word_id', $html);
        $this->assertStringNotContainsString('data-ll-wordset-editor-move-target', $html);
        $this->assertStringNotContainsString('ll-wordset-editor-move-options-', $html);
        $this->assertStringContainsString('data-ll-wordset-editor-confirm', $html);
        $this->assertStringContainsString('ll_wordset_manager_editor_action', $html);
        $this->assertStringContainsString('data-ll-wordset-editor-bulk-action', $html);
        $this->assertMatchesRegularExpression('/<label[^>]+data-ll-wordset-editor-category-target-field[^>]+hidden/', $html);
        $this->assertStringContainsString('aria-label="Show all words"', $html);
        $this->assertStringContainsString('aria-label="Show words missing published audio"', $html);
        $this->assertStringContainsString('ll_editor_recording=missing', $html);
        $this->assertStringContainsString('aria-label="Show words missing images"', $html);
        $this->assertStringContainsString('ll_editor_image=missing', $html);
        $this->assertStringContainsString('#ll-wordset-editor-history', $html);
        $this->assertStringNotContainsString('Review queues', $html);
        $this->assertStringNotContainsString('No published audio', $html);
        $this->assertStringNotContainsString('Missing required audio', $html);
        $this->assertStringContainsString('Saved views', $html);
        $this->assertStringContainsString('ll_wordset_editor_filter_name', $html);
        $this->assertStringContainsString('data-ll-wordset-editor-open-word-edit', $html);
        $this->assertStringContainsString('data-ll-wordset-editor-modal-grid', $html);
        $this->assertStringContainsString('data-ll-word-edit-panel', $html);
        $this->assertStringContainsString('ll-wordset-editor-pill--link', $html);
        $this->assertStringContainsString(esc_url(get_permalink((int) $lesson_id)), $html);
        $this->assertStringContainsString('missing_image_review', $html);
        $this->assertStringContainsString('Action history', $html);
        $this->assertStringContainsString('ll_editor_history_type', $html);
        $this->assertStringContainsString('Recent actions', $html);
    }

    public function test_wordset_editor_search_folds_diacritics_by_default_and_allows_exact_match(): void
    {
        $this->loginEditor();
        $fixture = $this->createFixture('wordset-editor-diacritic-search');
        $wordset_id = (int) $fixture['wordset_id'];
        wp_update_post([
            'ID' => (int) $fixture['alpha_word_id'],
            'post_title' => "\u{00C7}arna",
        ]);

        $category_rows = function_exists('ll_tools_word_grid_get_category_editor_rows')
            ? ll_tools_word_grid_get_category_editor_rows($wordset_id)
            : [];
        $base_filters = [
            'q'         => 'Carna',
            'category'  => 0,
            'status'    => '',
            'image'     => '',
            'recording' => '',
            'sort'      => 'word',
            'dir'       => 'asc',
            'paged'     => 1,
        ];

        $folded_result = ll_tools_wordset_editor_build_rows($wordset_id, $category_rows, $base_filters);
        $folded_ids = array_map('intval', wp_list_pluck((array) ($folded_result['rows'] ?? []), 'id'));
        $this->assertContains((int) $fixture['alpha_word_id'], $folded_ids);

        $exact_result = ll_tools_wordset_editor_build_rows($wordset_id, $category_rows, array_merge($base_filters, [
            'exact' => true,
        ]));
        $exact_ids = array_map('intval', wp_list_pluck((array) ($exact_result['rows'] ?? []), 'id'));
        $this->assertNotContains((int) $fixture['alpha_word_id'], $exact_ids);

        $exact_diacritic_result = ll_tools_wordset_editor_build_rows($wordset_id, $category_rows, array_merge($base_filters, [
            'q'     => "\u{00C7}arna",
            'exact' => true,
        ]));
        $exact_diacritic_ids = array_map('intval', wp_list_pluck((array) ($exact_diacritic_result['rows'] ?? []), 'id'));
        $this->assertContains((int) $fixture['alpha_word_id'], $exact_diacritic_ids);
    }

    public function test_missing_audio_filter_includes_words_with_no_published_audio_even_when_audio_is_not_required(): void
    {
        $this->loginEditor();
        $fixture = $this->createFixture('wordset-editor-missing-audio');
        update_term_meta((int) $fixture['category_a_id'], 'll_quiz_prompt_type', 'text_title');
        update_term_meta((int) $fixture['category_a_id'], 'll_quiz_option_type', 'text_translation');

        $category_rows = function_exists('ll_tools_word_grid_get_category_editor_rows')
            ? ll_tools_word_grid_get_category_editor_rows((int) $fixture['wordset_id'])
            : [];
        $result = ll_tools_wordset_editor_build_rows((int) $fixture['wordset_id'], $category_rows, [
            'q'         => '',
            'category'  => 0,
            'status'    => '',
            'image'     => '',
            'recording' => 'missing',
            'sort'      => 'word',
            'dir'       => 'asc',
            'paged'     => 1,
        ]);

        $rows = (array) ($result['rows'] ?? []);
        $summary = (array) ($result['summary'] ?? []);
        $this->assertSame(1, (int) ($summary['missing_audio'] ?? 0));
        $this->assertCount(1, $rows);
        $this->assertSame((int) $fixture['beta_word_id'], (int) ($rows[0]['id'] ?? 0));
        $this->assertFalse((bool) ($rows[0]['requires_audio'] ?? true));
        $this->assertTrue((bool) ($rows[0]['missing_audio'] ?? false));
    }

    public function test_category_filter_dropdown_scopes_options_to_other_active_filters_and_supports_multiple_selection(): void
    {
        $this->loginEditor();
        $fixture = $this->createFixture('wordset-editor-category-filter');
        $wordset_id = (int) $fixture['wordset_id'];
        $wordset_term = get_term($wordset_id, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset_term);

        set_post_thumbnail((int) $fixture['alpha_word_id'], $this->createImageAttachment('wordset-editor-category-filter-alpha.png'));
        $image_ready_word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Image Ready Word',
        ]);
        wp_set_object_terms((int) $image_ready_word_id, [$wordset_id], 'wordset', false);
        wp_set_object_terms((int) $image_ready_word_id, [(int) $fixture['category_b_id']], 'word-category', false);
        set_post_thumbnail((int) $image_ready_word_id, $this->createImageAttachment('wordset-editor-category-filter-ready.png'));

        $category_c_id = $this->createOwnedCategory('wordset-editor-category-filter-c', $wordset_id);
        $gamma_word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Gamma Missing Image',
        ]);
        wp_set_object_terms((int) $gamma_word_id, [$wordset_id], 'wordset', false);
        wp_set_object_terms((int) $gamma_word_id, [$category_c_id], 'word-category', false);

        $_GET = [
            'll_wordset_tool' => 'editor',
            'll_editor_image' => 'missing',
            'll_editor_category' => [
                (string) $fixture['category_a_id'],
                (string) $category_c_id,
            ],
        ];
        $_SERVER['REQUEST_URI'] = $this->requestUriFromUrl(ll_tools_get_wordset_settings_tool_url($wordset_term, 'editor'));
        set_query_var('ll_wordset_page', (string) $wordset_term->slug);
        set_query_var('ll_wordset_view', 'settings');

        $html = ll_tools_render_wordset_page_content($wordset_id);
        $filter_html = $this->extractCategoryFilterHtml($html);

        $this->assertStringContainsString('name="ll_editor_category[]"', $filter_html);
        $this->assertMatchesRegularExpression('/name="ll_editor_category\\[\\]"[^>]+value="' . preg_quote((string) $fixture['category_a_id'], '/') . '"[^>]+checked/', $filter_html);
        $this->assertMatchesRegularExpression('/name="ll_editor_category\\[\\]"[^>]+value="' . preg_quote((string) $category_c_id, '/') . '"[^>]+checked/', $filter_html);
        $this->assertStringContainsString('data-ll-wordset-editor-category-filter-option-id="' . (int) $fixture['category_a_id'] . '"', $filter_html);
        $this->assertStringContainsString('data-ll-wordset-editor-category-filter-option-id="' . (int) $category_c_id . '"', $filter_html);
        $this->assertStringNotContainsString('data-ll-wordset-editor-category-filter-option-id="' . (int) $fixture['category_b_id'] . '"', $filter_html);
        $this->assertStringContainsString('2 categories', $filter_html);
        $this->assertStringContainsString('Beta Word', $html);
        $this->assertStringContainsString('Gamma Missing Image', $html);
        $this->assertStringNotContainsString('Image Ready Word</strong>', $html);
    }

    public function test_quick_update_changes_word_fields_and_is_undoable(): void
    {
        $this->loginEditor();
        $fixture = $this->createFixture('wordset-editor-quick-update');
        $wordset_id = (int) $fixture['wordset_id'];
        $wordset_term = get_term($wordset_id, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset_term);

        $_POST = [
            'll_wordset_manager_editor_action' => 'quick_update',
            'll_wordset_manager_editor_wordset_id' => (string) $wordset_id,
            'll_wordset_manager_editor_nonce' => wp_create_nonce('ll_wordset_manager_editor_' . $wordset_id),
            'll_wordset_editor_word_id' => (string) $fixture['beta_word_id'],
            'll_wordset_editor_word_title' => 'Beta Updated',
            'll_wordset_editor_word_translation' => 'Beta Updated Translation',
            'll_wordset_tool' => 'editor',
        ];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = $this->requestUriFromUrl(ll_tools_get_wordset_settings_tool_url($wordset_term, 'editor'));
        set_query_var('ll_wordset_page', (string) $wordset_term->slug);
        set_query_var('ll_wordset_view', 'settings');

        $update_redirect = $this->captureRedirect(static function (): void {
            ll_tools_wordset_page_handle_manager_editor_action();
        });

        $update_query = $this->parseRedirectQuery($update_redirect);
        $this->assertSame('quick_update', (string) ($update_query['ll_wordset_manager_editor_result'] ?? ''));
        $this->assertSame('1', (string) ($update_query['ll_wordset_manager_editor_count'] ?? ''));
        $this->assertSame('Beta Updated', get_the_title((int) $fixture['beta_word_id']));
        $this->assertSame('Beta Updated Translation', (string) get_post_meta((int) $fixture['beta_word_id'], 'word_translation', true));
        $this->assertSame('Beta Updated Translation', (string) get_post_meta((int) $fixture['beta_word_id'], 'word_english_meaning', true));

        $recent = ll_tools_wordset_editor_get_recent_actions($wordset_id, 1);
        $this->assertCount(1, $recent);
        $this->assertSame('quick_update', (string) ($recent[0]['type'] ?? ''));

        $_GET = [
            'll_wordset_tool' => 'editor',
            'll_editor_history_type' => 'quick_update',
        ];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = $this->requestUriFromUrl(ll_tools_get_wordset_settings_tool_url($wordset_term, 'editor'));

        $html = ll_tools_render_wordset_page_content($wordset_id);
        $this->assertStringContainsString('Quick edits', $html);
        $this->assertStringContainsString('Details', $html);
        $this->assertStringContainsString('Beta Updated', $html);

        $_POST = [
            'll_wordset_manager_editor_action' => 'undo',
            'll_wordset_manager_editor_wordset_id' => (string) $wordset_id,
            'll_wordset_manager_editor_nonce' => wp_create_nonce('ll_wordset_manager_editor_' . $wordset_id),
            'll_wordset_editor_action_id' => (string) ($recent[0]['id'] ?? ''),
            'll_wordset_tool' => 'editor',
        ];
        $_SERVER['REQUEST_METHOD'] = 'POST';

        $undo_redirect = $this->captureRedirect(static function (): void {
            ll_tools_wordset_page_handle_manager_editor_action();
        });

        $undo_query = $this->parseRedirectQuery($undo_redirect);
        $this->assertSame('undo', (string) ($undo_query['ll_wordset_manager_editor_result'] ?? ''));
        $this->assertSame('Beta Word', get_the_title((int) $fixture['beta_word_id']));
        $this->assertSame('Beta Translation', (string) get_post_meta((int) $fixture['beta_word_id'], 'word_translation', true));
        $this->assertSame('', (string) get_post_meta((int) $fixture['beta_word_id'], 'word_english_meaning', true));
    }

    public function test_saved_filter_views_can_be_created_rendered_and_deleted(): void
    {
        $this->loginEditor();
        $fixture = $this->createFixture('wordset-editor-saved-view');
        $wordset_id = (int) $fixture['wordset_id'];
        $wordset_term = get_term($wordset_id, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset_term);

        $_POST = [
            'll_wordset_manager_editor_action' => 'save_filter',
            'll_wordset_manager_editor_wordset_id' => (string) $wordset_id,
            'll_wordset_manager_editor_nonce' => wp_create_nonce('ll_wordset_manager_editor_' . $wordset_id),
            'll_wordset_editor_filter_name' => 'Needs media',
            'll_editor_image' => 'missing',
            'll_editor_recording' => 'missing',
            'll_editor_sort' => 'translation',
            'll_editor_dir' => 'desc',
            'll_wordset_tool' => 'editor',
        ];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = $this->requestUriFromUrl(ll_tools_get_wordset_settings_tool_url($wordset_term, 'editor'));
        set_query_var('ll_wordset_page', (string) $wordset_term->slug);
        set_query_var('ll_wordset_view', 'settings');

        $save_redirect = $this->captureRedirect(static function (): void {
            ll_tools_wordset_page_handle_manager_editor_action();
        });

        $save_query = $this->parseRedirectQuery($save_redirect);
        $this->assertSame('save_filter', (string) ($save_query['ll_wordset_manager_editor_result'] ?? ''));
        $saved_filters = ll_tools_wordset_editor_get_saved_filters($wordset_id);
        $this->assertCount(1, $saved_filters);
        $this->assertSame('Needs media', (string) ($saved_filters[0]['name'] ?? ''));
        $this->assertSame('missing', (string) ($saved_filters[0]['filters']['image'] ?? ''));
        $this->assertSame('missing', (string) ($saved_filters[0]['filters']['recording'] ?? ''));

        $_GET = ['ll_wordset_tool' => 'editor'];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = $this->requestUriFromUrl(ll_tools_get_wordset_settings_tool_url($wordset_term, 'editor'));

        $html = ll_tools_render_wordset_page_content($wordset_id);
        $this->assertStringContainsString('Needs media', $html);
        $this->assertStringContainsString('ll_editor_image=missing', $html);
        $this->assertStringContainsString('Delete saved view', $html);

        $_POST = [
            'll_wordset_manager_editor_action' => 'delete_filter',
            'll_wordset_manager_editor_wordset_id' => (string) $wordset_id,
            'll_wordset_manager_editor_nonce' => wp_create_nonce('ll_wordset_manager_editor_' . $wordset_id),
            'll_wordset_editor_filter_id' => (string) ($saved_filters[0]['id'] ?? ''),
            'll_wordset_tool' => 'editor',
        ];
        $_SERVER['REQUEST_METHOD'] = 'POST';

        $delete_redirect = $this->captureRedirect(static function (): void {
            ll_tools_wordset_page_handle_manager_editor_action();
        });

        $delete_query = $this->parseRedirectQuery($delete_redirect);
        $this->assertSame('delete_filter', (string) ($delete_query['ll_wordset_manager_editor_result'] ?? ''));
        $this->assertSame([], ll_tools_wordset_editor_get_saved_filters($wordset_id));
    }

    public function test_all_filtered_bulk_category_move_uses_current_filters(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $fixture = $this->createFixture('wordset-editor-all-filtered');
        $wordset_id = (int) $fixture['wordset_id'];
        $wordset_term = get_term($wordset_id, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset_term);

        $_GET = [];
        $_POST = [
            'll_wordset_manager_editor_action' => 'move_category',
            'll_wordset_manager_editor_wordset_id' => (string) $wordset_id,
            'll_wordset_manager_editor_nonce' => wp_create_nonce('ll_wordset_manager_editor_' . $wordset_id),
            'll_wordset_editor_all_filtered' => '1',
            'll_editor_q' => 'Alpha Translation',
            'll_editor_sort' => 'word',
            'll_editor_dir' => 'asc',
            'll_wordset_editor_target_category' => (string) $fixture['category_b_id'],
            'll_wordset_tool' => 'editor',
        ];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = $this->requestUriFromUrl(ll_tools_get_wordset_settings_tool_url($wordset_term, 'editor'));
        set_query_var('ll_wordset_page', (string) $wordset_term->slug);
        set_query_var('ll_wordset_view', 'settings');

        $redirect = $this->captureRedirect(static function (): void {
            ll_tools_wordset_page_handle_manager_editor_action();
        });

        $query = $this->parseRedirectQuery($redirect);
        $this->assertSame('move_category', (string) ($query['ll_wordset_manager_editor_result'] ?? ''));
        $this->assertSame('1', (string) ($query['ll_wordset_manager_editor_count'] ?? ''));
        $this->assertSame('Alpha Translation', (string) ($query['ll_editor_q'] ?? ''));

        $alpha_categories = array_map('intval', wp_get_post_terms((int) $fixture['alpha_word_id'], 'word-category', ['fields' => 'ids']));
        $beta_categories = array_map('intval', wp_get_post_terms((int) $fixture['beta_word_id'], 'word-category', ['fields' => 'ids']));
        $this->assertContains((int) $fixture['category_b_id'], $alpha_categories);
        $this->assertNotContains((int) $fixture['category_a_id'], $alpha_categories);
        $this->assertContains((int) $fixture['category_a_id'], $beta_categories);
        $this->assertNotContains((int) $fixture['category_b_id'], $beta_categories);
    }

    public function test_bulk_category_move_logs_undo_and_restores_previous_category(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $fixture = $this->createFixture('wordset-editor-category');
        $wordset_id = (int) $fixture['wordset_id'];
        $wordset_term = get_term($wordset_id, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset_term);

        $_POST = [
            'll_wordset_manager_editor_action' => 'move_category',
            'll_wordset_manager_editor_wordset_id' => (string) $wordset_id,
            'll_wordset_manager_editor_nonce' => wp_create_nonce('ll_wordset_manager_editor_' . $wordset_id),
            'll_wordset_editor_word_ids' => [(string) $fixture['alpha_word_id']],
            'll_wordset_editor_target_category' => (string) $fixture['category_b_id'],
            'll_wordset_tool' => 'editor',
        ];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = $this->requestUriFromUrl(ll_tools_get_wordset_settings_tool_url($wordset_term, 'editor'));
        set_query_var('ll_wordset_page', (string) $wordset_term->slug);
        set_query_var('ll_wordset_view', 'settings');

        $move_redirect = $this->captureRedirect(static function (): void {
            ll_tools_wordset_page_handle_manager_editor_action();
        });

        $move_query = $this->parseRedirectQuery($move_redirect);
        $this->assertSame('ok', (string) ($move_query['ll_wordset_manager_editor'] ?? ''));
        $this->assertContains((int) $fixture['category_b_id'], array_map('intval', wp_get_post_terms((int) $fixture['alpha_word_id'], 'word-category', ['fields' => 'ids'])));

        $recent = ll_tools_wordset_editor_get_recent_actions($wordset_id, 1);
        $this->assertCount(1, $recent);
        $this->assertSame('bulk_categories', (string) ($recent[0]['type'] ?? ''));

        $_POST = [
            'll_wordset_manager_editor_action' => 'undo',
            'll_wordset_manager_editor_wordset_id' => (string) $wordset_id,
            'll_wordset_manager_editor_nonce' => wp_create_nonce('ll_wordset_manager_editor_' . $wordset_id),
            'll_wordset_editor_action_id' => (string) ($recent[0]['id'] ?? ''),
            'll_wordset_tool' => 'editor',
        ];
        $_SERVER['REQUEST_METHOD'] = 'POST';

        $undo_redirect = $this->captureRedirect(static function (): void {
            ll_tools_wordset_page_handle_manager_editor_action();
        });

        $undo_query = $this->parseRedirectQuery($undo_redirect);
        $this->assertSame('undo', (string) ($undo_query['ll_wordset_manager_editor_result'] ?? ''));
        $category_ids = array_map('intval', wp_get_post_terms((int) $fixture['alpha_word_id'], 'word-category', ['fields' => 'ids']));
        $this->assertContains((int) $fixture['category_a_id'], $category_ids);
        $this->assertNotContains((int) $fixture['category_b_id'], $category_ids);
    }

    public function test_missing_audio_review_adds_internal_note_and_is_undoable(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $fixture = $this->createFixture('wordset-editor-review');
        $wordset_id = (int) $fixture['wordset_id'];
        $wordset_term = get_term($wordset_id, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset_term);

        $_POST = [
            'll_wordset_manager_editor_action' => 'missing_audio_review',
            'll_wordset_manager_editor_wordset_id' => (string) $wordset_id,
            'll_wordset_manager_editor_nonce' => wp_create_nonce('ll_wordset_manager_editor_' . $wordset_id),
            'll_wordset_editor_word_ids' => [(string) $fixture['beta_word_id']],
            'll_wordset_tool' => 'editor',
        ];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = $this->requestUriFromUrl(ll_tools_get_wordset_settings_tool_url($wordset_term, 'editor'));
        set_query_var('ll_wordset_page', (string) $wordset_term->slug);
        set_query_var('ll_wordset_view', 'settings');

        $review_redirect = $this->captureRedirect(static function (): void {
            ll_tools_wordset_page_handle_manager_editor_action();
        });

        $review_query = $this->parseRedirectQuery($review_redirect);
        $this->assertSame('missing_audio_review', (string) ($review_query['ll_wordset_manager_editor_result'] ?? ''));
        $this->assertStringContainsString('Missing audio review', ll_tools_get_internal_review_note((int) $fixture['beta_word_id']));
        $this->assertSame('draft', get_post_status((int) $fixture['beta_word_id']));

        $recent = ll_tools_wordset_editor_get_recent_actions($wordset_id, 1);
        $this->assertSame('bulk_missing_audio_review', (string) ($recent[0]['type'] ?? ''));

        $_POST = [
            'll_wordset_manager_editor_action' => 'undo',
            'll_wordset_manager_editor_wordset_id' => (string) $wordset_id,
            'll_wordset_manager_editor_nonce' => wp_create_nonce('ll_wordset_manager_editor_' . $wordset_id),
            'll_wordset_editor_action_id' => (string) ($recent[0]['id'] ?? ''),
            'll_wordset_tool' => 'editor',
        ];

        $this->captureRedirect(static function (): void {
            ll_tools_wordset_page_handle_manager_editor_action();
        });

        $this->assertSame('', ll_tools_get_internal_review_note((int) $fixture['beta_word_id']));
    }

    public function test_missing_image_review_adds_internal_note_and_is_undoable(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $fixture = $this->createFixture('wordset-editor-image-review');
        $wordset_id = (int) $fixture['wordset_id'];
        $wordset_term = get_term($wordset_id, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset_term);

        $_POST = [
            'll_wordset_manager_editor_action' => 'missing_image_review',
            'll_wordset_manager_editor_wordset_id' => (string) $wordset_id,
            'll_wordset_manager_editor_nonce' => wp_create_nonce('ll_wordset_manager_editor_' . $wordset_id),
            'll_wordset_editor_word_ids' => [(string) $fixture['alpha_word_id']],
            'll_wordset_tool' => 'editor',
        ];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = $this->requestUriFromUrl(ll_tools_get_wordset_settings_tool_url($wordset_term, 'editor'));
        set_query_var('ll_wordset_page', (string) $wordset_term->slug);
        set_query_var('ll_wordset_view', 'settings');

        $review_redirect = $this->captureRedirect(static function (): void {
            ll_tools_wordset_page_handle_manager_editor_action();
        });

        $review_query = $this->parseRedirectQuery($review_redirect);
        $this->assertSame('missing_image_review', (string) ($review_query['ll_wordset_manager_editor_result'] ?? ''));
        $this->assertStringContainsString('Missing image review', ll_tools_get_internal_review_note((int) $fixture['alpha_word_id']));
        $this->assertSame('draft', get_post_status((int) $fixture['alpha_word_id']));

        $recent = ll_tools_wordset_editor_get_recent_actions($wordset_id, 1);
        $this->assertSame('bulk_missing_image_review', (string) ($recent[0]['type'] ?? ''));

        $_POST = [
            'll_wordset_manager_editor_action' => 'undo',
            'll_wordset_manager_editor_wordset_id' => (string) $wordset_id,
            'll_wordset_manager_editor_nonce' => wp_create_nonce('ll_wordset_manager_editor_' . $wordset_id),
            'll_wordset_editor_action_id' => (string) ($recent[0]['id'] ?? ''),
            'll_wordset_tool' => 'editor',
        ];

        $undo_redirect = $this->captureRedirect(static function (): void {
            ll_tools_wordset_page_handle_manager_editor_action();
        });

        $undo_query = $this->parseRedirectQuery($undo_redirect);
        $this->assertSame('undo', (string) ($undo_query['ll_wordset_manager_editor_result'] ?? ''));
        $this->assertSame('', ll_tools_get_internal_review_note((int) $fixture['alpha_word_id']));
        $this->assertSame('publish', get_post_status((int) $fixture['alpha_word_id']));
    }

    public function test_recording_move_logs_recent_action_and_is_undoable(): void
    {
        $this->loginEditor();
        $fixture = $this->createFixture('wordset-editor-recording-move');
        $wordset_id = (int) $fixture['wordset_id'];
        $wordset_term = get_term($wordset_id, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset_term);

        $_POST = [
            'll_wordset_manager_editor_action' => 'move_recording',
            'll_wordset_manager_editor_wordset_id' => (string) $wordset_id,
            'll_wordset_manager_editor_nonce' => wp_create_nonce('ll_wordset_manager_editor_' . $wordset_id),
            'll_wordset_editor_recording_id' => (string) $fixture['alpha_recording_id'],
            'll_wordset_editor_target_word_id' => (string) $fixture['beta_word_id'],
            'll_editor_recording' => 'has',
            'll_wordset_tool' => 'editor',
        ];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = $this->requestUriFromUrl(ll_tools_get_wordset_settings_tool_url($wordset_term, 'editor'));
        set_query_var('ll_wordset_page', (string) $wordset_term->slug);
        set_query_var('ll_wordset_view', 'settings');

        $move_redirect = $this->captureRedirect(static function (): void {
            ll_tools_wordset_page_handle_manager_editor_action();
        });

        $move_query = $this->parseRedirectQuery($move_redirect);
        $this->assertSame('move_recording', (string) ($move_query['ll_wordset_manager_editor_result'] ?? ''));
        $this->assertSame('has', (string) ($move_query['ll_editor_recording'] ?? ''));
        $this->assertSame((int) $fixture['beta_word_id'], (int) wp_get_post_parent_id((int) $fixture['alpha_recording_id']));

        $recent = ll_tools_wordset_editor_get_recent_actions($wordset_id, 1);
        $this->assertCount(1, $recent);
        $this->assertSame('recording_move', (string) ($recent[0]['type'] ?? ''));

        $_POST = [
            'll_wordset_manager_editor_action' => 'undo',
            'll_wordset_manager_editor_wordset_id' => (string) $wordset_id,
            'll_wordset_manager_editor_nonce' => wp_create_nonce('ll_wordset_manager_editor_' . $wordset_id),
            'll_wordset_editor_action_id' => (string) ($recent[0]['id'] ?? ''),
            'll_wordset_tool' => 'editor',
        ];

        $undo_redirect = $this->captureRedirect(static function (): void {
            ll_tools_wordset_page_handle_manager_editor_action();
        });

        $undo_query = $this->parseRedirectQuery($undo_redirect);
        $this->assertSame('undo', (string) ($undo_query['ll_wordset_manager_editor_result'] ?? ''));
        $this->assertSame((int) $fixture['alpha_word_id'], (int) wp_get_post_parent_id((int) $fixture['alpha_recording_id']));
    }

    public function test_recording_delete_moves_to_trash_and_can_be_undone(): void
    {
        $this->loginEditor();
        $fixture = $this->createFixture('wordset-editor-recording-delete');
        $wordset_id = (int) $fixture['wordset_id'];
        $wordset_term = get_term($wordset_id, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset_term);

        $_POST = [
            'll_wordset_manager_editor_action' => 'delete_recording',
            'll_wordset_manager_editor_wordset_id' => (string) $wordset_id,
            'll_wordset_manager_editor_nonce' => wp_create_nonce('ll_wordset_manager_editor_' . $wordset_id),
            'll_wordset_editor_recording_id' => (string) $fixture['alpha_recording_id'],
            'll_wordset_tool' => 'editor',
        ];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = $this->requestUriFromUrl(ll_tools_get_wordset_settings_tool_url($wordset_term, 'editor'));
        set_query_var('ll_wordset_page', (string) $wordset_term->slug);
        set_query_var('ll_wordset_view', 'settings');

        $delete_redirect = $this->captureRedirect(static function (): void {
            ll_tools_wordset_page_handle_manager_editor_action();
        });

        $delete_query = $this->parseRedirectQuery($delete_redirect);
        $this->assertSame('delete_recording', (string) ($delete_query['ll_wordset_manager_editor_result'] ?? ''));
        $this->assertSame('trash', get_post_status((int) $fixture['alpha_recording_id']));

        $recent = ll_tools_wordset_editor_get_recent_actions($wordset_id, 1);
        $this->assertCount(1, $recent);
        $this->assertSame('recording_trash', (string) ($recent[0]['type'] ?? ''));

        $_POST = [
            'll_wordset_manager_editor_action' => 'undo',
            'll_wordset_manager_editor_wordset_id' => (string) $wordset_id,
            'll_wordset_manager_editor_nonce' => wp_create_nonce('ll_wordset_manager_editor_' . $wordset_id),
            'll_wordset_editor_action_id' => (string) ($recent[0]['id'] ?? ''),
            'll_wordset_tool' => 'editor',
        ];

        $undo_redirect = $this->captureRedirect(static function (): void {
            ll_tools_wordset_page_handle_manager_editor_action();
        });

        $undo_query = $this->parseRedirectQuery($undo_redirect);
        $this->assertSame('undo', (string) ($undo_query['ll_wordset_manager_editor_result'] ?? ''));
        $this->assertSame('publish', get_post_status((int) $fixture['alpha_recording_id']));
        $this->assertSame((int) $fixture['alpha_word_id'], (int) wp_get_post_parent_id((int) $fixture['alpha_recording_id']));
    }

    /**
     * @return array{wordset_id:int,category_a_id:int,category_b_id:int,alpha_word_id:int,beta_word_id:int,alpha_recording_id:int}
     */
    private function createFixture(string $prefix): array
    {
        $wordset = wp_insert_term(ucwords(str_replace('-', ' ', $prefix)) . ' Wordset', 'wordset', ['slug' => $prefix . '-wordset']);
        $this->assertFalse(is_wp_error($wordset));
        $wordset_id = (int) ($wordset['term_id'] ?? 0);

        $category_a = wp_insert_term(ucwords(str_replace('-', ' ', $prefix)) . ' A', 'word-category', ['slug' => $prefix . '-a']);
        $category_b = wp_insert_term(ucwords(str_replace('-', ' ', $prefix)) . ' B', 'word-category', ['slug' => $prefix . '-b']);
        $this->assertFalse(is_wp_error($category_a));
        $this->assertFalse(is_wp_error($category_b));
        $category_a_id = (int) ($category_a['term_id'] ?? 0);
        $category_b_id = (int) ($category_b['term_id'] ?? 0);

        foreach ([$category_a_id, $category_b_id] as $category_id) {
            update_term_meta($category_id, 'll_quiz_prompt_type', 'audio');
            update_term_meta($category_id, 'll_quiz_option_type', 'text_translation');
            update_term_meta($category_id, 'll_desired_recording_types', ['isolation']);
            if (function_exists('ll_tools_set_category_wordset_owner')) {
                ll_tools_set_category_wordset_owner($category_id, $wordset_id, $category_id);
            }
        }

        $recording_type_id = $this->ensureTerm('recording_type', 'Isolation', 'isolation');

        $alpha_word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'draft',
            'post_title' => 'Alpha Word',
        ]);
        wp_set_object_terms($alpha_word_id, [$wordset_id], 'wordset', false);
        wp_set_object_terms($alpha_word_id, [$category_a_id], 'word-category', false);
        update_post_meta($alpha_word_id, 'word_translation', 'Alpha Translation');

        $alpha_recording_id = self::factory()->post->create([
            'post_type' => 'word_audio',
            'post_status' => 'publish',
            'post_parent' => $alpha_word_id,
            'post_title' => 'Alpha Recording',
        ]);
        wp_set_object_terms($alpha_recording_id, [$recording_type_id], 'recording_type', false);
        update_post_meta($alpha_recording_id, 'audio_file_path', '/wp-content/uploads/' . $prefix . '-alpha.wav');
        wp_update_post([
            'ID' => $alpha_word_id,
            'post_status' => 'publish',
        ]);

        $beta_word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'draft',
            'post_title' => 'Beta Word',
        ]);
        wp_set_object_terms($beta_word_id, [$wordset_id], 'wordset', false);
        wp_set_object_terms($beta_word_id, [$category_a_id], 'word-category', false);
        update_post_meta($beta_word_id, 'word_translation', 'Beta Translation');

        return [
            'wordset_id' => $wordset_id,
            'category_a_id' => $category_a_id,
            'category_b_id' => $category_b_id,
            'alpha_word_id' => (int) $alpha_word_id,
            'beta_word_id' => (int) $beta_word_id,
            'alpha_recording_id' => (int) $alpha_recording_id,
        ];
    }

    private function createImageAttachment(string $filename): int
    {
        $bytes = base64_decode(self::ONE_PIXEL_PNG_BASE64, true);
        $this->assertIsString($bytes);

        $upload = wp_upload_bits($filename, null, $bytes);
        $this->assertIsArray($upload);
        $this->assertSame('', (string) ($upload['error'] ?? ''));

        $file_path = (string) ($upload['file'] ?? '');
        $this->assertNotSame('', $file_path);
        $this->assertFileExists($file_path);

        $filetype = wp_check_filetype(basename($file_path), null);
        $attachment_id = wp_insert_attachment([
            'post_mime_type' => (string) ($filetype['type'] ?? 'image/png'),
            'post_title' => preg_replace('/\.[^.]+$/', '', basename($file_path)),
            'post_content' => '',
            'post_status' => 'inherit',
        ], $file_path);

        $this->assertIsInt($attachment_id);
        $this->assertGreaterThan(0, $attachment_id);

        $relative_path = function_exists('_wp_relative_upload_path')
            ? (string) _wp_relative_upload_path($file_path)
            : '';
        if ($relative_path === '') {
            $relative_path = ltrim((string) wp_normalize_path($file_path), '/');
        }
        update_post_meta($attachment_id, '_wp_attached_file', $relative_path);

        return (int) $attachment_id;
    }

    private function createOwnedCategory(string $slug, int $wordset_id): int
    {
        $name = ucwords(str_replace('-', ' ', $slug));
        $created = wp_insert_term($name, 'word-category', ['slug' => $slug]);
        $this->assertFalse(is_wp_error($created));
        $category_id = (int) ($created['term_id'] ?? 0);
        update_term_meta($category_id, 'll_quiz_prompt_type', 'audio');
        update_term_meta($category_id, 'll_quiz_option_type', 'text_translation');
        update_term_meta($category_id, 'll_desired_recording_types', ['isolation']);
        if (function_exists('ll_tools_set_category_wordset_owner')) {
            ll_tools_set_category_wordset_owner($category_id, $wordset_id, $category_id);
        }

        return $category_id;
    }

    private function extractCategoryFilterHtml(string $html): string
    {
        $start = strpos($html, 'data-ll-wordset-editor-category-filter');
        $this->assertIsInt($start);
        $end = strpos($html, 'name="ll_editor_status"', $start);
        $this->assertIsInt($end);

        return substr($html, $start, $end - $start);
    }

    private function ensureTerm(string $taxonomy, string $name, string $slug): int
    {
        $existing = get_term_by('slug', $slug, $taxonomy);
        if ($existing instanceof WP_Term) {
            return (int) $existing->term_id;
        }

        $created = wp_insert_term($name, $taxonomy, ['slug' => $slug]);
        $this->assertFalse(is_wp_error($created));
        return (int) ($created['term_id'] ?? 0);
    }

    private function loginEditor(): void
    {
        if (function_exists('ll_create_ll_tools_editor_role')) {
            ll_create_ll_tools_editor_role();
        }
        $role = get_role('ll_tools_editor') ? 'll_tools_editor' : 'administrator';
        $editor_id = self::factory()->user->create(['role' => $role]);
        $editor = get_user_by('id', $editor_id);
        $this->assertInstanceOf(WP_User::class, $editor);
        $editor->add_cap('view_ll_tools');
        clean_user_cache($editor_id);
        wp_set_current_user($editor_id);
    }

    private function requestUriFromUrl(string $url): string
    {
        $path = (string) wp_parse_url($url, PHP_URL_PATH);
        $query = (string) wp_parse_url($url, PHP_URL_QUERY);

        return $path . ($query !== '' ? ('?' . $query) : '');
    }

    /**
     * @return array<string,string>
     */
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
