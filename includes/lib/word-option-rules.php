<?php
// /includes/lib/word-option-rules.php
if (!defined('WPINC')) { die; }

function ll_tools_get_word_option_rules_store(): array {
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
    $category_id = (int) $category_id;
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
    $category_id = (int) $category_id;
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
