<?php
if (!defined('WPINC')) { die; }
require_once __DIR__ . '/mutation-job-state.php';

const LL_TOOLS_PUBLIC_STATIC_CACHE_GC_HOOK = 'll_tools_public_static_cache_collect';
const LL_TOOLS_PUBLIC_STATIC_CACHE_GC_STATE = 'll_tools_public_static_cache_gc_state';
const LL_TOOLS_PUBLIC_STATIC_CACHE_GC_ENTRY_PREFIX = 'll_tools_public_static_cache_file_';

/** Targets are best-effort: current usable fallback is more valuable than a quota. */
function ll_tools_public_static_cache_gc_limits(): array {
    $limits = (array) apply_filters('ll_tools_public_static_cache_gc_limits', [
        'bytes' => 256 * 1024 * 1024, 'entries' => 10000,
        'obsolete_age' => 7 * DAY_IN_SECONDS, 'temporary_age' => HOUR_IN_SECONDS,
        'batch' => 100, 'visits' => 1024, 'seconds' => 1.0, 'signature_bytes' => 32 * 1024 * 1024,
    ]);
    return [
        'bytes' => max(1, (int) ($limits['bytes'] ?? 0)),
        'entries' => max(1, (int) ($limits['entries'] ?? 0)),
        'obsolete_age' => max(600, (int) ($limits['obsolete_age'] ?? 0)),
        'temporary_age' => max(600, (int) ($limits['temporary_age'] ?? 0)),
        'batch' => max(1, min(200, (int) ($limits['batch'] ?? 100))),
        'visits' => max(1, min(4096, (int) ($limits['visits'] ?? 1024))),
        'seconds' => max(0.01, min(3.0, (float) ($limits['seconds'] ?? 1))),
        'signature_bytes' => max(16 * 1024 * 1024, min(64 * 1024 * 1024, (int) ($limits['signature_bytes'] ?? 0))),
    ];
}

function ll_tools_public_static_cache_gc_schedule(int $delay = 10): void {
    if (!wp_next_scheduled(LL_TOOLS_PUBLIC_STATIC_CACHE_GC_HOOK)) {
        wp_schedule_single_event(time() + max(1, $delay), LL_TOOLS_PUBLIC_STATIC_CACHE_GC_HOOK);
    }
}

/** Public requests only coalesce maintenance; they never enumerate a directory. */
add_action('init', 'll_tools_public_static_cache_gc_schedule', 30, 0);

function ll_tools_public_static_cache_gc_entry_option(string $key): string {
    return LL_TOOLS_PUBLIC_STATIC_CACHE_GC_ENTRY_PREFIX . $key;
}

/** Never follow a shard junction/symlink outside the configured cache root. */
function ll_tools_public_static_cache_gc_path_is_safe(string $file): bool {
    $root = realpath(ll_tools_public_static_cache_dir());
    $parent = realpath(dirname($file));
    if (!is_string($root) || is_link($file)) { return false; }
    $root = rtrim(wp_normalize_path($root), '/');
    if (!is_string($parent)) {
        $ancestor = realpath(dirname(dirname($file)));
        return !file_exists(dirname($file)) && is_string($ancestor)
            && rtrim(wp_normalize_path($ancestor), '/') === $root . '/files'
            && preg_match('/^[a-f0-9]{2}$/D', basename(dirname($file))) === 1;
    }
    $parent = rtrim(wp_normalize_path($parent), '/');
    return $parent === $root || preg_match('#^' . preg_quote($root, '#') . '/files/[a-f0-9]{2}$#D', $parent) === 1;
}

