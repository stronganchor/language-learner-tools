<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

final class WordsetButtonsShortcodeTest extends LL_Tools_TestCase
{
    private const ONE_PIXEL_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+yZ5kAAAAASUVORK5CYII=';

    protected function tearDown(): void
    {
        if (function_exists('ll_tools_purge_wordset_buttons_shortcode_cache')) {
            ll_tools_purge_wordset_buttons_shortcode_cache();
        }
        parent::tearDown();
    }

    public function test_shortcode_renders_viewable_wordsets_with_published_lesson_counts_only(): void
    {
        $public_term = wp_insert_term('Buttons Public Wordset', 'wordset');
        $private_term = wp_insert_term('Buttons Private Wordset', 'wordset');
        $empty_term = wp_insert_term('Buttons Empty Wordset', 'wordset');
        $draft_only_term = wp_insert_term('Buttons Draft Only Wordset', 'wordset');

        $this->assertIsArray($public_term);
        $this->assertIsArray($private_term);
        $this->assertIsArray($empty_term);
        $this->assertIsArray($draft_only_term);
        $this->assertFalse(is_wp_error($public_term));
        $this->assertFalse(is_wp_error($private_term));
        $this->assertFalse(is_wp_error($empty_term));
        $this->assertFalse(is_wp_error($draft_only_term));

        $public_term_id = (int) ($public_term['term_id'] ?? 0);
        $private_term_id = (int) ($private_term['term_id'] ?? 0);
        $empty_term_id = (int) ($empty_term['term_id'] ?? 0);
        $draft_only_term_id = (int) ($draft_only_term['term_id'] ?? 0);
        update_term_meta($private_term_id, LL_TOOLS_WORDSET_VISIBILITY_META_KEY, 'private');

        $this->createPublishedLessonForWordset($public_term_id, 'Public Buttons Lesson A');
        $this->createPublishedLessonForWordset($public_term_id, 'Public Buttons Lesson B');
        $this->createUnquizzablePublishedLessonForWordset($public_term_id, 'Public Buttons Unquizzable Lesson');
        $this->createPreviewOnlyLessonForWordset($public_term_id, 'Public Buttons Preview Lesson');
        $this->createPublishedLessonForWordset($private_term_id, 'Private Buttons Lesson');
        $this->createPreviewOnlyLessonForWordset($draft_only_term_id, 'Draft Only Buttons Preview Lesson');
        $this->createUnquizzablePublishedLessonForWordset($draft_only_term_id, 'Draft Only Buttons Unquizzable Lesson');

        $public_wordset = get_term($public_term_id, 'wordset');
        $private_wordset = get_term($private_term_id, 'wordset');
        $empty_wordset = get_term($empty_term_id, 'wordset');
        $draft_only_wordset = get_term($draft_only_term_id, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $public_wordset);
        $this->assertInstanceOf(WP_Term::class, $private_wordset);
        $this->assertInstanceOf(WP_Term::class, $empty_wordset);
        $this->assertInstanceOf(WP_Term::class, $draft_only_wordset);

        $lesson_counts = ll_tools_get_wordset_button_lesson_counts([$public_term_id, $draft_only_term_id, $empty_term_id]);
        $this->assertSame(2, (int) ($lesson_counts[$public_term_id] ?? 0));
        $this->assertSame(0, (int) ($lesson_counts[$draft_only_term_id] ?? 0));
        $this->assertSame(0, (int) ($lesson_counts[$empty_term_id] ?? 0));

        $html = do_shortcode('[ll_wordset_buttons]');

        $this->assertStringContainsString('ll-wordset-buttons-shortcode', $html);
        $this->assertStringContainsString('ll-study-btn', $html);
        $this->assertStringContainsString('ll-wordset-buttons-shortcode__count', $html);
        $this->assertStringContainsString($public_wordset->name, $html);
        $this->assertStringContainsString('2 lessons', $html);
        $this->assertStringContainsString(
            esc_url(ll_tools_get_wordset_page_view_url($public_wordset)),
            $html
        );
        $this->assertStringNotContainsString($private_wordset->name, $html);
        $this->assertStringNotContainsString($empty_wordset->name, $html);
        $this->assertStringNotContainsString($draft_only_wordset->name, $html);

        $this->assertTrue(wp_style_is('ll-wordset-pages-css', 'enqueued'));
        $this->assertTrue(wp_style_is('ll-tools-style', 'enqueued'));
    }

    public function test_shortcode_orders_wordsets_from_most_lessons_to_fewest(): void
    {
        $small_term = wp_insert_term('Buttons Small Wordset', 'wordset');
        $large_term = wp_insert_term('Buttons Large Wordset', 'wordset');
        $medium_term = wp_insert_term('Buttons Medium Wordset', 'wordset');

        $this->assertIsArray($small_term);
        $this->assertIsArray($large_term);
        $this->assertIsArray($medium_term);
        $this->assertFalse(is_wp_error($small_term));
        $this->assertFalse(is_wp_error($large_term));
        $this->assertFalse(is_wp_error($medium_term));

        $small_term_id = (int) ($small_term['term_id'] ?? 0);
        $large_term_id = (int) ($large_term['term_id'] ?? 0);
        $medium_term_id = (int) ($medium_term['term_id'] ?? 0);

        for ($index = 1; $index <= 4; $index++) {
            $this->createPublishedLessonForWordset($large_term_id, 'Buttons Large Lesson ' . $index);
        }
        for ($index = 1; $index <= 2; $index++) {
            $this->createPublishedLessonForWordset($medium_term_id, 'Buttons Medium Lesson ' . $index);
        }
        $this->createPublishedLessonForWordset($small_term_id, 'Buttons Small Lesson 1');

        $small_wordset = get_term($small_term_id, 'wordset');
        $large_wordset = get_term($large_term_id, 'wordset');
        $medium_wordset = get_term($medium_term_id, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $small_wordset);
        $this->assertInstanceOf(WP_Term::class, $large_wordset);
        $this->assertInstanceOf(WP_Term::class, $medium_wordset);

        $html = do_shortcode('[ll_wordset_buttons]');

        $large_pos = strpos($html, $large_wordset->name);
        $medium_pos = strpos($html, $medium_wordset->name);
        $small_pos = strpos($html, $small_wordset->name);

        $this->assertIsInt($large_pos);
        $this->assertIsInt($medium_pos);
        $this->assertIsInt($small_pos);
        $this->assertTrue($large_pos < $medium_pos);
        $this->assertTrue($medium_pos < $small_pos);
        $this->assertStringContainsString('4 lessons', $html);
        $this->assertStringContainsString('2 lessons', $html);
        $this->assertStringContainsString('1 lesson', $html);
    }

    public function test_shortcode_renders_configured_wordset_images(): void
    {
        $image_term = wp_insert_term('Buttons Image Wordset', 'wordset');
        $plain_term = wp_insert_term('Buttons Plain Wordset', 'wordset');

        $this->assertIsArray($image_term);
        $this->assertIsArray($plain_term);
        $this->assertFalse(is_wp_error($image_term));
        $this->assertFalse(is_wp_error($plain_term));

        $image_term_id = (int) ($image_term['term_id'] ?? 0);
        $plain_term_id = (int) ($plain_term['term_id'] ?? 0);
        $this->createPublishedLessonForWordset($image_term_id, 'Buttons Image Lesson');
        $this->createPublishedLessonForWordset($plain_term_id, 'Buttons Plain Lesson');

        $attachment_id = $this->createImageAttachment('wordset-button-image.png');
        update_term_meta($image_term_id, LL_TOOLS_WORDSET_BUTTON_IMAGE_ATTACHMENT_ID_META_KEY, $attachment_id);

        $html = do_shortcode('[ll_wordset_buttons]');

        $this->assertStringContainsString('Buttons Image Wordset', $html);
        $this->assertStringContainsString('Buttons Plain Wordset', $html);
        $this->assertStringContainsString('ll-wordset-buttons-shortcode__button--has-image', $html);
        $this->assertStringContainsString('ll-wordset-buttons-shortcode__image', $html);
        $this->assertSame(1, substr_count($html, 'll-wordset-buttons-shortcode__media'));
    }

    public function test_shortcode_returns_anonymous_cached_html_when_available(): void
    {
        wp_set_current_user(0);
        $cache_key = ll_tools_wordset_buttons_shortcode_cache_key([
            'class' => '',
            'hide_empty' => '0',
        ], 'll_wordset_buttons');
        $cached_html = '<div class="ll-wordset-buttons-shortcode"><a class="ll-wordset-buttons-shortcode__button">Cached wordsets</a></div>';
        ll_tools_wordset_buttons_shortcode_cache_set($cache_key, $cached_html);
        $stale_key = ll_tools_wordset_buttons_shortcode_stale_key([
            'class' => '',
            'hide_empty' => '0',
        ], 'll_wordset_buttons');
        $stable_payload = [
            'schema' => 1,
            'stored_at' => time() - 10,
            'html' => $cached_html,
        ];
        set_transient($stale_key, $stable_payload, HOUR_IN_SECONDS);

        $this->assertSame($cached_html, do_shortcode('[ll_wordset_buttons]'));
        $this->assertSame($stable_payload, get_transient($stale_key), 'A warm exact hit must not rewrite a still-valid LKG transient.');

        delete_transient($stale_key);
        $this->assertSame($cached_html, do_shortcode('[ll_wordset_buttons]'));
        $this->assertSame($cached_html, ll_tools_wordset_buttons_shortcode_stale_get($stale_key));

        ll_tools_purge_wordset_buttons_shortcode_cache();
        $this->assertFalse(get_transient($cache_key));
    }

