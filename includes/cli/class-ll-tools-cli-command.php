<?php
if (!defined('WPINC')) {
    die;
}

if (!class_exists('WP_CLI_Command')) {
    return;
}

class LL_Tools_CLI_Command extends WP_CLI_Command {
    /**
     * Resume the durable wordset-isolation migration until completion.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format: table or json.
     *
     * [--allow-large-option-rules]
     * : Permit explicit CLI maintenance to load an option-rules store above the background safety limit.
     *
     * ## EXAMPLES
     *
     *     wp ll-tools wordset-isolation-migrate
     *     wp ll-tools wordset-isolation-migrate --format=json
     *     wp ll-tools wordset-isolation-migrate --allow-large-option-rules
     */
    public function wordset_isolation_migrate(array $args, array $assoc_args): void {
        unset($args);
        if (!function_exists('ll_tools_run_wordset_isolation_migration')) {
            WP_CLI::error('Wordset isolation migration is unavailable.');
        }

        $allow_large_option_rules = !empty($assoc_args['allow-large-option-rules']);
        $allow_large_filter = static function (): int {
            return PHP_INT_MAX;
        };
        if ($allow_large_option_rules) {
            add_filter('ll_tools_wordset_isolation_migration_word_option_rules_max_bytes', $allow_large_filter);
        }
        try {
            $result = ll_tools_run_wordset_isolation_migration();
        } finally {
            if ($allow_large_option_rules) {
                remove_filter('ll_tools_wordset_isolation_migration_word_option_rules_max_bytes', $allow_large_filter);
            }
        }
        $format = isset($assoc_args['format']) ? sanitize_key((string) $assoc_args['format']) : 'table';
        if ($format === 'json') {
            WP_CLI::line(ll_tools_cli_json_encode($result));
        } else {
            $row = $result;
            $row['errors'] = implode('; ', (array) ($result['errors'] ?? []));
            \WP_CLI\Utils\format_items('table', [$row], array_keys($row));
        }

        if ((string) ($result['status'] ?? '') !== 'completed') {
            WP_CLI::error((string) (($result['errors'][0] ?? '') ?: 'Wordset isolation migration did not complete.'));
        }
    }

