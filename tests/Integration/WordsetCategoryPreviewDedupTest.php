<?php
declare(strict_types=1);

final class WordsetCategoryPreviewDedupTest extends LL_Tools_TestCase
{
    private const ONE_PIXEL_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+tmP8AAAAASUVORK5CYII=';
    private const ALT_PIXEL_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8Xw8AAoMBgQf4xX0AAAAASUVORK5CYII=';

    public function test_preview_does_not_repeat_identical_images_for_category_cards(): void
    {
        $wordset = wp_insert_term('Preview Dedup Wordset ' . wp_generate_password(6, false), 'wordset');
        $this->assertFalse(is_wp_error($wordset));
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];

        $category = wp_insert_term('Preview Dedup Category ' . wp_generate_password(6, false), 'word-category');
        $this->assertFalse(is_wp_error($category));
        $this->assertIsArray($category);
        $category_id = (int) $category['term_id'];

        $attachment_id = $this->createImageAttachment('preview-dedup-shared.png');
        $first_word_id = $this->createWordWithThumbnail($category_id, $wordset_id, $attachment_id, 'Preview Dedup Word A');
        $second_word_id = $this->createWordWithThumbnail($category_id, $wordset_id, $attachment_id, 'Preview Dedup Word B');
        $this->createAudioRecording($first_word_id, 'preview-dedup-word-a.mp3');
        $this->createAudioRecording($second_word_id, 'preview-dedup-word-b.mp3');

        $preview = ll_tools_get_wordset_category_preview($wordset_id, $category_id, 2, true);
        $this->assertIsArray($preview);

        $items = array_values((array) ($preview['items'] ?? []));
        $image_items = array_values(array_filter($items, static function ($item): bool {
            return is_array($item)
                && (($item['type'] ?? '') === 'image')
                && !empty($item['url']);
        }));
        $image_urls = array_values(array_filter(array_map(static function (array $item): string {
            return (string) ($item['url'] ?? '');
        }, $image_items)));
        $unique_image_urls = array_values(array_unique($image_urls));

