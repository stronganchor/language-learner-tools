<?php
declare(strict_types=1);

final class SiteSyncTest extends LL_Tools_TestCase
{
    public function test_snapshot_endpoint_exports_transcription_records_with_sync_ids(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        $wordset_id = $this->ensure_term('wordset', 'Sync Wordset', 'sync-wordset');
        $category_id = $this->ensure_term('word-category', 'Sync Category', 'sync-category');
        $recording_type_id = $this->ensure_term('recording_type', 'Isolation', 'isolation');
        $word_id = $this->create_word($wordset_id, [$category_id], 'Sync Word', 'Sync Translation');
        $recording_id = $this->create_recording($word_id, 'Sync Recording', ['recording_ipa' => 'old.ipa']);
        wp_set_object_terms($recording_id, [$recording_type_id], 'recording_type');

        wp_set_current_user($admin_id);

        $response = $this->dispatch_rest_request('GET', '/ll-tools/v1/wordsets/sync-wordset/site-sync/snapshot');

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertIsArray($data);
        $this->assertSame('transcriptions', (string) ($data['surface'] ?? ''));
        $this->assertSame(1, (int) ($data['record_count'] ?? 0));

        $record = (array) (($data['records'] ?? [])[0] ?? []);
        $this->assertNotSame('', (string) ($record['sync_id'] ?? ''));
        $this->assertSame('old.ipa', (string) (($record['values'] ?? [])['recording_ipa'] ?? ''));
        $this->assertSame(['isolation'], array_values((array) (($record['recording'] ?? [])['types'] ?? [])));
        $this->assertNotSame('', (string) get_post_meta($recording_id, ll_tools_site_sync_uuid_meta_key(), true));
    }

    public function test_push_plan_separates_clean_updates_from_conflicts(): void
    {
        $base = $this->snapshot([
            $this->record('shared-clean', 101, 'Word A', 'Recording A', ['recording_ipa' => 'base.clean']),
            $this->record('shared-conflict', 102, 'Word B', 'Recording B', ['recording_ipa' => 'base.conflict']),
        ]);
        $local = $this->snapshot([
            $this->record('shared-clean', 201, 'Word A', 'Recording A', ['recording_ipa' => 'local.clean']),
            $this->record('shared-conflict', 202, 'Word B', 'Recording B', ['recording_ipa' => 'local.conflict']),
        ]);
        $remote = $this->snapshot([
            $this->record('shared-clean', 301, 'Word A', 'Recording A', ['recording_ipa' => 'base.clean']),
            $this->record('shared-conflict', 302, 'Word B', 'Recording B', ['recording_ipa' => 'remote.conflict']),
        ]);

        $plan = ll_tools_site_sync_build_push_plan($local, $remote, $base);

        $this->assertSame(1, count((array) $plan['remote_updates']));
        $this->assertSame(301, (int) $plan['remote_updates'][0]['recording_id']);
        $this->assertSame('local.clean', (string) $plan['remote_updates'][0]['recording_ipa']);
        $this->assertSame(1, count((array) $plan['conflicts']));
        $this->assertSame('recording_ipa', (string) $plan['conflicts'][0]['field']);
        $this->assertSame(1, count((array) $plan['conflict_review_updates']));
        $this->assertStringContainsString('local.conflict', (string) $plan['conflict_review_updates'][0]['review_note']);
        $this->assertStringContainsString('remote.conflict', (string) $plan['conflict_review_updates'][0]['review_note']);
    }

    public function test_pull_plan_applies_remote_changes_to_local_recordings(): void
    {
        $wordset_id = $this->ensure_term('wordset', 'Pull Sync Wordset', 'pull-sync-wordset');
        $category_id = $this->ensure_term('word-category', 'Pull Sync Category', 'pull-sync-category');
        $word_id = $this->create_word($wordset_id, [$category_id], 'Pull Sync Word', 'Pull Sync Translation');
        $recording_id = $this->create_recording($word_id, 'Pull Sync Recording', [
            'recording_text' => 'local text',
            'recording_ipa' => 'local.ipa',
        ]);
        update_post_meta($recording_id, ll_tools_site_sync_uuid_meta_key(), 'pull-shared-recording');

        $local = ll_tools_site_sync_build_snapshot($wordset_id, 'transcriptions', true);
        $this->assertIsArray($local);
        $remote_record = $this->record('pull-shared-recording', 999, 'Pull Sync Word', 'Pull Sync Recording', [
            'recording_text' => 'remote text?',
            'recording_ipa' => 'remote.ipa',
        ]);
        $remote_record['natural_key'] = (string) (($local['records'][0] ?? [])['natural_key'] ?? '');
        $remote = $this->snapshot([$remote_record]);

        $plan = ll_tools_site_sync_build_pull_plan($local, $remote, []);
        $summary = ll_tools_site_sync_apply_pull_plan($plan, $wordset_id);

        $this->assertSame(1, (int) $summary['records_updated']);
        $this->assertSame('remote text?', (string) get_post_meta($recording_id, 'recording_text', true));
        $this->assertSame('remote.ipa', (string) get_post_meta($recording_id, 'recording_ipa', true));
    }

