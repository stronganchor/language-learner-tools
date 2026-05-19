<?php
declare(strict_types=1);

final class VocabLessonDeferredGridTest extends LL_Tools_TestCase
{
    private const ONE_PIXEL_PNG_BASE64 =
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO8Bf6kAAAAASUVORK5CYII=';

    public function test_lesson_grid_ajax_returns_rendered_word_grid_markup(): void
    {
        $wordset = wp_insert_term('Deferred Grid Wordset', 'wordset', ['slug' => 'deferred-grid-wordset']);
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];

        $category = wp_insert_term('Deferred Grid Category', 'word-category', ['slug' => 'deferred-grid-category']);
        $this->assertIsArray($category);
        $category_id = (int) $category['term_id'];

        update_term_meta($category_id, 'll_quiz_prompt_type', 'audio');
        update_term_meta($category_id, 'll_quiz_option_type', 'text_translation');
        $this->createRecordingType('isolation', 'Isolation');

        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Nehir',
        ]);
        wp_set_post_terms($word_id, [$category_id], 'word-category', false);
        wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);
        update_post_meta($word_id, 'word_translation', 'River');
        $this->createAudioRecording($word_id, 'isolation', 'deferred-grid-nehir.mp3');

        $lesson_id = self::factory()->post->create([
            'post_type' => 'll_vocab_lesson',
            'post_status' => 'publish',
            'post_title' => 'Deferred Lesson',
        ]);
        update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_WORDSET_META, $wordset_id);
        update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, $category_id);

        $_POST = [
            'lesson_id' => $lesson_id,
            'nonce' => wp_create_nonce('ll_vocab_lesson_grid_' . $lesson_id),
        ];
        $_REQUEST = $_POST;

        try {
            $response = $this->run_json_endpoint(static function (): void {
                ll_tools_get_vocab_lesson_grid_handler();
            });
        } finally {
            $_POST = [];
            $_REQUEST = [];
        }

        $this->assertTrue($response['success']);
        $html = (string) (($response['data'] ?? [])['html'] ?? '');
        $this->assertStringContainsString('data-ll-word-grid', $html);
        $this->assertStringContainsString('Nehir', $html);
        $this->assertStringContainsString('River', $html);

        $cached_html = ll_tools_vocab_lesson_grid_public_cache_get($lesson_id, $wordset_id, $category_id);
        $this->assertIsString($cached_html);
        $this->assertStringContainsString('Nehir', $cached_html);

        ll_tools_bump_category_cache_version([$category_id]);
        $this->assertNull(ll_tools_vocab_lesson_grid_public_cache_get($lesson_id, $wordset_id, $category_id));
    }

    public function test_lesson_grid_ajax_decodes_stored_entities_for_visible_text(): void
    {
        $wordset = wp_insert_term('Encoded Entity Grid Wordset', 'wordset', ['slug' => 'encoded-entity-grid-wordset']);
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];

        $category = wp_insert_term('Encoded Entity Grid Category', 'word-category', ['slug' => 'encoded-entity-grid-category']);
        $this->assertIsArray($category);
        $category_id = (int) $category['term_id'];

        update_term_meta($category_id, 'll_quiz_prompt_type', 'audio');
        update_term_meta($category_id, 'll_quiz_option_type', 'text_translation');
        $this->createRecordingType('isolation', 'Isolation');

        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'She can&#8217;t open &amp; close the door',
        ]);
        wp_set_post_terms($word_id, [$category_id], 'word-category', false);
        wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);
        update_post_meta($word_id, 'word_translation', 'Kap&#305;y&#305; a&ccedil;am&#305;yor &amp; bekliyor');
        $this->createAudioRecording($word_id, 'isolation', 'encoded-entity-grid-word.mp3');

        $lesson_id = self::factory()->post->create([
            'post_type' => 'll_vocab_lesson',
            'post_status' => 'publish',
            'post_title' => 'Encoded Entity Lesson',
        ]);
        update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_WORDSET_META, $wordset_id);
        update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, $category_id);

        $_POST = [
            'lesson_id' => $lesson_id,
            'nonce' => wp_create_nonce('ll_vocab_lesson_grid_' . $lesson_id),
        ];
        $_REQUEST = $_POST;

        try {
            $response = $this->run_json_endpoint(static function (): void {
                ll_tools_get_vocab_lesson_grid_handler();
            });
        } finally {
            $_POST = [];
            $_REQUEST = [];
        }

        $this->assertTrue($response['success']);
        $html = (string) (($response['data'] ?? [])['html'] ?? '');
        $visible_text = html_entity_decode(wp_strip_all_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $this->assertStringNotContainsString('&amp;#8217;', $html);
        $this->assertStringNotContainsString('&amp;#305;', $html);
        $this->assertStringNotContainsString('&amp;ccedil;', $html);
        $this->assertStringContainsString(
            html_entity_decode('She can&#8217;t open &amp; close the door', ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            $visible_text
        );
        $this->assertStringContainsString(
            html_entity_decode('Kap&#305;y&#305; a&ccedil;am&#305;yor &amp; bekliyor', ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            $visible_text
        );
    }

    public function test_lesson_grid_ajax_shows_draft_words_to_staff_with_audio_status_notes(): void
    {
        $wordset = wp_insert_term('Deferred Draft Wordset', 'wordset', ['slug' => 'deferred-draft-wordset']);
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];

        $category = wp_insert_term('Deferred Draft Category', 'word-category', ['slug' => 'deferred-draft-category']);
        $this->assertIsArray($category);
        $category_id = (int) $category['term_id'];

        update_term_meta($category_id, 'll_quiz_prompt_type', 'audio');
        update_term_meta($category_id, 'll_quiz_option_type', 'text_translation');
        $this->createRecordingType('isolation', 'Isolation');

        $published_word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Published Visible',
        ]);
        wp_set_post_terms($published_word_id, [$category_id], 'word-category', false);
        wp_set_post_terms($published_word_id, [$wordset_id], 'wordset', false);
        update_post_meta($published_word_id, 'word_translation', 'Ready');
        $this->createAudioRecording($published_word_id, 'isolation', 'published-visible.mp3');

        $draft_no_audio_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'draft',
            'post_title' => 'Draft No Audio',
        ]);
        wp_set_post_terms($draft_no_audio_id, [$category_id], 'word-category', false);
        wp_set_post_terms($draft_no_audio_id, [$wordset_id], 'wordset', false);
        update_post_meta($draft_no_audio_id, 'word_translation', 'Missing audio');

        $draft_unpublished_audio_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'draft',
            'post_title' => 'Draft Unpublished Audio',
        ]);
        wp_set_post_terms($draft_unpublished_audio_id, [$category_id], 'word-category', false);
        wp_set_post_terms($draft_unpublished_audio_id, [$wordset_id], 'wordset', false);
        update_post_meta($draft_unpublished_audio_id, 'word_translation', 'Audio pending');

        $audio_id = self::factory()->post->create([
            'post_type' => 'word_audio',
            'post_status' => 'draft',
            'post_parent' => $draft_unpublished_audio_id,
            'post_title' => 'Draft child audio',
        ]);
        update_post_meta($audio_id, 'audio_file_path', '/wp-content/uploads/draft-unpublished-audio.mp3');

        $lesson_id = self::factory()->post->create([
            'post_type' => 'll_vocab_lesson',
            'post_status' => 'publish',
            'post_title' => 'Deferred Draft Lesson',
        ]);
        update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_WORDSET_META, $wordset_id);
        update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, $category_id);

        $_POST = [
            'lesson_id' => $lesson_id,
            'nonce' => wp_create_nonce('ll_vocab_lesson_grid_' . $lesson_id),
        ];
        $_REQUEST = $_POST;

        try {
            $public_response = $this->run_json_endpoint(static function (): void {
                ll_tools_get_vocab_lesson_grid_handler();
            });
        } finally {
            $_POST = [];
            $_REQUEST = [];
        }

        $this->assertTrue($public_response['success']);
        $public_html = (string) (($public_response['data'] ?? [])['html'] ?? '');
        $this->assertStringContainsString('Published Visible', $public_html);
        $this->assertStringNotContainsString('Draft No Audio', $public_html);
        $this->assertStringNotContainsString('Draft Unpublished Audio', $public_html);
        $this->assertStringNotContainsString('ll-word-item--draft', $public_html);

        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        $admin = get_user_by('id', $admin_id);
        $this->assertInstanceOf(WP_User::class, $admin);
        $admin->add_cap('view_ll_tools');
        wp_set_current_user($admin_id);

        $_POST = [
            'lesson_id' => $lesson_id,
            'nonce' => wp_create_nonce('ll_vocab_lesson_grid_' . $lesson_id),
        ];
        $_REQUEST = $_POST;

        try {
            $staff_response = $this->run_json_endpoint(static function (): void {
                ll_tools_get_vocab_lesson_grid_handler();
            });
        } finally {
            $_POST = [];
            $_REQUEST = [];
        }

        $this->assertTrue($staff_response['success']);
        $staff_html = (string) (($staff_response['data'] ?? [])['html'] ?? '');
        $this->assertStringContainsString('Published Visible', $staff_html);
        $this->assertStringContainsString('Draft No Audio', $staff_html);
        $this->assertStringContainsString('Draft Unpublished Audio', $staff_html);
        $this->assertSame(2, substr_count($staff_html, 'll-word-item--draft'));
        $this->assertSame(2, substr_count($staff_html, 'data-ll-word-status="draft"'));
        $this->assertStringContainsString('No audio recording yet.', $staff_html);
        $this->assertStringContainsString('Audio exists but is not published yet.', $staff_html);
    }

    public function test_lesson_grid_ajax_shows_published_image_mismatches_to_staff_at_bottom(): void
    {
        $wordset = wp_insert_term('Deferred Presentation Hidden Wordset', 'wordset', ['slug' => 'deferred-presentation-hidden-wordset']);
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];

        $category = wp_insert_term('Deferred Presentation Hidden Category', 'word-category', ['slug' => 'deferred-presentation-hidden-category']);
        $this->assertIsArray($category);
        $category_id = (int) $category['term_id'];

        update_term_meta($category_id, 'll_quiz_prompt_type', 'audio');
        update_term_meta($category_id, 'll_quiz_option_type', 'image');
        $this->createRecordingType('isolation', 'Isolation');

        $alpha_id = $this->createWordWithThumbnail('Alpha Visible', $category_id, $wordset_id, 'alpha-visible.png');
        update_post_meta($alpha_id, 'word_translation', 'Alpha');
        $this->createAudioRecording($alpha_id, 'isolation', 'alpha-visible.mp3');

        $hidden_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Middle Hidden',
        ]);
        wp_set_post_terms($hidden_id, [$category_id], 'word-category', false);
        wp_set_post_terms($hidden_id, [$wordset_id], 'wordset', false);
        update_post_meta($hidden_id, 'word_translation', 'Middle');
        $this->createAudioRecording($hidden_id, 'isolation', 'middle-hidden.mp3');

        $zebra_id = $this->createWordWithThumbnail('Zebra Visible', $category_id, $wordset_id, 'zebra-visible.png');
        update_post_meta($zebra_id, 'word_translation', 'Zebra');
        $this->createAudioRecording($zebra_id, 'isolation', 'zebra-visible.mp3');

        $lesson_id = self::factory()->post->create([
            'post_type' => 'll_vocab_lesson',
            'post_status' => 'publish',
            'post_title' => 'Deferred Presentation Hidden Lesson',
        ]);
        update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_WORDSET_META, $wordset_id);
        update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, $category_id);

        wp_set_current_user(0);
        $_POST = [
            'lesson_id' => $lesson_id,
            'nonce' => wp_create_nonce('ll_vocab_lesson_grid_' . $lesson_id),
        ];
        $_REQUEST = $_POST;

        try {
            $public_response = $this->run_json_endpoint(static function (): void {
                ll_tools_get_vocab_lesson_grid_handler();
            });
        } finally {
            $_POST = [];
            $_REQUEST = [];
        }

        $this->assertTrue($public_response['success']);
        $public_html = (string) (($public_response['data'] ?? [])['html'] ?? '');
        $this->assertStringContainsString('Alpha Visible', $public_html);
        $this->assertStringContainsString('Zebra Visible', $public_html);
        $this->assertStringNotContainsString('Middle Hidden', $public_html);
        $this->assertStringNotContainsString('presentation-hidden', $public_html);

        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        $admin = get_user_by('id', $admin_id);
        $this->assertInstanceOf(WP_User::class, $admin);
        $admin->add_cap('view_ll_tools');
        wp_set_current_user($admin_id);

        $_POST = [
            'lesson_id' => $lesson_id,
            'nonce' => wp_create_nonce('ll_vocab_lesson_grid_' . $lesson_id),
        ];
        $_REQUEST = $_POST;

        try {
            $staff_response = $this->run_json_endpoint(static function (): void {
                ll_tools_get_vocab_lesson_grid_handler();
            });
        } finally {
            $_POST = [];
            $_REQUEST = [];
        }

        $this->assertTrue($staff_response['success']);
        $staff_html = (string) (($staff_response['data'] ?? [])['html'] ?? '');
        $this->assertStringContainsString('Alpha Visible', $staff_html);
        $this->assertStringContainsString('Zebra Visible', $staff_html);
        $this->assertStringContainsString('Middle Hidden', $staff_html);
        $this->assertStringContainsString('ll-word-item--presentation-hidden', $staff_html);
        $this->assertStringContainsString('ll-word-item--no-image', $staff_html);
        $this->assertStringContainsString('data-ll-word-status="publish" data-ll-word-presentation-hidden="1"', $staff_html);
        $this->assertStringContainsString('ll-word-draft-notice__badge">Hidden', $staff_html);
        $this->assertStringContainsString('Published but hidden. Reason: missing image.', $staff_html);

        $position_alpha = strpos($staff_html, 'Alpha Visible');
        $position_zebra = strpos($staff_html, 'Zebra Visible');
        $position_hidden = strpos($staff_html, 'Middle Hidden');
        $this->assertIsInt($position_alpha);
        $this->assertIsInt($position_zebra);
        $this->assertIsInt($position_hidden);
        $this->assertGreaterThan($position_alpha, $position_hidden);
        $this->assertGreaterThan($position_zebra, $position_hidden);
    }

    public function test_lesson_grid_ajax_shows_published_audio_mismatches_to_staff_at_bottom(): void
    {
        $wordset = wp_insert_term('Deferred Audio Hidden Wordset', 'wordset', ['slug' => 'deferred-audio-hidden-wordset']);
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];

        $category = wp_insert_term('Deferred Audio Hidden Category', 'word-category', ['slug' => 'deferred-audio-hidden-category']);
        $this->assertIsArray($category);
        $category_id = (int) $category['term_id'];

        update_term_meta($category_id, 'll_quiz_prompt_type', 'audio');
        update_term_meta($category_id, 'll_quiz_option_type', 'image');
        $this->createRecordingType('isolation', 'Isolation');

        $alpha_id = $this->createWordWithThumbnail('Audio Alpha Visible', $category_id, $wordset_id, 'audio-alpha-visible.png');
        $this->createAudioRecording($alpha_id, 'isolation', 'audio-alpha-visible.mp3');

        $hidden_id = $this->createWordWithThumbnail('Audio Middle Hidden', $category_id, $wordset_id, 'audio-middle-hidden.png');
        $this->assertGreaterThan(0, $hidden_id);

        $zebra_id = $this->createWordWithThumbnail('Audio Zebra Visible', $category_id, $wordset_id, 'audio-zebra-visible.png');
        $this->createAudioRecording($zebra_id, 'isolation', 'audio-zebra-visible.mp3');

        $lesson_id = self::factory()->post->create([
            'post_type' => 'll_vocab_lesson',
            'post_status' => 'publish',
            'post_title' => 'Deferred Audio Hidden Lesson',
        ]);
        update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_WORDSET_META, $wordset_id);
        update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, $category_id);

        wp_set_current_user(0);
        $_POST = [
            'lesson_id' => $lesson_id,
            'nonce' => wp_create_nonce('ll_vocab_lesson_grid_' . $lesson_id),
        ];
        $_REQUEST = $_POST;

        try {
            $public_response = $this->run_json_endpoint(static function (): void {
                ll_tools_get_vocab_lesson_grid_handler();
            });
        } finally {
            $_POST = [];
            $_REQUEST = [];
        }

        $this->assertTrue($public_response['success']);
        $public_html = (string) (($public_response['data'] ?? [])['html'] ?? '');
        $this->assertStringContainsString('Audio Alpha Visible', $public_html);
        $this->assertStringContainsString('Audio Zebra Visible', $public_html);
        $this->assertStringNotContainsString('Audio Middle Hidden', $public_html);

        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        $admin = get_user_by('id', $admin_id);
        $this->assertInstanceOf(WP_User::class, $admin);
        $admin->add_cap('view_ll_tools');
        wp_set_current_user($admin_id);

        $_POST = [
            'lesson_id' => $lesson_id,
            'nonce' => wp_create_nonce('ll_vocab_lesson_grid_' . $lesson_id),
        ];
        $_REQUEST = $_POST;

        try {
            $staff_response = $this->run_json_endpoint(static function (): void {
                ll_tools_get_vocab_lesson_grid_handler();
            });
        } finally {
            $_POST = [];
            $_REQUEST = [];
        }

        $this->assertTrue($staff_response['success']);
        $staff_html = (string) (($staff_response['data'] ?? [])['html'] ?? '');
        $this->assertStringContainsString('Audio Alpha Visible', $staff_html);
        $this->assertStringContainsString('Audio Zebra Visible', $staff_html);
        $this->assertStringContainsString('Audio Middle Hidden', $staff_html);
        $this->assertStringContainsString('ll-word-item--presentation-hidden', $staff_html);
        $this->assertStringContainsString('data-ll-word-presentation-hidden-reason="missing_audio"', $staff_html);
        $this->assertStringContainsString('ll-word-draft-notice__badge">Hidden', $staff_html);
        $this->assertStringContainsString('Published but hidden. Reason: missing audio.', $staff_html);

        $position_alpha = strpos($staff_html, 'Audio Alpha Visible');
        $position_zebra = strpos($staff_html, 'Audio Zebra Visible');
        $position_hidden = strpos($staff_html, 'Audio Middle Hidden');
        $this->assertIsInt($position_alpha);
        $this->assertIsInt($position_zebra);
        $this->assertIsInt($position_hidden);
        $this->assertGreaterThan($position_alpha, $position_hidden);
        $this->assertGreaterThan($position_zebra, $position_hidden);
    }

    public function test_lesson_grid_ajax_renders_prompt_card_question_and_audio_answers(): void
    {
        $wordset = wp_insert_term('Deferred Prompt Card Wordset', 'wordset', ['slug' => 'deferred-prompt-card-wordset']);
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];

        $prompt_category = wp_insert_term('Deferred Prompt Card Category', 'word-category', ['slug' => 'deferred-prompt-card-category']);
        $this->assertIsArray($prompt_category);
        $prompt_category_id = (int) $prompt_category['term_id'];

        $asset_category = wp_insert_term('Deferred Prompt Card Assets', 'word-category', ['slug' => 'deferred-prompt-card-assets']);
        $this->assertIsArray($asset_category);
        $asset_category_id = (int) $asset_category['term_id'];

        update_term_meta($prompt_category_id, 'll_quiz_prompt_type', 'image_audio');
        update_term_meta($prompt_category_id, 'll_quiz_option_type', 'audio');
        $this->createRecordingType('isolation', 'Isolation');

        $image_word_id = $this->createWordWithThumbnail('Prompt Image', $asset_category_id, $wordset_id, 'deferred-prompt-card-image.png');
        $correct_word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Logos',
        ]);
        $wrong_word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Doron',
        ]);
        wp_set_post_terms($correct_word_id, [$asset_category_id], 'word-category', false);
        wp_set_post_terms($wrong_word_id, [$asset_category_id], 'word-category', false);
        wp_set_post_terms($correct_word_id, [$wordset_id], 'wordset', false);
        wp_set_post_terms($wrong_word_id, [$wordset_id], 'wordset', false);
        update_post_meta($correct_word_id, 'word_translation', 'Word');
        update_post_meta($wrong_word_id, 'word_translation', 'Gift');

        $this->createAudioRecording($correct_word_id, 'isolation', 'prompt-card-logos.mp3', [
            'recording_text' => 'logos',
            'recording_translation' => 'word',
            'recording_ipa' => 'logos ipa',
        ]);
        $this->createAudioRecording($wrong_word_id, 'isolation', 'prompt-card-doron.mp3', [
            'recording_text' => 'doron',
            'recording_translation' => 'gift',
            'recording_ipa' => 'doron ipa',
        ]);

        $prompt_card_id = $this->createPromptCard($prompt_category_id, $wordset_id, [
            'title' => 'What is in the picture?',
            'prompt_text' => 'What is this?',
            'prompt_translation' => 'Identify the picture.',
            'prompt_transcription' => 'prompt ipa',
            'prompt_audio_url' => 'https://example.com/prompt-card-question.mp3',
            'prompt_image_word_id' => $image_word_id,
            'correct_answer_word_id' => $correct_word_id,
            'wrong_answer_word_ids' => [$wrong_word_id],
        ]);

        $lesson_id = self::factory()->post->create([
            'post_type' => 'll_vocab_lesson',
            'post_status' => 'publish',
            'post_title' => 'Deferred Prompt Card Lesson',
        ]);
        update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_WORDSET_META, $wordset_id);
        update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, $prompt_category_id);

        $_POST = [
            'lesson_id' => $lesson_id,
            'nonce' => wp_create_nonce('ll_vocab_lesson_grid_' . $lesson_id),
        ];
        $_REQUEST = $_POST;

        try {
            $response = $this->run_json_endpoint(static function (): void {
                ll_tools_get_vocab_lesson_grid_handler();
            });
        } finally {
            $_POST = [];
            $_REQUEST = [];
        }

        $this->assertTrue($response['success']);
        $html = (string) (($response['data'] ?? [])['html'] ?? '');
        $this->assertStringContainsString('ll-vocab-prompt-card-grid', $html);
        $this->assertStringContainsString('data-prompt-card-id="' . $prompt_card_id . '"', $html);
        $this->assertStringContainsString('ll-vocab-prompt-card__question-icon', $html);
        $this->assertStringContainsString('What is this?', $html);
        $this->assertStringContainsString('Identify the picture.', $html);
        $this->assertStringContainsString('prompt ipa', $html);
        $this->assertStringContainsString('https://example.com/prompt-card-question.mp3', $html);
        $this->assertStringContainsString('class="ll-vocab-prompt-card-answer is-correct"', $html);
        $this->assertStringContainsString('class="ll-vocab-prompt-card-answer is-wrong"', $html);
        $this->assertStringContainsString('Logos', $html);
        $this->assertStringContainsString('Word', $html);
        $this->assertStringContainsString('logos ipa', $html);
        $this->assertStringContainsString('Doron', $html);
        $this->assertStringContainsString('Gift', $html);
        $this->assertStringContainsString('doron ipa', $html);
        $this->assertStringContainsString('prompt-card-logos.mp3', $html);
        $this->assertStringContainsString('prompt-card-doron.mp3', $html);
        $this->assertStringNotContainsString('No words found', $html);
    }

    public function test_sign_language_image_choice_prompt_cards_render_image_choice_lesson_grid(): void
    {
        $wordset = wp_insert_term('Sign Image Choice Wordset', 'wordset', ['slug' => 'sign-image-choice-wordset']);
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];
        update_term_meta($wordset_id, LL_TOOLS_WORDSET_SIGN_LANGUAGE_MODE_META_KEY, '1');

        $prompt_category = wp_insert_term('Sign Image Choice Category', 'word-category', ['slug' => 'sign-image-choice-category']);
        $this->assertIsArray($prompt_category);
        $prompt_category_id = (int) $prompt_category['term_id'];

        $asset_category = wp_insert_term('Sign Image Choice Assets', 'word-category', ['slug' => 'sign-image-choice-assets']);
        $this->assertIsArray($asset_category);
        $asset_category_id = (int) $asset_category['term_id'];

        update_term_meta($prompt_category_id, 'll_quiz_prompt_type', 'audio');
        update_term_meta($prompt_category_id, 'll_quiz_option_type', 'audio');

        $prompt_image_word_id = $this->createWordWithThumbnail('Tree ASL sign', $asset_category_id, $wordset_id, 'sign-image-choice-prompt.png');
        $correct_word_id = $this->createWordWithThumbnail('Tree', $asset_category_id, $wordset_id, 'sign-image-choice-tree.png');
        $wrong_word_id = $this->createWordWithThumbnail('House', $asset_category_id, $wordset_id, 'sign-image-choice-house.png');

        $prompt_card_id = $this->createPromptCard($prompt_category_id, $wordset_id, [
            'title' => 'Tree sign prompt',
            'prompt_text' => 'Choose the matching ASL sign.',
            'prompt_audio_url' => 'https://example.com/should-not-render.mp3',
            'prompt_image_word_id' => $prompt_image_word_id,
            'correct_answer_word_id' => $correct_word_id,
            'wrong_answer_word_ids' => [$wrong_word_id],
        ]);

        $lesson_id = self::factory()->post->create([
            'post_type' => 'll_vocab_lesson',
            'post_status' => 'publish',
            'post_title' => 'Sign Image Choice Lesson',
        ]);
        update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_WORDSET_META, $wordset_id);
        update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, $prompt_category_id);

        $_POST = [
            'lesson_id' => $lesson_id,
            'nonce' => wp_create_nonce('ll_vocab_lesson_grid_' . $lesson_id),
        ];
        $_REQUEST = $_POST;

        try {
            $response = $this->run_json_endpoint(static function (): void {
                ll_tools_get_vocab_lesson_grid_handler();
            });
        } finally {
            $_POST = [];
            $_REQUEST = [];
        }

        $this->assertTrue($response['success']);
        $html = (string) (($response['data'] ?? [])['html'] ?? '');
        $this->assertStringContainsString('ll-vocab-image-choice-grid', $html);
        $this->assertStringContainsString('data-ll-image-choice-lesson-grid="1"', $html);
        $this->assertStringContainsString('data-prompt-card-id="' . $prompt_card_id . '"', $html);
        $this->assertStringContainsString('ll-vocab-image-choice-card--referent', $html);
        $this->assertStringContainsString('ll-vocab-image-choice-card__referent', $html);
        $this->assertStringContainsString('ll-vocab-image-choice-referent', $html);
        $this->assertStringContainsString('Tree', $html);
        $this->assertStringContainsString('sign-image-choice-tree', $html);
        $this->assertStringNotContainsString('ll-vocab-image-choice-card__options', $html);
        $this->assertStringNotContainsString('ll-vocab-image-choice-option is-correct', $html);
        $this->assertStringNotContainsString('ll-vocab-image-choice-option is-wrong', $html);
        $this->assertStringNotContainsString('ll-vocab-image-choice-option__state', $html);
        $this->assertStringNotContainsString('House', $html);
        $this->assertStringNotContainsString('sign-image-choice-house', $html);
        $this->assertStringNotContainsString('ll-vocab-prompt-card__question-icon', $html);
        $this->assertStringNotContainsString('Choose the matching ASL sign.', $html);
        $this->assertStringNotContainsString('should-not-render.mp3', $html);
    }

    public function test_sign_language_image_to_text_prompt_cards_keep_text_grid_without_instruction_prompt(): void
    {
        $wordset = wp_insert_term('Sign Text Prompt Wordset', 'wordset', ['slug' => 'sign-text-prompt-wordset']);
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];
        update_term_meta($wordset_id, LL_TOOLS_WORDSET_SIGN_LANGUAGE_MODE_META_KEY, '1');

        $prompt_category = wp_insert_term('Sign Text Prompt Category', 'word-category', ['slug' => 'sign-text-prompt-category']);
        $this->assertIsArray($prompt_category);
        $prompt_category_id = (int) $prompt_category['term_id'];

        $asset_category = wp_insert_term('Sign Text Prompt Assets', 'word-category', ['slug' => 'sign-text-prompt-assets']);
        $this->assertIsArray($asset_category);
        $asset_category_id = (int) $asset_category['term_id'];

        update_term_meta($prompt_category_id, 'll_quiz_prompt_type', 'audio');
        update_term_meta($prompt_category_id, 'll_quiz_option_type', 'text_audio');
        update_term_meta($prompt_category_id, 'use_word_titles_for_audio', '1');

        $prompt_image_word_id = $this->createWordWithThumbnail('Airplane ASL sign', $asset_category_id, $wordset_id, 'sign-text-prompt-sign.png');
        $correct_word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Airplane',
        ]);
        $wrong_word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Apple',
        ]);
        wp_set_post_terms($correct_word_id, [$asset_category_id], 'word-category', false);
        wp_set_post_terms($wrong_word_id, [$asset_category_id], 'word-category', false);
        wp_set_post_terms($correct_word_id, [$wordset_id], 'wordset', false);
        wp_set_post_terms($wrong_word_id, [$wordset_id], 'wordset', false);

        $this->createPromptCard($prompt_category_id, $wordset_id, [
            'title' => 'Airplane sign prompt',
            'prompt_text' => 'Choose the matching ASL sign.',
            'prompt_image_word_id' => $prompt_image_word_id,
            'correct_answer_word_id' => $correct_word_id,
            'wrong_answer_word_ids' => [$wrong_word_id],
        ]);

        $lesson_id = self::factory()->post->create([
            'post_type' => 'll_vocab_lesson',
            'post_status' => 'publish',
            'post_title' => 'Sign Text Prompt Lesson',
        ]);
        update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_WORDSET_META, $wordset_id);
        update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, $prompt_category_id);

        $_POST = [
            'lesson_id' => $lesson_id,
            'nonce' => wp_create_nonce('ll_vocab_lesson_grid_' . $lesson_id),
        ];
        $_REQUEST = $_POST;

        try {
            $response = $this->run_json_endpoint(static function (): void {
                ll_tools_get_vocab_lesson_grid_handler();
            });
        } finally {
            $_POST = [];
            $_REQUEST = [];
        }

        $this->assertTrue($response['success']);
        $html = (string) (($response['data'] ?? [])['html'] ?? '');
        $this->assertStringContainsString('ll-vocab-prompt-card-grid', $html);
        $this->assertStringNotContainsString('ll-vocab-image-choice-grid', $html);
        $this->assertStringNotContainsString('Choose the matching ASL sign.', $html);
        $this->assertStringNotContainsString('ll-vocab-prompt-card__question-icon', $html);
        $this->assertStringContainsString('ll-vocab-prompt-card-answer--referent', $html);
        $this->assertStringContainsString('Airplane', $html);
        $this->assertStringNotContainsString('Apple', $html);
        $this->assertStringNotContainsString('ll-vocab-prompt-card-answer is-wrong', $html);
        $this->assertStringNotContainsString('ll-vocab-prompt-card-answer__state', $html);
    }

    public function test_prompt_card_lesson_shell_uses_full_row_loading_cards(): void
    {
        $wordset = wp_insert_term('Prompt Card Shell Wordset', 'wordset', ['slug' => 'prompt-card-shell-wordset']);
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];

        $prompt_category = wp_insert_term('Prompt Card Shell Category', 'word-category', ['slug' => 'prompt-card-shell-category']);
        $this->assertIsArray($prompt_category);
        $prompt_category_id = (int) $prompt_category['term_id'];

        $asset_category = wp_insert_term('Prompt Card Shell Assets', 'word-category', ['slug' => 'prompt-card-shell-assets']);
        $this->assertIsArray($asset_category);
        $asset_category_id = (int) $asset_category['term_id'];

        update_term_meta($prompt_category_id, 'll_quiz_prompt_type', 'image_audio');
        update_term_meta($prompt_category_id, 'll_quiz_option_type', 'audio');

        $image_word_id = $this->createWordWithThumbnail('Shell Prompt Image', $asset_category_id, $wordset_id, 'prompt-card-shell-image.png');
        $correct_word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Hippos',
        ]);
        $wrong_word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Camels',
        ]);
        wp_set_post_terms($correct_word_id, [$asset_category_id], 'word-category', false);
        wp_set_post_terms($wrong_word_id, [$asset_category_id], 'word-category', false);
        wp_set_post_terms($correct_word_id, [$wordset_id], 'wordset', false);
        wp_set_post_terms($wrong_word_id, [$wordset_id], 'wordset', false);

        $this->createPromptCard($prompt_category_id, $wordset_id, [
            'title' => 'Shell Prompt Card',
            'prompt_text' => 'Is this a hippo or a camel?',
            'prompt_audio_url' => 'https://example.com/shell-question.mp3',
            'prompt_image_word_id' => $image_word_id,
            'correct_answer_word_id' => $correct_word_id,
            'wrong_answer_word_ids' => [$wrong_word_id],
        ]);

        $lesson_id = self::factory()->post->create([
            'post_type' => 'll_vocab_lesson',
            'post_status' => 'publish',
            'post_title' => 'Prompt Card Shell Lesson',
        ]);
        update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_WORDSET_META, $wordset_id);
        update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, $prompt_category_id);

        $this->go_to('/?post_type=ll_vocab_lesson&p=' . $lesson_id);
        $this->assertTrue(is_singular('ll_vocab_lesson'));

        ob_start();
        include LL_TOOLS_BASE_PATH . '/templates/vocab-lesson-template.php';
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('ll-vocab-lesson-grid-shell--prompt-cards', $html);
        $this->assertStringContainsString('ll-vocab-prompt-card-grid--skeleton', $html);
        $this->assertStringContainsString('data-ll-prompt-card-lesson-grid="1"', $html);
        $this->assertStringContainsString('ll-vocab-lesson-skeleton-card--prompt-card', $html);
        $this->assertStringContainsString('ll-vocab-lesson-skeleton-prompt-box', $html);
        $this->assertStringContainsString('ll-vocab-lesson-skeleton-answer-list', $html);
    }

    public function test_sign_language_image_choice_lesson_shell_uses_image_choice_loading_cards(): void
    {
        $wordset = wp_insert_term('Sign Choice Shell Wordset', 'wordset', ['slug' => 'sign-choice-shell-wordset']);
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];
        update_term_meta($wordset_id, LL_TOOLS_WORDSET_SIGN_LANGUAGE_MODE_META_KEY, '1');

        $prompt_category = wp_insert_term('Sign Choice Shell Category', 'word-category', ['slug' => 'sign-choice-shell-category']);
        $this->assertIsArray($prompt_category);
        $prompt_category_id = (int) $prompt_category['term_id'];

        $asset_category = wp_insert_term('Sign Choice Shell Assets', 'word-category', ['slug' => 'sign-choice-shell-assets']);
        $this->assertIsArray($asset_category);
        $asset_category_id = (int) $asset_category['term_id'];

        update_term_meta($prompt_category_id, 'll_quiz_prompt_type', 'audio');
        update_term_meta($prompt_category_id, 'll_quiz_option_type', 'audio');

        $prompt_image_word_id = $this->createWordWithThumbnail('Shell Sign Prompt', $asset_category_id, $wordset_id, 'sign-choice-shell-prompt.png');
        $correct_word_id = $this->createWordWithThumbnail('Shell Tree', $asset_category_id, $wordset_id, 'sign-choice-shell-tree.png');
        $wrong_word_id = $this->createWordWithThumbnail('Shell House', $asset_category_id, $wordset_id, 'sign-choice-shell-house.png');

        $this->createPromptCard($prompt_category_id, $wordset_id, [
            'title' => 'Shell Sign Prompt Card',
            'prompt_text' => 'Choose the matching ASL sign.',
            'prompt_audio_url' => 'https://example.com/shell-should-not-render.mp3',
            'prompt_image_word_id' => $prompt_image_word_id,
            'correct_answer_word_id' => $correct_word_id,
            'wrong_answer_word_ids' => [$wrong_word_id],
        ]);

        $lesson_id = self::factory()->post->create([
            'post_type' => 'll_vocab_lesson',
            'post_status' => 'publish',
            'post_title' => 'Sign Choice Shell Lesson',
        ]);
        update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_WORDSET_META, $wordset_id);
        update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, $prompt_category_id);

        $this->go_to('/?post_type=ll_vocab_lesson&p=' . $lesson_id);
        $this->assertTrue(is_singular('ll_vocab_lesson'));

        ob_start();
        include LL_TOOLS_BASE_PATH . '/templates/vocab-lesson-template.php';
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('ll-vocab-lesson-grid-shell--image-choice', $html);
        $this->assertStringContainsString('ll-vocab-image-choice-grid--skeleton', $html);
        $this->assertStringContainsString('data-ll-image-choice-lesson-grid="1"', $html);
        $this->assertStringContainsString('ll-vocab-lesson-skeleton-card--image-choice', $html);
        $this->assertStringContainsString('ll-vocab-lesson-skeleton-image-choice-referent', $html);
        $this->assertStringNotContainsString('ll-vocab-lesson-skeleton-image-choice-options', $html);
        $this->assertStringNotContainsString('ll-vocab-lesson-grid-shell--prompt-cards', $html);
        $this->assertStringNotContainsString('ll-vocab-lesson-skeleton-prompt-box', $html);
    }

    public function test_lesson_grid_ajax_strips_broken_one_pixel_thumbnail_dimensions(): void
    {
        $wordset = wp_insert_term('Deferred Grid Image Wordset', 'wordset', ['slug' => 'deferred-grid-image-wordset']);
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];

        $category = wp_insert_term('Deferred Grid Image Category', 'word-category', ['slug' => 'deferred-grid-image-category']);
        $this->assertIsArray($category);
        $category_id = (int) $category['term_id'];

        update_term_meta($category_id, 'll_quiz_prompt_type', 'image');
        update_term_meta($category_id, 'll_quiz_option_type', 'image');

        $word_id = $this->createWordWithThumbnail('Fil', $category_id, $wordset_id, 'deferred-grid-fil.png');
        update_post_meta($word_id, 'word_translation', 'Elephant');

        $thumbnail_id = (int) get_post_thumbnail_id($word_id);
        $this->assertGreaterThan(0, $thumbnail_id);

        $lesson_id = self::factory()->post->create([
            'post_type' => 'll_vocab_lesson',
            'post_status' => 'publish',
            'post_title' => 'Deferred Image Lesson',
        ]);
        update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_WORDSET_META, $wordset_id);
        update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, $category_id);

        $attr_filter = static function (array $attr, WP_Post $attachment, $size) use ($thumbnail_id): array {
            if ((int) $attachment->ID !== $thumbnail_id) {
                return $attr;
            }

            $attr['width'] = 1;
            $attr['height'] = 1;
            unset($attr['srcset'], $attr['sizes']);

            return $attr;
        };

        add_filter('wp_get_attachment_image_attributes', $attr_filter, 10, 3);

        $_POST = [
            'lesson_id' => $lesson_id,
            'nonce' => wp_create_nonce('ll_vocab_lesson_grid_' . $lesson_id),
        ];
        $_REQUEST = $_POST;

        try {
            $response = $this->run_json_endpoint(static function (): void {
                ll_tools_get_vocab_lesson_grid_handler();
            });
        } finally {
            remove_filter('wp_get_attachment_image_attributes', $attr_filter, 10);
            $_POST = [];
            $_REQUEST = [];
        }

        $this->assertTrue($response['success']);
        $html = (string) (($response['data'] ?? [])['html'] ?? '');
        $this->assertStringContainsString('class="word-image', $html);
        $this->assertStringContainsString('loading="eager"', $html);
        $this->assertStringContainsString('fetchpriority="high"', $html);
        $this->assertStringNotContainsString('width="1"', $html);
        $this->assertStringNotContainsString('height="1"', $html);
    }

    public function test_lesson_template_shell_renders_early_word_text_recording_buttons_and_image_preview(): void
    {
        $wordset = wp_insert_term('Early Shell Render Wordset', 'wordset', ['slug' => 'early-shell-render-wordset']);
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];

        $category = wp_insert_term('Early Shell Render Category', 'word-category', ['slug' => 'early-shell-render-category']);
        $this->assertIsArray($category);
        $category_id = (int) $category['term_id'];

        update_term_meta($category_id, 'll_quiz_prompt_type', 'image');
        update_term_meta($category_id, 'll_quiz_option_type', 'image');
        $this->createRecordingType('question', 'Question');

        $word_id = $this->createWordWithThumbnail('Shell Early Word', $category_id, $wordset_id, 'shell-early-word.png');
        update_post_meta($word_id, 'word_translation', 'Early Translation');
        $this->createAudioRecording($word_id, 'question', 'shell-early-word-question.mp3');

        $lesson_id = self::factory()->post->create([
            'post_type' => 'll_vocab_lesson',
            'post_status' => 'publish',
            'post_title' => 'Early Shell Lesson',
        ]);
        update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_WORDSET_META, $wordset_id);
        update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, $category_id);

        $this->go_to('/?post_type=ll_vocab_lesson&p=' . $lesson_id);
        $this->assertTrue(is_singular('ll_vocab_lesson'));

        ob_start();
        include LL_TOOLS_BASE_PATH . '/templates/vocab-lesson-template.php';
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('data-ll-vocab-lesson-grid-shell', $html);
        $this->assertStringContainsString('data-ll-shell-word-id="' . $word_id . '"', $html);
        $this->assertStringContainsString('ll-vocab-lesson-shell-word-text', $html);
        $this->assertStringContainsString('Shell Early Word', $html);
        $this->assertStringContainsString('Early Translation', $html);
        $this->assertStringContainsString('ll-vocab-lesson-shell-preview-image', $html);
        $this->assertStringContainsString('ll-vocab-lesson-shell-recording-btn', $html);
        $this->assertStringContainsString('ll-study-recording-btn--question', $html);
        $this->assertMatchesRegularExpression(
            '/<button(?=[^>]*ll-vocab-lesson-shell-recording-btn)(?=[^>]*data-audio-url="[^"]*shell-early-word-question\.mp3")(?![^>]*disabled)[^>]*>/s',
            $html
        );
    }

    public function test_lesson_template_renders_warm_public_grid_cache_without_shell(): void
    {
        wp_set_current_user(0);

        $wordset = wp_insert_term('Warm Grid Cache Wordset', 'wordset', ['slug' => 'warm-grid-cache-wordset']);
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];

        $category = wp_insert_term('Warm Grid Cache Category', 'word-category', ['slug' => 'warm-grid-cache-category']);
        $this->assertIsArray($category);
        $category_id = (int) $category['term_id'];

        update_term_meta($category_id, 'll_quiz_prompt_type', 'audio');
        update_term_meta($category_id, 'll_quiz_option_type', 'text_translation');

        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Warm Cached Word',
        ]);
        wp_set_post_terms($word_id, [$category_id], 'word-category', false);
        wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);
        update_post_meta($word_id, 'word_translation', 'Warm Cached Translation');

        $lesson_id = self::factory()->post->create([
            'post_type' => 'll_vocab_lesson',
            'post_status' => 'publish',
            'post_title' => 'Warm Cached Lesson',
        ]);
        update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_WORDSET_META, $wordset_id);
        update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, $category_id);

        $cached_grid_html = '<div id="word-grid" class="word-grid ll-word-grid" data-ll-word-grid data-ll-wordset-id="' . (int) $wordset_id . '" data-ll-category-id="' . (int) $category_id . '">';
        $cached_grid_html .= '<div class="word-item" data-word-id="' . (int) $word_id . '">';
        $cached_grid_html .= '<span class="ll-word-text" data-ll-word-text>Warm Cached Word</span>';
        $cached_grid_html .= '<span class="ll-word-translation" data-ll-word-translation>Warm Cached Translation</span>';
        $cached_grid_html .= '</div></div>';

        ll_tools_vocab_lesson_grid_public_cache_set($lesson_id, $wordset_id, $category_id, $cached_grid_html);

        $this->go_to('/?post_type=ll_vocab_lesson&p=' . $lesson_id);
        $this->assertTrue(is_singular('ll_vocab_lesson'));

        ob_start();
        include LL_TOOLS_BASE_PATH . '/templates/vocab-lesson-template.php';
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('Warm Cached Word', $html);
        $this->assertStringContainsString('Warm Cached Translation', $html);
        $this->assertStringContainsString('data-ll-word-grid', $html);
        $this->assertStringNotContainsString('data-ll-vocab-lesson-grid-shell', $html);
        $this->assertStringNotContainsString('ll-vocab-lesson-skeleton-card', $html);
    }

    public function test_text_answer_lesson_grid_renders_images_when_every_visible_word_has_an_image(): void
    {
        $wordset = wp_insert_term('Deferred Text Image Wordset', 'wordset', ['slug' => 'deferred-text-image-wordset']);
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];

        $category = wp_insert_term('Deferred Text Image Category', 'word-category', ['slug' => 'deferred-text-image-category']);
        $this->assertIsArray($category);
        $category_id = (int) $category['term_id'];

        update_term_meta($category_id, 'll_quiz_prompt_type', 'audio');
        update_term_meta($category_id, 'll_quiz_option_type', 'text_title');
        $this->createRecordingType('isolation', 'Isolation');

        $word_id = $this->createWordWithThumbnail('Kedi', $category_id, $wordset_id, 'deferred-text-image-kedi.png');
        update_post_meta($word_id, 'word_translation', 'Cat');
        $this->createAudioRecording($word_id, 'isolation', 'deferred-text-image-kedi.mp3');

        $lesson_id = self::factory()->post->create([
            'post_type' => 'll_vocab_lesson',
            'post_status' => 'publish',
            'post_title' => 'Deferred Text Image Lesson',
        ]);
        update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_WORDSET_META, $wordset_id);
        update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, $category_id);

        $context = ll_tools_word_grid_resolve_context([
            'category' => 'deferred-text-image-category',
            'wordset' => 'deferred-text-image-wordset',
            'deepest_only' => true,
            'lesson_id' => $lesson_id,
        ]);
        $spec = ll_tools_word_grid_get_shell_spec($context);

        $this->assertTrue((bool) ($spec['show_media'] ?? false));
        $this->assertStringNotContainsString('ll-word-grid--text', (string) ($spec['class'] ?? ''));

        $_POST = [
            'lesson_id' => $lesson_id,
            'nonce' => wp_create_nonce('ll_vocab_lesson_grid_' . $lesson_id),
        ];
        $_REQUEST = $_POST;

        try {
            $response = $this->run_json_endpoint(static function (): void {
                ll_tools_get_vocab_lesson_grid_handler();
            });
        } finally {
            $_POST = [];
            $_REQUEST = [];
        }

        $this->assertTrue($response['success']);
        $html = (string) (($response['data'] ?? [])['html'] ?? '');
        $this->assertStringContainsString('class="word-image', $html);
        $this->assertStringNotContainsString('ll-word-grid--text', $html);
    }

    public function test_text_answer_lesson_grid_stays_text_only_when_visible_words_lack_images(): void
    {
        $wordset = wp_insert_term('Deferred Text Only Wordset', 'wordset', ['slug' => 'deferred-text-only-wordset']);
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];

        $category = wp_insert_term('Deferred Text Only Category', 'word-category', ['slug' => 'deferred-text-only-category']);
        $this->assertIsArray($category);
        $category_id = (int) $category['term_id'];

        update_term_meta($category_id, 'll_quiz_prompt_type', 'audio');
        update_term_meta($category_id, 'll_quiz_option_type', 'text_translation');

        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Metin',
        ]);
        wp_set_post_terms($word_id, [$category_id], 'word-category', false);
        wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);
        update_post_meta($word_id, 'word_translation', 'Text');

        $lesson_id = self::factory()->post->create([
            'post_type' => 'll_vocab_lesson',
            'post_status' => 'publish',
            'post_title' => 'Deferred Text Only Lesson',
        ]);
        update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_WORDSET_META, $wordset_id);
        update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, $category_id);

        $context = ll_tools_word_grid_resolve_context([
            'category' => 'deferred-text-only-category',
            'wordset' => 'deferred-text-only-wordset',
            'deepest_only' => true,
            'lesson_id' => $lesson_id,
        ]);
        $spec = ll_tools_word_grid_get_shell_spec($context);

        $this->assertFalse((bool) ($spec['show_media'] ?? true));
        $this->assertStringContainsString('ll-word-grid--text', (string) ($spec['class'] ?? ''));
    }

    public function test_text_answer_mixed_image_lesson_grid_shows_inactive_images_only_to_staff(): void
    {
        $wordset = wp_insert_term('Deferred Mixed Image Staff Wordset', 'wordset', ['slug' => 'deferred-mixed-image-staff-wordset']);
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];

        $category = wp_insert_term('Deferred Mixed Image Staff Category', 'word-category', ['slug' => 'deferred-mixed-image-staff-category']);
        $this->assertIsArray($category);
        $category_id = (int) $category['term_id'];

        update_term_meta($category_id, 'll_quiz_prompt_type', 'audio');
        update_term_meta($category_id, 'll_quiz_option_type', 'text_translation');
        $this->createRecordingType('isolation', 'Isolation');

        $image_word_id = $this->createWordWithThumbnail('Image Backed Staff Word', $category_id, $wordset_id, 'deferred-staff-inactive-image.png');
        update_post_meta($image_word_id, 'word_translation', 'Image backed');
        $this->createAudioRecording($image_word_id, 'isolation', 'deferred-staff-inactive-image.mp3');

        $text_only_word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Plain Staff Word',
        ]);
        wp_set_post_terms($text_only_word_id, [$category_id], 'word-category', false);
        wp_set_post_terms($text_only_word_id, [$wordset_id], 'wordset', false);
        update_post_meta($text_only_word_id, 'word_translation', 'Plain');
        $this->createAudioRecording($text_only_word_id, 'isolation', 'deferred-staff-plain.mp3');

        $lesson_id = self::factory()->post->create([
            'post_type' => 'll_vocab_lesson',
            'post_status' => 'publish',
            'post_title' => 'Deferred Mixed Image Staff Lesson',
        ]);
        update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_WORDSET_META, $wordset_id);
        update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, $category_id);

        wp_set_current_user(0);
        $_POST = [
            'lesson_id' => $lesson_id,
            'nonce' => wp_create_nonce('ll_vocab_lesson_grid_' . $lesson_id),
        ];
        $_REQUEST = $_POST;

        try {
            $public_response = $this->run_json_endpoint(static function (): void {
                ll_tools_get_vocab_lesson_grid_handler();
            });
        } finally {
            $_POST = [];
            $_REQUEST = [];
        }

        $this->assertTrue($public_response['success']);
        $public_html = (string) (($public_response['data'] ?? [])['html'] ?? '');
        $this->assertStringContainsString('Image Backed Staff Word', $public_html);
        $this->assertStringContainsString('Plain Staff Word', $public_html);
        $this->assertStringContainsString('ll-word-grid--text', $public_html);
        $this->assertStringNotContainsString('class="word-image', $public_html);
        $this->assertStringNotContainsString('ll-word-image-staff-overlay', $public_html);
        $this->assertStringNotContainsString('Not public: quiz does not use pictures.', $public_html);

        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        $admin = get_user_by('id', $admin_id);
        $this->assertInstanceOf(WP_User::class, $admin);
        $admin->add_cap('view_ll_tools');
        wp_set_current_user($admin_id);

        $_POST = [
            'lesson_id' => $lesson_id,
            'nonce' => wp_create_nonce('ll_vocab_lesson_grid_' . $lesson_id),
        ];
        $_REQUEST = $_POST;

        try {
            $staff_response = $this->run_json_endpoint(static function (): void {
                ll_tools_get_vocab_lesson_grid_handler();
            });
        } finally {
            $_POST = [];
            $_REQUEST = [];
        }

        $this->assertTrue($staff_response['success']);
        $staff_html = (string) (($staff_response['data'] ?? [])['html'] ?? '');
        $this->assertStringContainsString('Image Backed Staff Word', $staff_html);
        $this->assertStringContainsString('Plain Staff Word', $staff_html);
        $this->assertStringContainsString('ll-word-grid--staff-inactive-images', $staff_html);
        $this->assertStringContainsString('ll-word-item--staff-inactive-image', $staff_html);
        $this->assertStringContainsString('ll-word-image-container--staff-inactive', $staff_html);
        $this->assertStringContainsString('class="word-image', $staff_html);
        $this->assertSame(1, substr_count($staff_html, 'll-word-image-staff-overlay'));
        $this->assertStringContainsString('Not public: quiz does not use pictures.', $staff_html);
    }

    public function test_lesson_grid_naturally_sorts_visible_word_titles(): void
    {
        $wordset = wp_insert_term('Deferred Sort Wordset', 'wordset', ['slug' => 'deferred-sort-wordset']);
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];

        $category = wp_insert_term('Deferred Sort Category', 'word-category', ['slug' => 'deferred-sort-category']);
        $this->assertIsArray($category);
        $category_id = (int) $category['term_id'];

        update_term_meta($category_id, 'll_quiz_prompt_type', 'audio');
        update_term_meta($category_id, 'll_quiz_option_type', 'text_title');
        $this->createRecordingType('isolation', 'Isolation');

        $titles = ['Lesson 10', 'Lesson 2', 'Lesson 9'];
        foreach ($titles as $title) {
            $word_id = self::factory()->post->create([
                'post_type' => 'words',
                'post_status' => 'publish',
                'post_title' => $title,
            ]);
            wp_set_post_terms($word_id, [$category_id], 'word-category', false);
            wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);
            $this->createAudioRecording($word_id, 'isolation', sanitize_title($title) . '.mp3');
        }

        $lesson_id = self::factory()->post->create([
            'post_type' => 'll_vocab_lesson',
            'post_status' => 'publish',
            'post_title' => 'Deferred Sort Lesson',
        ]);
        update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_WORDSET_META, $wordset_id);
        update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, $category_id);

        $_POST = [
            'lesson_id' => $lesson_id,
            'nonce' => wp_create_nonce('ll_vocab_lesson_grid_' . $lesson_id),
        ];
        $_REQUEST = $_POST;

        try {
            $response = $this->run_json_endpoint(static function (): void {
                ll_tools_get_vocab_lesson_grid_handler();
            });
        } finally {
            $_POST = [];
            $_REQUEST = [];
        }

        $this->assertTrue($response['success']);
        $html = (string) (($response['data'] ?? [])['html'] ?? '');

        $position_two = strpos($html, 'Lesson 2');
        $position_nine = strpos($html, 'Lesson 9');
        $position_ten = strpos($html, 'Lesson 10');

        $this->assertIsInt($position_two);
        $this->assertIsInt($position_nine);
        $this->assertIsInt($position_ten);
        $this->assertLessThan($position_nine, $position_two);
        $this->assertLessThan($position_ten, $position_nine);
    }

    /**
     * @return array<string, mixed>
     */
    private function run_json_endpoint(callable $callback): array
    {
        $die_handler = static function (): void {
            throw new RuntimeException('wp_die');
        };
        $die_filter = static function () use ($die_handler) {
            return $die_handler;
        };
        $die_ajax_filter = static function () use ($die_handler) {
            return $die_handler;
        };
        $doing_ajax_filter = static function (): bool {
            return true;
        };

        add_filter('wp_die_handler', $die_filter);
        add_filter('wp_die_ajax_handler', $die_ajax_filter);
        add_filter('wp_doing_ajax', $doing_ajax_filter);

        ob_start();
        try {
            $callback();
        } catch (RuntimeException $e) {
            $this->assertSame('wp_die', $e->getMessage());
        } finally {
            $output = (string) ob_get_clean();
            remove_filter('wp_die_handler', $die_filter);
            remove_filter('wp_die_ajax_handler', $die_ajax_filter);
            remove_filter('wp_doing_ajax', $doing_ajax_filter);
        }

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded, 'Expected JSON response payload.');
        return $decoded;
    }

    public function test_shell_spec_uses_final_lesson_title_sort_for_preview_cards(): void
    {
        $wordset = wp_insert_term('Shell Sort Wordset', 'wordset', ['slug' => 'shell-sort-wordset']);
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];

        $category = wp_insert_term('Shell Sort Category', 'word-category', ['slug' => 'shell-sort-category']);
        $this->assertIsArray($category);
        $category_id = (int) $category['term_id'];

        update_term_meta($category_id, 'll_quiz_prompt_type', 'audio');
        update_term_meta($category_id, 'll_quiz_option_type', 'image');
        $this->createRecordingType('isolation', 'Isolation');

        foreach (['Lesson 10', 'Lesson 2', 'Lesson 9'] as $title) {
            $word_id = $this->createWordWithThumbnail($title, $category_id, $wordset_id, sanitize_title($title) . '.png');
            $this->createAudioRecording($word_id, 'isolation', sanitize_title($title) . '.mp3');
        }

        $lesson_id = self::factory()->post->create([
            'post_type' => 'll_vocab_lesson',
            'post_status' => 'publish',
            'post_title' => 'Shell Sort Lesson',
        ]);
        update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_WORDSET_META, $wordset_id);
        update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, $category_id);

        $context = ll_tools_word_grid_resolve_context([
            'category' => 'shell-sort-category',
            'wordset' => 'shell-sort-wordset',
            'deepest_only' => true,
            'lesson_id' => $lesson_id,
        ]);
        $spec = ll_tools_word_grid_get_shell_spec($context);
        $cards = isset($spec['cards']) && is_array($spec['cards']) ? array_values($spec['cards']) : [];
        $titles = array_map(static function (array $card): string {
            return (string) ($card['word_text'] ?? '');
        }, array_slice($cards, 0, 3));

        $this->assertSame(['Lesson 2', 'Lesson 9', 'Lesson 10'], $titles);
    }

    public function test_shell_spec_represents_every_ordered_visible_word_with_later_loading_sheen_and_audio(): void
    {
        $wordset = wp_insert_term('Shell Full Count Wordset', 'wordset', ['slug' => 'shell-full-count-wordset']);
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];

        $category = wp_insert_term('Shell Full Count Category', 'word-category', ['slug' => 'shell-full-count-category']);
        $this->assertIsArray($category);
        $category_id = (int) $category['term_id'];

        update_term_meta($category_id, 'll_quiz_prompt_type', 'audio');
        update_term_meta($category_id, 'll_quiz_option_type', 'image');
        $this->createRecordingType('question', 'Question');

        $word_ids = [];
        for ($index = 1; $index <= 8; $index++) {
            $word_id = $this->createWordWithThumbnail(
                'Shell Ordered Word ' . $index,
                $category_id,
                $wordset_id,
                'shell-ordered-word-' . $index . '.png'
            );
            $this->createAudioRecording($word_id, 'question', 'shell-ordered-word-' . $index . '-question.mp3');
            $word_ids[] = $word_id;
        }

        $lesson_id = self::factory()->post->create([
            'post_type' => 'll_vocab_lesson',
            'post_status' => 'publish',
            'post_title' => 'Shell Full Count Lesson',
        ]);
        update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_WORDSET_META, $wordset_id);
        update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, $category_id);

        $manual_order = array_reverse($word_ids);
        update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_WORD_ORDER_META, $manual_order);

        $context = ll_tools_word_grid_resolve_context([
            'category' => 'shell-full-count-category',
            'wordset' => 'shell-full-count-wordset',
            'deepest_only' => true,
            'lesson_id' => $lesson_id,
        ]);
        $spec = ll_tools_word_grid_get_shell_spec($context);
        $cards = isset($spec['cards']) && is_array($spec['cards']) ? array_values($spec['cards']) : [];

        $this->assertCount(8, $cards);
        $this->assertSame($manual_order, array_map(static function (array $card): int {
            return (int) ($card['word_id'] ?? 0);
        }, $cards));
        $this->assertNotSame('', (string) ($cards[0]['image_preview_url'] ?? ''));
        $this->assertSame('', (string) ($cards[6]['image_preview_url'] ?? ''));
        $this->assertSame(['question'], array_values((array) ($cards[6]['recording_types'] ?? [])));
        $recordings = isset($cards[6]['recordings']) && is_array($cards[6]['recordings']) ? array_values($cards[6]['recordings']) : [];
        $this->assertNotEmpty($recordings);
        $this->assertStringContainsString('shell-ordered-word-2-question.mp3', (string) ($recordings[0]['url'] ?? ''));
    }

    public function test_shell_spec_defaults_skeleton_media_to_square_when_no_aspect_ratio_is_known(): void
    {
        $wordset = wp_insert_term('Square Shell Wordset', 'wordset', ['slug' => 'square-shell-wordset']);
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];

        $category = wp_insert_term('Square Shell Category', 'word-category', ['slug' => 'square-shell-category']);
        $this->assertIsArray($category);
        $category_id = (int) $category['term_id'];

        update_term_meta($category_id, 'll_quiz_prompt_type', 'image');
        update_term_meta($category_id, 'll_quiz_option_type', 'image');

        $context = ll_tools_word_grid_resolve_context([
            'category' => 'square-shell-category',
            'wordset' => 'square-shell-wordset',
            'deepest_only' => true,
        ]);
        $spec = ll_tools_word_grid_get_shell_spec($context);
        $style = (string) (($spec['attributes']['style'] ?? ''));

        $this->assertStringContainsString('--ll-word-grid-shell-image-aspect:1 / 1;', $style);
    }

    public function test_shell_spec_uses_known_image_aspect_ratio_when_a_thumbnail_is_available(): void
    {
        $wordset = wp_insert_term('Ratio Shell Wordset', 'wordset', ['slug' => 'ratio-shell-wordset']);
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];

        $category = wp_insert_term('Ratio Shell Category', 'word-category', ['slug' => 'ratio-shell-category']);
        $this->assertIsArray($category);
        $category_id = (int) $category['term_id'];

        update_term_meta($category_id, 'll_quiz_prompt_type', 'image');
        update_term_meta($category_id, 'll_quiz_option_type', 'image');

        $attachment_id = $this->createImageAttachment('ratio-shell.png');
        wp_update_attachment_metadata($attachment_id, [
            'width' => 400,
            'height' => 300,
            'file' => (string) get_post_meta($attachment_id, '_wp_attached_file', true),
        ]);

        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Ratio Shell Word',
        ]);
        wp_set_post_terms($word_id, [$category_id], 'word-category', false);
        wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);
        set_post_thumbnail($word_id, $attachment_id);

        $context = ll_tools_word_grid_resolve_context([
            'category' => 'ratio-shell-category',
            'wordset' => 'ratio-shell-wordset',
            'deepest_only' => true,
        ]);
        $spec = ll_tools_word_grid_get_shell_spec($context);
        $style = (string) (($spec['attributes']['style'] ?? ''));

        $this->assertStringContainsString('--ll-word-grid-shell-image-aspect:4 / 3;', $style);
    }

    public function test_shell_spec_cards_follow_visible_recording_button_counts(): void
    {
        $wordset = wp_insert_term('Shell Card Count Wordset', 'wordset', ['slug' => 'shell-card-count-wordset']);
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];

        $category = wp_insert_term('Shell Card Count Category', 'word-category', ['slug' => 'shell-card-count-category']);
        $this->assertIsArray($category);
        $category_id = (int) $category['term_id'];

        update_term_meta($category_id, 'll_quiz_prompt_type', 'image');
        update_term_meta($category_id, 'll_quiz_option_type', 'image');

        $this->createRecordingType('question', 'Question');
        $this->createRecordingType('isolation', 'Isolation');

        $first_word_id = $this->createWordWithThumbnail('Shell Card One', $category_id, $wordset_id, 'shell-card-one.png');
        $second_word_id = $this->createWordWithThumbnail('Shell Card Two', $category_id, $wordset_id, 'shell-card-two.png');
        update_post_meta($first_word_id, 'word_translation', 'First Shell Translation');
        update_post_meta($second_word_id, 'word_translation', 'Second Shell Translation');

        $this->createAudioRecording($first_word_id, 'question', 'shell-card-one-question.mp3');
        $this->createAudioRecording($second_word_id, 'question', 'shell-card-two-question.mp3');
        $this->createAudioRecording($second_word_id, 'isolation', 'shell-card-two-isolation.mp3');

        $context = ll_tools_word_grid_resolve_context([
            'category' => 'shell-card-count-category',
            'wordset' => 'shell-card-count-wordset',
            'deepest_only' => true,
        ]);
        $spec = ll_tools_word_grid_get_shell_spec($context);
        $cards = isset($spec['cards']) && is_array($spec['cards']) ? array_values($spec['cards']) : [];

        $this->assertGreaterThanOrEqual(2, count($cards));
        $recording_counts = array_map(static function (array $card): int {
            return (int) ($card['recording_count'] ?? 0);
        }, $cards);
        sort($recording_counts);
        $this->assertSame([1, 2], array_slice($recording_counts, 0, 2));

        $cards_by_word_id = [];
        foreach ($cards as $card) {
            $card_word_id = (int) ($card['word_id'] ?? 0);
            if ($card_word_id > 0) {
                $cards_by_word_id[$card_word_id] = $card;
            }
        }

        $this->assertArrayHasKey($first_word_id, $cards_by_word_id);
        $this->assertArrayHasKey($second_word_id, $cards_by_word_id);
        $this->assertSame('Shell Card One', (string) ($cards_by_word_id[$first_word_id]['word_text'] ?? ''));
        $this->assertSame('First Shell Translation', (string) ($cards_by_word_id[$first_word_id]['translation_text'] ?? ''));
        $this->assertSame(['question'], array_values((array) ($cards_by_word_id[$first_word_id]['recording_types'] ?? [])));
        $this->assertSame(['question', 'isolation'], array_values((array) ($cards_by_word_id[$second_word_id]['recording_types'] ?? [])));
        $this->assertNotSame('', (string) ($cards_by_word_id[$first_word_id]['image_preview_url'] ?? ''));
    }

    public function test_bulk_deepest_filter_keeps_only_words_where_the_requested_category_is_deepest(): void
    {
        $parent = wp_insert_term('Deepest Parent', 'word-category', ['slug' => 'deepest-parent']);
        $this->assertIsArray($parent);
        $parent_id = (int) $parent['term_id'];

        $child = wp_insert_term('Deepest Child', 'word-category', [
            'slug' => 'deepest-child',
            'parent' => $parent_id,
        ]);
        $this->assertIsArray($child);
        $child_id = (int) $child['term_id'];

        $parent_only_word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Parent Only',
        ]);
        wp_set_post_terms($parent_only_word_id, [$parent_id], 'word-category', false);

        $child_word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Child Word',
        ]);
        wp_set_post_terms($child_word_id, [$parent_id, $child_id], 'word-category', false);

        $filtered_ids = ll_tools_word_grid_filter_word_ids_to_deepest_category(
            [$parent_only_word_id, $child_word_id],
            $child_id
        );

        $this->assertSame([$child_word_id], array_values($filtered_ids));
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

    private function createWordWithThumbnail(string $title, int $category_id, int $wordset_id, string $image_filename): int
    {
        $attachment_id = $this->createImageAttachment($image_filename);
        wp_update_attachment_metadata($attachment_id, [
            'width' => 300,
            'height' => 300,
            'file' => (string) get_post_meta($attachment_id, '_wp_attached_file', true),
        ]);

        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => $title,
        ]);
        wp_set_post_terms($word_id, [$category_id], 'word-category', false);
        wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);
        set_post_thumbnail($word_id, $attachment_id);

        return (int) $word_id;
    }

    /**
     * @param array<string,string> $meta
     */
    private function createAudioRecording(int $word_id, string $recording_type, string $audio_file_name, array $meta = []): int
    {
        $audio_post_id = self::factory()->post->create([
            'post_type' => 'word_audio',
            'post_status' => 'publish',
            'post_parent' => $word_id,
            'post_title' => 'Audio ' . $recording_type . ' ' . $word_id,
        ]);
        update_post_meta($audio_post_id, 'audio_file_path', '/wp-content/uploads/' . $audio_file_name);
        wp_set_post_terms($audio_post_id, [$recording_type], 'recording_type', false);
        foreach (['recording_text', 'recording_translation', 'recording_ipa'] as $meta_key) {
            if (array_key_exists($meta_key, $meta)) {
                update_post_meta($audio_post_id, $meta_key, (string) $meta[$meta_key]);
            }
        }

        return (int) $audio_post_id;
    }

    /**
     * @param array<string,mixed> $args
     */
    private function createPromptCard(int $category_id, int $wordset_id, array $args): int
    {
        $post_id = self::factory()->post->create([
            'post_type' => LL_TOOLS_PROMPT_CARD_POST_TYPE,
            'post_status' => 'publish',
            'post_title' => (string) ($args['title'] ?? 'Prompt Card'),
        ]);

        wp_set_post_terms($post_id, [$category_id], 'word-category', false);
        wp_set_post_terms($post_id, [$wordset_id], 'wordset', false);
        update_post_meta($post_id, LL_TOOLS_PROMPT_CARD_PROMPT_TEXT_META_KEY, (string) ($args['prompt_text'] ?? ''));
        update_post_meta($post_id, '_ll_prompt_card_prompt_translation', (string) ($args['prompt_translation'] ?? ''));
        update_post_meta($post_id, '_ll_prompt_card_prompt_transcription', (string) ($args['prompt_transcription'] ?? ''));
        update_post_meta($post_id, LL_TOOLS_PROMPT_CARD_PROMPT_AUDIO_URL_META_KEY, (string) ($args['prompt_audio_url'] ?? ''));
        update_post_meta($post_id, LL_TOOLS_PROMPT_CARD_PROMPT_IMAGE_WORD_ID_META_KEY, (int) ($args['prompt_image_word_id'] ?? 0));
        update_post_meta($post_id, LL_TOOLS_PROMPT_CARD_CORRECT_ANSWER_WORD_ID_META_KEY, (int) ($args['correct_answer_word_id'] ?? 0));
        update_post_meta($post_id, LL_TOOLS_PROMPT_CARD_WRONG_ANSWER_WORD_IDS_META_KEY, array_values(array_map('intval', (array) ($args['wrong_answer_word_ids'] ?? []))));

        return (int) $post_id;
    }

    private function createRecordingType(string $slug, string $label): void
    {
        $existing_term = get_term_by('slug', $slug, 'recording_type');
        if ($existing_term && !is_wp_error($existing_term)) {
            return;
        }

        $result = wp_insert_term($label, 'recording_type', ['slug' => $slug]);
        $this->assertIsArray($result);
    }
}