/** Verify existing ancestors before mkdir can follow an external junction. */
function ll_tools_public_static_cache_gc_prepare_directory(string $file): bool {
    $dir = ll_tools_public_static_cache_dir();
    if ($dir === '' || !wp_mkdir_p($dir)) { return false; }
    $root = realpath($dir);
    if (!is_string($root)) { return false; }
    $root = rtrim(wp_normalize_path($root), '/');
    $parent = wp_normalize_path(dirname($file));
    if (realpath(dirname($file)) !== false && rtrim(wp_normalize_path((string) realpath(dirname($file))), '/') === $root) {
        return ll_tools_public_static_cache_gc_path_is_safe($file);
    }
    $shard = basename($parent);
    if (preg_match('/^[a-f0-9]{2}$/D', $shard) !== 1) { return false; }
    $layout = trailingslashit($dir) . 'files';
    $actual = realpath($layout);
    if ($actual !== false && rtrim(wp_normalize_path($actual), '/') !== $root . '/files') { return false; }
    if ($actual === false && (is_link($layout) || file_exists($layout))) { return false; }
    if (!wp_mkdir_p($layout)) { return false; }
    $actual = realpath($layout);
    if (!is_string($actual) || rtrim(wp_normalize_path($actual), '/') !== $root . '/files') { return false; }
    $actual_shard = realpath(dirname($file));
    if ($actual_shard !== false && rtrim(wp_normalize_path($actual_shard), '/') !== $root . '/files/' . $shard) { return false; }
    if ($actual_shard === false && (is_link(dirname($file)) || file_exists(dirname($file)))) { return false; }
    return wp_mkdir_p(dirname($file)) && ll_tools_public_static_cache_gc_path_is_safe($file);
}

function ll_tools_public_static_cache_legacy_file_path(string $key): string {
    $dir = ll_tools_public_static_cache_dir();
    return $dir !== '' ? trailingslashit($dir) . 'public-' . $key . '.html' : '';
}

/** A single-key compatibility read; adoption never requires a public scan. */
function ll_tools_public_static_cache_existing_file_path(string $key): string {
    $file = ll_tools_public_static_cache_file_path($key);
    $legacy = ll_tools_public_static_cache_legacy_file_path($key);
    return !is_file($file) && is_file($legacy) ? $legacy : $file;
}

/** Inventory only this successful publication; no directory totals on requests. */
function ll_tools_public_static_cache_gc_record(string $file, string $key, ?array $identity, ?string $published_version = null): bool {
    if (preg_match('/^(?:[a-f0-9]{32}|[a-f0-9]{64})$/D', $key) !== 1
        || !ll_tools_public_static_cache_gc_path_is_safe($file)) { return false; }
    clearstatcache(true, $file);
    $stat = @lstat($file);
    if (!is_array($stat) || ($stat['mode'] & 0170000) !== 0100000) { return false; }
    $entry = [
        'key' => $key, 'bytes' => (int) $stat['size'], 'mtime' => (int) $stat['mtime'],
        'identity' => is_array($identity) ? $identity : [],
        'plugin_version' => $published_version ?? (defined('LL_TOOLS_VERSION') ? (string) LL_TOOLS_VERSION : ''),
        'signature' => (int) $stat['size'] <= 16 * 1024 * 1024 ? ll_tools_public_static_cache_file_signature($file) : '',
    ];
    $saved = ll_tools_mutation_job_write_option(ll_tools_public_static_cache_gc_entry_option($key), $entry);
    ll_tools_public_static_cache_gc_schedule();
    return !is_wp_error($saved);
}

/** Journal before writing: an interrupted publisher cannot orphan its temp file. */
function ll_tools_public_static_cache_gc_record_temporary(string $file, string $key, ?array $identity, ?string $published_version = null): string {
    $name = basename($file);
    if (preg_match('/^(?:[a-f0-9]{32}|[a-f0-9]{64})$/D', $key) !== 1
        || preg_match('/^public-' . preg_quote($key, '/') . '\.(?:html|meta\.json)\.tmp-[a-zA-Z0-9]+$/D', $name) !== 1
        || !ll_tools_public_static_cache_gc_path_is_safe($file)) { return ''; }
    $option = LL_TOOLS_PUBLIC_STATIC_CACHE_GC_ENTRY_PREFIX . $key . '_tmp_' . substr(hash('sha256', $name), 0, 16);
    $entry = ['key' => $key, 'temporary' => $name, 'created_at' => time(), 'identity' => $identity ?? [],
        'plugin_version' => $published_version ?? (defined('LL_TOOLS_VERSION') ? (string) LL_TOOLS_VERSION : '')];
    $saved = ll_tools_mutation_job_write_option($option, $entry);
    ll_tools_public_static_cache_gc_schedule();
    return is_wp_error($saved) ? '' : $option;
}

