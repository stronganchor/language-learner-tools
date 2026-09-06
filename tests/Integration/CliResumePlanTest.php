<?php
declare(strict_types=1);

final class CliResumePlanTest extends LL_Tools_TestCase
{
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        parent::tearDown();
    }

    private function path(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'll-resume-test-');
        $this->assertIsString($path);
        $this->paths[] = $path;
        return $path;
    }

    private function plan(array $state = [], array $set = [], array $filters = [], int $wordset = 12)
    {
        return ll_tools_cli_bind_resume_plan(
            $state ?: ['version' => 2, 'processed_ids' => []],
            $wordset,
            $set ?: ['field' => 'word_translation', 'value' => 'hello'],
            $filters
        );
    }

    public function test_persisted_matching_plan_resumes_only_unprocessed_original_targets(): void
    {
        $path = $this->path();
        unlink($path);
        $state = $this->plan(ll_tools_cli_get_resume_state($path));
        $rows = [['word_id' => 11], ['word_id' => 12], ['word_id' => 13]];
        $this->assertSame($rows, ll_tools_cli_resume_select_rows($state, $rows, 0, 0));
        $this->assertTrue(ll_tools_cli_resume_mark_processed($path, $state, 11));
        $loaded = ll_tools_cli_get_resume_state($path);
        $this->assertNotWPError($loaded);
        $resumed = $this->plan($loaded);
        $this->assertNotWPError($resumed);
        $this->assertSame([['word_id' => 12], ['word_id' => 13]], ll_tools_cli_resume_select_rows(
            $resumed, array_merge($rows, [['word_id' => 14]]), 0, 0
        ));
        $this->assertSame([11, 12, 13], $resumed['target_ids']);
    }

    public function test_changed_wordset_field_value_and_every_scope_filter_are_rejected(): void
    {
        $state = $this->plan();
        $this->assertWPError($this->plan($state, [], [], 13));
        $this->assertWPError($this->plan($state, ['field' => 'word_note', 'value' => 'hello']));
        $this->assertWPError($this->plan($state, ['field' => 'word_translation', 'value' => 'goodbye']));
        foreach ([
            ['category' => 'animals'], ['word' => 'cat'], ['where_missing' => ['word_translation']],
            ['where_pos' => 'noun'], ['offset' => 1], ['limit' => 5],
        ] as $filters) {
            $this->assertWPError($this->plan($state, [], $filters));
        }
        $state['operation']['blog_id']++;
        $this->assertWPError($this->plan($state));
    }

    public function test_equivalent_missing_filter_order_and_output_path_do_not_change_identity(): void
    {
        $first = ['where_missing' => ['word_note', 'word_translation'], 'resume_file' => '/old/path'];
        $second = ['where_missing' => ['word_translation', 'word_note', 'word_note'], 'resume_file' => '/new/path'];
        $state = $this->plan([], [], $first);
        $this->assertSame($state, $this->plan($state, [], $second));
    }

    public function test_legacy_processed_state_and_partial_or_modified_identity_fail_closed(): void
    {
        $this->assertWPError($this->plan(['version' => 1, 'processed_ids' => [11]]));
        $this->assertWPError($this->plan(['version' => 2, 'processed_ids' => [], 'operation' => []]));
        $this->assertWPError($this->plan(['version' => 2, 'processed_ids' => [], 'target_ids' => [999]]));
        $state = $this->plan();
        $state['operation_fingerprint'] = str_repeat('0', 64);
        $this->assertWPError($this->plan($state));
    }

    public function test_existing_corrupt_or_unverifiable_files_are_not_treated_as_new_runs(): void
    {
        $path = $this->path();
        foreach (['', '{bad json', '{}', '{"version":99,"processed_ids":[]}',
            '{"version":2,"processed_ids":["11"]}',
            '{"version":2,"processed_ids":[],"target_ids":[999]}',
            '{"version":2,"processed_ids":[11],"target_ids":[12]}'] as $contents) {
            file_put_contents($path, $contents);
            $this->assertWPError(ll_tools_cli_get_resume_state($path));
        }
    }

    public function test_resume_does_not_reapply_offset_after_successful_rows_leave_the_missing_filter(): void
    {
        $state = $this->plan([], [], ['offset' => 1, 'limit' => 2]);
        $rows = [['word_id' => 10], ['word_id' => 11], ['word_id' => 12], ['word_id' => 13]];
        $this->assertSame([['word_id' => 11], ['word_id' => 12]], ll_tools_cli_resume_select_rows($state, $rows, 1, 2));
        $state['processed_ids'] = [11];
        $this->assertSame([['word_id' => 12]], ll_tools_cli_resume_select_rows(
            $state, [['word_id' => 12], ['word_id' => 13], ['word_id' => 14]], 1, 2
        ));
    }
}
