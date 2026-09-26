<?php
declare(strict_types=1);

/**
 * Compare the checked-in POT with a fresh, temporary WP-CLI extraction.
 *
 * This guard deliberately never writes to languages/. The source extraction is
 * generated in the system temporary directory and compared by canonical
 * gettext identity and source references. Reference freshness is essential:
 * public UI selection joins these locations to current function boundaries.
 */

require_once __DIR__ . '/check-public-i18n.php';

function ll_tools_i18n_source_pot_wp_cli_guidance(): string
{
    return 'Set WP_CLI to a usable WP-CLI executable, or set WP_CLI_PHAR to a readable wp-cli.phar file.';
}

/**
 * @return array<int, string>
 */
function ll_tools_i18n_source_pot_wp_cli_command(): array
{
    $explicit_phar = trim((string) getenv('WP_CLI_PHAR'));
    if ($explicit_phar !== '') {
        if (!is_file($explicit_phar) || !is_readable($explicit_phar)) {
            throw new RuntimeException(
                'WP_CLI_PHAR does not point to a readable file: ' . $explicit_phar . '. '
                . ll_tools_i18n_source_pot_wp_cli_guidance()
            );
        }
        return [PHP_BINARY, '-d', 'memory_limit=512M', $explicit_phar];
    }

    $explicit_binary = trim((string) getenv('WP_CLI'));
    if ($explicit_binary !== '') {
        return [$explicit_binary];
    }

    $relative_phar_path = implode(DIRECTORY_SEPARATOR, [
        'Programs',
        'Local',
        'resources',
        'extraResources',
        'bin',
        'wp-cli',
        'wp-cli.phar',
    ]);
    $local_app_data = trim((string) getenv('LOCALAPPDATA'));
    $user_profile = trim((string) getenv('USERPROFILE'));
    $phar_candidates = [];
    if ($local_app_data !== '') {
        $phar_candidates[] = rtrim($local_app_data, '/\\') . DIRECTORY_SEPARATOR . $relative_phar_path;
    }
    if ($user_profile !== '') {
        $phar_candidates[] = rtrim($user_profile, '/\\') . DIRECTORY_SEPARATOR . 'AppData'
            . DIRECTORY_SEPARATOR . 'Local' . DIRECTORY_SEPARATOR . $relative_phar_path;
    }

    $windows_user_name = trim((string) getenv('USERNAME'));
    if ($windows_user_name === '') {
        $windows_user_name = trim((string) getenv('USER'));
    }
    if ($windows_user_name !== '' && preg_match('/^[A-Za-z0-9._-]+$/', $windows_user_name) === 1) {
        foreach (['/mnt/c/Users', '/c/Users'] as $mounted_users_root) {
            $phar_candidates[] = $mounted_users_root . '/' . $windows_user_name
                . '/AppData/Local/' . str_replace(DIRECTORY_SEPARATOR, '/', $relative_phar_path);
        }
    }

    $phar_candidates = array_values(array_unique($phar_candidates));
    foreach ($phar_candidates as $candidate) {
        if (is_file($candidate) && is_readable($candidate)) {
            return [PHP_BINARY, '-d', 'memory_limit=512M', $candidate];
        }
    }

    return ['wp'];
}

/**
 * @param array<int, string> $command
 * @return array{exit_code:int,stdout:string,stderr:string}
 */