function ll_tools_public_static_cache_gc_read_meta(string $file): ?array {
    $meta_file = ll_tools_public_static_cache_meta_file_path($file);
    if ($meta_file === '' || !ll_tools_public_static_cache_gc_path_is_safe($meta_file)) { return null; }
    $handle = @fopen($meta_file, 'rb');
    if (!$handle) { return null; }
    try { $raw = fread($handle, 16385); } finally { fclose($handle); }
    if (!is_string($raw) || strlen($raw) > 16384) { return null; }
    $meta = json_decode($raw, true);
    return is_array($meta) && preg_match('/^public-([a-f0-9]+)\.html$/D', basename($file), $matches) === 1
        && ($meta['key'] ?? '') === $matches[1] ? $meta : null;
}

/** Match the same generation gate as serving without loading or hydrating HTML. */
function ll_tools_public_static_cache_gc_has_current_header(string $file, array $identity): bool {
    if (!ll_tools_public_static_cache_gc_path_is_safe($file) || !is_file($file)) { return false; }
    $handle = @fopen($file, 'rb');
    if (!$handle) { return false; }
    try { $header = fgets($handle, 256); } finally { fclose($handle); }
    if (!is_string($header)
        || preg_match('/^<!-- ll-tools-public-static-generation:([a-zA-Z0-9-]+) -->/', $header, $matches) !== 1) { return false; }
    $current = ll_tools_public_static_cache_generation($identity);
    return $current !== '' && hash_equals($current, $matches[1]);
}

function ll_tools_public_static_cache_gc_signature_cost(string $file): int {
    $size = @filesize($file);
    return $size !== false && $size <= 16 * 1024 * 1024 ? (int) $size : 0;
}

function ll_tools_public_static_cache_gc_pair_bytes(string $file, int $html_bytes): int {
    $meta = ll_tools_public_static_cache_meta_file_path($file);
    $stat = $meta !== '' && !is_link($meta) ? @lstat($meta) : false;
    return $html_bytes + (is_array($stat) && ($stat['mode'] & 0170000) === 0100000 ? (int) $stat['size'] : 0);
}

/** Keep explicit purge behavior across both layouts; normal GC never calls it. */
function ll_tools_public_static_cache_generated_files(string $pattern) {
    $files = ll_tools_static_cache_generated_files($pattern);
    if ($files === false) { return false; }
    $dir = ll_tools_public_static_cache_dir();
    $base = basename($pattern);
    for ($stripe = 0; $stripe < 256; $stripe++) {
        $shard = trailingslashit($dir) . 'files/' . sprintf('%02x', $stripe);
        if ((file_exists($shard) || is_link($shard))
            && !ll_tools_public_static_cache_gc_path_is_safe(trailingslashit($shard) . 'public-probe.html')) { return false; }
        if (!is_dir($shard)) { continue; }
        $batch = ll_tools_static_cache_generated_files(trailingslashit($shard) . $base);
        if ($batch === false) { return false; }
        $files = array_merge($files, $batch);
    }
    $name_pattern = '/^' . str_replace(['\\*', '\\?'], ['.*', '.'], preg_quote($base, '/')) . '$/D';
    foreach ($files as $file) {
        if (!is_string($file) || !ll_tools_public_static_cache_gc_path_is_safe($file)
            || preg_match($name_pattern, basename($file)) !== 1) { return false; }
    }
    return $files;
}

