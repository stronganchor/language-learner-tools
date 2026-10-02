<?php
/** Permanent learner accounts and explicitly scoped LTI activities. */
if (!defined('WPINC')) { die; }

const LL_TOOLS_LTI_RESOURCES_OPTION = 'll_tools_lti_resources';
const LL_TOOLS_LTI_ACCOUNT_COOKIE = 'll_tools_lti_account_binding';

/** Runtime proof, not a conversion: core enrollment/configuration tables must support rollback. */
function ll_tools_lti_account_storage_status(): array {
    global $wpdb;
    $tables = [$wpdb->users, $wpdb->usermeta, $wpdb->posts, $wpdb->postmeta, $wpdb->options];
    $rows = [];
    $ready = true;
    foreach ($tables as $table) {
        $wpdb->last_error = '';
        $status = $wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS LIKE %s', $wpdb->esc_like($table)), ARRAY_A);
        $engine = is_array($status) && $wpdb->last_error === '' ? (string) ($status['Engine'] ?? '') : '';
        $rows[$table] = $engine;
        $ready = $ready && strcasecmp($engine, 'InnoDB') === 0;
    }
    return ['ready' => $ready, 'tables' => $rows];
}

function ll_tools_lti_account_error(string $code = 'lti_account_unavailable'): WP_Error {
    $messages = [
        'lti_account_session_conflict' => __('You are signed in to a different learning account. Sign out, then open this activity from Moodle again.', 'll-tools-text-domain'),
        'lti_account_identity_conflict' => __('This Moodle identity is already connected to another account, or was disconnected. Ask the site administrator to review the link.', 'll-tools-text-domain'),
        'lti_account_enrollment_suspended' => __('Your access to this class has been removed. Your personal learning account remains available.', 'll-tools-text-domain'),
        'lti_account_learner_required' => __('Use a learner account for this activity. Staff accounts cannot be connected as Moodle students.', 'll-tools-text-domain'),
    ];
    return new WP_Error($code, $messages[$code] ?? __('This Moodle activity could not be opened. Return to Moodle and try again, or contact your teacher.', 'll-tools-text-domain'));
}

/** Only configured learners may receive a study session. LMS roles never become WP roles. */
function ll_tools_lti_account_is_learner(int $user_id): bool {
    $user = get_userdata($user_id);
    if (!($user instanceof WP_User) || array_values((array) $user->roles) !== ['ll_tools_learner']) {
        return false;
    }
    foreach (['manage_options', 'edit_users', 'promote_users', 'edit_posts', 'upload_files', 'manage_wordsets'] as $capability) {
        if (user_can($user, $capability)) { return false; }
    }
    return !function_exists('ll_tools_privacy_user_lms_deletion_is_pending')
        || !ll_tools_privacy_user_lms_deletion_is_pending($user_id);
}

function ll_tools_lti_launch_is_learner(array $context): bool {
    return in_array('http://purl.imsglobal.org/vocab/lis/v2/membership#Learner', (array) ($context['roles'] ?? []), true);
}

/** @return array<string,array<string,mixed>> */
function ll_tools_lti_get_resources(): array {
    $rows = get_option(LL_TOOLS_LTI_RESOURCES_OPTION, []);
    return is_array($rows) && count($rows) <= 100 ? $rows : [];
}

/** Compare-and-swap a bounded configuration record; do not overwrite another administrator's change. */
function ll_tools_lti_write_resources(array $before, array $after): bool {
    global $wpdb;
    if (count($after) > 100) { return false; }
    if ($before === [] && add_option(LL_TOOLS_LTI_RESOURCES_OPTION, $after, '', false)) { return true; }
    $updated = $wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
        maybe_serialize($after), LL_TOOLS_LTI_RESOURCES_OPTION, maybe_serialize($before)
    ));
    wp_cache_delete(LL_TOOLS_LTI_RESOURCES_OPTION, 'options');
    return $updated === 1 || ($updated === 0 && $before === $after);
}

/** Read-only authoring admission, performed before creating a new assignment. */
function ll_tools_lti_resource_preflight(array $input, int $actor_user_id) {
    if (empty(ll_tools_lti_account_storage_status()['ready'])) { return ll_tools_lti_account_error('lti_transactional_storage_required'); }
    $platform_id = is_string($input['platform_id'] ?? null) ? sanitize_key($input['platform_id']) : '';
    $platform = ll_tools_lti_get_platform($platform_id);
    $class_id = (int) ($input['class_id'] ?? 0);
    $category_id = (int) ($input['category_id'] ?? 0);
    $context = $input['context_id'] ?? '';
    $link = $input['resource_link_id'] ?? '';
    if (!is_array($platform) || empty($platform['enabled']) || !ll_tools_lti_opaque($context)
        || !is_string($link) || strlen($link) > 255 || ($link !== '' && !ll_tools_lti_opaque($link))
        || !in_array($input['kind'] ?? '', ['practice', 'assignment'], true)
        || !ll_tools_lms_assignment_user_can_manage_class($class_id, $actor_user_id)) { return ll_tools_lti_account_error('lti_resource_scope_invalid'); }
    $choices = ll_tools_lms_assignment_category_choices($class_id, $actor_user_id);
    if (is_wp_error($choices) || !in_array($category_id, array_map('intval', array_column($choices, 'id')), true)) { return ll_tools_lti_account_error('lti_resource_category_invalid'); }
    return count(ll_tools_lti_get_resources()) < 100 ? true : ll_tools_lti_account_error('lti_resource_limit');
}

