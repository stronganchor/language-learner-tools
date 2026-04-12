<?php
declare(strict_types=1);

final class WordsetTemplateCreationActionTest extends LL_Tools_TestCase
{
    /** @var array<string,mixed> */
    private $serverBackup = [];

    /** @var array<string,mixed> */
    private $getBackup = [];

    /** @var array<string,mixed> */
    private $postBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->serverBackup = $_SERVER;
        $this->getBackup = $_GET;
        $this->postBackup = $_POST;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        $_GET = $this->getBackup;
        $_POST = $this->postBackup;
        set_query_var('ll_wordset_page', null);
        set_query_var('ll_wordset_view', null);

        parent::tearDown();
    }

    public function test_template_action_creates_new_wordset_with_copied_categories_images_and_settings(): void
    {
        $adminId = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($adminId);

        $fixture = $this->createTemplateFixture();
        $newWordsetName = 'Template Target ' . wp_generate_password(6, false);
        $newWordsetSlug = 'template-target-' . strtolower(wp_generate_password(6, false, false));

        $redirectUrl = $this->runTemplateRequest((string) $fixture['wordset_slug'], [
            'll_wordset_manager_template_action' => 'create',
            'll_wordset_manager_template_wordset_id' => (string) $fixture['wordset_id'],
            'll_wordset_manager_template_name' => $newWordsetName,
            'll_wordset_manager_template_slug' => $newWordsetSlug,
            'll_wordset_manager_template_copy_settings' => '1',
            'll_wordset_manager_template_nonce' => wp_create_nonce('ll_wordset_manager_template_' . (int) $fixture['wordset_id']),
        ]);

        $query = $this->parseRedirectQuery($redirectUrl);
        $this->assertSame('ok', (string) ($query['ll_wordset_manager_template'] ?? ''));
        $this->assertSame('language', (string) ($query['ll_wordset_tool'] ?? ''));
        $this->assertSame('2', (string) ($query['ll_wordset_manager_template_categories'] ?? ''));
        $this->assertSame('2', (string) ($query['ll_wordset_manager_template_images'] ?? ''));
        $this->assertSame('1', (string) ($query['ll_wordset_manager_template_settings'] ?? ''));

        $targetWordset = get_term_by('slug', $newWordsetSlug, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $targetWordset);
        $targetWordsetId = (int) $targetWordset->term_id;

        $this->assertSame((string) $adminId, (string) get_term_meta($targetWordsetId, 'manager_user_id', true));
        $this->assertSame('private', (string) get_term_meta($targetWordsetId, LL_TOOLS_WORDSET_VISIBILITY_META_KEY, true));
        $this->assertSame('1', (string) get_term_meta($targetWordsetId, 'll_wordset_hide_lesson_text_for_non_text_quiz', true));
        $this->assertSame('', (string) get_term_meta($targetWordsetId, 'll_language', true));

        $sourceCategoryAId = (int) $fixture['category_a_id'];
        $sourceCategoryBId = (int) $fixture['category_b_id'];
        $targetCategoryAId = ll_tools_get_existing_isolated_category_copy_id(
            ll_tools_get_category_isolation_source_id($sourceCategoryAId),
            $targetWordsetId
        );
        $targetCategoryBId = ll_tools_get_existing_isolated_category_copy_id(
            ll_tools_get_category_isolation_source_id($sourceCategoryBId),
            $targetWordsetId
        );

        $this->assertGreaterThan(0, $targetCategoryAId);
        $this->assertGreaterThan(0, $targetCategoryBId);
        $this->assertNotSame($sourceCategoryAId, $targetCategoryAId);
        $this->assertNotSame($sourceCategoryBId, $targetCategoryBId);
        $this->assertSame('image', (string) get_term_meta($targetCategoryAId, 'll_quiz_prompt_type', true));
        $this->assertSame('text_title', (string) get_term_meta($targetCategoryBId, 'll_quiz_option_type', true));

        $manualOrder = get_term_meta($targetWordsetId, 'll_wordset_category_manual_order', true);
        $this->assertSame([$targetCategoryBId, $targetCategoryAId], $manualOrder);

        $prereqMap = get_term_meta($targetWordsetId, 'll_wordset_category_prerequisites', true);
        $this->assertSame([$targetCategoryBId => [$targetCategoryAId]], $prereqMap);

        $targetImageAId = ll_tools_get_existing_isolated_word_image_copy_id(
            ll_tools_get_word_image_isolation_source_id((int) $fixture['image_a_id']),
            $targetWordsetId
        );
        $targetImageBId = ll_tools_get_existing_isolated_word_image_copy_id(
            ll_tools_get_word_image_isolation_source_id((int) $fixture['image_b_id']),
            $targetWordsetId
        );

        $this->assertGreaterThan(0, $targetImageAId);
        $this->assertGreaterThan(0, $targetImageBId);
        $this->assertNotSame((int) $fixture['image_a_id'], $targetImageAId);
        $this->assertNotSame((int) $fixture['image_b_id'], $targetImageBId);

        $targetImageACategoryIds = wp_get_post_terms($targetImageAId, 'word-category', ['fields' => 'ids']);
        $this->assertSame([$targetCategoryAId], array_values(array_map('intval', $targetImageACategoryIds)));
        $this->assertSame((string) $targetWordsetId, (string) get_post_meta($targetImageAId, LL_TOOLS_WORD_IMAGE_WORDSET_OWNER_META_KEY, true));
    }

    public function test_template_action_redirects_permission_error_for_logged_out_user(): void
    {
        $fixture = $this->createTemplateFixture();
        wp_set_current_user(0);

        $redirectUrl = $this->runTemplateRequest((string) $fixture['wordset_slug'], [
            'll_wordset_manager_template_action' => 'create',
            'll_wordset_manager_template_wordset_id' => (string) $fixture['wordset_id'],
            'll_wordset_manager_template_name' => 'Denied Template Copy',
            'll_wordset_manager_template_nonce' => wp_create_nonce('ll_wordset_manager_template_' . (int) $fixture['wordset_id']),
        ]);

        $query = $this->parseRedirectQuery($redirectUrl);
        $this->assertSame('error', (string) ($query['ll_wordset_manager_template'] ?? ''));
        $this->assertSame('permission', (string) ($query['ll_wordset_manager_template_error'] ?? ''));
    }

    public function test_template_action_redirects_nonce_error_for_invalid_nonce(): void
    {
        $adminId = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($adminId);

        $fixture = $this->createTemplateFixture();

        $redirectUrl = $this->runTemplateRequest((string) $fixture['wordset_slug'], [
            'll_wordset_manager_template_action' => 'create',
            'll_wordset_manager_template_wordset_id' => (string) $fixture['wordset_id'],
            'll_wordset_manager_template_name' => 'Invalid Nonce Template Copy',
            'll_wordset_manager_template_nonce' => 'invalid',
        ]);

        $query = $this->parseRedirectQuery($redirectUrl);
        $this->assertSame('error', (string) ($query['ll_wordset_manager_template'] ?? ''));
        $this->assertSame('nonce', (string) ($query['ll_wordset_manager_template_error'] ?? ''));
    }

    /**
     * @return array{
     *   wordset_id:int,
     *   wordset_slug:string,
     *   category_a_id:int,
     *   category_b_id:int,
     *   image_a_id:int,
     *   image_b_id:int
     * }
     */
    private function createTemplateFixture(): array
    {
        $wordset = wp_insert_term('Template Source ' . wp_generate_password(6, false), 'wordset');
        $this->assertFalse(is_wp_error($wordset));
        $this->assertIsArray($wordset);

        $wordsetId = (int) $wordset['term_id'];
        $wordsetSlug = (string) get_term_field('slug', $wordsetId, 'wordset');

        update_term_meta($wordsetId, 'll_language', 'Spanish');
        update_term_meta($wordsetId, LL_TOOLS_WORDSET_VISIBILITY_META_KEY, 'private');
        update_term_meta($wordsetId, 'll_wordset_hide_lesson_text_for_non_text_quiz', '1');
        update_term_meta($wordsetId, 'll_wordset_category_ordering_mode', 'manual');

        $categoryAId = (int) ll_tools_create_or_get_wordset_category('Template Category A ' . wp_generate_password(4, false), $wordsetId);
        $categoryBId = (int) ll_tools_create_or_get_wordset_category('Template Category B ' . wp_generate_password(4, false), $wordsetId);
        $this->assertGreaterThan(0, $categoryAId);
        $this->assertGreaterThan(0, $categoryBId);

        update_term_meta($categoryAId, 'll_quiz_prompt_type', 'image');
        update_term_meta($categoryAId, 'll_quiz_option_type', 'text_title');
        update_term_meta($categoryBId, 'll_quiz_prompt_type', 'text_translation');
        update_term_meta($categoryBId, 'll_quiz_option_type', 'text_title');

        update_term_meta($wordsetId, 'll_wordset_category_manual_order', [$categoryBId, $categoryAId]);
        update_term_meta($wordsetId, 'll_wordset_category_prerequisites', [$categoryBId => [$categoryAId]]);

        $imageAId = self::factory()->post->create([
            'post_type' => 'word_images',
            'post_status' => 'publish',
            'post_title' => 'Template Image A ' . wp_generate_password(4, false),
        ]);
        wp_set_post_terms($imageAId, [$categoryAId], 'word-category', false);
        ll_tools_set_word_image_wordset_owner($imageAId, $wordsetId, $imageAId);

        $imageBId = self::factory()->post->create([
            'post_type' => 'word_images',
            'post_status' => 'publish',
            'post_title' => 'Template Image B ' . wp_generate_password(4, false),
        ]);
        wp_set_post_terms($imageBId, [$categoryBId], 'word-category', false);
        ll_tools_set_word_image_wordset_owner($imageBId, $wordsetId, $imageBId);

        return [
            'wordset_id' => $wordsetId,
            'wordset_slug' => $wordsetSlug,
            'category_a_id' => $categoryAId,
            'category_b_id' => $categoryBId,
            'image_a_id' => $imageAId,
            'image_b_id' => $imageBId,
        ];
    }

    /**
     * @param array<string,string> $overrides
     */
    private function runTemplateRequest(string $wordsetSlug, array $overrides): string
    {
        $wordsetTerm = get_term_by('slug', $wordsetSlug, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordsetTerm);

        $_GET = [];
        $_POST = array_merge([
            'll_wordset_page' => $wordsetSlug,
            'll_wordset_view' => 'settings',
            'll_wordset_tool' => 'template',
        ], $overrides);
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = $this->requestUriFromUrl(ll_tools_get_wordset_settings_tool_url($wordsetTerm, 'template'));
        set_query_var('ll_wordset_page', $wordsetSlug);
        set_query_var('ll_wordset_view', 'settings');

        return $this->captureRedirect(static function (): void {
            ll_tools_wordset_page_handle_manager_template_action();
        });
    }

    private function requestUriFromUrl(string $url): string
    {
        $path = (string) wp_parse_url($url, PHP_URL_PATH);
        $query = (string) wp_parse_url($url, PHP_URL_QUERY);

        return $path . ($query !== '' ? ('?' . $query) : '');
    }

    /**
     * @return array<string,string>
     */
    private function parseRedirectQuery(string $url): array
    {
        $query = (string) wp_parse_url($url, PHP_URL_QUERY);
        parse_str($query, $parsed);

        $normalized = [];
        foreach ($parsed as $key => $value) {
            if (is_scalar($value)) {
                $normalized[(string) $key] = (string) $value;
            }
        }

        return $normalized;
    }

    private function captureRedirect(callable $callback): string
    {
        $redirect = '';
        add_filter('wp_redirect', static function (string $location) use (&$redirect): string {
            $redirect = $location;
            throw new RuntimeException('redirect intercepted');
        });

        try {
            $callback();
        } catch (RuntimeException $exception) {
            $this->assertSame('redirect intercepted', $exception->getMessage());
        } finally {
            remove_all_filters('wp_redirect');
        }

        $this->assertNotSame('', $redirect, 'Expected redirect was not triggered.');
        return $redirect;
    }
}
