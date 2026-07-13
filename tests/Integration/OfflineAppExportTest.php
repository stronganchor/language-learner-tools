<?php
declare(strict_types=1);

final class OfflineAppExportTest extends LL_Tools_TestCase
{
    private const ONE_PIXEL_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8Xw8AAoMBgQf4xX0AAAAASUVORK5CYII=';

    public function test_offline_app_export_capability_defaults_to_manage_options_but_can_be_filtered(): void
    {
        ll_tools_register_or_refresh_audio_recorder_role();

        $recorder_id = self::factory()->user->create(['role' => 'audio_recorder']);
        $admin_id = self::factory()->user->create(['role' => 'administrator']);

        wp_set_current_user($recorder_id);
        $this->assertSame('manage_options', ll_tools_get_offline_app_export_capability());
        $this->assertFalse(ll_tools_current_user_can_offline_app_export());

        wp_set_current_user($admin_id);
        $this->assertTrue(ll_tools_current_user_can_offline_app_export());

        $filter = static function (): string {
            return 'view_ll_tools';
        };
        add_filter('ll_tools_offline_app_export_capability', $filter);

        try {
            wp_set_current_user($recorder_id);
            $this->assertSame('view_ll_tools', ll_tools_get_offline_app_export_capability());
            $this->assertTrue(ll_tools_current_user_can_offline_app_export());
        } finally {
            remove_filter('ll_tools_offline_app_export_capability', $filter);
        }
    }

