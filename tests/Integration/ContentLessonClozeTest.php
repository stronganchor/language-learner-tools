<?php
declare(strict_types=1);

final class ContentLessonClozeTest extends LL_Tools_TestCase
{
    private function lesson(string $source = 'A [[cat]], a [[cat]] and a [[dog]].'): int
    {
        $term = wp_insert_term('Cloze ' . wp_generate_password(8, false), 'wordset');
        $lesson = self::factory()->post->create(['post_type' => 'll_content_lesson', 'post_status' => 'publish']);
        update_post_meta($lesson, LL_TOOLS_CONTENT_LESSON_WORDSET_META, (int) $term['term_id']);
        update_post_meta($lesson, LL_TOOLS_CONTENT_LESSON_KIND_META, 'cloze');
        update_post_meta($lesson, LL_TOOLS_CONTENT_LESSON_CLOZE_SOURCE_META, wp_slash($source));
        return $lesson;
    }

    private function attempt(int $lesson): array
    {
        $parsed = ll_tools_cloze_parse_source(ll_tools_cloze_source($lesson));
        $payload = ll_tools_cloze_payload($lesson, $parsed);
        $payload['choices'] = array_map(static function ($index) use ($payload) {
            return ll_tools_cloze_token($payload['salt'], $index);
        }, array_keys($parsed['answers']));
        return $payload;
    }

    public function test_parser_preserves_hebrew_combining_marks_punctuation_and_line_breaks(): void
    {
        $source = " וַיָּבֹא [[אֵלַי]]׃\nלָמָּה [[בָּא]] אֵלַי? ";
        $parsed = ll_tools_cloze_parse_source($source);
        $this->assertIsArray($parsed);
        $this->assertSame($source, $parsed['source']);
        $this->assertSame(['אֵלַי', 'בָּא'], $parsed['answers']);
        $this->assertSame("׃\nלָמָּה ", $parsed['segments'][2]['text']);
        $this->assertSame(0, $parsed['segments'][1]['blank']);
    }

    public function test_parser_rejects_malformed_markup_and_bounds_source_and_blanks(): void
    {
        foreach (['No blanks', '[[]]', '[[ ]]', '[[ word]]', '[[nested [[word]]]]', '[[missing', 'bad]] [[word]]', "[[two\nlines]]", '<b>[[word]]</b>', "[[word]]\0", '[[' . str_repeat('x', 301) . ']]', str_repeat('[[x]] ', 61), str_repeat('a', 20000) . '[[x]]'] as $invalid) {
            $this->assertWPError(ll_tools_cloze_parse_source($invalid), $invalid);
        }
        $this->assertIsArray(ll_tools_cloze_parse_source(str_repeat('[[x]] ', 60)));
        $this->assertWPError(ll_tools_cloze_parse_source(['unexpected']));
    }

    public function test_payload_has_no_slot_answer_mapping_and_equal_tokens_are_interchangeable(): void
    {
        wp_set_current_user(0);
        $lesson = $this->lesson();
        $attempt = $this->attempt($lesson);
        $this->assertArrayNotHasKey('answers', $attempt);
        $this->assertArrayNotHasKey('source', $attempt);
        $this->assertCount(3, $attempt['bank']);
        $this->assertCount(3, array_unique(array_column($attempt['bank'], 'id')));
        $swap = $attempt['choices'][0];
        $attempt['choices'][0] = $attempt['choices'][1];
        $attempt['choices'][1] = $swap;
        $result = ll_tools_cloze_check_request($lesson, $attempt);
        $this->assertIsArray($result);
        $this->assertSame([true, true, true], $result['correct']);
        $this->assertTrue($result['allCorrect']);
        $this->assertFalse($result['saved']);
        $attempt['choices'][1] = $attempt['choices'][0];
        $this->assertWPError(ll_tools_cloze_check_request($lesson, $attempt));
    }

    public function test_wrong_answers_do_not_reveal_key_or_complete_and_stale_attempts_fail(): void
    {
        $user = self::factory()->user->create();
        wp_set_current_user($user);
        $lesson = $this->lesson();
        $attempt = $this->attempt($lesson);
        $attempt['choices'] = array_reverse($attempt['choices']);
        $result = ll_tools_cloze_check_request($lesson, $attempt);
        $this->assertSame([false, true, false], $result['correct']);
        $this->assertFalse(ll_tools_user_completed_content_lesson($lesson, $user));
        $this->assertArrayNotHasKey('answers', $result);
        $this->assertArrayNotHasKey('bank', $result);
        update_post_meta($lesson, LL_TOOLS_CONTENT_LESSON_CLOZE_SOURCE_META, 'A [[different]] lesson.');
        $stale = ll_tools_cloze_check_request($lesson, $attempt);
        $this->assertWPError($stale);
        $this->assertSame('cloze_changed', $stale->get_error_code());
    }

