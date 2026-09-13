<?php
/**
 * NextSafar AI Rewriter — نسخه ۵.۰
 * ✅ بهبود کامل fallback chain
 * ✅ رفع MAX_TOKENS در relevance check
 * ✅ هماهنگی کامل مدل‌ها با تنظیمات
 */

namespace NextSafar\API;

if (!defined('ABSPATH')) exit;

class AIRewriter {

    private $timeout = 120;  // ✅ افزایش timeout

    const FAIL_COUNT_KEY     = 'ns_ai_fail_count';
    const DISABLED_UNTIL_KEY = 'ns_ai_disabled_until';
    const MAX_FAILS          = 5;  // ✅ افزایش از 3 به 5
    const COOLDOWN_MINUTES   = 10;
    const SIMILARITY_MAX     = 0.60;

    /** ✅ زنجیره fallback کامل - از جدید به قدیم */
    const FALLBACK_MODELS = [
        'gemini-3.7-flash',
        'gemini-3.6-flash',
        'gemini-3.5-flash',
        'gemini-3.5-pro',
        'gemini-3.0-flash',
    ];

    /* ═══════════════ بازنویسی ═══════════════ */

    public function process(array $item): array {
        $ai_enabled = get_option('nextsafar_news_ai_enabled', '0') === '1';

        $result = [
            'title'       => $item['title'] ?? '',
            'excerpt'     => $item['excerpt'] ?? '',
            'content'     => $item['content'] ?? '',
            'ai_used'     => false,
            'ai_provider' => null,
            'ai_model'    => null,
            'tokens_used' => 0,
        ];

        if (!$ai_enabled) {
            $result['content'] = $this->add_source_link($result['content'], $item['link'] ?? '', $item['source_name'] ?? '');
            return $result;
        }

        if ($this->is_disabled()) {
            $until     = get_transient(self::DISABLED_UNTIL_KEY);
            $remaining = $until ? human_time_diff(time(), $until) : '';
            error_log("⚠️ AI in cooldown mode ({$remaining} remaining). Draft pending rewrite.");
            return $result;
        }

        if ($this->get_fail_count() >= self::MAX_FAILS) {
            $this->enter_cooldown();
            error_log('🚨 AI entered cooldown after ' . self::MAX_FAILS . ' failures.');
            return $result;
        }

        try {
            $rewritten = $this->rewrite_with_gemini($item);

            if ($rewritten) {
                $result['title']       = $rewritten['title'];
                $result['excerpt']     = $rewritten['excerpt'] ?? '';
                $result['content']     = $rewritten['content'];
                $result['ai_used']     = true;
                $result['ai_provider'] = 'gemini';
                $result['ai_model']    = $rewritten['model'] ?? null;
                $result['tokens_used'] = $rewritten['tokens'] ?? 0;
                $this->reset_fail_count();
            }
        } catch (\Exception $e) {
            $new_count = $this->increment_fail_count();
            error_log("⚠️ AI Rewrite Failed (count: {$new_count}/" . self::MAX_FAILS . '): ' . $e->getMessage());
            if ($new_count >= self::MAX_FAILS) {
                $this->enter_cooldown();
                error_log('🚨 AI entered cooldown after consecutive failures.');
            }
        }

        return $result;
    }

    /**
     * ✅ FIX: افزایش max_tokens از 50 به 200 برای relevance check
     */
    public function check_relevance(array $item): ?bool {
        if (get_option('nextsafar_news_ai_enabled', '0') !== '1') return null;
        if ($this->is_disabled()) return null;

        $client = new GeminiClient($this->timeout);
        if (!$client->has_api_key()) return null;

        $title = $item['title'] ?? '';
        $excerpt = mb_substr($item['excerpt'] ?? ($item['content'] ?? ''), 0, 500);

        $prompt = "You are a news classifier for a TRAVEL and TOURISM website.\n" .
                  "Is this news related to: tourism, travel, hotels, flights, tours, destinations, restaurants?\n\n" .
                  "Title: {$title}\nExcerpt: {$excerpt}\n\n" .
                  "Answer ONLY: YES or NO";

        // ✅ FIX: افزایش max_tokens به 200
        $answer = $client->send_request($prompt, [
            'temperature'   => 0.1,
            'max_tokens'    => 200,  // ✅ افزایش از 50 به 200
            'response_mime' => 'text/plain',
        ]);

        if ($answer === null) {
            $n = $this->increment_fail_count();
            error_log('⚠️ AI relevance check failed (' . $n . '/' . self::MAX_FAILS . '): ' . $client->get_last_error());
            if ($n >= self::MAX_FAILS) $this->enter_cooldown();
            return null;
        }

        $this->reset_fail_count();
        return (stripos(trim($answer), 'YES') !== false);
    }

