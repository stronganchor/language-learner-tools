<?php
declare(strict_types=1);

final class WordsetInviteTokenTest extends LL_Tools_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (function_exists('ll_tools_register_wordset_taxonomy')) {
            ll_tools_register_wordset_taxonomy();
        }

        unset(
            $GLOBALS['ll_tools_recorder_invite_request_context'],
            $GLOBALS['ll_tools_wordset_manager_invite_request_context']
        );
        unset($_REQUEST[LL_TOOLS_RECORDER_INVITE_QUERY_ARG], $_REQUEST[LL_TOOLS_WORDSET_MANAGER_INVITE_QUERY_ARG]);
    }

    protected function tearDown(): void
    {
        unset(
            $GLOBALS['ll_tools_recorder_invite_request_context'],
            $GLOBALS['ll_tools_wordset_manager_invite_request_context']
        );
        unset($_REQUEST[LL_TOOLS_RECORDER_INVITE_QUERY_ARG], $_REQUEST[LL_TOOLS_WORDSET_MANAGER_INVITE_QUERY_ARG]);

        parent::tearDown();
    }

    public function test_role_wrappers_preserve_the_legacy_token_bytes(): void
    {
        $wordset_id = $this->createWordset();
        $expires_at = time() + HOUR_IN_SECONDS;
        $email = 'invitee@example.com';

        $recorder_expected = $this->legacyToken('recorder', $wordset_id, $email, $expires_at);
        $manager_expected = $this->legacyToken('manager', $wordset_id, $email, $expires_at);

        $this->assertSame($recorder_expected, ll_tools_recorder_invite_build_token($wordset_id, [
            'email' => strtoupper($email),
            'expires_at' => $expires_at,
        ]));
        $this->assertSame($manager_expected, ll_tools_wordset_manager_invite_build_token($wordset_id, [
            'email' => strtoupper($email),
            'expires_at' => $expires_at,
        ]));

        $recorder = ll_tools_recorder_invite_parse_token($recorder_expected);
        $manager = ll_tools_wordset_manager_invite_parse_token($manager_expected);
        $this->assertIsArray($recorder);
        $this->assertIsArray($manager);
        $this->assertSame($wordset_id, (int) $recorder['wordset_id']);
        $this->assertSame($wordset_id, (int) $manager['wordset_id']);
        $this->assertSame($email, (string) $recorder['email']);
        $this->assertSame($email, (string) $manager['email']);
    }

    public function test_tokens_reject_tampering_cross_role_reuse_expiry_and_deleted_wordsets(): void
    {
        $wordset_id = $this->createWordset();
        $expires_at = time() + HOUR_IN_SECONDS;
        $recorder_token = $this->legacyToken('recorder', $wordset_id, '', $expires_at);
        $manager_token = $this->legacyToken('manager', $wordset_id, '', $expires_at);

        $tampered = substr($recorder_token, 0, -1) . (substr($recorder_token, -1) === 'a' ? 'b' : 'a');
        $this->assertWpErrorCode('invalid_invite', ll_tools_recorder_invite_parse_token($tampered));
        $this->assertWpErrorCode('invalid_invite', ll_tools_wordset_manager_invite_parse_token($recorder_token));
        $this->assertWpErrorCode('invalid_invite', ll_tools_recorder_invite_parse_token($manager_token));

        $expired = $this->legacyToken('recorder', $wordset_id, '', time() - 1);
        $this->assertWpErrorCode('expired_invite', ll_tools_recorder_invite_parse_token($expired));

        wp_delete_term($wordset_id, 'wordset');
        $this->assertWpErrorCode('missing_wordset', ll_tools_wordset_manager_invite_parse_token($manager_token));
    }

    public function test_signed_malformed_payloads_and_oversized_request_values_fail_closed(): void
    {
        $wordset_id = $this->createWordset();
        $token = $this->signedPayload('recorder', [
            'wid' => $wordset_id,
            'email' => "invitee@example.com\ninvalid",
            'exp' => time() + HOUR_IN_SECONDS,
        ]);
        $this->assertWpErrorCode('invalid_invite', ll_tools_recorder_invite_parse_token($token));

        $_REQUEST[LL_TOOLS_RECORDER_INVITE_QUERY_ARG] = str_repeat('a', 4097);
        $this->assertSame('', ll_tools_recorder_invite_get_request_token());
        $this->assertSame([], ll_tools_recorder_invite_get_request_context());

        unset($GLOBALS['ll_tools_recorder_invite_request_context']);
        $_REQUEST[LL_TOOLS_RECORDER_INVITE_QUERY_ARG] = ['unexpected'];
        $this->assertSame('', ll_tools_recorder_invite_get_request_token());
        $this->assertSame([], ll_tools_recorder_invite_get_request_context());
    }

    public function test_manager_acceptance_rejects_a_different_email_address(): void
    {
        $wordset_id = $this->createWordset();
        $user_id = self::factory()->user->create([
            'role' => 'subscriber',
            'user_email' => 'other@example.com',
        ]);
        $token = ll_tools_wordset_manager_invite_build_token($wordset_id, [
            'email' => 'invitee@example.com',
            'expires_at' => time() + HOUR_IN_SECONDS,
        ]);

        $result = ll_tools_wordset_manager_invite_accept_for_user($token, $user_id);

        $this->assertWpErrorCode('wrong_user', $result);
        $user = get_userdata($user_id);
        $this->assertInstanceOf(WP_User::class, $user);
        $this->assertNotContains('wordset_manager', (array) $user->roles);
    }

    private function createWordset(): int
    {
        $term = wp_insert_term('Invite Token ' . wp_generate_password(8, false, false), 'wordset');
        $this->assertIsArray($term);
        $this->assertFalse(is_wp_error($term));
        return (int) $term['term_id'];
    }

    private function legacyToken(string $invite_type, int $wordset_id, string $email, int $expires_at): string
    {
        return $this->signedPayload($invite_type, [
            'wid' => $wordset_id,
            'email' => strtolower($email),
            'exp' => $expires_at,
        ]);
    }

    /** @param array<string,mixed> $payload */
    private function signedPayload(string $invite_type, array $payload): string
    {
        $encoded = ll_tools_recorder_invite_base64url_encode((string) wp_json_encode($payload));
        $secret = $invite_type === 'manager'
            ? ll_tools_wordset_manager_invite_secret()
            : ll_tools_recorder_invite_secret();
        return $encoded . '.' . hash_hmac('sha256', $encoded, $secret);
    }

    private function assertWpErrorCode(string $expected, $actual): void
    {
        $this->assertInstanceOf(WP_Error::class, $actual);
        $this->assertSame($expected, $actual->get_error_code());
    }
}