        $this->assertNotEmpty($image_urls, 'Expected at least one image preview item.');
        $this->assertSame(
            count($unique_image_urls),
            count($image_urls),
            'Duplicate image URLs should not be returned for category previews.'
        );
        $this->assertSame(
            1,
            count($unique_image_urls),
            'A shared thumbnail should only appear once in the preview payload.'
        );
    }

    public function test_preview_skips_duplicate_content_from_separate_attachments_and_keeps_searching(): void
    {
        $wordset = wp_insert_term('Preview File Dedup Wordset ' . wp_generate_password(6, false), 'wordset');
        $this->assertFalse(is_wp_error($wordset));
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];

        $category = wp_insert_term('Preview File Dedup Category ' . wp_generate_password(6, false), 'word-category');
        $this->assertFalse(is_wp_error($category));
        $this->assertIsArray($category);
        $category_id = (int) $category['term_id'];

        $duplicate_attachment_a = $this->createImageAttachment('preview-dedup-file-a.png');
        $duplicate_attachment_b = $this->createImageAttachment('preview-dedup-file-b.png');
        $unique_attachment = $this->createImageAttachment('preview-dedup-file-c.png', self::ALT_PIXEL_PNG_BASE64);

        $first_word_id = $this->createWordWithThumbnail($category_id, $wordset_id, $duplicate_attachment_a, 'Preview File Dedup Word A', '2026-01-01 00:00:03');
        $second_word_id = $this->createWordWithThumbnail($category_id, $wordset_id, $duplicate_attachment_b, 'Preview File Dedup Word B', '2026-01-01 00:00:02');
        $third_word_id = $this->createWordWithThumbnail($category_id, $wordset_id, $unique_attachment, 'Preview File Dedup Word C', '2026-01-01 00:00:01');
        $this->createAudioRecording($first_word_id, 'preview-file-dedup-word-a.mp3');
        $this->createAudioRecording($second_word_id, 'preview-file-dedup-word-b.mp3');
        $this->createAudioRecording($third_word_id, 'preview-file-dedup-word-c.mp3');

        $preview = ll_tools_get_wordset_category_preview($wordset_id, $category_id, 2, true);
        $this->assertIsArray($preview);

        $image_urls = $this->extractPreviewImageUrls($preview);
        $this->assertCount(2, $image_urls, 'Expected two image preview items when a unique fallback image exists.');

        $duplicate_url_a = (string) wp_get_attachment_image_url($duplicate_attachment_a, 'medium');
        if ($duplicate_url_a === '') {
            $duplicate_url_a = (string) wp_get_attachment_url($duplicate_attachment_a);
        }
        $duplicate_url_b = (string) wp_get_attachment_image_url($duplicate_attachment_b, 'medium');
        if ($duplicate_url_b === '') {
            $duplicate_url_b = (string) wp_get_attachment_url($duplicate_attachment_b);
        }
        $unique_url = (string) wp_get_attachment_image_url($unique_attachment, 'medium');
        if ($unique_url === '') {
            $unique_url = (string) wp_get_attachment_url($unique_attachment);
        }

        $this->assertContains($unique_url, $image_urls, 'The preview should keep searching until it finds a non-duplicate image.');
        $duplicate_matches = array_values(array_intersect($image_urls, [$duplicate_url_a, $duplicate_url_b]));
        $this->assertCount(1, $duplicate_matches, 'Only one of the duplicate-content attachments should appear in the preview.');
    }

    public function test_preview_uses_prompt_card_prompt_images_when_category_has_no_words(): void
    {
        $wordset = wp_insert_term('Prompt Preview Wordset ' . wp_generate_password(6, false), 'wordset');
        $this->assertFalse(is_wp_error($wordset));
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];

        $prompt_category = wp_insert_term('Prompt Preview Category ' . wp_generate_password(6, false), 'word-category');
        $this->assertFalse(is_wp_error($prompt_category));
        $this->assertIsArray($prompt_category);
        $prompt_category_id = (int) $prompt_category['term_id'];
        update_term_meta($prompt_category_id, 'll_quiz_prompt_type', 'image_audio');
        update_term_meta($prompt_category_id, 'll_quiz_option_type', 'audio');

        $asset_category = wp_insert_term('Prompt Preview Assets ' . wp_generate_password(6, false), 'word-category');
        $this->assertFalse(is_wp_error($asset_category));
        $this->assertIsArray($asset_category);
        $asset_category_id = (int) $asset_category['term_id'];

        $first_attachment_id = $this->createImageAttachment('prompt-preview-first.png');
        $second_attachment_id = $this->createImageAttachment('prompt-preview-second.png', self::ALT_PIXEL_PNG_BASE64);
        $first_image_word_id = $this->createWordWithThumbnail($asset_category_id, $wordset_id, $first_attachment_id, 'Prompt Preview First Image');
        $second_image_word_id = $this->createWordWithThumbnail($asset_category_id, $wordset_id, $second_attachment_id, 'Prompt Preview Second Image');

        $correct_answer_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Prompt Preview Correct',
        ]);
        $this->createAudioRecording($first_image_word_id, 'prompt-preview-first.mp3');
        $this->createAudioRecording($second_image_word_id, 'prompt-preview-second.mp3');
        $this->createAudioRecording($correct_answer_id, 'prompt-preview-correct.mp3');

        $this->createPromptCard($prompt_category_id, $wordset_id, [
            'title' => 'Prompt Preview First Card',
            'prompt_image_word_id' => $first_image_word_id,
            'correct_answer_word_id' => $correct_answer_id,
        ]);
        $this->createPromptCard($prompt_category_id, $wordset_id, [
            'title' => 'Prompt Preview Second Card',
            'prompt_image_word_id' => $second_image_word_id,
            'correct_answer_word_id' => $correct_answer_id,
        ]);

        $preview = ll_tools_get_wordset_category_preview($wordset_id, $prompt_category_id, 2, true);
        $this->assertIsArray($preview);
        $this->assertTrue((bool) ($preview['has_images'] ?? false));

        $image_attachment_ids = $this->extractPreviewImageAttachmentIds($preview);
        sort($image_attachment_ids, SORT_NUMERIC);
        $expected_attachment_ids = [$first_attachment_id, $second_attachment_id];
        sort($expected_attachment_ids, SORT_NUMERIC);

        $this->assertSame($expected_attachment_ids, $image_attachment_ids);
    }

    public function test_sign_language_image_choice_preview_uses_answer_images(): void
    {
        $wordset = wp_insert_term('Sign Preview Wordset ' . wp_generate_password(6, false), 'wordset');
        $this->assertFalse(is_wp_error($wordset));
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];
        update_term_meta($wordset_id, LL_TOOLS_WORDSET_SIGN_LANGUAGE_MODE_META_KEY, '1');

        $prompt_category = wp_insert_term('Sign Preview Category ' . wp_generate_password(6, false), 'word-category');
        $this->assertFalse(is_wp_error($prompt_category));
        $this->assertIsArray($prompt_category);
        $prompt_category_id = (int) $prompt_category['term_id'];
        update_term_meta($prompt_category_id, 'll_quiz_prompt_type', 'audio');
        update_term_meta($prompt_category_id, 'll_quiz_option_type', 'audio');

        $asset_category = wp_insert_term('Sign Preview Assets ' . wp_generate_password(6, false), 'word-category');
        $this->assertFalse(is_wp_error($asset_category));
        $this->assertIsArray($asset_category);
        $asset_category_id = (int) $asset_category['term_id'];

        $prompt_attachment_id = $this->createImageAttachment('sign-preview-prompt.png');
        $answer_attachment_id = $this->createImageAttachment('sign-preview-answer.png', self::ALT_PIXEL_PNG_BASE64);
        $second_prompt_attachment_id = $this->createImageAttachment('sign-preview-prompt-second.png');
        $second_answer_attachment_id = $this->createImageAttachment('sign-preview-answer-second.png', self::ALT_PIXEL_PNG_BASE64);
        $prompt_image_word_id = $this->createWordWithThumbnail($asset_category_id, $wordset_id, $prompt_attachment_id, 'Tree Sign');
        $answer_word_id = $this->createWordWithThumbnail($asset_category_id, $wordset_id, $answer_attachment_id, 'Tree');
        $second_prompt_image_word_id = $this->createWordWithThumbnail($asset_category_id, $wordset_id, $second_prompt_attachment_id, 'Airplane Sign');
        $second_answer_word_id = $this->createWordWithThumbnail($asset_category_id, $wordset_id, $second_answer_attachment_id, 'Airplane');

        $this->createPromptCard($prompt_category_id, $wordset_id, [
            'title' => 'Sign Preview Card',
            'prompt_text' => 'Choose the matching ASL sign.',
            'prompt_image_word_id' => $prompt_image_word_id,
            'correct_answer_word_id' => $answer_word_id,
        ]);
        $this->createPromptCard($prompt_category_id, $wordset_id, [
            'title' => 'Sign Preview Second Card',
            'prompt_text' => 'Choose the matching ASL sign.',
            'prompt_image_word_id' => $second_prompt_image_word_id,
            'correct_answer_word_id' => $second_answer_word_id,
        ]);

        $preview = ll_tools_get_wordset_category_preview($wordset_id, $prompt_category_id, 2, true);
        $this->assertIsArray($preview);
        $this->assertTrue((bool) ($preview['has_images'] ?? false));

        $items = array_values((array) ($preview['items'] ?? []));
        $this->assertCount(2, $items);
        $this->assertSame('image', (string) ($items[0]['type'] ?? ''));
        $this->assertSame('image', (string) ($items[1]['type'] ?? ''));

        $image_attachment_ids = $this->extractPreviewImageAttachmentIds($preview);
        sort($image_attachment_ids, SORT_NUMERIC);
        $expected_attachment_ids = [$answer_attachment_id, $second_answer_attachment_id];
        sort($expected_attachment_ids, SORT_NUMERIC);
        $this->assertSame($expected_attachment_ids, $image_attachment_ids);
        $this->assertNotContains($prompt_attachment_id, $image_attachment_ids);
        $this->assertNotContains($second_prompt_attachment_id, $image_attachment_ids);
    }

    public function test_sign_language_image_choice_preview_does_not_fall_back_to_prompt_images_when_answer_images_missing(): void
    {
        $wordset = wp_insert_term('Sign Missing Answer Preview Wordset ' . wp_generate_password(6, false), 'wordset');
        $this->assertFalse(is_wp_error($wordset));
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];
        update_term_meta($wordset_id, LL_TOOLS_WORDSET_SIGN_LANGUAGE_MODE_META_KEY, '1');

        $prompt_category = wp_insert_term('Sign Missing Answer Preview Category ' . wp_generate_password(6, false), 'word-category');
        $this->assertFalse(is_wp_error($prompt_category));
        $this->assertIsArray($prompt_category);
        $prompt_category_id = (int) $prompt_category['term_id'];
        update_term_meta($prompt_category_id, 'll_quiz_prompt_type', 'audio');
        update_term_meta($prompt_category_id, 'll_quiz_option_type', 'audio');

        $asset_category = wp_insert_term('Sign Missing Answer Preview Assets ' . wp_generate_password(6, false), 'word-category');
        $this->assertFalse(is_wp_error($asset_category));
        $this->assertIsArray($asset_category);
        $asset_category_id = (int) $asset_category['term_id'];

        $prompt_attachment_id = $this->createImageAttachment('sign-missing-answer-prompt.png');
        $second_prompt_attachment_id = $this->createImageAttachment('sign-missing-answer-prompt-second.png', self::ALT_PIXEL_PNG_BASE64);
        $prompt_image_word_id = $this->createWordWithThumbnail($asset_category_id, $wordset_id, $prompt_attachment_id, 'Tree Sign Prompt');
        $second_prompt_image_word_id = $this->createWordWithThumbnail($asset_category_id, $wordset_id, $second_prompt_attachment_id, 'Airplane Sign Prompt');

        $answer_word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Tree',
        ]);
        $second_answer_word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Airplane',
        ]);
        foreach ([$answer_word_id, $second_answer_word_id] as $answer_id) {
            wp_set_post_terms($answer_id, [$asset_category_id], 'word-category', false);
            wp_set_post_terms($answer_id, [$wordset_id], 'wordset', false);
        }

        $this->createPromptCard($prompt_category_id, $wordset_id, [
            'title' => 'Sign Missing Answer Preview Card',
            'prompt_image_word_id' => $prompt_image_word_id,
            'correct_answer_word_id' => $answer_word_id,
        ]);
        $this->createPromptCard($prompt_category_id, $wordset_id, [
            'title' => 'Sign Missing Answer Preview Second Card',
            'prompt_image_word_id' => $second_prompt_image_word_id,
            'correct_answer_word_id' => $second_answer_word_id,
        ]);

        $preview = ll_tools_get_wordset_category_preview($wordset_id, $prompt_category_id, 2, true);
        $this->assertIsArray($preview);
        $this->assertFalse((bool) ($preview['has_images'] ?? true));
        $this->assertSame([], $this->extractPreviewImageAttachmentIds($preview));
        $this->assertNotContains($prompt_attachment_id, $this->extractPreviewImageAttachmentIds($preview));
        $this->assertNotContains($second_prompt_attachment_id, $this->extractPreviewImageAttachmentIds($preview));
    }

    public function test_sign_language_image_to_text_preview_uses_answer_text(): void
    {
        $wordset = wp_insert_term('Sign Text Preview Wordset ' . wp_generate_password(6, false), 'wordset');
        $this->assertFalse(is_wp_error($wordset));
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];
        update_term_meta($wordset_id, LL_TOOLS_WORDSET_SIGN_LANGUAGE_MODE_META_KEY, '1');

        $prompt_category = wp_insert_term('Sign Text Preview Category ' . wp_generate_password(6, false), 'word-category');
        $this->assertFalse(is_wp_error($prompt_category));
        $this->assertIsArray($prompt_category);
        $prompt_category_id = (int) $prompt_category['term_id'];
        update_term_meta($prompt_category_id, 'll_quiz_prompt_type', 'audio');
        update_term_meta($prompt_category_id, 'll_quiz_option_type', 'text_title');

        $asset_category = wp_insert_term('Sign Text Preview Assets ' . wp_generate_password(6, false), 'word-category');
        $this->assertFalse(is_wp_error($asset_category));
        $this->assertIsArray($asset_category);
        $asset_category_id = (int) $asset_category['term_id'];

        $prompt_attachment_id = $this->createImageAttachment('sign-text-preview-prompt.png');
        $second_prompt_attachment_id = $this->createImageAttachment('sign-text-preview-prompt-second.png', self::ALT_PIXEL_PNG_BASE64);
        $prompt_image_word_id = $this->createWordWithThumbnail($asset_category_id, $wordset_id, $prompt_attachment_id, 'Airplane Sign');
        $second_prompt_image_word_id = $this->createWordWithThumbnail($asset_category_id, $wordset_id, $second_prompt_attachment_id, 'Apple Sign');
        $answer_word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Airplane',
        ]);
        $second_answer_word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Apple',
        ]);
        wp_set_post_terms($answer_word_id, [$asset_category_id], 'word-category', false);
        wp_set_post_terms($second_answer_word_id, [$asset_category_id], 'word-category', false);
        wp_set_post_terms($answer_word_id, [$wordset_id], 'wordset', false);
        wp_set_post_terms($second_answer_word_id, [$wordset_id], 'wordset', false);

        $this->createPromptCard($prompt_category_id, $wordset_id, [
            'title' => 'Sign Text Preview Card',
            'prompt_text' => 'Choose the matching ASL sign.',
            'prompt_image_word_id' => $prompt_image_word_id,
            'correct_answer_word_id' => $answer_word_id,
        ]);
        $this->createPromptCard($prompt_category_id, $wordset_id, [
            'title' => 'Sign Text Preview Second Card',
            'prompt_text' => 'Choose the matching ASL sign.',
            'prompt_image_word_id' => $second_prompt_image_word_id,
            'correct_answer_word_id' => $second_answer_word_id,
        ]);

        $preview = ll_tools_get_wordset_category_preview($wordset_id, $prompt_category_id, 2, true);
        $this->assertIsArray($preview);
        $this->assertFalse((bool) ($preview['has_images'] ?? true));

        $items = array_values((array) ($preview['items'] ?? []));
        $this->assertCount(2, $items);
        $this->assertSame('text', (string) ($items[0]['type'] ?? ''));
        $this->assertSame('text', (string) ($items[1]['type'] ?? ''));
        $labels = array_map(static function (array $item): string {
            return (string) ($item['label'] ?? '');
        }, $items);
        sort($labels, SORT_STRING);
        $this->assertSame(['Airplane', 'Apple'], $labels);

        $this->assertSame([], $this->extractPreviewImageAttachmentIds($preview));
    }

    public function test_sign_language_wordset_page_category_cards_preview_answer_options(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($user_id);

        $wordset = wp_insert_term('Sign Page Preview Wordset ' . wp_generate_password(6, false), 'wordset');
        $this->assertFalse(is_wp_error($wordset));
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];
        update_term_meta($wordset_id, LL_TOOLS_WORDSET_SIGN_LANGUAGE_MODE_META_KEY, '1');

        $image_category = wp_insert_term('Sign Page Image Preview ' . wp_generate_password(6, false), 'word-category');
        $this->assertFalse(is_wp_error($image_category));
        $this->assertIsArray($image_category);
        $image_category_id = (int) $image_category['term_id'];
        update_term_meta($image_category_id, 'll_quiz_prompt_type', 'audio');
        update_term_meta($image_category_id, 'll_quiz_option_type', 'audio');
        $image_effective_category_id = $this->resolveEffectiveCategoryId($image_category_id, $wordset_id);
        update_term_meta($image_effective_category_id, 'll_quiz_prompt_type', 'audio');
        update_term_meta($image_effective_category_id, 'll_quiz_option_type', 'audio');
        $this->createVocabLesson($wordset_id, $image_effective_category_id, 'Sign Page Image Lesson');

        $text_category = wp_insert_term('Sign Page Text Preview ' . wp_generate_password(6, false), 'word-category');
        $this->assertFalse(is_wp_error($text_category));
        $this->assertIsArray($text_category);
        $text_category_id = (int) $text_category['term_id'];
        update_term_meta($text_category_id, 'll_quiz_prompt_type', 'audio');
        update_term_meta($text_category_id, 'll_quiz_option_type', 'text_title');
        $text_effective_category_id = $this->resolveEffectiveCategoryId($text_category_id, $wordset_id);
        update_term_meta($text_effective_category_id, 'll_quiz_prompt_type', 'audio');
        update_term_meta($text_effective_category_id, 'll_quiz_option_type', 'text_title');
        $this->createVocabLesson($wordset_id, $text_effective_category_id, 'Sign Page Text Lesson');

        $asset_category = wp_insert_term('Sign Page Preview Assets ' . wp_generate_password(6, false), 'word-category');
        $this->assertFalse(is_wp_error($asset_category));
        $this->assertIsArray($asset_category);
        $asset_category_id = (int) $asset_category['term_id'];

        $tree_sign_attachment_id = $this->createImageAttachment('sign-page-tree-sign.png');
        $tree_answer_attachment_id = $this->createImageAttachment('sign-page-tree-answer.png', self::ALT_PIXEL_PNG_BASE64);
        $plane_sign_attachment_id = $this->createImageAttachment('sign-page-plane-sign.png');
        $plane_answer_attachment_id = $this->createImageAttachment('sign-page-plane-answer.png', self::ALT_PIXEL_PNG_BASE64);
        $tree_sign_id = $this->createWordWithThumbnail($asset_category_id, $wordset_id, $tree_sign_attachment_id, 'Tree Sign');
        $tree_answer_id = $this->createWordWithThumbnail($image_effective_category_id, $wordset_id, $tree_answer_attachment_id, 'Tree');
        $plane_sign_id = $this->createWordWithThumbnail($asset_category_id, $wordset_id, $plane_sign_attachment_id, 'Airplane Sign');
        $plane_answer_id = $this->createWordWithThumbnail($image_effective_category_id, $wordset_id, $plane_answer_attachment_id, 'Airplane');
        $this->createAudioRecording($tree_answer_id, 'sign-page-tree-answer.mp3');
        $this->createAudioRecording($plane_answer_id, 'sign-page-plane-answer.mp3');

        $apple_sign_attachment_id = $this->createImageAttachment('sign-page-apple-sign.png');
        $animal_sign_attachment_id = $this->createImageAttachment('sign-page-animal-sign.png', self::ALT_PIXEL_PNG_BASE64);
        $apple_sign_id = $this->createWordWithThumbnail($asset_category_id, $wordset_id, $apple_sign_attachment_id, 'Apple Sign');
        $animal_sign_id = $this->createWordWithThumbnail($asset_category_id, $wordset_id, $animal_sign_attachment_id, 'Animal Sign');
        $apple_answer_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Apple',
        ]);
        $animal_answer_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Animal',
        ]);
        wp_set_post_terms($apple_answer_id, [$text_effective_category_id], 'word-category', false);
        wp_set_post_terms($animal_answer_id, [$text_effective_category_id], 'word-category', false);
        wp_set_post_terms($apple_answer_id, [$wordset_id], 'wordset', false);
        wp_set_post_terms($animal_answer_id, [$wordset_id], 'wordset', false);
        $this->createAudioRecording($apple_answer_id, 'sign-page-apple-answer.mp3');
        $this->createAudioRecording($animal_answer_id, 'sign-page-animal-answer.mp3');

        $this->createPromptCard($image_effective_category_id, $wordset_id, [
            'title' => 'Sign Page Tree Image Card',
            'prompt_image_word_id' => $tree_sign_id,
            'correct_answer_word_id' => $tree_answer_id,
        ]);
        $this->createPromptCard($image_effective_category_id, $wordset_id, [
            'title' => 'Sign Page Airplane Image Card',
            'prompt_image_word_id' => $plane_sign_id,
            'correct_answer_word_id' => $plane_answer_id,
        ]);
        $this->createPromptCard($text_effective_category_id, $wordset_id, [
            'title' => 'Sign Page Apple Text Card',
            'prompt_image_word_id' => $apple_sign_id,
            'correct_answer_word_id' => $apple_answer_id,
        ]);
        $this->createPromptCard($text_effective_category_id, $wordset_id, [
            'title' => 'Sign Page Animal Text Card',
            'prompt_image_word_id' => $animal_sign_id,
            'correct_answer_word_id' => $animal_answer_id,
        ]);

        $min_words_filter = static function (): int {
            return 1;
        };
        add_filter('ll_tools_quiz_min_words', $min_words_filter);
        try {
            $categories = ll_tools_get_wordset_page_categories($wordset_id, 2, ['defer_previews' => false]);
        } finally {
            remove_filter('ll_tools_quiz_min_words', $min_words_filter);
        }
        $image_row = $this->findCategoryRow($categories, $image_effective_category_id);
        $text_row = $this->findCategoryRow($categories, $text_effective_category_id);

        $debug_category_ids = array_map(static function ($row): int {
            return is_array($row) ? (int) ($row['id'] ?? 0) : 0;
        }, $categories);

        $this->assertIsArray($image_row, 'Expected image preview category row. Returned category IDs: ' . implode(',', $debug_category_ids));
        $this->assertTrue((bool) ($image_row['preview_requires_images'] ?? false));
        $this->assertTrue((bool) ($image_row['has_images'] ?? false));
        $this->assertSame(2, (int) ($image_row['preview_limit'] ?? 0));
        $expected_image_attachment_ids = [$tree_answer_attachment_id, $plane_answer_attachment_id];
        sort($expected_image_attachment_ids, SORT_NUMERIC);
        $this->assertSame($expected_image_attachment_ids, $this->sortedPreviewAttachmentIds($image_row));
        $this->assertNotContains($tree_sign_attachment_id, $this->sortedPreviewAttachmentIds($image_row));
        $this->assertNotContains($plane_sign_attachment_id, $this->sortedPreviewAttachmentIds($image_row));

        $this->assertIsArray($text_row);
        $this->assertFalse((bool) ($text_row['preview_requires_images'] ?? true));
        $this->assertFalse((bool) ($text_row['has_images'] ?? true));
        $this->assertSame(4, (int) ($text_row['preview_limit'] ?? 0));
        $text_items = array_values((array) ($text_row['preview'] ?? []));
        $this->assertCount(2, $text_items);
        $labels = array_map(static function (array $item): string {
            return (string) ($item['label'] ?? '');
        }, $text_items);
        sort($labels, SORT_STRING);
        $this->assertSame(['Animal', 'Apple'], $labels);
    }

    private function createWordWithThumbnail(int $category_id, int $wordset_id, int $attachment_id, string $title, string $post_date = ''): int
    {
        $post_data = [
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => $title,
        ];
        if ($post_date !== '') {
            $post_data['post_date'] = $post_date;
            $post_data['post_date_gmt'] = $post_date;
        }

        $word_id = self::factory()->post->create($post_data);

        wp_set_post_terms($word_id, [$category_id], 'word-category', false);
        wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);
        set_post_thumbnail($word_id, $attachment_id);

        return (int) $word_id;
    }

    private function createAudioRecording(int $word_id, string $audio_file_name): int
    {
        $audio_post_id = self::factory()->post->create([
            'post_type' => 'word_audio',
            'post_status' => 'publish',
            'post_parent' => $word_id,
            'post_title' => 'Audio ' . $word_id,
        ]);
        update_post_meta($audio_post_id, 'audio_file_path', '/wp-content/uploads/' . $audio_file_name);

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
            'post_title' => (string) ($args['title'] ?? 'Prompt Preview Card'),
        ]);

        wp_set_post_terms($post_id, [$category_id], 'word-category', false);
        wp_set_post_terms($post_id, [$wordset_id], 'wordset', false);
        update_post_meta($post_id, LL_TOOLS_PROMPT_CARD_PROMPT_TEXT_META_KEY, (string) ($args['prompt_text'] ?? ''));
        update_post_meta($post_id, LL_TOOLS_PROMPT_CARD_PROMPT_IMAGE_WORD_ID_META_KEY, (int) ($args['prompt_image_word_id'] ?? 0));
        update_post_meta($post_id, LL_TOOLS_PROMPT_CARD_CORRECT_ANSWER_WORD_ID_META_KEY, (int) ($args['correct_answer_word_id'] ?? 0));
        update_post_meta($post_id, LL_TOOLS_PROMPT_CARD_WRONG_ANSWER_WORD_IDS_META_KEY, array_values(array_map('intval', (array) ($args['wrong_answer_word_ids'] ?? []))));

        return (int) $post_id;
    }

    private function createVocabLesson(int $wordset_id, int $category_id, string $title): int
    {
        $lesson_id = self::factory()->post->create([
            'post_type' => 'll_vocab_lesson',
            'post_status' => 'publish',
            'post_title' => $title . ' ' . wp_generate_password(4, false),
        ]);
        update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_WORDSET_META, (string) $wordset_id);
        update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, (string) $category_id);

        return (int) $lesson_id;
    }

    private function resolveEffectiveCategoryId(int $category_id, int $wordset_id): int
    {
        if (function_exists('ll_tools_get_effective_category_id_for_wordset')) {
            $resolved = (int) ll_tools_get_effective_category_id_for_wordset($category_id, $wordset_id, true);
            if ($resolved > 0) {
                return $resolved;
            }
        }

        return $category_id;
    }

    private function findCategoryRow(array $categories, int $category_id): ?array
    {
        foreach ($categories as $row) {
            if (is_array($row) && (int) ($row['id'] ?? 0) === $category_id) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @return int[]
     */
    private function sortedPreviewAttachmentIds(array $category_row): array
    {
        $ids = $this->extractPreviewImageAttachmentIds([
            'items' => (array) ($category_row['preview'] ?? []),
        ]);
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    private function createImageAttachment(string $filename, string $base64 = self::ONE_PIXEL_PNG_BASE64): int
    {
        $bytes = base64_decode($base64, true);
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

        $metadata = function_exists('wp_generate_attachment_metadata')
            ? wp_generate_attachment_metadata($attachment_id, $file_path)
            : [];
        if (is_array($metadata) && !empty($metadata)) {
            wp_update_attachment_metadata($attachment_id, $metadata);
        }

        $relative_path = function_exists('_wp_relative_upload_path')
            ? (string) _wp_relative_upload_path($file_path)
            : '';
        if ($relative_path === '') {
            $relative_path = ltrim((string) wp_normalize_path($file_path), '/');
        }
        update_post_meta($attachment_id, '_wp_attached_file', $relative_path);

        return (int) $attachment_id;
    }

    private function extractPreviewImageUrls(array $preview): array
    {
        $items = array_values((array) ($preview['items'] ?? []));
        $image_items = array_values(array_filter($items, static function ($item): bool {
            return is_array($item)
                && (($item['type'] ?? '') === 'image')
                && !empty($item['url']);
        }));

        return array_values(array_filter(array_map(static function (array $item): string {
            return (string) ($item['url'] ?? '');
        }, $image_items)));
    }

    /**
     * @return array<int,int>
     */
    private function extractPreviewImageAttachmentIds(array $preview): array
    {
        $items = array_values((array) ($preview['items'] ?? []));
        $image_items = array_values(array_filter($items, static function ($item): bool {
            return is_array($item)
                && (($item['type'] ?? '') === 'image')
                && !empty($item['attachment_id']);
        }));

        return array_values(array_filter(array_map(static function (array $item): int {
            return (int) ($item['attachment_id'] ?? 0);
        }, $image_items), static function (int $attachment_id): bool {
            return $attachment_id > 0;
        }));
    }
}