    public function test_anonymous_epoch_rebuild_resumes_without_rescanning_completed_pairs(): void
    {
        wp_set_current_user(0);
        $first_term = wp_insert_term('Buttons Epoch First Wordset', 'wordset');
        $second_term = wp_insert_term('Buttons Epoch Second Wordset', 'wordset');
        $this->assertIsArray($first_term);
        $this->assertIsArray($second_term);
        $this->assertFalse(is_wp_error($first_term));
        $this->assertFalse(is_wp_error($second_term));

        $first_wordset_id = (int) ($first_term['term_id'] ?? 0);
        $second_wordset_id = (int) ($second_term['term_id'] ?? 0);
        for ($index = 1; $index <= 2; $index++) {
            $this->createPublishedLessonForWordset($first_wordset_id, 'Buttons Epoch First Lesson ' . $index);
            $this->createPublishedLessonForWordset($second_wordset_id, 'Buttons Epoch Second Lesson ' . $index);
        }

        $atts = ['class' => '', 'hide_empty' => '0'];
        $initial_html = do_shortcode('[ll_wordset_buttons]');
        $this->assertStringContainsString('Buttons Epoch First Wordset', $initial_html);
        $this->assertStringContainsString('Buttons Epoch Second Wordset', $initial_html);
        $this->assertStringContainsString('2 lessons', $initial_html);

        $initial_exact_key = ll_tools_wordset_buttons_shortcode_cache_key($atts, 'll_wordset_buttons');
        $initial_stale_key = ll_tools_wordset_buttons_shortcode_stale_key($atts, 'll_wordset_buttons');
        $this->assertSame($initial_html, ll_tools_wordset_buttons_shortcode_stale_get($initial_stale_key));

        $scanned_pairs = [];
        $capture_pair = static function (int $wordset_id, int $category_id) use (&$scanned_pairs): void {
            $scanned_pairs[] = $wordset_id . ':' . $category_id;
        };
        $batch_size = static function (): int {
            return 2;
        };
        add_action('ll_tools_wordset_buttons_shortcode_eligibility_pair_scanned', $capture_pair, 10, 2);
        add_filter('ll_tools_wordset_buttons_shortcode_eligibility_batch_size', $batch_size);
        try {
            ll_tools_bump_quiz_content_cache_epoch([$first_wordset_id, $second_wordset_id], true);
            $next_exact_key = ll_tools_wordset_buttons_shortcode_cache_key($atts, 'll_wordset_buttons');
            $this->assertNotSame($initial_exact_key, $next_exact_key);
            $this->assertSame($initial_stale_key, ll_tools_wordset_buttons_shortcode_stale_key($atts, 'll_wordset_buttons'));

            $first_rebuild_html = do_shortcode('[ll_wordset_buttons]');
            $this->assertStringContainsString(
                $initial_html,
                $first_rebuild_html,
                'An incomplete exact generation may wrap only the prior complete public render.'
            );
            $this->assertStringContainsString('data-ajax-action="ll_tools_wordset_buttons_status"', $first_rebuild_html);
            $this->assertFalse(get_transient($next_exact_key), 'Partial pair counts must never publish the current HTML generation.');

            $completed_html = do_shortcode('[ll_wordset_buttons]');
            $cached_html = do_shortcode('[ll_wordset_buttons]');
        } finally {
            remove_filter('ll_tools_wordset_buttons_shortcode_eligibility_batch_size', $batch_size);
            remove_action('ll_tools_wordset_buttons_shortcode_eligibility_pair_scanned', $capture_pair, 10);
        }

        $this->assertSame($initial_html, $completed_html);
        $this->assertSame($completed_html, $cached_html);
        $this->assertCount(4, $scanned_pairs);
        $this->assertCount(4, array_unique($scanned_pairs));
        foreach (array_count_values($scanned_pairs) as $scan_count) {
            $this->assertSame(1, $scan_count, 'A completed lesson/category pair must not be rescanned on the next anonymous render.');
        }
        $this->assertIsString(get_transient($next_exact_key));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_incomplete_anonymous_response_is_explicitly_not_cacheable(): void
    {
        wp_set_current_user(0);
        $this->assertFalse(defined('DONOTCACHEPAGE'));

        $html = ll_tools_wordset_buttons_shortcode_refresh_html(
            ['class' => 'isolated-cold-shell', 'hide_empty' => '0'],
            'll_wordset_buttons',
            ll_tools_wordset_buttons_shortcode_loading_html(['class' => 'isolated-cold-shell'])
        );

        $this->assertTrue(defined('DONOTCACHEPAGE'));
        $this->assertTrue(DONOTCACHEPAGE);
        $this->assertStringContainsString('data-ll-wordset-buttons-refresh', $html);
        $this->assertStringContainsString('data-status-token="', $html);
        $this->assertSame(
            'no-cache, no-store, must-revalidate, max-age=0, private',
            ll_tools_wordset_buttons_shortcode_incomplete_cache_control()
        );
    }

    public function test_cold_anonymous_rebuild_exposes_read_only_status_recovery_until_cards_are_complete(): void
    {
        wp_set_current_user(0);
        $term = wp_insert_term('Buttons Cold Wordset', 'wordset');
        $this->assertIsArray($term);
        $this->assertFalse(is_wp_error($term));
        $wordset_id = (int) ($term['term_id'] ?? 0);
        for ($index = 1; $index <= 3; $index++) {
            $this->createPublishedLessonForWordset($wordset_id, 'Buttons Cold Lesson ' . $index);
        }

        $atts = ['class' => 'homepage-wordsets', 'hide_empty' => '0'];
        $exact_key = ll_tools_wordset_buttons_shortcode_cache_key($atts, 'll_wordset_buttons');
        $stale_key = ll_tools_wordset_buttons_shortcode_stale_key($atts, 'll_wordset_buttons');
        $this->assertFalse(get_transient($exact_key));
        $this->assertSame('', ll_tools_wordset_buttons_shortcode_stale_get($stale_key));

        $scanned_pairs = [];
        $capture_pair = static function (int $scanned_wordset_id, int $category_id) use (&$scanned_pairs): void {
            $scanned_pairs[] = $scanned_wordset_id . ':' . $category_id;
        };
        $batch_size = static function (): int {
            return 1;
        };
        add_action('ll_tools_wordset_buttons_shortcode_eligibility_pair_scanned', $capture_pair, 10, 2);
        add_filter('ll_tools_wordset_buttons_shortcode_eligibility_batch_size', $batch_size);
        try {
            $first_html = do_shortcode('[ll_wordset_buttons class="homepage-wordsets"]');
            $this->assertFalse(get_transient($exact_key));

            $this->assertStringContainsString('ll-wordset-buttons-shortcode--loading', $first_html);
            $this->assertStringContainsString('homepage-wordsets', $first_html);
            $this->assertStringContainsString('data-ll-wordset-buttons-refresh', $first_html);
            $this->assertStringContainsString('data-ajax-action="ll_tools_wordset_buttons_status"', $first_html);
            $this->assertStringContainsString('data-status-token="', $first_html);
            $this->assertStringNotContainsString('data-shortcode-tag=', $first_html);
            $this->assertStringNotContainsString('data-shortcode-class=', $first_html);
            $this->assertStringNotContainsString('data-hide-empty=', $first_html);
            $this->assertStringNotContainsString('data-nonce=', $first_html);
            $this->assertSame(3, substr_count($first_html, 'll-wordset-buttons-shortcode__loading-card'));
            $this->assertTrue(wp_script_is('ll-tools-wordset-buttons-refresh', 'enqueued'));

            $this->assertSame(1, preg_match('/data-status-token="([^"]+)"/', $first_html, $token_match));
            $token = html_entity_decode((string) ($token_match[1] ?? ''), ENT_QUOTES, 'UTF-8');
            $token_error = '';
            $scope = ll_tools_wordset_buttons_shortcode_verify_status_token($token, $token_error);
            $this->assertSame('', $token_error);
            $this->assertSame([
                'tag' => 'll_wordset_buttons',
                'atts' => ['class' => 'homepage-wordsets', 'hide_empty' => '0'],
            ], $scope);

            $wordset_ids = [$wordset_id];
            $generation = ll_tools_wordset_button_counts_generation_key(
                $wordset_ids,
                ll_tools_wordset_button_quiz_min_word_count()
            );
            $state_key = ll_tools_wordset_button_counts_state_key($generation);
            $lock_key = ll_tools_wordset_button_count_lock_option($state_key);
            $lock_additions = 0;
            $capture_lock_addition = static function () use (&$lock_additions): void {
                $lock_additions++;
            };
            add_action('add_option_' . $lock_key, $capture_lock_addition);
            ll_tools_schedule_wordset_button_count_refresh($wordset_ids, false);
            $this->assertFalse(wp_next_scheduled(
                'll_tools_refresh_wordset_button_lesson_counts',
                [$wordset_ids, 0]
            ));

            $status_ip = '192.0.2.10';
            ll_tools_wordset_buttons_shortcode_reset_public_status_throttle($status_ip);
            $state_before_status = get_option($state_key, null);
            $scan_count_before_status = count($scanned_pairs);
            $status_response = $this->runWordsetButtonsStatusAjax([
                'token' => $token,
                'tag' => 'hostile_tag',
                'class' => 'hostile-class',
                'hide_empty' => '1',
            ], $status_ip);
            $this->assertTrue((bool) ($status_response['success'] ?? false));
            $status_payload = (array) ($status_response['data'] ?? []);
            $this->assertFalse($status_payload['complete']);
            $this->assertSame($scan_count_before_status, count($scanned_pairs));
            $this->assertSame($state_before_status, get_option($state_key, null));
            $this->assertSame(0, $lock_additions);
            $this->assertStringContainsString('homepage-wordsets', (string) $status_payload['html']);
            $this->assertStringNotContainsString('hostile-class', (string) $status_payload['html']);
            $this->assertStringNotContainsString(
                'data-ll-wordset-buttons-refresh',
                (string) $status_payload['html']
            );
            $first_schedule = wp_next_scheduled(
                'll_tools_refresh_wordset_button_lesson_counts',
                [$wordset_ids, 0]
            );
            $this->assertNotFalse($first_schedule);
            $this->assertSame(1, $this->countScheduledWordsetButtonRefreshes($wordset_ids, 0));

            $second_status_response = $this->runWordsetButtonsStatusAjax(['token' => $token], $status_ip);
            $this->assertTrue((bool) ($second_status_response['success'] ?? false));
            $this->assertSame($first_schedule, wp_next_scheduled(
                'll_tools_refresh_wordset_button_lesson_counts',
                [$wordset_ids, 0]
            ));
            $this->assertSame(1, $this->countScheduledWordsetButtonRefreshes($wordset_ids, 0));
            $this->assertSame($state_before_status, get_option($state_key, null));
            $this->assertSame($scan_count_before_status, count($scanned_pairs));
            $this->assertSame(0, $lock_additions);

            for ($attempt = 0; $attempt < 10 && empty($status_payload['complete']); $attempt++) {
                ll_tools_refresh_wordset_button_lesson_counts($wordset_ids, 0);
                $status_response = $this->runWordsetButtonsStatusAjax(['token' => $token], $status_ip);
                $this->assertTrue((bool) ($status_response['success'] ?? false));
                $status_payload = (array) ($status_response['data'] ?? []);
            }
        } finally {
            if (isset($status_ip)) {
                ll_tools_wordset_buttons_shortcode_reset_public_status_throttle($status_ip);
            }
            if (isset($lock_key, $capture_lock_addition)) {
                remove_action('add_option_' . $lock_key, $capture_lock_addition);
            }
            remove_filter('ll_tools_wordset_buttons_shortcode_eligibility_batch_size', $batch_size);
            remove_action('ll_tools_wordset_buttons_shortcode_eligibility_pair_scanned', $capture_pair, 10);
            ll_tools_schedule_wordset_button_count_refresh([$wordset_id], false);
        }

        $this->assertFalse(has_action(
            'wp_ajax_nopriv_ll_tools_wordset_buttons_refresh',
            'll_tools_wordset_buttons_shortcode_refresh_ajax'
        ));
        $this->assertNotFalse(has_action(
            'wp_ajax_nopriv_ll_tools_wordset_buttons_status',
            'll_tools_wordset_buttons_shortcode_status_ajax'
        ));
        $this->assertTrue((bool) ($status_payload['complete'] ?? false));
        $this->assertStringContainsString('Buttons Cold Wordset', (string) ($status_payload['html'] ?? ''));
        $this->assertStringContainsString('3 lessons', (string) ($status_payload['html'] ?? ''));
        $this->assertCount(3, $scanned_pairs);
        $this->assertCount(3, array_unique($scanned_pairs));
        $this->assertIsString(get_transient($exact_key));
    }

    public function test_public_status_tokens_are_tamper_evident_context_bound_and_rate_limited(): void
    {
        wp_set_current_user(0);
        $atts = ['class' => 'homepage-wordsets extra-class', 'hide_empty' => '1'];
        $token = ll_tools_wordset_buttons_shortcode_create_status_token($atts, 'll_wordset_buttons');
        $this->assertNotSame('', $token);

        $error = '';
        $this->assertSame([
            'tag' => 'll_wordset_buttons',
            'atts' => ['class' => 'homepage-wordsets extra-class', 'hide_empty' => '1'],
        ], ll_tools_wordset_buttons_shortcode_verify_status_token($token, $error));
        $this->assertSame('', $error);

        $rejected_scan_count = 0;
        $capture_rejected_scan = static function () use (&$rejected_scan_count): void {
            $rejected_scan_count++;
        };
        $cron_before_rejections = _get_cron_array();
        add_action('ll_tools_wordset_buttons_shortcode_eligibility_pair_scanned', $capture_rejected_scan);

        $tampered = substr($token, 0, -1) . (substr($token, -1) === 'A' ? 'B' : 'A');
        $this->assertNull(ll_tools_wordset_buttons_shortcode_verify_status_token($tampered, $error));
        $this->assertSame('invalid_status_token', $error);
        $tampered_response = $this->runWordsetButtonsStatusAjax(
            ['token' => $tampered],
            '192.0.2.20'
        );
        $this->assertFalse((bool) ($tampered_response['success'] ?? true));
        $this->assertSame(
            'invalid_status_token',
            (string) ($tampered_response['data']['code'] ?? '')
        );
        $this->assertSame(403, ll_tools_wordset_buttons_shortcode_status_error_http_code('invalid_status_token'));
        ll_tools_wordset_buttons_shortcode_reset_public_status_throttle('192.0.2.20');

        ll_tools_bump_wordset_buttons_shortcode_generation_epoch();
        $this->assertNull(ll_tools_wordset_buttons_shortcode_verify_status_token($token, $error));
        $this->assertSame('stale_status_token', $error);
        $stale_response = $this->runWordsetButtonsStatusAjax(['token' => $token], '192.0.2.21');
        $this->assertFalse((bool) ($stale_response['success'] ?? true));
        $this->assertSame('stale_status_token', (string) ($stale_response['data']['code'] ?? ''));
        $this->assertSame(409, ll_tools_wordset_buttons_shortcode_status_error_http_code('stale_status_token'));
        ll_tools_wordset_buttons_shortcode_reset_public_status_throttle('192.0.2.21');

        $scope = ll_tools_wordset_buttons_shortcode_public_scope($atts, 'll_wordset_buttons');
        $expired_payload = [
            'schema' => 1,
            'expires_at' => time() - 1,
            'tag' => $scope['tag'],
            'atts' => $scope['atts'],
            'context' => ll_tools_wordset_buttons_shortcode_cache_key($scope['atts'], $scope['tag']),
        ];
        $encoded = ll_tools_wordset_buttons_shortcode_base64url_encode((string) wp_json_encode($expired_payload));
        $expired_token = $encoded . '.' . ll_tools_wordset_buttons_shortcode_base64url_encode(
            hash_hmac('sha256', $encoded, wp_salt('nonce'), true)
        );
        $this->assertNull(ll_tools_wordset_buttons_shortcode_verify_status_token($expired_token, $error));
        $this->assertSame('expired_status_token', $error);
        $expired_response = $this->runWordsetButtonsStatusAjax(
            ['token' => $expired_token],
            '192.0.2.22'
        );
        $this->assertFalse((bool) ($expired_response['success'] ?? true));
        $this->assertSame(
            'expired_status_token',
            (string) ($expired_response['data']['code'] ?? '')
        );
        $this->assertSame(410, ll_tools_wordset_buttons_shortcode_status_error_http_code('expired_status_token'));
        ll_tools_wordset_buttons_shortcode_reset_public_status_throttle('192.0.2.22');
        remove_action('ll_tools_wordset_buttons_shortcode_eligibility_pair_scanned', $capture_rejected_scan);
        $this->assertSame(0, $rejected_scan_count);
        $this->assertSame($cron_before_rejections, _get_cron_array());

        $identifier = '192.0.2.23';
        $one_request = static function (): int {
            return 1;
        };
        $fresh_token = ll_tools_wordset_buttons_shortcode_create_status_token($atts, 'll_wordset_buttons');
        $fresh_exact_key = ll_tools_wordset_buttons_shortcode_cache_key($atts, 'll_wordset_buttons');
        set_transient(
            $fresh_exact_key,
            '<div class="ll-wordset-buttons-shortcode"><a class="ll-wordset-buttons-shortcode__button">Ready</a></div>',
            MINUTE_IN_SECONDS
        );
        add_filter('ll_tools_wordset_buttons_shortcode_public_status_limit', $one_request);
        try {
            ll_tools_wordset_buttons_shortcode_reset_public_status_throttle($identifier);
            $allowed_response = $this->runWordsetButtonsStatusAjax(['token' => $fresh_token], $identifier);
            $this->assertTrue((bool) ($allowed_response['success'] ?? false));

            $limited_response = $this->runWordsetButtonsStatusAjax(['token' => $fresh_token], $identifier);
            $this->assertFalse((bool) ($limited_response['success'] ?? true));
            $this->assertSame('rate_limited', (string) ($limited_response['data']['code'] ?? ''));
            $this->assertGreaterThan(0, (int) ($limited_response['data']['retryAfterMs'] ?? 0));
        } finally {
            ll_tools_wordset_buttons_shortcode_reset_public_status_throttle($identifier);
            remove_filter('ll_tools_wordset_buttons_shortcode_public_status_limit', $one_request);
        }
    }

    public function test_anonymous_cache_publication_refuses_structural_epoch_drift(): void
    {
        wp_set_current_user(0);
        $atts = ['class' => 'epoch-fence-' . wp_generate_password(8, false), 'hide_empty' => '0'];
        $old_exact_key = ll_tools_wordset_buttons_shortcode_cache_key($atts, 'll_wordset_buttons');
        $old_stale_key = ll_tools_wordset_buttons_shortcode_stale_key($atts, 'll_wordset_buttons');
        $html = '<div class="ll-wordset-buttons-shortcode"><a class="ll-wordset-buttons-shortcode__button">Old</a></div>';

        ll_tools_bump_wordset_cache_epoch();

        $new_exact_key = ll_tools_wordset_buttons_shortcode_cache_key($atts, 'll_wordset_buttons');
        $new_stale_key = ll_tools_wordset_buttons_shortcode_stale_key($atts, 'll_wordset_buttons');
        $this->assertNotSame($old_exact_key, $new_exact_key);
        $this->assertNotSame($old_stale_key, $new_stale_key);
        $this->assertFalse(ll_tools_wordset_buttons_shortcode_publish_anonymous_html(
            $atts,
            'll_wordset_buttons',
            $html,
            $old_exact_key,
            $old_stale_key
        ));
        $this->assertFalse(get_transient($old_exact_key));
        $this->assertFalse(get_transient($old_stale_key));
        $this->assertFalse(get_transient($new_exact_key));
        $this->assertFalse(get_transient($new_stale_key));
    }

    public function test_anonymous_render_returns_recovery_shell_when_context_changes_mid_render(): void
    {
        wp_set_current_user(0);
        $term = wp_insert_term('Buttons Render Fence Wordset', 'wordset');
        $this->assertIsArray($term);
        $this->assertFalse(is_wp_error($term));
        $wordset_id = (int) ($term['term_id'] ?? 0);
        $this->createPublishedLessonForWordset($wordset_id, 'Buttons Render Fence Lesson');

        $counts_complete = false;
        $retry_after_ms = 0;
        for ($attempt = 0; $attempt < 10 && !$counts_complete; $attempt++) {
            ll_tools_get_wordset_button_lesson_counts_bounded(
                [$wordset_id],
                $counts_complete,
                $retry_after_ms
            );
        }
        $this->assertTrue($counts_complete);

        $atts = ['class' => 'render-fence-' . wp_generate_password(8, false), 'hide_empty' => '0'];
        $old_permalink_structure = get_option('permalink_structure', '');
        update_option('permalink_structure', '/%postname%/');
        $epoch_bumped = false;
        $bump_during_url = static function (bool $allow) use (&$epoch_bumped, $wordset_id): bool {
            if (!$epoch_bumped) {
                $epoch_bumped = true;
                ll_tools_bump_wordset_cache_epoch([$wordset_id]);
            }
            return $allow;
        };
        add_filter('ll_tools_wordset_page_allow_conflict', $bump_during_url);

        try {
            $complete = true;
            $html = ll_tools_wordset_buttons_shortcode_render(
                $atts,
                'll_wordset_buttons',
                true,
                $complete
            );
        } finally {
            remove_filter('ll_tools_wordset_page_allow_conflict', $bump_during_url);
            update_option('permalink_structure', $old_permalink_structure);
        }

        $this->assertTrue($epoch_bumped);
        $this->assertFalse($complete);
        $this->assertStringContainsString('data-ll-wordset-buttons-refresh', $html);
        $this->assertStringContainsString('data-ajax-action="ll_tools_wordset_buttons_status"', $html);
        $this->assertStringNotContainsString('Buttons Render Fence Wordset', $html);
        $this->assertFalse(get_transient(
            ll_tools_wordset_buttons_shortcode_cache_key($atts, 'll_wordset_buttons')
        ));
        $this->assertFalse(get_transient(
            ll_tools_wordset_buttons_shortcode_stale_key($atts, 'll_wordset_buttons')
        ));
    }

    public function test_public_status_handler_rejects_context_change_during_payload_build(): void
    {
        wp_set_current_user(0);
        $atts = ['class' => 'status-fence-' . wp_generate_password(8, false), 'hide_empty' => '0'];
        $token = ll_tools_wordset_buttons_shortcode_create_status_token($atts, 'll_wordset_buttons');
        $old_exact_key = ll_tools_wordset_buttons_shortcode_cache_key($atts, 'll_wordset_buttons');
        $old_stale_key = ll_tools_wordset_buttons_shortcode_stale_key($atts, 'll_wordset_buttons');
        $marker_html = '<div class="ll-wordset-buttons-shortcode"><a class="ll-wordset-buttons-shortcode__button">Old</a></div>';
        $epoch_bumped = false;
        $race_cache_read = static function () use (&$epoch_bumped, $marker_html) {
            if (!$epoch_bumped) {
                $epoch_bumped = true;
                ll_tools_bump_wordset_buttons_shortcode_generation_epoch();
            }
            return $marker_html;
        };
        $scan_count = 0;
        $capture_scan = static function () use (&$scan_count): void {
            $scan_count++;
        };
        $status_ip = '192.0.2.24';
        ll_tools_wordset_buttons_shortcode_reset_public_status_throttle($status_ip);
        add_filter('pre_transient_' . $old_exact_key, $race_cache_read);
        add_action('ll_tools_wordset_buttons_shortcode_eligibility_pair_scanned', $capture_scan);

        try {
            $response = $this->runWordsetButtonsStatusAjax(['token' => $token], $status_ip);
        } finally {
            remove_filter('pre_transient_' . $old_exact_key, $race_cache_read);
            remove_action('ll_tools_wordset_buttons_shortcode_eligibility_pair_scanned', $capture_scan);
            ll_tools_wordset_buttons_shortcode_reset_public_status_throttle($status_ip);
        }

        $new_exact_key = ll_tools_wordset_buttons_shortcode_cache_key($atts, 'll_wordset_buttons');
        $new_stale_key = ll_tools_wordset_buttons_shortcode_stale_key($atts, 'll_wordset_buttons');
        $this->assertTrue($epoch_bumped);
        $this->assertNotSame($old_exact_key, $new_exact_key);
        $this->assertFalse((bool) ($response['success'] ?? true));
        $this->assertSame('stale_status_token', (string) ($response['data']['code'] ?? ''));
        $this->assertSame(0, $scan_count);
        $this->assertFalse(get_transient($old_exact_key));
        $this->assertFalse(get_transient($new_exact_key));
        $this->assertFalse(get_transient($old_stale_key));
        $this->assertFalse(get_transient($new_stale_key));
    }

    public function test_public_status_handler_accepts_authoritative_complete_empty_generation(): void
    {
        wp_set_current_user(0);
        $atts = ['class' => 'status-empty-' . wp_generate_password(8, false), 'hide_empty' => '0'];
        $token = ll_tools_wordset_buttons_shortcode_create_status_token($atts, 'll_wordset_buttons');
        $exact_key = ll_tools_wordset_buttons_shortcode_cache_key($atts, 'll_wordset_buttons');
        $stale_key = ll_tools_wordset_buttons_shortcode_stale_key($atts, 'll_wordset_buttons');
        $scan_count = 0;
        $capture_scan = static function () use (&$scan_count): void {
            $scan_count++;
        };
        $status_ip = '192.0.2.25';
        ll_tools_wordset_buttons_shortcode_reset_public_status_throttle($status_ip);
        add_action('ll_tools_wordset_buttons_shortcode_eligibility_pair_scanned', $capture_scan);

        try {
            $response = $this->runWordsetButtonsStatusAjax(['token' => $token], $status_ip);
        } finally {
            remove_action('ll_tools_wordset_buttons_shortcode_eligibility_pair_scanned', $capture_scan);
            ll_tools_wordset_buttons_shortcode_reset_public_status_throttle($status_ip);
        }

        $this->assertTrue((bool) ($response['success'] ?? false));
        $this->assertTrue((bool) ($response['data']['complete'] ?? false));
        $this->assertSame('', (string) ($response['data']['html'] ?? 'missing'));
        $this->assertSame(0, $scan_count);
        $this->assertFalse(get_transient($exact_key));
        $this->assertFalse(get_transient($stale_key));
    }

    public function test_logged_in_recorder_loader_advances_bounded_batches_until_exact_cards_are_ready(): void
    {
        $term = wp_insert_term('Buttons Recorder Refresh Wordset', 'wordset');
        $this->assertIsArray($term);
        $this->assertFalse(is_wp_error($term));
        $wordset_id = (int) ($term['term_id'] ?? 0);
        for ($index = 1; $index <= 3; $index++) {
            $this->createPublishedLessonForWordset(
                $wordset_id,
                'Buttons Recorder Refresh Lesson ' . $index
            );
        }

        $user_id = self::factory()->user->create(['role' => 'audio_recorder']);
        wp_set_current_user($user_id);
        $atts = ['class' => 'homepage-wordsets recorder-home', 'hide_empty' => '0'];
        $one_pair = static function (): int {
            return 1;
        };

        add_filter('ll_tools_wordset_buttons_shortcode_eligibility_batch_size', $one_pair);
        try {
            $initial_html = do_shortcode('[ll_wordset_buttons class="homepage-wordsets recorder-home"]');
            $this->assertStringContainsString('data-ll-wordset-buttons-refresh', $initial_html);
            $this->assertStringContainsString('data-shortcode-tag="ll_wordset_buttons"', $initial_html);
            $this->assertStringContainsString('data-shortcode-class="homepage-wordsets recorder-home"', $initial_html);
            $this->assertStringContainsString('data-ajax-url="', $initial_html);
            $this->assertStringContainsString('data-error-message="Something went wrong"', $initial_html);
            $this->assertStringContainsString('data-retry-label="Try again"', $initial_html);
            $this->assertStringContainsString('data-ajax-action="ll_tools_wordset_buttons_refresh"', $initial_html);
            $this->assertStringContainsString('data-nonce="', $initial_html);
            $this->assertStringContainsString('aria-busy="true"', $initial_html);
            $this->assertTrue(wp_script_is('ll-tools-wordset-buttons-refresh', 'enqueued'));
            $this->assertNotFalse(has_action(
                'wp_ajax_ll_tools_wordset_buttons_refresh',
                'll_tools_wordset_buttons_shortcode_refresh_ajax'
            ));
            $this->assertFalse(has_action(
                'wp_ajax_nopriv_ll_tools_wordset_buttons_refresh',
                'll_tools_wordset_buttons_shortcode_refresh_ajax'
            ));

            $localized_data = (string) wp_scripts()->get_data('ll-tools-wordset-buttons-refresh', 'data');
            $this->assertStringContainsString('llToolsWordsetButtonsRefresh', $localized_data);
            $this->assertStringContainsString('ll_tools_wordset_buttons_refresh', $localized_data);
            $this->assertStringContainsString('maxFailures', $localized_data);
            $this->assertStringContainsString('maxWaitMs', $localized_data);
            $this->assertStringContainsString('retryLabel', $localized_data);

            $payload = ['complete' => false, 'html' => ''];
            for ($attempt = 0; $attempt < 10 && empty($payload['complete']); $attempt++) {
                $payload = ll_tools_wordset_buttons_shortcode_refresh_payload($atts, 'll_wordset_buttons');
                $this->assertArrayHasKey('complete', $payload);
                $this->assertArrayHasKey('html', $payload);
                $this->assertArrayHasKey('retryAfterMs', $payload);
                $this->assertStringNotContainsString(
                    'data-ll-wordset-buttons-refresh',
                    (string) $payload['html'],
                    'AJAX payloads must not nest another polling root.'
                );
            }
        } finally {
            remove_filter('ll_tools_wordset_buttons_shortcode_eligibility_batch_size', $one_pair);
            wp_set_current_user(0);
        }

        $this->assertTrue((bool) ($payload['complete'] ?? false));
        $this->assertStringContainsString('Buttons Recorder Refresh Wordset', (string) ($payload['html'] ?? ''));
        $this->assertStringContainsString('3 lessons', (string) ($payload['html'] ?? ''));
        $this->assertGreaterThanOrEqual(500, (int) ($payload['retryAfterMs'] ?? 0));
    }

    public function test_logged_in_singular_page_enqueues_refresh_fallback_before_cached_shortcode_markup(): void
    {
        $user_id = self::factory()->user->create(['role' => 'administrator']);
        $page_id = self::factory()->post->create([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_title' => 'Cached Wordset Buttons Shell',
            'post_content' => 'Page-builder content is cached outside post_content.',
        ]);

        wp_set_current_user($user_id);
        $this->go_to(get_permalink($page_id));
        ll_tools_wordset_buttons_shortcode_enqueue_logged_in_fallback();

        $this->assertTrue(wp_script_is('ll-tools-wordset-buttons-refresh', 'enqueued'));
        $localized_data = (string) wp_scripts()->get_data('ll-tools-wordset-buttons-refresh', 'data');
        $this->assertStringContainsString('llToolsWordsetButtonsRefresh', $localized_data);
        $this->assertStringContainsString('errorMessage', $localized_data);
        $this->assertStringContainsString('retryLabel', $localized_data);
    }

    public function test_structural_privacy_epoch_never_reuses_the_previous_complete_render(): void
    {
        wp_set_current_user(0);
        $term = wp_insert_term('Buttons Structural Privacy Wordset', 'wordset');
        $this->assertIsArray($term);
        $this->assertFalse(is_wp_error($term));
        $wordset_id = (int) ($term['term_id'] ?? 0);
        $this->createPublishedLessonForWordset($wordset_id, 'Buttons Structural Privacy Lesson');

        $atts = ['class' => '', 'hide_empty' => '0'];
        $public_html = do_shortcode('[ll_wordset_buttons]');
        $old_exact_key = ll_tools_wordset_buttons_shortcode_cache_key($atts, 'll_wordset_buttons');
        $old_stale_key = ll_tools_wordset_buttons_shortcode_stale_key($atts, 'll_wordset_buttons');
        $this->assertStringContainsString('Buttons Structural Privacy Wordset', $public_html);
        $this->assertSame($public_html, ll_tools_wordset_buttons_shortcode_stale_get($old_stale_key));

        update_term_meta($wordset_id, LL_TOOLS_WORDSET_VISIBILITY_META_KEY, 'private');
        ll_tools_bump_wordset_cache_epoch([$wordset_id]);

        $new_exact_key = ll_tools_wordset_buttons_shortcode_cache_key($atts, 'll_wordset_buttons');
        $new_stale_key = ll_tools_wordset_buttons_shortcode_stale_key($atts, 'll_wordset_buttons');
        $this->assertNotSame($old_exact_key, $new_exact_key);
        $this->assertNotSame($old_stale_key, $new_stale_key);
        $this->assertSame($public_html, ll_tools_wordset_buttons_shortcode_stale_get($old_stale_key));
        $this->assertSame('', ll_tools_wordset_buttons_shortcode_stale_get($new_stale_key));
        $this->assertSame('', do_shortcode('[ll_wordset_buttons]'));
    }

    public function test_structural_privacy_change_during_rebuild_never_serves_the_previous_public_render(): void
    {
        wp_set_current_user(0);
        $term = wp_insert_term('Buttons Racing Privacy Wordset', 'wordset');
        $this->assertIsArray($term);
        $this->assertFalse(is_wp_error($term));
        $wordset_id = (int) ($term['term_id'] ?? 0);
        $this->createPublishedLessonForWordset($wordset_id, 'Buttons Racing Privacy Lesson A');
        $this->createPublishedLessonForWordset($wordset_id, 'Buttons Racing Privacy Lesson B');

        $atts = ['class' => '', 'hide_empty' => '0'];
        $public_html = do_shortcode('[ll_wordset_buttons]');
        $old_stale_key = ll_tools_wordset_buttons_shortcode_stale_key($atts, 'll_wordset_buttons');
        $this->assertStringContainsString('Buttons Racing Privacy Wordset', $public_html);
        $this->assertSame($public_html, ll_tools_wordset_buttons_shortcode_stale_get($old_stale_key));

        ll_tools_bump_quiz_content_cache_epoch([$wordset_id], true);
        $visibility_changed = false;
        $make_private_after_first_scan = static function () use (&$visibility_changed, $wordset_id): void {
            if ($visibility_changed) {
                return;
            }
            $visibility_changed = true;
            update_term_meta($wordset_id, LL_TOOLS_WORDSET_VISIBILITY_META_KEY, 'private');
            ll_tools_bump_wordset_cache_epoch([$wordset_id]);
        };

        add_action('ll_tools_wordset_buttons_shortcode_eligibility_pair_scanned', $make_private_after_first_scan, 10, 0);
        try {
            $racing_html = do_shortcode('[ll_wordset_buttons]');
        } finally {
            remove_action('ll_tools_wordset_buttons_shortcode_eligibility_pair_scanned', $make_private_after_first_scan, 10);
        }

        $new_stale_key = ll_tools_wordset_buttons_shortcode_stale_key($atts, 'll_wordset_buttons');
        $this->assertTrue($visibility_changed);
        $this->assertNotSame($old_stale_key, $new_stale_key);
        $this->assertSame('', ll_tools_wordset_buttons_shortcode_stale_get($new_stale_key));
        $this->assertStringContainsString('ll-wordset-buttons-shortcode--loading', $racing_html);
        $this->assertStringNotContainsString('Buttons Racing Privacy Wordset', $racing_html);
        $this->assertStringNotContainsString('ll-wordset-buttons-shortcode__button', $racing_html);
    }

    public function test_previous_version_exact_cache_bridges_only_the_6_6_75_rebuild(): void
    {
        wp_set_current_user(0);
        $this->assertFalse(ll_tools_wordset_buttons_shortcode_previous_version_bridge_enabled('6.6.74'));
        $this->assertTrue(ll_tools_wordset_buttons_shortcode_previous_version_bridge_enabled('6.6.75'));
        $this->assertFalse(ll_tools_wordset_buttons_shortcode_previous_version_bridge_enabled('6.6.76'));
        $this->assertFalse(ll_tools_wordset_buttons_shortcode_legacy_bridge_enabled('6.6.71'));
        $this->assertTrue(ll_tools_wordset_buttons_shortcode_legacy_bridge_enabled('6.6.72'));
        $this->assertTrue(ll_tools_wordset_buttons_shortcode_legacy_bridge_enabled('6.6.73'));
        $this->assertTrue(ll_tools_wordset_buttons_shortcode_legacy_bridge_enabled('6.6.74'));
        $this->assertFalse(ll_tools_wordset_buttons_shortcode_legacy_bridge_enabled('6.6.75'));

        $legacy_atts = ['class' => '', 'hide_empty' => '0'];
        $legacy_key = ll_tools_wordset_buttons_shortcode_legacy_cache_key(
            $legacy_atts,
            'll_wordset_buttons',
            '6.6.74'
        );
        $legacy_html = '<div class="ll-wordset-buttons-shortcode"><a class="ll-wordset-buttons-shortcode__button">Schema 3 complete render</a></div>';
        $this->assertNotSame('', $legacy_key);
        $this->assertSame('', ll_tools_wordset_buttons_shortcode_legacy_cache_key(
            $legacy_atts,
            'll_wordset_buttons',
            '6.6.75'
        ));
        set_transient($legacy_key, $legacy_html, HOUR_IN_SECONDS);
        $this->assertSame(
            $legacy_html,
            ll_tools_wordset_buttons_shortcode_legacy_cache_get($legacy_atts, 'll_wordset_buttons', '6.6.74')
        );
        delete_transient($legacy_key);

        $term = wp_insert_term('Buttons Previous Version Bridge Wordset', 'wordset');
        $this->assertIsArray($term);
        $this->assertFalse(is_wp_error($term));
        $wordset_id = (int) ($term['term_id'] ?? 0);
        $this->createPublishedLessonForWordset($wordset_id, 'Buttons Previous Version Bridge Lesson 1');
        $this->createPublishedLessonForWordset($wordset_id, 'Buttons Previous Version Bridge Lesson 2');

        $atts = ['class' => '', 'hide_empty' => '0'];
        $previous_key = ll_tools_wordset_buttons_shortcode_cache_key($atts, 'll_wordset_buttons', '6.6.74');
        $previous_html = '<div class="ll-wordset-buttons-shortcode"><a class="ll-wordset-buttons-shortcode__button">6.6.74 complete render</a></div>';
        set_transient($previous_key, $previous_html, HOUR_IN_SECONDS);
        $this->assertSame('', ll_tools_wordset_buttons_shortcode_previous_version_cache_get($atts, 'll_wordset_buttons'));
        try {
            $current_html = do_shortcode('[ll_wordset_buttons]');
        } finally {
            delete_transient($previous_key);
        }

        $this->assertStringNotContainsString('6.6.74 complete render', $current_html);
    }

    public function test_incomplete_pair_is_retried_without_publishing_partial_counts(): void
    {
        global $wpdb;

        wp_set_current_user(0);
        $term = wp_insert_term('Buttons Pair Retry Wordset', 'wordset');
        $this->assertIsArray($term);
        $this->assertFalse(is_wp_error($term));
        $wordset_id = (int) ($term['term_id'] ?? 0);
        $lesson_id = $this->createPublishedLessonForWordset($wordset_id, 'Buttons Pair Retry Lesson');
        $category_id = (int) get_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, true);
        $this->assertGreaterThan(0, $category_id);

        $atts = ['class' => '', 'hide_empty' => '0'];
        $exact_key = ll_tools_wordset_buttons_shortcode_cache_key($atts, 'll_wordset_buttons');
        $injected = false;
        $break_query = static function (string $sql) use ($wpdb, &$injected): string {
            if (!$injected && strpos($sql, "{$wpdb->posts}.post_type = 'words'") !== false) {
                $injected = true;
                return "SELECT ID FROM {$wpdb->posts}_ll_tools_missing_button_pair";
            }
            return $sql;
        };
        $scanned_pairs = [];
        $capture_pair = static function (int $scanned_wordset_id, int $scanned_category_id) use (&$scanned_pairs): void {
            $scanned_pairs[] = $scanned_wordset_id . ':' . $scanned_category_id;
        };
        $logical_now = 1700000000;
        $now_filter = static function () use (&$logical_now): int {
            return $logical_now;
        };

        $previous_suppress = $wpdb->suppress_errors(true);
        add_filter('query', $break_query);
        add_filter('ll_tools_wordset_buttons_shortcode_now', $now_filter);
        add_action('ll_tools_wordset_buttons_shortcode_eligibility_pair_scanned', $capture_pair, 10, 2);
        try {
            $failed_html = do_shortcode('[ll_wordset_buttons]');
        } finally {
            remove_filter('query', $break_query);
            $wpdb->suppress_errors($previous_suppress);
        }
        $this->assertTrue($injected);
        $this->assertFalse(get_transient($exact_key));
        $this->assertStringContainsString('ll-wordset-buttons-shortcode--loading', $failed_html);
        $this->assertStringNotContainsString('ll-wordset-buttons-shortcode__button', $failed_html);

        $generation_key = ll_tools_wordset_button_counts_generation_key(
            [$wordset_id],
            ll_tools_wordset_button_quiz_min_word_count()
        );
        $state_key = ll_tools_wordset_button_counts_state_key($generation_key);
        $failed_state = ll_tools_get_wordset_button_count_state($state_key);
        $this->assertIsArray($failed_state);
        $this->assertSame(1, (int) ($failed_state['attempts'] ?? 0));
        $this->assertGreaterThan($logical_now, (int) ($failed_state['next_retry_at'] ?? 0));
        $backoff_payload = ll_tools_wordset_buttons_shortcode_refresh_payload(
            $atts,
            'll_wordset_buttons'
        );
        $this->assertFalse((bool) ($backoff_payload['complete'] ?? true));
        $this->assertSame(
            ((int) $failed_state['next_retry_at'] - $logical_now) * 1000,
            (int) ($backoff_payload['retryAfterMs'] ?? 0),
            'The browser must honor the durable server backoff instead of polling every 1.5 seconds.'
        );
        $backoff_html = do_shortcode('[ll_wordset_buttons]');
        $this->assertStringContainsString('ll-wordset-buttons-shortcode--loading', $backoff_html);
        $this->assertStringNotContainsString('ll-wordset-buttons-shortcode__button', $backoff_html);
        $this->assertCount(1, $scanned_pairs);

        try {
            $logical_now = (int) ($failed_state['next_retry_at'] ?? 0);
            $recovered_html = do_shortcode('[ll_wordset_buttons]');
        } finally {
            remove_filter('ll_tools_wordset_buttons_shortcode_now', $now_filter);
            remove_action('ll_tools_wordset_buttons_shortcode_eligibility_pair_scanned', $capture_pair, 10);
        }

        $this->assertStringContainsString('Buttons Pair Retry Wordset', $recovered_html);
        $this->assertSame([
            $wordset_id . ':' . $category_id,
            $wordset_id . ':' . $category_id,
        ], $scanned_pairs, 'The incomplete pair must remain at the cursor and be retried after the source recovers.');
        $this->assertIsString(get_transient($exact_key));
    }

