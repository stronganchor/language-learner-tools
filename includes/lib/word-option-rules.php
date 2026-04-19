<?php
// /includes/lib/word-option-rules.php
if (!defined('WPINC')) { die; }

function ll_tools_resolve_word_option_rules_category_id(int $wordset_id, int $category_id, bool $create_missing = false): int {
    $wordset_id = (int) $wordset_id;
    $category_id = (int) $category_id;
    if ($category_id <= 0) {
        return 0;
    }

    if (function_exists('ll_tools_get_effective_category_id_for_wordset')) {
        $resolved_category_id = (int) ll_tools_get_effective_category_id_for_wordset($category_id, $wordset_id, $create_missing);
        if ($resolved_category_id > 0) {
            return $resolved_category_id;
        }
    }

    return $category_id;
}

function ll_tools_merge_word_option_rules_payloads(array $left, array $right): array {
    $left = ll_tools_normalize_word_option_rules($left);
    $right = ll_tools_normalize_word_option_rules($right);

    $groups = [];
    $group_keys = [];
    foreach (array_merge($left['groups'], $right['groups']) as $group) {
        $label = trim((string) ($group['label'] ?? ''));
        $word_ids = array_values(array_unique(array_filter(array_map('intval', (array) ($group['word_ids'] ?? [])), static function (int $word_id): bool {
            return $word_id > 0;
        })));
        if ($label === '' || empty($word_ids)) {
            continue;
        }

        sort($word_ids, SORT_NUMERIC);
        $group_key = $label . '|' . implode(',', $word_ids);
        if (isset($group_keys[$group_key])) {
            continue;
        }

        $group_keys[$group_key] = true;
        $groups[] = [
            'label' => $label,
            'word_ids' => $word_ids,
        ];
    }

    return [
        'groups' => $groups,
        'pairs' => ll_tools_normalize_word_option_pair_list(array_merge($left['pairs'], $right['pairs'])),
        'similar_image_overrides' => ll_tools_normalize_word_option_pair_list(array_merge($left['similar_image_overrides'], $right['similar_image_overrides'])),
    ];
}

function ll_tools_word_option_rules_repair_store_array_for_isolation(array $store, bool $create_missing = false): array {
    if (empty($store)) {
        return [
            'store' => [],
            'changed' => false,
            'repaired_scopes' => 0,
        ];
    }

    $rebuilt_store = [];
    $changed = false;
    $repaired_scopes = 0;

    foreach ($store as $raw_wordset_id => $scopes) {
        $wordset_id = (int) $raw_wordset_id;
        if ($wordset_id <= 0 || !is_array($scopes)) {
            continue;
        }

        foreach ($scopes as $raw_category_id => $rules) {
            $category_id = (int) $raw_category_id;
            if ($category_id <= 0 || !is_array($rules)) {
                continue;
            }

            $target_category_id = ll_tools_resolve_word_option_rules_category_id($wordset_id, $category_id, $create_missing);
            if ($target_category_id <= 0) {
                $target_category_id = $category_id;
            }

            $normalized_rules = ll_tools_normalize_word_option_rules($rules);
            if (!isset($rebuilt_store[$wordset_id])) {
                $rebuilt_store[$wordset_id] = [];
            }

            if (isset($rebuilt_store[$wordset_id][$target_category_id])) {
                $rebuilt_store[$wordset_id][$target_category_id] = ll_tools_merge_word_option_rules_payloads(
                    $rebuilt_store[$wordset_id][$target_category_id],
                    $normalized_rules
                );
                if ($target_category_id !== $category_id) {
                    $changed = true;
                }
                continue;
            }

            $rebuilt_store[$wordset_id][$target_category_id] = $normalized_rules;
            if ($target_category_id !== $category_id) {
                $changed = true;
                $repaired_scopes++;
            }
        }
    }

    return [
        'store' => $rebuilt_store,
        'changed' => $changed || $rebuilt_store !== $store,
        'repaired_scopes' => $repaired_scopes,
    ];
}

