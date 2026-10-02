<?php
if (!defined('WPINC')) { die; }

use LLTools\Vendor\Firebase\JWT\JWT;
use LLTools\Vendor\Firebase\JWT\JWK;

/** LTI 1.3 tool: explicit registrations, new-window launches, score-only AGS. */
const LL_TOOLS_LTI_PLATFORMS_OPTION = 'll_tools_lti_platforms';
const LL_TOOLS_LTI_SCORE_SCOPE = 'https://purl.imsglobal.org/spec/lti-ags/scope/score';

function ll_tools_lti_error(string $code, int $status = 400): WP_Error {
    return new WP_Error($code, __('The learning platform request could not be verified.', 'll-tools-text-domain'), ['status' => $status]);
}

function ll_tools_lti_tool_urls(): array {
    return [
        'login' => rest_url('ll-tools/v1/lti/login'),
        'launch' => rest_url('ll-tools/v1/lti/launch'),
        'jwks' => rest_url('ll-tools/v1/lti/jwks'),
        'resource' => home_url('/?ll_lti_resource=1'),
    ];
}

/** URL parsing deliberately excludes credentials, fragments, nonstandard ports and IP literals. */
function ll_tools_lti_url_origin($url): string {
    if (!is_string($url) || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) { return ''; }
    $parts = wp_parse_url($url);
    if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
        || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
        || (isset($parts['port']) && (int) $parts['port'] !== 443)) { return ''; }
    $host = strtolower((string) $parts['host']);
    if (filter_var($host, FILTER_VALIDATE_IP) || !preg_match('/^(?=.{1,253}$)[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/D', $host)
        || strpos($host, '.') === false || str_ends_with($host, '.local') || str_ends_with($host, '.localhost')) { return ''; }
    return 'https://' . $host;
}

function ll_tools_lti_opaque($value, int $max = 255): bool {
    return is_string($value) && $value !== '' && strlen($value) <= $max && !preg_match('/[\x00-\x1f\x7f]/', $value);
}

/** Registration contains public endpoint/configuration data only, never keys or bearer tokens. */
function ll_tools_lti_normalize_platform(array $config) {
    $issuer = $config['issuer'] ?? '';
    $origin = ll_tools_lti_url_origin($issuer);
    if ($origin === '' || !is_string($issuer) || wp_parse_url($issuer, PHP_URL_QUERY) !== null
        || !ll_tools_lti_opaque($config['client_id'] ?? '') || !ll_tools_lti_opaque($config['deployment_id'] ?? '')) {
        return ll_tools_lti_error('lti_invalid_platform');
    }
    foreach (['authorization_url', 'jwks_url', 'token_url'] as $key) {
        if (ll_tools_lti_url_origin($config[$key] ?? '') !== $origin) { return ll_tools_lti_error('lti_invalid_platform_endpoint'); }
    }
    $id = $config['id'] ?? substr(hash('sha256', $issuer . "\0" . $config['client_id'] . "\0" . $config['deployment_id']), 0, 24);
    if (!is_string($id) || !preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/D', $id)) { return ll_tools_lti_error('lti_invalid_platform_id'); }
    return [
        'id' => $id, 'name' => sanitize_text_field((string) ($config['name'] ?? $id)),
        'issuer' => $issuer, 'client_id' => $config['client_id'], 'deployment_id' => $config['deployment_id'],
        'authorization_url' => $config['authorization_url'], 'jwks_url' => $config['jwks_url'], 'token_url' => $config['token_url'],
        'enabled' => !empty($config['enabled']),
    ];
}

function ll_tools_lti_get_platforms(): array {
    $stored = get_option(LL_TOOLS_LTI_PLATFORMS_OPTION, []);
    if (!is_array($stored) || count($stored) > 10) { return []; }
    $valid = [];
    foreach ($stored as $config) {
        if (!is_array($config)) { continue; }
        $row = ll_tools_lti_normalize_platform($config);
        if (!is_wp_error($row)) { $valid[$row['id']] = $row; }
    }
    return $valid;
}

function ll_tools_lti_get_platform(string $id): ?array {
    return ll_tools_lti_get_platforms()[$id] ?? null;
}

function ll_tools_lti_register_platform(array $config) {
    if (!current_user_can('manage_options')) { return ll_tools_lti_error('lti_registration_forbidden', 403); }
    $row = ll_tools_lti_normalize_platform($config);
    if (is_wp_error($row)) { return $row; }
    $rows = ll_tools_lti_get_platforms();
    if (!isset($rows[$row['id']]) && count($rows) >= 10) { return ll_tools_lti_error('lti_platform_limit'); }
    foreach ($rows as $id => $other) {
        if ($id !== $row['id'] && $other['issuer'] === $row['issuer'] && $other['client_id'] === $row['client_id']
            && $other['deployment_id'] === $row['deployment_id']) { return ll_tools_lti_error('lti_duplicate_platform'); }
    }
    $rows[$row['id']] = $row;
    update_option(LL_TOOLS_LTI_PLATFORMS_OPTION, $rows, false);
    return ll_tools_lti_get_platform($row['id']) === $row ? $row : ll_tools_lti_error('lti_registration_write_failed', 503);
}

/** Encrypted non-autoload records; raw provider identity never enters generic hash-only rows. */
function ll_tools_lti_encrypt_record(array $value, string $name) {
    $json = wp_json_encode($value);
    if (!is_string($json) || strlen($json) > 65536) { return ll_tools_lti_error('lti_record_too_large'); }
    return ll_tools_lms_seal_secret($json, 'lti-record:' . $name);
}

function ll_tools_lti_decrypt_record($stored, string $name): ?array {
    if (!is_string($stored) || strlen($stored) > 100000) { return null; }
    $json = ll_tools_lms_open_secret($stored, 'lti-record:' . $name);
    if (!is_string($json)) { return null; }
    $value = json_decode($json, true, 32);
    return is_array($value) ? $value : null;
}

function ll_tools_lti_record_get(string $name): ?array {
    global $wpdb;
    $stored = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name));
    return ll_tools_lti_decrypt_record(maybe_unserialize($stored), $name);
}

function ll_tools_lti_uncache_record(string $name): void {
    wp_cache_delete($name, 'options');
    wp_cache_delete('notoptions', 'options');
    $GLOBALS['ll_tools_lti_changed_options'][$name] = true;
}

/** Immutable insert accepts an existing byte-independent equivalent record, never silently changes scope. */
function ll_tools_lti_record_put(string $name, array $value) {
    global $wpdb;
    $existing = ll_tools_lti_record_get($name);
    if (is_array($existing)) { return $existing === $value ? true : ll_tools_lti_error('lti_record_conflict', 409); }
    $encrypted = ll_tools_lti_encrypt_record($value, $name);
    if (is_wp_error($encrypted)) { return $encrypted; }
    if ($wpdb->insert($wpdb->options, ['option_name' => $name, 'option_value' => $encrypted, 'autoload' => 'no'], ['%s', '%s', '%s']) === 1) { ll_tools_lti_uncache_record($name); return true; }
    return ll_tools_lti_record_get($name) === $value ? true : ll_tools_lti_error('lti_record_write_failed', 503);
}

function ll_tools_lti_record_replace(string $name, array $before, array $after) {
    global $wpdb;
    $stored = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name));
    if (!is_string($stored) || ll_tools_lti_decrypt_record(maybe_unserialize($stored), $name) !== $before) { return ll_tools_lti_error('lti_record_conflict', 409); }
    $encrypted = ll_tools_lti_encrypt_record($after, $name);
    if (is_wp_error($encrypted)) { return $encrypted; }
    $changed = $wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $encrypted, $name, $stored));
    ll_tools_lti_uncache_record($name);
    return $changed === 1 ? true : ll_tools_lti_error('lti_record_write_failed', 503);
}

