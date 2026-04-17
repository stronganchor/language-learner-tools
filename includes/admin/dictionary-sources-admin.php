<?php
if (!defined('WPINC')) { die; }

/**
 * Capability required for dictionary source registry management.
 */
function ll_tools_get_dictionary_sources_capability(): string {
    return (string) apply_filters('ll_tools_dictionary_sources_capability', 'manage_options');
}

function ll_tools_current_user_can_manage_dictionary_sources(): bool {
    return current_user_can(ll_tools_get_dictionary_sources_capability());
}

/**
 * Register the dictionary source registry admin page.
 */
function ll_tools_register_dictionary_sources_page(): void {
    add_management_page(
        __('LL Dictionary Sources', 'll-tools-text-domain'),
        __('LL Dictionary Sources', 'll-tools-text-domain'),
        ll_tools_get_dictionary_sources_capability(),
        'll-dictionary-sources',
        'll_tools_render_dictionary_sources_page'
    );
}
add_action('admin_menu', 'll_tools_register_dictionary_sources_page');

/**
 * Render the dictionary source registry screen.
 */
function ll_tools_render_dictionary_sources_page(): void {
    if (!ll_tools_current_user_can_manage_dictionary_sources()) {
        return;
    }

    $sources = array_values(ll_tools_get_dictionary_source_registry());
    $saved = false;

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ll_dictionary_sources_nonce'])) {
        check_admin_referer('ll_tools_dictionary_sources', 'll_dictionary_sources_nonce');
        $posted_sources = isset($_POST['ll_dictionary_sources']) ? wp_unslash($_POST['ll_dictionary_sources']) : [];
        $sources = array_values(ll_tools_update_dictionary_source_registry(is_array($posted_sources) ? $posted_sources : []));
        $saved = true;
    }

    $display_rows = $sources;
    for ($i = 0; $i < 3; $i++) {
        $display_rows[] = [
            'id' => '',
            'label' => '',
            'attribution_text' => '',
            'attribution_url' => '',
            'default_dialects' => [],
        ];
    }

    $import_url = admin_url('tools.php?page=ll-dictionary-import');
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('LL Dictionary Sources', 'll-tools-text-domain'); ?></h1>
        <p>
            <?php esc_html_e('Define reusable source records for imported dictionaries. Each source can carry a short public attribution statement, a public source-information page URL for the dictionary badges, and default dialect tags that are applied when an import row does not provide its own dialect field.', 'll-tools-text-domain'); ?>
        </p>
        <p>
            <?php
            echo wp_kses_post(sprintf(
                /* translators: %s: URL to the dictionary import tools screen */
                __('After saving sources here, use them from the <a href="%s">Dictionary Manager</a> screen with the TSV columns <code>source_id</code> or <code>source_dictionary</code>.', 'll-tools-text-domain'),
                esc_url($import_url)
            ));
            ?>
        </p>

        <?php if ($saved) : ?>
            <div class="notice notice-success"><p><?php esc_html_e('Dictionary sources updated.', 'll-tools-text-domain'); ?></p></div>
        <?php endif; ?>

        <form method="post">
            <?php wp_nonce_field('ll_tools_dictionary_sources', 'll_dictionary_sources_nonce'); ?>

            <p class="description">
                <?php esc_html_e('Use a stable source ID such as "regional-dictionary" or "community-archive". Leave both the ID and label blank to remove a row. Separate multiple default dialects with commas.', 'll-tools-text-domain'); ?>
            </p>

            <table class="widefat striped" style="margin-top:16px;">
                <thead>
                    <tr>
                        <th scope="col"><?php esc_html_e('Source ID', 'll-tools-text-domain'); ?></th>
                        <th scope="col"><?php esc_html_e('Label', 'll-tools-text-domain'); ?></th>
                        <th scope="col"><?php esc_html_e('Attribution Text', 'll-tools-text-domain'); ?></th>
                        <th scope="col"><?php esc_html_e('Source Page / Details URL', 'll-tools-text-domain'); ?></th>
                        <th scope="col"><?php esc_html_e('Default Dialects', 'll-tools-text-domain'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($display_rows as $index => $source) : ?>
                        <?php
                        $source_id = (string) ($source['id'] ?? '');
                        $label = (string) ($source['label'] ?? '');
                        $attribution_text = (string) ($source['attribution_text'] ?? '');
                        $attribution_url = (string) ($source['attribution_url'] ?? '');
                        $default_dialects = implode(', ', array_values(array_filter(array_map('strval', (array) ($source['default_dialects'] ?? [])))));
                        ?>
                        <tr>
                            <td style="width:14%;">
                                <input type="text" class="regular-text" name="ll_dictionary_sources[<?php echo esc_attr((string) $index); ?>][id]" value="<?php echo esc_attr($source_id); ?>" placeholder="<?php echo esc_attr__('regional-dictionary', 'll-tools-text-domain'); ?>">
                            </td>
                            <td style="width:16%;">
                                <input type="text" class="regular-text" name="ll_dictionary_sources[<?php echo esc_attr((string) $index); ?>][label]" value="<?php echo esc_attr($label); ?>" placeholder="<?php echo esc_attr__('Regional Dictionary', 'll-tools-text-domain'); ?>">
                            </td>
                            <td style="width:30%;">
                                <textarea class="large-text" rows="3" name="ll_dictionary_sources[<?php echo esc_attr((string) $index); ?>][attribution_text]"><?php echo esc_textarea($attribution_text); ?></textarea>
                            </td>
                            <td style="width:24%;">
                                <input type="url" class="large-text" name="ll_dictionary_sources[<?php echo esc_attr((string) $index); ?>][attribution_url]" value="<?php echo esc_attr($attribution_url); ?>" placeholder="<?php echo esc_attr__('https://example.com/dictionary-source/regional-dictionary', 'll-tools-text-domain'); ?>">
                            </td>
                            <td style="width:16%;">
                                <input type="text" class="regular-text" name="ll_dictionary_sources[<?php echo esc_attr((string) $index); ?>][default_dialects]" value="<?php echo esc_attr($default_dialects); ?>" placeholder="<?php echo esc_attr__('Northern, Southern', 'll-tools-text-domain'); ?>">
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php submit_button(__('Save Dictionary Sources', 'll-tools-text-domain')); ?>
        </form>
    </div>
    <?php
}