function ll_tools_repair_word_option_rules_store_for_isolation(bool $create_missing = false): int {
    if (!function_exists('ll_tools_is_wordset_isolation_enabled') || !ll_tools_is_wordset_isolation_enabled()) {
        return 0;
    }

    $raw_store = get_option('ll_tools_word_option_rules', []);
    if (!is_array($raw_store) || empty($raw_store)) {
        return 0;
    }

    $repair = ll_tools_word_option_rules_repair_store_array_for_isolation($raw_store, $create_missing);
    if (!empty($repair['changed'])) {
        update_option('ll_tools_word_option_rules', $repair['store'], false);
    }

    return (int) ($repair['repaired_scopes'] ?? 0);
}

function ll_tools_get_word_option_rules_store(): array {
    static $did_isolation_repair = false;

    if (!$did_isolation_repair && function_exists('ll_tools_is_wordset_isolation_enabled') && ll_tools_is_wordset_isolation_enabled()) {
        ll_tools_repair_word_option_rules_store_for_isolation(true);
        $did_isolation_repair = true;
    }

    $raw = get_option('ll_tools_word_option_rules', []);
    return is_array($raw) ? $raw : [];
}

function ll_tools_normalize_word_option_pair_word_ids($pair): array {
    if (!is_array($pair)) {
        return [0, 0];
    }

    if (isset($pair['word_ids']) && is_array($pair['word_ids'])) {
        $ids_raw = $pair['word_ids'];
    } elseif (array_key_exists('a', $pair) || array_key_exists('b', $pair)) {
        $ids_raw = [$pair['a'] ?? 0, $pair['b'] ?? 0];
    } else {
        $ids_raw = $pair;
    }

    $ids = array_values(array_filter(array_map('intval', (array) $ids_raw), static function (int $id): bool {
        return $id > 0;
    }));
    if (count($ids) < 2) {
        return [0, 0];
    }

    $a = (int) $ids[0];
    $b = (int) $ids[1];
    if ($a <= 0 || $b <= 0 || $a === $b) {
        return [0, 0];
    }

    if ($a > $b) {
        $tmp = $a;
        $a = $b;
        $b = $tmp;
    }

    return [$a, $b];
}

function ll_tools_normalize_word_option_pair_recording_type_list($types_raw): array {
    if (!is_array($types_raw)) {
        return [];
    }

    $types = array_values(array_filter(array_map(static function ($type): string {
        return sanitize_key(str_replace([' ', '_'], '-', (string) $type));
    }, $types_raw), static function (string $type): bool {
        return $type !== '';
    }));
    $types = array_values(array_unique($types));

    if (empty($types)) {
        return [];
    }

    if (function_exists('ll_sort_recording_type_slugs')) {
        return ll_sort_recording_type_slugs($types);
    }

    if (count($types) > 1) {
        usort($types, static function (string $left, string $right): int {
            if (function_exists('ll_compare_recording_type_slugs')) {
                return (int) ll_compare_recording_type_slugs($left, $right);
            }

            return strnatcasecmp($left, $right);
        });
    }

    return $types;
}

function ll_tools_get_word_option_rule_recording_type_slugs(): array {
    $types = [];

    if (function_exists('ll_tools_get_main_recording_types')) {
        $types = array_merge($types, (array) ll_tools_get_main_recording_types());
    }

    $terms = get_terms([
        'taxonomy' => 'recording_type',
        'hide_empty' => false,
    ]);
    if (!is_wp_error($terms) && !empty($terms)) {
        foreach ($terms as $term) {
            if (!($term instanceof WP_Term)) {
                continue;
            }

            $slug = sanitize_key((string) $term->slug);
            if ($slug !== '') {
                $types[] = $slug;
            }
        }
    }

    return ll_tools_normalize_word_option_pair_recording_type_list($types);
}