function ll_tools_lti_ticket_name(string $kind, string $ticket): string {
    if (!in_array($kind, ['state', 'launch', 'account'], true) || preg_match('/^[a-f0-9]{64}$/D', $ticket) !== 1) { return ''; }
    return 'll_tools_lti_ticket_' . $kind . '_' . $ticket;
}

function ll_tools_lti_ticket_put(string $kind, array $payload, int $ttl = 900) {
    try { $ticket = bin2hex(random_bytes(32)); } catch (Throwable $e) { return ll_tools_lti_error('lti_random_unavailable', 503); }
    $name = ll_tools_lti_ticket_name($kind, $ticket);
    if ($name === '') { return ll_tools_lti_error('lti_invalid_ticket'); }
    $record = ['expires_at' => time() + max(30, min(900, $ttl)), 'payload' => $payload];
    $saved = ll_tools_lti_record_put($name, $record);
    if (is_wp_error($saved)) { return $saved; }
    if (!wp_next_scheduled('ll_tools_lti_cleanup_tickets')) { wp_schedule_single_event(time() + 60, 'll_tools_lti_cleanup_tickets'); }
    return $ticket;
}

function ll_tools_lti_ticket_get(string $kind, string $ticket): ?array {
    $name = ll_tools_lti_ticket_name($kind, $ticket);
    if ($name === '') { return null; }
    $record = ll_tools_lti_record_get($name);
    return is_array($record) && (int) ($record['expires_at'] ?? 0) >= time() && is_array($record['payload'] ?? null) ? $record['payload'] : null;
}

/** Current DB read + exact-value DELETE is a one-use compare-and-swap even with persistent object caching. */
function ll_tools_lti_ticket_consume(string $kind, string $ticket) {
    global $wpdb;
    $name = ll_tools_lti_ticket_name($kind, $ticket);
    if ($name === '') { return ll_tools_lti_error('lti_invalid_ticket'); }
    $stored = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name));
    $record = ll_tools_lti_decrypt_record(maybe_unserialize($stored), $name);
    if (!is_string($stored) || !is_array($record)) { return ll_tools_lti_error('lti_ticket_expired_or_used', 403); }
    $deleted = $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $name, $stored));
    wp_cache_delete($name, 'options');
    if ($deleted !== 1 || (int) ($record['expires_at'] ?? 0) < time() || !is_array($record['payload'] ?? null)) {
        return ll_tools_lti_error('lti_ticket_expired_or_used', 403);
    }
    return $record['payload'];
}

function ll_tools_lti_browser_hash(string $cookie): string { return ll_tools_lti_hash('lti-browser:' . $cookie); }
function ll_tools_lti_browser_matches(array $payload): bool {
    $cookie = $_COOKIE['__Host-ll-tools-lti'] ?? '';
    return is_string($cookie) && preg_match('/^[a-f0-9]{64}$/D', $cookie) === 1
        && is_string($payload['_browser_binding'] ?? null) && hash_equals($payload['_browser_binding'], ll_tools_lti_browser_hash($cookie));
}
function ll_tools_lti_get_launch(string $ticket): ?array {
    $launch = ll_tools_lti_ticket_get('launch', $ticket);
    return is_array($launch) && ll_tools_lti_browser_matches($launch) ? $launch : null;
}
function ll_tools_lti_consume_launch(string $ticket) {
    $launch = ll_tools_lti_ticket_consume('launch', $ticket);
    if (is_wp_error($launch)) { return $launch; }
    return ll_tools_lti_browser_matches($launch) ? $launch : ll_tools_lti_error('lti_browser_binding_failed', 403);
}
function ll_tools_lti_delete_launch(string $ticket): bool { return !is_wp_error(ll_tools_lti_consume_launch($ticket)); }

function ll_tools_lti_cleanup_tickets(): void {
    global $wpdb;
    $prefix = $wpdb->esc_like('ll_tools_lti_ticket_') . '%';
    $rows = $wpdb->get_results($wpdb->prepare("SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id ASC LIMIT 200", $prefix), ARRAY_A);
    foreach ((array) $rows as $row) {
        $name = (string) $row['option_name'];
        $record = ll_tools_lti_decrypt_record(maybe_unserialize($row['option_value']), $name);
        if (!is_array($record) || (int) ($record['expires_at'] ?? 0) < time()) { delete_option($name); }
    }
    $admissions = $wpdb->get_results($wpdb->prepare("SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id ASC LIMIT 200", $wpdb->esc_like('ll_tools_lti_admission_') . '%'), ARRAY_A);
    foreach ((array) $admissions as $row) {
        if ((int) $row['option_value'] < time() - 120) { delete_option($row['option_name']); }
    }
    $guards = $wpdb->get_results($wpdb->prepare("SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id ASC LIMIT 200", $wpdb->esc_like('ll_tools_lti_u_') . '%' . $wpdb->esc_like('_a_') . '%'), ARRAY_A);
    foreach ((array) $guards as $row) {
        $record = ll_tools_lti_decrypt_record(maybe_unserialize($row['option_value']), $row['option_name']);
        if (!is_array($record) || (int) ($record['expires_at'] ?? 0) < time()) { delete_option($row['option_name']); }
    }
    if ((count((array) $rows) > 0 || count((array) $admissions) > 0 || count((array) $guards) > 0) && !wp_next_scheduled('ll_tools_lti_cleanup_tickets')) { wp_schedule_single_event(time() + 60, 'll_tools_lti_cleanup_tickets'); }
}
add_action('ll_tools_lti_cleanup_tickets', 'll_tools_lti_cleanup_tickets');

/** Fixed-origin, HTTPS-only, no redirects. wp_safe_remote_request rejects unsafe DNS/IP destinations. */
function ll_tools_lti_http(array $platform, string $url, array $args = []) {
    if (empty($platform['enabled']) || ll_tools_lti_url_origin($url) !== ll_tools_lti_url_origin($platform['issuer'] ?? '')) {
        return ll_tools_lti_error('lti_unsafe_endpoint');
    }
    $args = array_merge($args, ['timeout' => 8, 'redirection' => 0, 'sslverify' => true, 'reject_unsafe_urls' => true, 'limit_response_size' => 65536]);
    return wp_safe_remote_request($url, $args);
}

function ll_tools_lti_signing_material() {
    if (!class_exists(JWT::class) || !defined('LL_TOOLS_LTI_PRIVATE_KEY') || !defined('LL_TOOLS_LTI_KEY_ID')) { return ll_tools_lti_error('lti_signing_unavailable', 503); }
    $pem = constant('LL_TOOLS_LTI_PRIVATE_KEY');
    $kid = constant('LL_TOOLS_LTI_KEY_ID');
    if (!is_string($pem) || !is_string($kid) || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $kid)) { return ll_tools_lti_error('lti_signing_unavailable', 503); }
    $key = openssl_pkey_get_private($pem);
    $details = $key ? openssl_pkey_get_details($key) : false;
    if (!is_array($details) || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA || (int) ($details['bits'] ?? 0) < 2048 || empty($details['rsa']['n']) || empty($details['rsa']['e'])) {
        return ll_tools_lti_error('lti_signing_unavailable', 503);
    }
    $encode = static fn(string $raw): string => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    return ['pem' => $pem, 'kid' => $kid, 'jwk' => ['kty' => 'RSA', 'use' => 'sig', 'alg' => 'RS256', 'kid' => $kid, 'n' => $encode($details['rsa']['n']), 'e' => $encode($details['rsa']['e'])]];
}

