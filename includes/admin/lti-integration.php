<?php
/** Manual Moodle LTI registration and bounded class activity setup. */
if (!defined('WPINC')) { die; }

function ll_tools_lti_admin_url(array $args = []): string {
    return (string) add_query_arg(array_merge(['page' => 'll-tools-lti'], $args), admin_url('admin.php'));
}

function ll_tools_lti_register_admin_page(): void {
    add_submenu_page(
        function_exists('ll_tools_get_admin_menu_slug') ? ll_tools_get_admin_menu_slug() : 'll-tools-dashboard-home',
        __('Moodle / LTI', 'll-tools-text-domain'), __('Moodle / LTI', 'll-tools-text-domain'),
        ll_tools_google_classroom_integration_capability(), 'll-tools-lti', 'll_tools_lti_render_admin_page'
    );
}
add_action('admin_menu', 'll_tools_lti_register_admin_page', 18);

function ll_tools_lti_teacher_admin_pages(array $pages): array {
    return array_values(array_unique(array_merge($pages, ['ll-tools-lti'])));
}
function ll_tools_lti_teacher_admin_actions(array $actions): array {
    return array_values(array_unique(array_merge($actions, ['ll_tools_lti_register_resource', 'll_tools_lti_disable_resource'])));
}
add_filter('ll_tools_limited_role_allowed_admin_page_slugs', 'll_tools_lti_teacher_admin_pages');
add_filter('ll_tools_limited_role_allowed_admin_post_actions', 'll_tools_lti_teacher_admin_actions');

function ll_tools_lti_admin_require_access(): int {
    if (!is_user_logged_in() || !current_user_can('view_ll_tools') || !ll_tools_user_can_manage_classes(get_current_user_id())) {
        wp_die(esc_html__('You cannot manage Moodle activities.', 'll-tools-text-domain'), '', ['response' => 403]);
    }
    return get_current_user_id();
}

function ll_tools_lti_admin_post_text(string $key): string {
    $value = $_POST[$key] ?? '';
    return is_string($value) ? trim(wp_unslash($value)) : '';
}

function ll_tools_lti_admin_redirect($result, int $class_id = 0, string $retained_uuid = ''): void {
    wp_safe_redirect(ll_tools_lti_admin_url(['class_id' => $class_id, 'lti_notice' => is_wp_error($result) ? 'failed' : 'saved', 'lti_retained_assignment' => $retained_uuid]));
    exit;
}

function ll_tools_lti_admin_platform_action(): void {
    ll_tools_lti_admin_require_access();
    if (!current_user_can('manage_options')) { wp_die(esc_html__('Only a site administrator can register Moodle.', 'll-tools-text-domain'), '', ['response' => 403]); }
    check_admin_referer('ll_tools_lti_register_platform');
    $input = ['enabled' => !empty($_POST['enabled'])];
    foreach (['id', 'name', 'issuer', 'client_id', 'deployment_id', 'authorization_url', 'jwks_url', 'token_url'] as $key) {
        $input[$key] = ll_tools_lti_admin_post_text($key);
    }
    ll_tools_lti_admin_redirect(ll_tools_lti_register_platform($input));
}
add_action('admin_post_ll_tools_lti_register_platform', 'll_tools_lti_admin_platform_action');

