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
        update_term_meta($category_a_id, 'll_quiz_prompt_type', 'text_title');
        update_term_meta($category_a_id, 'll_quiz_option_type', 'text_title');

        $category_b = wp_insert_term('Offline UI Category B ' . wp_generate_password(6, false), 'word-category');
        $this->assertFalse(is_wp_error($category_b));
        $this->assertIsArray($category_b);
        $category_b_id = (int) $category_b['term_id'];
        update_term_meta($category_b_id, 'll_quiz_prompt_type', 'text_title');
        update_term_meta($category_b_id, 'll_quiz_option_type', 'text_title');

        $category_draft_only = wp_insert_term('Offline UI Draft Only ' . wp_generate_password(6, false), 'word-category');
        $this->assertFalse(is_wp_error($category_draft_only));
        $this->assertIsArray($category_draft_only);
        $category_draft_only_id = (int) $category_draft_only['term_id'];
        update_term_meta($category_draft_only_id, 'll_quiz_prompt_type', 'text_title');
        update_term_meta($category_draft_only_id, 'll_quiz_option_type', 'text_title');

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

        $options = ll_tools_offline_app_get_wordset_category_options($wordset_a_id);
        $option_ids = array_values(array_filter(array_map(static function ($row): int {
            return is_array($row) ? (int) ($row['id'] ?? 0) : 0;
        }, $options), static function (int $category_id): bool {
            return $category_id > 0;
        }));
        sort($option_ids, SORT_NUMERIC);

        $this->assertSame([$category_a_id], $option_ids);
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
            $wordset_slug = (string) get_term_field('slug', $wordset_id, 'wordset');
            update_term_meta($wordset_id, 'll_wordset_has_gender', '1');

            $category_term = wp_insert_term('Offline Bundle Category ' . wp_generate_password(6, false), 'word-category');
            $this->assertFalse(is_wp_error($category_term));
            $this->assertIsArray($category_term);
            $category_id = (int) $category_term['term_id'];

            update_term_meta($category_id, 'll_quiz_prompt_type', 'audio');
            update_term_meta($category_id, 'll_quiz_option_type', 'text_translation');

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
            $offline_stt_bundle_dir_name = wp_basename($offline_stt_bundle_path);
            update_term_meta($wordset_id, LL_TOOLS_WORDSET_OFFLINE_STT_BUNDLE_PATH_META_KEY, $offline_stt_bundle_path);

            wp_update_post([
                'ID'          => $word_id,
                'post_status' => 'publish',
            ]);

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
                $this->assertStringNotContainsString('admin-ajax.php', $offline_data);
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
                $this->assertStringContainsString('"provider":"embedded_model"', $offline_data);
                $this->assertStringContainsString('"embedded_model":{"wordsetId":', $offline_data);
                $this->assertStringContainsString('"offline_stt":{"wordsetId":', $offline_data);
                $this->assertStringContainsString('"./plugin/media/space-shooter-correct-hit.mp3"', $offline_data);
                $this->assertStringContainsString('"./plugin/media/bubble-pop.mp3"', $offline_data);

                $index_html = $zip->getFromName('www/index.html');
                $this->assertIsString($index_html);
                $offline_app_js = $zip->getFromName('www/app/offline-app.js');
                $this->assertIsString($offline_app_js);
                $manifest_json = $zip->getFromName('bundle-manifest.json');
                $this->assertIsString($manifest_json);
                $this->assertNotFalse($zip->locateName('www/content/stt-models/' . $wordset_slug . '/' . $offline_stt_bundle_dir_name . '/manifest.json'));
                $this->assertNotFalse($zip->locateName('www/content/stt-models/' . $wordset_slug . '/' . $offline_stt_bundle_dir_name . '/model.bin'));
                $this->assertStringContainsString('id="ll-offline-category-grid"', $index_html);
                $this->assertStringContainsString('class="ll-wordset-grid"', $index_html);
                $this->assertStringContainsString('id="ll-offline-select-all"', $index_html);
                $this->assertStringContainsString('id="ll-offline-selection-bar"', $index_html);
                $this->assertStringContainsString('data-ll-offline-view-toggle', $index_html);
                $this->assertStringContainsString('data-ll-offline-view="games"', $index_html);
                $this->assertStringContainsString('data-ll-wordset-games-root', $index_html);
                $this->assertStringContainsString('data-ll-offline-launch-selected', $index_html);
                $this->assertStringContainsString('src="./app/offline-app.js"', $index_html);
                $this->assertStringContainsString('href="./plugin/css/language-learner-tools.css"', $index_html);
                $this->assertStringContainsString('href="./plugin/css/wordset-pages.css"', $index_html);
                $this->assertStringContainsString('href="./plugin/css/wordset-games.css"', $index_html);
                $this->assertStringContainsString('href="./plugin/css/flashcard/mode-listening.css"', $index_html);
                $this->assertStringContainsString('href="./plugin/css/flashcard/mode-gender.css"', $index_html);
                $this->assertStringNotContainsString('http://./', $index_html);
                $this->assertStringNotContainsString('id="ll-tools-start-flashcard"', $index_html);
                $this->assertStringContainsString('data-ll-offline-category-mode', $offline_app_js);
                $this->assertStringContainsString('buildOfflineSpeakingBridge', $offline_app_js);
                $this->assertStringContainsString('id="restart-self-check-mode"', $index_html);
                $this->assertStringContainsString('id="restart-listening-mode"', $index_html);
                $this->assertStringContainsString('id="restart-gender-mode"', $index_html);
                $this->assertStringNotContainsString('id="ll-tools-settings-button"', $index_html);
                $this->assertStringNotContainsString('id="ll-tools-settings-panel"', $index_html);
                $this->assertStringContainsString('data-mode="listening"', $index_html);
                $this->assertStringContainsString('data-mode="self-check"', $index_html);
                $this->assertStringContainsString('data-mode="gender"', $index_html);
                $this->assertStringContainsString('"speechToText"', $manifest_json);
                $this->assertStringContainsString('"wordsetId": ' . $wordset_id, $manifest_json);
                $this->assertStringContainsString('"androidAssetModelPath"', $offline_data);
                $this->assertStringContainsString('"engine":"whisper.cpp"', $offline_data);

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
                $this->assertTrue($has_model_asset, 'Expected bundled offline STT model assets.');
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

            foreach ([$category_55_id, $category_6_id] as $category_id) {
                update_term_meta($category_id, 'll_quiz_prompt_type', 'audio');
                update_term_meta($category_id, 'll_quiz_option_type', 'text_translation');
            }

            update_term_meta($wordset_id, 'll_wordset_category_ordering_mode', 'none');

            $this->createPublishedOfflineBundleWord(
                'Order Word 55',
                'Order Translation 55',
                $category_55_id,
                $wordset_id,
                $recording_type_id,
                'offline-order-55.png',
                'offline-order-55.mp3'
            );
            $this->createPublishedOfflineBundleWord(
                'Order Word 6',
                'Order Translation 6',
                $category_6_id,
                $wordset_id,
                $recording_type_id,
                'offline-order-6.png',
                'offline-order-6.mp3'
            );

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
}
