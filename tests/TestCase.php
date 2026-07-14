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
        $this->resetLlToolsRuntimeState();
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
    }
}
