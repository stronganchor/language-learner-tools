<?php
if (!defined('WPINC')) { die; }

/**
 * Polyfill array_is_list() for PHP 8.0 runtimes.
 */
function ll_tools_array_is_list(array $values): bool {
    if (function_exists('array_is_list')) {
        return array_is_list($values);
    }

    $expected_key = 0;
    foreach ($values as $key => $_value) {
        if ($key !== $expected_key) {
            return false;
        }

        $expected_key++;
    }

    return true;
}