    public function test_wordset_manager_export_starts_and_resumes_the_owned_bounded_job(): void
    {
        if (!class_exists('ZipArchive')) {
            $this->markTestSkipped('ZipArchive is required for offline export job coverage.');
        }

        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        $other_admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $wordset = wp_insert_term('Manager Offline Wordset ' . wp_generate_password(6, false), 'wordset');
        $category = wp_insert_term('Manager Offline Category ' . wp_generate_password(6, false), 'word-category');
        $this->assertIsArray($wordset);
        $this->assertIsArray($category);
        $wordset_id = (int) $wordset['term_id'];
        $category_id = (int) $category['term_id'];

        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Manager Offline Word',
        ]);
        wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);
        wp_set_post_terms($word_id, [$category_id], 'word-category', false);
        update_post_meta($word_id, 'word_translation', 'Manager Offline Translation');

        $options = ll_tools_offline_app_get_wordset_category_options($wordset_id);
        $this->assertNotEmpty($options);
        $export_category_id = (int) ($options[0]['id'] ?? 0);
        $this->assertGreaterThan(0, $export_category_id);

        $wordset_term = get_term($wordset_id, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset_term);
        $request = [
            'll_wordset_manager_offline_wordset_id' => (string) $wordset_id,
            'll_wordset_manager_offline_nonce' => wp_create_nonce('ll_wordset_manager_offline_export_' . $wordset_id),
            'll_offline_wordset_id' => (string) $wordset_id,
            'll_offline_category_scope' => 'custom',
            'll_offline_category_ids' => [(string) $export_category_id],
            'll_offline_app_name' => 'Manager Offline App',
            'll_offline_version_name' => '1.0.0',
            'll_offline_version_code' => '1',
            'll_offline_app_id_suffix' => 'tests.manager.offline',
        ];

        $full_builder_started = false;
        $full_builder_listener = static function () use (&$full_builder_started): void {
            $full_builder_started = true;
        };
        add_action('ll_tools_offline_app_full_builder_started', $full_builder_listener);

        $token = '';
        $original_get = $_GET;
        try {
            $start = ll_tools_wordset_page_prepare_manager_offline_export_job($wordset_term, $request);
            $this->assertIsArray($start, is_wp_error($start) ? $start->get_error_message() : '');
            $this->assertSame('queued', (string) ($start['status'] ?? ''));
            $this->assertSame('categories', (string) ($start['phase'] ?? ''));
            $this->assertFalse($full_builder_started, 'The manager start request must not invoke the full bundle builder.');
            $token = (string) ($start['token'] ?? '');
            $this->assertNotSame('', $token);

            $step = ll_tools_wordset_page_continue_manager_offline_export_job($wordset_term, array_merge($request, [
                'll_wordset_manager_offline_job_token' => $token,
            ]));
            $this->assertIsArray($step, is_wp_error($step) ? $step->get_error_message() : '');
            $this->assertLessThanOrEqual(ll_tools_offline_app_export_category_batch_size(), (int) (($step['batch'] ?? [])['categories'] ?? 0));
            $this->assertFalse($full_builder_started, 'The manager continuation must use the bounded job step.');

            wp_set_current_user($other_admin_id);
            $foreign_job = ll_tools_wordset_page_get_manager_offline_export_job($token, $wordset_id);
            $this->assertWPError($foreign_job);
            $this->assertSame('ll_tools_offline_app_job_forbidden', $foreign_job->get_error_code());

            wp_set_current_user($admin_id);
            $_GET['ll_offline_job'] = $token;
            $html = ll_tools_wordset_page_render_settings_offline_app_tool(
                $wordset_term,
                $wordset_id,
                '',
                $options,
                true
            );
            $this->assertStringContainsString('data-ll-wordset-offline-export-form', $html);
            $this->assertStringContainsString('name="ll_offline_category_scope" value="custom"', $html);
            $this->assertStringContainsString('data-ll-wordset-offline-export-job', $html);
            $this->assertStringContainsString('data-ll-wordset-offline-export-progress', $html);
            $this->assertStringContainsString('name="ll_wordset_manager_offline_job_token" value="' . esc_attr($token) . '"', $html);
            $this->assertTrue(wp_script_is('ll-wordset-offline-export-js', 'enqueued'));
            $this->assertTrue(wp_style_is('ll-wordset-offline-export-css', 'enqueued'));
            $localized = (string) wp_scripts()->get_data('ll-wordset-offline-export-js', 'data');
            $this->assertStringContainsString($token, $localized);
        } finally {
            $_GET = $original_get;
            wp_set_current_user($admin_id);
            remove_action('ll_tools_offline_app_full_builder_started', $full_builder_listener);
            wp_dequeue_script('ll-wordset-offline-export-js');
            wp_dequeue_style('ll-wordset-offline-export-css');
            if ($token !== '') {
                ll_tools_offline_app_export_delete_job($token);
            }
        }
    }

    public function test_offline_app_category_options_are_filtered_to_selected_wordset_content(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $wordset_a = wp_insert_term('Offline UI Wordset A ' . wp_generate_password(6, false), 'wordset');
        $this->assertFalse(is_wp_error($wordset_a));
        $this->assertIsArray($wordset_a);
        $wordset_a_id = (int) $wordset_a['term_id'];

        $wordset_b = wp_insert_term('Offline UI Wordset B ' . wp_generate_password(6, false), 'wordset');
        $this->assertFalse(is_wp_error($wordset_b));
        $this->assertIsArray($wordset_b);
        $wordset_b_id = (int) $wordset_b['term_id'];

        $category_a = wp_insert_term('Offline UI Category A ' . wp_generate_password(6, false), 'word-category');
        $this->assertFalse(is_wp_error($category_a));
        $this->assertIsArray($category_a);
        $category_a_id = (int) $category_a['term_id'];

        $category_b = wp_insert_term('Offline UI Category B ' . wp_generate_password(6, false), 'word-category');
        $this->assertFalse(is_wp_error($category_b));
        $this->assertIsArray($category_b);
        $category_b_id = (int) $category_b['term_id'];

        $category_draft_only = wp_insert_term('Offline UI Draft Only ' . wp_generate_password(6, false), 'word-category');
        $this->assertFalse(is_wp_error($category_draft_only));
        $this->assertIsArray($category_draft_only);
        $category_draft_only_id = (int) $category_draft_only['term_id'];

        $word_a = self::factory()->post->create([
            'post_type'   => 'words',
            'post_status' => 'draft',
            'post_title'  => 'Offline UI Word A',
        ]);
        wp_set_post_terms($word_a, [$category_a_id], 'word-category', false);
        wp_set_post_terms($word_a, [$wordset_a_id], 'wordset', false);
        wp_update_post([
            'ID'          => $word_a,
            'post_status' => 'publish',
        ]);

        $word_b = self::factory()->post->create([
            'post_type'   => 'words',
            'post_status' => 'draft',
            'post_title'  => 'Offline UI Word B',
        ]);
        wp_set_post_terms($word_b, [$category_b_id], 'word-category', false);
        wp_set_post_terms($word_b, [$wordset_b_id], 'wordset', false);
        wp_update_post([
            'ID'          => $word_b,
            'post_status' => 'publish',
        ]);

        $draft_word = self::factory()->post->create([
            'post_type'   => 'words',
            'post_status' => 'draft',
            'post_title'  => 'Offline UI Draft Word',
        ]);
        wp_set_post_terms($draft_word, [$category_draft_only_id], 'word-category', false);
        wp_set_post_terms($draft_word, [$wordset_a_id], 'wordset', false);

        $category_a_id = $this->getOfflineWordCategoryId($word_a) ?: $category_a_id;
        $category_b_id = $this->getOfflineWordCategoryId($word_b) ?: $category_b_id;
        $category_draft_only_id = $this->getOfflineWordCategoryId($draft_word) ?: $category_draft_only_id;
        $category_a_id = $this->applyOfflineWordsetCategoryConfig($category_a_id, $wordset_a_id, 'text_title', 'text_title');
        $category_b_id = $this->applyOfflineWordsetCategoryConfig($category_b_id, $wordset_b_id, 'text_title', 'text_title');
        $category_draft_only_id = $this->applyOfflineWordsetCategoryConfig($category_draft_only_id, $wordset_a_id, 'text_title', 'text_title');

        $options = ll_tools_offline_app_get_wordset_category_options($wordset_a_id);
        $option_ids = array_values(array_filter(array_map(static function ($row): int {
            return is_array($row) ? (int) ($row['id'] ?? 0) : 0;
        }, $options), static function (int $category_id): bool {
            return $category_id > 0;
        }));
        sort($option_ids, SORT_NUMERIC);

        $this->assertSame([$category_a_id], $option_ids);
    }

    public function test_offline_export_page_loads_categories_only_for_selected_wordset(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $wordset_ids = [];
        $category_names = [];
        foreach (range(1, 2) as $index) {
            $wordset_insert = wp_insert_term('Lazy Offline Wordset ' . $index, 'wordset');
            $category_insert = wp_insert_term('Lazy Offline Category ' . $index, 'word-category');
            $this->assertIsArray($wordset_insert);
            $this->assertIsArray($category_insert);
            $wordset_id = (int) $wordset_insert['term_id'];
            $category_id = (int) $category_insert['term_id'];
            $wordset_ids[] = $wordset_id;
            $category_names[] = (string) get_term($category_id, 'word-category')->name;

            $word_id = self::factory()->post->create([
                'post_type' => 'words',
                'post_status' => 'publish',
                'post_title' => 'Lazy Offline Word ' . $index,
            ]);
            wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);
            wp_set_post_terms($word_id, [$category_id], 'word-category', false);
        }

        ob_start();
        ll_tools_render_offline_app_export_page();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('const categoriesByWordset = {};', $html);
        $this->assertStringContainsString('ll_tools_offline_app_export_categories', $html);
        foreach ($category_names as $category_name) {
            $this->assertStringNotContainsString($category_name, $html);
        }

        $_POST = [
            'nonce' => wp_create_nonce('ll_tools_offline_app_export_categories'),
            'wordset_id' => (string) $wordset_ids[0],
        ];
        $_REQUEST = $_POST;
        $response = $this->runJsonEndpoint(static function (): void {
            ll_tools_ajax_offline_app_export_categories();
        });

        $this->assertTrue((bool) ($response['success'] ?? false));
        $categories = (array) (($response['data'] ?? [])['categories'] ?? []);
        $this->assertCount(1, $categories);
        $this->assertSame($category_names[0], (string) ($categories[0]['name'] ?? ''));
    }

    public function test_offline_app_export_bundles_effective_word_image_when_word_lacks_thumbnail(): void
    {
        $wordset = wp_insert_term('Offline Effective Image Wordset ' . wp_generate_password(6, false), 'wordset');
        $this->assertFalse(is_wp_error($wordset));
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];

        $category = wp_insert_term('Offline Effective Image Category ' . wp_generate_password(6, false), 'word-category');
        $this->assertFalse(is_wp_error($category));
        $this->assertIsArray($category);
        $category_id = (int) $category['term_id'];

        $attachment_id = $this->create_image_attachment('offline-effective-word-image.png');
        $word_image_id = self::factory()->post->create([
            'post_type'   => 'word_images',
            'post_status' => 'publish',
            'post_title'  => 'Offline Effective Image Source',
        ]);
        set_post_thumbnail($word_image_id, $attachment_id);
        wp_set_post_terms($word_image_id, [$category_id], 'word-category', false);

        $word_id = self::factory()->post->create([
            'post_type'   => 'words',
            'post_status' => 'publish',
            'post_title'  => 'Offline Effective Image Word',
        ]);
        wp_set_post_terms($word_id, [$category_id], 'word-category', false);
        wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);
        update_post_meta($word_id, '_ll_autopicked_image_id', $word_image_id);
        delete_post_meta($word_id, '_thumbnail_id');

        $registry = ['images' => []];
        $entries = [];
        $warnings = [];
        $relative_image = ll_tools_offline_app_register_word_image_asset($word_id, $registry, $entries, $warnings);

        $this->assertNotSame('', $relative_image);
        $this->assertStringContainsString('./content/images/' . $attachment_id . '-', $relative_image);
        $this->assertCount(1, $entries);
        $this->assertSame([], $warnings);

        $preview = ll_tools_offline_app_build_launcher_preview([
            [
                'id' => $word_id,
                'title' => 'Offline Effective Image Word',
                'translation' => 'Offline effective translation',
                'image' => $relative_image,
            ],
        ]);

        $this->assertSame('image', (string) ($preview[0]['type'] ?? ''));
        $this->assertSame($relative_image, (string) ($preview[0]['url'] ?? ''));
    }

    public function test_offline_app_bundle_includes_shell_runtime_data_and_local_media(): void
    {
        $min_words_filter = static function (): int {
            return 1;
        };
        add_filter('ll_tools_quiz_min_words', $min_words_filter);

        try {
            $admin_id = self::factory()->user->create(['role' => 'administrator']);
            wp_set_current_user($admin_id);

            $wordset_term = wp_insert_term('Offline Bundle Wordset ' . wp_generate_password(6, false), 'wordset');
            $this->assertFalse(is_wp_error($wordset_term));
            $this->assertIsArray($wordset_term);
            $wordset_id = (int) $wordset_term['term_id'];
            update_term_meta($wordset_id, 'll_wordset_has_gender', '1');

            $category_term = wp_insert_term('Offline Bundle Category ' . wp_generate_password(6, false), 'word-category');
            $this->assertFalse(is_wp_error($category_term));
            $this->assertIsArray($category_term);
            $category_id = (int) $category_term['term_id'];

            $recording_term = wp_insert_term('Isolation', 'recording_type', ['slug' => 'isolation']);
            if (is_wp_error($recording_term)) {
                $existing = get_term_by('slug', 'isolation', 'recording_type');
                $this->assertInstanceOf(WP_Term::class, $existing);
                $recording_type_id = (int) $existing->term_id;
            } else {
                $this->assertIsArray($recording_term);
                $recording_type_id = (int) $recording_term['term_id'];
            }

            $part_of_speech_term = wp_insert_term('Noun', 'part_of_speech', ['slug' => 'noun']);
            if (is_wp_error($part_of_speech_term)) {
                $existing_pos = get_term_by('slug', 'noun', 'part_of_speech');
                $this->assertInstanceOf(WP_Term::class, $existing_pos);
                $part_of_speech_id = (int) $existing_pos->term_id;
            } else {
                $this->assertIsArray($part_of_speech_term);
                $part_of_speech_id = (int) $part_of_speech_term['term_id'];
            }

            $image_attachment_id = $this->create_image_attachment('offline-export-word-image.png');

            $word_id = self::factory()->post->create([
                'post_type'   => 'words',
                'post_status' => 'draft',
                'post_title'  => 'Offline Export Word',
            ]);
            wp_set_post_terms($word_id, [$category_id], 'word-category', false);
            wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);
            wp_set_post_terms($word_id, [$part_of_speech_id], 'part_of_speech', false);
            set_post_thumbnail($word_id, $image_attachment_id);
            update_post_meta($word_id, 'word_translation', 'Offline Export Translation');
            update_post_meta($word_id, 'll_grammatical_gender', 'masculine');

            $audio_path = $this->create_audio_upload_file('offline-export-word.mp3');
            $audio_post_id = self::factory()->post->create([
                'post_type'   => 'word_audio',
                'post_status' => 'publish',
                'post_parent' => $word_id,
                'post_title'  => 'Offline Export Audio',
            ]);
            update_post_meta($audio_post_id, 'audio_file_path', $audio_path);
            update_post_meta($audio_post_id, 'recording_text', 'Offline Export Word');
            update_post_meta($audio_post_id, 'recording_ipa', 'ɔflaɪn');
            wp_set_post_terms($audio_post_id, [$recording_type_id], 'recording_type', false);

            update_term_meta($wordset_id, LL_TOOLS_WORDSET_LOCAL_TRANSCRIPTION_ENDPOINT_META_KEY, 'http://127.0.0.1:8765/transcribe');
            update_term_meta($wordset_id, LL_TOOLS_WORDSET_LOCAL_TRANSCRIPTION_TARGET_META_KEY, 'recording_ipa');
            update_term_meta($wordset_id, LL_TOOLS_WORDSET_SPEAKING_GAME_ENABLED_META_KEY, '1');
            update_term_meta($wordset_id, LL_TOOLS_WORDSET_SPEAKING_GAME_PROVIDER_META_KEY, 'local_browser');
            update_term_meta($wordset_id, LL_TOOLS_WORDSET_SPEAKING_GAME_TARGET_META_KEY, 'recording_ipa');
            $offline_stt_bundle_path = $this->create_offline_stt_bundle_dir('offline-stt-bundle');
            update_term_meta($wordset_id, LL_TOOLS_WORDSET_OFFLINE_STT_BUNDLE_PATH_META_KEY, $offline_stt_bundle_path);

            wp_update_post([
                'ID'          => $word_id,
                'post_status' => 'publish',
            ]);

            $category_id = $this->getOfflineWordCategoryId($word_id) ?: $category_id;
            $this->applyOfflineWordsetCategoryConfig($category_id, $wordset_id, 'audio', 'text_translation');

            for ($index = 2; $index <= 5; $index += 1) {
                $extra_word_id = $this->createPublishedOfflineBundleWord(
                    'Offline Export Word ' . $index,
                    'Offline Export Translation ' . $index,
                    $category_id,
                    $wordset_id,
                    $recording_type_id,
                    'offline-export-word-image-' . $index . '.png',
                    'offline-export-word-' . $index . '.mp3'
                );
                $extra_audio_posts = get_posts([
                    'post_type' => 'word_audio',
                    'post_status' => 'publish',
                    'post_parent' => $extra_word_id,
                    'posts_per_page' => 1,
                    'orderby' => 'ID',
                    'order' => 'DESC',
                    'suppress_filters' => true,
                    'no_found_rows' => true,
                ]);
                $this->assertNotEmpty($extra_audio_posts);
                $extra_audio_post = $extra_audio_posts[0] ?? null;
                $this->assertInstanceOf(WP_Post::class, $extra_audio_post);
                update_post_meta((int) $extra_audio_post->ID, 'recording_ipa', 'ɔflaɪn' . $index);
            }

            $term = get_term($category_id, 'word-category');
            $this->assertInstanceOf(WP_Term::class, $term);
            $resolved_config = ll_tools_resolve_effective_category_quiz_config($term, 1, [$wordset_id]);
            $rows_with_wordset_scope = ll_get_words_by_category(
                (string) $term->name,
                'text_translation',
                [$wordset_id],
                [
                    'prompt_type' => 'audio',
                    'option_type' => 'text_translation',
                ]
            );
            $rows_with_resolved_config = ll_get_words_by_category(
                (string) $term->name,
                (string) ($resolved_config['option_type'] ?? 'text_translation'),
                [$wordset_id],
                $resolved_config
            );

            $this->assertSame(
                5,
                ll_get_words_by_category_count(
                    (string) $term->name,
                    'text_translation',
                    [$wordset_id],
                    [
                        'prompt_type' => 'audio',
                        'option_type' => 'text_translation',
                    ]
                ),
                'Expected the wordset-scoped category count helper to find the test word.'
            );
            $this->assertCount(
                5,
                $rows_with_wordset_scope,
                'Expected the wordset-scoped quiz rows to include the test word.'
            );
            $this->assertCount(5, $rows_with_resolved_config, 'Expected the resolved category config to preserve the test word.');

            $bundle = ll_tools_build_offline_app_bundle([
                'wordset_id'    => $wordset_id,
                'category_ids'  => [$category_id],
                'app_name'      => 'Offline Bundle App',
                'version_name'  => '1.2.3',
                'version_code'  => 7,
                'app_id_suffix' => 'tests.offline.bundle',
            ]);

            $this->assertFalse(is_wp_error($bundle), is_wp_error($bundle) ? $bundle->get_error_message() : '');
            $this->assertIsArray($bundle);

            $zip_path = (string) ($bundle['zip_path'] ?? '');
            $staging_dir = (string) ($bundle['staging_dir'] ?? '');
            $this->assertNotSame('', $zip_path);
            $this->assertFileExists($zip_path);

            try {
                $zip = new ZipArchive();
                $this->assertTrue($zip->open($zip_path) === true);

                $this->assertNotFalse($zip->locateName('bundle-manifest.json'));
                $this->assertNotFalse($zip->locateName('README.txt'));
                $this->assertNotFalse($zip->locateName('www/index.html'));
                $this->assertNotFalse($zip->locateName('www/data/offline-data.js'));
                $this->assertNotFalse($zip->locateName('www/app/offline-app.js'));
                $this->assertNotFalse($zip->locateName('www/vendor/jquery/jquery.min.js'));
                $this->assertNotFalse($zip->locateName('www/plugin/js/flashcard-widget/loader.js'));
                $this->assertNotFalse($zip->locateName('www/plugin/js/wordset-games.js'));
                $this->assertNotFalse($zip->locateName('www/plugin/js/flashcard-widget/audio-visualizer.js'));
                $this->assertNotFalse($zip->locateName('www/plugin/js/flashcard-widget/modes/listening.js'));
                $this->assertNotFalse($zip->locateName('www/plugin/js/flashcard-widget/modes/self-check.js'));
                $this->assertNotFalse($zip->locateName('www/plugin/js/flashcard-widget/modes/gender.js'));
                $this->assertNotFalse($zip->locateName('www/plugin/js/self-check-shared.js'));
                $this->assertNotFalse($zip->locateName('www/plugin/css/wordset-games.css'));
                $this->assertNotFalse($zip->locateName('www/plugin/css/flashcard/mode-listening.css'));
                $this->assertNotFalse($zip->locateName('www/plugin/css/flashcard/mode-gender.css'));
                $this->assertNotFalse($zip->locateName('www/plugin/css/self-check-shared.css'));
                $this->assertNotFalse($zip->locateName('www/plugin/media/space-shooter-correct-hit.mp3'));
                $this->assertNotFalse($zip->locateName('www/plugin/media/space-shooter-wrong-hit.mp3'));
                $this->assertNotFalse($zip->locateName('www/plugin/media/bubble-pop.mp3'));

                $entry_names = [];
                for ($index = 0; $index < $zip->numFiles; $index++) {
                    $stat = $zip->statIndex($index);
                    if (is_array($stat) && isset($stat['name'])) {
                        $entry_names[] = (string) $stat['name'];
                    }
                }

                $offline_data = $zip->getFromName('www/data/offline-data.js');
                $this->assertIsString($offline_data);
                $this->assertStringContainsString('"runtimeMode":"offline"', $offline_data);
                $this->assertStringContainsString('"availableModes":["learning","practice","listening","self-check","gender"]', $offline_data);
                $this->assertStringContainsString('"offlineSync":{"enabled":true', $offline_data);
                $this->assertStringContainsString('"loginAction":"ll_tools_offline_app_login"', $offline_data);
                $this->assertStringContainsString('"logoutAction":"ll_tools_offline_app_logout"', $offline_data);
                $this->assertStringContainsString('"syncAction":"ll_tools_offline_app_sync"', $offline_data);
                $this->assertStringContainsString('admin-ajax.php', $offline_data);
                $this->assertStringContainsString('./content/images/', $offline_data);
                $this->assertStringContainsString('./content/audio/', $offline_data);
                $this->assertStringContainsString('"launcher":{"categories":[', $offline_data);
                $this->assertStringContainsString('"preview":[', $offline_data);
                $this->assertStringContainsString('"label":"Offline Export Translation', $offline_data);
                $this->assertStringContainsString('"preview_limit":4', $offline_data);
                $this->assertStringContainsString('"preview_aspect_ratio":"', $offline_data);
                $this->assertStringContainsString('"genderEnabled":true', $offline_data);
                $this->assertStringContainsString('"gender_supported":true', $offline_data);
                $this->assertStringContainsString('"genderOptions":["', $offline_data);
                $this->assertStringContainsString('"games":{', $offline_data);
                $this->assertStringContainsString('"runtimeMode":"offline"', $offline_data);
                $this->assertStringContainsString('"./plugin/media/space-shooter-correct-hit.mp3"', $offline_data);
                $this->assertStringContainsString('"./plugin/media/bubble-pop.mp3"', $offline_data);
                $this->assertStringNotContainsString('"speaking-practice":{"slug":"speaking-practice"', $offline_data);
                $this->assertStringNotContainsString('"speaking-stack":{"slug":"speaking-stack"', $offline_data);
                $this->assertStringNotContainsString('"embedded_model"', $offline_data);
                $this->assertStringNotContainsString('"offline_stt"', $offline_data);
                $this->assertStringNotContainsString('"speechToText"', $offline_data);

                $index_html = $zip->getFromName('www/index.html');
                $this->assertIsString($index_html);
                $offline_app_js = $zip->getFromName('www/app/offline-app.js');
                $this->assertIsString($offline_app_js);
                $manifest_json = $zip->getFromName('bundle-manifest.json');
                $this->assertIsString($manifest_json);
                $this->assertStringContainsString('id="ll-offline-category-grid"', $index_html);
                $this->assertStringContainsString('class="ll-wordset-grid"', $index_html);
                $this->assertStringContainsString('id="ll-offline-next-card"', $index_html);
                $this->assertStringContainsString('data-ll-offline-next', $index_html);
                $this->assertStringContainsString('data-ll-offline-category-search', $index_html);
                $this->assertStringContainsString('data-ll-offline-sort-toggle', $index_html);
                $this->assertStringContainsString('data-ll-offline-sort-option="progress-desc"', $index_html);
                $this->assertStringContainsString('id="ll-offline-select-all"', $index_html);
                $this->assertStringContainsString('id="ll-offline-selection-bar"', $index_html);
                $this->assertStringContainsString('data-ll-offline-view-toggle', $index_html);
                $this->assertStringContainsString('data-ll-offline-view="games"', $index_html);
                $this->assertStringContainsString('data-ll-wordset-games-root', $index_html);
                $this->assertStringContainsString('data-ll-offline-launch-selected', $index_html);
                $this->assertStringContainsString('id="ll-offline-sync-panel"', $index_html);
                $this->assertStringContainsString('id="ll-offline-sync-sheet"', $index_html);
                $this->assertStringContainsString('id="ll-offline-sync-connect"', $index_html);
                $this->assertStringContainsString('id="ll-offline-sync-password-toggle"', $index_html);
                $this->assertStringContainsString('Speaking Practice is temporarily disabled in offline app exports.', $index_html);
                $this->assertStringContainsString('src="./app/offline-app.js"', $index_html);
                $this->assertStringContainsString('href="./plugin/css/language-learner-tools.css"', $index_html);
                $this->assertStringContainsString('href="./plugin/css/wordset-pages.css"', $index_html);
                $this->assertStringContainsString('href="./plugin/css/wordset-games.css"', $index_html);
                $this->assertStringContainsString('href="./plugin/css/flashcard/mode-listening.css"', $index_html);
                $this->assertStringContainsString('href="./plugin/css/flashcard/mode-gender.css"', $index_html);
                $this->assertStringNotContainsString('http://./', $index_html);
                $this->assertStringNotContainsString('id="ll-tools-start-flashcard"', $index_html);
                $this->assertStringContainsString('data-ll-offline-category-mode', $offline_app_js);
                $this->assertStringContainsString('buildCategoryProgressMarkup', $offline_app_js);
                $this->assertStringContainsString('data-ll-offline-sort-option', $offline_app_js);
                $this->assertStringContainsString('buildOfflineSpeakingBridge', $offline_app_js);
                $this->assertStringContainsString('id="restart-self-check-mode"', $index_html);
                $this->assertStringContainsString('id="restart-listening-mode"', $index_html);
                $this->assertStringContainsString('id="restart-gender-mode"', $index_html);
                $this->assertStringNotContainsString('id="ll-tools-settings-button"', $index_html);
                $this->assertStringNotContainsString('id="ll-tools-settings-panel"', $index_html);
                $this->assertStringContainsString('data-mode="listening"', $index_html);
                $this->assertStringContainsString('data-mode="self-check"', $index_html);
                $this->assertStringContainsString('data-mode="gender"', $index_html);
                $this->assertStringNotContainsString('"speechToText"', $manifest_json);
                $this->assertStringNotContainsString('"androidAssetModelPath"', $offline_data);
                $this->assertStringNotContainsString('"engine":"whisper.cpp"', $offline_data);

                $has_image_asset = false;
                $has_audio_asset = false;
                $has_model_asset = false;
                foreach ($entry_names as $entry_name) {
                    if (strpos($entry_name, 'www/content/images/') === 0) {
                        $has_image_asset = true;
                    }
                    if (strpos($entry_name, 'www/content/audio/') === 0) {
                        $has_audio_asset = true;
                    }
                    if (strpos($entry_name, 'www/content/stt-models/') === 0) {
                        $has_model_asset = true;
                    }
                }

                $this->assertTrue($has_image_asset, 'Expected bundled offline image assets.');
                $this->assertTrue($has_audio_asset, 'Expected bundled offline audio assets.');
                $this->assertFalse($has_model_asset, 'Offline export should not bundle STT model assets while mobile speaking is disabled.');
                $zip->close();
            } finally {
                @unlink($zip_path);
                if ($staging_dir !== '' && is_dir($staging_dir)) {
                    ll_tools_rrmdir($staging_dir);
                }
                if (is_dir($offline_stt_bundle_path)) {
                    ll_tools_rrmdir($offline_stt_bundle_path);
                }
            }
        } finally {
            remove_filter('ll_tools_quiz_min_words', $min_words_filter);
        }
    }

    public function test_offline_app_build_categories_uses_wordset_smart_alphabetical_order(): void
    {
        $min_words_filter = static function (): int {
            return 1;
        };

        add_filter('ll_tools_quiz_min_words', $min_words_filter);

        try {
            $admin_id = self::factory()->user->create(['role' => 'administrator']);
            wp_set_current_user($admin_id);

            $wordset_term = wp_insert_term('Offline Order Wordset ' . wp_generate_password(6, false), 'wordset');
            $this->assertFalse(is_wp_error($wordset_term));
            $this->assertIsArray($wordset_term);
            $wordset_id = (int) $wordset_term['term_id'];

            $suffix = wp_generate_password(4, false, false);
            $category_55_name = 'Quiz 55.4 ' . $suffix;
            $category_6_name = 'Quiz 6.1 ' . $suffix;
            $category_55 = wp_insert_term($category_55_name, 'word-category');
            $category_6 = wp_insert_term($category_6_name, 'word-category');
            $this->assertFalse(is_wp_error($category_55));
            $this->assertFalse(is_wp_error($category_6));
            $this->assertIsArray($category_55);
            $this->assertIsArray($category_6);

            $category_55_id = (int) $category_55['term_id'];
            $category_6_id = (int) $category_6['term_id'];

            $recording_term = wp_insert_term('Isolation', 'recording_type', ['slug' => 'isolation']);
            if (is_wp_error($recording_term)) {
                $existing = get_term_by('slug', 'isolation', 'recording_type');
                $this->assertInstanceOf(WP_Term::class, $existing);
                $recording_type_id = (int) $existing->term_id;
            } else {
                $this->assertIsArray($recording_term);
                $recording_type_id = (int) $recording_term['term_id'];
            }

            update_term_meta($wordset_id, 'll_wordset_category_ordering_mode', 'none');

            $word_55_id = $this->createPublishedOfflineBundleWord(
                'Order Word 55',
                'Order Translation 55',
                $category_55_id,
                $wordset_id,
                $recording_type_id,
                'offline-order-55.png',
                'offline-order-55.mp3'
            );
            $word_6_id = $this->createPublishedOfflineBundleWord(
                'Order Word 6',
                'Order Translation 6',
                $category_6_id,
                $wordset_id,
                $recording_type_id,
                'offline-order-6.png',
                'offline-order-6.mp3'
            );

            $category_55_id = $this->getOfflineWordCategoryId($word_55_id) ?: $category_55_id;
            $category_6_id = $this->getOfflineWordCategoryId($word_6_id) ?: $category_6_id;
            $this->applyOfflineWordsetCategoryConfig($category_55_id, $wordset_id, 'audio', 'text_translation');
            $this->applyOfflineWordsetCategoryConfig($category_6_id, $wordset_id, 'audio', 'text_translation');

            $bundle = ll_tools_build_offline_app_bundle([
                'wordset_id' => $wordset_id,
                'category_ids' => [$category_55_id, $category_6_id],
                'app_name' => 'Offline Order App',
                'version_name' => '1.0.0',
                'version_code' => 1,
                'app_id_suffix' => 'tests.offline.order',
            ]);

            $this->assertFalse(is_wp_error($bundle), is_wp_error($bundle) ? $bundle->get_error_message() : '');
            $this->assertIsArray($bundle);

            $zip_path = (string) ($bundle['zip_path'] ?? '');
            $staging_dir = (string) ($bundle['staging_dir'] ?? '');
            $this->assertNotSame('', $zip_path);
            $this->assertFileExists($zip_path);

            try {
                $zip = new ZipArchive();
                $this->assertTrue($zip->open($zip_path) === true);

                $offline_data = $zip->getFromName('www/data/offline-data.js');
                $this->assertIsString($offline_data);

                $quiz_6_position = strpos($offline_data, '"name":"' . $category_6_name . '"');
                $quiz_55_position = strpos($offline_data, '"name":"' . $category_55_name . '"');

                $this->assertNotFalse($quiz_6_position);
                $this->assertNotFalse($quiz_55_position);
                $this->assertLessThan($quiz_55_position, $quiz_6_position);

                $zip->close();
            } finally {
                @unlink($zip_path);
                if ($staging_dir !== '' && is_dir($staging_dir)) {
                    ll_tools_rrmdir($staging_dir);
                }
            }
        } finally {
            remove_filter('ll_tools_quiz_min_words', $min_words_filter);
        }
    }

    public function test_offline_app_launcher_uses_four_text_previews_for_text_categories(): void
    {
        $min_words_filter = static function (): int {
            return 1;
        };

        add_filter('ll_tools_quiz_min_words', $min_words_filter);

        try {
            $admin_id = self::factory()->user->create(['role' => 'administrator']);
            wp_set_current_user($admin_id);

            $category_name = 'Offline Text Preview Category ' . wp_generate_password(4, false, false);
            $categories = [[
                'id' => 501,
                'slug' => 'offline-text-preview-category',
                'name' => $category_name,
                'translation' => $category_name,
                'mode' => 'text_title',
                'option_type' => 'text_title',
                'prompt_type' => 'text_translation',
                'requires_images' => false,
                'learning_supported' => true,
                'use_titles' => false,
                'aspect_bucket' => 'no-image',
            ]];

            $launcher_categories = ll_tools_offline_app_build_launcher_categories($categories, [
                $category_name => [
                    ['title' => 'Alpha preview', 'translation' => 'Text Preview Translation 1'],
                    ['title' => 'Beta preview', 'translation' => 'Text Preview Translation 2'],
                    ['title' => 'Gamma preview', 'translation' => 'Text Preview Translation 3'],
                    ['title' => 'Delta preview', 'translation' => 'Text Preview Translation 4'],
                    ['title' => 'Epsilon preview', 'translation' => 'Text Preview Translation 5'],
                ],
            ]);

            $this->assertCount(1, $launcher_categories);
            $launcher_category = $launcher_categories[0];
            $preview = (array) ($launcher_category['preview'] ?? []);

            $this->assertSame(4, (int) ($launcher_category['preview_limit'] ?? 0));
            $this->assertCount(4, $preview);
            $this->assertSame(['text', 'text', 'text', 'text'], array_map(static function ($item): string {
                return is_array($item) ? (string) ($item['type'] ?? '') : '';
            }, $preview));
        } finally {
            remove_filter('ll_tools_quiz_min_words', $min_words_filter);
        }
    }

    public function test_offline_app_export_job_starts_cheap_and_caps_every_phase(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $wordset = wp_insert_term('Offline Job Wordset ' . wp_generate_password(6, false, false), 'wordset');
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];
        $category_ids = [];
        foreach (range(1, 3) as $category_index) {
            $category = wp_insert_term('Offline Job Category ' . $category_index . ' ' . wp_generate_password(4, false, false), 'word-category');
            $this->assertIsArray($category);
            $category_id = (int) $category['term_id'];
            $effective_category_id = 0;
            foreach (range(1, 5) as $word_index) {
                $word_id = $this->createPublishedOfflineTextWord(
                    'Offline Job Word ' . $category_index . '-' . $word_index,
                    'Offline Job Translation ' . $category_index . '-' . $word_index,
                    $category_id,
                    $wordset_id
                );
                if ($effective_category_id <= 0) {
                    $effective_category_id = $this->getOfflineWordCategoryId($word_id);
                }
            }
            $effective_category_id = $effective_category_id > 0 ? $effective_category_id : $category_id;
            $category_ids[] = $this->applyOfflineWordsetCategoryConfig(
                $effective_category_id,
                $wordset_id,
                'text_title',
                'text_translation'
            );
        }

        $full_builder_started = false;
        $full_builder_listener = static function () use (&$full_builder_started): void {
            $full_builder_started = true;
        };
        $minimum_filter = static function (): int {
            return 1;
        };
        $category_batch_filter = static function (): int {
            return 2;
        };
        $word_batch_filter = static function (): int {
            return 3;
        };
        $media_batch_filter = static function (): int {
            return 7;
        };
        $data_batch_filter = static function (): int {
            return 4;
        };
        $zip_batch_filter = static function (): int {
            return 8;
        };
        $word_candidate_queries = [];
        $query_filter = static function (string $query) use (&$word_candidate_queries): string {
            if (stripos($query, 'SELECT DISTINCT p.ID') !== false && stripos($query, 'AND p.ID >') !== false) {
                $word_candidate_queries[] = $query;
            }
            return $query;
        };

        add_action('ll_tools_offline_app_full_builder_started', $full_builder_listener);
        add_filter('ll_tools_quiz_min_words', $minimum_filter);
        add_filter('ll_tools_offline_app_export_category_batch_size', $category_batch_filter);
        add_filter('ll_tools_offline_app_export_word_batch_size', $word_batch_filter);
        add_filter('ll_tools_offline_app_export_media_batch_size', $media_batch_filter);
        add_filter('ll_tools_offline_app_export_data_batch_size', $data_batch_filter);
        add_filter('ll_tools_offline_app_export_zip_batch_size', $zip_batch_filter);
        add_filter('query', $query_filter);

        $token = '';
        try {
            $start = ll_tools_offline_app_export_prepare_job([
                'll_offline_wordset_id' => $wordset_id,
                'll_offline_category_scope' => 'custom',
                'll_offline_category_ids' => $category_ids,
                'll_offline_app_name' => 'Offline Bounded Job',
                'll_offline_version_name' => '1.0.0',
                'll_offline_version_code' => 1,
                'll_offline_app_id_suffix' => 'tests.offline.job',
            ]);
            $this->assertIsArray($start, is_wp_error($start) ? $start->get_error_message() : '');
            $this->assertFalse($full_builder_started, 'Starting a resumable export must not invoke the full bundle builder.');
            $this->assertSame('categories', (string) ($start['phase'] ?? ''));
            $token = (string) ($start['token'] ?? '');
            $this->assertNotSame('', $token);

            $phase_batches = [];
            $response = $start;
            for ($iteration = 0; $iteration < 160; $iteration++) {
                $response = ll_tools_offline_app_export_run_step($token);
                $this->assertIsArray($response, is_wp_error($response) ? $response->get_error_message() : '');
                $batch = (array) ($response['batch'] ?? []);
                $batch_phase = (string) ($batch['phase'] ?? '');
                if ($batch_phase !== '') {
                    $phase_batches[$batch_phase][] = $batch;
                }
                $this->assertLessThanOrEqual(2, (int) ($batch['categories'] ?? 0));
                $this->assertLessThanOrEqual(3, (int) ($batch['words'] ?? 0));
                $this->assertLessThanOrEqual(7, (int) ($batch['media'] ?? 0));
                $this->assertLessThanOrEqual(4, (int) ($batch['data'] ?? 0));
                $this->assertLessThanOrEqual(8, (int) ($batch['zip'] ?? 0));
                if ((string) ($response['status'] ?? '') === 'completed') {
                    break;
                }
            }

            $this->assertSame('completed', (string) ($response['status'] ?? ''), wp_json_encode($response));
            $this->assertFalse($full_builder_started, 'Resumable export steps must not invoke the full bundle builder.');
            foreach (['categories', 'words', 'media', 'data', 'zip'] as $phase) {
                $this->assertArrayHasKey($phase, $phase_batches, 'Expected at least one bounded ' . $phase . ' batch.');
            }
            $this->assertNotEmpty($word_candidate_queries);
            foreach ($word_candidate_queries as $candidate_query) {
                $this->assertMatchesRegularExpression('/LIMIT\s+4\s*$/i', trim($candidate_query));
            }

            $job = ll_tools_offline_app_export_load_job($token);
            $this->assertIsArray($job);
            $zip_path = (string) ($job['zip_path'] ?? '');
            $this->assertFileExists($zip_path);
            $zip = new ZipArchive();
            $this->assertTrue($zip->open($zip_path) === true);
            $offline_data = $zip->getFromName('www/data/offline-data.js');
            $this->assertIsString($offline_data);
            $this->assertStringContainsString('Offline Job Translation 1-1', $offline_data);
            $this->assertStringContainsString('Offline Job Translation 3-5', $offline_data);
            $payload_json = preg_replace('/^window\.llToolsOfflineData\s*=\s*/', '', trim($offline_data));
            $payload_json = is_string($payload_json) ? preg_replace('/;$/', '', $payload_json) : '';
            $this->assertIsString($payload_json);
            $payload = json_decode($payload_json, true);
            $this->assertIsArray($payload, json_last_error_msg());
            $this->assertCount(5, (array) (($payload['flashcards'] ?? [])['firstCategoryData'] ?? []));
            $this->assertCount(3, (array) (($payload['flashcards'] ?? [])['offlineCategoryData'] ?? []));
            $zip->close();
        } finally {
            remove_action('ll_tools_offline_app_full_builder_started', $full_builder_listener);
            remove_filter('ll_tools_quiz_min_words', $minimum_filter);
            remove_filter('ll_tools_offline_app_export_category_batch_size', $category_batch_filter);
            remove_filter('ll_tools_offline_app_export_word_batch_size', $word_batch_filter);
            remove_filter('ll_tools_offline_app_export_media_batch_size', $media_batch_filter);
            remove_filter('ll_tools_offline_app_export_data_batch_size', $data_batch_filter);
            remove_filter('ll_tools_offline_app_export_zip_batch_size', $zip_batch_filter);
            remove_filter('query', $query_filter);
            if ($token !== '') {
                ll_tools_offline_app_export_delete_job($token);
            }
        }
    }

    private function create_image_attachment(string $filename): int
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
            'post_title'     => preg_replace('/\\.[^.]+$/', '', basename($file_path)),
            'post_status'    => 'inherit',
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
        wp_update_attachment_metadata($attachment_id, [
            'width'  => 2,
            'height' => 2,
            'file'   => $relative_path,
            'sizes'  => [],
        ]);

        return (int) $attachment_id;
    }

    private function create_audio_upload_file(string $filename): string
    {
        $upload = wp_upload_bits($filename, null, "offline audio bytes\n");
        $this->assertIsArray($upload);
        $this->assertSame('', (string) ($upload['error'] ?? ''));

        $file_path = (string) ($upload['file'] ?? '');
        $this->assertNotSame('', $file_path);
        $this->assertFileExists($file_path);

        $uploads = wp_upload_dir();
        $base_dir = wp_normalize_path((string) ($uploads['basedir'] ?? ''));
        $base_url = (string) ($uploads['baseurl'] ?? '');
        $base_url_path = (string) wp_parse_url($base_url, PHP_URL_PATH);
        $normalized_file_path = wp_normalize_path($file_path);
        $relative_path = '';

        if ($base_dir !== '' && strpos($normalized_file_path, trailingslashit($base_dir)) === 0) {
            $relative_path = ltrim(substr($normalized_file_path, strlen(trailingslashit($base_dir))), '/');
        }

        if ($relative_path === '') {
            $relative_path = basename($normalized_file_path);
        }

        return '/' . ltrim(trailingslashit($base_url_path) . $relative_path, '/');
    }

    private function create_offline_stt_bundle_dir(string $prefix): string
    {
        $upload_dir = wp_upload_dir();
        $base_dir = trailingslashit((string) ($upload_dir['basedir'] ?? ''));
        $bundle_dir = $base_dir . $prefix . '-' . wp_generate_password(6, false, false);
        $this->assertTrue(wp_mkdir_p($bundle_dir));
        $manifest = wp_json_encode([
            'engine' => 'whisper.cpp',
            'modelPath' => 'model.bin',
            'language' => 'auto',
            'task' => 'transcribe',
        ]);
        $this->assertIsString($manifest);
        $this->assertNotFalse(file_put_contents(trailingslashit($bundle_dir) . 'manifest.json', $manifest));
        $this->assertNotFalse(file_put_contents(trailingslashit($bundle_dir) . 'model.bin', "offline-stt\n"));

        return wp_normalize_path($bundle_dir);
    }

    private function createPublishedOfflineTextWord(string $title, string $translation, int $category_id, int $wordset_id): int
    {
        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => $title,
        ]);

        wp_set_post_terms($word_id, [$category_id], 'word-category', false);
        wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);
        update_post_meta($word_id, 'word_translation', $translation);

        return (int) $word_id;
    }

    private function createPublishedOfflineBundleWord(string $title, string $translation, int $category_id, int $wordset_id, int $recording_type_id, string $image_file_name, string $audio_file_name): int
    {
        $image_attachment_id = $this->create_image_attachment($image_file_name);
        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'draft',
            'post_title' => $title,
        ]);

        wp_set_post_terms($word_id, [$category_id], 'word-category', false);
        wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);
        set_post_thumbnail($word_id, $image_attachment_id);
        update_post_meta($word_id, 'word_translation', $translation);

        $audio_path = $this->create_audio_upload_file($audio_file_name);

        $audio_post_id = self::factory()->post->create([
            'post_type' => 'word_audio',
            'post_status' => 'publish',
            'post_parent' => $word_id,
            'post_title' => 'Audio ' . $title,
        ]);

        update_post_meta($audio_post_id, 'audio_file_path', $audio_path);
        update_post_meta($audio_post_id, 'recording_text', $title);
        wp_set_post_terms($audio_post_id, [$recording_type_id], 'recording_type', false);

        wp_update_post([
            'ID' => $word_id,
            'post_status' => 'publish',
        ]);

        return (int) $word_id;
    }

    private function applyOfflineWordsetCategoryConfig(int $category_id, int $wordset_id, string $prompt_type, string $option_type): int
    {
        if ($category_id <= 0 || $wordset_id <= 0) {
            return 0;
        }

        update_term_meta($category_id, 'll_quiz_prompt_type', $prompt_type);
        update_term_meta($category_id, 'll_quiz_option_type', $option_type);

        return $category_id;
    }

    private function getOfflineWordCategoryId(int $word_id): int
    {
        $term_ids = wp_get_object_terms($word_id, 'word-category', ['fields' => 'ids']);
        if (is_wp_error($term_ids) || empty($term_ids)) {
            return 0;
        }

        return (int) ($term_ids[0] ?? 0);
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
        $doing_ajax_filter = static function (): bool {
            return true;
        };

        add_filter('wp_die_handler', $die_filter);
        add_filter('wp_die_ajax_handler', $die_filter);
        add_filter('wp_doing_ajax', $doing_ajax_filter);

        ob_start();
        try {
            $callback();
            $this->fail('Expected wp_die to be called.');
        } catch (RuntimeException $e) {
            $this->assertSame('wp_die', $e->getMessage());
        } finally {
            $output = (string) ob_get_clean();
            remove_filter('wp_die_handler', $die_filter);
            remove_filter('wp_die_ajax_handler', $die_filter);
            remove_filter('wp_doing_ajax', $doing_ajax_filter);
        }

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded, 'Expected JSON response payload.');
        return $decoded;
    }
}