function ll_tools_normalize_word_option_pair_list($pairs_raw): array {
    $pairs = [];
    if (!is_array($pairs_raw)) {
        return [];
    }

    foreach ($pairs_raw as $pair) {
        if (!is_array($pair)) {
            continue;
        }

        [$a, $b] = ll_tools_normalize_word_option_pair_word_ids($pair);
        if ($a <= 0 || $b <= 0) {
            continue;
        }

        $unblocked_recording_types = [];
        if (isset($pair['unblocked_recording_types']) && is_array($pair['unblocked_recording_types'])) {
            $unblocked_recording_types = $pair['unblocked_recording_types'];
        } elseif (isset($pair['excluded_recording_types']) && is_array($pair['excluded_recording_types'])) {
            $unblocked_recording_types = $pair['excluded_recording_types'];
        } elseif (isset($pair['recording_type_exclusions']) && is_array($pair['recording_type_exclusions'])) {
            $unblocked_recording_types = $pair['recording_type_exclusions'];
        }

        $pairs[$a . '|' . $b] = [
            'word_ids' => [$a, $b],
            'unblocked_recording_types' => ll_tools_normalize_word_option_pair_recording_type_list($unblocked_recording_types),
        ];
    }

    return array_values($pairs);
}

function ll_tools_normalize_word_option_rules(array $rules): array {
    $out = [
        'groups' => [],
        'pairs' => [],
        'similar_image_overrides' => [],
    ];

    $groups_raw = $rules['groups'] ?? [];
    if (is_array($groups_raw)) {
        foreach ($groups_raw as $key => $group) {
            $label = '';
            $word_ids = [];
            if (is_array($group) && isset($group['word_ids'])) {
                $label = isset($group['label']) ? sanitize_text_field($group['label']) : '';
                $word_ids = $group['word_ids'];
            } elseif (is_string($key) && is_array($group)) {
                $label = sanitize_text_field($key);
                $word_ids = $group;
            }

            $label = trim($label);
            if ($label === '' || !is_array($word_ids)) {
                continue;
            }

            $ids = array_values(array_unique(array_filter(array_map('intval', $word_ids), function ($id) {
                return $id > 0;
            })));
            if (empty($ids)) {
                continue;
            }

            $out['groups'][] = [
                'label' => $label,
                'word_ids' => $ids,
            ];
        }
    }

    $out['pairs'] = ll_tools_normalize_word_option_pair_list($rules['pairs'] ?? []);
    $out['similar_image_overrides'] = ll_tools_normalize_word_option_pair_list($rules['similar_image_overrides'] ?? []);

    return $out;
}

function ll_tools_get_word_option_rules(int $wordset_id, int $category_id): array {
    $wordset_id = (int) $wordset_id;
    $category_id = ll_tools_resolve_word_option_rules_category_id($wordset_id, (int) $category_id, true);
    if ($wordset_id <= 0 || $category_id <= 0) {
        return ['groups' => [], 'pairs' => [], 'similar_image_overrides' => []];
    }

    $store = ll_tools_get_word_option_rules_store();
    $raw = $store[$wordset_id][$category_id] ?? [];
    return ll_tools_normalize_word_option_rules(is_array($raw) ? $raw : []);
}