/** Create one permanent fixed assignment, then configure its Moodle course scope. */
function ll_tools_lti_admin_resource_action(): void {
    $actor = ll_tools_lti_admin_require_access();
    check_admin_referer('ll_tools_lti_register_resource');
    $class_id = (int) ($_POST['class_id'] ?? 0);
    $category_id = (int) ($_POST['category_id'] ?? 0);
    $kind = ll_tools_lti_admin_post_text('kind');
    $uuid = ll_tools_lti_admin_post_text('assignment_uuid');
    $input = [
        'platform_id' => ll_tools_lti_admin_post_text('platform_id'), 'context_id' => ll_tools_lti_admin_post_text('context_id'),
        'resource_link_id' => ll_tools_lti_admin_post_text('resource_link_id'), 'class_id' => $class_id,
        'category_id' => $category_id, 'kind' => $kind, 'assignment_uuid' => $uuid, 'name' => ll_tools_lti_admin_post_text('name'),
    ];
    $preflight = ll_tools_lti_resource_preflight($input, $actor);
    if (is_wp_error($preflight)) { ll_tools_lti_admin_redirect($preflight, $class_id); }
    $created_uuid = '';
    if ($kind === 'assignment' && $uuid === '') {
        $created = ll_tools_lms_assignment_create_from_category($class_id, $category_id, [
            'title' => sanitize_text_field(ll_tools_lti_admin_post_text('name')),
            'points_maximum' => ll_tools_lti_admin_post_text('points_maximum'),
            'attempt_limit' => (int) ($_POST['attempt_limit'] ?? 1),
            'grade_policy' => ll_tools_lti_admin_post_text('grade_policy'),
        ], $actor);
        if (is_wp_error($created)) { ll_tools_lti_admin_redirect($created, $class_id); }
        $published = ll_tools_lms_assignment_publish((int) $created['id'], $actor);
        if (is_wp_error($published)) { ll_tools_lti_admin_redirect($published, $class_id); }
        $uuid = (string) $created['assignment_uuid'];
        $created_uuid = $uuid;
    }
    $input['assignment_uuid'] = $uuid;
    $result = ll_tools_lti_register_resource($input, $actor);
    ll_tools_lti_admin_redirect($result, $class_id, is_wp_error($result) ? $created_uuid : '');
}
add_action('admin_post_ll_tools_lti_register_resource', 'll_tools_lti_admin_resource_action');

function ll_tools_lti_admin_disable_resource_action(): void {
    $actor = ll_tools_lti_admin_require_access();
    $id = ll_tools_lti_admin_post_text('resource_id');
    check_admin_referer('ll_tools_lti_disable_resource_' . $id);
    ll_tools_lti_admin_redirect(ll_tools_lti_disable_resource($id, $actor));
}
add_action('admin_post_ll_tools_lti_disable_resource', 'll_tools_lti_admin_disable_resource_action');