    /**
     * Create a word set, optionally from a template.
     *
     * ## OPTIONS
     *
     * <name>
     * : New word set name.
     *
     * [--slug=<slug>]
     * : Explicit slug for the new word set.
     *
     * [--template=<wordset>]
     * : Existing word set slug, name, or ID to clone as a template.
     *
     * [--manager=<user>]
     * : Manager login, email, or user ID to assign after creation.
     *
     * [--format=<format>]
     * : Output format: table or json.
     *
     * ## EXAMPLES
     *
     *     wp ll-tools wordset-create "Spanish Nouns"
     *     wp ll-tools wordset-create "Spanish Travel" --template=travel-template --manager=codex
     */
    public function wordset_create(array $args, array $assoc_args): void {
        [$name] = $args;

        $name = sanitize_text_field((string) $name);
        $slug = isset($assoc_args['slug']) ? sanitize_title((string) $assoc_args['slug']) : '';
        $manager_id = 0;

        if (!empty($assoc_args['manager'])) {
            $manager_id = ll_tools_cli_resolve_user_id((string) $assoc_args['manager']);
            if (is_wp_error($manager_id)) {
                WP_CLI::error($manager_id->get_error_message());
            }
        }

        $payload = [];
        if (!empty($assoc_args['template'])) {
            $template_term = ll_tools_cli_resolve_wordset_term((string) $assoc_args['template']);
            if (is_wp_error($template_term)) {
                WP_CLI::error($template_term->get_error_message());
            }

            $result = ll_tools_create_wordset_from_template((int) $template_term->term_id, [
                'name' => $name,
                'slug' => $slug,
                'manager_user_id' => $manager_id,
                'copy_settings' => true,
            ]);
            if (is_wp_error($result)) {
                WP_CLI::error($result->get_error_message());
            }

            $created_wordset_id = (int) ($result['wordset_id'] ?? 0);
            if ($manager_id > 0) {
                ll_tools_cli_assign_wordset_manager($created_wordset_id, $manager_id);
            }
            $created_term = get_term($created_wordset_id, 'wordset');
            $payload = [
                'wordset_id' => $created_wordset_id,
                'wordset_slug' => ($created_term instanceof WP_Term && !is_wp_error($created_term)) ? (string) $created_term->slug : '',
                'wordset_name' => ($created_term instanceof WP_Term && !is_wp_error($created_term)) ? (string) $created_term->name : '',
                'template_wordset_id' => (int) $template_term->term_id,
                'template_wordset_slug' => (string) $template_term->slug,
                'categories_created' => (int) ($result['categories_created'] ?? 0),
                'images_created' => (int) ($result['images_created'] ?? 0),
                'failed_categories' => (int) ($result['failed_categories'] ?? 0),
                'failed_images' => (int) ($result['failed_images'] ?? 0),
                'manager_user_id' => $manager_id,
            ];
        } else {
            $insert_args = [];
            if ($slug !== '') {
                $insert_args['slug'] = $slug;
            }
            $inserted = wp_insert_term($name, 'wordset', $insert_args);
            if (is_wp_error($inserted)) {
                WP_CLI::error($inserted->get_error_message());
            }

            $created_wordset_id = (int) ($inserted['term_id'] ?? 0);
            if (function_exists('ll_tools_ensure_vocab_lessons_enabled_for_wordset')) {
                ll_tools_ensure_vocab_lessons_enabled_for_wordset($created_wordset_id, false);
            }
            if ($manager_id > 0) {
                ll_tools_cli_assign_wordset_manager($created_wordset_id, $manager_id);
            }

            $created_term = get_term($created_wordset_id, 'wordset');
            $payload = [
                'wordset_id' => $created_wordset_id,
                'wordset_slug' => ($created_term instanceof WP_Term && !is_wp_error($created_term)) ? (string) $created_term->slug : '',
                'wordset_name' => ($created_term instanceof WP_Term && !is_wp_error($created_term)) ? (string) $created_term->name : '',
                'manager_user_id' => $manager_id,
            ];
        }

        $format = isset($assoc_args['format']) ? sanitize_key((string) $assoc_args['format']) : 'table';
        if ($format === 'json') {
            WP_CLI::line(ll_tools_cli_json_encode($payload));
            return;
        }

        \WP_CLI\Utils\format_items('table', [$payload], array_keys($payload));
    }