/** @return array|WP_Error */
function ll_tools_lti_register_resource(array $input, int $actor_user_id = 0) {
    $actor_user_id = (int) ($actor_user_id ?: get_current_user_id());
    $preflight = ll_tools_lti_resource_preflight($input, $actor_user_id);
    if (is_wp_error($preflight)) { return $preflight; }
    $platform_id = is_string($input['platform_id'] ?? null) ? sanitize_key($input['platform_id']) : '';
    $platform = ll_tools_lti_get_platform($platform_id);
    $class_id = (int) ($input['class_id'] ?? 0);
    $wordset_id = function_exists('ll_tools_teacher_class_get_wordset_id') ? ll_tools_teacher_class_get_wordset_id($class_id) : 0;
    $context_id = is_string($input['context_id'] ?? null) ? trim($input['context_id']) : '';
    $resource_link_id = is_string($input['resource_link_id'] ?? null) ? trim($input['resource_link_id']) : '';
    $category_id = (int) ($input['category_id'] ?? 0);
    $kind = ($input['kind'] ?? '') === 'assignment' ? 'assignment' : 'practice';
    if (!is_array($platform) || empty($platform['enabled']) || $context_id === '' || strlen($context_id) > 255
        || strlen($resource_link_id) > 255 || $wordset_id <= 0
        || !ll_tools_lms_assignment_user_can_manage_class($class_id, $actor_user_id)) {
        return ll_tools_lti_account_error('lti_resource_scope_invalid');
    }
    $choices = ll_tools_lms_assignment_category_choices($class_id, $actor_user_id);
    if (is_wp_error($choices) || !in_array($category_id, array_map('intval', array_column($choices, 'id')), true)) {
        return ll_tools_lti_account_error('lti_resource_category_invalid');
    }
    $assignment = null;
    if ($kind === 'assignment') {
        $assignment = ll_tools_lms_assignment_get((string) ($input['assignment_uuid'] ?? ''));
        if (!is_array($assignment) || (int) $assignment['class_id'] !== $class_id
            || (int) $assignment['wordset_id'] !== $wordset_id || $assignment['status'] !== 'published') {
            return ll_tools_lti_account_error('lti_resource_assignment_invalid');
        }
    }
    $rows = ll_tools_lti_get_resources();
    if (count($rows) >= 100) { return ll_tools_lti_account_error('lti_resource_limit'); }
    $id = substr(hash('sha256', wp_generate_uuid4()), 0, 32);
    $row = [
        'id' => $id, 'platform_id' => $platform_id, 'deployment_id' => $platform['deployment_id'],
        'context_id' => $context_id, 'resource_link_id' => $resource_link_id, 'class_id' => $class_id,
        'wordset_id' => $wordset_id, 'category_id' => $category_id, 'kind' => $kind,
        'assignment_uuid' => is_array($assignment) ? $assignment['assignment_uuid'] : '',
        'name' => substr(sanitize_text_field((string) ($input['name'] ?? '')), 0, 200), 'enabled' => true,
        'created_by' => $actor_user_id,
    ];
    $after = $rows;
    $after[$id] = $row;
    return ll_tools_lti_write_resources($rows, $after) ? $row : ll_tools_lti_account_error('lti_resource_write_conflict');
}

function ll_tools_lti_disable_resource(string $resource_id, int $actor_user_id = 0) {
    $rows = ll_tools_lti_get_resources();
    $row = $rows[$resource_id] ?? null;
    if (!is_array($row) || !ll_tools_lms_assignment_user_can_manage_class((int) $row['class_id'], (int) ($actor_user_id ?: get_current_user_id()))) {
        return ll_tools_lti_account_error('lti_resource_forbidden');
    }
    $after = $rows;
    $after[$resource_id]['enabled'] = false;
    return ll_tools_lti_write_resources($rows, $after) ? true : ll_tools_lti_account_error('lti_resource_write_conflict');
}

/** Signed custom activity ID selects a locally configured course mapping. */
function ll_tools_lti_resolve_resource(array $context) {
    $id = $context['custom']['ll_activity'] ?? '';
    if (!is_string($id) || preg_match('/^[a-f0-9]{32}$/D', $id) !== 1) { return ll_tools_lti_account_error('lti_resource_missing'); }
    $row = ll_tools_lti_get_resources()[$id] ?? null;
    $platform = ll_tools_lti_get_platform((string) ($context['platform_id'] ?? ''));
    if (!is_array($row) || empty($row['enabled']) || !is_array($platform) || empty($platform['enabled'])
        || $row['platform_id'] !== ($context['platform_id'] ?? '') || $row['deployment_id'] !== ($context['deployment_id'] ?? '')
        || $row['context_id'] !== ($context['context_id'] ?? '') || ($context['resource_link_id'] ?? '') === ''
        || $platform['issuer'] !== ($context['issuer'] ?? '') || $platform['client_id'] !== ($context['client_id'] ?? '')
        || $platform['deployment_id'] !== ($context['deployment_id'] ?? '')
        || (int) $row['wordset_id'] !== ll_tools_teacher_class_get_wordset_id((int) $row['class_id'])
        || !ll_tools_lms_assignment_user_can_manage_class((int) $row['class_id'], (int) $row['created_by'])) {
        return ll_tools_lti_account_error('lti_resource_scope_mismatch');
    }
    if ($row['resource_link_id'] !== '' && $row['resource_link_id'] !== $context['resource_link_id']) {
        return ll_tools_lti_account_error('lti_resource_link_mismatch');
    }
    return $row;
}

