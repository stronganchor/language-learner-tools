<?php
if (!defined('WPINC')) { die; }

/**
 * Role & capability: Audio Recorder
 * Grants minimal caps: read + upload_files
 *
 * NOTE:
 * - We DO NOT rely on register_activation_hook here (it only works in the main plugin file).
 * - Instead, we "create or refresh" the role on every load (fast & idempotent).
 */

/**
 * Create the Audio Recorder role if missing, and ensure required caps.
 * Safe to run on every request.
 */
function ll_tools_register_or_refresh_audio_recorder_role() {
    $role = get_role('audio_recorder');

    // Create role if missing
    if (!$role) {
        add_role(
            'audio_recorder',
            __('Audio Recorder', 'll-tools'),
            array(
                'read'         => true,
                'upload_files' => true,
            )
        );
        $role = get_role('audio_recorder'); // re-fetch for safety
    }

    // Ensure caps exist (guard against other plugins removing them)
    if ($role) {
        if (!$role->has_cap('read')) {
            $role->add_cap('read');
        }
        if (!$role->has_cap('upload_files')) {
            $role->add_cap('upload_files');
        }
    }
}

/**
 * Run early so the role is always available (front & admin).
 * This replaces the need for an activation hook in the main file.
 */
add_action('plugins_loaded', 'll_tools_register_or_refresh_audio_recorder_role', 1);

/**
 * Optional: If you *also* want to re-ensure on init (e.g., after role manager plugins run),
 * keep this. It’s cheap and idempotent.
 */
add_action('init', 'll_tools_register_or_refresh_audio_recorder_role', 1);

/**
 * Helper: who can record?
 * You’re gating on `upload_files`, so centralize the check.
 */
function ll_tools_user_can_record() {
    return is_user_logged_in() && current_user_can('upload_files');
}