    /**
     * List missing word metadata in a word set without using Editor Hub AJAX.
     *
     * ## OPTIONS
     *
     * <wordset>
     * : Word set slug, name, or ID.
     *
     * [--category=<category>]
     * : Limit to one category slug or name.
     *
     * [--fields=<fields>]
     * : Comma-separated missing fields to require. Allowed: word_text, word_translation, word_note, dictionary_entry, part_of_speech, grammatical_gender, grammatical_plurality, verb_tense, verb_mood.
     *
     * [--format=<format>]
     * : Output format: table, json, csv, count, or ids.
     *
     * [--summary-file=<path>]
     * : Optional JSON summary output path.
     *
     * ## EXAMPLES
     *
     *     wp ll-tools wordset-missing-meta spanish
     *     wp ll-tools wordset-missing-meta spanish --category=household-items --fields=part_of_speech,grammatical_gender --format=json
     */
    public function wordset_missing_meta(array $args, array $assoc_args): void {
        [$wordset_spec] = $args;
        $wordset_term = ll_tools_cli_resolve_wordset_term($wordset_spec);
        if (is_wp_error($wordset_term)) {
            WP_CLI::error($wordset_term->get_error_message());
        }

        $category_spec = isset($assoc_args['category']) ? (string) $assoc_args['category'] : '';
        $word_ids = ll_tools_cli_get_word_ids_for_scope((int) $wordset_term->term_id, $category_spec, '');
        if (is_wp_error($word_ids)) {
            WP_CLI::error($word_ids->get_error_message());
        }

        $rows = ll_tools_cli_get_word_rows((int) $wordset_term->term_id, $word_ids);
        $rows = array_values(array_filter($rows, static function (array $row): bool {
            return !empty($row['has_missing']);
        }));

        $missing_fields = [];
        if (!empty($assoc_args['fields'])) {
            $missing_fields = ll_tools_cli_normalize_field_list((string) $assoc_args['fields'], ll_tools_cli_supported_missing_fields());
            if (is_wp_error($missing_fields)) {
                WP_CLI::error($missing_fields->get_error_message());
            }
            $rows = ll_tools_cli_filter_word_rows($rows, [
                'missing_fields' => $missing_fields,
            ]);
        }

        $summary = [
            'generated_at_gmt' => gmdate('c'),
            'wordset' => [
                'id' => (int) $wordset_term->term_id,
                'slug' => (string) $wordset_term->slug,
                'name' => (string) $wordset_term->name,
            ],
            'filters' => [
                'category' => $category_spec,
                'fields' => $missing_fields,
            ],
            'count' => count($rows),
            'rows' => ll_tools_cli_prepare_word_rows_for_output($rows),
        ];

        if (!empty($assoc_args['summary-file'])) {
            $write_result = ll_tools_cli_write_json_file((string) $assoc_args['summary-file'], $summary);
            if (is_wp_error($write_result)) {
                WP_CLI::warning($write_result->get_error_message());
            }
        }

        $format = isset($assoc_args['format']) ? sanitize_key((string) $assoc_args['format']) : 'table';
        if ($format === 'count') {
            WP_CLI::line((string) count($rows));
            return;
        }

        if ($format === 'ids') {
            foreach ($rows as $row) {
                WP_CLI::line((string) ((int) ($row['word_id'] ?? 0)));
            }
            return;
        }

        if ($format === 'json') {
            WP_CLI::line(ll_tools_cli_json_encode($summary));
            return;
        }

        \WP_CLI\Utils\format_items($format, ll_tools_cli_prepare_word_rows_for_output($rows), [
            'word_id',
            'word_slug',
            'word_text',
            'word_translation',
            'category_slug',
            'part_of_speech',
            'grammatical_gender',
            'missing_fields',
            'missing_count',
        ]);
    }

