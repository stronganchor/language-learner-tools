<?php
if (!defined('WPINC')) {
    die;
}

function ll_tools_remote_stt_extract_transcript($payload, string $raw_body = ''): string {
    if (is_string($payload)) {
        return trim($payload);
    }

    if (is_array($payload)) {
        $source = $payload;
        if (isset($payload['data']) && is_array($payload['data'])) {
            $source = $payload['data'];
        }

        foreach (['predicted_ipa', 'ipa', 'transcript', 'text', 'prediction', 'predicted_text'] as $key) {
            if (isset($source[$key]) && is_scalar($source[$key])) {
                $value = trim((string) $source[$key]);
                if ($value !== '') {
                    return $value;
                }
            }
        }
    }

    return trim($raw_body);
}

function ll_tools_remote_stt_extract_error_message($payload, string $fallback): string {
    if (is_array($payload)) {
        $source = $payload;
        if (isset($payload['data']) && is_array($payload['data'])) {
            $source = $payload['data'];
        }

        foreach (['message', 'error', 'detail', 'code'] as $key) {
            if (isset($source[$key]) && is_scalar($source[$key])) {
                $value = trim((string) $source[$key]);
                if ($value !== '') {
                    return $value;
                }
            }
        }
    }

    return $fallback;
}

function ll_tools_remote_stt_build_multipart_body(array $fields, array $files, string &$boundary): string {
    $boundary = '----LLTools' . wp_generate_password(24, false, false);
    $eol = "\r\n";
    $body = '';

    foreach ($fields as $name => $value) {
        if (!is_scalar($value)) {
            continue;
        }

        $body .= '--' . $boundary . $eol;
        $body .= 'Content-Disposition: form-data; name="' . str_replace('"', '', (string) $name) . '"' . $eol . $eol;
        $body .= (string) $value . $eol;
    }

    foreach ($files as $name => $file) {
        if (!is_array($file)) {
            continue;
        }

        $filename = isset($file['filename']) ? (string) $file['filename'] : 'upload.bin';
        $content = isset($file['content']) ? $file['content'] : '';
        $content_type = isset($file['content_type']) ? (string) $file['content_type'] : 'application/octet-stream';
        if (!is_string($content) || $content === '') {
            continue;
        }

        $body .= '--' . $boundary . $eol;
        $body .= 'Content-Disposition: form-data; name="' . str_replace('"', '', (string) $name) . '"; filename="' . str_replace('"', '', $filename) . '"' . $eol;
        $body .= 'Content-Type: ' . $content_type . $eol . $eol;
        $body .= $content . $eol;
    }

    $body .= '--' . $boundary . '--' . $eol;

    return $body;
}

function ll_tools_remote_stt_transcribe_audio_file(string $endpoint, string $file_path, array $args = []) {
    $endpoint = function_exists('ll_tools_sanitize_wordset_local_transcription_endpoint')
        ? ll_tools_sanitize_wordset_local_transcription_endpoint($endpoint)
        : trim($endpoint);
    if ($endpoint === '') {
        return new WP_Error('stt_missing_endpoint', __('STT endpoint URL is not configured.', 'll-tools-text-domain'));
    }

    if ($file_path === '' || !file_exists($file_path) || !is_readable($file_path)) {
        return new WP_Error('stt_missing_audio', __('STT audio file is missing.', 'll-tools-text-domain'));
    }

    $audio_bytes = file_get_contents($file_path);
    if (!is_string($audio_bytes) || $audio_bytes === '') {
        return new WP_Error('stt_audio_unreadable', __('Unable to read the STT audio file.', 'll-tools-text-domain'));
    }

    $filename = isset($args['filename']) && is_scalar($args['filename'])
        ? trim((string) $args['filename'])
        : wp_basename($file_path);
    if ($filename === '') {
        $filename = 'audio.wav';
    }

    $timeout = isset($args['timeout']) ? max(10, min(180, (int) $args['timeout'])) : 60;
    $token = isset($args['token']) && is_scalar($args['token'])
        ? trim((string) $args['token'])
        : '';
    $fields = isset($args['fields']) && is_array($args['fields']) ? $args['fields'] : [];
    $mime = (string) wp_check_filetype($filename)['type'];
    if ($mime === '') {
        $mime = 'application/octet-stream';
    }

    $body = ll_tools_remote_stt_build_multipart_body(
        $fields,
        [
            'audio' => [
                'filename' => $filename,
                'content' => $audio_bytes,
                'content_type' => $mime,
            ],
        ],
        $boundary
    );

    $headers = [
        'Accept' => 'application/json',
        'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
    ];
    if ($token !== '') {
        $headers['Authorization'] = 'Bearer ' . $token;
        $headers['X-LL-Tools-Token'] = $token;
    }

    $response = wp_remote_post($endpoint, [
        'timeout' => $timeout,
        'headers' => $headers,
        'body' => $body,
        'data_format' => 'body',
    ]);
    if (is_wp_error($response)) {
        return new WP_Error(
            'stt_request_failed',
            sprintf(
                /* translators: %s: remote STT request error message */
                __('Unable to reach the STT endpoint: %s', 'll-tools-text-domain'),
                $response->get_error_message()
            )
        );
    }

    $status_code = (int) wp_remote_retrieve_response_code($response);
    $raw_body = (string) wp_remote_retrieve_body($response);
    $payload = json_decode($raw_body, true);
    if (!is_array($payload)) {
        $payload = [];
    }

    if ($status_code < 200 || $status_code >= 300) {
        $fallback = sprintf(
            /* translators: %d: HTTP status code from custom STT endpoint */
            __('STT endpoint returned HTTP %d.', 'll-tools-text-domain'),
            $status_code
        );
        return new WP_Error(
            'stt_request_rejected',
            ll_tools_remote_stt_extract_error_message($payload, $fallback)
        );
    }

    $transcript = ll_tools_remote_stt_extract_transcript($payload, $raw_body);
    if ($transcript === '') {
        return new WP_Error('stt_empty_transcript', __('STT endpoint returned an empty transcript.', 'll-tools-text-domain'));
    }

    return [
        'transcript' => $transcript,
        'payload' => $payload,
        'status_code' => $status_code,
    ];
}