    /* ═══════════════ مدیریت شکست / cooldown ═══════════════ */

    private function get_fail_count(): int {
        $c = get_transient(self::FAIL_COUNT_KEY);
        return is_numeric($c) ? (int) $c : 0;
    }

    private function increment_fail_count(): int {
        $c = $this->get_fail_count() + 1;
        set_transient(self::FAIL_COUNT_KEY, $c, HOUR_IN_SECONDS);
        return $c;
    }

    private function reset_fail_count(): void {
        delete_transient(self::FAIL_COUNT_KEY);
        delete_transient(self::DISABLED_UNTIL_KEY);
    }

    private function is_disabled(): bool {
        $until = get_transient(self::DISABLED_UNTIL_KEY);
        return $until && $until > time();
    }

    private function enter_cooldown(): void {
        $secs = self::COOLDOWN_MINUTES * MINUTE_IN_SECONDS;
        set_transient(self::DISABLED_UNTIL_KEY, time() + $secs, $secs);
        set_transient(self::FAIL_COUNT_KEY, self::MAX_FAILS, $secs);
    }

    public static function reset_all(): void {
        delete_transient(self::FAIL_COUNT_KEY);
        delete_transient(self::DISABLED_UNTIL_KEY);
        error_log('✅ AIRewriter: counters reset by admin');
    }

    public static function get_status(): array {
        $fail  = (int) get_transient(self::FAIL_COUNT_KEY);
        $until = get_transient(self::DISABLED_UNTIL_KEY);
        $dis   = $until && $until > time();
        return [
            'fail_count'         => $fail,
            'max_fails'          => self::MAX_FAILS,
            'is_disabled'        => $dis,
            'cooldown_remaining' => $dis ? human_time_diff(time(), $until) . ' باقی‌مانده' : null,
            'cooldown_minutes'   => self::COOLDOWN_MINUTES,
        ];
    }

    /* ═══════════════ AJAX: دریافت لیست مدل‌ها ═══════════════ */

    public static function get_gemini_models(): array {
        return GeminiClient::get_models_list();
    }

    public static function get_openai_models(): array {
        return ['error' => 'OpenAI هنوز پشتیبانی نمی‌شود'];
    }

    /* ═══════════════ ارتباط با Gemini (با fallback کامل) ═══════════════ */

    private function rewrite_with_gemini(array $item, bool $force_different = false): ?array {
        $client = new GeminiClient($this->timeout);
        if (!$client->has_api_key()) throw new \Exception('Gemini API key is not set');

        // ✅ زنجیره کامل: مدل اصلی + تمام fallback‌ها
        $active_model = $client->get_active_model();
        $all_models = array_unique(array_merge([$active_model], self::FALLBACK_MODELS));
        
        $prompt   = $this->get_system_prompt($force_different) . "\n\n" . $this->build_prompt($item, $force_different);
        $last_err = '';
        $tried_count = 0;

        foreach ($all_models as $model) {
            $tried_count++;
            error_log("🤖 Trying model: {$model} (attempt {$tried_count}/" . count($all_models) . ")");
            
            $res = $client->send_json_request($prompt, [
                'temperature' => 0.7,
                'max_tokens'  => 4096,
                'model'       => $model,
            ]);

            if ($res === null) {
                $last_err = $client->get_last_error() ?? 'unknown error';
                // ✅ اضافه کردن 404 به لیست خطاهای قابل گذشت
                if (preg_match('/HTTP (404|429|500|503)/', $last_err) || stripos($last_err, 'not found') !== false) {
                    error_log("⚠️ Model {$model} failed — trying next model");
                    continue;
                }
                throw new \Exception("Gemini [{$model}]: {$last_err}");
            }

            if (empty($res['title']) || empty($res['content'])) { 
                $last_err = 'Invalid JSON response'; 
                error_log("⚠️ Model {$model} returned invalid JSON — trying next");
                continue; 
            }

            error_log("✅ Model {$model} succeeded!");
            $res['content'] = $this->add_source_link($res['content'], $item['link'] ?? '', $item['source_name'] ?? '');
            $res['tokens']  = $client->get_last_token_count();
            $res['model']   = $model;
            return $res;
        }

        throw new \Exception("All " . count($all_models) . " Gemini models failed. Last error: " . $last_err);
    }