function ll_tools_lti_rest_jwks(): WP_REST_Response {
    $material = ll_tools_lti_signing_material();
    return new WP_REST_Response(is_wp_error($material) ? ['keys' => []] : ['keys' => [$material['jwk']]], is_wp_error($material) ? 503 : 200);
}

function ll_tools_lti_redirect_response(string $url, int $status = 302): WP_REST_Response {
    $response = new WP_REST_Response(null, $status);
    $response->header('Location', $url);
    $response->header('Cache-Control', 'private, no-store, max-age=0');
    $response->header('Referrer-Policy', 'no-referrer');
    return $response;
}

/** Anonymous login admission is globally bounded; atomic INSERT limits concurrency too. */
function ll_tools_lti_login_admit(): bool {
    if (!wp_next_scheduled('ll_tools_lti_cleanup_tickets')) { wp_schedule_single_event(time() + 60, 'll_tools_lti_cleanup_tickets'); }
    $slot = (string) intdiv(time(), 60);
    for ($i = 0; $i < 30; $i++) {
        $name = 'll_tools_lti_admission_' . $slot . '_' . $i;
        if (add_option($name, time(), '', false)) { return true; }
    }
    return false;
}

function ll_tools_lti_rest_login(WP_REST_Request $request) {
    if (is_wp_error(ll_tools_lti_signing_material())) { return ll_tools_lti_error('lti_signing_unavailable', 503); }
    $issuer = $request->get_param('iss');
    $client = $request->get_param('client_id');
    $hint = $request->get_param('login_hint');
    $target = $request->get_param('target_link_uri');
    if (!ll_tools_lti_opaque($hint, 1024) || $target !== ll_tools_lti_tool_urls()['resource']) { return ll_tools_lti_error('lti_invalid_login'); }
    $matches = [];
    foreach (ll_tools_lti_get_platforms() as $platform) {
        if ($platform['enabled'] && $platform['issuer'] === $issuer && ($client === null || $client === $platform['client_id'])) { $matches[] = $platform; }
    }
    if (count($matches) !== 1) { return ll_tools_lti_error('lti_unknown_registration', 403); }
    if (!ll_tools_lti_login_admit()) { return ll_tools_lti_error('lti_login_rate_limited', 429); }
    $platform = $matches[0];
    if (ll_tools_lti_url_origin(ll_tools_lti_tool_urls()['launch']) === '') { return ll_tools_lti_error('lti_tool_https_required', 503); }
    $browser_cookie = $_COOKIE['__Host-ll-tools-lti'] ?? '';
    if (!is_string($browser_cookie) || preg_match('/^[a-f0-9]{64}$/D', $browser_cookie) !== 1) {
        try { $browser_cookie = bin2hex(random_bytes(32)); } catch (Throwable $e) { return ll_tools_lti_error('lti_random_unavailable', 503); }
        if (headers_sent() || !setcookie('__Host-ll-tools-lti', $browser_cookie, ['expires' => time() + 3600, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'None'])) {
            return ll_tools_lti_error('lti_browser_cookie_required', 403);
        }
        $_COOKIE['__Host-ll-tools-lti'] = $browser_cookie;
    }
    try { $nonce = bin2hex(random_bytes(32)); } catch (Throwable $e) { return ll_tools_lti_error('lti_random_unavailable', 503); }
    $state = ll_tools_lti_ticket_put('state', ['platform_id' => $platform['id'], 'nonce' => $nonce, 'target_uri' => $target, '_browser_binding' => ll_tools_lti_browser_hash($browser_cookie)], 300);
    if (is_wp_error($state)) { return $state; }
    $params = ['scope' => 'openid', 'response_type' => 'id_token', 'response_mode' => 'form_post', 'prompt' => 'none',
        'client_id' => $platform['client_id'], 'redirect_uri' => ll_tools_lti_tool_urls()['launch'], 'login_hint' => $hint, 'state' => $state, 'nonce' => $nonce];
    $message_hint = $request->get_param('lti_message_hint');
    if ($message_hint !== null) {
        if (!ll_tools_lti_opaque($message_hint, 2048)) { ll_tools_lti_ticket_consume('state', $state); return ll_tools_lti_error('lti_invalid_login'); }
        $params['lti_message_hint'] = $message_hint;
    }
    return ll_tools_lti_redirect_response(add_query_arg($params, $platform['authorization_url']));
}

function ll_tools_lti_jwks(array $platform) {
    $cache_key = 'll_tools_lti_jwks_' . hash('sha256', $platform['jwks_url']);
    $cached = get_transient($cache_key);
    if (is_array($cached)) { return $cached; }
    $response = ll_tools_lti_http($platform, $platform['jwks_url'], ['method' => 'GET', 'headers' => ['Accept' => 'application/json']]);
    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) { return ll_tools_lti_error('lti_keys_unavailable', 503); }
    $data = json_decode(wp_remote_retrieve_body($response), true, 8);
    if (!is_array($data) || !is_array($data['keys'] ?? null) || count($data['keys']) < 1 || count($data['keys']) > 10) { return ll_tools_lti_error('lti_invalid_keys', 403); }
    $keys = [];
    foreach ($data['keys'] as $key) {
        if (!is_array($key) || ($key['kty'] ?? '') !== 'RSA' || ($key['alg'] ?? 'RS256') !== 'RS256'
            || ($key['use'] ?? 'sig') !== 'sig' || !ll_tools_lti_opaque($key['kid'] ?? '', 128)
            || isset($keys[$key['kid']]) || !is_string($key['n'] ?? null) || !is_string($key['e'] ?? null)
            || isset($key['d']) || strlen($key['n']) < 342 || strlen($key['n']) > 1400 || strlen($key['e']) > 16) { return ll_tools_lti_error('lti_invalid_keys', 403); }
        $keys[$key['kid']] = $key;
    }
    $valid = ['keys' => array_values($keys)];
    set_transient($cache_key, $valid, 300);
    return $valid;
}