    public function test_sparse_raw_scan_resumes_with_strict_per_request_caps(): void
    {
        wp_set_current_user(0);
        $term = wp_insert_term('Buttons Sparse Scan Wordset', 'wordset');
        $this->assertIsArray($term);
        $this->assertFalse(is_wp_error($term));
        $wordset_id = (int) ($term['term_id'] ?? 0);
        $category_id = $this->createCustomLessonCategoryForWordset(
            $wordset_id,
            'Buttons Sparse Scan Lesson',
            27,
            false,
            'audio',
            'text_title'
        );
        $this->createLessonPostForWordsetCategory($wordset_id, $category_id, 'Buttons Sparse Scan Lesson');

        $generation_key = ll_tools_wordset_button_counts_generation_key(
            [$wordset_id],
            ll_tools_wordset_button_quiz_min_word_count()
        );
        $state_key = ll_tools_wordset_button_counts_state_key($generation_key);
        $raw_queries = [];
        $capture_raw_query = static function (string $sql) use (&$raw_queries): string {
            if (strpos($sql, 'SELECT DISTINCT p.ID') !== false && strpos($sql, "p.post_type = 'words'") !== false) {
                $raw_queries[] = $sql;
            }
            return $sql;
        };
        $one_query = static function (): int {
            return 1;
        };
        $ten_rows = static function (): int {
            return 10;
        };

        add_filter('query', $capture_raw_query);
        add_filter('ll_tools_wordset_buttons_shortcode_raw_query_budget', $one_query);
        add_filter('ll_tools_wordset_buttons_shortcode_raw_row_budget', $ten_rows);
        try {
            $first_html = do_shortcode('[ll_wordset_buttons]');
            $first_state = ll_tools_get_wordset_button_count_state($state_key);
            $first_query_count = count($raw_queries);

            $second_html = do_shortcode('[ll_wordset_buttons]');
            $second_state = ll_tools_get_wordset_button_count_state($state_key);
            $second_query_count = count($raw_queries);

            $third_html = do_shortcode('[ll_wordset_buttons]');
            $third_state = ll_tools_get_wordset_button_count_state($state_key);
            $third_query_count = count($raw_queries);

            $fourth_html = do_shortcode('[ll_wordset_buttons]');
            $fourth_query_count = count($raw_queries);
        } finally {
            remove_filter('ll_tools_wordset_buttons_shortcode_raw_row_budget', $ten_rows);
            remove_filter('ll_tools_wordset_buttons_shortcode_raw_query_budget', $one_query);
            remove_filter('query', $capture_raw_query);
        }

        $this->assertStringContainsString('ll-wordset-buttons-shortcode--loading', $first_html);
        $this->assertStringContainsString('ll-wordset-buttons-shortcode--loading', $second_html);
        $this->assertSame('', $third_html);
        $this->assertSame('', $fourth_html);
        $this->assertSame(1, $first_query_count);
        $this->assertSame(2, $second_query_count);
        $this->assertSame(3, $third_query_count);
        $this->assertSame(3, $fourth_query_count, 'A completed false result must not restart the raw scan.');
        $this->assertIsArray($first_state);
        $this->assertIsArray($second_state);
        $this->assertIsArray($third_state);
        $first_cursor = (int) ($first_state['active_pair']['scan']['phases']['primary']['raw_cursor_id'] ?? 0);
        $second_cursor = (int) ($second_state['active_pair']['scan']['phases']['primary']['raw_cursor_id'] ?? 0);
        $this->assertGreaterThan(0, $first_cursor);
        $this->assertGreaterThan($first_cursor, $second_cursor);
        $this->assertFalse((bool) ($first_state['complete'] ?? true));
        $this->assertFalse((bool) ($second_state['complete'] ?? true));
        $this->assertTrue((bool) ($third_state['complete'] ?? false));
        $this->assertSame([], (array) ($third_state['active_pair'] ?? []));
        $this->assertSame(0, (int) ($third_state['counts'][$wordset_id] ?? -1));
        foreach ($raw_queries as $sql) {
            $this->assertMatchesRegularExpression('/LIMIT\s+10\s*$/i', trim($sql));
        }
    }