    public function test_pull_links_remote_sync_ids_when_values_already_match(): void
    {
        $wordset_id = $this->ensure_term('wordset', 'Link Sync Wordset', 'link-sync-wordset');
        $category_id = $this->ensure_term('word-category', 'Link Sync Category', 'link-sync-category');
        $word_id = $this->create_word($wordset_id, [$category_id], 'Link Sync Word', 'Link Sync Translation');
        $recording_id = $this->create_recording($word_id, 'Link Sync Recording', [
            'recording_text' => 'same text',
            'recording_ipa' => 'same.ipa',
        ]);

        $local = ll_tools_site_sync_build_snapshot($wordset_id, 'transcriptions', true);
        $this->assertIsArray($local);
        $remote_record = $this->record('remote-link-recording', 999, 'Link Sync Word', 'Link Sync Recording', [
            'recording_text' => 'same text',
            'recording_ipa' => 'same.ipa',
        ]);
        $remote_record['natural_key'] = (string) (($local['records'][0] ?? [])['natural_key'] ?? '');
        $remote_record['word']['sync_id'] = 'remote-link-word';
        $remote = $this->snapshot([$remote_record]);

        $plan = ll_tools_site_sync_build_pull_plan($local, $remote, []);
        $summary = ll_tools_site_sync_apply_pull_plan($plan, $wordset_id);

        $this->assertSame(0, (int) $summary['records_updated']);
        $this->assertSame(2, (int) $summary['sync_ids_linked']);
        $this->assertSame('remote-link-recording', (string) get_post_meta($recording_id, ll_tools_site_sync_uuid_meta_key(), true));
        $this->assertSame('remote-link-word', (string) get_post_meta($word_id, ll_tools_site_sync_uuid_meta_key(), true));
    }

    public function test_pull_creates_missing_local_recording_for_existing_word_with_remote_audio_url(): void
    {
        $wordset_id = $this->ensure_term('wordset', 'Create Recording Wordset', 'create-recording-wordset');
        $category_id = $this->ensure_term('word-category', 'Create Recording Category', 'create-recording-category');
        $word_id = $this->create_word($wordset_id, [$category_id], 'Create Recording Word', 'Create Recording Translation');
        wp_update_post([
            'ID' => $word_id,
            'post_name' => '',
        ]);

        $local = ll_tools_site_sync_build_snapshot($wordset_id, 'transcriptions', true);
        $this->assertIsArray($local);
        $this->assertSame(0, count((array) ($local['records'] ?? [])));

        $audio_url = 'https://zazacaogren.com/wp-content/uploads/2026/05/create-recording.mp3';
        $remote_record = $this->record('create-remote-recording', 999, 'Create Recording Word', 'Remote Create Recording', [
            'recording_text' => 'remote text',
            'recording_ipa' => 'remote.ipa',
        ], [
            'audio' => [
                'url' => $audio_url,
                'mime_type' => 'audio/mpeg',
                'has_local_file' => true,
            ],
        ]);
        $remote_record['word']['sync_id'] = 'create-remote-word';
        $remote_record['word']['slug'] = 'remote-only-create-recording-word';
        $remote_record['recording']['slug'] = 'remote-create-recording';
        $remote = $this->snapshot([$remote_record]);

        $plan = ll_tools_site_sync_build_pull_plan($local, $remote, []);

        $this->assertSame(1, (int) ($plan['stats']['records_to_create'] ?? 0));
        $this->assertSame(0, (int) ($plan['stats']['skipped'] ?? 0));
        $this->assertSame('create_local_recording', (string) ($plan['actions'][0]['type'] ?? ''));
        $this->assertContains('audio_file_path', (array) ($plan['actions'][0]['fields'] ?? []));

        $summary = ll_tools_site_sync_apply_pull_plan($plan, $wordset_id);

        $this->assertSame(1, (int) ($summary['records_created'] ?? 0));
        $this->assertSame(0, (int) ($summary['records_updated'] ?? 0));
        $this->assertSame(1, (int) ($summary['media_refs_updated'] ?? 0));
        $this->assertSame('create-remote-word', (string) get_post_meta($word_id, ll_tools_site_sync_uuid_meta_key(), true));

        $recordings = get_posts([
            'post_type' => 'word_audio',
            'post_status' => ['publish', 'draft', 'pending', 'private', 'future'],
            'post_parent' => $word_id,
            'posts_per_page' => -1,
        ]);
        $this->assertCount(1, $recordings);

        $recording_id = (int) $recordings[0]->ID;
        $this->assertSame('create-remote-recording', (string) get_post_meta($recording_id, ll_tools_site_sync_uuid_meta_key(), true));
        $this->assertSame('remote text', (string) get_post_meta($recording_id, 'recording_text', true));
        $this->assertSame('remote.ipa', (string) get_post_meta($recording_id, 'recording_ipa', true));
        $this->assertSame($audio_url, (string) get_post_meta($recording_id, 'audio_file_path', true));

        $recording_types = wp_get_post_terms($recording_id, 'recording_type', ['fields' => 'slugs']);
        $this->assertSame(['isolation'], array_values((array) $recording_types));

        $after_local = ll_tools_site_sync_build_snapshot($wordset_id, 'transcriptions', true);
        $this->assertIsArray($after_local);
        $after_plan = ll_tools_site_sync_build_pull_plan($after_local, $remote, $remote);
        $this->assertSame(0, count((array) ($after_plan['actions'] ?? [])));
    }