/** Verified JSON claims become a deliberately small, provider-neutral launch context. */
function ll_tools_lti_validate_claims(array $claims, array $platform, array $state) {
    $prefix = 'https://purl.imsglobal.org/spec/lti/claim/';
    $aud = $claims['aud'] ?? null;
    $audiences = is_string($aud) ? [$aud] : $aud;
    if (!is_array($audiences) || count($audiences) !== 1 || $audiences[0] !== $platform['client_id']
        || (isset($claims['azp']) && $claims['azp'] !== $platform['client_id'])
        || ($claims['iss'] ?? '') !== $platform['issuer']
        || !is_string($claims['nonce'] ?? null) || !hash_equals((string) $state['nonce'], $claims['nonce'])
        || ($claims[$prefix . 'deployment_id'] ?? '') !== $platform['deployment_id']
        || ($claims[$prefix . 'message_type'] ?? '') !== 'LtiResourceLinkRequest'
        || ($claims[$prefix . 'version'] ?? '') !== '1.3.0'
        || ($claims[$prefix . 'target_link_uri'] ?? '') !== $state['target_uri']
        || $state['target_uri'] !== ll_tools_lti_tool_urls()['resource']
        || !ll_tools_lti_opaque($claims['sub'] ?? '')
        || !is_int($claims['iat'] ?? null) || $claims['iat'] > time() + 30 || $claims['iat'] < time() - 300
        || !is_int($claims['exp'] ?? null) || $claims['exp'] <= time() || $claims['exp'] > time() + 600
        || (isset($claims['nbf']) && (!is_int($claims['nbf']) || $claims['nbf'] > time()))) { return ll_tools_lti_error('lti_invalid_launch_claims', 403); }
    $context = $claims[$prefix . 'context'] ?? [];
    $resource = $claims[$prefix . 'resource_link'] ?? [];
    $roles = $claims[$prefix . 'roles'] ?? null;
    if (!is_array($context) || !ll_tools_lti_opaque($context['id'] ?? '') || !is_array($resource) || !ll_tools_lti_opaque($resource['id'] ?? '')
        || !is_array($roles) || count($roles) > 20 || (!empty($roles) && array_keys($roles) !== range(0, count($roles) - 1))) { return ll_tools_lti_error('lti_missing_scope', 403); }
    foreach ($roles as $role) { if (!ll_tools_lti_opaque($role) || !preg_match('~^https?://~', $role)) { return ll_tools_lti_error('lti_invalid_roles', 403); } }
    $custom = $claims[$prefix . 'custom'] ?? [];
    if (!is_array($custom) || count($custom) > 20) { return ll_tools_lti_error('lti_invalid_custom', 403); }
    foreach ($custom as $key => $value) { if (!is_string($key) || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $key) || !ll_tools_lti_opaque($value)) { return ll_tools_lti_error('lti_invalid_custom', 403); } }
    $ags = $claims['https://purl.imsglobal.org/spec/lti-ags/claim/endpoint'] ?? [];
    if (!is_array($ags)) { return ll_tools_lti_error('lti_invalid_ags', 403); }
    if (!empty($ags)) {
        $scopes = $ags['scope'] ?? null;
        if (!is_array($scopes) || count($scopes) > 10) { return ll_tools_lti_error('lti_invalid_ags', 403); }
        foreach ($scopes as $scope) { if (!ll_tools_lti_opaque($scope, 512)) { return ll_tools_lti_error('lti_invalid_ags', 403); } }
        foreach (['lineitem', 'lineitems'] as $endpoint) {
            if (isset($ags[$endpoint]) && ll_tools_lti_url_origin($ags[$endpoint]) !== ll_tools_lti_url_origin($platform['issuer'])) { return ll_tools_lti_error('lti_invalid_ags', 403); }
        }
        // Moodle may advertise its collection on ungraded practice. It grants no score recipient.
        $ags = isset($ags['lineitem']) && in_array(LL_TOOLS_LTI_SCORE_SCOPE, $scopes, true)
            ? ['lineitem' => $ags['lineitem'], 'scope' => [LL_TOOLS_LTI_SCORE_SCOPE]] : [];
    }
    $presentation = $claims[$prefix . 'launch_presentation'] ?? [];
    $return_url = is_array($presentation) ? ($presentation['return_url'] ?? '') : '';
    if ($return_url !== '' && ll_tools_lti_url_origin($return_url) !== ll_tools_lti_url_origin($platform['issuer'])) { $return_url = ''; }
    return ['platform_id' => $platform['id'], 'issuer' => $platform['issuer'], 'client_id' => $platform['client_id'], 'deployment_id' => $platform['deployment_id'],
        'subject' => $claims['sub'], 'roles' => $roles, 'context_id' => $context['id'], 'resource_link_id' => $resource['id'],
        'target_uri' => $state['target_uri'], 'custom' => $custom, 'ags' => $ags, 'return_url' => $return_url];
}

function ll_tools_lti_rest_launch(WP_REST_Request $request) {
    $state_token = $request->get_param('state');
    $token = $request->get_param('id_token');
    if (!is_string($state_token) || !is_string($token) || strlen($token) > 32768 || substr_count($token, '.') !== 2) { return ll_tools_lti_error('lti_invalid_launch', 403); }
    $state = ll_tools_lti_ticket_consume('state', $state_token);
    if (is_wp_error($state)) { return $state; }
    if (!ll_tools_lti_browser_matches($state)) { return ll_tools_lti_error('lti_browser_binding_failed', 403); }
    $platform = ll_tools_lti_get_platform((string) ($state['platform_id'] ?? ''));
    if (!is_array($platform) || !$platform['enabled'] || !class_exists(JWT::class)) { return ll_tools_lti_error('lti_unknown_registration', 403); }
    $jwks = ll_tools_lti_jwks($platform);
    if (is_wp_error($jwks)) { return $jwks; }
    try {
        $keys = JWK::parseKeySet($jwks, 'RS256');
        $claims_object = JWT::decode($token, $keys);
        $claims = json_decode(wp_json_encode($claims_object), true, 16);
    } catch (Throwable $error) { return ll_tools_lti_error('lti_invalid_signature', 403); }
    if (!is_array($claims)) { return ll_tools_lti_error('lti_invalid_launch', 403); }
    $context = ll_tools_lti_validate_claims($claims, $platform, $state);
    if (is_wp_error($context)) { return $context; }
    $context['_browser_binding'] = $state['_browser_binding'];
    $ticket = ll_tools_lti_ticket_put('launch', $context);
    if (is_wp_error($ticket)) { return $ticket; }
    return ll_tools_lti_redirect_response(add_query_arg('ll_lti_launch', $ticket, home_url('/')), 303);
}

function ll_tools_lti_register_rest_routes(): void {
    register_rest_route('ll-tools/v1', '/lti/login', ['methods' => ['GET', 'POST'], 'callback' => 'll_tools_lti_rest_login', 'permission_callback' => '__return_true']);
    register_rest_route('ll-tools/v1', '/lti/launch', ['methods' => 'POST', 'callback' => 'll_tools_lti_rest_launch', 'permission_callback' => '__return_true']);
    register_rest_route('ll-tools/v1', '/lti/jwks', ['methods' => 'GET', 'callback' => 'll_tools_lti_rest_jwks', 'permission_callback' => '__return_true']);
}
add_action('rest_api_init', 'll_tools_lti_register_rest_routes');

function ll_tools_lti_hash(string $value): string {
    $master = ll_tools_lms_credential_master_key();
    return is_wp_error($master) ? '' : hash_hmac('sha256', $value, ll_tools_lms_credential_context_key($master, 'lti-durable-mapping-hash'));
}
function ll_tools_lti_connection_hash(array $context): string { return ll_tools_lti_hash('lti-issuer:' . (string) ($context['issuer'] ?? '')); }
function ll_tools_lti_subject_hash(array $context): string { return ll_tools_lti_hash('lti-subject:' . (string) ($context['subject'] ?? '')); }

function ll_tools_lti_context_platform(array $context): ?array {
    $platform = ll_tools_lti_get_platform((string) ($context['platform_id'] ?? ''));
    return is_array($platform) && $platform['enabled'] && $platform['issuer'] === ($context['issuer'] ?? '')
        && $platform['client_id'] === ($context['client_id'] ?? '') && $platform['deployment_id'] === ($context['deployment_id'] ?? '')
        && ll_tools_lti_opaque($context['subject'] ?? '') && ll_tools_lti_opaque($context['context_id'] ?? '')
        && ll_tools_lti_opaque($context['resource_link_id'] ?? '') ? $platform : null;
}

function ll_tools_lti_identity_record_name(array $mapping): string {
    return 'll_tools_lti_u_' . (int) ($mapping['learner_user_id'] ?? 0) . '_i_' . ($mapping['connection_key_hash'] ?? '') . '_' . ($mapping['subject_key_hash'] ?? '');
}

