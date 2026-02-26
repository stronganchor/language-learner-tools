<?php
declare(strict_types=1);

final class ExternalApiWrappersTest extends LL_Tools_TestCase
{
    /** @var string[] */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        update_option('ll_deepl_api_key', '');
        update_option('ll_assemblyai_api_key', '');
        delete_transient('deepl_language_json_target');
        delete_transient('deepl_language_json_source');
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (is_string($path) && $path !== '' && file_exists($path)) {
                @unlink($path);
            }
        }
        $this->tempFiles = [];

        delete_transient('deepl_language_json_target');
        delete_transient('deepl_language_json_source');
        delete_option('ll_deepl_api_key');
        delete_option('ll_assemblyai_api_key');

        parent::tearDown();
    }

    public function test_translate_with_deepl_sends_expected_request_and_parses_response(): void
    {
        update_option('ll_deepl_api_key', 'deepl-test-key');

        $requests = [];
        $httpFilter = static function ($pre, array $args, string $url) use (&$requests) {
            $requests[] = [
                'url' => $url,
                'args' => $args,
            ];

            if ($url === 'https://api-free.deepl.com/v2/translate') {
                return [
                    'headers'  => [],
                    'body'     => wp_json_encode([
                        'translations' => [
                            ['text' => 'Hello world'],
                        ],
                    ]),
                    'response' => ['code' => 200, 'message' => 'OK'],
                    'cookies'  => [],
                    'filename' => null,
                ];
            }

            return $pre;
        };

        add_filter('pre_http_request', $httpFilter, 10, 3);
        try {
            $translated = translate_with_deepl('Merhaba dünya', 'EN', 'TR');
        } finally {
            remove_filter('pre_http_request', $httpFilter, 10);
        }

        $this->assertSame('Hello world', $translated);
        $this->assertCount(1, $requests);
        $request = $requests[0];
        $this->assertSame('https://api-free.deepl.com/v2/translate', $request['url']);
        $this->assertSame('DeepL-Auth-Key deepl-test-key', $request['args']['headers']['Authorization'] ?? null);
        $this->assertSame('Merhaba dünya', $request['args']['body']['text'] ?? null);
        $this->assertSame('EN', $request['args']['body']['target_lang'] ?? null);
        $this->assertSame('TR', $request['args']['body']['source_lang'] ?? null);
    }

    public function test_get_deepl_language_json_uses_transient_cache_after_first_success(): void
    {
        update_option('ll_deepl_api_key', 'deepl-test-key');

        $requestCount = 0;
        $httpFilter = static function ($pre, array $args, string $url) use (&$requestCount) {
            if (strpos($url, 'https://api-free.deepl.com/v2/languages') === 0) {
                $requestCount++;
                return [
                    'headers'  => [],
                    'body'     => wp_json_encode([
                        ['language' => 'EN', 'name' => 'English'],
                        ['language' => 'TR', 'name' => 'Turkish'],
                    ]),
                    'response' => ['code' => 200, 'message' => 'OK'],
                    'cookies'  => [],
                    'filename' => null,
                ];
            }

            return $pre;
        };

        add_filter('pre_http_request', $httpFilter, 10, 3);
        try {
            $first = get_deepl_language_json('target');
            $second = get_deepl_language_json('target');
        } finally {
            remove_filter('pre_http_request', $httpFilter, 10);
        }

        $this->assertIsArray($first);
        $this->assertSame($first, $second);
        $this->assertSame(1, $requestCount);

        $cached = get_transient('deepl_language_json_target');
        $this->assertIsArray($cached);
        $this->assertSame($first, $cached);
    }

    public function test_assemblyai_start_transcription_uploads_audio_and_requests_transcript(): void
    {
        update_option('ll_assemblyai_api_key', 'assembly-test-key');
        $audioPath = $this->createTempAudioFile('assembly-start');

        $requests = [];
        $httpFilter = static function ($pre, array $args, string $url) use (&$requests) {
            $requests[] = ['url' => $url, 'args' => $args];

            if ($url === 'https://api.assemblyai.com/v2/upload') {
                return [
                    'headers'  => [],
                    'body'     => wp_json_encode(['upload_url' => 'https://cdn.example.com/uploaded-audio.wav']),
                    'response' => ['code' => 200, 'message' => 'OK'],
                    'cookies'  => [],
                    'filename' => null,
                ];
            }

            if ($url === 'https://api.assemblyai.com/v2/transcript') {
                return [
                    'headers'  => [],
                    'body'     => wp_json_encode(['id' => 'tx_123']),
                    'response' => ['code' => 200, 'message' => 'OK'],
                    'cookies'  => [],
                    'filename' => null,
                ];
            }

            return $pre;
        };

        add_filter('pre_http_request', $httpFilter, 10, 3);
        try {
            $transcriptId = ll_tools_assemblyai_start_transcription($audioPath, 'tr');
        } finally {
            remove_filter('pre_http_request', $httpFilter, 10);
        }

        $this->assertSame('tx_123', $transcriptId);
        $this->assertCount(2, $requests);

        $uploadRequest = $requests[0];
        $this->assertSame('https://api.assemblyai.com/v2/upload', $uploadRequest['url']);
        $this->assertSame('assembly-test-key', $uploadRequest['args']['headers']['authorization'] ?? null);
        $this->assertSame('application/octet-stream', $uploadRequest['args']['headers']['content-type'] ?? null);
        $this->assertIsString($uploadRequest['args']['body'] ?? null);
        $this->assertNotSame('', $uploadRequest['args']['body'] ?? '');

        $transcriptRequest = $requests[1];
        $this->assertSame('https://api.assemblyai.com/v2/transcript', $transcriptRequest['url']);
        $this->assertSame('assembly-test-key', $transcriptRequest['args']['headers']['authorization'] ?? null);

        $payload = json_decode((string) ($transcriptRequest['args']['body'] ?? ''), true);
        $this->assertIsArray($payload);
        $this->assertSame('https://cdn.example.com/uploaded-audio.wav', $payload['audio_url'] ?? null);
        $this->assertSame('tr', $payload['language_code'] ?? null);
        $this->assertTrue((bool) ($payload['punctuate'] ?? false));
        $this->assertTrue((bool) ($payload['format_text'] ?? false));
    }

    public function test_assemblyai_transcribe_audio_file_polls_until_completed(): void
    {
        update_option('ll_assemblyai_api_key', 'assembly-test-key');
        $audioPath = $this->createTempAudioFile('assembly-poll');

        $transcriptStatusCalls = 0;
        $requests = [];

        $httpFilter = static function ($pre, array $args, string $url) use (&$transcriptStatusCalls, &$requests) {
            $requests[] = ['url' => $url, 'args' => $args];

            if ($url === 'https://api.assemblyai.com/v2/upload') {
                return [
                    'headers'  => [],
                    'body'     => wp_json_encode(['upload_url' => 'https://cdn.example.com/poll-audio.wav']),
                    'response' => ['code' => 200, 'message' => 'OK'],
                    'cookies'  => [],
                    'filename' => null,
                ];
            }

            if ($url === 'https://api.assemblyai.com/v2/transcript') {
                return [
                    'headers'  => [],
                    'body'     => wp_json_encode(['id' => 'tx_poll']),
                    'response' => ['code' => 200, 'message' => 'OK'],
                    'cookies'  => [],
                    'filename' => null,
                ];
            }

            if ($url === 'https://api.assemblyai.com/v2/transcript/tx_poll') {
                $transcriptStatusCalls++;
                $body = ($transcriptStatusCalls === 1)
                    ? ['status' => 'processing']
                    : ['status' => 'completed', 'text' => 'Recognized text'];

                return [
                    'headers'  => [],
                    'body'     => wp_json_encode($body),
                    'response' => ['code' => 200, 'message' => 'OK'],
                    'cookies'  => [],
                    'filename' => null,
                ];
            }

            return $pre;
        };

        $sleepFilter = static function (): int {
            return 0;
        };
        $attemptsFilter = static function (): int {
            return 3;
        };

        add_filter('pre_http_request', $httpFilter, 10, 3);
        add_filter('ll_tools_assemblyai_poll_sleep', $sleepFilter);
        add_filter('ll_tools_assemblyai_poll_attempts', $attemptsFilter);

        try {
            $result = ll_tools_assemblyai_transcribe_audio_file($audioPath, 'tr');
        } finally {
            remove_filter('pre_http_request', $httpFilter, 10);
            remove_filter('ll_tools_assemblyai_poll_sleep', $sleepFilter);
            remove_filter('ll_tools_assemblyai_poll_attempts', $attemptsFilter);
        }

        $this->assertIsArray($result);
        $this->assertSame('tx_poll', $result['id'] ?? null);
        $this->assertSame('completed', $result['status'] ?? null);
        $this->assertSame('Recognized text', $result['text'] ?? null);
        $this->assertSame(2, $transcriptStatusCalls);
        $this->assertCount(4, $requests);
    }

    private function createTempAudioFile(string $prefix): string
    {
        $temp = tempnam(sys_get_temp_dir(), $prefix);
        $this->assertIsString($temp);
        $this->assertNotFalse(file_put_contents($temp, "RIFF\x00\x00\x00\x00WAVEdata"));
        $this->tempFiles[] = $temp;
        return $temp;
    }
}