    public function test_logged_in_render_is_bounded_and_schedules_scoped_continuation(): void
    {
        $user_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($user_id);
        $term = wp_insert_term('Buttons Logged In Bound Wordset', 'wordset');
        $this->assertIsArray($term);
        $this->assertFalse(is_wp_error($term));
        $wordset_id = (int) ($term['term_id'] ?? 0);
        $category_id = $this->createCustomLessonCategoryForWordset(
            $wordset_id,
            'Buttons Logged In Bound Lesson',
            15,
            true,
            'audio',
            'text_title'
        );
        $this->createLessonPostForWordsetCategory($wordset_id, $category_id, 'Buttons Logged In Bound Lesson');

        $raw_query_count = 0;
        $capture_raw_query = static function (string $sql) use (&$raw_query_count): string {
            if (strpos($sql, 'SELECT DISTINCT p.ID') !== false && strpos($sql, "p.post_type = 'words'") !== false) {
                $raw_query_count++;
            }
            return $sql;
        };
        $minimum = static function (): int {
            return 15;
        };
        $one_query = static function (): int {
            return 1;
        };
        $ten_rows = static function (): int {
            return 10;
        };
        $cron_args = [[$wordset_id], $user_id];

        add_filter('query', $capture_raw_query);
        add_filter('ll_tools_quiz_min_words', $minimum);
        add_filter('ll_tools_wordset_buttons_shortcode_raw_query_budget', $one_query);
        add_filter('ll_tools_wordset_buttons_shortcode_raw_row_budget', $ten_rows);
        try {
            $renders = [];
            $query_deltas = [];
            $scheduled = [];
            for ($attempt = 0; $attempt < 10; $attempt++) {
                $before_queries = $raw_query_count;
                $renders[] = do_shortcode('[ll_wordset_buttons]');
                $query_deltas[] = $raw_query_count - $before_queries;
                $scheduled[] = wp_next_scheduled('ll_tools_refresh_wordset_button_lesson_counts', $cron_args);
                if (strpos((string) end($renders), 'Buttons Logged In Bound Wordset') !== false) {
                    break;
                }
            }
            $completed_html = (string) end($renders);
            $queries_at_completion = $raw_query_count;
            $cached_state_html = do_shortcode('[ll_wordset_buttons]');
            $queries_after_cached_state = $raw_query_count;
        } finally {
            remove_filter('ll_tools_wordset_buttons_shortcode_raw_row_budget', $ten_rows);
            remove_filter('ll_tools_wordset_buttons_shortcode_raw_query_budget', $one_query);
            remove_filter('ll_tools_quiz_min_words', $minimum);
            remove_filter('query', $capture_raw_query);
            wp_set_current_user(0);
        }

        $this->assertGreaterThanOrEqual(2, count($renders));
        $this->assertStringContainsString('Buttons Logged In Bound Wordset', $completed_html);
        foreach ($query_deltas as $query_delta) {
            $this->assertLessThanOrEqual(1, $query_delta, 'Each logged-in request must share the same strict raw-query cap.');
        }
        $this->assertNotEmpty(array_filter($scheduled), 'Incomplete logged-in progress must enqueue its scoped continuation.');
        $this->assertGreaterThanOrEqual(2, $queries_at_completion);
        $this->assertSame($completed_html, $cached_state_html);
        $this->assertSame($queries_at_completion, $queries_after_cached_state);
    }

