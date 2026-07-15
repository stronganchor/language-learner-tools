<?php

final class LL_Tools_Hidden_Recording_Counting_String
{
    public static int $casts = 0;

    private string $value;

    public function __construct(string $value)
    {
        $this->value = $value;
    }

    public function __toString(): string
    {
        self::$casts++;
        return $this->value;
    }
}

final class AudioRecordingHiddenWordsRequestCacheTest extends LL_Tools_TestCase
{
    protected function tearDown(): void
    {
        ll_tools_invalidate_hidden_recording_words_request_cache();
        parent::tearDown();
    }

    public function test_twenty_summary_lookups_reuse_one_normalized_hidden_snapshot_and_save_invalidates_it(): void
    {
        $recorder_id = self::factory()->user->create(['role' => 'subscriber']);
        $meta_key = ll_tools_recording_hidden_words_meta_key();

        update_user_meta($recorder_id, $meta_key, [
            'word:101' => [
                'key'       => 'word:101',
                'word_id'   => 101,
                'title'     => new LL_Tools_Hidden_Recording_Counting_String("\u{00C7}ilek"),
                'hidden_at' => '2026-07-15 01:00:00',
            ],
            'word:102' => [
                'key'       => 'word:102',
                'word_id'   => 102,
                'title'     => new LL_Tools_Hidden_Recording_Counting_String('Ceviz'),
                'hidden_at' => '2026-07-15 01:00:00',
            ],
        ]);
        ll_tools_invalidate_hidden_recording_words_request_cache($recorder_id);
        wp_cache_delete($recorder_id, 'user_meta');

        LL_Tools_Hidden_Recording_Counting_String::$casts = 0;
        $metadata_reads = 0;
        $metadata_filter = static function ($value, $object_id, $requested_key, $single) use ($recorder_id, $meta_key, &$metadata_reads) {
            unset($single);
            if ((int) $object_id === $recorder_id && (string) $requested_key === $meta_key) {
                $metadata_reads++;
            }
            return $value;
        };
        add_filter('get_user_metadata', $metadata_filter, 10, 4);

        try {
            $casts_after_first_lookup = 0;
            for ($category_index = 0; $category_index < 20; $category_index++) {
                $lookup = ll_tools_get_hidden_recording_word_lookup($recorder_id);
                $this->assertArrayHasKey('word:101', $lookup);
                $this->assertArrayHasKey('word:102', $lookup);
                if ($category_index === 0) {
                    $casts_after_first_lookup = LL_Tools_Hidden_Recording_Counting_String::$casts;
                    $this->assertGreaterThan(0, $casts_after_first_lookup);
                }
            }

            $this->assertSame(1, $metadata_reads, 'A 20-category summary batch should read the recorder hidden meta only once.');
            $this->assertSame(
                $casts_after_first_lookup,
                LL_Tools_Hidden_Recording_Counting_String::$casts,
                'Repeated summary lookups should not normalize or sort the same hidden rows again.'
            );
            $this->assertSame(
                ['word:102', 'word:101'],
                array_column(ll_tools_get_hidden_recording_words_list($recorder_id), 'key'),
                'The cached list must retain locale-aware title ordering for equal timestamps.'
            );
            $this->assertSame(1, $metadata_reads);

            $this->assertTrue(ll_tools_save_hidden_recording_words($recorder_id, [
                'word:103' => [
                    'key'       => 'word:103',
                    'word_id'   => 103,
                    'title'     => 'New hidden row',
                    'hidden_at' => '2026-07-15 02:00:00',
                ],
            ]));
            $reads_after_save = $metadata_reads;

            $updated_lookup = ll_tools_get_hidden_recording_word_lookup($recorder_id);
            $this->assertArrayHasKey('word:103', $updated_lookup);
            $this->assertArrayNotHasKey('word:101', $updated_lookup);
            $this->assertSame(
                $reads_after_save + 1,
                $metadata_reads,
                'The first read after save must rebuild from the newly persisted raw value.'
            );

            $reads_after_rebuild = $metadata_reads;
            for ($category_index = 0; $category_index < 20; $category_index++) {
                $this->assertArrayHasKey('word:103', ll_tools_get_hidden_recording_word_lookup($recorder_id));
            }
            $this->assertSame($reads_after_rebuild, $metadata_reads);
        } finally {
            remove_filter('get_user_metadata', $metadata_filter, 10);
        }
    }

    public function test_request_cache_is_scoped_per_recorder_and_invalidated_by_direct_meta_writes(): void
    {
        $first_recorder_id = self::factory()->user->create(['role' => 'subscriber']);
        $second_recorder_id = self::factory()->user->create(['role' => 'subscriber']);
        $meta_key = ll_tools_recording_hidden_words_meta_key();

        update_user_meta($first_recorder_id, $meta_key, [
            'word:201' => [
                'key' => 'word:201',
                'word_id' => 201,
                'title' => 'First recorder row',
            ],
        ]);
        update_user_meta($second_recorder_id, $meta_key, [
            'word:301' => [
                'key' => 'word:301',
                'word_id' => 301,
                'title' => 'Second recorder row',
            ],
        ]);

        $first_lookup = ll_tools_get_hidden_recording_word_lookup($first_recorder_id);
        $second_lookup = ll_tools_get_hidden_recording_word_lookup($second_recorder_id);
        $this->assertArrayHasKey('word:201', $first_lookup);
        $this->assertArrayNotHasKey('word:301', $first_lookup);
        $this->assertArrayHasKey('word:301', $second_lookup);
        $this->assertArrayNotHasKey('word:201', $second_lookup);

        update_user_meta($first_recorder_id, $meta_key, [
            'word:202' => [
                'key' => 'word:202',
                'word_id' => 202,
                'title' => 'Replacement first-recorder row',
            ],
        ]);

        $updated_first_lookup = ll_tools_get_hidden_recording_word_lookup($first_recorder_id);
        $this->assertArrayHasKey('word:202', $updated_first_lookup);
        $this->assertArrayNotHasKey('word:201', $updated_first_lookup);
        $this->assertSame($second_lookup, ll_tools_get_hidden_recording_word_lookup($second_recorder_id));
    }

    public function test_save_retains_the_five_hundred_entry_cap(): void
    {
        $recorder_id = self::factory()->user->create(['role' => 'subscriber']);
        $entries = [];
        $newest_timestamp = 2000000000;

        for ($index = 0; $index <= 500; $index++) {
            $word_id = $index + 1;
            $entries['word:' . $word_id] = [
                'key'       => 'word:' . $word_id,
                'word_id'   => $word_id,
                'title'     => 'Hidden row ' . $word_id,
                'hidden_at' => gmdate('Y-m-d H:i:s', $newest_timestamp - $index),
            ];
        }

        $this->assertTrue(ll_tools_save_hidden_recording_words($recorder_id, $entries));
        $saved = ll_tools_get_hidden_recording_words($recorder_id);

        $this->assertCount(500, $saved);
        $this->assertArrayHasKey('word:1', $saved);
        $this->assertArrayNotHasKey('word:501', $saved);
    }
}
