<?php
declare(strict_types=1);

final class LmsRestApiTest extends LL_Tools_TestCase
{
    protected function tearDown(): void
    {
        wp_set_current_user(0);
        parent::tearDown();
    }

    public function test_lms_routes_are_registered_with_separate_teacher_and_learner_surfaces(): void
    {
        do_action('rest_api_init');
        $routes = rest_get_server()->get_routes();

        $this->assertArrayHasKey('/ll-tools/v1/lms/assignments', $routes);
        $this->assertArrayHasKey('/ll-tools/v1/lms/classes/(?P<class_id>\d+)/assignments', $routes);
        $this->assertTrue($this->route_pattern_exists($routes, '#^/ll-tools/v1/lms/assignments/\(\?P<assignment_uuid>.+/attempts$#'));
        $this->assertTrue($this->route_pattern_exists($routes, '#^/ll-tools/v1/lms/attempts/\(\?P<attempt_uuid>.+/answers$#'));
        $this->assertTrue($this->route_pattern_exists($routes, '#^/ll-tools/v1/lms/attempts/\(\?P<attempt_uuid>.+/finalize$#'));
    }

    public function test_assignment_json_contract_rejects_scores_unknown_fields_and_oversized_bodies(): void
    {
        $score_request = $this->json_request(['answer_uuid' => wp_generate_uuid4(), 'item_key' => 'item-1', 'option_key' => 'a', 'score' => 1]);
        $score_result = ll_tools_lms_rest_json_params($score_request, ['answer_uuid', 'item_key', 'option_key']);
        $this->assertWPError($score_result);
        $this->assertSame('ll_tools_lms_rest_unknown_field', $score_result->get_error_code());

        $correctness_request = $this->json_request(['answer_uuid' => wp_generate_uuid4(), 'item_key' => 'item-1', 'option_key' => 'a', 'is_correct' => true]);
        $correctness_result = ll_tools_lms_rest_json_params($correctness_request, ['answer_uuid', 'item_key', 'option_key']);
        $this->assertWPError($correctness_result);
        $this->assertSame('ll_tools_lms_rest_unknown_field', $correctness_result->get_error_code());

        $large = new WP_REST_Request('POST', '/ll-tools/v1/lms/assignments');
        $large->set_header('content-type', 'application/json');
        $large->set_body(wp_json_encode(['title' => str_repeat('x', LL_TOOLS_LMS_REST_BODY_MAX_BYTES)]));
        $large_result = ll_tools_lms_rest_json_params($large, ['title']);
        $this->assertWPError($large_result);
        $this->assertSame('ll_tools_lms_rest_body_too_large', $large_result->get_error_code());
        $this->assertSame(413, (int) ($large_result->get_error_data()['status'] ?? 0));
    }

    public function test_finalize_rejects_every_client_supplied_grade_field_before_domain_code_runs(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));
        $request = $this->json_request(['score_given' => 5, 'score_maximum' => 5]);
        $request->set_param('attempt_uuid', wp_generate_uuid4());

        $result = ll_tools_lms_rest_finalize_attempt($request);

        $this->assertWPError($result);
        $this->assertSame('ll_tools_lms_rest_unknown_field', $result->get_error_code());
        $this->assertSame(400, (int) ($result->get_error_data()['status'] ?? 0));
    }

    public function test_permissions_distinguish_anonymous_learners_and_class_managers(): void
    {
        wp_set_current_user(0);
        $anonymous = ll_tools_lms_rest_logged_in_permission();
        $this->assertWPError($anonymous);
        $this->assertSame(401, (int) ($anonymous->get_error_data()['status'] ?? 0));

        $learner_id = self::factory()->user->create(['role' => 'll_tools_learner']);
        wp_set_current_user($learner_id);
        $this->assertTrue(ll_tools_lms_rest_logged_in_permission());
        $learner_teacher_permission = ll_tools_lms_rest_teacher_permission();
        $this->assertWPError($learner_teacher_permission);
        $this->assertSame(403, (int) ($learner_teacher_permission->get_error_data()['status'] ?? 0));

        $teacher_id = self::factory()->user->create(['role' => 'll_tools_teacher']);
        wp_set_current_user($teacher_id);
        $this->assertTrue(ll_tools_lms_rest_teacher_permission());
    }

    public function test_domain_errors_receive_stable_rest_statuses_without_losing_retry_metadata(): void
    {
        $schema = new WP_Error('lms_assignment_schema_unavailable', 'Unavailable', ['retryable' => true]);
        $prepared = ll_tools_lms_rest_prepare_error($schema);
        $this->assertSame(503, (int) ($prepared->get_error_data()['status'] ?? 0));
        $this->assertTrue((bool) ($prepared->get_error_data()['retryable'] ?? false));

        $this->assertSame(403, ll_tools_lms_rest_error_status('assignment_membership_required'));
        $this->assertSame(404, ll_tools_lms_rest_error_status('assignment_attempt_not_found'));
        $this->assertSame(409, ll_tools_lms_rest_error_status('assignment_attempt_limit_reached'));
        $this->assertSame(400, ll_tools_lms_rest_error_status('invalid_assignment_answer'));
    }

    /** @param array<string,mixed> $payload */
    private function json_request(array $payload): WP_REST_Request
    {
        $request = new WP_REST_Request('POST', '/ll-tools/v1/lms/test');
        $request->set_header('content-type', 'application/json');
        $encoded = wp_json_encode($payload);
        $this->assertIsString($encoded);
        $request->set_body($encoded);
        return $request;
    }

    /** @param array<string,mixed> $routes */
    private function route_pattern_exists(array $routes, string $pattern): bool
    {
        foreach (array_keys($routes) as $route) {
            if (preg_match($pattern, (string) $route) === 1) {
                return true;
            }
        }
        return false;
    }
}