    public function test_pull_creates_missing_local_word_once_for_multiple_remote_recordings(): void
    {
        $wordset_id = $this->ensure_term('wordset', 'Create Word Wordset', 'create-word-wordset');

        $local = ll_tools_site_sync_build_snapshot($wordset_id, 'transcriptions', true);
        $this->assertIsArray($local);
        $this->assertSame(0, count((array) ($local['records'] ?? [])));

        $remote_word = [
            'sync_id' => 'remote-created-word',
            'slug' => 'remote-created-word',
            'title' => 'Remote Created Word',
            'status' => 'publish',
            'word_translation' => 'Remote translation',
            'word_english_meaning' => 'Remote English meaning',
            'categories' => [
                [
                    'sync_id' => 'remote-created-category',
                    'slug' => 'remote-created-category',
                    'name' => 'Remote Created Category',
                ],
            ],
        ];
        $first_audio_url = 'https://zazacaogren.com/wp-content/uploads/2026/05/remote-created-isolation.mp3';
        $second_audio_url = 'https://zazacaogren.com/wp-content/uploads/2026/05/remote-created-question.mp3';
        $first_remote_record = $this->record('remote-created-recording-isolation', 999, 'Remote Created Word', 'Remote Created Isolation', [
            'recording_text' => 'remote isolation',
            'recording_ipa' => 'remote.iso',
        ], [
            'audio' => [
                'url' => $first_audio_url,
                'mime_type' => 'audio/mpeg',
                'has_local_file' => true,
            ],
        ]);
        $first_remote_record['word'] = $remote_word;
        $first_remote_record['recording']['slug'] = 'remote-created-isolation';

        $second_remote_record = $this->record('remote-created-recording-question', 1000, 'Remote Created Word', 'Remote Created Question', [
            'recording_text' => 'remote question',
            'recording_ipa' => 'remote.question',
        ], [
            'audio' => [
                'url' => $second_audio_url,
                'mime_type' => 'audio/mpeg',
                'has_local_file' => true,
            ],
        ]);
        $second_remote_record['word'] = $remote_word;
        $second_remote_record['recording']['slug'] = 'remote-created-question';
        $second_remote_record['recording']['types'] = ['question'];

        $remote = $this->snapshot([$first_remote_record, $second_remote_record]);

        $plan = ll_tools_site_sync_build_pull_plan($local, $remote, []);

        $this->assertSame(1, (int) ($plan['stats']['words_to_create'] ?? 0));
        $this->assertSame(2, (int) ($plan['stats']['records_to_create'] ?? 0));
        $this->assertSame(0, (int) ($plan['stats']['skipped'] ?? 0));

        $summary = ll_tools_site_sync_apply_pull_plan($plan, $wordset_id);

        $this->assertSame(1, (int) ($summary['words_created'] ?? 0));
        $this->assertSame(2, (int) ($summary['records_created'] ?? 0));
        $this->assertSame(2, (int) ($summary['media_refs_updated'] ?? 0));

        $words = get_posts([
            'post_type' => 'words',
            'post_status' => ['publish', 'draft', 'pending', 'private', 'future'],
            'title' => 'Remote Created Word',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'tax_query' => [
                [
                    'taxonomy' => 'wordset',
                    'field' => 'term_id',
                    'terms' => [$wordset_id],
                ],
            ],
        ]);
        $this->assertCount(1, $words);

        $word_id = (int) $words[0];
        $this->assertSame('remote-created-word', (string) get_post_meta($word_id, ll_tools_site_sync_uuid_meta_key(), true));
        $this->assertSame('Remote translation', (string) get_post_meta($word_id, 'word_translation', true));
        $this->assertSame('Remote English meaning', (string) get_post_meta($word_id, 'word_english_meaning', true));

        $category_ids = wp_get_post_terms($word_id, 'word-category', ['fields' => 'ids']);
        $this->assertCount(1, (array) $category_ids);
        $this->assertSame('remote-created-category', (string) get_term_meta((int) $category_ids[0], ll_tools_site_sync_uuid_meta_key(), true));

        $recordings = get_posts([
            'post_type' => 'word_audio',
            'post_status' => ['publish', 'draft', 'pending', 'private', 'future'],
            'post_parent' => $word_id,
            'posts_per_page' => -1,
            'orderby' => 'post_name',
            'order' => 'ASC',
        ]);
        $this->assertCount(2, $recordings);
        $this->assertSame($first_audio_url, (string) get_post_meta((int) $recordings[0]->ID, 'audio_file_path', true));
        $this->assertSame($second_audio_url, (string) get_post_meta((int) $recordings[1]->ID, 'audio_file_path', true));

        $after_local = ll_tools_site_sync_build_snapshot($wordset_id, 'transcriptions', true);
        $this->assertIsArray($after_local);
        $after_plan = ll_tools_site_sync_build_pull_plan($after_local, $remote, $remote);
        $this->assertSame(0, count((array) ($after_plan['actions'] ?? [])));
    }