/** A failed lease read is busy. Never trust an object-cache snapshot here. */
function ll_tools_public_static_cache_gc_acquire_key(string $key): bool {
    global $wpdb;
    $wpdb->last_error = '';
    $raw = $wpdb->get_var($wpdb->prepare(
        "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
        ll_tools_public_static_cache_rebuild_lock_option($key)
    ));
    if ($wpdb->last_error !== '') { return false; }
    if ($raw !== null) {
        $expires = explode('|', (string) $raw, 2)[0];
        if (!ctype_digit($expires) || (int) $expires > time()) { return false; }
    }
    // Taking an expired lease fences its old renderer before any destructive
    // work; mere expiry does not prove that the old PHP process stopped.
    return ll_tools_public_static_cache_acquire_rebuild_lock($key);
}

/** Only prove obsolescence from a known identity and the stored generation. */
function ll_tools_public_static_cache_gc_is_protected(string $file, array $entry): bool {
    if (empty($entry['identity']) || !is_array($entry['identity'])) { return true; }
    $size = @filesize($file);
    if ($size === false || $size > 16 * 1024 * 1024) { return true; }
    $signature = (string) ($entry['signature'] ?? '');
    if ($signature === '' || !hash_equals($signature, ll_tools_public_static_cache_file_signature($file))) { return true; }
    $handle = @fopen($file, 'rb');
    if (!$handle) { return true; }
    try { $header = fgets($handle, 256); } finally { fclose($handle); }
    if (!is_string($header)
        || preg_match('/^<!-- ll-tools-public-static-generation:([a-zA-Z0-9-]+) -->/', $header, $matches) !== 1) { return true; }
    $current = ll_tools_public_static_cache_generation($entry['identity']);
    if ($current === '') { return true; }
    $current_version = defined('LL_TOOLS_VERSION') ? (string) LL_TOOLS_VERSION : '';
    return hash_equals($current, $matches[1])
        && in_array((string) ($entry['plugin_version'] ?? ''), ['', $current_version], true);
}

/**
 * Physically move a bounded root prefix, so later passes do not rescan an ever
 * growing retained prefix. New publications use shards from the outset.
 */