/** Shared advisory ownership linearizes account changes and bounded remote grade sends. */
function ll_tools_lti_with_learner_advisory(int $user_id, callable $callback) {
    $held = $GLOBALS['ll_tools_lti_held_learner_lock'] ?? null;
    if (is_array($held)) {
        if ((int) ($held['user_id'] ?? 0) !== $user_id || !ll_tools_privacy_user_session_lock_is_owned($user_id, (string) ($held['token'] ?? ''))) {
            return ll_tools_lti_error('lti_learner_lock_unavailable', 503);
        }
        return $callback();
    }
    $lock = ll_tools_offline_app_acquire_user_session_lock($user_id);
    if (!is_string($lock) || $lock === '' || !ll_tools_privacy_user_session_lock_is_owned($user_id, $lock)) {
        if (is_string($lock) && $lock !== '') { ll_tools_offline_app_release_user_session_lock($lock); }
        return ll_tools_lti_error('lti_learner_lock_unavailable', 503);
    }
    $GLOBALS['ll_tools_lti_held_learner_lock'] = ['user_id' => $user_id, 'token' => $lock];
    try { return $callback(); }
    finally {
        unset($GLOBALS['ll_tools_lti_held_learner_lock']);
        ll_tools_offline_app_release_user_session_lock($lock);
    }
}

/** The same advisory -> learner-row -> privacy-fence ordering as progress/erasure. */
function ll_tools_lti_with_learner_lock(int $user_id, callable $callback) {
    return ll_tools_lti_with_learner_advisory($user_id, static function () use ($user_id, $callback) {
    global $wpdb;
    $wpdb->last_error = '';
    $options_status = $wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS LIKE %s', $wpdb->esc_like($wpdb->options)), ARRAY_A);
    if (!is_array($options_status) || $wpdb->last_error !== '' || strcasecmp((string) ($options_status['Engine'] ?? ''), 'InnoDB') !== 0) {
        return ll_tools_lti_error('lti_transactional_storage_required', 503);
    }
    if (!ll_tools_grade_delivery_user_write_allowed($user_id)) { return ll_tools_lti_error('lti_learner_write_fenced', 403); }
    $transaction = null;
    $depth = (int) ($GLOBALS['ll_tools_lti_transaction_depth'] ?? 0);
    $GLOBALS['ll_tools_lti_transaction_depth'] = $depth + 1;
    if ($depth === 0) { $GLOBALS['ll_tools_lti_schedule_worker'] = false; }
    try {
        $transaction = ll_tools_lms_assignment_begin_transaction();
        if (!is_array($transaction) || !ll_tools_lms_assignment_lock_user($user_id) || !ll_tools_grade_delivery_user_write_allowed_under_lock($user_id)) {
            if (is_array($transaction)) { ll_tools_lms_assignment_rollback_transaction($transaction); }
            return ll_tools_lti_error('lti_learner_write_fenced', 403);
        }
        $result = $callback();
        if (is_wp_error($result)) { ll_tools_lms_assignment_rollback_transaction($transaction); return $result; }
        if (!ll_tools_lms_assignment_commit_transaction($transaction)) { ll_tools_lms_assignment_rollback_transaction($transaction); return ll_tools_lti_error('lti_mapping_commit_failed', 503); }
        if ($depth === 0 && !empty($GLOBALS['ll_tools_lti_schedule_worker'])) { ll_tools_grade_delivery_schedule_worker(); }
        return $result;
    } catch (Throwable $e) {
        if (is_array($transaction)) { ll_tools_lms_assignment_rollback_transaction($transaction); }
        return ll_tools_lti_error('lti_mapping_write_failed', 503);
    } finally {
        $GLOBALS['ll_tools_lti_transaction_depth'] = $depth;
        // A rolled-back option delete/insert must not leave cached presence or absence behind.
        foreach (array_keys((array) ($GLOBALS['ll_tools_lti_changed_options'] ?? [])) as $name) { wp_cache_delete($name, 'options'); }
        wp_cache_delete('notoptions', 'options');
        wp_cache_delete('alloptions', 'options');
        if ($depth === 0) { unset($GLOBALS['ll_tools_lti_changed_options'], $GLOBALS['ll_tools_lti_schedule_worker']); }
    }
    });
}

function ll_tools_lti_validate_identity(array $mapping) {
    $name = ll_tools_lti_identity_record_name($mapping);
    $raw = ll_tools_lti_record_get($name) ?? ($GLOBALS['ll_tools_lti_mapping_stage'][$name] ?? null);
    if (!is_array($raw) || ($raw['learner_user_id'] ?? 0) !== ($mapping['learner_user_id'] ?? -1)
        || ll_tools_lti_connection_hash($raw) !== ($mapping['connection_key_hash'] ?? '')
        || ll_tools_lti_subject_hash($raw) !== ($mapping['subject_key_hash'] ?? '')) { return ll_tools_lti_error('lti_identity_unverified', 403); }
    foreach (ll_tools_lti_get_platforms() as $platform) { if ($platform['enabled'] && $platform['issuer'] === $raw['issuer']) { return true; } }
    return ll_tools_lti_error('lti_identity_registration_disabled', 403);
}

/** Internal caller must supply the already verified, one-use launch context. */
function ll_tools_lti_create_identity(int $learner_id, array $context) {
    if (!ll_tools_lti_context_platform($context)) { return ll_tools_lti_error('lti_invalid_identity_scope', 403); }
    return ll_tools_lti_with_learner_lock($learner_id, static function () use ($learner_id, $context) {
    if ((int) ($context['_account_user_id'] ?? 0) > 0 && (!function_exists('ll_tools_lti_account_confirmation_is_valid')
        || !ll_tools_lti_account_confirmation_is_valid($context, $learner_id))) { return ll_tools_lti_error('lti_account_confirmation_expired', 403); }
    $mapping = ['adapter' => 'lti', 'connection_key_hash' => ll_tools_lti_connection_hash($context), 'subject_key_hash' => ll_tools_lti_subject_hash($context), 'learner_user_id' => $learner_id];
    $name = ll_tools_lti_identity_record_name($mapping);
    $raw = ['issuer' => $context['issuer'], 'subject' => $context['subject'], 'learner_user_id' => $learner_id];
    $GLOBALS['ll_tools_lti_mapping_stage'][$name] = $raw;
    try { $id = ll_tools_grade_delivery_create_external_identity($mapping); }
    finally { unset($GLOBALS['ll_tools_lti_mapping_stage'][$name]); }
    if (is_wp_error($id)) { return $id; }
    $stored = ll_tools_lti_record_put($name, $raw);
    return is_wp_error($stored) ? $stored : $id;
    });
}

function ll_tools_lti_destination_key(array $context): string {
    return ll_tools_lti_hash(wp_json_encode([$context['platform_id'], $context['deployment_id'], $context['context_id'], $context['resource_link_id'], $context['ags']['lineitem'] ?? '']));
}