    public function test_pull_reparents_recording_when_remote_word_sync_is_different(): void
    {
        $wordset_id = $this->ensure_term('wordset', 'Reparent Wordset', 'reparent-wordset');
        $category_id = $this->ensure_term('word-category', 'Reparent Category', 'reparent-category');
        $old_word_id = $this->create_word($wordset_id, [$category_id], 'Shared Title Word', 'Old Translation');
        update_post_meta($old_word_id, ll_tools_site_sync_uuid_meta_key(), 'old-remote-word');
        $recording_id = $this->create_recording($old_word_id, 'Shared Title Recording', [
            'recording_text' => 'same text',
            'recording_ipa' => 'same.ipa',
        ]);
        update_post_meta($recording_id, ll_tools_site_sync_uuid_meta_key(), 'shared-title-recording');

        $local = ll_tools_site_sync_build_snapshot($wordset_id, 'transcriptions', true);
        $this->assertIsArray($local);

        $remote_record = $this->record('shared-title-recording', 999, 'Shared Title Word', 'Shared Title Recording', [
            'recording_text' => 'same text',
            'recording_ipa' => 'same.ipa',
        ]);
        $remote_record['natural_key'] = (string) (($local['records'][0] ?? [])['natural_key'] ?? '');
        $remote_record['word']['sync_id'] = 'new-remote-word';
        $remote_record['word']['slug'] = 'shared-title-word-live-copy';
        $remote = $this->snapshot([$remote_record]);

        $plan = ll_tools_site_sync_build_pull_plan($local, $remote, $remote);
        $summary = ll_tools_site_sync_apply_pull_plan($plan, $wordset_id);

        $this->assertSame(1, (int) ($summary['words_created'] ?? 0));
        $this->assertSame(1, (int) ($summary['recordings_reparented'] ?? 0));
        $this->assertSame('old-remote-word', (string) get_post_meta($old_word_id, ll_tools_site_sync_uuid_meta_key(), true));

        $new_words = get_posts([
            'post_type' => 'words',
            'post_status' => ['publish', 'draft', 'pending', 'private', 'future'],
            'meta_key' => ll_tools_site_sync_uuid_meta_key(),
            'meta_value' => 'new-remote-word',
            'posts_per_page' => 1,
            'fields' => 'ids',
        ]);
        $this->assertCount(1, $new_words);
        $this->assertSame((int) $new_words[0], (int) get_post($recording_id)->post_parent);

        $after_local = ll_tools_site_sync_build_snapshot($wordset_id, 'transcriptions', true);
        $this->assertIsArray($after_local);
        $after_plan = ll_tools_site_sync_build_pull_plan($after_local, $remote, $remote);
        $this->assertSame(0, count((array) ($after_plan['actions'] ?? [])));
    }

