<?php
declare(strict_types=1);

final class LmsAssignmentExperienceTest extends LL_Tools_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTrue(ll_tools_install_lms_assignment_schema());
        $this->assertTrue(ll_tools_install_grade_delivery_schema());
        $this->assertTrue(ll_tools_install_user_progress_schema());
        delete_option(LL_TOOLS_USER_PROGRESS_CORE_ENGINE_OPTION);
        $this->assertTrue((bool) ll_tools_user_progress_core_engine_status(true)['ready']);
        ll_tools_register_or_refresh_teacher_role();
        ll_tools_register_or_refresh_learner_role();
    }

    public function test_category_authoring_is_bounded_scoped_and_preserves_private_immutable_snapshot(): void
    {
        $fixture = $this->fixture();
        wp_set_current_user($fixture['teacher']);
        $assignment = ll_tools_lms_assignment_create_from_category($fixture['class'], $fixture['category'], ['grade_policy' => 'best', 'attempt_limit' => 2]);
        $this->assertIsArray($assignment);
        $revision = ll_tools_lms_assignment_get_revision((int) $assignment['current_revision_id']);
        $this->assertSame(2, (int) $revision['manifest_schema']);
        $manifest = ll_tools_lms_assignment_revision_manifest($revision);
        $this->assertIsArray($manifest);
        $this->assertCount(5, $manifest['items']);
        $public = ll_tools_lms_assignment_public_manifest($manifest);
        $this->assertSame(2, $public['schema']);
        foreach ($public['items'] as $item) {
            $this->assertArrayNotHasKey('vocabulary', $item);
            foreach ($item['options'] as $option) { $this->assertArrayNotHasKey('correct', $option); }
        }
        $before = $revision['manifest_json'];
        wp_update_post(['ID' => $fixture['words'][0], 'post_title' => 'Changed later']);
        $this->assertSame($before, ll_tools_lms_assignment_get_revision((int) $revision['id'])['manifest_json']);
        $outsider = self::factory()->user->create(['role' => 'll_tools_teacher']);
        $this->assertWPError(ll_tools_lms_assignment_create_from_category($fixture['class'], $fixture['category'], [], $outsider));
        $this->assertWPError(ll_tools_lms_assignment_create_from_category($fixture['class'], $fixture['category'], ['word_ids' => array_slice($fixture['words'], 0, 4)]));
        $other = $this->fixture();
        wp_set_current_user($fixture['teacher']);
        $this->assertWPError(ll_tools_lms_assignment_create_from_category($fixture['class'], $other['category']));
        $bad_manifest = $manifest;
        $bad_manifest['items'][0]['vocabulary']['wordset_id'] = $other['wordset'];
        $this->assertWPError(ll_tools_lms_assignment_create($fixture['class'], ['title' => 'Foreign snapshot', 'manifest' => $bad_manifest], $fixture['teacher']));
        $bad_manifest = $manifest;
        $bad_manifest['items'][0]['prompt']['image'] = 'javascript:alert(1)';
        $this->assertWPError(ll_tools_lms_assignment_normalize_manifest($bad_manifest));
    }

    public function test_server_answers_project_exact_word_progress_once_and_best_grade_is_separate(): void
    {
        global $wpdb;
        $fixture = $this->fixture();
        wp_set_current_user($fixture['teacher']);
        $assignment = ll_tools_lms_assignment_create_from_category($fixture['class'], $fixture['category'], ['grade_policy' => 'best', 'attempt_limit' => 2]);
        $this->assertIsArray($assignment);
        $this->assertTrue(ll_tools_lms_assignment_publish((int) $assignment['id'], $fixture['teacher']));
        wp_set_current_user($fixture['learner']);
        $started = ll_tools_lms_assignment_start_attempt((int) $assignment['id'], $fixture['learner']);
        $this->assertIsArray($started);
        $resumed = ll_tools_lms_assignment_start_attempt((int) $assignment['id'], $fixture['learner']);
        $this->assertSame($started['attempt']['attempt_uuid'], $resumed['attempt']['attempt_uuid']);
        $manifest = ll_tools_lms_assignment_revision_manifest(ll_tools_lms_assignment_get_revision((int) $assignment['current_revision_id']));
        foreach ($manifest['items'] as $index => $item) {
            $option = $this->optionKey($item, $index < 4);
            $answer_uuid = wp_generate_uuid4();
            $answer = ll_tools_lms_assignment_submit_answer($started['attempt']['attempt_uuid'], $answer_uuid, $item['key'], $option, $fixture['learner']);
            $this->assertIsArray($answer);
            $replay = ll_tools_lms_assignment_submit_answer($started['attempt']['attempt_uuid'], $answer_uuid, $item['key'], $option, $fixture['learner']);
            $this->assertTrue($replay['replayed']);
            $row = $this->wordRow($fixture['learner'], $item['vocabulary']['word_id']);
            $this->assertSame(1, (int) $row['total_coverage']);
            $this->assertSame($index < 4 ? 1 : 0, (int) $row['correct_clean']);
            $this->assertSame($index < 4 ? 0 : 1, (int) $row['incorrect']);
            $this->assertSame('', (string) $row['practice_correct_recording_types']);
        }
        $finalized = ll_tools_lms_assignment_finalize_attempt($started['attempt']['attempt_uuid'], $fixture['learner']);
        $this->assertIsArray($finalized);
        $this->assertSame(4, $finalized['grade']['score_given']);
        $state = ll_tools_lms_assignment_player_state($assignment['assignment_uuid'], $fixture['learner']);
        $this->assertCount(5, $state['answers']);
        $this->assertSame('finalized', $state['attempt']['status']);
        $second = ll_tools_lms_assignment_start_attempt((int) $assignment['id'], $fixture['learner']);
        $this->assertIsArray($second);
        $this->assertNotSame($started['attempt']['attempt_uuid'], $second['attempt']['attempt_uuid']);
        foreach ($manifest['items'] as $item) {
            $this->assertIsArray(ll_tools_lms_assignment_submit_answer($second['attempt']['attempt_uuid'], wp_generate_uuid4(), $item['key'], $this->optionKey($item, false), $fixture['learner']));
            $this->assertSame(2, (int) $this->wordRow($fixture['learner'], $item['vocabulary']['word_id'])['total_coverage']);
        }
        $finalized_second = ll_tools_lms_assignment_finalize_attempt($second['attempt']['attempt_uuid'], $fixture['learner']);
        $this->assertSame(0, $finalized_second['attempt']['score_given']);
        $this->assertSame(4, $finalized_second['grade']['score_given']);
        $progress_tables = ll_tools_user_progress_table_names();
        $this->assertSame(20, (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$progress_tables['events']} WHERE user_id=%d AND event_uuid LIKE 'lms-%%'", $fixture['learner'])));
    }

    public function test_progress_failure_rolls_back_answer_and_retry_uses_original_first_answer(): void
    {
        global $wpdb;
        $fixture = $this->fixture();
        wp_set_current_user($fixture['teacher']);
        $assignment = ll_tools_lms_assignment_create_from_category($fixture['class'], $fixture['category']);
        $this->assertIsArray($assignment);
        $this->assertTrue(ll_tools_lms_assignment_publish((int) $assignment['id'], $fixture['teacher']));
        wp_set_current_user($fixture['learner']);
        $started = ll_tools_lms_assignment_start_attempt((int) $assignment['id'], $fixture['learner']);
        $manifest = ll_tools_lms_assignment_revision_manifest(ll_tools_lms_assignment_get_revision((int) $assignment['current_revision_id']));
        $item = $manifest['items'][0];
        $uuid = wp_generate_uuid4();
        $table = ll_tools_user_progress_table_names()['words'];
        $fault = static function (string $sql) use ($table): string {
            return stripos($sql, 'INSERT INTO') === 0 && strpos($sql, $table) !== false ? 'INSERT INTO ll_assignment_test_missing_table (invalid) VALUES (1)' : $sql;
        };
        add_filter('query', $fault);
        $old = $wpdb->suppress_errors(true);
        try {
            $this->assertWPError(ll_tools_lms_assignment_submit_answer($started['attempt']['attempt_uuid'], $uuid, $item['key'], $this->optionKey($item, true), $fixture['learner']));
        } finally { remove_filter('query', $fault); $wpdb->suppress_errors($old); }
        $tables = ll_tools_lms_assignment_table_names();
        $attempt = ll_tools_lms_assignment_get_attempt($started['attempt']['attempt_uuid']);
        $this->assertSame(0, (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$tables['answers']} WHERE attempt_id=%d", (int) $attempt['id'])));
        $retry = ll_tools_lms_assignment_submit_answer($started['attempt']['attempt_uuid'], $uuid, $item['key'], $this->optionKey($item, true), $fixture['learner']);
        $this->assertIsArray($retry);
        $this->assertFalse($retry['replayed']);
        $this->assertSame(1, (int) $this->wordRow($fixture['learner'], $item['vocabulary']['word_id'])['correct_clean']);
    }

    public function test_audio_assignment_credits_only_the_chosen_recording_aspect(): void
    {
        global $wpdb;
        $fixture = $this->fixture();
        update_term_meta($fixture['category'], 'll_quiz_prompt_type', 'audio');
        foreach (['isolation', 'question'] as $type) {
            if (!term_exists($type, 'recording_type')) {
                $this->assertIsArray(wp_insert_term(ucfirst($type), 'recording_type', ['slug' => $type]));
            }
            foreach ($fixture['words'] as $word_id) {
                $audio_id = self::factory()->post->create([
                    'post_type' => 'word_audio', 'post_status' => 'publish',
                    'post_parent' => $word_id, 'post_title' => $type . ' recording',
                ]);
                update_post_meta($audio_id, 'audio_file_path', '/wp-content/uploads/assignment-' . $audio_id . '.mp3');
                wp_set_object_terms($audio_id, [$type], 'recording_type');
            }
        }
        ll_tools_reset_word_practice_recording_types_request_cache();
        wp_set_current_user($fixture['teacher']);
        $assignment = ll_tools_lms_assignment_create_from_category($fixture['class'], $fixture['category']);
        $this->assertIsArray($assignment);
        $this->assertTrue(ll_tools_lms_assignment_publish((int) $assignment['id'], $fixture['teacher']));
        $manifest = ll_tools_lms_assignment_revision_manifest(ll_tools_lms_assignment_get_revision((int) $assignment['current_revision_id']));
        $item = $manifest['items'][0];
        $selected_type = $item['vocabulary']['recording_type'];
        $this->assertNotSame('', $item['prompt']['audio']);
        $this->assertContains($selected_type, ['isolation', 'question']);
        $this->assertSame(['question', 'isolation'], $item['vocabulary']['available_recording_types']);
        wp_set_current_user($fixture['learner']);
        $attempt = ll_tools_lms_assignment_start_attempt((int) $assignment['id'], $fixture['learner']);
        $this->assertIsArray($attempt);
        $uuid = wp_generate_uuid4();
        $this->assertIsArray(ll_tools_lms_assignment_submit_answer($attempt['attempt']['attempt_uuid'], $uuid, $item['key'], $this->optionKey($item, true), $fixture['learner']));
        $row = $this->wordRow($fixture['learner'], $item['vocabulary']['word_id']);
        $this->assertSame(['question', 'isolation'], ll_tools_decode_practice_recording_types($row['practice_required_recording_types']));
        $this->assertSame([$selected_type], ll_tools_decode_practice_recording_types($row['practice_correct_recording_types']));
        $this->assertSame(1, (int) $row['correct_clean']);
        $this->assertSame(1, (int) $row['stage'], 'The ordinary answer gets one practice step, without invented audio timing bonuses.');
        $this->assertSame(1, (int) $row['coverage_practice']);
        $this->assertSame(0, (int) $row['coverage_listening']);
        $this->assertSame(0, (int) $row['coverage_self_check']);
        $table = ll_tools_user_progress_table_names()['events'];
        $payload = json_decode((string) $wpdb->get_var($wpdb->prepare("SELECT payload_json FROM {$table} WHERE user_id=%d AND word_id=%d AND event_type='word_outcome' ORDER BY id DESC LIMIT 1", $fixture['learner'], $item['vocabulary']['word_id'])), true);
        $this->assertSame('lms_assignment', $payload['event_source']);
        $this->assertSame($selected_type, $payload['recording_type']);
        $this->assertArrayNotHasKey('speaking_game_bucket', $payload);
        $this->assertArrayNotHasKey('audio_duration_ms', $payload);
        $this->assertTrue(ll_tools_lms_assignment_submit_answer($attempt['attempt']['attempt_uuid'], $uuid, $item['key'], $this->optionKey($item, true), $fixture['learner'])['replayed']);
        $this->assertSame(1, (int) $this->wordRow($fixture['learner'], $item['vocabulary']['word_id'])['coverage_practice']);
    }

    public function test_erasure_fence_rejects_answer_without_progress_or_attempt_mutation(): void
    {
        $fixture = $this->fixture();
        wp_set_current_user($fixture['teacher']);
        $assignment = ll_tools_lms_assignment_create_from_category($fixture['class'], $fixture['category']);
        $this->assertIsArray($assignment);
        $this->assertTrue(ll_tools_lms_assignment_publish((int) $assignment['id'], $fixture['teacher']));
        wp_set_current_user($fixture['learner']);
        $started = ll_tools_lms_assignment_start_attempt((int) $assignment['id'], $fixture['learner']);
        $manifest = ll_tools_lms_assignment_revision_manifest(ll_tools_lms_assignment_get_revision((int) $assignment['current_revision_id']));
        $item = $manifest['items'][0];
        $lease = ll_tools_privacy_begin_user_lms_erasure($fixture['learner'], 'assignment-experience-test');
        $this->assertIsString($lease);
        $this->assertNotSame('', $lease);
        $answer = ll_tools_lms_assignment_submit_answer($started['attempt']['attempt_uuid'], wp_generate_uuid4(), $item['key'], $this->optionKey($item, true), $fixture['learner']);
        $this->assertWPError($answer);
        $this->assertWPError(ll_tools_lms_assignment_player_state($assignment['assignment_uuid'], $fixture['learner']));
        delete_option(ll_tools_privacy_user_lms_erasure_option_name($fixture['learner']));
        $this->assertIsArray(ll_tools_lms_assignment_submit_answer($started['attempt']['attempt_uuid'], wp_generate_uuid4(), $item['key'], $this->optionKey($item, true), $fixture['learner']));
    }

    private function fixture(): array
    {
        $suffix = strtolower(wp_generate_password(8, false, false));
        $teacher = self::factory()->user->create(['role' => 'll_tools_teacher']);
        $learner = self::factory()->user->create(['role' => 'll_tools_learner']);
        $wordset = wp_insert_term('Assignment experience ' . $suffix, 'wordset');
        $category = wp_insert_term('Experience category ' . $suffix, 'word-category');
        $this->assertIsArray($wordset);
        $this->assertIsArray($category);
        $wordset_id = (int) $wordset['term_id'];
        $category_id = (int) $category['term_id'];
        ll_tools_set_category_wordset_owner($category_id, $wordset_id);
        update_term_meta($category_id, 'll_quiz_prompt_type', 'text_title');
        update_term_meta($category_id, 'll_quiz_option_type', 'text_translation');
        $words = [];
        for ($i = 1; $i <= 5; $i++) {
            $id = self::factory()->post->create(['post_type' => 'words', 'post_status' => 'publish', 'post_title' => 'Hebrew ' . $i . ' ' . $suffix]);
            wp_set_post_terms($id, [$wordset_id], 'wordset');
            wp_set_post_terms($id, [$category_id], 'word-category');
            update_post_meta($id, 'translation', 'Meaning ' . $i);
            update_post_meta($id, 'word_translation', 'Meaning ' . $i);
            $words[] = $id;
        }
        $class = ll_tools_teacher_class_create($teacher, 'Experience class ' . $suffix, $wordset_id);
        $this->assertIsInt($class);
        $this->assertTrue(ll_tools_teacher_class_add_student($class, $learner));
        return ['teacher' => $teacher, 'learner' => $learner, 'class' => $class, 'wordset' => $wordset_id, 'category' => $category_id, 'words' => $words];
    }

    private function optionKey(array $item, bool $correct): string
    {
        foreach ($item['options'] as $option) { if ($option['correct'] === $correct) { return $option['key']; } }
        throw new RuntimeException('Missing fixture option');
    }

    private function wordRow(int $user_id, int $word_id): array
    {
        global $wpdb;
        $table = ll_tools_user_progress_table_names()['words'];
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE user_id=%d AND word_id=%d", $user_id, $word_id), ARRAY_A);
        $this->assertIsArray($row);
        return $row;
    }
}