/** Bind the first verified Moodle resource link once, without allowing later cross-activity reuse. */
function ll_tools_lti_bind_resource_link(array $resource, array $context) {
    $rows = ll_tools_lti_get_resources();
    $id = (string) $resource['id'];
    if (!isset($rows[$id])) { return ll_tools_lti_account_error('lti_resource_write_conflict'); }
    if ($rows[$id] !== $resource) {
        $original = $rows[$id];
        $original['resource_link_id'] = $resource['resource_link_id'];
        return $original === $resource && $rows[$id]['resource_link_id'] === $context['resource_link_id']
            ? true : ll_tools_lti_account_error('lti_resource_write_conflict');
    }
    if ($resource['resource_link_id'] !== '') { return true; }
    $after = $rows;
    $after[$id]['resource_link_id'] = $context['resource_link_id'];
    return ll_tools_lti_write_resources($rows, $after) ? true : ll_tools_lti_account_error('lti_resource_write_conflict');
}

/** @return array|WP_Error|null */
function ll_tools_lti_find_account_identity(array $context) {
    global $wpdb;
    if (empty(ll_tools_grade_delivery_runtime_schema_status()['ready'])) { return ll_tools_lti_account_error('lti_account_storage_unavailable'); }
    $table = ll_tools_grade_delivery_table_names()['identities'];
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table} WHERE adapter = %s AND connection_key_hash = %s AND subject_key_hash = %s LIMIT 1",
        'lti', ll_tools_lti_connection_hash($context), ll_tools_lti_subject_hash($context)
    ), ARRAY_A);
    return $wpdb->last_error === '' ? (is_array($row) ? $row : null) : ll_tools_lti_account_error('lti_account_storage_unavailable');
}

/** All raw identity data remains in the encrypted server continuation. */
function ll_tools_lti_prepare_account(array $context, string $browser_binding) {
    if (empty(ll_tools_lti_account_storage_status()['ready'])) { return ll_tools_lti_account_error('lti_transactional_storage_required'); }
    if (preg_match('/^[a-f0-9]{64}$/D', $browser_binding) !== 1 || !ll_tools_lti_launch_is_learner($context)) {
        return ll_tools_lti_account_error('lti_account_role_invalid');
    }
    $resource = ll_tools_lti_resolve_resource($context);
    if (is_wp_error($resource)) { return $resource; }
    $user_id = get_current_user_id();
    $context['_account_user_id'] = $user_id;
    unset($context['_account_guard']);
    if ($user_id > 0) {
        if (!ll_tools_lti_account_is_learner($user_id)) { return ll_tools_lti_account_error('lti_account_learner_required'); }
        $guard = ll_tools_lti_create_account_guard($user_id);
        if (is_wp_error($guard)) { return $guard; }
        $context['_account_guard'] = $guard;
    }
    $json = wp_json_encode($context);
    $sealed = is_string($json) ? ll_tools_lms_seal_secret($json, 'lti-account-continuation') : ll_tools_lti_account_error();
    if (is_wp_error($sealed)) { return $sealed; }
    return ll_tools_lti_ticket_put('account', ['sealed' => $sealed, 'binding' => hash('sha256', $browser_binding)], 900);
}

/** A user-scoped encrypted marker is removed by privacy erasure, invalidating old link confirmations. */
function ll_tools_lti_create_account_guard(int $user_id) {
    return ll_tools_lti_with_learner_lock($user_id, static function () use ($user_id) {
        $guard = bin2hex(random_bytes(32));
        $name = 'll_tools_lti_u_' . $user_id . '_a_' . hash('sha256', $guard);
        $stored = ll_tools_lti_record_put($name, ['learner_user_id' => $user_id, 'expires_at' => time() + 900]);
        return is_wp_error($stored) ? $stored : $guard;
    });
}

function ll_tools_lti_read_account_continuation(string $ticket, string $browser_binding, bool $consume = false) {
    $pending = ll_tools_lti_ticket_get('account', $ticket);
    if (!is_array($pending) || !is_string($pending['binding'] ?? null)
        || preg_match('/^[a-f0-9]{64}$/D', $browser_binding) !== 1
        || !hash_equals($pending['binding'], hash('sha256', $browser_binding))) {
        return ll_tools_lti_account_error('lti_account_continuation_invalid');
    }
    if ($consume) {
        $pending = ll_tools_lti_ticket_consume('account', $ticket);
        if (is_wp_error($pending)) { return $pending; }
    }
    $plain = ll_tools_lms_open_secret((string) ($pending['sealed'] ?? ''), 'lti-account-continuation');
    $context = is_string($plain) ? json_decode($plain, true) : null;
    if (!is_array($context) || !ll_tools_lti_launch_is_learner($context)) { return ll_tools_lti_account_error('lti_account_continuation_invalid'); }
    if (($context['_account_user_id'] ?? 0) > 0) {
        $guard = $context['_account_guard'] ?? '';
        $name = 'll_tools_lti_u_' . (int) $context['_account_user_id'] . '_a_' . hash('sha256', is_string($guard) ? $guard : '');
        $marker = ll_tools_lti_record_get($name);
        if (!is_array($marker) || (int) ($marker['expires_at'] ?? 0) < time()
            || (int) ($marker['learner_user_id'] ?? 0) !== (int) $context['_account_user_id']) { return ll_tools_lti_account_error('lti_account_continuation_invalid'); }
    }
    $resource = ll_tools_lti_resolve_resource($context);
    return is_wp_error($resource) ? $resource : $context;
}

