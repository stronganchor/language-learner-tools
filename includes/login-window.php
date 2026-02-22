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

if (!function_exists('ll_tools_is_learner_self_registration_enabled')) {
    function ll_tools_is_learner_self_registration_enabled(): bool {
        $enabled = (int) get_option('ll_allow_learner_self_registration', 1);
        return (bool) apply_filters('ll_tools_allow_learner_self_registration', ($enabled === 1));
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

if (!function_exists('ll_tools_login_window_feedback_storage_key')) {
    function ll_tools_login_window_feedback_storage_key(string $token): string {
        return 'll_tools_auth_feedback_' . $token;
    }
}

if (!function_exists('ll_tools_login_window_sanitize_feedback_token')) {
    function ll_tools_login_window_sanitize_feedback_token($token): string {
        $token = strtolower((string) $token);
        $token = preg_replace('/[^a-z0-9]/', '', $token);
        return substr((string) $token, 0, 40);
    }
}

if (!function_exists('ll_tools_login_window_store_feedback')) {
    function ll_tools_login_window_store_feedback(array $feedback): string {
        $token = ll_tools_login_window_sanitize_feedback_token(wp_generate_password(24, false, false));
        if ($token === '') {
            return '';
        }

        set_transient(ll_tools_login_window_feedback_storage_key($token), $feedback, 10 * MINUTE_IN_SECONDS);
        return $token;
    }
}

if (!function_exists('ll_tools_login_window_append_feedback_to_url')) {
    function ll_tools_login_window_append_feedback_to_url(string $url, array $feedback): string {
        $token = ll_tools_login_window_store_feedback($feedback);
        if ($token === '') {
            return $url;
        }

        return (string) add_query_arg('ll_tools_auth_feedback', $token, $url);
    }
}

if (!function_exists('ll_tools_login_window_consume_feedback_from_request')) {
    function ll_tools_login_window_consume_feedback_from_request(): array {
        $raw_token = isset($_GET['ll_tools_auth_feedback']) ? wp_unslash((string) $_GET['ll_tools_auth_feedback']) : '';
        $token = ll_tools_login_window_sanitize_feedback_token($raw_token);
        if ($token === '') {
            return [];
        }

        $key = ll_tools_login_window_feedback_storage_key($token);
        $payload = get_transient($key);
        delete_transient($key);

        if (!is_array($payload)) {
            return [];
        }

        return $payload;
    }
}

if (!function_exists('ll_tools_get_auth_redirect_target')) {
    function ll_tools_get_auth_redirect_target($requested_redirect = ''): string {
        $requested = ll_tools_get_valid_login_redirect_request($requested_redirect);
        if ($requested !== '') {
            return $requested;
        }

        $referer = wp_get_referer();
        if (is_string($referer) && $referer !== '') {
            $referer = ll_tools_get_valid_login_redirect_request($referer);
            if ($referer !== '') {
                return $referer;
            }
        }

        return home_url('/');
    }
}

if (!function_exists('ll_tools_handle_frontend_learner_registration')) {
    function ll_tools_handle_frontend_learner_registration(): void {
        $raw_redirect = isset($_POST['redirect_to']) ? wp_unslash((string) $_POST['redirect_to']) : '';
        $redirect_to = ll_tools_get_auth_redirect_target($raw_redirect);

        if (is_user_logged_in()) {
            wp_safe_redirect($redirect_to);
            exit;
        }

        if (!ll_tools_is_learner_self_registration_enabled()) {
            $redirect_to = ll_tools_login_window_append_feedback_to_url($redirect_to, [
                'type' => 'error',
                'messages' => [__('New account registration is currently disabled.', 'll-tools-text-domain')],
            ]);
            wp_safe_redirect($redirect_to);
            exit;
        }

        $nonce = isset($_POST['ll_tools_register_learner_nonce']) ? wp_unslash((string) $_POST['ll_tools_register_learner_nonce']) : '';
        if (!wp_verify_nonce($nonce, 'll_tools_register_learner')) {
            $redirect_to = ll_tools_login_window_append_feedback_to_url($redirect_to, [
                'type' => 'error',
                'messages' => [__('Registration security check failed. Please try again.', 'll-tools-text-domain')],
            ]);
            wp_safe_redirect($redirect_to);
            exit;
        }

        $username = isset($_POST['ll_tools_register_username'])
            ? sanitize_user(wp_unslash((string) $_POST['ll_tools_register_username']), true)
            : '';
        $email = isset($_POST['ll_tools_register_email'])
            ? sanitize_email(wp_unslash((string) $_POST['ll_tools_register_email']))
            : '';
        $password = isset($_POST['ll_tools_register_password'])
            ? (string) wp_unslash($_POST['ll_tools_register_password'])
            : '';
        $password_confirm = isset($_POST['ll_tools_register_password_confirm'])
            ? (string) wp_unslash($_POST['ll_tools_register_password_confirm'])
            : '';

        $errors = [];
        if ($username === '') {
            $errors[] = __('Please choose a username.', 'll-tools-text-domain');
        } elseif (!validate_username($username)) {
            $errors[] = __('That username is not valid.', 'll-tools-text-domain');
        } elseif (username_exists($username)) {
            $errors[] = __('That username is already taken.', 'll-tools-text-domain');
        }

        if ($email === '' || !is_email($email)) {
            $errors[] = __('Please enter a valid email address.', 'll-tools-text-domain');
        } elseif (email_exists($email)) {
            $errors[] = __('That email is already registered.', 'll-tools-text-domain');
        }

        if ($password === '') {
            $errors[] = __('Please enter a password.', 'll-tools-text-domain');
        } elseif (strlen($password) < 8) {
            $errors[] = __('Use at least 8 characters for your password.', 'll-tools-text-domain');
        }

        if ($password !== $password_confirm) {
            $errors[] = __('Password confirmation does not match.', 'll-tools-text-domain');
        }

        if (!empty($errors)) {
            $redirect_to = ll_tools_login_window_append_feedback_to_url($redirect_to, [
                'type' => 'error',
                'messages' => $errors,
                'prefill' => [
                    'username' => $username,
                    'email' => $email,
                ],
            ]);
            wp_safe_redirect($redirect_to);
            exit;
        }

        if (function_exists('ll_tools_register_or_refresh_learner_role')) {
            ll_tools_register_or_refresh_learner_role();
        }

        $user_id = wp_insert_user([
            'user_login' => $username,
            'user_email' => $email,
            'user_pass' => $password,
            'display_name' => $username,
            'role' => 'll_tools_learner',
        ]);

        if (is_wp_error($user_id)) {
            $messages = $user_id->get_error_messages();
            if (empty($messages)) {
                $messages = [__('Unable to create the account right now.', 'll-tools-text-domain')];
            }

            $redirect_to = ll_tools_login_window_append_feedback_to_url($redirect_to, [
                'type' => 'error',
                'messages' => array_values(array_map('strval', $messages)),
                'prefill' => [
                    'username' => $username,
                    'email' => $email,
                ],
            ]);
            wp_safe_redirect($redirect_to);
            exit;
        }

        $user_id = (int) $user_id;
        $user = get_user_by('id', $user_id);
        if ($user instanceof WP_User) {
            $user->set_role('ll_tools_learner');
            wp_set_current_user($user_id, $user->user_login);
            wp_set_auth_cookie($user_id, true, is_ssl());
            do_action('wp_login', $user->user_login, $user);
        } else {
            wp_set_current_user($user_id);
            wp_set_auth_cookie($user_id, true, is_ssl());
        }

        wp_safe_redirect($redirect_to);
        exit;
    }
}
add_action('admin_post_nopriv_ll_tools_register_learner', 'll_tools_handle_frontend_learner_registration');
add_action('admin_post_ll_tools_register_learner', 'll_tools_handle_frontend_learner_registration');

if (!function_exists('ll_tools_render_login_window')) {
    function ll_tools_render_login_window(array $args = []): string {
        $defaults = [
            'container_class' => '',
            'title' => __('Sign in', 'll-tools-text-domain'),
            'message' => __('Sign in to continue.', 'll-tools-text-domain'),
            'submit_label' => __('Log in', 'll-tools-text-domain'),
            'redirect_to' => '',
            'show_lost_password' => true,
            'show_registration' => false,
            'registration_title' => __('Create account', 'll-tools-text-domain'),
            'registration_message' => __('New here? Create a learner account to save your progress.', 'll-tools-text-domain'),
            'registration_submit_label' => __('Create account', 'll-tools-text-domain'),
            'registration_disabled_message' => __('New account registration is currently disabled.', 'll-tools-text-domain'),
        ];
        $args = wp_parse_args($args, $defaults);

        ll_enqueue_asset_by_timestamp('/css/login-window.css', 'll-tools-login-window');

        $redirect_to = trim((string) $args['redirect_to']);
        if ($redirect_to === '') {
            $redirect_to = ll_tools_get_current_request_url();
        }
        $redirect_to = (string) remove_query_arg('ll_tools_auth_feedback', $redirect_to);
        $redirect_to = esc_url_raw($redirect_to);

        $suffix = substr(md5($redirect_to . '|' . (string) $args['title']), 0, 8);
        $show_registration = !empty($args['show_registration']);
        $feedback = $show_registration ? ll_tools_login_window_consume_feedback_from_request() : [];
        $feedback_messages = [];
        if (isset($feedback['messages']) && is_array($feedback['messages'])) {
            foreach ($feedback['messages'] as $message) {
                $message = trim((string) $message);
                if ($message !== '') {
                    $feedback_messages[] = $message;
                }
            }
        }
        $feedback_type = (isset($feedback['type']) && $feedback['type'] === 'success') ? 'success' : 'error';
        $prefill = (isset($feedback['prefill']) && is_array($feedback['prefill'])) ? $feedback['prefill'] : [];

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

        $registration_enabled = $show_registration && ll_tools_is_learner_self_registration_enabled();
        $registration_disabled = $show_registration && !$registration_enabled;

        $registration_title = trim((string) $args['registration_title']);
        $registration_message = trim((string) $args['registration_message']);
        $registration_submit_label = trim((string) $args['registration_submit_label']);
        if ($registration_submit_label === '') {
            $registration_submit_label = __('Create account', 'll-tools-text-domain');
        }
        $registration_disabled_message = trim((string) $args['registration_disabled_message']);
        if ($registration_disabled_message === '') {
            $registration_disabled_message = __('New account registration is currently disabled.', 'll-tools-text-domain');
        }

        $registration_form_id = 'll-tools-register-form-' . $suffix;
        $registration_username_id = 'll-tools-register-username-' . $suffix;
        $registration_email_id = 'll-tools-register-email-' . $suffix;
        $registration_password_id = 'll-tools-register-password-' . $suffix;
        $registration_password_confirm_id = 'll-tools-register-password-confirm-' . $suffix;

        $prefill_username = isset($prefill['username']) ? sanitize_user((string) $prefill['username'], true) : '';
        $prefill_email = isset($prefill['email']) ? sanitize_email((string) $prefill['email']) : '';

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

                <?php if ($registration_enabled || $registration_disabled): ?>
                    <div class="ll-tools-login-window__divider" role="presentation">
                        <span><?php esc_html_e('or', 'll-tools-text-domain'); ?></span>
                    </div>
                <?php endif; ?>

                <?php if ($registration_enabled): ?>
                    <div class="ll-tools-login-window__register">
                        <?php if ($registration_title !== ''): ?>
                            <h3 class="ll-tools-login-window__register-title"><?php echo esc_html($registration_title); ?></h3>
                        <?php endif; ?>
                        <?php if ($registration_message !== ''): ?>
                            <p class="ll-tools-login-window__register-message"><?php echo esc_html($registration_message); ?></p>
                        <?php endif; ?>

                        <?php if (!empty($feedback_messages)): ?>
                            <div class="ll-tools-login-window__notice ll-tools-login-window__notice--<?php echo esc_attr($feedback_type); ?>" role="alert">
                                <?php if (count($feedback_messages) === 1): ?>
                                    <p><?php echo esc_html($feedback_messages[0]); ?></p>
                                <?php else: ?>
                                    <ul>
                                        <?php foreach ($feedback_messages as $feedback_message): ?>
                                            <li><?php echo esc_html($feedback_message); ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <form id="<?php echo esc_attr($registration_form_id); ?>" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <input type="hidden" name="action" value="ll_tools_register_learner" />
                            <input type="hidden" name="redirect_to" value="<?php echo esc_attr($redirect_to); ?>" />
                            <?php wp_nonce_field('ll_tools_register_learner', 'll_tools_register_learner_nonce'); ?>

                            <p>
                                <label for="<?php echo esc_attr($registration_username_id); ?>"><?php esc_html_e('Username', 'll-tools-text-domain'); ?></label>
                                <input
                                    type="text"
                                    id="<?php echo esc_attr($registration_username_id); ?>"
                                    name="ll_tools_register_username"
                                    value="<?php echo esc_attr($prefill_username); ?>"
                                    autocomplete="username"
                                    required />
                            </p>
                            <p>
                                <label for="<?php echo esc_attr($registration_email_id); ?>"><?php esc_html_e('Email', 'll-tools-text-domain'); ?></label>
                                <input
                                    type="email"
                                    id="<?php echo esc_attr($registration_email_id); ?>"
                                    name="ll_tools_register_email"
                                    value="<?php echo esc_attr($prefill_email); ?>"
                                    autocomplete="email"
                                    required />
                            </p>
                            <p>
                                <label for="<?php echo esc_attr($registration_password_id); ?>"><?php esc_html_e('Password', 'll-tools-text-domain'); ?></label>
                                <input
                                    type="password"
                                    id="<?php echo esc_attr($registration_password_id); ?>"
                                    name="ll_tools_register_password"
                                    autocomplete="new-password"
                                    required />
                            </p>
                            <p>
                                <label for="<?php echo esc_attr($registration_password_confirm_id); ?>"><?php esc_html_e('Confirm Password', 'll-tools-text-domain'); ?></label>
                                <input
                                    type="password"
                                    id="<?php echo esc_attr($registration_password_confirm_id); ?>"
                                    name="ll_tools_register_password_confirm"
                                    autocomplete="new-password"
                                    required />
                            </p>
                            <p class="ll-tools-login-window__register-submit">
                                <button type="submit"><?php echo esc_html($registration_submit_label); ?></button>
                            </p>
                        </form>
                    </div>
                <?php elseif ($registration_disabled): ?>
                    <p class="ll-tools-login-window__assist ll-tools-login-window__assist--muted">
                        <?php echo esc_html($registration_disabled_message); ?>
                    </p>
                <?php endif; ?>

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