function ll_tools_lti_validate_destination(array $mapping) {
    $name = 'll_tools_lti_d_' . ($mapping['destination_key_hash'] ?? '');
    $raw = ll_tools_lti_record_get($name) ?? ($GLOBALS['ll_tools_lti_mapping_stage'][$name] ?? null);
    if (!is_array($raw) || (int) ($raw['assignment_id'] ?? 0) !== (int) ($mapping['assignment_id'] ?? 0)
        || (int) ($raw['revision_id'] ?? 0) !== (int) ($mapping['revision_id'] ?? 0)
        || ll_tools_lti_connection_hash($raw['context'] ?? []) !== ($mapping['connection_key_hash'] ?? '')
        || ll_tools_lti_destination_key($raw['context'] ?? []) !== ($mapping['destination_key_hash'] ?? '')) { return ll_tools_lti_error('lti_destination_unverified', 403); }
    $context = $raw['context'];
    $platform = ll_tools_lti_get_platform((string) ($context['platform_id'] ?? ''));
    if (!is_array($platform) || !$platform['enabled'] || $platform['issuer'] !== ($context['issuer'] ?? '')
        || $platform['client_id'] !== ($context['client_id'] ?? '') || $platform['deployment_id'] !== ($context['deployment_id'] ?? '')
        || ll_tools_lti_url_origin($context['ags']['lineitem'] ?? '') !== ll_tools_lti_url_origin($platform['issuer'])) { return ll_tools_lti_error('lti_destination_registration_disabled', 403); }
    return true;
}

function ll_tools_lti_validate_recipient(array $mapping) {
    $name = 'll_tools_lti_u_' . (int) ($mapping['learner_user_id'] ?? 0) . '_r_' . ($mapping['recipient_key_hash'] ?? '');
    $raw = ll_tools_lti_record_get($name) ?? ($GLOBALS['ll_tools_lti_mapping_stage'][$name] ?? null);
    if (!is_array($raw) || (int) ($raw['destination_id'] ?? 0) !== (int) ($mapping['destination_id'] ?? 0)
        || (int) ($raw['external_identity_id'] ?? 0) !== (int) ($mapping['external_identity_id'] ?? 0)
        || (int) ($raw['learner_user_id'] ?? 0) !== (int) ($mapping['learner_user_id'] ?? 0)
        || ll_tools_lti_subject_hash($raw) !== ($mapping['subject_key_hash'] ?? '')
        || ll_tools_lti_connection_hash($raw) !== ($mapping['connection_key_hash'] ?? '')
        || ($raw['destination_key_hash'] ?? '') !== ($mapping['destination_key_hash'] ?? '')) { return ll_tools_lti_error('lti_recipient_unverified', 403); }
    return true;
}

/** Creates only a score recipient for the exact signed line item; never creates gradebook columns. */
function ll_tools_lti_connect_grade(array $context, int $assignment_id, int $revision_id, int $identity_id, int $learner_id) {
    return ll_tools_lti_with_learner_lock($learner_id, static function () use ($context, $assignment_id, $revision_id, $identity_id, $learner_id) {
    global $wpdb;
    $platform = ll_tools_lti_context_platform($context);
    if (!$platform || !in_array(LL_TOOLS_LTI_SCORE_SCOPE, $context['ags']['scope'] ?? [], true)
        || ll_tools_lti_url_origin($context['ags']['lineitem'] ?? '') !== ll_tools_lti_url_origin($platform['issuer'])) { return ll_tools_lti_error('lti_grades_not_authorized', 403); }
    $tables = ll_tools_grade_delivery_table_names();
    $identity = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$tables['identities']} WHERE id = %d AND adapter = 'lti' AND status = 'active'", $identity_id), ARRAY_A);
    if (!is_array($identity) || (int) $identity['learner_user_id'] !== $learner_id || $identity['connection_key_hash'] !== ll_tools_lti_connection_hash($context)
        || $identity['subject_key_hash'] !== ll_tools_lti_subject_hash($context)) { return ll_tools_lti_error('lti_grade_identity_mismatch', 403); }
    $destination_key = ll_tools_lti_destination_key($context);
    $dest_name = 'll_tools_lti_d_' . $destination_key;
    $destination_context = array_intersect_key($context, array_flip(['platform_id', 'issuer', 'client_id', 'deployment_id', 'context_id', 'resource_link_id', 'ags']));
    $dest_raw = ['assignment_id' => $assignment_id, 'revision_id' => $revision_id, 'context' => $destination_context];
    $GLOBALS['ll_tools_lti_mapping_stage'][$dest_name] = $dest_raw;
    try { $destination_id = ll_tools_grade_delivery_create_destination(['adapter' => 'lti', 'connection_key_hash' => ll_tools_lti_connection_hash($context), 'destination_key_hash' => $destination_key, 'assignment_id' => $assignment_id, 'revision_id' => $revision_id]); }
    finally { unset($GLOBALS['ll_tools_lti_mapping_stage'][$dest_name]); }
    if (is_wp_error($destination_id)) { return $destination_id; }
    $saved = ll_tools_lti_record_put($dest_name, $dest_raw);
    if (is_wp_error($saved)) { return $saved; }
    $recipient_key = ll_tools_lti_hash($destination_key . ':' . $context['subject']);
    $recipient_name = 'll_tools_lti_u_' . $learner_id . '_r_' . $recipient_key;
    $recipient_raw = ['issuer' => $context['issuer'], 'subject' => $context['subject'], 'learner_user_id' => $learner_id,
        'external_identity_id' => $identity_id, 'destination_id' => $destination_id, 'destination_key_hash' => $destination_key];
    $GLOBALS['ll_tools_lti_mapping_stage'][$recipient_name] = $recipient_raw;
    try { $recipient_id = ll_tools_grade_delivery_create_recipient(['destination_id' => $destination_id, 'external_identity_id' => $identity_id, 'learner_user_id' => $learner_id, 'recipient_key_hash' => $recipient_key]); }
    finally { unset($GLOBALS['ll_tools_lti_mapping_stage'][$recipient_name]); }
    if (is_wp_error($recipient_id)) { return $recipient_id; }
    $saved = ll_tools_lti_record_put($recipient_name, $recipient_raw);
    if (is_wp_error($saved)) { return $saved; }
    $grade_tables = ll_tools_lms_assignment_table_names();
    $wpdb->last_error = '';
    $selected = $wpdb->get_var($wpdb->prepare("SELECT grade_revision FROM {$grade_tables['grades']} WHERE assignment_id = %d AND revision_id = %d AND user_id = %d LIMIT 1", $assignment_id, $revision_id, $learner_id));
    if ($wpdb->last_error !== '') { return ll_tools_lti_error('lti_selected_grade_read_failed', 503); }
    if ($selected !== null) {
        $enqueued = ll_tools_grade_delivery_enqueue_current_grade($assignment_id, $revision_id, $learner_id, (int) $selected);
        if (is_wp_error($enqueued)) { return $enqueued; }
        if (!empty($enqueued['enqueued'])) { $GLOBALS['ll_tools_lti_schedule_worker'] = true; }
    }
    return ['destination_id' => $destination_id, 'recipient_id' => $recipient_id];
    });
}

