<?php
/**
 * AiTripGeminiClient — نسخه ۱.۰.۰ (Safe Edition)
 * کلاینت مستقل Gemini مخصوص AI Trip Planner
 *
 * ✅ کاملاً جدا از سیستم اخبار (GeminiClient دست نمی‌خوره)
 * ✅ بدون sleep طولانی → بک‌اند قفل نمی‌شه
 * ✅ بودجه زمانی سخت (حداکثر ۷۵ ثانیه) → تضمین پایان
 * ✅ کلید و مدل مستقل از منوی «سفر AI»
 */

namespace NextSafar\API;

if (!defined('ABSPATH')) exit;

class AiTripGeminiClient {

    const VALID_MODELS = [
        'gemini-3.7-flash',
        'gemini-3.6-flash',
        'gemini-3.5-flash',
        'gemini-3.5-pro',
        'gemini-3.0-flash',
        'gemini-3.0-pro',
        'gemini-2.5-flash',
    ];

    const DEFAULT_MODEL  = 'gemini-3.7-flash';
    const API_URL        = 'https://generativelanguage.googleapis.com/v1beta/models';
    const OPTION_KEY     = 'nextsafar_trip_planner_key';
    const OPTION_MODEL   = 'nextsafar_trip_planner_model';

    /* ⭐ تنظیمات ایمن — بدون قفل کردن بک‌اند */
    const MAX_HTTP_RETRIES   = 2;    /* حداکثر ۲ retry (نه ۴) */
    const MAX_WAIT_PER_RETRY = 5;    /* حداکثر ۵ ثانیه sleep در هر retry */
    const TOTAL_TIME_BUDGET  = 70;   /* کل عملیات حداکثر ۷۰ ثانیه */
    const RETRYABLE_CODES    = [429, 500, 503];

    private $api_key;
    private $timeout;
    private $last_response = null;
    private $last_error = null;

    public function __construct(int $timeout = 75) {
        $this->api_key = trim((string) get_option(self::OPTION_KEY, ''));
        $this->timeout = min($timeout, 80); /* سقف سخت */
    }

    public function has_api_key(): bool {
        return !empty($this->api_key);
    }

    public function get_model(): string {
        $model = get_option(self::OPTION_MODEL, self::DEFAULT_MODEL);
        return in_array($model, self::VALID_MODELS, true) ? $model : self::DEFAULT_MODEL;
    }

    public function get_last_error(): ?string {
        return $this->last_error;
    }

    public function get_last_token_count(): int {
        if (empty($this->last_response)) return 0;
        $data = json_decode($this->last_response, true);
        return (int) ($data['usageMetadata']['totalTokenCount'] ?? 0);
    }

