<?php
/** Wordset editor for the ZIPA provider and its matching rules. */

defined('WPINC') || exit;

function ll_tools_zipa_admin_menu(): void {
    $hook = add_submenu_page(
        'tools.php',
        __('ZIPA Speaking Games', 'll-tools-text-domain'),
        __('ZIPA Speaking Games', 'll-tools-text-domain'),
        'view_ll_tools',
        'll-zipa-settings',
        'll_tools_zipa_render_admin'
    );
    if ($hook) {
        add_action('load-' . $hook, 'll_tools_zipa_admin_handle_post');
    }
}
add_action('admin_menu', 'll_tools_zipa_admin_menu');

function ll_tools_zipa_admin_can_edit(int $wordset_id): bool {
    return $wordset_id > 0 && current_user_can('view_ll_tools')
        && ll_tools_user_can_manage_wordset_content($wordset_id);
}

function ll_tools_zipa_admin_url(int $wordset_id): string {
    return add_query_arg(['page' => 'll-zipa-settings', 'wordset_id' => $wordset_id], admin_url('tools.php'));
}

function ll_tools_zipa_admin_wordset_link(WP_Term $wordset): void {
    if (!ll_tools_zipa_admin_can_edit((int) $wordset->term_id)) {
        return;
    }
    printf(
        '<tr class="form-field"><th scope="row">%s</th><td><a href="%s">%s</a></td></tr>',
        esc_html__('Speaking games', 'll-tools-text-domain'),
        esc_url(ll_tools_zipa_admin_url((int) $wordset->term_id)),
        esc_html__('Edit ZIPA settings', 'll-tools-text-domain')
    );
}
add_action('wordset_edit_form_fields', 'll_tools_zipa_admin_wordset_link');

function ll_tools_zipa_admin_request_text(array $request, string $key): string {
    return is_string($request[$key] ?? null) ? wp_unslash($request[$key]) : '';
}

function ll_tools_zipa_admin_read_json(string $input, string $field): array {
    if (strlen($input) > 98304) {
        throw ll_tools_zipa_setting_error($field);
    }
    if (trim($input) === '') {
        return [];
    }
    if (!str_starts_with(ltrim($input), '{')) {
        throw ll_tools_zipa_setting_error($field);
    }

    $value = json_decode($input, true, 16);
    if (!is_array($value)) {
        throw ll_tools_zipa_setting_error($field);
    }
    return $value;
}

function ll_tools_zipa_admin_prepare_input(array $request): array {
    $fields = ['audience', 'browserModelManifestUrl', 'cardIds', 'cardReferences', 'ipaAliases', 'closeForms', 'matcher', 'stackCapture'];
    $input = [];
    foreach ($fields as $field) {
        $input[$field] = ll_tools_zipa_admin_request_text($request, $field);
    }
    $input['enabled'] = ($request['enabled'] ?? null) === '1';
    $input['hostedEnabled'] = ($request['hostedEnabled'] ?? null) === '1';
    return $input;
}

function ll_tools_zipa_admin_save_input(int $wordset_id, array $input) {
    try {
        $settings = $input;
        if (strlen($input['cardIds']) > 2048) {
            throw ll_tools_zipa_setting_error('cardIds');
        }
        $settings['cardIds'] = preg_split('/[\s,]+/', trim($input['cardIds']), -1, PREG_SPLIT_NO_EMPTY);
        foreach (['cardReferences', 'ipaAliases', 'matcher', 'stackCapture'] as $field) {
            $settings[$field] = ll_tools_zipa_admin_read_json($input[$field], $field);
        }
        $settings['matcher']['closeForms'] = ll_tools_zipa_admin_read_json($input['closeForms'], 'closeForms');
        unset($settings['closeForms']);
        return ll_tools_zipa_save_settings($wordset_id, $settings);
    } catch (InvalidArgumentException $error) {
        return new WP_Error('ll_zipa_invalid_setting', $error->getMessage());
    }
}

