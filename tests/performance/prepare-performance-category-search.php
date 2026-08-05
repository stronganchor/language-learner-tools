<?php
/**
 * Prepare the selected performance fixture's durable category-search index.
 *
 * The browser benchmark measures steady-state search. A newly seeded large
 * fixture needs more bounded materializer batches than the public UI should
 * perform in one foreground retry cycle, so complete that work here before
 * timing the interaction.
 */

if (!defined('ABSPATH')) {
    fwrite(STDERR, "This script must run inside WordPress, usually through WP-CLI eval-file.\n");
    exit(1);
}

function ll_tools_perf_category_search_fail(string $message): void {
    if (class_exists('WP_CLI')) {
        WP_CLI::error($message);
    }

    throw new RuntimeException($message);
}

function ll_tools_perf_category_search_log(string $message): void {
    if (class_exists('WP_CLI')) {
        WP_CLI::log($message);
        return;
    }

    fwrite(STDOUT, $message . PHP_EOL);
}

/**
 * @return array<string,string>
 */
function ll_tools_perf_category_search_cli_options(): array {
    $options = [];
    $raw_args = [];
    if (isset($GLOBALS['args']) && is_array($GLOBALS['args']) && !empty($GLOBALS['args'])) {
        $raw_args = array_values($GLOBALS['args']);
    } elseif (isset($_SERVER['argv']) && is_array($_SERVER['argv'])) {
        // Some WP-CLI launch paths (notably WSL invoking Windows wp-cli.bat)
        // do not populate eval-file's $args global, but retain the arguments in
        // argv. Keep the explicit manifest usable in both launch modes.
        $raw_args = array_slice(array_values($_SERVER['argv']), 1);
    }
    foreach ($raw_args as $raw_arg) {
        $raw_arg = (string) $raw_arg;
        if (strpos($raw_arg, '=') === false) {
            continue;
        }
        [$key, $value] = array_map('trim', explode('=', $raw_arg, 2));
        $key = sanitize_key($key);
        if ($key !== '') {
            $options[$key] = $value;
        }
    }

    return $options;
}

function ll_tools_perf_category_search_manifest_path(): string {
    $options = ll_tools_perf_category_search_cli_options();
    if (!empty($options['manifest'])) {
        return (string) $options['manifest'];
    }

    foreach (['LL_TOOLS_PERF_FIXTURE_MANIFEST', 'LL_E2E_PERF_FIXTURE_MANIFEST'] as $env_name) {
        $value = getenv($env_name);
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }
    }

    return __DIR__ . '/fixtures/performance-wordsets.json';
}

/**
 * @return array{size:string,slug:string,expected_categories:int,expected_words:int}
 */
function ll_tools_perf_category_search_target(): array {
    $manifest_path = ll_tools_perf_category_search_manifest_path();
    if (!is_readable($manifest_path)) {
        ll_tools_perf_category_search_fail('Performance fixture manifest is not readable: ' . $manifest_path);
    }

    $manifest = json_decode((string) file_get_contents($manifest_path), true);
    if (!is_array($manifest)) {
        ll_tools_perf_category_search_fail('Performance fixture manifest is not valid JSON: ' . $manifest_path);
    }

    $wordsets = array_values(array_filter(
        (array) ($manifest['wordsets'] ?? []),
        'is_array'
    ));
    $requested_size = sanitize_key((string) ($manifest['benchmarkTargetSize'] ?? 'large'));
    $target_wordset = null;
    foreach ($wordsets as $wordset) {
        if (sanitize_key((string) ($wordset['size'] ?? '')) === $requested_size) {
            $target_wordset = $wordset;
            break;
        }
    }
    if (!is_array($target_wordset)) {
        foreach ($wordsets as $wordset) {
            if (sanitize_key((string) ($wordset['size'] ?? '')) === 'large') {
                $target_wordset = $wordset;
                break;
            }
        }
    }
    if (!is_array($target_wordset) && !empty($wordsets)) {
        $target_wordset = $wordsets[count($wordsets) - 1];
    }

    if (is_array($target_wordset)) {
        $target_size = sanitize_key((string) ($target_wordset['size'] ?? $requested_size));
        $slug = sanitize_title((string) ($target_wordset['slug'] ?? ''));
        $category_count = max(0, (int) ($target_wordset['categoryCount'] ?? 0));
        $words_per_category = max(0, (int) ($target_wordset['wordsPerCategory'] ?? 0));
        if ($target_size !== '' && $slug !== '' && $category_count > 0 && $words_per_category > 0) {
            return [
                'size' => $target_size,
                'slug' => $slug,
                'expected_categories' => $category_count,
                'expected_words' => $category_count * $words_per_category,
            ];
        }
    }

    ll_tools_perf_category_search_fail('Performance fixture manifest has no valid benchmark target wordset.');
}