    public function test_pull_uses_remote_media_urls_when_local_media_is_missing(): void
    {
        $wordset_id = $this->ensure_term('wordset', 'Media Sync Wordset', 'media-sync-wordset');
        $category_id = $this->ensure_term('word-category', 'Media Sync Category', 'media-sync-category');
        $word_id = $this->create_word($wordset_id, [$category_id], 'Media Sync Word', 'Media Sync Translation');
        $recording_id = $this->create_recording($word_id, 'Media Sync Recording', [
            'recording_text' => 'same text',
            'recording_ipa' => 'same.ipa',
        ]);
        update_post_meta($recording_id, ll_tools_site_sync_uuid_meta_key(), 'media-shared-recording');

        $local = ll_tools_site_sync_build_snapshot($wordset_id, 'transcriptions', true);
        $this->assertIsArray($local);

        $audio_url = 'https://zazacaogren.com/wp-content/uploads/2026/05/media-sync.mp3';
        $image_url = 'https://zazacaogren.com/wp-content/uploads/2026/05/media-sync.webp';
        $remote_record = $this->record('media-shared-recording', 999, 'Media Sync Word', 'Media Sync Recording', [
            'recording_text' => 'same text',
            'recording_ipa' => 'same.ipa',
        ], [
            'audio' => [
                'url' => $audio_url,
                'mime_type' => 'audio/mpeg',
                'has_local_file' => true,
            ],
            'word_image' => [
                'sync_id' => 'remote-media-word-image',
                'slug' => 'media-sync-word-image',
                'title' => 'Media Sync Image',
                'status' => 'publish',
                'attachment' => [
                    'url' => $image_url,
                    'source_url' => $image_url,
                    'mime_type' => 'image/webp',
                    'title' => 'Media Sync Image',
                    'alt' => 'Media alt',
                    'width' => 640,
                    'height' => 480,
                    'has_local_file' => true,
                ],
            ],
        ]);
        $remote_record['natural_key'] = (string) (($local['records'][0] ?? [])['natural_key'] ?? '');
        $remote_record['word']['sync_id'] = (string) (($local['records'][0]['word'] ?? [])['sync_id'] ?? '');
        $remote = $this->snapshot([$remote_record]);

        $plan = ll_tools_site_sync_build_pull_plan($local, $remote, []);
        $this->assertSame(2, (int) ($plan['stats']['media_refs_to_apply'] ?? 0));
        $this->assertContains('audio_file_path', (array) ($plan['actions'][0]['fields'] ?? []));
        $this->assertContains('word_image', (array) ($plan['actions'][0]['fields'] ?? []));

        $summary = ll_tools_site_sync_apply_pull_plan($plan, $wordset_id);

        $this->assertSame(0, (int) $summary['records_updated']);
        $this->assertSame(2, (int) $summary['media_refs_updated']);
        $this->assertSame($audio_url, (string) get_post_meta($recording_id, 'audio_file_path', true));
        $this->assertSame($audio_url, ll_tools_resolve_audio_file_url($audio_url));
        $uploads = wp_get_upload_dir();
        $uploads_path = (string) wp_parse_url((string) ($uploads['baseurl'] ?? ''), PHP_URL_PATH);
        if ($uploads_path !== '') {
            $legacy_local_url = 'http://old-site.local' . $uploads_path . '/legacy-sync.mp3';
            $this->assertSame(trailingslashit((string) $uploads['baseurl']) . 'legacy-sync.mp3', ll_tools_resolve_audio_file_url($legacy_local_url));
        }

        $word_image_id = (int) get_post_meta($word_id, '_ll_autopicked_image_id', true);
        $this->assertGreaterThan(0, $word_image_id);
        $this->assertSame('word_images', get_post_type($word_image_id));
        $this->assertSame('remote-media-word-image', (string) get_post_meta($word_image_id, ll_tools_site_sync_uuid_meta_key(), true));

        $attachment_id = (int) get_post_thumbnail_id($word_image_id);
        $this->assertGreaterThan(0, $attachment_id);
        $this->assertSame($attachment_id, (int) get_post_thumbnail_id($word_id));
        $this->assertSame($image_url, (string) wp_get_attachment_url($attachment_id));
        $this->assertSame($image_url, (string) get_post_meta($attachment_id, '_ll_tools_external_source_url', true));
        $this->assertSame('Media alt', (string) get_post_meta($attachment_id, '_wp_attachment_image_alt', true));
        $this->assertFalse(ll_tools_site_sync_attachment_has_local_file($attachment_id));

        $metadata = wp_get_attachment_metadata($attachment_id);
        $this->assertIsArray($metadata);
        $this->assertSame(640, (int) ($metadata['width'] ?? 0));
        $this->assertSame(480, (int) ($metadata['height'] ?? 0));
    }