/** Called again under the identity writer's shared learner lock, closing the erasure/read race. */
function ll_tools_lti_account_confirmation_is_valid(array $context, int $user_id): bool {
    global $wpdb;
    if ((int) ($context['_account_user_id'] ?? 0) !== $user_id || !is_string($context['_account_guard'] ?? null)
        || preg_match('/^[a-f0-9]{64}$/D', $context['_account_guard']) !== 1) { return false; }
    $name = 'll_tools_lti_u_' . $user_id . '_a_' . hash('sha256', $context['_account_guard']);
    $stored = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s FOR UPDATE", $name));
    if ($wpdb->last_error !== '' || !is_string($stored)) { return false; }
    $marker = ll_tools_lti_decrypt_record(maybe_unserialize($stored), $name);
    return is_array($marker) && (int) ($marker['learner_user_id'] ?? 0) === $user_id && (int) ($marker['expires_at'] ?? 0) >= time();
}

/** Returns a local destination only after exact scope, identity and membership checks. */
function ll_tools_lti_admit_account(array $context, int $learner_user_id, bool $link_confirmed = false) {
    if (empty(ll_tools_lti_account_storage_status()['ready'])) { return ll_tools_lti_account_error('lti_transactional_storage_required'); }
    $result = ll_tools_lti_with_learner_lock($learner_user_id, static function () use ($context, $learner_user_id, $link_confirmed) {
        return ll_tools_lti_admit_account_locked($context, $learner_user_id, $link_confirmed);
    });
    if (is_wp_error($result)) {
        wp_cache_delete(LL_TOOLS_LTI_RESOURCES_OPTION, 'options');
        wp_cache_delete($learner_user_id, 'user_meta');
        $id = $context['custom']['ll_activity'] ?? '';
        $resource = is_string($id) ? (ll_tools_lti_get_resources()[$id] ?? null) : null;
        if (is_array($resource)) { clean_post_cache((int) $resource['class_id']); }
    }
    return $result;
}

/** Caller owns the core learner advisory, row, options and privacy transaction. */
function ll_tools_lti_admit_account_locked(array $context, int $learner_user_id, bool $link_confirmed) {
    if (!ll_tools_lti_launch_is_learner($context) || !ll_tools_lti_account_is_learner($learner_user_id)) {
        return ll_tools_lti_account_error('lti_account_learner_required');
    }
    $current_user_id = get_current_user_id();
    if ($current_user_id > 0 && $current_user_id !== $learner_user_id) { return ll_tools_lti_account_error('lti_account_session_conflict'); }
    $resource = ll_tools_lti_resolve_resource($context);
    if (is_wp_error($resource)) { return $resource; }
    $identity = ll_tools_lti_find_account_identity($context);
    if (is_wp_error($identity)) { return $identity; }
    if (is_array($identity)) {
        if ((int) $identity['learner_user_id'] !== $learner_user_id || $identity['status'] !== 'active') {
            return ll_tools_lti_account_error('lti_account_identity_conflict');
        }
        $identity_id = (int) $identity['id'];
    } else {
        if (!$link_confirmed || $current_user_id !== $learner_user_id) { return ll_tools_lti_account_error('lti_account_confirmation_required'); }
        $identity_id = ll_tools_lti_create_identity($learner_user_id, $context);
        if (is_wp_error($identity_id)) { return $identity_id; }
    }
    $bound = ll_tools_lti_bind_resource_link($resource, $context);
    if (is_wp_error($bound)) { return $bound; }
    // Membership and its admission marker share the existing native privacy transaction.
    $membership = ll_tools_lti_enroll_account($context, $resource, $learner_user_id, $identity_id);
    if (is_wp_error($membership)) { return $membership; }
    if ($resource['kind'] === 'assignment') {
        $assignment = ll_tools_lms_assignment_get((string) $resource['assignment_uuid']);
        if (!is_array($assignment) || $assignment['status'] !== 'published'
            || (int) $assignment['class_id'] !== (int) $resource['class_id'] || (int) $assignment['wordset_id'] !== (int) $resource['wordset_id']) {
            return ll_tools_lti_account_error('lti_resource_assignment_invalid');
        }
        $connected = ll_tools_lti_connect_grade($context, (int) $assignment['id'], (int) $assignment['current_revision_id'], $identity_id, $learner_user_id);
        if (is_wp_error($connected)) { return $connected; }
        return ll_tools_lms_assignment_player_url((string) $assignment['assignment_uuid']);
    }
    $category = get_term((int) $resource['category_id'], 'word-category');
    $wordset = get_term((int) $resource['wordset_id'], 'wordset');
    if (!($category instanceof WP_Term) || !($wordset instanceof WP_Term)) { return ll_tools_lti_account_error('lti_resource_category_invalid'); }
    $embed_context = ll_tools_resolve_embed_quiz_context($category->slug, $wordset->slug);
    if (!isset($embed_context['term']) || !($embed_context['term'] instanceof WP_Term)
        || (int) $embed_context['term']->term_id !== (int) $category->term_id) { return ll_tools_lti_account_error('lti_resource_category_invalid'); }
    return (string) add_query_arg(['wordset' => $wordset->slug, 'mode' => 'practice'], home_url('/embed/' . $category->slug));
}