function ll_tools_i18n_source_pot_run_command(array $command): array
{
    $pipes = [];
    $process = proc_open(
        $command,
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes
    );
    if (!is_resource($process)) {
        throw new RuntimeException(
            'Unable to start WP-CLI for the source POT freshness check. '
            . ll_tools_i18n_source_pot_wp_cli_guidance()
        );
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exit_code = proc_close($process);

    return [
        'exit_code' => (int) $exit_code,
        'stdout' => is_string($stdout) ? $stdout : '',
        'stderr' => is_string($stderr) ? $stderr : '',
    ];
}

/**
 * @param array<int, array<string, mixed>> $entries
 * @return array<string, array<string, mixed>>
 */
function ll_tools_i18n_source_pot_catalog_entries_by_key(array $entries): array
{
    $by_key = [];
    foreach ($entries as $entry) {
        if (($entry['msgid'] ?? '') === '') {
            continue;
        }
        $key = ll_tools_public_i18n_entry_key($entry);
        if (isset($by_key[$key])) {
            $entry['references'] = array_values(array_unique(array_merge(
                $by_key[$key]['references'] ?? [],
                $entry['references'] ?? []
            )));
            sort($entry['references'], SORT_STRING);
        }
        $by_key[$key] = $entry;
    }

    ksort($by_key, SORT_STRING);
    return $by_key;
}

/**
 * Compare identities and reference sets independently of extraction ordering.
 */
function ll_tools_i18n_source_pot_compare_entries(array $generated, array $checked): array
{
    $generated = ll_tools_i18n_source_pot_catalog_entries_by_key($generated);
    $checked = ll_tools_i18n_source_pot_catalog_entries_by_key($checked);
    $missing = array_values(array_diff_key($generated, $checked));
    $stale = array_values(array_diff_key($checked, $generated));
    $reference_drift = [];
    foreach (array_intersect_key($generated, $checked) as $key => $entry) {
        $actual = array_values(array_unique($entry['references'] ?? []));
        $recorded = array_values(array_unique($checked[$key]['references'] ?? []));
        sort($actual, SORT_STRING);
        sort($recorded, SORT_STRING);
        if ($actual !== $recorded) {
            $reference_drift[] = array_merge($entry, ['checked_references' => $recorded]);
        }
    }
    return [
        'ok' => $missing === [] && $stale === [] && $reference_drift === [],
        'generated_count' => count($generated),
        'checked_count' => count($checked),
        'missing' => $missing,
        'stale' => $stale,
        'reference_drift' => $reference_drift,
    ];
}

/**
 * @return array<string,mixed>
 */
function ll_tools_i18n_compare_source_to_checked_pot(string $root_dir): array
{
    $root_dir = rtrim($root_dir, '/\\');
    $checked_pot = $root_dir . DIRECTORY_SEPARATOR . 'languages' . DIRECTORY_SEPARATOR . 'll-tools-text-domain.pot';
    if (!is_file($checked_pot)) {
        throw new RuntimeException("Checked-in POT not found: {$checked_pot}");
    }

    $temporary_base = tempnam(sys_get_temp_dir(), 'll-tools-source-pot-');
    if ($temporary_base === false) {
        throw new RuntimeException('Unable to allocate a temporary source POT path.');
    }
    @unlink($temporary_base);
    $temporary_pot = $temporary_base . '.pot';

    try {
        $command = array_merge(
            ll_tools_i18n_source_pot_wp_cli_command(),
            [
                'i18n',
                'make-pot',
                $root_dir,
                $temporary_pot,
                '--slug=language-learner-tools',
                '--domain=ll-tools-text-domain',
                '--exclude=offline-app-builder,tests,test-results,_codex_temp',
                '--skip-audit',
            ]
        );
        $run = ll_tools_i18n_source_pot_run_command($command);
        if ($run['exit_code'] !== 0 || !is_file($temporary_pot)) {
            $diagnostic = trim($run['stderr'] !== '' ? $run['stderr'] : $run['stdout']);
            throw new RuntimeException(
                'WP-CLI could not generate the temporary source POT'
                . ($diagnostic !== '' ? ': ' . $diagnostic : '.')
                . PHP_EOL
                . ll_tools_i18n_source_pot_wp_cli_guidance()
            );
        }

        return ll_tools_i18n_source_pot_compare_entries(
            ll_tools_public_i18n_parse_po_file($temporary_pot, $root_dir),
            ll_tools_public_i18n_parse_po_file($checked_pot, $root_dir)
        );
    } finally {
        if (is_file($temporary_pot)) {
            @unlink($temporary_pot);
        }
    }
}

function ll_tools_i18n_source_pot_entry_label(array $entry): string
{
    $parts = [];
    if (($entry['context'] ?? null) !== null) {
        $parts[] = 'context=' . json_encode((string) $entry['context'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    $parts[] = 'msgid=' . json_encode((string) ($entry['msgid'] ?? ''), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (($entry['msgid_plural'] ?? null) !== null) {
        $parts[] = 'plural=' . json_encode((string) $entry['msgid_plural'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    return implode(' ', $parts);
}

function ll_tools_i18n_source_pot_reference_drift_label(array $entry): string
{
    return ll_tools_i18n_source_pot_entry_label($entry)
        . ' checked=[' . implode(', ', (array) ($entry['checked_references'] ?? [])) . ']'
        . ' source=[' . implode(', ', (array) ($entry['references'] ?? [])) . ']';
}

if (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    try {
        $comparison = ll_tools_i18n_compare_source_to_checked_pot(dirname(__DIR__));
        if ($comparison['ok']) {
            fwrite(
                STDOUT,
                sprintf("Source POT is fresh (%d canonical gettext keys and source references).\n", $comparison['checked_count'])
            );
            exit(0);
        }

        fwrite(
            STDERR,
            sprintf(
                "Source POT drift: %d missing source keys; %d stale checked-in keys; %d stale reference sets.\n",
                count($comparison['missing']),
                count($comparison['stale']),
                count($comparison['reference_drift'])
            )
        );
        foreach (array_slice($comparison['missing'], 0, 10) as $entry) {
            fwrite(STDERR, '+ ' . ll_tools_i18n_source_pot_entry_label($entry) . "\n");
        }
        foreach (array_slice($comparison['stale'], 0, 10) as $entry) {
            fwrite(STDERR, '- ' . ll_tools_i18n_source_pot_entry_label($entry) . "\n");
        }
        foreach (array_slice($comparison['reference_drift'], 0, 10) as $entry) {
            fwrite(STDERR, '~ ' . ll_tools_i18n_source_pot_reference_drift_label($entry) . "\n");
        }
        exit(1);
    } catch (Throwable $error) {
        fwrite(STDERR, $error->getMessage() . "\n");
        exit(2);
    }
}