    /**
     * Apply a safe partial metadata update to words in a word set.
     *
     * ## OPTIONS
     *
     * <wordset>
     * : Word set slug, name, or ID.
     *
     * --set=<field=value>
     * : Field update to apply. Supported fields: word_translation, word_note, dictionary_entry_title, part_of_speech, grammatical_gender, grammatical_plurality, verb_tense, verb_mood.
     *
     * [--category=<category>]
     * : Limit to one category slug or name.
     *
     * [--word=<word>]
     * : Limit to one word slug, title, or ID inside the word set.
     *
     * [--where-missing=<fields>]
     * : Require these missing fields before updating.
     *
     * [--where-pos=<slug>]
     * : Require a matching part of speech before updating.
     *
     * [--limit=<number>]
     * : Process at most this many matched words.
     *
     * [--offset=<number>]
     * : Skip this many matched words before processing.
     *
     * [--dry-run]
     * : Print the exact matches without changing anything.
     *
     * [--summary-file=<path>]
     * : Optional JSON summary output path.
     *
     * [--resume-file=<path>]
     * : Optional JSON state file for interrupted runs.
     *
     * [--format=<format>]
     * : Output format for matched rows: table, json, csv, or count.
     *
     * ## EXAMPLES
     *
     *     wp ll-tools word-bulk-update spanish --category=household-items --where-missing=grammatical_gender --set=grammatical_gender=Feminine --dry-run
     *     wp ll-tools word-bulk-update spanish --where-pos=noun --where-missing=part_of_speech --set=part_of_speech=noun --summary-file=tmp/spanish-pos.json
     */
    public function word_bulk_update(array $args, array $assoc_args): void {
        [$wordset_spec] = $args;
        $wordset_term = ll_tools_cli_resolve_wordset_term($wordset_spec);
        if (is_wp_error($wordset_term)) {
            WP_CLI::error($wordset_term->get_error_message());
        }

        if (empty($assoc_args['set'])) {
            WP_CLI::error(__('Missing required --set=<field>=<value> argument.', 'll-tools-text-domain'));
        }

        $set_args = ll_tools_cli_parse_set_argument((string) $assoc_args['set']);
        if (is_wp_error($set_args)) {
            WP_CLI::error($set_args->get_error_message());
        }

        $category_spec = isset($assoc_args['category']) ? (string) $assoc_args['category'] : '';
        $word_spec = isset($assoc_args['word']) ? (string) $assoc_args['word'] : '';
        $word_ids = ll_tools_cli_get_word_ids_for_scope((int) $wordset_term->term_id, $category_spec, $word_spec);
        if (is_wp_error($word_ids)) {
            WP_CLI::error($word_ids->get_error_message());
        }

        $rows = ll_tools_cli_get_word_rows((int) $wordset_term->term_id, $word_ids);

        $missing_fields = [];
        if (!empty($assoc_args['where-missing'])) {
            $missing_fields = ll_tools_cli_normalize_field_list((string) $assoc_args['where-missing'], ll_tools_cli_supported_missing_fields());
            if (is_wp_error($missing_fields)) {
                WP_CLI::error($missing_fields->get_error_message());
            }
        }

        $rows = ll_tools_cli_filter_word_rows($rows, [
            'missing_fields' => $missing_fields,
            'part_of_speech' => isset($assoc_args['where-pos']) ? (string) $assoc_args['where-pos'] : '',
        ]);

        $resume_path = isset($assoc_args['resume-file']) ? trim((string) $assoc_args['resume-file']) : '';
        $resume_state = ll_tools_cli_get_resume_state($resume_path);
        if ($resume_path !== '') {
            $rows = array_values(array_filter($rows, static function (array $row) use ($resume_state): bool {
                return !ll_tools_cli_resume_has_processed($resume_state, (int) ($row['word_id'] ?? 0));
            }));
        }

        $offset = isset($assoc_args['offset']) ? max(0, (int) $assoc_args['offset']) : 0;
        $limit = isset($assoc_args['limit']) ? max(0, (int) $assoc_args['limit']) : 0;
        $rows = ll_tools_cli_slice_rows($rows, $offset, $limit);

        $dry_run = !empty($assoc_args['dry-run']);
        $summary = [
            'generated_at_gmt' => gmdate('c'),
            'dry_run' => $dry_run,
            'wordset' => [
                'id' => (int) $wordset_term->term_id,
                'slug' => (string) $wordset_term->slug,
                'name' => (string) $wordset_term->name,
            ],
            'filters' => [
                'category' => $category_spec,
                'word' => $word_spec,
                'where_missing' => $missing_fields,
                'where_pos' => isset($assoc_args['where-pos']) ? sanitize_title((string) $assoc_args['where-pos']) : '',
                'limit' => $limit,
                'offset' => $offset,
                'resume_file' => $resume_path,
            ],
            'set' => $set_args,
            'matched_count' => count($rows),
            'matched_rows' => ll_tools_cli_prepare_word_rows_for_output($rows),
            'updated_count' => 0,
            'updated' => [],
            'errors' => [],
        ];

        $format = isset($assoc_args['format']) ? sanitize_key((string) $assoc_args['format']) : 'table';
        if ($dry_run) {
            if (!empty($assoc_args['summary-file'])) {
                $write_result = ll_tools_cli_write_json_file((string) $assoc_args['summary-file'], $summary);
                if (is_wp_error($write_result)) {
                    WP_CLI::warning($write_result->get_error_message());
                }
            }

            if ($format === 'count') {
                WP_CLI::line((string) count($rows));
                return;
            }

            if ($format === 'json') {
                WP_CLI::line(ll_tools_cli_json_encode($summary));
                return;
            }

            \WP_CLI\Utils\format_items($format, ll_tools_cli_prepare_word_rows_for_output($rows), [
                'word_id',
                'word_slug',
                'word_text',
                'word_translation',
                'category_slug',
                'part_of_speech',
                'grammatical_gender',
                'missing_fields',
            ]);
            return;
        }

        foreach ($rows as $row) {
            $word_id = (int) ($row['word_id'] ?? 0);
            if ($word_id <= 0) {
                continue;
            }

            $update_result = ll_tools_cli_apply_word_field_update(
                (int) $wordset_term->term_id,
                $word_id,
                (string) $set_args['field'],
                (string) $set_args['value']
            );

            if (is_wp_error($update_result)) {
                $summary['errors'][] = [
                    'word_id' => $word_id,
                    'word_slug' => (string) ($row['word_slug'] ?? ''),
                    'message' => $update_result->get_error_message(),
                ];
                WP_CLI::warning(sprintf(
                    '%s (%d): %s',
                    (string) ($row['word_slug'] ?? __('unknown', 'll-tools-text-domain')),
                    $word_id,
                    $update_result->get_error_message()
                ));
                continue;
            }

            $summary['updated'][] = [
                'word_id' => (int) ($update_result['word_id'] ?? 0),
                'word_slug' => (string) ($update_result['word_slug'] ?? ''),
                'changed' => !empty($update_result['changed']),
                'changed_keys' => array_values(array_map('strval', (array) ($update_result['changed_keys'] ?? []))),
                'before' => ll_tools_cli_prepare_word_rows_for_output([(array) ($update_result['before'] ?? [])])[0] ?? [],
                'after' => ll_tools_cli_prepare_word_rows_for_output([(array) ($update_result['after'] ?? [])])[0] ?? [],
            ];
            if (!empty($update_result['changed'])) {
                $summary['updated_count']++;
            }

            if ($resume_path !== '') {
                $resume_state['wordset_id'] = (int) $wordset_term->term_id;
                $resume_state['set'] = $set_args;
                $resume_state['filters'] = $summary['filters'];
                $resume_result = ll_tools_cli_resume_mark_processed($resume_path, $resume_state, $word_id);
                if (is_wp_error($resume_result)) {
                    WP_CLI::warning($resume_result->get_error_message());
                }
            }
        }

        if (!empty($assoc_args['summary-file'])) {
            $write_result = ll_tools_cli_write_json_file((string) $assoc_args['summary-file'], $summary);
            if (is_wp_error($write_result)) {
                WP_CLI::warning($write_result->get_error_message());
            }
        }

        if ($format === 'count') {
            WP_CLI::line((string) $summary['updated_count']);
            return;
        }

        if ($format === 'json') {
            WP_CLI::line(ll_tools_cli_json_encode($summary));
            return;
        }

        $updated_rows = array_map(static function (array $row): array {
            $after = (array) ($row['after'] ?? []);
            return [
                'word_id' => (int) ($row['word_id'] ?? 0),
                'word_slug' => (string) ($row['word_slug'] ?? ''),
                'changed' => !empty($row['changed']) ? 'yes' : 'no',
                'changed_keys' => implode(',', array_map('strval', (array) ($row['changed_keys'] ?? []))),
                'part_of_speech' => (string) ($after['part_of_speech'] ?? ''),
                'grammatical_gender' => (string) ($after['grammatical_gender'] ?? ''),
                'grammatical_plurality' => (string) ($after['grammatical_plurality'] ?? ''),
                'verb_tense' => (string) ($after['verb_tense'] ?? ''),
                'verb_mood' => (string) ($after['verb_mood'] ?? ''),
                'word_translation' => (string) ($after['word_translation'] ?? ''),
                'word_note' => (string) ($after['word_note'] ?? ''),
                'dictionary_entry_title' => (string) ($after['dictionary_entry_title'] ?? ''),
            ];
        }, (array) $summary['updated']);

        \WP_CLI\Utils\format_items($format, $updated_rows, [
            'word_id',
            'word_slug',
            'changed',
            'changed_keys',
            'part_of_speech',
            'grammatical_gender',
            'grammatical_plurality',
            'verb_tense',
            'verb_mood',
            'word_translation',
            'dictionary_entry_title',
        ]);
    }

