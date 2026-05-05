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
    private function record(string $sync_id, int $recording_id, string $word_title, string $recording_title, array $values): array
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
