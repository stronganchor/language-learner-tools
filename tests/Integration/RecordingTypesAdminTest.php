<?php
declare(strict_types=1);

final class RecordingTypesAdminTest extends LL_Tools_TestCase
{
    private int $adminUserId = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUserId = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($this->adminUserId);

        delete_option('ll_uncategorized_desired_recording_types');
        $_POST = [];
        $_REQUEST = [];
    }

    protected function tearDown(): void
    {
        delete_option('ll_uncategorized_desired_recording_types');
        $_POST = [];
        $_REQUEST = [];

        parent::tearDown();
    }

    public function test_admin_page_add_term_creates_recording_type(): void
    {
        $_POST = [
            'add_term' => '1',
            'term_name' => 'Prompt',
            'term_slug' => 'prompt',
            '_wpnonce' => wp_create_nonce('ll_add_recording_type'),
        ];

        $output = $this->renderRecordingTypesPage();
        $term = get_term_by('slug', 'prompt', 'recording_type');

        $this->assertInstanceOf(WP_Term::class, $term);
        $this->assertStringContainsString('Recording type added successfully.', $output);
    }

    public function test_admin_page_delete_term_removes_recording_type(): void
    {
        $term = wp_insert_term('Delete Me', 'recording_type', ['slug' => 'delete-me']);
        $this->assertIsArray($term);
        $termId = (int) $term['term_id'];

        $_POST = [
            'delete_term' => '1',
            'term_id' => (string) $termId,
            '_wpnonce' => wp_create_nonce('ll_delete_recording_type'),
        ];

        $output = $this->renderRecordingTypesPage();

        $this->assertNull(term_exists($termId, 'recording_type'));
        $this->assertStringContainsString('Recording type deleted successfully.', $output);
    }

    public function test_admin_page_saves_uncategorized_defaults_after_filtering_invalid_slugs(): void
    {
        $alpha = wp_insert_term('Alpha Type', 'recording_type', ['slug' => 'alpha-type']);
        $beta = wp_insert_term('Beta Type', 'recording_type', ['slug' => 'beta-type']);
        $this->assertIsArray($alpha);
        $this->assertIsArray($beta);

        $_POST = [
            'save_uncategorized_defaults' => '1',
            'll_uncategorized_desired_recording_types' => ['alpha-type', 'invalid-type', 'beta-type', 'alpha-type'],
            '_wpnonce' => wp_create_nonce('ll_save_uncategorized_defaults'),
        ];

        $output = $this->renderRecordingTypesPage();
        $stored = get_option('ll_uncategorized_desired_recording_types', []);
        sort($stored);

        $this->assertSame(['alpha-type', 'beta-type'], $stored);
        $this->assertStringContainsString('Uncategorized defaults updated.', $output);
    }

    public function test_admin_page_reset_uncategorized_defaults_restores_main_default_behavior(): void
    {
        update_option('ll_uncategorized_desired_recording_types', ['question']);

        $_POST = [
            'save_uncategorized_defaults' => '1',
            '_wpnonce' => wp_create_nonce('ll_save_uncategorized_defaults'),
        ];

        $output = $this->renderRecordingTypesPage();

        $this->assertFalse(get_option('ll_uncategorized_desired_recording_types', false));
        $this->assertSame(['isolation'], ll_tools_get_uncategorized_desired_recording_types());
        $this->assertStringContainsString('Uncategorized defaults reset to main recording types.', $output);
    }

    private function renderRecordingTypesPage(): string
    {
        $_REQUEST = $_POST;
        ob_start();
        ll_render_recording_types_admin_page();
        return (string) ob_get_clean();
    }
}