    /**
     * ═══════════════════════════════════════════════════════════
     * درخواست با JSON Schema — ایمن و زمان‌مند
     *
     * @return array{success:bool, data:?array, error:?string, usage:array}
     * ═══════════════════════════════════════════════════════════
     */
    public function generate_json_with_schema(string $prompt, array $schema, array $options = []): array {
        if (!$this->has_api_key()) {
            return [
                'success' => false,
                'data'    => null,
                'error'   => 'کلید API برنامه سفر تنظیم نشده (منو: سفر AI)',
                'usage'   => [],
            ];
        }

        $model       = $options['model'] ?? $this->get_model();
        $temperature = $options['temperature'] ?? 0.8;
        $max_tokens  = max(2048, (int) ($options['max_tokens'] ?? 6144));

        $url = self::API_URL . '/' . $model . ':generateContent?key=' . urlencode($this->api_key);

        $request_body = [
            'contents' => [[
                'role'  => 'user',
                'parts' => [['text' => $prompt]],
            ]],
            'generationConfig' => [
                'temperature'      => $temperature,
                'maxOutputTokens'  => $max_tokens,
                'responseMimeType' => 'application/json',
                'responseSchema'   => $schema,
            ],
            'safetySettings' => [
                ['category' => 'HARM_CATEGORY_HARASSMENT',       'threshold' => 'BLOCK_NONE'],
                ['category' => 'HARM_CATEGORY_HATE_SPEECH',       'threshold' => 'BLOCK_NONE'],
                ['category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT', 'threshold' => 'BLOCK_NONE'],
                ['category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'threshold' => 'BLOCK_NONE'],
            ],
        ];

        $body       = json_encode($request_body);
        $attempt    = 0;
        $start_time = microtime(true);

        while (true) {
            /* ⭐ بودجه زمانی سخت — اگه تمام شد، فوری برگرد */
            $elapsed = microtime(true) - $start_time;
            if ($elapsed > self::TOTAL_TIME_BUDGET) {
                return [
                    'success' => false,
                    'data'    => null,
                    'error'   => 'بودجه زمانی تمام شد (' . self::TOTAL_TIME_BUDGET . 's)',
                    'usage'   => ['duration_ms' => (int) ($elapsed * 1000)],
                ];
            }

            /* ⭐ بدون sleep قبل از درخواست (برخلاف نسخه اخبار) */
            $response = wp_remote_post($url, [
                'timeout'   => $this->timeout,
                'headers'   => ['Content-Type' => 'application/json'],
                'body'      => $body,
                'blocking'  => true,
            ]);

            $duration_ms = (int) ((microtime(true) - $start_time) * 1000);

            /* ─── خطای شبکه ─── */
            if (is_wp_error($response)) {
                if ($attempt < self::MAX_HTTP_RETRIES) {
                    $attempt++;
                    sleep(min(3 * $attempt, self::MAX_WAIT_PER_RETRY));
                    continue;
                }
                return [
                    'success' => false,
                    'data'    => null,
                    'error'   => 'HTTP Error: ' . $response->get_error_message(),
                    'usage'   => ['duration_ms' => $duration_ms],
                ];
            }

            $code = wp_remote_retrieve_response_code($response);
            $raw  = wp_remote_retrieve_body($response);
            $this->last_response = $raw;

            /* ─── Retry محدود با sleep کوتاه ─── */
            if (in_array((int) $code, self::RETRYABLE_CODES, true) && $attempt < self::MAX_HTTP_RETRIES) {
                $attempt++;
                $err = json_decode($raw, true);
                $msg = $err['error']['message'] ?? '';
                $wait = 3 * $attempt; /* ۳، ۶ ثانیه */

                if ($code === 429 && preg_match('/retry in (\d+(?:\.\d+)?)s/i', $msg, $m)) {
                    $wait = min((int) ceil((float) $m[1]), self::MAX_WAIT_PER_RETRY);
                }

                error_log("⏳ TripGemini HTTP {$code} — retry {$attempt}/" . self::MAX_HTTP_RETRIES . " (wait {$wait}s)");
                sleep($wait);
                continue;
            }

            /* ─── خطای غیرقابل retry ─── */
            if ($code !== 200) {
                $err = json_decode($raw, true);
                return [
                    'success' => false,
                    'data'    => null,
                    'error'   => "HTTP {$code}: " . ($err['error']['message'] ?? mb_substr($raw, 0, 300)),
                    'usage'   => ['duration_ms' => $duration_ms, 'http_code' => $code],
                ];
            }

            /* ─── پارس پاسخ موفق ─── */
            $data = json_decode($raw, true);

            if (empty($data['candidates'][0])) {
                return [
                    'success' => false,
                    'data'    => null,
                    'error'   => 'No candidates. Block: ' . ($data['promptFeedback']['blockReason'] ?? 'Unknown'),
                    'usage'   => ['duration_ms' => $duration_ms],
                ];
            }

            $candidate     = $data['candidates'][0];
            $finish_reason = $candidate['finishReason'] ?? 'UNKNOWN';
            $text          = $candidate['content']['parts'][0]['text'] ?? null;

            /* ─── MAX_TOKENS: یک retry با توکن بیشتر ─── */
            if (empty($text) && $finish_reason === 'MAX_TOKENS' && $attempt < self::MAX_HTTP_RETRIES) {
                $attempt++;
                $max_tokens = $max_tokens * 2;
                error_log("⚠️ TripGemini MAX_TOKENS — retry with {$max_tokens}");
                $request_body['generationConfig']['maxOutputTokens'] = $max_tokens;
                $body = json_encode($request_body);
                continue;
            }

            if (empty($text)) {
                return [
                    'success' => false,
                    'data'    => null,
                    'error'   => "Empty text. Finish: {$finish_reason}",
                    'usage'   => ['duration_ms' => $duration_ms],
                ];
            }

            $json = $this->extract_json($text);
            if (!is_array($json)) {
                return [
                    'success' => false,
                    'data'    => null,
                    'error'   => 'Invalid JSON from model',
                    'usage'   => ['duration_ms' => $duration_ms, 'raw_text' => mb_substr($text, 0, 500)],
                ];
            }

            $usage_meta = $data['usageMetadata'] ?? [];

            return [
                'success' => true,
                'data'    => $json,
                'error'   => null,
                'usage'   => [
                    'duration_ms'   => $duration_ms,
                    'prompt_tokens' => (int) ($usage_meta['promptTokenCount'] ?? 0),
                    'output_tokens' => (int) ($usage_meta['candidatesTokenCount'] ?? 0),
                    'total_tokens'  => (int) ($usage_meta['totalTokenCount'] ?? 0),
                    'model'         => $model,
                    'finish_reason' => $finish_reason,
                ],
            ];
        }
    }

    /**
     * درخواست متن ساده (برای تست)
     */
    public function send_request(string $prompt, array $options = []): ?string {
        $result = $this->generate_json_with_schema(
            $prompt,
            [
                'type' => 'object',
                'properties' => ['text' => ['type' => 'string']],
                'required' => ['text'],
            ],
            $options
        );

        if (!$result['success']) {
            $this->last_error = $result['error'];
            return null;
        }
        return $result['data']['text'] ?? null;
    }

    /**
     * تست اتصال
     */
    public function test_connection(): array {
        return $this->generate_json_with_schema(
            'یک جمله کوتاه فارسی بگو که اتصال برقرار است.',
            [
                'type' => 'object',
                'properties' => [
                    'message' => ['type' => 'string'],
                    'ok'      => ['type' => 'boolean'],
                ],
                'required' => ['message', 'ok'],
            ],
            ['max_tokens' => 256, 'temperature' => 0.6]
        );
    }

    private function extract_json(string $text): ?array {
        $text = trim($text);
        $text = preg_replace('/^```json\s*/i', '', $text);
        $text = preg_replace('/\s*```\s*$/', '', $text);

        $result = json_decode($text, true);
        if (is_array($result)) return $result;

        if (preg_match('/\{[\s\S]*\}/', $text, $m)) {
            $result = json_decode($m[0], true);
            if (is_array($result)) return $result;
        }
        return null;
    }

    public static function get_models_for_select(): array {
        return [
            'gemini-3.7-flash' => 'Gemini 3.7 Flash — جدیدترین (پیشنهادی)',
            'gemini-3.6-flash' => 'Gemini 3.6 Flash — سریع و دقیق',
            'gemini-3.5-flash' => 'Gemini 3.5 Flash — پایدار',
            'gemini-3.5-pro'   => 'Gemini 3.5 Pro — دقیق‌تر، کندتر',
            'gemini-3.0-flash' => 'Gemini 3.0 Flash',
            'gemini-3.0-pro'   => 'Gemini 3.0 Pro',
            'gemini-2.5-flash' => 'Gemini 2.5 Flash — قدیمی',
        ];
    }
}