    public function test_logged_in_incomplete_scope_uses_public_lkg_then_cron_resumes_exact_private_access(): void
    {
        wp_set_current_user(0);
        $public_term = wp_insert_term('Buttons LKG Public Wordset', 'wordset');
        $private_term = wp_insert_term('Buttons LKG Private Wordset', 'wordset');
        $this->assertIsArray($public_term);
        $this->assertIsArray($private_term);
        $this->assertFalse(is_wp_error($public_term));
        $this->assertFalse(is_wp_error($private_term));
        $public_wordset_id = (int) ($public_term['term_id'] ?? 0);
        $private_wordset_id = (int) ($private_term['term_id'] ?? 0);
        update_term_meta($private_wordset_id, LL_TOOLS_WORDSET_VISIBILITY_META_KEY, 'private');

        $this->createPublishedLessonForWordset($public_wordset_id, 'Buttons LKG Public Lesson');
        for ($index = 1; $index <= 2; $index++) {
            $lesson_id = $this->createPublishedLessonForWordset(
                $private_wordset_id,
                'Buttons LKG Private Lesson ' . $index
            );
            $category_id = (int) get_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, true);
            $this->assertGreaterThan(0, $category_id);
            update_term_meta($category_id, LL_TOOLS_CATEGORY_VISIBILITY_META_KEY, 'private');
        }

