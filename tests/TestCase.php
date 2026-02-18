<?php
declare(strict_types=1);

abstract class LL_Tools_TestCase extends WP_UnitTestCase
{
    /** @var int */
    protected $original_user_id = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->original_user_id = get_current_user_id();
    }

    protected function tearDown(): void
    {
        wp_set_current_user($this->original_user_id);
        parent::tearDown();
    }
}
