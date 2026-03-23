<?php
declare(strict_types=1);

final class OfflineAppExportTest extends LL_Tools_TestCase
{
    private const ONE_PIXEL_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8Xw8AAoMBgQf4xX0AAAAASUVORK5CYII=';

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

            $image_attachment_id = $this->create_image_attachment('offline-export-word-image.png');

            $word_id = self::factory()->post->create([
                'post_type'   => 'words',
                'post_status' => 'draft',
                'post_title'  => 'Offline Export Word',
            ]);
            wp_set_post_terms($word_id, [$category_id], 'word-category', false);
            wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);
            set_post_thumbnail($word_id, $image_attachment_id);
            update_post_meta($word_id, 'word_translation', 'Offline Export Translation');

            $audio_path = $this->create_audio_upload_file('offline-export-word.mp3');
            $audio_post_id = self::factory()->post->create([
                'post_type'   => 'word_audio',
                'post_status' => 'publish',
                'post_parent' => $word_id,
                'post_title'  => 'Offline Export Audio',
            ]);
            update_post_meta($audio_post_id, 'audio_file_path', $audio_path);
            update_post_meta($audio_post_id, 'recording_text', 'Offline Export Word');
            wp_set_post_terms($audio_post_id, [$recording_type_id], 'recording_type', false);

            wp_update_post([
                'ID'          => $word_id,
                'post_status' => 'publish',
            ]);

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
                1,
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
                1,
                $rows_with_wordset_scope,
                'Expected the wordset-scoped quiz rows to include the test word.'
            );
            $this->assertCount(1, $rows_with_resolved_config, 'Expected the resolved category config to preserve the test word.');

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
                $this->assertStringContainsString('"availableModes":["practice","learning"]', $offline_data);
                $this->assertStringNotContainsString('admin-ajax.php', $offline_data);
                $this->assertStringContainsString('./content/images/', $offline_data);
                $this->assertStringContainsString('./content/audio/', $offline_data);
                $this->assertStringContainsString('"launcher":{"categories":[', $offline_data);
                $this->assertStringContainsString('"preview":[{"type":"image","url":"./content/images/', $offline_data);

                $index_html = $zip->getFromName('www/index.html');
                $this->assertIsString($index_html);
                $offline_app_js = $zip->getFromName('www/app/offline-app.js');
                $this->assertIsString($offline_app_js);
                $this->assertStringContainsString('id="ll-offline-category-grid"', $index_html);
                $this->assertStringContainsString('id="ll-offline-select-all"', $index_html);
                $this->assertStringContainsString('data-ll-offline-launch-selected', $index_html);
                $this->assertStringNotContainsString('id="ll-tools-start-flashcard"', $index_html);
                $this->assertStringContainsString('data-ll-offline-category-mode', $offline_app_js);
                $this->assertStringNotContainsString('restart-self-check-mode', $index_html);
                $this->assertStringNotContainsString('restart-listening-mode', $index_html);
                $this->assertStringNotContainsString('restart-gender-mode', $index_html);

                $has_image_asset = false;
                $has_audio_asset = false;
                foreach ($entry_names as $entry_name) {
                    if (strpos($entry_name, 'www/content/images/') === 0) {
                        $has_image_asset = true;
                    }
                    if (strpos($entry_name, 'www/content/audio/') === 0) {
                        $has_audio_asset = true;
                    }
                }

                $this->assertTrue($has_image_asset, 'Expected bundled offline image assets.');
                $this->assertTrue($has_audio_asset, 'Expected bundled offline audio assets.');
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
}