        $anonymous_generation = ll_tools_wordset_button_counts_generation_key(
            [$public_wordset_id],
            ll_tools_wordset_button_quiz_min_word_count()
        );
        $anonymous_html = do_shortcode('[ll_wordset_buttons]');
        $this->assertStringContainsString('Buttons LKG Public Wordset', $anonymous_html);
        $this->assertStringNotContainsString('Buttons LKG Private Wordset', $anonymous_html);
        $atts = ['class' => '', 'hide_empty' => '0'];
        $anonymous_exact_key = ll_tools_wordset_buttons_shortcode_cache_key($atts, 'll_wordset_buttons');
        $public_lkg_key = ll_tools_wordset_buttons_shortcode_stale_key($atts, 'll_wordset_buttons');
        $this->assertSame($anonymous_html, get_transient($anonymous_exact_key));
        $this->assertSame($anonymous_html, ll_tools_wordset_buttons_shortcode_stale_get($public_lkg_key));
        delete_transient($public_lkg_key);
        $this->assertSame('', ll_tools_wordset_buttons_shortcode_stale_get($public_lkg_key));

        $user_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($user_id);
        $private_scope_ids = ll_tools_wordset_button_normalize_wordset_ids([
            $public_wordset_id,
            $private_wordset_id,
        ]);
        $logged_in_generation = ll_tools_wordset_button_counts_generation_key(
            $private_scope_ids,
            ll_tools_wordset_button_quiz_min_word_count()
        );
        $one_pair = static function (): int {
            return 1;
        };
        add_filter('ll_tools_wordset_buttons_shortcode_eligibility_batch_size', $one_pair);
        try {
            $incomplete_html = do_shortcode('[ll_wordset_buttons]');
            $scheduled_at = wp_next_scheduled(
                'll_tools_refresh_wordset_button_lesson_counts',
                [$private_scope_ids, $user_id]
            );
        } finally {
            remove_filter('ll_tools_wordset_buttons_shortcode_eligibility_batch_size', $one_pair);
        }

        $this->assertNotSame($anonymous_generation, $logged_in_generation, 'Authorization-specific count scopes must remain isolated.');
        $this->assertStringContainsString(
            $anonymous_html,
            $incomplete_html,
            'An expired LKG must remain the visible fallback inside the authenticated refresh root.'
        );
        $this->assertStringContainsString('data-ll-wordset-buttons-refresh', $incomplete_html);
        $this->assertIsInt($scheduled_at);

        wp_set_current_user(0);
        ll_tools_refresh_wordset_button_lesson_counts($private_scope_ids, $user_id);
        $this->assertSame(0, get_current_user_id(), 'The cron worker must restore its prior authorization context.');

