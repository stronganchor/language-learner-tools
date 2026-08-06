<?php
declare(strict_types=1);

abstract class LL_Tools_TestCase extends WP_UnitTestCase
{
    /** @var int */
    protected $original_user_id = 0;
    /** @var callable|null */
    protected $audio_requirement_bypass_filter = null;
    protected bool $enforce_audio_publish_requirement = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetLlToolsRuntimeState();
        $this->original_user_id = get_current_user_id();
        if (!$this->enforce_audio_publish_requirement) {
            $this->audio_requirement_bypass_filter = static function (): bool {
                return true;
            };
            add_filter('ll_tools_skip_audio_requirement', $this->audio_requirement_bypass_filter);
        }
        if (function_exists('ll_tools_teacher_class_reset_invite_request_context')) {
            ll_tools_teacher_class_reset_invite_request_context();
        }
        if (function_exists('ll_tools_flashcard_widget_reset_render_guard')) {
            ll_tools_flashcard_widget_reset_render_guard();
        }
        if (function_exists('ll_qpg_flashcard_shell_reset_render_guard')) {
            ll_qpg_flashcard_shell_reset_render_guard();
        }
    }

    protected function tearDown(): void
    {
        if ($this->audio_requirement_bypass_filter !== null) {
            remove_filter('ll_tools_skip_audio_requirement', $this->audio_requirement_bypass_filter);
            $this->audio_requirement_bypass_filter = null;
        }
        $this->completeLlToolsSimulatedRequest();
        if (function_exists('ll_tools_teacher_class_reset_invite_request_context')) {
            ll_tools_teacher_class_reset_invite_request_context();
        }
        if (function_exists('ll_tools_flashcard_widget_reset_render_guard')) {
            ll_tools_flashcard_widget_reset_render_guard();
        }
        if (function_exists('ll_qpg_flashcard_shell_reset_render_guard')) {
            ll_qpg_flashcard_shell_reset_render_guard();
        }
        wp_set_current_user($this->original_user_id);
        parent::tearDown();
    }

    /**
     * Execute pending coalesced cache/epoch finalizers before another simulated request.
     *
     * PHPUnit keeps one PHP process alive across tests, while production runs these
     * finalizers before request shutdown. Discarding their pending state can leave a
     * partially-built cache generation visible to a later test.
     */
    protected function completeLlToolsSimulatedRequest(): void
    {
        if (function_exists('ll_tools_orphan_media_flush_runtime_state')) {
            ll_tools_orphan_media_flush_runtime_state();
        }

        $image_aggregate_state = $GLOBALS['ll_tools_wordset_editor_image_aggregate_mutation_state'] ?? null;
        if (
            is_array($image_aggregate_state)
            && !empty($image_aggregate_state['dirty'])
            && function_exists('ll_tools_wordset_editor_finalize_image_aggregate_epoch')
        ) {
            ll_tools_wordset_editor_finalize_image_aggregate_epoch();
        }

        $recorder_queue_states = $GLOBALS['ll_tools_wordset_page_recorder_queue_epoch_states'] ?? null;
        $recorder_queue_finalizers = [
            'structure' => 'll_tools_wordset_page_finalize_recorder_queue_structure_epoch',
            'content' => 'll_tools_wordset_page_finalize_recorder_queue_content_epoch',
            'recording_type' => 'll_tools_wordset_page_finalize_recorder_queue_recording_type_epoch',
        ];
        foreach ($recorder_queue_finalizers as $scope => $finalizer) {
            if (
                is_array($recorder_queue_states)
                && !empty($recorder_queue_states[$scope]['dirty'])
                && function_exists($finalizer)
            ) {
                $finalizer();
            }
        }

        if (function_exists('ll_tools_finalize_wordset_page_lesson_cache_invalidation')) {
            ll_tools_finalize_wordset_page_lesson_cache_invalidation();
        }
        if (function_exists('ll_tools_finalize_quiz_content_cache_invalidation')) {
            ll_tools_finalize_quiz_content_cache_invalidation();
        }

        $this->resetLlToolsRuntimeState();
    }

    protected function resetLlToolsRuntimeState(): void
    {
        unset($GLOBALS['ll_tools_active_rest_request']);
        unset($GLOBALS['ll_tools_active_rest_request_depth']);
        unset($GLOBALS['ll_tools_generic_page_cache_bypass_reason']);
        unset($GLOBALS['ll_tools_wordset_isolation_added_tt_ids']);

        // PHPUnit reuses one WP_Scripts registry across simulated requests.
        // wp_localize_script() appends to a handle's existing data, so clear
        // the plugin-owned flashcard localization slots between tests while
        // leaving production request behavior untouched.
        $scripts = $GLOBALS['wp_scripts'] ?? null;
        if ($scripts instanceof WP_Scripts) {
            foreach (['ll-tools-flashcard-audio', 'll-flc-util', 'll-flc-main', 'll-flc-mode-config'] as $handle) {
                if (isset($scripts->registered[$handle]->extra['data'])) {
                    unset($scripts->registered[$handle]->extra['data']);
                }
            }
        }

        if (function_exists('ll_tools_reset_category_maintenance_runtime')) {
            ll_tools_reset_category_maintenance_runtime();
        }
        if (function_exists('ll_tools_epoch_request_cache_reset')) {
            ll_tools_epoch_request_cache_reset();
        }
        if (function_exists('ll_tools_public_static_cache_reset_purge_once_state')) {
            ll_tools_public_static_cache_reset_purge_once_state();
        }
        if (function_exists('ll_tools_cloudflare_static_cache_reset_purge_once_state')) {
            ll_tools_cloudflare_static_cache_reset_purge_once_state();
        }
        if (function_exists('ll_tools_reset_dictionary_static_cache_purge_once_state')) {
            ll_tools_reset_dictionary_static_cache_purge_once_state();
        }
        if (function_exists('ll_tools_reset_wordset_buttons_shortcode_cache_purge_once_state')) {
            ll_tools_reset_wordset_buttons_shortcode_cache_purge_once_state();
        }
        unset($GLOBALS['ll_tools_epoch_bump_failed']);
        unset($GLOBALS['ll_tools_specific_wrong_answer_owner_map_read_complete']);
        unset($GLOBALS['ll_tools_specific_wrong_answer_owner_map_rebuild_complete']);
        unset($GLOBALS['ll_tools_wordset_page_lesson_cache_invalidation_state']);
        remove_action('shutdown', 'll_tools_finalize_wordset_page_lesson_cache_invalidation', PHP_INT_MAX);
        unset($GLOBALS['ll_tools_quiz_content_final_invalidation_state']);
        remove_action('shutdown', 'll_tools_finalize_quiz_content_cache_invalidation', PHP_INT_MAX);
        unset($GLOBALS['ll_tools_orphan_media_registry_cache']);
        unset($GLOBALS['ll_tools_orphan_media_registry_dirty']);
        unset($GLOBALS['ll_tools_orphan_media_snapshot_stale']);
        unset($GLOBALS['ll_tools_wordset_editor_image_aggregate_mutation_state']);
        remove_action('shutdown', 'll_tools_wordset_editor_finalize_image_aggregate_epoch', PHP_INT_MAX);
        unset($GLOBALS['ll_tools_wordset_page_recorder_queue_epoch_states']);
        remove_action('shutdown', 'll_tools_wordset_page_finalize_recorder_queue_structure_epoch', PHP_INT_MAX);
        remove_action('shutdown', 'll_tools_wordset_page_finalize_recorder_queue_content_epoch', PHP_INT_MAX);
        remove_action('shutdown', 'll_tools_wordset_page_finalize_recorder_queue_recording_type_epoch', PHP_INT_MAX);
    }
}