function ll_tools_public_static_cache_gc_adopt_legacy(array $limits, float $deadline): array {
    $dir = ll_tools_public_static_cache_dir();
    $result = ['visited' => 0, 'adopted' => 0, 'signature_bytes' => 0, 'complete' => true, 'retry' => false, 'blocked' => false];
    if ($dir === '' || !is_dir($dir)) { return $result; }
    $stream = @opendir($dir);
    if (!$stream) { $result['complete'] = false; $result['retry'] = true; return $result; }
    try {
        while (($name = readdir($stream)) !== false) {
            if ($result['visited'] >= $limits['visits'] || microtime(true) >= $deadline
                || $result['adopted'] >= $limits['batch']) {
                $result['complete'] = false;
                break;
            }
            $result['visited']++;
            if (preg_match('/^public-([a-f0-9]{32}|[a-f0-9]{64})(\.html|\.meta\.json)(\.tmp-[a-zA-Z0-9]+)?$/D', $name, $matches) !== 1) { continue; }
            $key = $matches[1];
            $source = trailingslashit($dir) . $name;
            if (!ll_tools_public_static_cache_gc_path_is_safe($source)) { continue; }
            $lock = ll_tools_public_static_cache_open_publication_lock($source);
            if (!is_resource($lock)) { $result['complete'] = false; continue; }
            $key_owned = false;
            try {
                $key_owned = ll_tools_public_static_cache_gc_acquire_key($key);
                if (!$key_owned) { $result['complete'] = false; continue; }
                $target = ll_tools_public_static_cache_file_path($key);
                if (!empty($matches[3])) {
                    // Historical unjournaled root temps can make physical
                    // progress too, but never while a rebuild owns this key.
                    clearstatcache(true, $source);
                    $mtime = @filemtime($source);
                    if ($mtime !== false && $mtime <= time() - $limits['temporary_age'] && @unlink($source)) { $result['adopted']++; }
                    else { $result['complete'] = false; }
                    continue;
                }
                if ($matches[2] === '.meta.json') {
                    if (is_file(ll_tools_public_static_cache_legacy_file_path($key))) { continue; }
                    $target_meta = ll_tools_public_static_cache_meta_file_path($target);
                    $mtime = @filemtime($source);
                    if (is_file($target) && ll_tools_public_static_cache_gc_prepare_directory($target)
                        && ll_tools_public_static_cache_gc_path_is_safe($target_meta)) {
                        if (is_file($target_meta) ? @unlink($source) : @rename($source, $target_meta)) { $result['adopted']++; }
                        else { $result['retry'] = true; $result['complete'] = false; }
                    } elseif (!is_file($target) && $mtime !== false && $mtime <= time() - $limits['obsolete_age'] && @unlink($source)) { $result['adopted']++; }
                    else { $result['complete'] = false; }
                    continue;
                }
                // A usable current target for this exact key makes the root
                // duplicate redundant. Only its bounded header is needed.
                if (is_file($target)) {
                    $meta = ll_tools_public_static_cache_gc_read_meta($source);
                    if (!empty($meta['identity']) && is_array($meta['identity'])
                        && ll_tools_public_static_cache_gc_has_current_header($target, $meta['identity'])
                        && @unlink($source)) {
                        $source_meta = ll_tools_public_static_cache_meta_file_path($source);
                        if (is_file($source_meta) && !is_link($source_meta)) { @unlink($source_meta); }
                        $result['adopted']++;
                    } else { $result['complete'] = false; }
                    continue;
                }
                $source_meta_data = ll_tools_public_static_cache_gc_read_meta($source);
                $signature_cost = ll_tools_public_static_cache_gc_signature_cost($source);
                if ($result['signature_bytes'] + $signature_cost > $limits['signature_bytes'] / 2) { $result['complete'] = false; break; }
                if (!ll_tools_public_static_cache_gc_prepare_directory($target)) { $result['complete'] = false; $result['retry'] = true; continue; }
                $journal = ll_tools_public_static_cache_gc_record_temporary(
                    $target . '.tmp-adopt' . wp_generate_password(12, false, false), $key, $source_meta_data['identity'] ?? null,
                    (string) ($source_meta_data['plugin_version'] ?? '')
                );
                if ($journal === '') { $result['complete'] = false; $result['retry'] = true; continue; }
                if (!@rename($source, $target)) {
                    $result['complete'] = false; $result['retry'] = true; continue;
                }
                $source_meta = ll_tools_public_static_cache_meta_file_path($source);
                $target_meta = ll_tools_public_static_cache_meta_file_path($target);
                if (is_file($source_meta) && !is_link($source_meta)
                    && (!ll_tools_public_static_cache_gc_path_is_safe($target_meta) || !@rename($source_meta, $target_meta))) {
                    // Do not invent metadata; the unknown inventory stays protected.
                    $result['retry'] = true;
                }
                $meta = ll_tools_public_static_cache_gc_read_meta($target) ?? $source_meta_data;
                $result['signature_bytes'] += $signature_cost;
                if (!ll_tools_public_static_cache_gc_record($target, $key, $meta['identity'] ?? null, (string) ($meta['plugin_version'] ?? ''))) { $result['retry'] = true; }
                else { delete_option($journal); }
                $result['adopted']++;
            } finally {
                ll_tools_public_static_cache_close_publication_lock($lock);
                if ($key_owned) { ll_tools_public_static_cache_release_rebuild_lock($key); }
            }
        }
    } finally { closedir($stream); }
    $result['blocked'] = !$result['complete'] && $result['adopted'] === 0;
    return $result;
}

/** Keep a failed row in the inventory without blocking the rest of the sweep. */
function ll_tools_public_static_cache_gc_defer_row(array &$state, string $option, int $retry_at, int $retained_bytes): void {
    $state['cursor'] = $option;
    $state['bytes'] = (int) ($state['bytes'] ?? 0) + max(0, $retained_bytes);
    $state['entries'] = (int) ($state['entries'] ?? 0) + 1;
    $state['sweep_pending_retries'] = (int) ($state['sweep_pending_retries'] ?? 0) + 1;
    $state['sweep_retry_at'] = min($retry_at, (int) ($state['sweep_retry_at'] ?? $retry_at));
}