    /**
     * Migrate a bounded page of legacy post-based lessons into content lessons.
     *
     * The command is dry-run by default. Run the lessons phase before
     * relations, then migrate user completions in resumable user-ID pages.
     *
     * ## OPTIONS
     *
     * <wordset>
     * : Target word set slug, name, or ID.
     *
     * [--phase=<phase>]
     * : lessons, relations, or completions. Default: lessons.
     *
     * [--categories=<categories>]
     * : Comma-separated source post category IDs, slugs, or names.
     *
     * [--source-ids=<ids>]
     * : Explicit comma-separated source post IDs.
     *
     * [--exclude-source-ids=<ids>]
     * : Source post IDs to leave on their existing editorial URLs.
     *
     * [--after=<id>]
     * : Resume after this source post or user ID.
     *
     * [--run-id=<uuid>]
     * : Completion audit run ID emitted by the first applied page. Required
     *   with --phase=completions when --after is greater than zero.
     *
     * [--limit=<number>]
     * : Batch size from 1 to 500. Default: 100.
     *
     * [--status=<status>]
     * : Target status for new lessons. Existing lesson status is preserved
     *   unless this option is supplied. Allowed: draft, publish, pending,
     *   private.
     *
     * [--show-in-mix=<0|1>]
     * : Surface migrated lessons in the category-driven lesson mix. Default: 0.
     *
     * [--retained-source]
     * : Create a published compatibility-only target while retaining the
     *   source post body and URL. Lessons phase only; requires one to 20
     *   explicit source IDs, --status=publish, --show-in-mix=0, no category
     *   scope, and --limit of 20 or less.
     *
     * [--apply]
     * : Persist the planned changes.
     *
     * [--format=<format>]
     * : json or table. Default: json.
     *
     * ## EXAMPLES
     *
     *     wp ll-tools legacy-lessons-migrate turkish --categories=grammar,culture
     *     wp ll-tools legacy-lessons-migrate turkish --phase=lessons --categories=4,10 --apply
     *     wp ll-tools legacy-lessons-migrate turkish --phase=relations --categories=4,10 --apply
     *     wp ll-tools legacy-lessons-migrate turkish --phase=completions --after=0 --limit=100 --apply
     *     wp ll-tools legacy-lessons-migrate vocab --phase=lessons --source-ids=3797 --retained-source --status=publish --show-in-mix=0 --limit=1
     */
    public function legacy_lessons_migrate(array $args, array $assoc_args): void {
        if (!function_exists('ll_tools_migrate_legacy_content_lessons_batch')) {
            WP_CLI::error('Legacy lesson migration is unavailable.');
        }
        $wordset_spec = isset($args[0]) ? (string) $args[0] : '';
        $wordset_term = ll_tools_cli_resolve_wordset_term($wordset_spec);
        if (is_wp_error($wordset_term)) {
            WP_CLI::error($wordset_term->get_error_message());
        }

        $phase = isset($assoc_args['phase'])
            ? sanitize_key((string) $assoc_args['phase'])
            : 'lessons';
        $category_ids = [];
        if (!empty($assoc_args['categories'])) {
            $category_ids = ll_tools_resolve_legacy_lesson_category_ids(
                (string) $assoc_args['categories']
            );
            if (is_wp_error($category_ids)) {
                WP_CLI::error($category_ids->get_error_message());
            }
        }
        $source_ids = [];
        if (!empty($assoc_args['source-ids'])) {
            $source_ids = preg_split('/\s*,\s*/', (string) $assoc_args['source-ids']);
        }
        $exclude_source_ids = [];
        if (!empty($assoc_args['exclude-source-ids'])) {
            $exclude_source_ids = preg_split(
                '/\s*,\s*/',
                (string) $assoc_args['exclude-source-ids']
            );
        }

        $migration_args = [
            'phase' => $phase,
            'wordset_id' => (int) $wordset_term->term_id,
            'category_ids' => $category_ids,
            'source_ids' => $source_ids,
            'exclude_source_ids' => $exclude_source_ids,
            'after_id' => isset($assoc_args['after']) ? absint($assoc_args['after']) : 0,
            'run_id' => isset($assoc_args['run-id'])
                ? sanitize_text_field((string) $assoc_args['run-id'])
                : '',
            'limit' => isset($assoc_args['limit']) ? absint($assoc_args['limit']) : 100,
            'limit_was_explicit' => array_key_exists('limit', $assoc_args),
            'limit_raw' => isset($assoc_args['limit'])
                ? (string) $assoc_args['limit']
                : '',
            'show_in_mix' => isset($assoc_args['show-in-mix'])
                && (string) $assoc_args['show-in-mix'] === '1',
            'show_in_mix_was_explicit' => array_key_exists(
                'show-in-mix',
                $assoc_args
            ),
            'show_in_mix_raw' => isset($assoc_args['show-in-mix'])
                ? (string) $assoc_args['show-in-mix']
                : '',
            'retained_source' => !empty($assoc_args['retained-source']),
            'apply' => !empty($assoc_args['apply']),
        ];
        if (array_key_exists('status', $assoc_args)) {
            $migration_args['status'] = (string) $assoc_args['status'];
        }
        $result = ll_tools_migrate_legacy_content_lessons_batch($migration_args);
        if (is_wp_error($result)) {
            WP_CLI::error($result->get_error_message());
        }

        foreach ((array) ($result['errors'] ?? []) as $error) {
            WP_CLI::warning((string) $error);
        }
        $apply_failed = !empty($migration_args['apply'])
            && !empty($result['errors']);
        $format = isset($assoc_args['format'])
            ? sanitize_key((string) $assoc_args['format'])
            : 'json';
        if ($format === 'table') {
            $row = $result;
            foreach ($row as $key => $value) {
                if (is_array($value)) {
                    $row[$key] = implode('; ', array_map('strval', $value));
                } elseif (is_bool($value)) {
                    $row[$key] = $value ? 'yes' : 'no';
                }
            }
            \WP_CLI\Utils\format_items('table', [$row], array_keys($row));
        } else {
            WP_CLI::line(ll_tools_cli_json_encode($result));
        }

        if ($apply_failed) {
            $failed_ids = array_values(array_filter(array_map(
                'absint',
                (array) ($result['failed_source_ids'] ?? [])
            )));
            $retry_hint = empty($failed_ids)
                ? ''
                : ' Retry with --source-ids=' . implode(',', $failed_ids) . '.';
            WP_CLI::error(
                'The applied migration page had errors and was not complete.'
                . $retry_hint
            );
        }
    }