/** Token is request-local and never persisted to options or delivery diagnostics. */
function ll_tools_lti_access_token(array $platform) {
    $material = ll_tools_lti_signing_material();
    if (is_wp_error($material)) { return $material; }
    try {
        $assertion = JWT::encode(['iss' => $platform['client_id'], 'sub' => $platform['client_id'], 'aud' => $platform['token_url'],
            'iat' => time(), 'exp' => time() + 60, 'jti' => bin2hex(random_bytes(32)), 'https://purl.imsglobal.org/spec/lti/claim/deployment_id' => $platform['deployment_id']], $material['pem'], 'RS256', $material['kid']);
    } catch (Throwable $e) { return ll_tools_lti_error('lti_signing_failed', 503); }
    $response = ll_tools_lti_http($platform, $platform['token_url'], ['method' => 'POST', 'headers' => ['Content-Type' => 'application/x-www-form-urlencoded', 'Accept' => 'application/json'],
        'body' => ['grant_type' => 'client_credentials', 'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer', 'client_assertion' => $assertion, 'scope' => LL_TOOLS_LTI_SCORE_SCOPE]]);
    if (is_wp_error($response)) { return ll_tools_lti_error('lti_token_network_error', 503); }
    $status = wp_remote_retrieve_response_code($response);
    if ($status !== 200) { return new WP_Error('lti_token_http_error', '', ['http_status' => $status]); }
    $data = json_decode(wp_remote_retrieve_body($response), true, 8);
    if (!is_array($data) || !ll_tools_lti_opaque($data['access_token'] ?? '', 8192) || strtolower((string) ($data['token_type'] ?? '')) !== 'bearer'
        || (isset($data['scope']) && (!is_string($data['scope']) || !in_array(LL_TOOLS_LTI_SCORE_SCOPE, explode(' ', $data['scope']), true)))) { return ll_tools_lti_error('lti_invalid_token', 503); }
    return $data['access_token'];
}

function ll_tools_lti_scores_url(string $lineitem): string {
    $parts = wp_parse_url($lineitem);
    return rtrim(substr($lineitem, 0, strcspn($lineitem, '?')), '/') . '/scores' . (isset($parts['query']) ? '?' . $parts['query'] : '');
}

/** Keep timestamps stable across network retries and increasing across corrections. */
function ll_tools_lti_grade_timestamp(int $user_id, int $recipient_id, int $revision) {
    return ll_tools_lti_with_learner_lock($user_id, static function () use ($user_id, $recipient_id, $revision) {
        $name = 'll_tools_lti_u_' . $user_id . '_t_' . $recipient_id;
        $before = ll_tools_lti_record_get($name);
        if (is_array($before) && (int) ($before['grade_revision'] ?? 0) > $revision) { return ll_tools_lti_error('lti_stale_grade', 409); }
        if (is_array($before) && (int) ($before['grade_revision'] ?? 0) === $revision && empty($before['republish'])) { return (int) $before['timestamp']; }
        $now = max(1, (int) apply_filters('ll_tools_lti_transport_now', time()));
        $timestamp = max($now, is_array($before) ? (int) $before['timestamp'] + 1 : 0);
        if ($timestamp > $now) { return ll_tools_lti_error('lti_timestamp_wait', 503); }
        $after = ['grade_revision' => $revision, 'timestamp' => $timestamp, 'republish' => false];
        $saved = is_array($before) ? ll_tools_lti_record_replace($name, $before, $after) : ll_tools_lti_record_put($name, $after);
        return is_wp_error($saved) ? $saved : $timestamp;
    });
}

/** An explicit conflict triggers a fresh publication of the still-current selected grade. */
function ll_tools_lti_mark_grade_conflict(int $user_id, int $recipient_id, int $revision) {
    return ll_tools_lti_with_learner_lock($user_id, static function () use ($user_id, $recipient_id, $revision) {
        $name = 'll_tools_lti_u_' . $user_id . '_t_' . $recipient_id;
        $before = ll_tools_lti_record_get($name);
        if (!is_array($before) || (int) ($before['grade_revision'] ?? 0) !== $revision) { return ll_tools_lti_error('lti_stale_grade', 409); }
        if (!empty($before['republish'])) { return true; }
        $after = $before;
        $after['republish'] = true;
        return ll_tools_lti_record_replace($name, $before, $after);
    });
}

function ll_tools_lti_send_grade(array $delivery): array {
    global $wpdb;
    $tables = ll_tools_grade_delivery_table_names();
    $wpdb->last_error = '';
    $user_id = $wpdb->get_var($wpdb->prepare("SELECT learner_user_id FROM {$tables['deliveries']} WHERE id = %d AND adapter = 'lti' AND grade_revision = %d", (int) ($delivery['delivery_id'] ?? 0), (int) ($delivery['grade_revision'] ?? 0)));
    if ($wpdb->last_error !== '' || (int) $user_id <= 0) { return ['type' => 'permanent_error', 'error_code' => 'lti_delivery_missing', 'diagnostic' => 'The assignment grade snapshot is unavailable.']; }
    // Keep advisory ownership across the final checks and both bounded HTTPS requests.
    // Timestamp writes use short nested SQL transactions; no SQL row lock spans the HTTP send.
    $result = ll_tools_lti_with_learner_advisory((int) $user_id, static function () use ($delivery) { return ll_tools_lti_send_grade_locked($delivery); });
    return is_wp_error($result) ? ['type' => 'network_error', 'error_code' => 'lti_learner_lock_unavailable', 'diagnostic' => 'The learner connection is busy.', 'retry_after' => 1] : $result;
}

