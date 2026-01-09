<?php
// /includes/lib/word-option-rules.php
if (!defined('WPINC')) { die; }

function ll_tools_get_word_option_rules_store(): array {
    $raw = get_option('ll_tools_word_option_rules', []);
    return is_array($raw) ? $raw : [];
}

function ll_tools_normalize_word_option_rules(array $rules): array {
    $out = [
        'groups' => [],
        'pairs' => [],
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

    $pairs_raw = $rules['pairs'] ?? [];
    $pairs = [];
    if (is_array($pairs_raw)) {
        foreach ($pairs_raw as $pair) {
            if (!is_array($pair) || count($pair) < 2) {
                continue;
            }
            $ids = array_values(array_filter(array_map('intval', $pair), function ($id) {
                return $id > 0;
            }));
            if (count($ids) < 2) {
                continue;
            }
            $a = (int) $ids[0];
            $b = (int) $ids[1];
            if ($a === $b) {
                continue;
            }
            if ($a > $b) {
                $tmp = $a;
                $a = $b;
                $b = $tmp;
            }
            $pairs[$a . '|' . $b] = [$a, $b];
        }
    }
    $out['pairs'] = array_values($pairs);

    return $out;
}

function ll_tools_get_word_option_rules(int $wordset_id, int $category_id): array {
    $wordset_id = (int) $wordset_id;
    $category_id = (int) $category_id;
    if ($wordset_id <= 0 || $category_id <= 0) {
        return ['groups' => [], 'pairs' => []];
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
                $group_map[$word_id] = $label;
            }
        }
    }

    $blocked_map = [];
    foreach ($rules['pairs'] as $pair) {
        $a = (int) ($pair[0] ?? 0);
        $b = (int) ($pair[1] ?? 0);
        if ($a <= 0 || $b <= 0 || $a === $b) {
            continue;
        }
        if (!isset($blocked_map[$a])) {
            $blocked_map[$a] = [];
        }
        if (!isset($blocked_map[$b])) {
            $blocked_map[$b] = [];
        }
        $blocked_map[$a][$b] = true;
        $blocked_map[$b][$a] = true;
    }

    $blocked_list = [];
    foreach ($blocked_map as $word_id => $blocked) {
        $blocked_list[$word_id] = array_values(array_map('intval', array_keys($blocked)));
    }

    return [
        'groups' => $rules['groups'],
        'pairs' => $rules['pairs'],
        'group_map' => $group_map,
        'blocked_map' => $blocked_list,
    ];
}

function ll_tools_update_word_option_rules(int $wordset_id, int $category_id, array $groups, array $pairs): bool {
    $wordset_id = (int) $wordset_id;
    $category_id = (int) $category_id;
    if ($wordset_id <= 0 || $category_id <= 0) {
        return false;
    }

    $store = ll_tools_get_word_option_rules_store();
    $normalized = ll_tools_normalize_word_option_rules([
        'groups' => $groups,
        'pairs' => $pairs,
    ]);

    if (empty($normalized['groups']) && empty($normalized['pairs'])) {
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