/** One bounded keyset batch; interruption leaves the durable cursor retryable. */
function ll_tools_public_static_cache_gc_run(): array {
    global $wpdb;
    $limits = ll_tools_public_static_cache_gc_limits();
    $deadline = microtime(true) + $limits['seconds'];
    $result = ['visited' => 0, 'adopted' => 0, 'signature_bytes' => 0, 'selected' => 0, 'deleted' => 0, 'bytes_deleted' => 0, 'protected' => 0, 'deferred' => 0, 'row_retries' => 0, 'retry' => false];
    $lease = ll_tools_mutation_job_acquire('public_static_cache_gc', 'files');
    if (is_wp_error($lease)) { $result['retry'] = true; ll_tools_public_static_cache_gc_schedule(30); return $result; }
    try {
        if (ll_tools_public_static_cache_is_quarantined()) { $result['retry'] = true; return $result; }
        $state = ll_tools_mutation_job_read_option(LL_TOOLS_PUBLIC_STATIC_CACHE_GC_STATE);
        if (is_wp_error($state)) { $result['retry'] = true; return $result; }
        $state = is_array($state) ? $state : [];
        // A retained/foreign legacy prefix must never starve indexed files.
        $adoption = ll_tools_public_static_cache_gc_adopt_legacy($limits, min($deadline, microtime(true) + $limits['seconds'] / 4));
        $result = array_merge($result, array_intersect_key($adoption, $result));
        $state['legacy_adoption_blocked'] = $adoption['blocked'];
        $state['legacy_adoption_complete'] = $adoption['complete'];
        $cursor = (string) ($state['cursor'] ?? '');
        $wpdb->last_error = '';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name > %s ORDER BY option_name ASC LIMIT %d",
            $wpdb->esc_like(LL_TOOLS_PUBLIC_STATIC_CACHE_GC_ENTRY_PREFIX) . '%',
            $cursor, $limits['batch']
        ), ARRAY_A);
        if ($wpdb->last_error !== '' || !is_array($rows)) { $result['retry'] = true; return $result; }
        $pressure = (int) ($state['last_bytes'] ?? 0) > $limits['bytes'] || (int) ($state['last_entries'] ?? 0) > $limits['entries'];
        foreach ($rows as $row) {
            if (microtime(true) >= $deadline || !ll_tools_mutation_job_owns($lease)) { $result['retry'] = true; break; }
            $result['selected']++;
            $option = $row['option_name'];
            $entry = maybe_unserialize($row['option_value']);
            $key = is_array($entry) && is_string($entry['key'] ?? null) ? $entry['key'] : '';
            $temporary = is_array($entry) ? ($entry['temporary'] ?? '') : '';
            $identity = is_array($entry) ? ($entry['identity'] ?? null) : null;
            $published_version = is_array($entry) ? ($entry['plugin_version'] ?? '') : '';
            if (preg_match('/^(?:[a-f0-9]{32}|[a-f0-9]{64})$/D', $key) !== 1
                || ($identity !== null && !is_array($identity)) || !is_string($published_version)
                || !is_string($temporary)
                || ($temporary !== '' && preg_match('/^public-' . preg_quote($key, '/') . '\.(?:html|meta\.json)\.tmp-[a-zA-Z0-9]+$/D', $temporary) !== 1)) {
                // Keep unrecognized journal data for inspection, and never
                // use it to construct a path.
                $result['row_retries']++;
                ll_tools_public_static_cache_gc_defer_row($state, $option, time() + HOUR_IN_SECONDS, 0);
                continue;
            }
            if ((int) ($entry['retry_at'] ?? 0) > time()) {
                $result['row_retries']++;
                ll_tools_public_static_cache_gc_defer_row($state, $option, (int) $entry['retry_at'], (int) ($entry['bytes'] ?? 0));
                continue;
            }
            $final_file = ll_tools_public_static_cache_existing_file_path($key);
            $file = $temporary !== '' ? trailingslashit(dirname(ll_tools_public_static_cache_file_path($key))) . $temporary : $final_file;
            if (!ll_tools_public_static_cache_gc_path_is_safe($file)) {
                $result['protected']++; $state['cursor'] = $row['option_name']; continue;
            }
            $lock = ll_tools_public_static_cache_open_publication_lock($final_file);
            if (!is_resource($lock)) {
                $result['deferred']++;
                $state['cursor'] = $row['option_name'];
                continue;
            }
            $key_owned = false;
            try {
                $key_owned = ll_tools_public_static_cache_gc_acquire_key($key);
                if (!$key_owned) {
                    $result['protected']++; $result['deferred']++;
                    $busy_stat = @lstat($file);
                    $state['bytes'] = (int) ($state['bytes'] ?? 0) + (is_array($busy_stat)
                        ? ($temporary === '' ? ll_tools_public_static_cache_gc_pair_bytes($file, (int) $busy_stat['size']) : (int) $busy_stat['size']) : 0);
                    $state['entries'] = (int) ($state['entries'] ?? 0) + 1;
                    $state['cursor'] = $row['option_name'];
                    continue;
                }
                $defer_failure = static function () use (&$state, &$result, $option, $entry, $lease, $file, $temporary): void {
                    $attempt = max(1, min(8, (int) ($entry['retry_attempt'] ?? 0) + 1));
                    $retry_at = time() + min(HOUR_IN_SECONDS, 30 * (2 ** ($attempt - 1)));
                    $retry_entry = array_merge($entry, ['retry_attempt' => $attempt, 'retry_at' => $retry_at]);
                    // A publisher may have replaced this inventory row between
                    // the keyset read and our lock acquisition.
                    if (ll_tools_mutation_job_read_option($option) !== $entry
                        || is_wp_error(ll_tools_mutation_job_write_option($option, $retry_entry, $lease))) {
                        $retry_at = time() + 30;
                    }
                    clearstatcache(true, $file);
                    $size = @filesize($file);
                    $bytes = $temporary === '' ? ll_tools_public_static_cache_gc_pair_bytes($file, (int) $size) : (int) $size;
                    $result['row_retries']++;
                    ll_tools_public_static_cache_gc_defer_row($state, $option, $retry_at, $bytes);
                };
                clearstatcache(true, $file);
                $stat = @lstat($file);
                if (!is_array($stat)) {
                    if ($temporary !== '' && is_file($final_file)) {
                        $cost = ll_tools_public_static_cache_gc_signature_cost($final_file);
                        if ($result['signature_bytes'] + $cost > $limits['signature_bytes']) { $result['retry'] = true; break; }
                        $result['signature_bytes'] += $cost;
                        if (!ll_tools_public_static_cache_gc_record($final_file, $key, $identity, $published_version)) {
                            $defer_failure();
                            continue;
                        }
                    }
                    $orphan_meta = $temporary === '' ? ll_tools_public_static_cache_meta_file_path($file) : '';
                    if (($orphan_meta !== '' && is_file($orphan_meta) && !is_link($orphan_meta) && !@unlink($orphan_meta))
                        || !delete_option($option)) {
                        $defer_failure();
                        continue;
                    }
                } elseif (($stat['mode'] & 0170000) !== 0100000 || is_link($file)) {
                    $result['protected']++;
                } elseif ($temporary !== '' && (int) $stat['mtime'] > time() - $limits['temporary_age']) {
                    $result['protected']++;
                    $state['bytes'] = (int) ($state['bytes'] ?? 0) + (int) $stat['size'];
                } elseif ($temporary === '' && $result['signature_bytes'] + ll_tools_public_static_cache_gc_signature_cost($file) > $limits['signature_bytes']) {
                    $result['retry'] = true; break;
                } elseif ($temporary === '' && ll_tools_public_static_cache_gc_is_protected($file, $entry)) {
                    $result['signature_bytes'] += ll_tools_public_static_cache_gc_signature_cost($file);
                    $result['protected']++;
                    $state['bytes'] = (int) ($state['bytes'] ?? 0) + ll_tools_public_static_cache_gc_pair_bytes($file, (int) $stat['size']);
                    $state['entries'] = (int) ($state['entries'] ?? 0) + 1;
                } elseif ($temporary !== '' || $pressure || (int) $stat['mtime'] <= time() - $limits['obsolete_age']) {
                    if ($temporary === '') { $result['signature_bytes'] += ll_tools_public_static_cache_gc_signature_cost($file); }
                    // Discovery, final generation/lease check and unlink share
                    // the publication stripe; no retained file signature is reused.
                    $removed_bytes = $temporary === '' ? ll_tools_public_static_cache_gc_pair_bytes($file, (int) $stat['size']) : (int) $stat['size'];
                    if (!@unlink($file)) {
                        $defer_failure();
                        continue;
                    }
                    $result['deleted']++;
                    $result['bytes_deleted'] += (int) $stat['size'];
                    $meta_file = $temporary === '' ? ll_tools_public_static_cache_meta_file_path($file) : '';
                    if ($meta_file !== '' && is_file($meta_file) && !is_link($meta_file) && !@unlink($meta_file)) {
                        $defer_failure();
                        continue;
                    }
                    $result['bytes_deleted'] += $removed_bytes - (int) $stat['size'];
                    if (!delete_option($option)) {
                        $defer_failure();
                        continue;
                    }
                } else {
                    $result['signature_bytes'] += ll_tools_public_static_cache_gc_signature_cost($file);
                    $state['bytes'] = (int) ($state['bytes'] ?? 0) + ll_tools_public_static_cache_gc_pair_bytes($file, (int) $stat['size']);
                    $state['entries'] = (int) ($state['entries'] ?? 0) + 1;
                }
            } finally {
                ll_tools_public_static_cache_close_publication_lock($lock);
                if ($key_owned) { ll_tools_public_static_cache_release_rebuild_lock($key); }
            }
            $state['cursor'] = $row['option_name'];
        }
        $finished = count($rows) < $limits['batch'] && !$result['retry'];
        if ($finished) {
            $state['last_bytes'] = (int) ($state['bytes'] ?? 0);
            $state['last_entries'] = (int) ($state['entries'] ?? 0);
            $state['over_budget'] = $state['last_bytes'] > $limits['bytes'] || $state['last_entries'] > $limits['entries'];
            $state['completed_at'] = time();
            $state['deferred'] = $result['deferred'];
            $state['pending_retries'] = (int) ($state['sweep_pending_retries'] ?? 0);
            $state['next_retry_at'] = (int) ($state['sweep_retry_at'] ?? 0);
            unset($state['sweep_pending_retries'], $state['sweep_retry_at']);
            $state['cursor'] = ''; $state['bytes'] = 0; $state['entries'] = 0;
        }
        $saved = ll_tools_mutation_job_write_option(LL_TOOLS_PUBLIC_STATIC_CACHE_GC_STATE, $state, $lease);
        if (is_wp_error($saved)) { $result['retry'] = true; }
        $result['complete'] = $finished && !$result['retry'];
        return $result;
    } finally {
        ll_tools_mutation_job_release($lease);
        $delay = HOUR_IN_SECONDS;
        if (is_array($state ?? null) && !empty($state['next_retry_at'])) {
            $delay = min($delay, max(1, (int) $state['next_retry_at'] - time()));
        }
        ll_tools_public_static_cache_gc_schedule(!empty($result['complete']) && empty($result['deferred'])
            && !empty($adoption['complete']) ? $delay : 30);
    }
}
add_action(LL_TOOLS_PUBLIC_STATIC_CACHE_GC_HOOK, 'll_tools_public_static_cache_gc_run');