function ll_tools_lti_send_grade_locked(array $delivery): array {
    global $wpdb;
    $tables = ll_tools_grade_delivery_table_names();
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$tables['deliveries']} WHERE id = %d AND adapter = 'lti' AND grade_revision = %d", (int) ($delivery['delivery_id'] ?? 0), (int) ($delivery['grade_revision'] ?? 0)), ARRAY_A);
    if (!is_array($row)) { return ['type' => 'permanent_error', 'error_code' => 'lti_delivery_missing', 'diagnostic' => 'The assignment grade snapshot is unavailable.']; }
    $held = $GLOBALS['ll_tools_lti_held_learner_lock'] ?? [];
    if ((int) ($held['user_id'] ?? 0) !== (int) $row['learner_user_id'] || !ll_tools_privacy_user_session_lock_is_owned((int) $row['learner_user_id'], (string) ($held['token'] ?? ''))) {
        return ['type' => 'network_error', 'error_code' => 'lti_learner_lock_unavailable', 'diagnostic' => 'The learner connection is busy.', 'retry_after' => 1];
    }
    foreach (['destination_id', 'recipient_id', 'assignment_id', 'revision_id', 'grade_revision', 'score_given', 'score_maximum', 'points_given', 'points_maximum'] as $field) {
        if ((string) ($row[$field] ?? '') !== (string) ($delivery[$field] ?? '')) { return ['type' => 'permanent_error', 'error_code' => 'lti_snapshot_mismatch', 'diagnostic' => 'The assignment grade snapshot does not match.']; }
    }
    $current = ll_tools_grade_delivery_current_grade_status($row);
    $exact = ll_tools_grade_delivery_load_exact_mapping($row);
    if (is_wp_error($current) || !empty($current['stale']) || is_wp_error($exact) || is_wp_error(ll_tools_grade_delivery_revalidate_exact_mapping((array) $exact))) {
        return ['type' => 'permanent_error', 'error_code' => 'lti_stale_mapping', 'diagnostic' => 'The current assignment grade mapping is unavailable.'];
    }
    $recipient = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$tables['recipients']} WHERE id = %d AND status = 'active'", (int) ($delivery['recipient_id'] ?? 0)), ARRAY_A);
    $destination = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$tables['destinations']} WHERE id = %d AND adapter = 'lti' AND status = 'active'", (int) ($delivery['destination_id'] ?? 0)), ARRAY_A);
    if (!is_array($recipient) || !is_array($destination) || (int) $recipient['destination_id'] !== (int) $destination['id']
        || (int) $recipient['external_identity_id'] !== (int) ($delivery['external_identity_id'] ?? 0)
        || (int) $destination['assignment_id'] !== (int) ($delivery['assignment_id'] ?? 0) || (int) $destination['revision_id'] !== (int) ($delivery['revision_id'] ?? 0)) {
        return ['type' => 'permanent_error', 'error_code' => 'lti_exact_mapping_missing', 'diagnostic' => 'The exact learning platform mapping is unavailable.'];
    }
    $recipient_raw = ll_tools_lti_record_get('ll_tools_lti_u_' . (int) $recipient['learner_user_id'] . '_r_' . $recipient['recipient_key_hash']);
    $dest_raw = ll_tools_lti_record_get('ll_tools_lti_d_' . $destination['destination_key_hash']);
    if (!is_array($recipient_raw) || !is_array($dest_raw) || !ll_tools_grade_delivery_user_write_allowed((int) $recipient['learner_user_id'])) {
        return ['type' => 'permanent_error', 'error_code' => 'lti_private_mapping_missing', 'diagnostic' => 'The private learning platform mapping is unavailable.'];
    }
    $context = $dest_raw['context'];
    $platform = ll_tools_lti_get_platform($context['platform_id']);
    if (!is_array($platform) || is_wp_error(ll_tools_lti_validate_destination($destination))) {
        return ['type' => 'permanent_error', 'error_code' => 'lti_registration_disabled', 'diagnostic' => 'The learning platform registration is disabled.'];
    }
    $token = ll_tools_lti_access_token($platform);
    if (is_wp_error($token)) {
        $data = (array) $token->get_error_data();
        return isset($data['http_status']) ? ['type' => 'http', 'http_status' => (int) $data['http_status'], 'error_code' => 'lti_token_rejected', 'diagnostic' => 'The learning platform rejected grade authentication.']
            : ['type' => 'network_error', 'error_code' => 'lti_token_unavailable', 'diagnostic' => 'Learning platform grade authentication is unavailable.'];
    }
    $score = (float) ($delivery['points_given'] ?? 0);
    $maximum = (float) ($delivery['points_maximum'] ?? 0);
    if (!is_finite($score) || !is_finite($maximum) || $score < 0 || $maximum <= 0 || $score > $maximum) {
        return ['type' => 'permanent_error', 'error_code' => 'lti_invalid_score', 'diagnostic' => 'The assignment score is invalid.'];
    }
    $timestamp = ll_tools_lti_grade_timestamp((int) $recipient['learner_user_id'], (int) $recipient['id'], (int) $delivery['grade_revision']);
    if (is_wp_error($timestamp)) { return ['type' => 'network_error', 'error_code' => 'lti_timestamp_unavailable', 'diagnostic' => 'The assignment grade publication timestamp is unavailable.', 'retry_after' => 1]; }
    $body = ['userId' => $recipient_raw['subject'], 'scoreGiven' => $score, 'scoreMaximum' => $maximum,
        'activityProgress' => 'Completed', 'gradingProgress' => 'FullyGraded', 'timestamp' => gmdate('Y-m-d\TH:i:s', $timestamp) . '.000000Z'];
    $scores_url = ll_tools_lti_scores_url($context['ags']['lineitem']);
    $still_current = ll_tools_grade_delivery_current_grade_status($row);
    if (!ll_tools_grade_delivery_user_write_allowed((int) $recipient['learner_user_id'])
        || is_wp_error(ll_tools_grade_delivery_load_exact_mapping($row)) || is_wp_error($still_current) || !empty($still_current['stale'])) {
        return ['type' => 'permanent_error', 'error_code' => 'lti_grade_scope_changed', 'diagnostic' => 'The assignment grade scope has changed.'];
    }
    $response = ll_tools_lti_http($platform, $scores_url, ['method' => 'POST', 'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/vnd.ims.lis.v1.score+json'], 'body' => wp_json_encode($body)]);
    unset($token);
    if (is_wp_error($response)) { return ['type' => 'network_error', 'error_code' => 'lti_score_network_error', 'diagnostic' => 'The learning platform grade request could not be completed.']; }
    $retry_after = wp_remote_retrieve_header($response, 'retry-after');
    $result = ['type' => 'http', 'http_status' => wp_remote_retrieve_response_code($response), 'diagnostic' => ''];
    if ($result['http_status'] === 409) { ll_tools_lti_mark_grade_conflict((int) $recipient['learner_user_id'], (int) $recipient['id'], (int) $delivery['grade_revision']); }
    if (is_string($retry_after) && strlen($retry_after) <= 128 && (preg_match('/^\d{1,10}$/D', $retry_after) || strtotime($retry_after) !== false)) { $result['retry_after'] = $retry_after; }
    return $result;
}

function ll_tools_lti_register_grade_adapter(): void {
    if (!ll_tools_grade_delivery_get_adapter('lti')) {
        ll_tools_grade_delivery_register_adapter('lti', ['validate_identity' => 'll_tools_lti_validate_identity', 'validate_destination' => 'll_tools_lti_validate_destination', 'validate_recipient' => 'll_tools_lti_validate_recipient', 'send' => 'll_tools_lti_send_grade']);
    }
}
add_action('init', 'll_tools_lti_register_grade_adapter', 14);

function ll_tools_lti_export_user_data(int $user_id, int $page = 1) {
    global $wpdb;
    if ($user_id <= 0) { return ['data' => [], 'done' => true]; }
    $prefix = $wpdb->esc_like('ll_tools_lti_u_' . $user_id . '_') . '%';
    $wpdb->last_error = '';
    $rows = $wpdb->get_results($wpdb->prepare("SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id ASC LIMIT 100 OFFSET %d", $prefix, (max(1, min(100000, $page)) - 1) * 100), ARRAY_A);
    if (!is_array($rows) || $wpdb->last_error !== '') { return ll_tools_lti_error('lti_export_read_failed', 503); }
    $data = [];
    foreach ((array) $rows as $row) {
        $record = ll_tools_lti_decrypt_record(maybe_unserialize($row['option_value']), $row['option_name']);
        if (!is_array($record)) { return ll_tools_lti_error('lti_export_decryption_failed', 503); }
        if (!isset($record['issuer'], $record['subject'])) { continue; }
        $data[] = ['group_id' => 'll-tools-learning-platform', 'group_label' => __('Learning platform connections', 'll-tools-text-domain'),
            'item_id' => 'll-tools-lti-' . hash('sha256', $row['option_name']), 'data' => [
                ['name' => __('Platform', 'll-tools-text-domain'), 'value' => esc_url_raw((string) ($record['issuer'] ?? ''))],
                ['name' => __('Learner identifier', 'll-tools-text-domain'), 'value' => (string) ($record['subject'] ?? '')],
            ]];
    }
    return ['data' => $data, 'done' => count((array) $rows) < 100];
}

/** Called by the existing privacy lifecycle while its learner fence/lock is held. */
function ll_tools_lti_erase_user_data(int $user_id) {
    global $wpdb;
    if ($user_id <= 0) { return ['items_removed' => false, 'done' => true]; }
    $prefix = $wpdb->esc_like('ll_tools_lti_u_' . $user_id . '_') . '%';
    $wpdb->last_error = '';
    $names = $wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id ASC LIMIT 100", $prefix));
    if (!is_array($names) || $wpdb->last_error !== '') { return ll_tools_lti_error('lti_erasure_read_failed', 503); }
    foreach ($names as $name) {
        if (!delete_option($name)) { return ll_tools_lti_error('lti_erasure_write_failed', 503); }
    }
    $wpdb->last_error = '';
    $remaining = $wpdb->get_var($wpdb->prepare("SELECT option_id FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 1", $prefix));
    if ($wpdb->last_error !== '') { return ll_tools_lti_error('lti_erasure_read_failed', 503); }
    return ['items_removed' => !empty($names), 'done' => $remaining === null];
}