/** Caller owns the outer LTI learner transaction; class row is locked before roster reads. */
function ll_tools_lti_enroll_account(array $context, array $resource, int $user_id, int $identity_id) {
    global $wpdb;
    $class_id = (int) $resource['class_id'];
        $table = ll_tools_grade_delivery_table_names()['identities'];
        $identity = $wpdb->get_row($wpdb->prepare("SELECT learner_user_id, status, connection_key_hash, subject_key_hash FROM {$table} WHERE id = %d AND adapter = 'lti' FOR UPDATE", $identity_id), ARRAY_A);
        if (!is_array($identity) || $wpdb->last_error !== '' || (int) $identity['learner_user_id'] !== $user_id || $identity['status'] !== 'active'
            || !hash_equals($identity['connection_key_hash'], ll_tools_lti_connection_hash($context))
            || !hash_equals($identity['subject_key_hash'], ll_tools_lti_subject_hash($context))) { return ll_tools_lti_account_error('lti_account_identity_conflict'); }
        if ((int) ($context['_account_user_id'] ?? 0) > 0 && !ll_tools_lti_account_confirmation_is_valid($context, $user_id)) {
            return ll_tools_lti_account_error('lti_account_continuation_invalid');
        }
        if (!ll_tools_teacher_class_lock_for_membership_mutation($class_id)
            || !ll_tools_teacher_class_deletion_lease_allows_mutation($class_id, [])
            || !ll_tools_teacher_class_exists($class_id)
            || ll_tools_teacher_class_get_wordset_id($class_id) !== (int) $resource['wordset_id']) { return ll_tools_lti_account_error('lti_resource_scope_mismatch'); }
        wp_cache_delete($user_id, 'user_meta');
        $wpdb->last_error = '';
        $students = ll_tools_teacher_class_get_student_ids($class_id);
        $classes = ll_tools_teacher_class_get_ids_for_student($user_id);
        $admissions = get_user_meta($user_id, '_ll_tools_lti_class_admissions', true);
        if ($wpdb->last_error !== '') { return ll_tools_lti_account_error('lti_account_admission_read_failed'); }
        $admissions = is_array($admissions) ? array_values(array_unique(array_map('intval', $admissions))) : [];
        if (in_array($class_id, $admissions, true) && !in_array($user_id, $students, true)) { return ll_tools_lti_account_error('lti_account_enrollment_suspended'); }
        $next_students = ll_tools_teacher_class_normalize_ids(array_merge($students, [$user_id]));
        $next_classes = ll_tools_teacher_class_normalize_ids(array_merge($classes, [$class_id]));
        if ($next_students !== $students && !update_post_meta($class_id, LL_TOOLS_TEACHER_CLASS_STUDENT_IDS_META, $next_students)) { return ll_tools_lti_account_error('lti_account_admission_write_failed'); }
        if ($next_classes !== $classes && !update_user_meta($user_id, LL_TOOLS_STUDENT_CLASS_IDS_META, $next_classes)) { return ll_tools_lti_account_error('lti_account_admission_write_failed'); }
        if (!in_array($class_id, $admissions, true)) {
            if (count($admissions) >= 100) { return ll_tools_lti_account_error('lti_account_admission_limit'); }
            $admissions[] = $class_id;
            if (!update_user_meta($user_id, '_ll_tools_lti_class_admissions', $admissions)) { return ll_tools_lti_account_error('lti_account_admission_write_failed'); }
        }
        clean_post_cache($class_id);
        wp_cache_delete($user_id, 'user_meta');
        if ($wpdb->last_error !== '' || ll_tools_teacher_class_get_student_ids($class_id) !== $next_students
            || ll_tools_teacher_class_get_ids_for_student($user_id) !== $next_classes
            || get_user_meta($user_id, '_ll_tools_lti_class_admissions', true) !== $admissions) { return ll_tools_lti_account_error('lti_account_admission_write_failed'); }
        return true;
}

function ll_tools_lti_confirm_account(string $ticket, string $browser_binding, int $learner_user_id) {
    if (get_current_user_id() !== $learner_user_id || !ll_tools_lti_account_is_learner($learner_user_id)) {
        return ll_tools_lti_account_error('lti_account_learner_required');
    }
    $context = ll_tools_lti_read_account_continuation($ticket, $browser_binding, true);
    if (is_wp_error($context)) { return $context; }
    if ((int) ($context['_account_user_id'] ?? 0) !== $learner_user_id) { return ll_tools_lti_account_error('lti_account_continuation_invalid'); }
    $result = ll_tools_lti_admit_account($context, $learner_user_id, true);
    delete_option('ll_tools_lti_u_' . $learner_user_id . '_a_' . hash('sha256', $context['_account_guard']));
    return $result;
}

