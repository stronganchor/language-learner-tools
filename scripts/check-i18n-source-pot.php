<?php
declare(strict_types=1);

/**
 * Compare the checked-in POT with a fresh, temporary WP-CLI extraction.
 *
 * This guard deliberately never writes to languages/. The source extraction is
 * generated in the system temporary directory and compared by canonical
 * gettext identity: context, singular msgid, and plural msgid.
 */

require_once __DIR__ . '/check-public-i18n.php';

/**
 * @return array<int, string>
 */
function ll_tools_i18n_source_pot_wp_cli_command(): array
{
    $explicit_phar = trim((string) getenv('WP_CLI_PHAR'));
    if ($explicit_phar !== '' && is_file($explicit_phar)) {
        return [PHP_BINARY, '-d', 'memory_limit=512M', $explicit_phar];
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
        if (is_file($candidate)) {
            return [PHP_BINARY, '-d', 'memory_limit=512M', $candidate];
        }
    }

    $explicit_binary = trim((string) getenv('WP_CLI'));
    if ($explicit_binary !== '') {
        return [$explicit_binary];
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
        throw new RuntimeException('Unable to start WP-CLI for the source POT freshness check.');
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
        $by_key[ll_tools_public_i18n_entry_key($entry)] = $entry;
    }

    ksort($by_key, SORT_STRING);
    return $by_key;
}

/**
 * @return array{ok:bool,generated_count:int,checked_count:int,missing:array<int,array<string,mixed>>,stale:array<int,array<string,mixed>>}
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
                '--exclude=offline-app-builder,tests,_codex_temp',
                '--skip-audit',
            ]
        );
        $run = ll_tools_i18n_source_pot_run_command($command);
        if ($run['exit_code'] !== 0 || !is_file($temporary_pot)) {
            $diagnostic = trim($run['stderr'] !== '' ? $run['stderr'] : $run['stdout']);
            throw new RuntimeException(
                'WP-CLI could not generate the temporary source POT'
                . ($diagnostic !== '' ? ': ' . $diagnostic : '.')
            );
        }

        $generated = ll_tools_i18n_source_pot_catalog_entries_by_key(
            ll_tools_public_i18n_parse_po_file($temporary_pot)
        );
        $checked = ll_tools_i18n_source_pot_catalog_entries_by_key(
            ll_tools_public_i18n_parse_po_file($checked_pot)
        );
        $missing = array_values(array_diff_key($generated, $checked));
        $stale = array_values(array_diff_key($checked, $generated));

        return [
            'ok' => $missing === [] && $stale === [],
            'generated_count' => count($generated),
            'checked_count' => count($checked),
            'missing' => $missing,
            'stale' => $stale,
        ];
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

if (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    try {
        $comparison = ll_tools_i18n_compare_source_to_checked_pot(dirname(__DIR__));
        if ($comparison['ok']) {
            fwrite(
                STDOUT,
                sprintf("Source POT is fresh (%d canonical gettext keys).\n", $comparison['checked_count'])
            );
            exit(0);
        }

        fwrite(
            STDERR,
            sprintf(
                "Source POT drift: %d missing source keys; %d stale checked-in keys.\n",
                count($comparison['missing']),
                count($comparison['stale'])
            )
        );
        foreach (array_slice($comparison['missing'], 0, 10) as $entry) {
            fwrite(STDERR, '+ ' . ll_tools_i18n_source_pot_entry_label($entry) . "\n");
        }
        foreach (array_slice($comparison['stale'], 0, 10) as $entry) {
            fwrite(STDERR, '- ' . ll_tools_i18n_source_pot_entry_label($entry) . "\n");
        }
        exit(1);
    } catch (Throwable $error) {
        fwrite(STDERR, $error->getMessage() . "\n");
        exit(2);
    }
}
