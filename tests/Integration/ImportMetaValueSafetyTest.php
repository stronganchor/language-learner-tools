<?php
declare(strict_types=1);

final class LL_Tools_Test_Import_Meta_Wakeup_Payload
{
    public static int $wakeupCount = 0;

    public function __wakeup(): void
    {
        self::$wakeupCount++;
    }
}

final class ImportMetaValueSafetyTest extends LL_Tools_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        LL_Tools_Test_Import_Meta_Wakeup_Payload::$wakeupCount = 0;
    }

    public function test_safe_decoder_preserves_plain_and_legacy_scalar_and_array_values(): void
    {
        $plain_array = [
            'nested' => ['count' => 3, 'enabled' => true],
            'serialized-looking text' => 'a:1:{i:0;s:4:"word";}',
        ];

        $this->assertSame('plain text', ll_tools_import_decode_legacy_meta_value('plain text'));
        $this->assertSame(27, ll_tools_import_decode_legacy_meta_value(27));
        $this->assertSame(false, ll_tools_import_decode_legacy_meta_value(false));
        $this->assertSame(null, ll_tools_import_decode_legacy_meta_value(null));
        $this->assertSame($plain_array, ll_tools_import_decode_legacy_meta_value($plain_array));
        $this->assertSame(42, ll_tools_import_decode_legacy_meta_value(serialize(42)));
        $this->assertFalse(ll_tools_import_decode_legacy_meta_value(serialize(false)));
        $this->assertSame(
            ['mode' => 'legacy', 'ids' => [3, 7]],
            ll_tools_import_decode_legacy_meta_value(serialize(['mode' => 'legacy', 'ids' => [3, 7]]))
        );
    }

    public function test_safe_decoder_and_comparison_reject_serialized_objects_without_wakeup(): void
    {
        $serialized_object = serialize(new LL_Tools_Test_Import_Meta_Wakeup_Payload());
        $serialized_nested_object = serialize([
            'safe' => 'value',
            'payload' => new LL_Tools_Test_Import_Meta_Wakeup_Payload(),
        ]);

        $direct_result = ll_tools_import_decode_legacy_meta_value($serialized_object);
        $nested_result = ll_tools_import_decode_legacy_meta_value($serialized_nested_object);
        $comparison_result = ll_tools_import_normalize_meta_for_compare([
            'll_bundle_meta' => [$serialized_nested_object],
        ]);

        $this->assertWPError($direct_result);
        $this->assertWPError($nested_result);
        $this->assertWPError($comparison_result);
        $this->assertSame(0, LL_Tools_Test_Import_Meta_Wakeup_Payload::$wakeupCount);
    }

    public function test_safe_decoder_rejects_cyclic_and_overly_deep_serialized_arrays(): void
    {
        $cyclic = [];
        $cyclic['self'] = &$cyclic;

        $deep = 'leaf';
        for ($depth = 0; $depth < 65; $depth++) {
            $deep = [$deep];
        }

        $this->assertWPError(ll_tools_import_decode_legacy_meta_value(serialize($cyclic)));
        $this->assertWPError(ll_tools_import_decode_legacy_meta_value(serialize($deep)));
    }

    public function test_meta_replacement_preserves_existing_values_when_import_contains_object_payload(): void
    {
        $serialized_object = serialize(new LL_Tools_Test_Import_Meta_Wakeup_Payload());
        $post_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'draft',
            'post_title' => 'Safe legacy meta import',
        ]);
        update_post_meta($post_id, 'll_safe_meta', 'existing post value');
        update_post_meta($post_id, 'word_translation', 'existing translation');
        update_post_meta($post_id, 'word_english_meaning', 'existing meaning');

        ll_tools_import_replace_post_meta_values($post_id, [
            'll_safe_meta' => [$serialized_object],
            'word_translation' => [$serialized_object],
            'word_english_meaning' => ['new meaning'],
            'll_legacy_array' => [serialize(['safe' => ['nested' => true]])],
        ], 'words');

        $this->assertSame('existing post value', (string) get_post_meta($post_id, 'll_safe_meta', true));
        $this->assertSame('existing translation', (string) get_post_meta($post_id, 'word_translation', true));
        $this->assertSame('existing meaning', (string) get_post_meta($post_id, 'word_english_meaning', true));
        $this->assertSame(['safe' => ['nested' => true]], get_post_meta($post_id, 'll_legacy_array', true));

        $term = wp_insert_term('Safe legacy meta term', 'word-category');
        $this->assertIsArray($term);
        $term_id = (int) $term['term_id'];
        update_term_meta($term_id, 'display_color', 'existing term value');
        ll_tools_import_replace_term_meta_values($term_id, [
            'display_color' => [$serialized_object],
        ], 'word-category');

        $this->assertSame('existing term value', (string) get_term_meta($term_id, 'display_color', true));
        $this->assertSame(0, LL_Tools_Test_Import_Meta_Wakeup_Payload::$wakeupCount);
    }

    public function test_undo_skips_unsafe_snapshot_value_without_deleting_current_meta_or_wakeup(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $post_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Unsafe undo snapshot guard',
        ]);
        update_post_meta($post_id, 'word_translation', 'current safe value');

        $undo = ll_tools_import_default_undo_payload();
        $undo['updated_post_snapshots'] = [
            'words' => [
                (string) $post_id => [
                    'post_fields' => [],
                    'meta' => [
                        'word_translation' => [serialize(new LL_Tools_Test_Import_Meta_Wakeup_Payload())],
                    ],
                ],
            ],
        ];

        $result = ll_tools_undo_import_entry(['undo' => $undo]);

        $this->assertFalse((bool) ($result['ok'] ?? true));
        $this->assertSame('current safe value', (string) get_post_meta($post_id, 'word_translation', true));
        $this->assertSame(0, LL_Tools_Test_Import_Meta_Wakeup_Payload::$wakeupCount);
        $this->assertStringContainsString('stored value was unsafe', implode(' | ', (array) ($result['errors'] ?? [])));
    }
}
