<?php
declare(strict_types=1);

final class SimulatedRequestBoundaryTest extends LL_Tools_TestCase
{
    public function test_boundary_finalizes_mutations_and_resets_request_guards(): void
    {
        $orphan_registry_option = LL_TOOLS_ORPHAN_MEDIA_OPTION_REGISTRY;
        $orphan_registry_backup = get_option($orphan_registry_option, null);
        $buttons_epoch_option = 'll_tools_wordset_buttons_generation_epoch';
        $buttons_epoch_backup = get_option($buttons_epoch_option, null);
        $option_names = [
            'image' => 'll_tools_wordset_editor_image_aggregate_epoch',
            'structure' => 'll_tools_wordset_recorder_queue_structure_epoch',
            'content' => 'll_tools_wordset_recorder_queue_content_epoch',
            'recording_type' => 'll_tools_wordset_recorder_queue_recording_type_epoch',
        ];
        $option_backups = [];
        foreach ($option_names as $scope => $option_name) {
            $option_backups[$scope] = get_option($option_name, null);
            update_option($option_name, 'boundary-' . $scope, false);
        }

        try {
            update_option($buttons_epoch_option, 100, false);
            ll_tools_reset_wordset_buttons_shortcode_cache_purge_once_state();
            ll_tools_purge_wordset_buttons_shortcode_cache_once();
            $buttons_epoch_after_first_purge = ll_tools_wordset_buttons_shortcode_generation_epoch();
            $this->assertSame(0, ll_tools_purge_wordset_buttons_shortcode_cache_once());

            $expected_orphan_registry = [
                'audio' => [],
                'image_files' => [],
                'image_attachments' => [],
                'boundary_marker' => 'flushed',
            ];
            $GLOBALS['ll_tools_orphan_media_registry_cache'] = $expected_orphan_registry;
            $GLOBALS['ll_tools_orphan_media_registry_dirty'] = true;
            $GLOBALS['ll_tools_orphan_media_snapshot_stale'] = true;
            ll_tools_wordset_editor_mark_image_aggregate_dirty();
            ll_tools_wordset_page_mark_recorder_queue_structure_dirty();
            ll_tools_wordset_page_mark_recorder_queue_content_dirty();
            ll_tools_wordset_page_mark_recorder_queue_recording_types_dirty();

            $epochs_after_initial_bump = [];
            foreach ($option_names as $scope => $option_name) {
                $epochs_after_initial_bump[$scope] = (string) get_option($option_name, '');
            }

            $this->assertIsArray($GLOBALS['ll_tools_wordset_editor_image_aggregate_mutation_state'] ?? null);
            $this->assertCount(3, $GLOBALS['ll_tools_wordset_page_recorder_queue_epoch_states'] ?? []);

            $this->completeLlToolsSimulatedRequest();

            $this->assertSame($expected_orphan_registry, get_option($orphan_registry_option));
            ll_tools_purge_wordset_buttons_shortcode_cache_once();
            $this->assertGreaterThan(
                $buttons_epoch_after_first_purge,
                ll_tools_wordset_buttons_shortcode_generation_epoch(),
                'A later simulated request should be allowed to purge the wordset-button cache.'
            );
            foreach ($option_names as $scope => $option_name) {
                $this->assertNotSame(
                    $epochs_after_initial_bump[$scope],
                    (string) get_option($option_name, ''),
                    sprintf('%s epoch should receive its request-final bump.', $scope)
                );
            }
            $this->assertArrayNotHasKey('ll_tools_wordset_editor_image_aggregate_mutation_state', $GLOBALS);
            $this->assertArrayNotHasKey('ll_tools_wordset_page_recorder_queue_epoch_states', $GLOBALS);
            $this->assertArrayNotHasKey('ll_tools_orphan_media_registry_cache', $GLOBALS);
            $this->assertArrayNotHasKey('ll_tools_orphan_media_registry_dirty', $GLOBALS);
            $this->assertArrayNotHasKey('ll_tools_orphan_media_snapshot_stale', $GLOBALS);
            $this->assertFalse(has_action('shutdown', 'll_tools_wordset_editor_finalize_image_aggregate_epoch'));
            $this->assertFalse(has_action('shutdown', 'll_tools_wordset_page_finalize_recorder_queue_structure_epoch'));
            $this->assertFalse(has_action('shutdown', 'll_tools_wordset_page_finalize_recorder_queue_content_epoch'));
            $this->assertFalse(has_action('shutdown', 'll_tools_wordset_page_finalize_recorder_queue_recording_type_epoch'));
        } finally {
            if ($orphan_registry_backup === null) {
                delete_option($orphan_registry_option);
            } else {
                update_option($orphan_registry_option, $orphan_registry_backup, false);
            }
            if ($buttons_epoch_backup === null) {
                delete_option($buttons_epoch_option);
            } else {
                update_option($buttons_epoch_option, $buttons_epoch_backup, false);
            }
            foreach ($option_names as $scope => $option_name) {
                if ($option_backups[$scope] === null) {
                    delete_option($option_name);
                } else {
                    update_option($option_name, $option_backups[$scope], false);
                }
            }
        }
    }
}