function ll_tools_lti_render_admin_page(): void {
    $actor = ll_tools_lti_admin_require_access();
    $urls = ll_tools_lti_tool_urls();
    $platforms = ll_tools_lti_get_platforms();
    $classes = ll_tools_teacher_classes_for_user($actor, 0, ['posts_per_page' => 100]);
    $class_id = (int) ($_GET['class_id'] ?? 0);
    if ($class_id > 0 && !ll_tools_lms_assignment_user_can_manage_class($class_id, $actor)) { $class_id = 0; }
    $choices = $class_id > 0 ? ll_tools_lms_assignment_category_choices($class_id, $actor) : [];
    $storage = ll_tools_lti_account_storage_status();
    $retained_uuid = is_string($_GET['lti_retained_assignment'] ?? null) ? ll_tools_lms_assignment_normalize_uuid($_GET['lti_retained_assignment']) : '';
    ?><div class="wrap ll-tools-lti-admin"><h1><?php esc_html_e('Moodle / LTI', 'll-tools-text-domain'); ?></h1>
    <?php if (isset($_GET['lti_notice'])): ?><div class="notice <?php echo ($_GET['lti_notice'] === 'saved') ? 'notice-success' : 'notice-error'; ?>"><p><?php echo ($_GET['lti_notice'] === 'saved') ? esc_html__('Saved.', 'll-tools-text-domain') : esc_html__('The change could not be saved. Check the registered URLs, class ownership, and selected activity, then retry.', 'll-tools-text-domain'); ?></p></div><?php endif; ?>
    <?php if ($retained_uuid !== ''): ?><div class="notice notice-warning"><p><?php esc_html_e('The assignment was created. Reuse this UUID when retrying the activity setup:', 'll-tools-text-domain'); ?> <code><?php echo esc_html($retained_uuid); ?></code></p></div><?php endif; ?>
    <p><?php esc_html_e('Use Moodle’s External tool activity with LTI 1.3. Choose a new-window launch so students can connect their permanent learner accounts. Practice saves personal progress; graded assignments can return the selected result to Moodle.', 'll-tools-text-domain'); ?></p>
    <?php if (!ll_tools_lms_credential_store_is_available()): ?><div class="notice notice-error"><p><?php esc_html_e('A configuration-only LMS credential encryption key is required before Moodle launches or account linking are available.', 'll-tools-text-domain'); ?></p></div><?php endif; ?>
    <?php if (empty($storage['ready'])): ?><div class="notice notice-error"><p><?php esc_html_e('Moodle account linking and enrollment are paused. The core users, usermeta, posts, postmeta and options tables must all be InnoDB so account, class and grade changes can roll back together. Ask the database administrator to prepare a backed-up migration; this plugin will not convert core tables.', 'll-tools-text-domain'); ?></p><ul><?php foreach ($storage['tables'] as $table => $engine): if (strcasecmp($engine, 'InnoDB') === 0) { continue; } ?><li><code><?php echo esc_html($table); ?></code>: <?php echo $engine !== '' ? esc_html($engine) : esc_html__('Unavailable', 'll-tools-text-domain'); ?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <h2><?php esc_html_e('Tool details for the Moodle administrator', 'll-tools-text-domain'); ?></h2><table class="widefat striped"><tbody>
    <?php foreach (['resource' => __('Tool URL', 'll-tools-text-domain'), 'login' => __('Initiate login URL', 'll-tools-text-domain'), 'launch' => __('Redirection URI', 'll-tools-text-domain'), 'jwks' => __('Public keyset URL', 'll-tools-text-domain')] as $key => $label): ?><tr><th scope="row"><?php echo esc_html($label); ?></th><td><code><?php echo esc_html((string) ($urls[$key] ?? '')); ?></code></td></tr><?php endforeach; ?>
    </tbody></table>
    <h2><?php esc_html_e('Registered Moodle platforms', 'll-tools-text-domain'); ?></h2><table class="widefat striped"><thead><tr><th><?php esc_html_e('Name', 'll-tools-text-domain'); ?></th><th><?php esc_html_e('Platform issuer', 'll-tools-text-domain'); ?></th><th><?php esc_html_e('Client ID', 'll-tools-text-domain'); ?></th><th><?php esc_html_e('Deployment ID', 'll-tools-text-domain'); ?></th><th><?php esc_html_e('Status', 'll-tools-text-domain'); ?></th></tr></thead><tbody>
    <?php foreach ($platforms as $platform): ?><tr><td><?php echo esc_html($platform['name']); ?></td><td><code><?php echo esc_html($platform['issuer']); ?></code></td><td><code><?php echo esc_html($platform['client_id']); ?></code></td><td><code><?php echo esc_html($platform['deployment_id']); ?></code></td><td><?php echo !empty($platform['enabled']) ? esc_html__('Enabled', 'll-tools-text-domain') : esc_html__('Disabled', 'll-tools-text-domain'); ?></td></tr><?php endforeach; ?>
    </tbody></table>
    <?php if (current_user_can('manage_options')): ?>
    <h2><?php esc_html_e('Register or update a platform', 'll-tools-text-domain'); ?></h2><p><?php esc_html_e('Copy these values from Moodle’s tool configuration. Registration is limited to the Moodle issuer’s HTTPS origin. Reusing an ID updates that registration; uncheck Enabled to stop its launches.', 'll-tools-text-domain'); ?></p>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="ll_tools_lti_register_platform"><?php wp_nonce_field('ll_tools_lti_register_platform'); ?><table class="form-table"><tbody>
    <?php foreach (['id' => __('Local registration ID', 'll-tools-text-domain'), 'name' => __('Display name', 'll-tools-text-domain'), 'issuer' => __('Platform issuer', 'll-tools-text-domain'), 'client_id' => __('Client ID', 'll-tools-text-domain'), 'deployment_id' => __('Deployment ID', 'll-tools-text-domain'), 'authorization_url' => __('Authentication request URL', 'll-tools-text-domain'), 'jwks_url' => __('Platform public keyset URL', 'll-tools-text-domain'), 'token_url' => __('Access token URL', 'll-tools-text-domain')] as $key => $label): ?><tr><th><label for="ll-lti-<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label></th><td><input class="regular-text" id="ll-lti-<?php echo esc_attr($key); ?>" type="<?php echo str_ends_with($key, '_url') || $key === 'issuer' ? 'url' : 'text'; ?>" name="<?php echo esc_attr($key); ?>" maxlength="<?php echo str_ends_with($key, '_url') || $key === 'issuer' ? 2048 : 255; ?>" required></td></tr><?php endforeach; ?>
    <tr><th><?php esc_html_e('Enabled', 'll-tools-text-domain'); ?></th><td><label><input type="checkbox" name="enabled" value="1" checked> <?php esc_html_e('Allow launches', 'll-tools-text-domain'); ?></label></td></tr>
    </tbody></table><?php submit_button(__('Save platform', 'll-tools-text-domain')); ?></form>
    <?php endif; ?>
    <h2><?php esc_html_e('Class activities', 'll-tools-text-domain'); ?></h2><p><?php esc_html_e('Create the class in LL Tools first. Students are enrolled on their first trusted launch; removing them from the LL Tools class blocks later launches without deleting their personal accounts.', 'll-tools-text-domain'); ?></p>
    <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>"><input type="hidden" name="page" value="ll-tools-lti"><label for="ll-lti-class"><?php esc_html_e('Class', 'll-tools-text-domain'); ?></label> <select id="ll-lti-class" name="class_id"><option value="0"><?php esc_html_e('Select class', 'll-tools-text-domain'); ?></option><?php foreach ($classes as $class): ?><option value="<?php echo (int) $class->ID; ?>" <?php selected($class_id, $class->ID); ?>><?php echo esc_html($class->post_title); ?></option><?php endforeach; ?></select> <?php submit_button(__('Choose class', 'll-tools-text-domain'), 'secondary', '', false); ?></form>
    <?php if ($class_id > 0 && is_array($choices) && $choices !== []): ?>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="ll_tools_lti_register_resource"><input type="hidden" name="class_id" value="<?php echo $class_id; ?>"><?php wp_nonce_field('ll_tools_lti_register_resource'); ?><table class="form-table"><tbody>
    <tr><th><label for="ll-lti-platform"><?php esc_html_e('Moodle platform', 'll-tools-text-domain'); ?></label></th><td><select id="ll-lti-platform" name="platform_id" required><?php foreach ($platforms as $platform): if (empty($platform['enabled'])) { continue; } ?><option value="<?php echo esc_attr($platform['id']); ?>"><?php echo esc_html($platform['name']); ?></option><?php endforeach; ?></select></td></tr>
    <tr><th><label for="ll-lti-context"><?php esc_html_e('Moodle course context ID', 'll-tools-text-domain'); ?></label></th><td><input id="ll-lti-context" name="context_id" class="regular-text" required maxlength="255"><p class="description"><?php esc_html_e('Use the exact LTI context ID sent by this Moodle course.', 'll-tools-text-domain'); ?></p></td></tr>
    <tr><th><label for="ll-lti-resource-link"><?php esc_html_e('Moodle resource link ID', 'll-tools-text-domain'); ?></label></th><td><input id="ll-lti-resource-link" name="resource_link_id" class="regular-text" maxlength="255"><p class="description"><?php esc_html_e('Optional before the first launch. The first verified launch pins the resource link, preventing reuse by a different Moodle activity.', 'll-tools-text-domain'); ?></p></td></tr>
    <tr><th><label for="ll-lti-name"><?php esc_html_e('Activity name', 'll-tools-text-domain'); ?></label></th><td><input id="ll-lti-name" name="name" class="regular-text" required maxlength="200"></td></tr>
    <tr><th><label for="ll-lti-category"><?php esc_html_e('Lesson category', 'll-tools-text-domain'); ?></label></th><td><select id="ll-lti-category" name="category_id"><?php foreach ($choices as $category): ?><option value="<?php echo (int) $category['id']; ?>"><?php echo esc_html($category['name']); ?></option><?php endforeach; ?></select></td></tr>
    <tr><th><label for="ll-lti-kind"><?php esc_html_e('Activity type', 'll-tools-text-domain'); ?></label></th><td><select id="ll-lti-kind" name="kind"><option value="practice"><?php esc_html_e('Practice — personal progress, no Moodle grade', 'll-tools-text-domain'); ?></option><option value="assignment"><?php esc_html_e('Graded assignment', 'll-tools-text-domain'); ?></option></select></td></tr>
    <tr><th><label for="ll-lti-assignment"><?php esc_html_e('Existing assignment UUID', 'll-tools-text-domain'); ?></label></th><td><input id="ll-lti-assignment" name="assignment_uuid" value="<?php echo esc_attr($retained_uuid); ?>" class="regular-text" maxlength="36"><p class="description"><?php esc_html_e('Optional. Leave blank to create and publish a fixed assignment from this category.', 'll-tools-text-domain'); ?></p></td></tr>
    <tr><th><label for="ll-lti-points"><?php esc_html_e('Maximum grade', 'll-tools-text-domain'); ?></label></th><td><input id="ll-lti-points" name="points_maximum" type="number" value="100" min="1" max="1000000"></td></tr>
    <tr><th><label for="ll-lti-attempts"><?php esc_html_e('Attempt limit', 'll-tools-text-domain'); ?></label></th><td><input id="ll-lti-attempts" name="attempt_limit" type="number" value="3" min="1" max="100"></td></tr>
    <tr><th><label for="ll-lti-policy"><?php esc_html_e('Grade policy', 'll-tools-text-domain'); ?></label></th><td><select id="ll-lti-policy" name="grade_policy"><option value="best"><?php esc_html_e('Best completed attempt', 'll-tools-text-domain'); ?></option><option value="latest"><?php esc_html_e('Latest completed attempt', 'll-tools-text-domain'); ?></option><option value="first"><?php esc_html_e('First completed attempt', 'll-tools-text-domain'); ?></option></select></td></tr>
    </tbody></table><?php submit_button(__('Create Moodle activity', 'll-tools-text-domain')); ?></form>
    <?php elseif ($class_id > 0): ?><p><?php esc_html_e('No eligible lesson categories are available for this class wordset.', 'll-tools-text-domain'); ?></p><?php endif; ?>
    <h2><?php esc_html_e('Configured activities', 'll-tools-text-domain'); ?></h2><table class="widefat striped"><thead><tr><th><?php esc_html_e('Activity', 'll-tools-text-domain'); ?></th><th><?php esc_html_e('Course context', 'll-tools-text-domain'); ?></th><th><?php esc_html_e('Moodle custom parameter', 'll-tools-text-domain'); ?></th><th><?php esc_html_e('Type / assignment', 'll-tools-text-domain'); ?></th><th><?php esc_html_e('Actions', 'll-tools-text-domain'); ?></th></tr></thead><tbody>
    <?php foreach (ll_tools_lti_get_resources() as $row): if (!ll_tools_lms_assignment_user_can_manage_class((int) $row['class_id'], $actor) || ($class_id > 0 && (int) $row['class_id'] !== $class_id)) { continue; } ?><tr><td><?php echo esc_html($row['name']); ?></td><td><code><?php echo esc_html($row['context_id']); ?></code></td><td><code>ll_activity=<?php echo esc_html($row['id']); ?></code></td><td><?php echo $row['kind'] === 'practice' ? esc_html__('Practice', 'll-tools-text-domain') : '<code>' . esc_html($row['assignment_uuid']) . '</code>'; ?></td><td><?php if (!empty($row['enabled'])): ?><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="ll_tools_lti_disable_resource"><input type="hidden" name="resource_id" value="<?php echo esc_attr($row['id']); ?>"><?php wp_nonce_field('ll_tools_lti_disable_resource_' . $row['id']); ?><button type="submit" class="button"><?php esc_html_e('Disable', 'll-tools-text-domain'); ?></button></form><?php else: esc_html_e('Disabled', 'll-tools-text-domain'); endif; ?></td></tr><?php endforeach; ?>
    </tbody></table></div><?php
}
