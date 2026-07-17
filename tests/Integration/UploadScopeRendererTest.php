<?php
declare(strict_types=1);

final class UploadScopeRendererTest extends LL_Tools_TestCase
{
    public function test_audio_and_image_forms_preserve_shared_scope_contract_and_specific_copy(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $wordset_one_id = $this->ensureTerm('Upload Scope Renderer One', 'upload-scope-renderer-one');
        $this->ensureTerm('Upload Scope Renderer Two', 'upload-scope-renderer-two');

        $audio_html = ll_audio_upload_form_shortcode();
        $image_html = ll_image_upload_form_shortcode();

        $shared_fragments = [
            'data-ll-wordset-scope-root',
            'name="ll_wordset_scope_mode" value="single" checked data-ll-scope-mode',
            'name="ll_wordset_scope_mode" value="multiple" data-ll-scope-mode',
            'data-ll-single-wordset-wrap',
            'name="ll_single_wordset_id" class="regular-text" data-ll-single-wordset',
            'data-ll-multi-wordset-wrap',
            'name="ll_multi_wordset_ids[]"',
            'data-ll-multi-wordset',
            'data-ll-wordset-label=',
            'Choose where this upload should land before selecting a category.',
        ];
        foreach ([$audio_html, $image_html] as $html) {
            foreach ($shared_fragments as $fragment) {
                $this->assertStringContainsString($fragment, $html);
            }
            $this->assertStringContainsString('— Select —', $html);
            $this->assertStringNotContainsString('â€', $html);
            $this->assertSame(2, substr_count($html, 'data-ll-scope-mode'));
        }

        $this->assertStringContainsString('for="ll-audio-upload-single-wordset"', $audio_html);
        $this->assertStringContainsString('id="ll-audio-upload-single-wordset"', $audio_html);
        $this->assertStringContainsString(
            'New words will be created in the same logical category across every selected word set.',
            $audio_html
        );
        $this->assertStringNotContainsString('ll-image-upload-single-wordset', $audio_html);

        $this->assertStringContainsString('for="ll-image-upload-single-wordset"', $image_html);
        $this->assertStringContainsString('id="ll-image-upload-single-wordset"', $image_html);
        $this->assertStringContainsString(
            'Each uploaded image will be assigned to the same logical category in every selected word set.',
            $image_html
        );
        $this->assertStringNotContainsString('ll-audio-upload-single-wordset', $image_html);

        $locked_audio_html = ll_audio_upload_form_shortcode([
            'wordset_id' => (string) $wordset_one_id,
            'lock_wordset' => '1',
        ]);
        $locked_image_html = ll_image_upload_form_shortcode([
            'wordset_id' => (string) $wordset_one_id,
            'lock_wordset' => '1',
        ]);

        foreach ([$locked_audio_html, $locked_image_html] as $html) {
            $this->assertStringContainsString('name="ll_wordset_scope_mode" value="single"', $html);
            $this->assertStringContainsString('name="ll_single_wordset_id" value="' . $wordset_one_id . '"', $html);
            $this->assertStringContainsString('data-ll-wordset-scope-locked="1"', $html);
            $this->assertStringContainsString('Locked to this word set', $html);
            $this->assertStringNotContainsString('name="ll_multi_wordset_ids[]"', $html);
        }
        $this->assertStringContainsString(
            'Audio uploads will use this word set automatically.',
            $locked_audio_html
        );
        $this->assertStringContainsString(
            'Images and any generated words will use this word set automatically.',
            $locked_image_html
        );
    }

    public function test_renderer_preserves_form_specific_empty_scope_copy(): void
    {
        $audio_html = $this->renderEmptyScope(
            'll-audio-upload',
            'No word sets are available for audio upload right now.'
        );
        $image_html = $this->renderEmptyScope(
            'll-image-upload',
            'No word sets are available for image upload right now.'
        );

        $this->assertStringContainsString('data-ll-wordset-scope-root', $audio_html);
        $this->assertStringContainsString('No word sets are available for audio upload right now.', $audio_html);
        $this->assertStringNotContainsString('No word sets are available for image upload right now.', $audio_html);
        $this->assertStringContainsString('data-ll-wordset-scope-root', $image_html);
        $this->assertStringContainsString('No word sets are available for image upload right now.', $image_html);
        $this->assertStringNotContainsString('No word sets are available for audio upload right now.', $image_html);
    }

    private function renderEmptyScope(string $id_prefix, string $empty_description): string
    {
        ob_start();
        ll_tools_render_upload_wordset_scope([
            'wordsets' => [],
            'wordset_selection_locked' => false,
            'default_single_wordset_id' => 0,
            'preselected_wordset' => null,
            'lock_wordset' => false,
            'id_prefix' => $id_prefix,
            'automatic_description' => '',
            'multiple_description' => '',
            'empty_description' => $empty_description,
        ]);

        return (string) ob_get_clean();
    }

    private function ensureTerm(string $name, string $slug): int
    {
        $existing = term_exists($slug, 'wordset');
        if (is_array($existing) && !empty($existing['term_id'])) {
            return (int) $existing['term_id'];
        }
        if (is_int($existing) && $existing > 0) {
            return $existing;
        }

        $inserted = wp_insert_term($name, 'wordset', ['slug' => $slug]);
        $this->assertIsArray($inserted);

        return (int) $inserted['term_id'];
    }
}