/**
 * Return a compact marker used to detect a stalled bounded build.
 */
function ll_tools_perf_category_search_progress_marker(array $state): string {
    return implode('|', [
        (string) ($state['status'] ?? ''),
        (string) ($state['signature'] ?? ''),
        (string) ($state['generation'] ?? ''),
        (string) ($state['published_generation'] ?? ''),
        (string) max(0, (int) ($state['last_id'] ?? 0)),
        (string) max(0, (int) ($state['processed'] ?? 0)),
        (string) max(0, (int) ($state['retry_count'] ?? 0)),
        (string) max(0, (int) ($state['next_retry_at'] ?? 0)),
        !empty($state['terminal']) ? '1' : '0',
    ]);
}

function ll_tools_perf_category_search_stale_row_count(int $wordset_id, string $generation): int {
    global $wpdb;

    $wpdb->last_error = '';
    $count = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*)
         FROM " . ll_tools_wordset_category_search_table_name() . "
         WHERE wordset_id = %d
           AND generation <> %s",
        $wordset_id,
        $generation
    ));
    if ($wpdb->last_error !== '') {
        ll_tools_perf_category_search_fail('Unable to inspect stale performance category-search rows: ' . $wpdb->last_error);
    }

    return max(0, $count);
}

/**
 * @return array<string,mixed>
 */
