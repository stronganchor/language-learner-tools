<?php
declare(strict_types=1);

namespace Elementor {
    if (!class_exists(__NAMESPACE__ . '\\Plugin', false)) {
        class Plugin
        {
            public static $instance;
        }
    }

    if (!class_exists(__NAMESPACE__ . '\\LLToolsFakeFrontend', false)) {
        class LLToolsFakeFrontend
        {
            public int $enqueueCalls = 0;

            public function enqueue_scripts(): void
            {
                $this->enqueueCalls++;
                \wp_add_inline_script(
                    'elementor-frontend',
                    'var elementorFrontendConfig = {"urls":{"assets":"https://example.test/wp-content/plugins/elementor/assets/"}};',
                    'before'
                );
            }
        }
    }
}

namespace {
    final class WordsetPageElementorCompatTest extends LL_Tools_TestCase
    {
        protected function setUp(): void
        {
            parent::setUp();
            set_query_var('ll_wordset_page', '');
            $_GET = [];
            \Elementor\Plugin::$instance = null;
        }

        protected function tearDown(): void
        {
            wp_dequeue_script('elementor-frontend');
            wp_deregister_script('elementor-frontend');
            set_query_var('ll_wordset_page', '');
            $_GET = [];
            \Elementor\Plugin::$instance = null;
            parent::tearDown();
        }

        public function test_wordset_page_prints_missing_elementor_frontend_config_when_script_is_enqueued(): void
        {
            $frontend = new \Elementor\LLToolsFakeFrontend();
            \Elementor\Plugin::$instance = (object) [
                'frontend' => $frontend,
            ];

            set_query_var('ll_wordset_page', 'biblical-hebrew');
            wp_register_script('elementor-frontend', false, [], null, true);
            wp_enqueue_script('elementor-frontend');

            ll_tools_wordset_page_ensure_elementor_frontend_config();

            $this->assertSame(1, $frontend->enqueueCalls);
            $before = wp_scripts()->get_data('elementor-frontend', 'before');
            $this->assertIsArray($before);
            $this->assertStringContainsString('elementorFrontendConfig', implode("\n", $before));
        }

        public function test_wordset_page_does_not_duplicate_existing_elementor_frontend_config(): void
        {
            $frontend = new \Elementor\LLToolsFakeFrontend();
            \Elementor\Plugin::$instance = (object) [
                'frontend' => $frontend,
            ];

            set_query_var('ll_wordset_page', 'biblical-hebrew');
            wp_register_script('elementor-frontend', false, [], null, true);
            wp_enqueue_script('elementor-frontend');
            wp_add_inline_script('elementor-frontend', 'var elementorFrontendConfig = {};', 'before');

            ll_tools_wordset_page_ensure_elementor_frontend_config();

            $this->assertSame(0, $frontend->enqueueCalls);
        }
    }
}