    public function test_pull_replaces_existing_review_note_with_empty_remote_note(): void
    {
        $wordset_id = $this->ensure_term('wordset', 'Review Sync Wordset', 'review-sync-wordset');
        $category_id = $this->ensure_term('word-category', 'Review Sync Category', 'review-sync-category');
        $word_id = $this->create_word($wordset_id, [$category_id], 'Review Sync Word', 'Review Sync Translation');
        $recording_id = $this->create_recording($word_id, 'Review Sync Recording', [
            'recording_text' => 'same text',
            'recording_ipa' => 'same.ipa',
        ]);
        update_post_meta($recording_id, ll_tools_site_sync_uuid_meta_key(), 'review-shared-recording');
        ll_tools_ipa_keyboard_set_recording_review_state($recording_id, true, 'recording_ipa', 'old local note');

        $local = ll_tools_site_sync_build_snapshot($wordset_id, 'transcriptions', true);
        $this->assertIsArray($local);
        $remote_record = $this->record('review-shared-recording', 999, 'Review Sync Word', 'Review Sync Recording', [
            'recording_text' => 'same text',
            'recording_ipa' => 'same.ipa',
            'needs_review' => true,
            'review_fields' => [],
            'review_note' => '',
        ]);
        $remote_record['natural_key'] = (string) (($local['records'][0] ?? [])['natural_key'] ?? '');
        $remote = $this->snapshot([$remote_record]);

        $plan = ll_tools_site_sync_build_pull_plan($local, $remote, []);
        $summary = ll_tools_site_sync_apply_pull_plan($plan, $wordset_id);

        $this->assertSame(1, (int) $summary['records_updated']);
        $this->assertSame('', ll_tools_ipa_keyboard_get_recording_review_note($recording_id));
        $this->assertSame(['recording_ipa'], ll_tools_ipa_keyboard_get_recording_review_field_list($recording_id));
    }

    public function test_pull_base_merge_preserves_old_base_for_conflicted_fields(): void
    {
        $base_record = $this->record('merge-shared', 101, 'Merge Word', 'Merge Recording', ['recording_ipa' => 'base.ipa']);
        $base = $this->snapshot([$base_record]);
        $local = $this->snapshot([
            $this->record('merge-shared', 201, 'Merge Word', 'Merge Recording', ['recording_ipa' => 'local.ipa']),
        ]);
        $remote = $this->snapshot([
            $this->record('merge-shared', 301, 'Merge Word', 'Merge Recording', ['recording_ipa' => 'remote.ipa']),
        ]);

        $plan = ll_tools_site_sync_build_pull_plan($local, $remote, $base);
        $merged = ll_tools_site_sync_merge_base_snapshot_after_pull($base, $remote, $plan);

        $this->assertSame(1, count((array) $plan['conflicts']));
        $this->assertSame('base.ipa', (string) (($merged['records'][0]['values'] ?? [])['recording_ipa'] ?? ''));
    }

    public function test_compact_base_snapshot_keeps_merge_data_without_media_payload(): void
    {
        $base_record = $this->record('compact-shared', 101, 'Compact Word', 'Compact Recording', [
            'recording_ipa' => 'base.ipa',
        ], [
            'audio' => [
                'url' => 'https://example.com/audio.mp3',
                'mime_type' => 'audio/mpeg',
                'has_local_file' => true,
            ],
            'word_image' => [
                'attachment' => [
                    'url' => 'https://example.com/image.webp',
                    'source_url' => 'https://example.com/image.webp',
                    'mime_type' => 'image/webp',
                    'width' => 1024,
                    'height' => 1024,
                    'has_local_file' => true,
                ],
            ],
        ]);
        $base = $this->snapshot([$base_record]);

        $compacted = ll_tools_site_sync_compact_base_snapshot($base);
        $local = $this->snapshot([
            $this->record('compact-shared', 201, 'Compact Word', 'Compact Recording', ['recording_ipa' => 'local.ipa']),
        ]);
        $remote = $this->snapshot([
            $this->record('compact-shared', 301, 'Compact Word', 'Compact Recording', ['recording_ipa' => 'remote.ipa']),
        ]);

        $this->assertArrayNotHasKey('media', (array) ($compacted['records'][0] ?? []));
        $this->assertSame('base.ipa', (string) (($compacted['records'][0]['values'] ?? [])['recording_ipa'] ?? ''));
        $this->assertLessThan(strlen(serialize($base)), strlen(serialize($compacted)));

        $plan = ll_tools_site_sync_build_pull_plan($local, $remote, $compacted);

        $this->assertSame(1, count((array) $plan['conflicts']));
        $this->assertSame('base.ipa', (string) ($plan['conflicts'][0]['base_value'] ?? ''));
    }