function ll_tools_get_word_option_maps(int $wordset_id, int $category_id): array {
    $rules = ll_tools_get_word_option_rules($wordset_id, $category_id);
    $group_map = [];
    foreach ($rules['groups'] as $group) {
        $label = (string) ($group['label'] ?? '');
        $word_ids = isset($group['word_ids']) && is_array($group['word_ids']) ? $group['word_ids'] : [];
        foreach ($word_ids as $word_id) {
            $word_id = (int) $word_id;
            if ($word_id > 0) {
                if (!isset($group_map[$word_id])) {
                    $group_map[$word_id] = [];
                }
                $group_map[$word_id][] = $label;
            }
        }
    }

    foreach ($group_map as $word_id => $labels) {
        $labels = array_values(array_unique(array_filter(array_map('strval', (array) $labels), function ($val) {
            return $val !== '';
        })));
        $group_map[$word_id] = $labels;
    }

    $blocked_map = [];
    $blocked_map_by_recording_type = [];
    $all_recording_types = ll_tools_get_word_option_rule_recording_type_slugs();
    foreach ($rules['pairs'] as $pair) {
        [$a, $b] = ll_tools_normalize_word_option_pair_word_ids($pair);
        if ($a <= 0 || $b <= 0 || $a === $b) {
            continue;
        }

        $unblocked_recording_types = ll_tools_normalize_word_option_pair_recording_type_list($pair['unblocked_recording_types'] ?? []);
        if (empty($unblocked_recording_types)) {
            if (!isset($blocked_map[$a])) {
                $blocked_map[$a] = [];
            }
            if (!isset($blocked_map[$b])) {
                $blocked_map[$b] = [];
            }
            $blocked_map[$a][$b] = true;
            $blocked_map[$b][$a] = true;
            continue;
        }

        if (empty($all_recording_types)) {
            continue;
        }

        $blocked_recording_types = array_values(array_diff($all_recording_types, $unblocked_recording_types));
        foreach ($blocked_recording_types as $recording_type) {
            if (!isset($blocked_map_by_recording_type[$a])) {
                $blocked_map_by_recording_type[$a] = [];
            }
            if (!isset($blocked_map_by_recording_type[$b])) {
                $blocked_map_by_recording_type[$b] = [];
            }
            if (!isset($blocked_map_by_recording_type[$a][$recording_type])) {
                $blocked_map_by_recording_type[$a][$recording_type] = [];
            }
            if (!isset($blocked_map_by_recording_type[$b][$recording_type])) {
                $blocked_map_by_recording_type[$b][$recording_type] = [];
            }
            $blocked_map_by_recording_type[$a][$recording_type][$b] = true;
            $blocked_map_by_recording_type[$b][$recording_type][$a] = true;
        }
    }

    $blocked_list = [];
    foreach ($blocked_map as $word_id => $blocked) {
        $blocked_list[$word_id] = array_values(array_map('intval', array_keys($blocked)));
    }

    $blocked_list_by_recording_type = [];
    foreach ($blocked_map_by_recording_type as $word_id => $types) {
        foreach ((array) $types as $recording_type => $blocked) {
            $blocked_list_by_recording_type[$word_id][$recording_type] = array_values(array_map('intval', array_keys((array) $blocked)));
        }
    }

    $similar_image_override_map = [];
    foreach ($rules['similar_image_overrides'] as $pair) {
        [$a, $b] = ll_tools_normalize_word_option_pair_word_ids($pair);
        if ($a <= 0 || $b <= 0 || $a === $b) {
            continue;
        }
        if ($a > $b) {
            $tmp = $a;
            $a = $b;
            $b = $tmp;
        }
        $similar_image_override_map[$a . '|' . $b] = true;
    }

    return [
        'groups' => $rules['groups'],
        'pairs' => $rules['pairs'],
        'similar_image_overrides' => $rules['similar_image_overrides'],
        'group_map' => $group_map,
        'blocked_map' => $blocked_list,
        'blocked_map_by_recording_type' => $blocked_list_by_recording_type,
        'similar_image_override_map' => $similar_image_override_map,
    ];
}

function ll_tools_update_word_option_rules(int $wordset_id, int $category_id, array $groups, array $pairs, array $similar_image_overrides = []): bool {
    $wordset_id = (int) $wordset_id;
    $category_id = ll_tools_resolve_word_option_rules_category_id($wordset_id, (int) $category_id, true);
    if ($wordset_id <= 0 || $category_id <= 0) {
        return false;
    }

    $store = ll_tools_get_word_option_rules_store();
    $normalized = ll_tools_normalize_word_option_rules([
        'groups' => $groups,
        'pairs' => $pairs,
        'similar_image_overrides' => $similar_image_overrides,
    ]);

    if (empty($normalized['groups']) && empty($normalized['pairs']) && empty($normalized['similar_image_overrides'])) {
        if (isset($store[$wordset_id][$category_id])) {
            unset($store[$wordset_id][$category_id]);
            if (empty($store[$wordset_id])) {
                unset($store[$wordset_id]);
            }
        }
    } else {
        if (!isset($store[$wordset_id]) || !is_array($store[$wordset_id])) {
            $store[$wordset_id] = [];
        }
        $store[$wordset_id][$category_id] = $normalized;
    }

    update_option('ll_tools_word_option_rules', $store, false);
    if (function_exists('ll_tools_bump_category_cache_version')) {
        ll_tools_bump_category_cache_version([$category_id]);
    }

    return true;
}