/** Revoke one Moodle identity; preserve personal progress and selected local grades. */
function ll_tools_lti_unlink_account(int $identity_id, int $learner_user_id) {
    global $wpdb;
    if (get_current_user_id() !== $learner_user_id && !current_user_can('manage_options')) { return ll_tools_lti_account_error('lti_account_forbidden'); }
    if (empty(ll_tools_grade_delivery_runtime_schema_status()['ready'])) { return ll_tools_lti_account_error(); }
    $lock = ll_tools_offline_app_acquire_user_session_lock($learner_user_id);
    if ($lock === '') { return ll_tools_lti_account_error('lti_account_write_busy'); }
    $transaction = ll_tools_lms_assignment_begin_transaction();
    try {
        if (!is_array($transaction) || !ll_tools_lms_assignment_lock_user($learner_user_id)) { throw new RuntimeException('lti_account_write_busy'); }
        $tables = ll_tools_grade_delivery_table_names();
        $identity = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$tables['identities']} WHERE id = %d AND learner_user_id = %d AND adapter = 'lti' FOR UPDATE", $identity_id, $learner_user_id), ARRAY_A);
        if (!is_array($identity) || $wpdb->last_error !== '') { throw new RuntimeException('lti_account_identity_missing'); }
        // A delivery already in progress must finish before revocation is declared complete.
        $processing = $wpdb->get_var($wpdb->prepare("SELECT d.id FROM {$tables['deliveries']} d JOIN {$tables['recipients']} r ON r.id = d.recipient_id WHERE r.external_identity_id = %d AND d.status = 'processing' LIMIT 1", $identity_id));
        if ($wpdb->last_error !== '' || $processing !== null) { throw new RuntimeException('lti_account_write_busy'); }
        if ($wpdb->update($tables['identities'], ['status' => 'revoked', 'updated_at' => current_time('mysql', true)], ['id' => $identity_id]) === false
            || $wpdb->update($tables['recipients'], ['status' => 'revoked', 'updated_at' => current_time('mysql', true)], ['external_identity_id' => $identity_id]) === false
            || !ll_tools_lms_assignment_commit_transaction($transaction)) { throw new RuntimeException('lti_account_unlink_failed'); }
        return true;
    } catch (Throwable $error) {
        if (is_array($transaction)) { ll_tools_lms_assignment_rollback_transaction($transaction); }
        return ll_tools_lti_account_error($error->getMessage());
    } finally {
        ll_tools_offline_app_release_user_session_lock($lock);
    }
}

function ll_tools_lti_account_request_token(string $key): string {
    $value = $_REQUEST[$key] ?? '';
    return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1 ? $value : '';
}

function ll_tools_lti_account_browser_binding(bool $create = false): string {
    $binding = $_COOKIE[LL_TOOLS_LTI_ACCOUNT_COOKIE] ?? '';
    if (is_string($binding) && preg_match('/^[a-f0-9]{64}$/D', $binding) === 1) { return $binding; }
    if (!$create || headers_sent()) { return ''; }
    $binding = bin2hex(random_bytes(32));
    setcookie(LL_TOOLS_LTI_ACCOUNT_COOKIE, $binding, ['expires' => time() + 900, 'path' => '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax']);
    $_COOKIE[LL_TOOLS_LTI_ACCOUNT_COOKIE] = $binding;
    return $binding;
}

/** Own a minimal top-level page; login/registration reuse the normal permanent-account flow. */
function ll_tools_lti_render_account_page(string $ticket, $error = null): void {
    if (!defined('DONOTCACHEPAGE')) { define('DONOTCACHEPAGE', true); }
    nocache_headers();
    header('Referrer-Policy: no-referrer');
    header('X-Robots-Tag: noindex, nofollow');
    $resume_url = (string) add_query_arg('ll_lti_continue', $ticket, home_url('/'));
    $context = $ticket !== '' ? ll_tools_lti_read_account_continuation($ticket, ll_tools_lti_account_browser_binding()) : null;
    $user_id = get_current_user_id();
    $identity = is_array($context) ? ll_tools_lti_find_account_identity($context) : null;
    if (!is_wp_error($error) && is_array($context) && $user_id > 0 && (int) ($context['_account_user_id'] ?? 0) === 0
        && ll_tools_lti_account_is_learner($user_id)) {
        $replacement = ll_tools_lti_prepare_account($context, ll_tools_lti_account_browser_binding());
        if (!is_wp_error($replacement)) {
            ll_tools_lti_ticket_consume('account', $ticket);
            wp_safe_redirect(add_query_arg('ll_lti_continue', $replacement, home_url('/'))); exit;
        }
        $error = $replacement;
    }
    $conflict = is_array($identity) && ($identity['status'] !== 'active' || ($user_id > 0 && (int) $identity['learner_user_id'] !== $user_id));
    if (is_wp_error($context) && $error === null) { $error = $context; }
    status_header(is_wp_error($error) ? 400 : 200);
    ?><!doctype html><html <?php language_attributes(); ?>><head><meta charset="<?php bloginfo('charset'); ?>"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?php esc_html_e('Connect your learning account', 'll-tools-text-domain'); ?></title><?php wp_head(); ?></head><body class="ll-lti-account-page"><main class="ll-lti-account-panel">
    <h1><?php esc_html_e('Connect your learning account', 'll-tools-text-domain'); ?></h1>
    <?php if (is_wp_error($error)): ?><p role="alert"><?php echo esc_html($error->get_error_message()); ?></p>
    <?php elseif ($conflict): ?><p><?php esc_html_e('This Moodle identity already belongs to a different account, or has been disconnected. Sign in to the original account; ask the site administrator to review a disconnected link.', 'll-tools-text-domain'); ?></p>
    <?php elseif ($user_id === 0): ?>
        <p><?php esc_html_e('Sign in or create a permanent learner account. Your class activities and personal practice can save progress in this account.', 'll-tools-text-domain'); ?></p>
        <?php echo ll_tools_render_login_window(['redirect_to' => $resume_url, 'show_registration' => true, 'screen_mode' => 'combined']); // Existing renderer escapes its output. ?>
    <?php elseif (!ll_tools_lti_account_is_learner($user_id)): ?><p><?php esc_html_e('Use a learner account for this activity. Staff accounts cannot be connected as Moodle students.', 'll-tools-text-domain'); ?></p>
    <?php else: ?>
        <p><?php echo esc_html(sprintf(__('Connect Moodle to %s? Assigned results may be returned to your course. Personal practice stays in this account; your class teacher can view progress for the class wordset.', 'll-tools-text-domain'), wp_get_current_user()->display_name)); ?></p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="ll_tools_lti_confirm_account"><input type="hidden" name="ll_lti_continue" value="<?php echo esc_attr($ticket); ?>"><?php wp_nonce_field('ll_tools_lti_confirm_account_' . $ticket); ?><button type="submit" class="ll-lti-button"><?php esc_html_e('Connect account and open activity', 'll-tools-text-domain'); ?></button></form>
    <?php endif; ?>
    <?php if ($user_id > 0): ?><p><a href="<?php echo esc_url(wp_logout_url($resume_url)); ?>"><?php esc_html_e('Use another account', 'll-tools-text-domain'); ?></a></p><?php endif; ?>
    <p><a href="<?php echo esc_url(home_url('/?ll_lti_connections=1')); ?>"><?php esc_html_e('Manage Moodle connections', 'll-tools-text-domain'); ?></a></p>
    </main><?php wp_footer(); ?></body></html><?php
}