    public function test_local_change_summary_compares_current_site_to_saved_baseline(): void
    {
        $wordset_id = $this->ensure_term('wordset', 'Local Summary Wordset', 'local-summary-wordset');
        $category_id = $this->ensure_term('word-category', 'Local Summary Category', 'local-summary-category');
        $word_id = $this->create_word($wordset_id, [$category_id], 'Local Summary Word', 'Local Summary Translation');
        $recording_id = $this->create_recording($word_id, 'Local Summary Recording', [
            'recording_text' => 'baseline text',
            'recording_ipa' => 'baseline.ipa',
        ]);
        update_post_meta($recording_id, ll_tools_site_sync_uuid_meta_key(), 'local-summary-recording');

        $base_snapshot = ll_tools_site_sync_build_snapshot($wordset_id, 'transcriptions', true);
        $this->assertIsArray($base_snapshot);

        update_post_meta($recording_id, 'recording_ipa', 'changed local ipa');

        $summary = ll_tools_site_sync_build_local_change_summary([
            'local_wordset_id' => $wordset_id,
            'remote_url' => 'https://example.com',
            'remote_wordset' => 'remote-wordset',
            'remote_username' => 'remote-user',
            'surface' => 'transcriptions',
        ], $base_snapshot);

        $this->assertTrue((bool) ($summary['available'] ?? false));
        $this->assertSame(1, (int) (($summary['stats'] ?? [])['changed_records'] ?? 0));
        $this->assertSame(1, (int) (($summary['field_counts'] ?? [])['recording_ipa'] ?? 0));

        $sample = (array) (($summary['samples'] ?? [])[0] ?? []);
        $change = (array) (($sample['changes'] ?? [])[0] ?? []);
        $this->assertSame('Modified locally', (string) ($sample['status_label'] ?? ''));
        $this->assertSame('recording_ipa', (string) ($change['field'] ?? ''));
        $this->assertSame('baseline.ipa', (string) ($change['before'] ?? ''));
        $this->assertSame('changed local ipa', (string) ($change['after'] ?? ''));
    }

    public function test_site_sync_preview_renders_media_and_before_after_values(): void
    {
        $base = $this->snapshot([
            $this->record('rich-preview', 101, 'Preview Word', 'Preview Recording', [
                'recording_text' => 'baseline text',
                'recording_ipa' => 'baseline.ipa',
            ]),
        ]);
        $local = $this->snapshot([
            $this->record('rich-preview', 201, 'Preview Word', 'Preview Recording', [
                'recording_text' => 'changed local text',
                'recording_ipa' => 'baseline.ipa',
            ], [
                'audio' => [
                    'url' => 'https://example.com/audio.mp3',
                    'mime_type' => 'audio/mpeg',
                    'has_local_file' => false,
                ],
                'word_image' => [
                    'attachment' => [
                        'source_url' => 'https://example.com/image.webp',
                        'url' => 'https://example.com/image.webp',
                        'mime_type' => 'image/webp',
                    ],
                ],
            ]),
        ]);
        $remote = $this->snapshot([
            $this->record('rich-preview', 301, 'Preview Word', 'Preview Recording', [
                'recording_text' => 'baseline text',
                'recording_ipa' => 'baseline.ipa',
            ]),
        ]);

        $plan = ll_tools_site_sync_build_push_plan($local, $remote, $base);

        ob_start();
        ll_tools_site_sync_render_plan_summary($plan);
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('Preview Word', $html);
        $this->assertStringContainsString('Live now', $html);
        $this->assertStringContainsString('After push', $html);
        $this->assertStringContainsString('baseline', $html);
        $this->assertStringContainsString('changed', $html);
        $this->assertStringContainsString('local', $html);
        $this->assertStringContainsString('ll-site-sync-diff-added', $html);
        $this->assertStringContainsString('<audio controls', $html);
        $this->assertStringContainsString('https://example.com/image.webp', $html);
    }

    public function test_live_comparison_preview_emphasizes_conflicts(): void
    {
        $base = $this->snapshot([
            $this->record('conflict-preview', 101, 'Conflict Word', 'Conflict Recording', [
                'recording_ipa' => 'baseline.ipa',
            ]),
        ]);
        $local = $this->snapshot([
            $this->record('conflict-preview', 201, 'Conflict Word', 'Conflict Recording', [
                'recording_ipa' => 'staging.ipa',
            ]),
        ]);
        $remote = $this->snapshot([
            $this->record('conflict-preview', 301, 'Conflict Word', 'Conflict Recording', [
                'recording_ipa' => 'live.ipa',
            ]),
        ]);

        $plan = ll_tools_site_sync_build_push_plan($local, $remote, $base);

        ob_start();
        ll_tools_site_sync_render_plan_summary($plan);
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('Live Comparison Preview', $html);
        $this->assertStringContainsString('fresh live-site snapshot', $html);
        $this->assertStringContainsString('1 conflict needs review', $html);
        $this->assertStringContainsString('Last pulled', $html);
        $this->assertStringContainsString('baseline.ipa', $html);
        $this->assertStringContainsString('staging.ipa', $html);
        $this->assertStringContainsString('live.ipa', $html);
    }