    /**
     * Dump a machine-readable word set report for automation and health checks.
     *
     * ## OPTIONS
     *
     * <wordset>
     * : Word set slug, name, or ID.
     *
     * [--category=<category>]
     * : Limit report counts to one category slug or name.
     *
     * [--format=<format>]
     * : Output format: json or yaml.
     *
     * [--summary-file=<path>]
     * : Optional JSON report output path.
     *
     * ## EXAMPLES
     *
     *     wp ll-tools wordset-report spanish
     *     wp ll-tools wordset-report spanish --summary-file=tmp/spanish-report.json
     */
    public function wordset_report(array $args, array $assoc_args): void {
        [$wordset_spec] = $args;
        $wordset_term = ll_tools_cli_resolve_wordset_term($wordset_spec);
        if (is_wp_error($wordset_term)) {
            WP_CLI::error($wordset_term->get_error_message());
        }

        $category_spec = isset($assoc_args['category']) ? (string) $assoc_args['category'] : '';
        $report = ll_tools_cli_build_wordset_report((int) $wordset_term->term_id, $category_spec);
        if (!empty($report['error'])) {
            WP_CLI::error((string) $report['error']);
        }

        if (!empty($assoc_args['summary-file'])) {
            $write_result = ll_tools_cli_write_json_file((string) $assoc_args['summary-file'], $report);
            if (is_wp_error($write_result)) {
                WP_CLI::warning($write_result->get_error_message());
            }
        }

        $format = isset($assoc_args['format']) ? sanitize_key((string) $assoc_args['format']) : 'json';
        if ($format === 'yaml' && function_exists('\WP_CLI\Utils\yaml_emit')) {
            WP_CLI::line(\WP_CLI\Utils\yaml_emit($report));
            return;
        }

        WP_CLI::line(ll_tools_cli_json_encode($report));
    }
}