function ll_tools_lti_account_redirect(string $url, int $user_id): void {
    // No role mutation, email match, or silent account switching.
    if (get_current_user_id() === 0) {
        wp_set_current_user($user_id);
        wp_set_auth_cookie($user_id, false, is_ssl());
        $user = get_userdata($user_id);
        if ($user instanceof WP_User) { do_action('wp_login', $user->user_login, $user); }
    }
    wp_safe_redirect($url);
    exit;
}

function ll_tools_lti_account_launch_controller(): void {
    if (!isset($_GET['ll_lti_launch']) && !isset($_GET['ll_lti_continue']) && !isset($_GET['ll_lti_connections'])) { return; }
    nocache_headers();
    if (isset($_GET['ll_lti_connections'])) { ll_tools_lti_render_connections_page(); exit; }
    $launch_ticket = ll_tools_lti_account_request_token('ll_lti_launch');
    if ($launch_ticket !== '') {
        $context = ll_tools_lti_consume_launch($launch_ticket);
        if (is_wp_error($context)) { ll_tools_lti_render_account_page('', ll_tools_lti_account_error()); exit; }
        if (!ll_tools_lti_launch_is_learner($context)) {
            if (is_user_logged_in() && current_user_can('view_ll_tools') && ll_tools_user_can_manage_classes(get_current_user_id())) {
                ll_tools_lti_render_scope_diagnostic($context); exit;
            }
            ll_tools_lti_render_account_page('', new WP_Error('lti_instructor_setup', __('To configure this activity, sign in to a local teacher account and open it from Moodle again.', 'll-tools-text-domain'))); exit;
        }
        $identity = ll_tools_lti_find_account_identity($context);
        if (is_array($identity) && $identity['status'] === 'active') {
            $url = ll_tools_lti_admit_account($context, (int) $identity['learner_user_id']);
            if (!is_wp_error($url)) { ll_tools_lti_account_redirect($url, (int) $identity['learner_user_id']); }
            ll_tools_lti_render_account_page('', $url); exit;
        }
        $ticket = ll_tools_lti_prepare_account($context, ll_tools_lti_account_browser_binding(true));
        if (is_wp_error($ticket)) { ll_tools_lti_render_account_page('', $ticket); exit; }
        wp_safe_redirect(add_query_arg('ll_lti_continue', $ticket, home_url('/'))); exit;
    }
    $ticket = ll_tools_lti_account_request_token('ll_lti_continue');
    ll_tools_lti_render_account_page($ticket, $ticket === '' ? ll_tools_lti_account_error() : null);
    exit;
}
add_action('template_redirect', 'll_tools_lti_account_launch_controller', 1);

/** Staff must already authenticate locally. Show only public registration/course identifiers. */
function ll_tools_lti_render_scope_diagnostic(array $context): void {
    if (!defined('DONOTCACHEPAGE')) { define('DONOTCACHEPAGE', true); }
    nocache_headers();
    header('Referrer-Policy: no-referrer');
    header('X-Robots-Tag: noindex, nofollow');
    ?><!doctype html><html <?php language_attributes(); ?>><head><meta charset="<?php bloginfo('charset'); ?>"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?php esc_html_e('Moodle activity setup', 'll-tools-text-domain'); ?></title><?php wp_head(); ?></head><body class="ll-lti-account-page"><main class="ll-lti-account-panel"><h1><?php esc_html_e('Moodle activity setup', 'll-tools-text-domain'); ?></h1><p><?php esc_html_e('This instructor launch was verified. Copy its course and resource identifiers into your Moodle / LTI activity configuration.', 'll-tools-text-domain'); ?></p><dl>
    <?php foreach (['issuer' => __('Platform issuer', 'll-tools-text-domain'), 'client_id' => __('Client ID', 'll-tools-text-domain'), 'deployment_id' => __('Deployment ID', 'll-tools-text-domain'), 'context_id' => __('Moodle course context ID', 'll-tools-text-domain'), 'resource_link_id' => __('Moodle resource link ID', 'll-tools-text-domain')] as $key => $label): ?><dt><?php echo esc_html($label); ?></dt><dd><code><?php echo esc_html((string) ($context[$key] ?? '')); ?></code></dd><?php endforeach; ?>
    </dl><p><a href="<?php echo esc_url(admin_url('admin.php?page=ll-tools-lti')); ?>"><?php esc_html_e('Open Moodle / LTI settings', 'll-tools-text-domain'); ?></a></p></main><?php wp_footer(); ?></body></html><?php
}