    public function test_remote_plan_hides_page_load_local_change_overview(): void
    {
        $this->assertTrue(ll_tools_site_sync_should_render_local_change_overview(['plan' => null]));
        $this->assertTrue(ll_tools_site_sync_should_render_local_change_overview([]));
        $this->assertFalse(ll_tools_site_sync_should_render_local_change_overview([
            'plan' => [
                'direction' => 'push',
            ],
        ]));
    }

    private function ensure_term(string $taxonomy, string $name, string $slug): int
    {
        $existing = get_term_by('slug', $slug, $taxonomy);
        if ($existing instanceof WP_Term) {
            return (int) $existing->term_id;
        }

        $inserted = wp_insert_term($name, $taxonomy, ['slug' => $slug]);
        $this->assertNotWPError($inserted);
        return (int) $inserted['term_id'];
    }

    /**
     * @param array<int,int> $category_ids
     */
    private function create_word(int $wordset_id, array $category_ids, string $title, string $translation): int
    {
        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => $title,
        ]);
        wp_set_object_terms($word_id, [$wordset_id], 'wordset');
        wp_set_object_terms($word_id, $category_ids, 'word-category');
        update_post_meta($word_id, 'word_translation', $translation);
        return (int) $word_id;
    }

    /**
     * @param array<string,string> $meta
     */
    private function create_recording(int $word_id, string $title, array $meta): int
    {
        $recording_id = self::factory()->post->create([
            'post_type' => 'word_audio',
            'post_status' => 'publish',
            'post_parent' => $word_id,
            'post_title' => $title,
        ]);
        foreach ($meta as $key => $value) {
            update_post_meta($recording_id, $key, $value);
        }
        return (int) $recording_id;
    }

    /**
     * @param array<int,array<string,mixed>> $records
     * @return array<string,mixed>
     */
    private function snapshot(array $records): array
    {
        return [
            'schema_version' => LL_TOOLS_SITE_SYNC_SCHEMA_VERSION,
            'surface' => 'transcriptions',
            'generated_at_gmt' => gmdate('c'),
            'wordset' => ['id' => 1, 'slug' => 'sync', 'name' => 'Sync'],
            'record_count' => count($records),
            'records' => $records,
        ];
    }

    /**
     * @param array<string,mixed> $values
     * @return array<string,mixed>
     */
    private function record(string $sync_id, int $recording_id, string $word_title, string $recording_title, array $values, array $media = []): array
    {
        $values = array_merge([
            'recording_text' => '',
            'recording_ipa' => '',
            'needs_review' => false,
            'review_fields' => [],
            'review_note' => '',
        ], $values);

        return [
            'record_type' => 'word_audio_transcription',
            'sync_id' => $sync_id,
            'natural_key' => 'natural:' . $sync_id,
            'word' => [
                'id' => 10 + $recording_id,
                'sync_id' => 'word-' . $sync_id,
                'slug' => sanitize_title($word_title),
                'title' => $word_title,
            ],
            'recording' => [
                'id' => $recording_id,
                'slug' => sanitize_title($recording_title),
                'title' => $recording_title,
                'types' => ['isolation'],
            ],
            'values' => ll_tools_site_sync_normalize_record_values($values),
            'value_hash' => ll_tools_site_sync_value_hash($values),
            'media' => ll_tools_site_sync_normalize_record_media($media),
        ];
    }

    /**
     * @param array<string,mixed> $params
     */
    private function dispatch_rest_request(string $method, string $route, array $params = []): WP_REST_Response
    {
        $previous_rest_route = $_GET['rest_route'] ?? null;
        $_GET['rest_route'] = $route;
        $request = new WP_REST_Request($method, $route);
        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }

        try {
            $response = rest_get_server()->dispatch($request);
            $this->assertNotWPError($response);
            return rest_ensure_response($response);
        } finally {
            if ($previous_rest_route === null) {
                unset($_GET['rest_route']);
            } else {
                $_GET['rest_route'] = $previous_rest_route;
            }
        }
    }
}