        wp_set_current_user($user_id);
        $complete_html = do_shortcode('[ll_wordset_buttons]');
        $this->assertStringContainsString('Buttons LKG Public Wordset', $complete_html);
        $this->assertStringContainsString('Buttons LKG Private Wordset', $complete_html);
        $this->assertStringContainsString('2 lessons', $complete_html);
        $this->assertFalse(wp_next_scheduled(
            'll_tools_refresh_wordset_button_lesson_counts',
            [$private_scope_ids, $user_id]
        ));
        wp_set_current_user(0);
    }

    public function test_count_builder_schema_rotates_the_generation_key(): void
    {
        wp_set_current_user(0);
        $wordset_ids = [19, 7, 19];
        $minimum = 5;
        $current_generation = ll_tools_wordset_button_counts_generation_key($wordset_ids, $minimum);
        $next_schema = static function (): int {
            return 3;
        };

        add_filter('ll_tools_wordset_buttons_shortcode_count_builder_schema', $next_schema);
        try {
            $next_generation = ll_tools_wordset_button_counts_generation_key($wordset_ids, $minimum);
        } finally {
            remove_filter('ll_tools_wordset_buttons_shortcode_count_builder_schema', $next_schema);
        }

        $this->assertNotSame($current_generation, $next_generation);
    }

    public function test_count_generation_separates_users_with_the_same_wordset_ids(): void
    {
        $first_user_id = self::factory()->user->create(['role' => 'subscriber']);
        $second_user_id = self::factory()->user->create(['role' => 'subscriber']);
        $wordset_ids = [19, 7];

        wp_set_current_user($first_user_id);
        $first_generation = ll_tools_wordset_button_counts_generation_key($wordset_ids, 5);
        wp_set_current_user($second_user_id);
        $second_generation = ll_tools_wordset_button_counts_generation_key($wordset_ids, 5);
        wp_set_current_user(0);

        $this->assertNotSame(
            $first_generation,
            $second_generation,
            'Private-category grants can differ even when two users have the same visible wordset IDs.'
        );
    }

    public function test_completed_primary_phase_does_not_starve_budgeted_text_fallback(): void
    {
        wp_set_current_user(0);
        $term = wp_insert_term('Buttons Fallback Resume Wordset', 'wordset');
        $this->assertIsArray($term);
        $this->assertFalse(is_wp_error($term));
        $wordset_id = (int) ($term['term_id'] ?? 0);
        $category_id = $this->createCustomLessonCategoryForWordset(
            $wordset_id,
            'Buttons Fallback Resume Lesson',
            $this->quizMinWordCount(),
            false,
            'text_title',
            'audio'
        );
        $raw_query_count = 0;
        $capture_raw_query = static function (string $sql) use (&$raw_query_count): string {
            if (strpos($sql, 'SELECT DISTINCT p.ID') !== false && strpos($sql, "p.post_type = 'words'") !== false) {
                $raw_query_count++;
            }
            return $sql;
        };
        $one_query = static function (): int {
            return 1;
        };
        $ten_rows = static function (): int {
            return 10;
        };

        add_filter('query', $capture_raw_query);
        add_filter('ll_tools_wordset_buttons_shortcode_raw_query_budget', $one_query);
        add_filter('ll_tools_wordset_buttons_shortcode_raw_row_budget', $ten_rows);
        try {
            $resume_context = [
                'schema' => 1,
                'enabled' => true,
                'budget' => [
                    'max_raw_queries' => 1,
                    'max_raw_rows' => 10,
                    'raw_queries_used' => 0,
                    'raw_rows_used' => 0,
                ],
            ];
            $first_complete = true;
            $first_result = ll_can_category_generate_quiz(
                $category_id,
                $this->quizMinWordCount(),
                [$wordset_id],
                $first_complete,
                $resume_context
            );
            $first_query_count = $raw_query_count;

            $resume_context['budget'] = [
                'max_raw_queries' => 1,
                'max_raw_rows' => 10,
                'raw_queries_used' => 0,
                'raw_rows_used' => 0,
            ];
            $second_complete = true;
            $second_result = ll_can_category_generate_quiz(
                $category_id,
                $this->quizMinWordCount(),
                [$wordset_id],
                $second_complete,
                $resume_context
            );
            $second_query_count = $raw_query_count;

            $third_complete = true;
            $third_result = ll_can_category_generate_quiz(
                $category_id,
                $this->quizMinWordCount(),
                [$wordset_id],
                $third_complete
            );
            $third_query_count = $raw_query_count;
        } finally {
            remove_filter('ll_tools_wordset_buttons_shortcode_raw_row_budget', $ten_rows);
            remove_filter('ll_tools_wordset_buttons_shortcode_raw_query_budget', $one_query);
            remove_filter('query', $capture_raw_query);
        }

        $this->assertFalse($first_result);
        $this->assertFalse($first_complete);
        $this->assertTrue((bool) ($resume_context['phases']['primary']['source_complete'] ?? false));
        $this->assertSame(1, $first_query_count);
        $this->assertTrue($second_result);
        $this->assertTrue($second_complete);
        $this->assertSame(2, $second_query_count);
        $this->assertTrue($third_result);
        $this->assertTrue($third_complete);
        $this->assertSame(2, $third_query_count);
    }

    public function test_epoch_change_after_pair_scan_rejects_the_stale_generation(): void
    {
        wp_set_current_user(0);
        $term = wp_insert_term('Buttons Epoch Race Wordset', 'wordset');
        $this->assertIsArray($term);
        $this->assertFalse(is_wp_error($term));
        $wordset_id = (int) ($term['term_id'] ?? 0);
        $first_lesson_id = $this->createPublishedLessonForWordset($wordset_id, 'Buttons Epoch Race Lesson A');
        $second_lesson_id = $this->createPublishedLessonForWordset($wordset_id, 'Buttons Epoch Race Lesson B');
        $first_category_id = (int) get_post_meta($first_lesson_id, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, true);
        $second_category_id = (int) get_post_meta($second_lesson_id, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, true);

        $minimum = ll_tools_wordset_button_quiz_min_word_count();
        $old_generation = ll_tools_wordset_button_counts_generation_key([$wordset_id], $minimum);
        $old_state_key = ll_tools_wordset_button_counts_state_key($old_generation);
        $old_exact_key = ll_tools_wordset_buttons_shortcode_cache_key(
            ['class' => '', 'hide_empty' => '0'],
            'll_wordset_buttons'
        );
        $scanned_pairs = [];
        $bumped = false;
        $bump_after_first_scan = static function (
            int $scanned_wordset_id,
            int $scanned_category_id
        ) use (&$scanned_pairs, &$bumped, $wordset_id): void {
            $scanned_pairs[] = $scanned_wordset_id . ':' . $scanned_category_id;
            if (!$bumped) {
                $bumped = true;
                ll_tools_bump_quiz_content_cache_epoch([$wordset_id], true);
            }
        };

        add_action('ll_tools_wordset_buttons_shortcode_eligibility_pair_scanned', $bump_after_first_scan, 10, 2);
        try {
            $stale_worker_html = do_shortcode('[ll_wordset_buttons]');
            $new_generation = ll_tools_wordset_button_counts_generation_key([$wordset_id], $minimum);
            $new_state_key = ll_tools_wordset_button_counts_state_key($new_generation);
            $new_exact_key = ll_tools_wordset_buttons_shortcode_cache_key(
                ['class' => '', 'hide_empty' => '0'],
                'll_wordset_buttons'
            );
            $recovered_html = do_shortcode('[ll_wordset_buttons]');
        } finally {
            remove_action('ll_tools_wordset_buttons_shortcode_eligibility_pair_scanned', $bump_after_first_scan, 10);
        }

        $this->assertTrue($bumped);
        $this->assertStringContainsString('ll-wordset-buttons-shortcode--loading', $stale_worker_html);
        $this->assertStringNotContainsString('ll-wordset-buttons-shortcode__button', $stale_worker_html);
        $this->assertNotSame($old_generation, $new_generation);
        $this->assertNotSame($old_exact_key, $new_exact_key);
        $this->assertNull(ll_tools_get_wordset_button_count_state($old_state_key));
        $this->assertFalse(get_transient($old_exact_key));
        $this->assertStringContainsString('Buttons Epoch Race Wordset', $recovered_html);
        $this->assertStringContainsString('2 lessons', $recovered_html);
        $this->assertIsArray(ll_tools_get_wordset_button_count_state($new_state_key));
        $this->assertIsString(get_transient($new_exact_key));
        $this->assertSame($wordset_id . ':' . $first_category_id, $scanned_pairs[0] ?? '');
        $this->assertContains($wordset_id . ':' . $second_category_id, $scanned_pairs);
        $scan_counts = array_count_values($scanned_pairs);
        $this->assertGreaterThanOrEqual(2, (int) ($scan_counts[$scanned_pairs[0] ?? ''] ?? 0));
    }

    public function test_purge_fences_a_stale_writer_that_already_loaded_state(): void
    {
        wp_set_current_user(0);
        $term = wp_insert_term('Buttons Stale Writer Wordset', 'wordset');
        $this->assertIsArray($term);
        $this->assertFalse(is_wp_error($term));
        $wordset_id = (int) ($term['term_id'] ?? 0);
        $minimum = ll_tools_wordset_button_quiz_min_word_count();
        $generation = ll_tools_wordset_button_counts_generation_key([$wordset_id], $minimum);
        $state_key = ll_tools_wordset_button_counts_state_key($generation);

        $state = [
            'schema' => 2,
            'generation' => $generation,
            'wordset_ids' => [$wordset_id],
            'min_word_count' => $minimum,
            'lesson_cursor_id' => 0,
            'counts' => [$wordset_id => 0],
            'seen_pairs' => [],
            'active_pair' => [],
            'attempts' => 0,
            'next_retry_at' => 0,
            'last_failure_reason' => '',
            'complete' => false,
            'revision' => 0,
        ];

        $first_token = ll_tools_acquire_wordset_button_count_lock($state_key);
        $this->assertNotSame('', $first_token);
        try {
            $this->assertTrue(ll_tools_store_wordset_button_count_state($state_key, $state, null, $first_token));
        } finally {
            ll_tools_release_wordset_button_count_lock($state_key, $first_token);
        }

        $second_token = ll_tools_acquire_wordset_button_count_lock($state_key);
        $this->assertNotSame('', $second_token);
        $expected_state = ll_tools_get_wordset_button_count_state($state_key);
        $this->assertIsArray($expected_state);
        $next_state = $expected_state;
        $next_state['lesson_cursor_id'] = 123;
        $this->assertSame(
            $generation,
            ll_tools_wordset_button_counts_generation_key([$wordset_id], $minimum),
            'The stale worker has passed its earlier generation check.'
        );

        ll_tools_purge_wordset_buttons_shortcode_cache();
        $write_succeeded = ll_tools_store_wordset_button_count_state(
            $state_key,
            $next_state,
            $expected_state,
            $second_token
        );

        $this->assertFalse($write_succeeded);
        $this->assertNotSame($generation, ll_tools_wordset_button_counts_generation_key([$wordset_id], $minimum));
        $this->assertFalse(get_option($state_key, false));
        $this->assertFalse(get_option(ll_tools_wordset_button_count_lock_option($state_key), false));
    }

    public function test_state_registry_evicts_old_state_and_paired_lock_options(): void
    {
        $state_keys = [];
        $lock_keys = [];
        for ($index = 1; $index <= 51; $index++) {
            $state_key = 'll_ws_button_counts_registry_' . $index;
            $lock_key = ll_tools_wordset_button_count_lock_option($state_key);
            add_option($state_key, [
                'index' => $index,
                'expires_at' => time() + HOUR_IN_SECONDS,
            ], '', 'no');
            add_option($lock_key, 'token-' . $index . '|' . (time() + 60), '', 'no');
            ll_tools_wordset_buttons_shortcode_record_lock_key($lock_key);
            ll_tools_wordset_buttons_shortcode_record_state_key($state_key);
            $state_keys[] = $state_key;
            $lock_keys[] = $lock_key;
        }

        $registered_states = get_option('ll_tools_wordset_buttons_shortcode_state_keys', []);
        $registered_locks = get_option('ll_tools_wordset_buttons_shortcode_lock_keys', []);
        $this->assertIsArray($registered_states);
        $this->assertIsArray($registered_locks);
        $this->assertCount(50, $registered_states);
        $this->assertLessThanOrEqual(50, count($registered_locks));
        $this->assertNotContains($state_keys[0], $registered_states);
        $this->assertFalse(get_option($state_keys[0], false));
        $this->assertFalse(get_option($lock_keys[0], false));
        $this->assertContains($state_keys[50], $registered_states);
        $this->assertIsArray(get_option($state_keys[50], false));
    }

    public function test_prompt_card_source_uses_budgeted_keyset_pages(): void
    {
        wp_set_current_user(0);
        $term = wp_insert_term('Buttons Prompt Page Wordset', 'wordset');
        $this->assertIsArray($term);
        $this->assertFalse(is_wp_error($term));
        $wordset_id = (int) ($term['term_id'] ?? 0);
        $category_id = $this->createCustomLessonCategoryForWordset(
            $wordset_id,
            'Buttons Prompt Page Category',
            1,
            false,
            'audio',
            'text_title'
        );
        $word_ids = get_posts([
            'post_type' => 'words',
            'post_status' => 'publish',
            'fields' => 'ids',
            'posts_per_page' => 1,
            'tax_query' => [[
                'taxonomy' => 'wordset',
                'field' => 'term_id',
                'terms' => [$wordset_id],
            ]],
        ]);
        $word_id = (int) ($word_ids[0] ?? 0);
        $this->assertGreaterThan(0, $word_id);
        for ($index = 1; $index <= $this->quizMinWordCount(); $index++) {
            $this->createWordWithAudio(
                'Buttons Prompt Page Eligible Word ' . $index,
                'Buttons Prompt Page Eligible Translation ' . $index,
                $category_id,
                $wordset_id,
                'buttons-prompt-page-eligible-' . $index . '.mp3'
            );
        }

        $category_context = ll_tools_get_word_category_query_context($category_id);
        $category_context = ll_tools_remap_word_category_query_context_for_wordset($category_context, [$wordset_id]);
        $scoped_category_id = (int) ($category_context['term_id'] ?? $category_id);
        $prompt_category_ids = array_values(array_unique(array_filter([$category_id, $scoped_category_id])));
        $prompt_post_type = defined('LL_TOOLS_PROMPT_CARD_POST_TYPE')
            ? (string) LL_TOOLS_PROMPT_CARD_POST_TYPE
            : 'll_prompt_card';
        for ($index = 1; $index <= 25; $index++) {
            $prompt_card_id = self::factory()->post->create([
                'post_type' => $prompt_post_type,
                'post_status' => 'publish',
                'post_title' => 'Buttons Prompt Page Card ' . $index,
            ]);
            wp_set_post_terms($prompt_card_id, $prompt_category_ids, 'word-category', false);
            wp_set_post_terms($prompt_card_id, [$wordset_id], 'wordset', false);
            update_post_meta($prompt_card_id, LL_TOOLS_PROMPT_CARD_CORRECT_ANSWER_WORD_ID_META_KEY, $word_id);
        }

        $all_prompt_ids_complete = true;
        $all_prompt_ids = ll_tools_get_quiz_eligibility_prompt_card_id_batch(
            $scoped_category_id,
            [$wordset_id],
            0,
            100,
            $all_prompt_ids_complete
        );
        $this->assertTrue($all_prompt_ids_complete);
        $this->assertCount(25, $all_prompt_ids);

        $prompt_queries = [];
        $raw_query_count = 0;
        $capture_source_queries = static function (string $sql) use (&$prompt_queries, &$raw_query_count, $prompt_post_type): string {
            if (strpos($sql, 'SELECT DISTINCT p.ID') === false) {
                return $sql;
            }
            if (strpos($sql, "p.post_type = '" . $prompt_post_type . "'") !== false) {
                $prompt_queries[] = $sql;
            } elseif (strpos($sql, "p.post_type = 'words'") !== false) {
                $raw_query_count++;
            }
            return $sql;
        };
        add_filter('query', $capture_source_queries);
        try {
            $resume_context = [
                'schema' => 1,
                'enabled' => true,
                'phases' => [],
            ];
            $query_deltas = [];
            $prompt_cursors = [];
            for ($attempt = 0; $attempt < 5; $attempt++) {
                $before_prompt = count($prompt_queries);
                $before_raw = $raw_query_count;
                $resume_context['budget'] = [
                    'max_raw_queries' => 1,
                    'max_raw_rows' => 10,
                    'raw_queries_used' => 0,
                    'raw_rows_used' => 0,
                    'max_prompt_queries' => 1,
                    'max_prompt_cards' => 10,
                    'prompt_queries_used' => 0,
                    'prompt_cards_used' => 0,
                ];
                $eligibility_complete = true;
                $can_generate = ll_can_category_generate_quiz(
                    $category_id,
                    $this->quizMinWordCount(),
                    [$wordset_id],
                    $eligibility_complete,
                    $resume_context
                );
                $query_deltas[] = [
                    'prompt' => count($prompt_queries) - $before_prompt,
                    'raw' => $raw_query_count - $before_raw,
                ];
                $prompt_cursors[] = (int) ($resume_context['phases']['primary']['prompt_cursor_id'] ?? 0);
                if ($eligibility_complete) {
                    break;
                }
            }
            $before_cached_prompt = count($prompt_queries);
            $before_cached_raw = $raw_query_count;
            $cached_complete = true;
            $cached_result = ll_can_category_generate_quiz(
                $category_id,
                $this->quizMinWordCount(),
                [$wordset_id],
                $cached_complete
            );
        } finally {
            remove_filter('query', $capture_source_queries);
        }

        $this->assertTrue($eligibility_complete);
        $this->assertTrue($can_generate);
        $this->assertCount(3, $prompt_queries);
        $this->assertSame(1, $raw_query_count);
        $this->assertGreaterThan(0, $prompt_cursors[0] ?? 0);
        $this->assertGreaterThan($prompt_cursors[0] ?? 0, $prompt_cursors[1] ?? 0);
        $this->assertSame(max($all_prompt_ids), $prompt_cursors[2] ?? 0);
        foreach ($query_deltas as $query_delta) {
            $this->assertLessThanOrEqual(1, (int) ($query_delta['prompt'] ?? 0));
            $this->assertLessThanOrEqual(1, (int) ($query_delta['raw'] ?? 0));
        }
        foreach ($prompt_queries as $sql) {
            $this->assertMatchesRegularExpression('/LIMIT\s+10\s*$/i', trim($sql));
        }
        $this->assertTrue($cached_complete);
        $this->assertTrue($cached_result);
        $this->assertSame($before_cached_prompt, count($prompt_queries));
        $this->assertSame($before_cached_raw, $raw_query_count);
    }

    public function test_resumable_non_sign_count_preserves_raw_prompt_support_words(): void
    {
        wp_set_current_user(0);
        $wordset_term = wp_insert_term('Buttons Prompt Support Wordset', 'wordset');
        $this->assertIsArray($wordset_term);
        $this->assertFalse(is_wp_error($wordset_term));
        $wordset_id = (int) ($wordset_term['term_id'] ?? 0);
        $category_id = $this->createCustomLessonCategoryForWordset(
            $wordset_id,
            'Buttons Prompt Support Category',
            0,
            true,
            'audio',
            'text_title'
        );

        $word_ids = [];
        for ($index = 1; $index <= $this->quizMinWordCount(); $index++) {
            $word_ids[] = $this->createWordWithAudio(
                'Buttons Prompt Support Word ' . $index,
                'Buttons Prompt Support Translation ' . $index,
                $category_id,
                $wordset_id,
                'buttons-prompt-support-' . $index . '.mp3'
            );
        }
        $this->assertGreaterThanOrEqual(2, count($word_ids));

        $category_context = ll_tools_get_word_category_query_context($category_id);
        $category_context = ll_tools_remap_word_category_query_context_for_wordset($category_context, [$wordset_id]);
        $scoped_category_id = (int) ($category_context['term_id'] ?? $category_id);
        $prompt_category_ids = array_values(array_unique(array_filter([$category_id, $scoped_category_id])));
        $prompt_post_type = defined('LL_TOOLS_PROMPT_CARD_POST_TYPE')
            ? (string) LL_TOOLS_PROMPT_CARD_POST_TYPE
            : 'll_prompt_card';
        $prompt_card_id = self::factory()->post->create([
            'post_type' => $prompt_post_type,
            'post_status' => 'publish',
            'post_title' => 'Buttons Prompt Support Card',
        ]);
        wp_set_post_terms($prompt_card_id, $prompt_category_ids, 'word-category', false);
        wp_set_post_terms($prompt_card_id, [$wordset_id], 'wordset', false);
        update_post_meta($prompt_card_id, LL_TOOLS_PROMPT_CARD_CORRECT_ANSWER_WORD_ID_META_KEY, $word_ids[0]);
        update_post_meta($prompt_card_id, LL_TOOLS_PROMPT_CARD_WRONG_ANSWER_WORD_IDS_META_KEY, [$word_ids[1]]);

        $category_term = get_term($category_id, 'word-category');
        $this->assertInstanceOf(WP_Term::class, $category_term);
        $config = ll_tools_get_category_quiz_config($category_term);
        $default_complete = true;
        $default_count = ll_get_words_by_category_count(
            $category_term,
            'text_title',
            [$wordset_id],
            $config,
            $default_complete,
            $this->quizMinWordCount()
        );

        $resume_context = [
            'schema' => 1,
            'enabled' => true,
            'phases' => [],
            'budget' => [
                'max_prompt_queries' => 1,
                'max_prompt_cards' => 10,
                'prompt_queries_used' => 0,
                'prompt_cards_used' => 0,
                'max_raw_queries' => 1,
                'max_raw_rows' => 10,
                'raw_queries_used' => 0,
                'raw_rows_used' => 0,
            ],
        ];
        $resumable_complete = true;
        $resumable_count = ll_get_words_by_category_count(
            $category_term,
            'text_title',
            [$wordset_id],
            $config,
            $resumable_complete,
            $this->quizMinWordCount(),
            $resume_context
        );

        $this->assertTrue($default_complete);
        $this->assertSame($this->quizMinWordCount(), $default_count);
        $this->assertTrue($resumable_complete);
        $this->assertSame($default_count, $resumable_count);
        $this->assertArrayNotHasKey('prompt_support_ids', $resume_context['phases']['primary'] ?? []);
        $this->assertContains($word_ids[1], $resume_context['phases']['primary']['seen_ids'] ?? []);

        $legacy_seen_ids = array_values(array_filter($word_ids, static function (int $word_id) use ($word_ids): bool {
            return $word_id !== $word_ids[1];
        }));
        $legacy_resume_context = [
            'schema' => 1,
            'enabled' => true,
            'phases' => [
                'primary' => [
                    'schema' => 1,
                    'count' => count($legacy_seen_ids),
                    'seen_ids' => $legacy_seen_ids,
                    'prompt_cursor_id' => $prompt_card_id,
                    'prompt_source_complete' => true,
                    'raw_cursor_id' => max($word_ids),
                    'source_complete' => true,
                    'prompt_support_ids' => [$word_ids[0], $word_ids[1]],
                ],
            ],
            'budget' => [
                'max_prompt_queries' => 1,
                'max_prompt_cards' => 10,
                'prompt_queries_used' => 0,
                'prompt_cards_used' => 0,
                'max_raw_queries' => 1,
                'max_raw_rows' => 10,
                'raw_queries_used' => 0,
                'raw_rows_used' => 0,
            ],
        ];
        $legacy_complete = true;
        $legacy_count = ll_get_words_by_category_count(
            $category_term,
            'text_title',
            [$wordset_id],
            $config,
            $legacy_complete,
            $this->quizMinWordCount(),
            $legacy_resume_context
        );

        $this->assertTrue($legacy_complete);
        $this->assertSame($this->quizMinWordCount(), $legacy_count);
        $this->assertArrayNotHasKey('prompt_support_ids', $legacy_resume_context['phases']['primary'] ?? []);
        $this->assertContains($word_ids[1], $legacy_resume_context['phases']['primary']['seen_ids'] ?? []);
    }

    /**
     * @param array<string,mixed> $post
     * @return array<string,mixed>
     */
    private function runWordsetButtonsStatusAjax(array $post, string $remote_addr): array
    {
        $original_post = $_POST;
        $original_request = $_REQUEST;
        $had_remote_addr = array_key_exists('REMOTE_ADDR', $_SERVER);
        $original_remote_addr = $_SERVER['REMOTE_ADDR'] ?? null;

        $die_handler = static function (): void {
            throw new RuntimeException('wp_die');
        };
        $die_filter = static function () use ($die_handler) {
            return $die_handler;
        };
        $doing_ajax_filter = static function (): bool {
            return true;
        };
        $_POST = $post;
        $_REQUEST = $post;
        $_SERVER['REMOTE_ADDR'] = $remote_addr;
        add_filter('wp_die_handler', $die_filter);
        add_filter('wp_die_ajax_handler', $die_filter);
        add_filter('wp_doing_ajax', $doing_ajax_filter);

        ob_start();
        try {
            ll_tools_wordset_buttons_shortcode_status_ajax();
            $this->fail('Expected wp_die to be called.');
        } catch (RuntimeException $e) {
            $this->assertSame('wp_die', $e->getMessage());
        } finally {
            $output = (string) ob_get_clean();
            remove_filter('wp_die_handler', $die_filter);
            remove_filter('wp_die_ajax_handler', $die_filter);
            remove_filter('wp_doing_ajax', $doing_ajax_filter);
            $_POST = $original_post;
            $_REQUEST = $original_request;
            if ($had_remote_addr) {
                $_SERVER['REMOTE_ADDR'] = $original_remote_addr;
            } else {
                unset($_SERVER['REMOTE_ADDR']);
            }
        }

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded, 'Expected JSON response payload.');

        return $decoded;
    }

    private function countScheduledWordsetButtonRefreshes(array $wordset_ids, int $user_id): int
    {
        $count = 0;
        foreach ((array) _get_cron_array() as $hooks) {
            foreach ((array) ($hooks['ll_tools_refresh_wordset_button_lesson_counts'] ?? []) as $event) {
                if (($event['args'] ?? null) === [$wordset_ids, $user_id]) {
                    $count++;
                }
            }
        }
        return $count;
    }

    private function createPublishedLessonForWordset(int $wordset_id, string $title): int
    {
        $category_id = $this->createLessonCategoryForWordset($wordset_id, $title, $this->quizMinWordCount());
        return $this->createLessonPostForWordsetCategory($wordset_id, $category_id, $title);
    }

    private function createUnquizzablePublishedLessonForWordset(int $wordset_id, string $title): int
    {
        $category_id = $this->createLessonCategoryForWordset($wordset_id, $title, max(0, $this->quizMinWordCount() - 1));
        return $this->createLessonPostForWordsetCategory($wordset_id, $category_id, $title);
    }

    private function createPreviewOnlyLessonForWordset(int $wordset_id, string $title): int
    {
        $category_id = $this->createLessonCategoryForWordset($wordset_id, $title, $this->quizMinWordCount());
        $lesson_id = $this->createLessonPostForWordsetCategory($wordset_id, $category_id, $title);
        update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_PREVIEW_ONLY_META, '1');

        return $lesson_id;
    }

    private function createLessonPostForWordsetCategory(int $wordset_id, int $category_id, string $title): int
    {
        $lesson_id = self::factory()->post->create([
            'post_type' => 'll_vocab_lesson',
            'post_status' => 'publish',
            'post_title' => $title,
        ]);

        update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_WORDSET_META, (string) $wordset_id);
        update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, (string) $category_id);

        return (int) $lesson_id;
    }

    private function createLessonCategoryForWordset(int $wordset_id, string $title, int $word_count): int
    {
        return $this->createCustomLessonCategoryForWordset(
            $wordset_id,
            $title,
            $word_count,
            true,
            'audio',
            'text_title'
        );
    }

    private function createCustomLessonCategoryForWordset(
        int $wordset_id,
        string $title,
        int $word_count,
        bool $with_audio,
        string $prompt_type,
        string $option_type
    ): int
    {
        $term = wp_insert_term($title . ' Category ' . wp_generate_password(4, false), 'word-category');
        $this->assertIsArray($term);
        $this->assertFalse(is_wp_error($term));

        $category_id = (int) ($term['term_id'] ?? 0);
        $this->assertGreaterThan(0, $category_id);

        update_term_meta($category_id, 'll_quiz_prompt_type', $prompt_type);
        update_term_meta($category_id, 'll_quiz_option_type', $option_type);

        for ($index = 1; $index <= $word_count; $index++) {
            if ($with_audio) {
                $this->createWordWithAudio(
                    $title . ' Word ' . $index,
                    $title . ' Translation ' . $index,
                    $category_id,
                    $wordset_id,
                    sanitize_title($title) . '-' . $index . '.mp3'
                );
            } else {
                $this->createWordWithoutAudio(
                    $title . ' Word ' . $index,
                    $title . ' Translation ' . $index,
                    $category_id,
                    $wordset_id
                );
            }
        }

        return $category_id;
    }

    private function createWordWithAudio(string $title, string $translation, int $category_id, int $wordset_id, string $audio_file_name): int
    {
        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => $title . ' ' . wp_generate_password(4, false),
        ]);

        wp_set_post_terms($word_id, [$category_id], 'word-category', false);
        wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);
        update_post_meta($word_id, 'word_translation', $translation);

        $audio_post_id = self::factory()->post->create([
            'post_type' => 'word_audio',
            'post_status' => 'publish',
            'post_parent' => $word_id,
            'post_title' => 'Audio ' . $title,
        ]);
        update_post_meta($audio_post_id, 'audio_file_path', '/wp-content/uploads/' . $audio_file_name);

        return (int) $word_id;
    }

    private function createWordWithoutAudio(
        string $title,
        string $translation,
        int $category_id,
        int $wordset_id
    ): int {
        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => $title . ' ' . wp_generate_password(4, false),
        ]);

        wp_set_post_terms($word_id, [$category_id], 'word-category', false);
        wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);
        update_post_meta($word_id, 'word_translation', $translation);

        return (int) $word_id;
    }

    private function quizMinWordCount(): int
    {
        $min_word_count = defined('LL_TOOLS_MIN_WORDS_PER_QUIZ')
            ? (int) apply_filters('ll_tools_quiz_min_words', LL_TOOLS_MIN_WORDS_PER_QUIZ)
            : 5;

        return max(1, $min_word_count);
    }

    private function createImageAttachment(string $filename): int
    {
        $bytes = base64_decode(self::ONE_PIXEL_PNG_BASE64, true);
        $this->assertIsString($bytes);

        $upload = wp_upload_bits($filename, null, $bytes);
        $this->assertIsArray($upload);
        $this->assertSame('', (string) ($upload['error'] ?? ''));

        $file_path = (string) ($upload['file'] ?? '');
        $this->assertNotSame('', $file_path);
        $this->assertFileExists($file_path);

        $filetype = wp_check_filetype(basename($file_path), null);
        $attachment_id = wp_insert_attachment([
            'post_mime_type' => (string) ($filetype['type'] ?? 'image/png'),
            'post_title' => preg_replace('/\.[^.]+$/', '', basename($file_path)),
            'post_content' => '',
            'post_status' => 'inherit',
        ], $file_path);

        $this->assertIsInt($attachment_id);
        $this->assertGreaterThan(0, $attachment_id);

        $metadata = function_exists('wp_generate_attachment_metadata')
            ? wp_generate_attachment_metadata($attachment_id, $file_path)
            : [];
        if (is_array($metadata) && !empty($metadata)) {
            wp_update_attachment_metadata($attachment_id, $metadata);
        }

        $relative_path = function_exists('_wp_relative_upload_path')
            ? (string) _wp_relative_upload_path($file_path)
            : '';
        if ($relative_path === '') {
            $relative_path = ltrim((string) wp_normalize_path($file_path), '/');
        }
        update_post_meta($attachment_id, '_wp_attached_file', $relative_path);

        return (int) $attachment_id;
    }
}