    /* ═══════════════ نگهبان شباهت ═══════════════ */

    private function similarity(string $a, string $b): float {
        $norm = function (string $t): string {
            $t = preg_replace('/<[^>]+>/u', ' ', $t);
            return mb_strtolower(preg_replace('/\s+/u', '', trim($t)), 'UTF-8');
        };
        $grams = function (string $t): array {
            $out = []; $len = mb_strlen($t, 'UTF-8');
            for ($i = 0; $i + 5 <= $len; $i += 3) $out[mb_substr($t, $i, 5, 'UTF-8')] = 1;
            return $out;
        };
        $ga = $grams($norm($a)); $gb = $grams($norm($b));
        if (!$ga || !$gb) return 0.0;
        return count(array_intersect_key($ga, $gb)) / min(count($ga), count($gb));
    }

    /* ═══════════════ پرامپت‌ها ═══════════════ */

    private function build_prompt(array $item, bool $force_different = false): string {
        $title = $item['title'] ?? '';
        $content = $item['content'] ?? '';
        $source = $item['source_name'] ?? 'نامشخص';
        
        return <<<PROMPT
این خبر را برای سایت NextSafar (سایت تخصصی گردشگری ایران) ترجمه و بازنویسی کن.

عنوان اصلی: {$title}
منبع: {$source}

متن اصلی:
{$content}

قوانین مهم:
1. عنوان، چکیده و متن کامل را به فارسی ترجمه کن
2. هیچ کلمه انگلیسی در خروجی نباشد
3. از HTML ساده استفاده کن (p, h2, h3, strong)
4. متن روان و طبیعی باشد

خروجی JSON:
{
    "title": "عنوان فارسی جذاب (حداکثر 70 کاراکتر)",
    "excerpt": "چکیده فارسی (حداکثر 160 کاراکتر)",
    "content": "متن کامل فارسی با HTML"
}
PROMPT;
    }

    private function get_system_prompt(bool $force_different = false): string {
        return "تو مترجم و نویسنده گردشگری سایت NextSafar هستی. اخبار انگلیسی را به فارسی ترجمه و بازنویسی کن. فقط JSON معتبر برگردان.";
    }

    private function add_source_link(string $content, string $url, string $source): string {
        if (empty($url) || empty($source)) return $content;
        if (strpos($content, $url) !== false) return $content;
        $box  = '<div class="ns-source-box" style="margin-top:30px;padding:20px;background:#f8f9fa;border-right:4px solid #00a3a3;border-radius:8px;">';
        $box .= '<strong>📰 منبع اصلی:</strong> ';
        $box .= '<a href="' . esc_url($url) . '" target="_blank" rel="noopener nofollow">' . esc_html($source) . '</a>';
        $box .= '</div>';
        return $content . $box;
    }

    public function log_final(int $post_id, array $result): void {
        if (empty($result['ai_used'])) return;

        global $wpdb;
        $table = $wpdb->prefix . 'ns_news_ai_log';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) return;

        $model = $result['ai_model'] ?? get_option('nextsafar_news_ai_model', 'gemini-3.7-flash');
        $price_per_million = stripos($model, 'pro') !== false ? 1.25 : 0.075;
        $tokens = (int) ($result['tokens_used'] ?? 0);

        $wpdb->insert($table, [
            'post_id'     => $post_id,
            'provider'    => $result['ai_provider'] ?? 'gemini',
            'model'       => $model,
            'tokens_used' => $tokens,
            'cost_usd'    => ($tokens / 1000000) * $price_per_million,
            'status'      => 'success',
        ]);
    }
}