function ll_tools_lti_confirm_account_action(): void {
    $ticket = ll_tools_lti_account_request_token('ll_lti_continue');
    check_admin_referer('ll_tools_lti_confirm_account_' . $ticket);
    $url = ll_tools_lti_confirm_account($ticket, ll_tools_lti_account_browser_binding(), get_current_user_id());
    if (is_wp_error($url)) { ll_tools_lti_render_account_page('', $url); exit; }
    ll_tools_lti_account_redirect($url, get_current_user_id());
}
add_action('admin_post_ll_tools_lti_confirm_account', 'll_tools_lti_confirm_account_action');

function ll_tools_lti_account_enqueue_assets(): void {
    if (isset($_GET['ll_lti_launch']) || isset($_GET['ll_lti_continue']) || isset($_GET['ll_lti_connections']) || (($_POST['action'] ?? '') === 'll_tools_lti_confirm_account')) {
        ll_enqueue_asset_by_timestamp('/css/lti-accounts.css', 'll-tools-lti-accounts');
    }
}
add_action('wp_enqueue_scripts', 'll_tools_lti_account_enqueue_assets');

/** Bounded learner-owned connections, without exposing Moodle subject identifiers. */
function ll_tools_lti_account_connections(int $user_id): array {
    global $wpdb;
    if ($user_id <= 0 || empty(ll_tools_grade_delivery_runtime_schema_status()['ready'])) { return []; }
    $table = ll_tools_grade_delivery_table_names()['identities'];
    $rows = $wpdb->get_results($wpdb->prepare("SELECT id, connection_key_hash, status FROM {$table} WHERE learner_user_id = %d AND adapter = 'lti' ORDER BY id ASC LIMIT 50", $user_id), ARRAY_A);
    return is_array($rows) && $wpdb->last_error === '' ? $rows : [];
}

function ll_tools_lti_render_connections_page(): void {
    if (!is_user_logged_in()) { auth_redirect(); }
    if (!defined('DONOTCACHEPAGE')) { define('DONOTCACHEPAGE', true); }
    nocache_headers();
    ?><!doctype html><html <?php language_attributes(); ?>><head><meta charset="<?php bloginfo('charset'); ?>"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?php esc_html_e('Moodle connections', 'll-tools-text-domain'); ?></title><?php wp_head(); ?></head><body class="ll-lti-account-page"><main class="ll-lti-account-panel"><h1><?php esc_html_e('Moodle connections', 'll-tools-text-domain'); ?></h1><p><?php esc_html_e('Disconnecting stops future Moodle launches and grade delivery through this identity. Your account and personal progress remain. An administrator must review any later reconnection.', 'll-tools-text-domain'); ?></p>
    <?php foreach (ll_tools_lti_account_connections(get_current_user_id()) as $row):
        $name = __('Moodle', 'll-tools-text-domain');
        foreach (ll_tools_lti_get_platforms() as $platform) {
            if (hash_equals($row['connection_key_hash'], ll_tools_lti_connection_hash($platform))) { $name = $platform['name']; break; }
        }
        ?><section><h2><?php echo esc_html($name); ?></h2><?php if ($row['status'] === 'active'): ?><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="ll_tools_lti_unlink_account"><input type="hidden" name="identity_id" value="<?php echo (int) $row['id']; ?>"><?php wp_nonce_field('ll_tools_lti_unlink_account_' . $row['id']); ?><button type="submit" class="ll-lti-button"><?php esc_html_e('Disconnect Moodle', 'll-tools-text-domain'); ?></button></form><?php else: ?><p><?php esc_html_e('Disconnected', 'll-tools-text-domain'); ?></p><?php endif; ?></section>
    <?php endforeach; ?><p><a href="<?php echo esc_url(home_url('/')); ?>"><?php esc_html_e('Continue learning', 'll-tools-text-domain'); ?></a></p></main><?php wp_footer(); ?></body></html><?php
}

function ll_tools_lti_unlink_account_action(): void {
    $id = (int) ($_POST['identity_id'] ?? 0);
    check_admin_referer('ll_tools_lti_unlink_account_' . $id);
    $result = ll_tools_lti_unlink_account($id, get_current_user_id());
    if (is_wp_error($result)) { ll_tools_lti_render_account_page('', $result); exit; }
    wp_safe_redirect(home_url('/?ll_lti_connections=1')); exit;
}
add_action('admin_post_ll_tools_lti_unlink_account', 'll_tools_lti_unlink_account_action');

/** Permit only these nonce-checked learner admin-post handlers through the existing limited-role gate. */
function ll_tools_lti_learner_admin_post_actions(array $actions): array {
    return array_values(array_unique(array_merge($actions, ['ll_tools_lti_confirm_account', 'll_tools_lti_unlink_account'])));
}
add_filter('ll_tools_limited_role_allowed_admin_post_actions', 'll_tools_lti_learner_admin_post_actions');