function ll_tools_zipa_admin_format_settings(array $settings): array {
    $settings['closeForms'] = $settings['matcher']['closeForms'] ?? [];
    unset($settings['matcher']['closeForms']);
    $settings['cardIds'] = implode(', ', $settings['cardIds']);
    foreach (['cardReferences', 'ipaAliases', 'closeForms', 'matcher', 'stackCapture'] as $field) {
        $settings[$field] = (string) wp_json_encode((object) $settings[$field], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    return $settings;
}

function ll_tools_zipa_admin_handle_post(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        return;
    }
    $wordset_id = absint(ll_tools_zipa_admin_request_text($_GET, 'wordset_id'));
    if (!ll_tools_zipa_admin_can_edit($wordset_id)) {
        wp_die(esc_html__('You cannot edit ZIPA settings for this wordset.', 'll-tools-text-domain'), '', ['response' => 403]);
    }
    check_admin_referer('ll_zipa_settings_' . $wordset_id);

    $saved = ll_tools_zipa_admin_save_input($wordset_id, ll_tools_zipa_admin_prepare_input($_POST));
    if (is_wp_error($saved)) {
        add_settings_error('ll_zipa_settings', 'll_zipa_invalid_setting', esc_html($saved->get_error_message()));
        return;
    }
    wp_safe_redirect(add_query_arg('saved', '1', ll_tools_zipa_admin_url($wordset_id)));
    exit;
}

function ll_tools_zipa_admin_textarea(string $field, string $label, string $value, string $description, int $rows): void {
    $id = 'll-zipa-' . $field;
    ?>
    <tr>
        <th scope="row"><label for="<?php echo esc_attr($id); ?>"><?php echo esc_html($label); ?></label></th>
        <td>
            <textarea id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr($field); ?>"
                class="large-text code" rows="<?php echo esc_attr((string) $rows); ?>" spellcheck="false"
                aria-describedby="<?php echo esc_attr($id . '-help'); ?>"><?php echo esc_textarea($value); ?></textarea>
            <p class="description" id="<?php echo esc_attr($id . '-help'); ?>"><?php echo esc_html($description); ?></p>
        </td>
    </tr>
    <?php
}

function ll_tools_zipa_render_admin(): void {
    if (!current_user_can('view_ll_tools')) {
        wp_die(esc_html__('You cannot edit ZIPA settings.', 'll-tools-text-domain'), '', ['response' => 403]);
    }

    $wordset_id = absint(ll_tools_zipa_admin_request_text($_GET, 'wordset_id'));
    $wordset = $wordset_id > 0 ? get_term($wordset_id, 'wordset') : null;
    if ($wordset_id && (!($wordset instanceof WP_Term) || !ll_tools_zipa_admin_can_edit($wordset_id))) {
        wp_die(esc_html__('You cannot edit ZIPA settings for this wordset.', 'll-tools-text-domain'), '', ['response' => 403]);
    }

    $saved_settings = ll_tools_zipa_get_settings($wordset_id);
    $input = $wordset_id && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
        ? ll_tools_zipa_admin_prepare_input($_POST)
        : ll_tools_zipa_admin_format_settings($saved_settings);
    $preview_url = $wordset instanceof WP_Term && $saved_settings['enabled'] && $saved_settings['hostedEnabled']
        ? add_query_arg('ll_zipa_hosted', '1', ll_tools_get_wordset_page_view_url($wordset, 'games')) : '';

    $wordsets = get_terms(['taxonomy' => 'wordset', 'hide_empty' => false, 'orderby' => 'name']);
    $wordsets = is_array($wordsets) ? array_filter($wordsets, static fn($term): bool => $term instanceof WP_Term
        && ll_tools_zipa_admin_can_edit((int) $term->term_id)) : [];
    $audiences = [
        'learners' => __('Signed-in learners', 'll-tools-text-domain'),
        'everyone' => __('Everyone who can view this wordset', 'll-tools-text-domain'),
        'managers' => __('Wordset managers', 'll-tools-text-domain'),
    ];

    ?>
    <div class="wrap">
        <h1><?php esc_html_e('ZIPA Speaking Games', 'll-tools-text-domain'); ?></h1>
        <?php settings_errors('ll_zipa_settings'); ?>
        <?php if (ll_tools_zipa_admin_request_text($_GET, 'saved') === '1') : ?>
            <div class="notice notice-success is-dismissible"><p><?php esc_html_e('ZIPA settings saved.', 'll-tools-text-domain'); ?></p></div>
        <?php endif; ?>
        <form method="get" action="<?php echo esc_url(admin_url('tools.php')); ?>">
            <input type="hidden" name="page" value="ll-zipa-settings">
            <label for="ll-zipa-wordset"><?php esc_html_e('Wordset', 'll-tools-text-domain'); ?></label>
            <select name="wordset_id" id="ll-zipa-wordset" required>
                <option value=""><?php esc_html_e('Select a wordset', 'll-tools-text-domain'); ?></option>
                <?php foreach ($wordsets as $term) : ?>
                    <option value="<?php echo esc_attr((string) $term->term_id); ?>" <?php selected($wordset_id, $term->term_id); ?>><?php echo esc_html($term->name); ?></option>
                <?php endforeach; ?>
            </select>
            <?php submit_button(__('Open', 'll-tools-text-domain'), 'secondary', '', false); ?>
        </form>

        <?php if ($wordset_id) : ?>
            <form method="post" action="<?php echo esc_url(ll_tools_zipa_admin_url($wordset_id)); ?>">
                <?php wp_nonce_field('ll_zipa_settings_' . $wordset_id); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('ZIPA speaking games', 'll-tools-text-domain'); ?></th>
                        <td><label><input type="checkbox" name="enabled" value="1" <?php checked($input['enabled']); ?>> <?php esc_html_e('Enable ZIPA for this wordset', 'll-tools-text-domain'); ?></label></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ll-zipa-audience"><?php esc_html_e('On-device access', 'll-tools-text-domain'); ?></label></th>
                        <td>
                            <select id="ll-zipa-audience" name="audience">
                                <?php foreach ($audiences as $value => $label) : ?>
                                    <option value="<?php echo esc_attr($value); ?>" <?php selected($input['audience'], $value); ?>><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ll-zipa-manifest"><?php esc_html_e('Custom model manifest', 'll-tools-text-domain'); ?></label></th>
                        <td>
                            <input type="url" id="ll-zipa-manifest" name="browserModelManifestUrl" class="large-text code" value="<?php echo esc_attr($input['browserModelManifestUrl']); ?>" aria-describedby="ll-zipa-manifest-help">
                            <p class="description" id="ll-zipa-manifest-help"><?php esc_html_e('Enter the URL of a public model manifest hosted on this site. Leave blank to use stock ZIPA.', 'll-tools-text-domain'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Hosted model preview', 'll-tools-text-domain'); ?></th>
                        <td>
                            <label><input type="checkbox" name="hostedEnabled" value="1" <?php checked($input['hostedEnabled']); ?>> <?php esc_html_e('Allow wordset managers to try the hosted model', 'll-tools-text-domain'); ?></label>
                            <p class="description"><?php esc_html_e('Uses the hosted endpoint and credentials already set in wordset transcription settings.', 'll-tools-text-domain'); ?></p>
                            <?php if ($preview_url !== '') : ?>
                                <p><a href="<?php echo esc_url($preview_url); ?>"><?php esc_html_e('Open hosted preview', 'll-tools-text-domain'); ?></a></p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php
                    ll_tools_zipa_admin_textarea('cardIds', __('Curated words', 'll-tools-text-domain'), $input['cardIds'],
                        __('Optional word IDs from this wordset, separated by spaces or commas. Leave blank to use the regular game pool.', 'll-tools-text-domain'), 2);
                    ll_tools_zipa_admin_textarea('ipaAliases', __('Accepted pronunciations', 'll-tools-text-domain'), $input['ipaAliases'],
                        __('Full-credit IPA variants by word ID. Example {"123":["kæt","kat"]}.', 'll-tools-text-domain'), 5);
                    ll_tools_zipa_admin_textarea('closeForms', __('Close matches', 'll-tools-text-domain'), $input['closeForms'],
                        __('Model confusions allowed for individual words in Word Stack. Example {"123":{"target":"kæt","heard":["kət"]}}.', 'll-tools-text-domain'), 5);
                    ?>
                </table>
                <details>
                    <summary><?php esc_html_e('Advanced ZIPA settings', 'll-tools-text-domain'); ?></summary>
                    <table class="form-table" role="presentation">
                        <?php
                        ll_tools_zipa_admin_textarea('matcher', __('Pronunciation rules', 'll-tools-text-domain'), $input['matcher'],
                            __('A JSON object for this wordset’s sound equivalences, IPA normalization, and spelling display. Example {"confusableGraphemes":{"r":"rr","rr":"r"}}.', 'll-tools-text-domain'), 12);
                        ll_tools_zipa_admin_textarea('cardReferences', __('Card reference overrides', 'll-tools-text-domain'), $input['cardReferences'],
                            __('Optional recording ID, image URL, title, and reference IPA assignments by word ID. Example {"123":{"recordingId":456,"ipa":"kæt"}}.', 'll-tools-text-domain'), 6);
                        ll_tools_zipa_admin_textarea('stackCapture', __('Word Stack microphone tuning', 'll-tools-text-domain'), $input['stackCapture'],
                            __('Optional speechStartThreshold and silenceThreshold between zero and one, and feedbackHoldMs in milliseconds.', 'll-tools-text-domain'), 3);
                        ?>
                    </table>
                </details>
                <?php submit_button(); ?>
            </form>
        <?php endif; ?>
    </div>
    <?php
}