function ll_tools_perf_prepare_category_search_index(): array {
    global $wpdb;

    $required_functions = [
        'll_tools_wordset_category_search_dependency_signature',
        'll_tools_get_wordset_category_search_state',
        'll_tools_wordset_category_search_state_is_ready',
        'll_tools_wordset_category_search_process_rebuild_batch',
        'll_tools_wordset_category_search_rebuild_batch_size',
        'll_tools_wordset_category_search_cleanup_old_generations',
        'll_tools_wordset_category_search_lock_exists',
        'll_tools_wordset_category_search_table_name',
    ];
    foreach ($required_functions as $function_name) {
        if (!function_exists($function_name)) {
            ll_tools_perf_category_search_fail('LL Tools category-search runtime is unavailable: ' . $function_name);
        }
    }

    $target = ll_tools_perf_category_search_target();
    $wordset = get_term_by('slug', (string) $target['slug'], 'wordset');
    if (!($wordset instanceof WP_Term) || is_wp_error($wordset)) {
        ll_tools_perf_category_search_fail('Performance target wordset is missing: ' . (string) $target['slug']);
    }

    $wordset_id = (int) $wordset->term_id;
    $expected_categories = (int) $target['expected_categories'];
    $expected_words = (int) $target['expected_words'];
    $batch_size = max(1, (int) ll_tools_wordset_category_search_rebuild_batch_size());
    $maximum_steps = min(10000, max(30, (int) ceil($expected_words / $batch_size) + 30));
    $no_progress_steps = 0;
    $steps = 0;

    $source_signature = ll_tools_wordset_category_search_dependency_signature($wordset_id);

    for ($step = 0; $step < $maximum_steps; $step++) {
        $current_signature = ll_tools_wordset_category_search_dependency_signature($wordset_id);
        if (!hash_equals($source_signature, $current_signature)) {
            ll_tools_perf_category_search_fail('Performance category-search source changed during preparation for ' . (string) $target['slug']);
        }
        $state = ll_tools_get_wordset_category_search_state($wordset_id);
        if (ll_tools_wordset_category_search_state_is_ready($wordset_id, $source_signature, $state)) {
            break;
        }
        if (!empty($state['terminal']) && hash_equals($source_signature, (string) ($state['signature'] ?? ''))) {
            ll_tools_perf_category_search_fail(sprintf(
                'Performance category-search preparation reached a terminal state for %s: %s',
                (string) $target['slug'],
                (string) ($state['last_error'] ?? 'unknown error')
            ));
        }
        if (
            hash_equals($source_signature, (string) ($state['signature'] ?? ''))
            && (int) ($state['next_retry_at'] ?? 0) > time()
        ) {
            ll_tools_perf_category_search_fail(sprintf(
                'Performance category-search preparation is backing off for %s after %s (retry at %d).',
                (string) $target['slug'],
                (string) ($state['last_error'] ?? 'a transient error'),
                (int) $state['next_retry_at']
            ));
        }

        $before = ll_tools_perf_category_search_progress_marker($state);
        $state = ll_tools_wordset_category_search_process_rebuild_batch($wordset_id);
        $steps++;
        if (!hash_equals(
            $source_signature,
            ll_tools_wordset_category_search_dependency_signature($wordset_id)
        )) {
            ll_tools_perf_category_search_fail('Performance category-search source changed during preparation for ' . (string) $target['slug']);
        }
        $after = ll_tools_perf_category_search_progress_marker($state);
        if (hash_equals($before, $after)) {
            $no_progress_steps++;
            if (ll_tools_wordset_category_search_lock_exists($wordset_id)) {
                usleep(250000);
            }
        } else {
            $no_progress_steps = 0;
        }
        if ($no_progress_steps >= 20) {
            ll_tools_perf_category_search_fail(sprintf(
                'Performance category-search preparation made no progress for %s after %d bounded attempts.',
                (string) $target['slug'],
                $steps
            ));
        }
    }

    $signature = ll_tools_wordset_category_search_dependency_signature($wordset_id);
    if (!hash_equals($source_signature, $signature)) {
        ll_tools_perf_category_search_fail('Performance category-search source changed during preparation for ' . (string) $target['slug']);
    }
    $state = ll_tools_get_wordset_category_search_state($wordset_id);
    if (!ll_tools_wordset_category_search_state_is_ready($wordset_id, $source_signature, $state)) {
        ll_tools_perf_category_search_fail(sprintf(
            'Performance category-search preparation did not complete for %s within %d bounded batches (processed %d of %d).',
            (string) $target['slug'],
            $maximum_steps,
            (int) ($state['processed'] ?? 0),
            $expected_words
        ));
    }
    if ((int) ($state['processed'] ?? 0) !== $expected_words) {
        ll_tools_perf_category_search_fail(sprintf(
            'Performance category-search state count mismatch for %s: expected %d, processed %d.',
            (string) $target['slug'],
            $expected_words,
            (int) ($state['processed'] ?? 0)
        ));
    }

    $table = ll_tools_wordset_category_search_table_name();
    $generation = (string) ($state['published_generation'] ?? '');
    $wpdb->last_error = '';
    $index_counts = $wpdb->get_row($wpdb->prepare(
        "SELECT
             COUNT(*) AS indexed_rows,
             COUNT(DISTINCT word_id) AS indexed_words,
             COUNT(DISTINCT category_id) AS indexed_categories
         FROM {$table}
         WHERE wordset_id = %d
           AND generation = %s",
        $wordset_id,
        $generation
    ), ARRAY_A);
    if ($wpdb->last_error !== '') {
        ll_tools_perf_category_search_fail('Unable to verify the prepared performance category-search rows: ' . $wpdb->last_error);
    }
    $index_counts = is_array($index_counts) ? $index_counts : [];
    $indexed_rows = (int) ($index_counts['indexed_rows'] ?? 0);
    $indexed_words = (int) ($index_counts['indexed_words'] ?? 0);
    $indexed_categories = (int) ($index_counts['indexed_categories'] ?? 0);
    if ($indexed_rows !== $expected_words || $indexed_words !== $expected_words) {
        ll_tools_perf_category_search_fail(sprintf(
            'Performance category-search word count mismatch for %s: expected %d, found %d rows and %d distinct words.',
            (string) $target['slug'],
            $expected_words,
            $indexed_rows,
            $indexed_words
        ));
    }
    if ($indexed_categories !== $expected_categories) {
        ll_tools_perf_category_search_fail(sprintf(
            'Performance category-search category count mismatch for %s: expected %d, indexed %d.',
            (string) $target['slug'],
            $expected_categories,
            $indexed_categories
        ));
    }
    $initial_stale_rows = ll_tools_perf_category_search_stale_row_count($wordset_id, $generation);
    $remaining_stale_rows = $initial_stale_rows;
    $cleanup_steps = 0;
    $maximum_cleanup_steps = min(10000, max(1, (int) ceil($initial_stale_rows / 50) + 2));
    while ($remaining_stale_rows > 0 && $cleanup_steps < $maximum_cleanup_steps) {
        ll_tools_wordset_category_search_cleanup_old_generations($wordset_id, $generation);
        $cleanup_steps++;
        $next_stale_rows = ll_tools_perf_category_search_stale_row_count($wordset_id, $generation);
        if ($next_stale_rows >= $remaining_stale_rows) {
            ll_tools_perf_category_search_fail(sprintf(
                'Performance category-search cleanup made no progress for %s with %d stale rows remaining.',
                (string) $target['slug'],
                $remaining_stale_rows
            ));
        }
        $remaining_stale_rows = $next_stale_rows;
    }
    if ($remaining_stale_rows > 0) {
        ll_tools_perf_category_search_fail(sprintf(
            'Performance category-search cleanup did not finish for %s within %d bounded batches (%d stale rows remain).',
            (string) $target['slug'],
            $maximum_cleanup_steps,
            $remaining_stale_rows
        ));
    }
    if (!hash_equals($source_signature, ll_tools_wordset_category_search_dependency_signature($wordset_id))) {
        ll_tools_perf_category_search_fail('Performance category-search source changed during preparation for ' . (string) $target['slug']);
    }

    if (defined('LL_TOOLS_WORDSET_CATEGORY_SEARCH_REBUILD_HOOK')) {
        wp_clear_scheduled_hook(LL_TOOLS_WORDSET_CATEGORY_SEARCH_REBUILD_HOOK, [$wordset_id]);
    }
    $final_signature = ll_tools_wordset_category_search_dependency_signature($wordset_id);
    $final_state = ll_tools_get_wordset_category_search_state($wordset_id);
    if (
        !hash_equals($source_signature, $final_signature)
        || !ll_tools_wordset_category_search_state_is_ready(
            $wordset_id,
            $source_signature,
            $final_state
        )
        || !hash_equals($generation, (string) ($final_state['published_generation'] ?? ''))
    ) {
        ll_tools_perf_category_search_fail('Performance category-search readiness changed during final verification for ' . (string) $target['slug']);
    }

    return [
        'target_size' => (string) $target['size'],
        'wordset_slug' => (string) $target['slug'],
        'wordset_id' => $wordset_id,
        'expected_categories' => $expected_categories,
        'indexed_categories' => $indexed_categories,
        'expected_words' => $expected_words,
        'indexed_rows' => $indexed_rows,
        'indexed_words' => $indexed_words,
        'stale_rows_removed' => $initial_stale_rows,
        'cleanup_batches_run' => $cleanup_steps,
        'batch_size' => $batch_size,
        'batches_run' => $steps,
        'status' => (string) ($final_state['status'] ?? ''),
        'generation' => $generation,
        'signature' => $source_signature,
    ];
}

$summary = ll_tools_perf_prepare_category_search_index();
ll_tools_perf_category_search_log(wp_json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
