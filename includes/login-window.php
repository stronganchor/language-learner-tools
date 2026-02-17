<?php
if (!defined('WPINC')) { die; }

if (!function_exists('ll_tools_get_current_request_url')) {
    function ll_tools_get_current_request_url(): string {
        global $wp;

        $request_path = '';
        if (isset($wp) && is_object($wp) && isset($wp->request)) {
            $request_path = (string) $wp->request;
        }

        $url = home_url('/');
        if ($request_path !== '') {
            $url = home_url('/' . ltrim($request_path, '/'));
        }

        if (!empty($_GET) && is_array($_GET)) {
            $query_args = wp_unslash($_GET);
            if (is_array($query_args) && !empty($query_args)) {
                $url = add_query_arg($query_args, $url);
            }
        }

        return esc_url_raw($url);
    }
}

if (!function_exists('ll_tools_get_valid_login_redirect_request')) {
    function ll_tools_get_valid_login_redirect_request($request): string {
        $request = is_string($request) ? trim($request) : '';
        if ($request === '') {
            return '';
        }

        return (string) wp_validate_redirect($request, '');
    }
}

if (!function_exists('ll_tools_login_window_class_string')) {
    function ll_tools_login_window_class_string($classes = ''): string {
        $class_list = ['ll-tools-login-window-wrap'];
        $raw_classes = is_array($classes) ? $classes : preg_split('/\s+/', (string) $classes);

        foreach ((array) $raw_classes as $candidate) {
            $candidate = sanitize_html_class((string) $candidate);
            if ($candidate !== '') {
                $class_list[] = $candidate;
            }
        }

        $class_list = array_values(array_unique($class_list));
        return implode(' ', $class_list);
    }
}

if (!function_exists('ll_tools_render_login_window')) {
    function ll_tools_render_login_window(array $args = []): string {
        $defaults = [
            'container_class' => '',
            'title' => __('Sign in', 'll-tools-text-domain'),
            'message' => __('Sign in to continue.', 'll-tools-text-domain'),
            'submit_label' => __('Log in', 'll-tools-text-domain'),
            'redirect_to' => '',
            'show_lost_password' => true,
        ];
        $args = wp_parse_args($args, $defaults);

        ll_enqueue_asset_by_timestamp('/css/login-window.css', 'll-tools-login-window');

        $redirect_to = trim((string) $args['redirect_to']);
        if ($redirect_to === '') {
            $redirect_to = ll_tools_get_current_request_url();
        }
        $redirect_to = esc_url_raw($redirect_to);

        $suffix = substr(md5($redirect_to . '|' . (string) $args['title']), 0, 8);
        $form_markup = wp_login_form([
            'echo'           => false,
            'redirect'       => $redirect_to,
            'form_id'        => 'll-tools-login-form-' . $suffix,
            'id_username'    => 'll-tools-user-login-' . $suffix,
            'id_password'    => 'll-tools-user-pass-' . $suffix,
            'id_remember'    => 'll-tools-user-remember-' . $suffix,
            'id_submit'      => 'll-tools-submit-' . $suffix,
            'label_username' => __('Username or Email', 'll-tools-text-domain'),
            'label_password' => __('Password', 'll-tools-text-domain'),
            'label_remember' => __('Remember me', 'll-tools-text-domain'),
            'label_log_in'   => (string) $args['submit_label'],
            'remember'       => true,
            'value_remember' => true,
        ]);

        $container_class = ll_tools_login_window_class_string((string) $args['container_class']);
        $title = trim((string) $args['title']);
        $message = trim((string) $args['message']);
        $show_lost_password = !empty($args['show_lost_password']);
        $lost_password_url = wp_lostpassword_url($redirect_to);

        ob_start();
        ?>
        <div class="<?php echo esc_attr($container_class); ?>">
            <div class="ll-tools-login-window" role="group" aria-label="<?php echo esc_attr($title); ?>">
                <span class="ll-tools-login-window__icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" focusable="false">
                        <path d="M17 10h-1V7a4 4 0 10-8 0v3H7a2 2 0 00-2 2v7a2 2 0 002 2h10a2 2 0 002-2v-7a2 2 0 00-2-2zm-3 0h-4V7a2 2 0 114 0v3zm-2 8a2 2 0 110-4 2 2 0 010 4z"></path>
                    </svg>
                </span>
                <?php if ($title !== ''): ?>
                    <h2 class="ll-tools-login-window__title"><?php echo esc_html($title); ?></h2>
                <?php endif; ?>
                <?php if ($message !== ''): ?>
                    <p class="ll-tools-login-window__message"><?php echo esc_html($message); ?></p>
                <?php endif; ?>

                <div class="ll-tools-login-window__form">
                    <?php echo $form_markup; ?>
                </div>

                <?php if ($show_lost_password): ?>
                    <p class="ll-tools-login-window__assist">
                        <a href="<?php echo esc_url($lost_password_url); ?>">
                            <?php esc_html_e('Forgot password?', 'll-tools-text-domain'); ?>
                        </a>
                    </p>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }
}