    public function test_completion_requires_verified_activity_and_preserves_normal_progress(): void
    {
        $user = self::factory()->user->create();
        wp_set_current_user($user);
        $lesson = $this->lesson();
        $bypass = ll_tools_update_content_lesson_completion_request($user, $lesson, true);
        $this->assertWPError($bypass);
        $this->assertSame('content_lesson_completion_forbidden', $bypass->get_error_code());
        $this->assertFalse(ll_tools_user_completed_content_lesson($lesson, $user));
        $result = ll_tools_cloze_check_request($lesson, $this->attempt($lesson));
        $this->assertTrue($result['allCorrect']);
        $this->assertTrue($result['saved']);
        $this->assertTrue(ll_tools_user_completed_content_lesson($lesson, $user));
        $clear = ll_tools_update_content_lesson_completion_request($user, $lesson, false);
        $this->assertIsArray($clear);
        $this->assertFalse($clear['completed']);
    }

    public function test_private_password_and_invalid_challenges_fail_closed(): void
    {
        wp_set_current_user(0);
        $lesson = $this->lesson();
        $attempt = $this->attempt($lesson);
        $forged = $attempt;
        $forged['signature'] = str_repeat('0', 64);
        $this->assertWPError(ll_tools_cloze_check_request($lesson, $forged));
        wp_update_post(['ID' => $lesson, 'post_password' => 'protected']);
        $this->assertFalse(ll_tools_cloze_can_view($lesson));
        $this->assertSame('', ll_tools_render_cloze_activity($lesson));
        $this->assertWPError(ll_tools_cloze_check_request($lesson, $attempt));
        wp_update_post(['ID' => $lesson, 'post_password' => '']);
        update_term_meta(ll_tools_get_content_lesson_wordset_id($lesson), LL_TOOLS_WORDSET_VISIBILITY_META_KEY, 'private');
        $this->assertFalse(ll_tools_cloze_can_view($lesson));
        $this->assertSame('', ll_tools_render_cloze_activity($lesson));
        $this->assertWPError(ll_tools_cloze_check_request($lesson, $attempt));
    }

    public function test_autosave_checks_capability_scope_conflicts_and_exact_backslashes(): void
    {
        $lesson = $this->lesson();
        $original = ll_tools_cloze_source($lesson);
        wp_set_current_user(self::factory()->user->create());
        $this->assertWPError(ll_tools_cloze_save_request($lesson, 'New [[word]].', hash('sha256', $original)));
        $manager = self::factory()->user->create(['role' => 'administrator']);
        get_user_by('id', $manager)->add_cap('view_ll_tools');
        wp_set_current_user($manager);
        $new = 'A \\ path and [[word]].';
        $saved = ll_tools_cloze_save_request($lesson, $new, hash('sha256', $original));
        $this->assertIsArray($saved);
        $this->assertSame($new, ll_tools_cloze_source($lesson));
        $this->assertIsArray(ll_tools_cloze_save_request($lesson, $new, hash('sha256', $original)), 'Lost response can retry unchanged draft safely.');
        $this->assertWPError(ll_tools_cloze_save_request($lesson, 'Other [[word]].', hash('sha256', $original)));
        $this->assertWPError(ll_tools_cloze_save_request($lesson, 'Invalid [[]].', $saved['hash']));
        $this->assertSame($new, ll_tools_cloze_source($lesson));
        $backup = $_POST;
        try {
            $_POST = ['ll_content_lesson_cloze_source' => 'Stale [[word]].', 'll_content_lesson_cloze_hash' => hash('sha256', $original)];
            ll_tools_cloze_save_metabox($lesson);
        } finally { $_POST = $backup; }
        $this->assertSame($new, ll_tools_cloze_source($lesson));
    }

    public function test_cloze_card_is_text_and_page_is_never_shared_static_cache(): void
    {
        wp_set_current_user(0);
        $lesson = $this->lesson();
        $card = ll_tools_get_content_lesson_card_data(get_post($lesson));
        $this->assertSame('text', $card['media_type']);
        $this->assertSame('cloze', $card['lesson_kind']);
        $this->go_to(get_permalink($lesson));
        $this->assertTrue(is_singular('ll_content_lesson'));
        $this->assertNull(ll_tools_public_static_cache_request_identity());
        $html = ll_tools_render_cloze_activity($lesson);
        $this->assertStringContainsString('data-ll-cloze-payload', $html);
        $this->assertStringNotContainsString('[[', $html);
        $this->assertStringNotContainsString('"answers"', $html);
    }
}
