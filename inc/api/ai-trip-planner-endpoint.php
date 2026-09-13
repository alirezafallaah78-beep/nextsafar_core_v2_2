<?php
namespace NextSafar\API;

if (!defined('ABSPATH')) exit;

use NextSafar\Database\AiTripTable;

/**
 * Endpoint: /nextsafar/v1/ai-trip-planner
 *
 * POST /generate      - ساخت برنامه جدید (فوری)
 * POST /process-now   - پردازش فوری یک برنامه (internal, non-blocking)
 * GET  /plan/{id}     - دریافت یک برنامه (با auto-trigger)
 * GET  /history       - تاریخچه کاربر
 * POST /delete        - حذف برنامه
 */
class AiTripPlannerEndpoint {

    const LOCK_KEY       = 'ns_ai_job_lock';
    const LOCK_TTL       = 150;
    const MAX_ATTEMPTS   = 3;
    const PROCESS_SECRET = 'ns_internal_2026_secret'; /* باید با .env هم sync باشه */

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);

        /* cron به عنوان fallback */
        add_filter('cron_schedules', [__CLASS__, 'add_cron_interval']);
        add_action('ns_ai_process_plan', [__CLASS__, 'process_plan']);
        add_action('ns_ai_process_queue', [__CLASS__, 'process_queue']);

        if (!wp_next_scheduled('ns_ai_process_queue')) {
            wp_schedule_event(time() + 60, 'every_minute', 'ns_ai_process_queue');
        }
    }

    public static function add_cron_interval($schedules) {
        if (!isset($schedules['every_minute'])) {
            $schedules['every_minute'] = [
                'interval' => 60,
                'display'  => 'Every Minute',
            ];
        }
        return $schedules;
    }

    public static function register_routes() {
        register_rest_route('nextsafar/v1', '/ai-trip-planner/generate', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'generate'],
            'permission_callback' => '__return_true',
            'args'                => self::get_generate_args(),
        ]);

        /* ⭐ endpoint داخلی برای پردازش غیربلاکینگ */
        register_rest_route('nextsafar/v1', '/ai-trip-planner/process-now', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'process_now'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('nextsafar/v1', '/ai-trip-planner/plan/(?P<id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'get_plan'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('nextsafar/v1', '/ai-trip-planner/history', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'get_history'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('nextsafar/v1', '/ai-trip-planner/delete', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'delete_plan'],
            'permission_callback' => '__return_true',
        ]);
    }

    private static function get_generate_args(): array {
        return [
            'destination'  => ['required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
            'country'      => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
            'days'         => ['required' => true, 'type' => 'integer', 'minimum' => 2, 'maximum' => 14, 'sanitize_callback' => 'absint'],
            'budget_level' => ['required' => true, 'type' => 'string', 'enum' => ['economy', 'medium', 'luxury']],
            'interests'    => ['type' => 'array', 'items' => ['type' => 'string']],
            'travelers'    => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20, 'default' => 2],
            'start_date' => [
                'required' => false,
                'type'     => 'string',
                'validate_callback' => function ($param) {
                    return $param === null || $param === '' || is_string($param);
                },
                'sanitize_callback' => function ($param) {
                    return $param ? sanitize_text_field($param) : null;
                },
            ],
        ];
    }

    /* ═══════════════════════════════════════════════════════════
       GENERATE — ساخت رکورد + پاسخ فوری
    ═══════════════════════════════════════════════════════════ */
    public static function generate($request) {
        $client = new AiTripGeminiClient();
        if (!$client->has_api_key()) {
            return new \WP_Error(
                'no_api_key',
                'کلید API برنامه سفر تنظیم نشده. از منوی «سفر AI» در ادمین تنظیم کنید.',
                ['status' => 503]
            );
        }

        $rate_check = self::check_rate_limit();
        if (!$rate_check['allowed']) {
            return new \WP_Error(
                'rate_limit_exceeded',
                $rate_check['message'],
                ['status' => 429, 'remaining' => $rate_check['remaining']]
            );
        }

        $input = [
            'destination'  => $request->get_param('destination'),
            'country'      => $request->get_param('country') ?: null,
            'days'         => (int) $request->get_param('days'),
            'budget_level' => $request->get_param('budget_level'),
            'interests'    => $request->get_param('interests') ?: [],
            'travelers'    => (int) ($request->get_param('travelers') ?: 2),
            'start_date'   => $request->get_param('start_date') ?: null,
        ];

        /* کش هوشمند */
        $cached = self::find_cached_plan($input);
        if ($cached) {
            return rest_ensure_response([
                'success'         => true,
                'plan_id'         => (int) $cached['id'],
                'status'          => 'completed',
                'cached'          => true,
                'plan'            => self::format_db_plan($cached),
                'remaining_today' => self::get_remaining_requests(),
            ]);
        }

        global $wpdb;
        $table = AiTripTable::get_table_name();

        $inserted = $wpdb->insert($table, [
            'user_id'      => get_current_user_id() ?: null,
            'session_id'   => self::get_session_id(),
            'ip_address'   => self::get_client_ip(),
            'destination'  => $input['destination'],
            'country'      => $input['country'],
            'days'         => $input['days'],
            'budget_level' => $input['budget_level'],
            'interests'    => wp_json_encode($input['interests']),
            'travelers'    => $input['travelers'],
            'start_date'   => $input['start_date'],
            'days_plan'    => '[]',
            'status'       => 'pending',
            'attempts'     => 0,
            'created_at'   => current_time('mysql'),
            'expires_at'   => gmdate('Y-m-d H:i:s', strtotime('+1 year')),
        ]);

        if (!$inserted) {
            return new \WP_Error('db_error', 'خطا در ساخت رکورد', ['status' => 500]);
        }

        $plan_id = (int) $wpdb->insert_id;

        /* ⭐ Trigger پردازش فوری (غیربلاکینگ) */
        self::trigger_background_processing($plan_id);

        /* cron fallback */
        wp_schedule_single_event(time() + 60, 'ns_ai_process_plan', [$plan_id]);
        spawn_cron();

        return rest_ensure_response([
            'success'         => true,
            'plan_id'         => $plan_id,
            'status'          => 'pending',
            'message'         => 'برنامه در صف ساخت قرار گرفت',
            'poll_url'        => '/nextsafar/v1/ai-trip-planner/plan/' . $plan_id,
            'remaining_today' => self::get_remaining_requests(),
        ]);
    }

    /* ═══════════════════════════════════════════════════════════
       ⭐ BACKGROUND TRIGGER — غیربلاکینگ
    ═══════════════════════════════════════════════════════════ */
    private static function trigger_background_processing(int $plan_id): void {
        /* اگه قفل فعاله، رد شو */
        if (get_transient(self::LOCK_KEY)) {
            return;
        }

        /* HTTP غیربلاکینگ به endpoint داخلی */
        $url = rest_url('nextsafar/v1/ai-trip-planner/process-now');

        wp_remote_post($url, [
            'blocking' => false,  /* ⭐ کلیدی: منتظر جواب نمی‌مونیم */
            'timeout'  => 0.01,
            'headers'  => ['Content-Type' => 'application/json'],
            'body'     => wp_json_encode([
                'plan_id' => $plan_id,
                'secret'  => self::PROCESS_SECRET,
            ]),
            'sslverify' => false,
        ]);
    }

    /* ═══════════════════════════════════════════════════════════
       ⭐ PROCESS-NOW — endpoint داخلی
    ═══════════════════════════════════════════════════════════ */
    public static function process_now($request) {
        /* بررسی secret */
        $secret = $request->get_param('secret');
        if ($secret !== self::PROCESS_SECRET) {
            return new \WP_Error('forbidden', 'دسترسی غیرمجاز', ['status' => 403]);
        }

        $plan_id = (int) $request->get_param('plan_id');
        if ($plan_id < 1) {
            return new \WP_Error('invalid', 'ID نامعتبر', ['status' => '400']);
        }

        if (function_exists('fastcgi_finish_request')) {
            /* پاسخ سریع به کلاینت */
            echo wp_json_encode(['status' => 'processing']);
            fastcgi_finish_request();
        } else {
            /* fallback برای لوکال */
            @ob_end_clean();
            header('Connection: close');
            ignore_user_abort(true);
            @ob_start();
            echo wp_json_encode(['status' => 'processing']);
            $size = ob_get_length();
            header("Content-Length: {$size}");
            @ob_end_flush();
            @flush();
            if (session_id()) {
                session_write_close();
            }
        }

        /* حالا در پس‌زمینه پردازش کن */
        self::process_plan($plan_id);

        exit;
    }

    /* ═══════════════════════════════════════════════════════════
       PROCESSOR — منطق اصلی پردازش AI
    ═══════════════════════════════════════════════════════════ */
    public static function process_plan($plan_id) {
        $plan_id = (int) $plan_id;

        if (get_transient(self::LOCK_KEY)) {
            return;
        }
        set_transient(self::LOCK_KEY, $plan_id, self::LOCK_TTL);

        try {
            global $wpdb;
            $table = AiTripTable::get_table_name();

            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table} WHERE id = %d",
                $plan_id
            ), ARRAY_A);

            if (!$row || $row['status'] !== 'pending') {
                return;
            }

            $attempts = (int) $row['attempts'] + 1;

            if ($attempts > self::MAX_ATTEMPTS) {
                $wpdb->update($table,
                    ['status' => 'failed', 'error_message' => 'Max attempts exceeded'],
                    ['id' => $plan_id]
                );
                return;
            }

            $wpdb->update($table, ['attempts' => $attempts], ['id' => $plan_id]);

            $input = [
                'destination'  => $row['destination'],
                'country'      => $row['country'],
                'days'         => (int) $row['days'],
                'budget_level' => $row['budget_level'],
                'interests'    => json_decode($row['interests'] ?? '[]', true) ?: [],
                'travelers'    => (int) $row['travelers'],
                'start_date'   => $row['start_date'],
            ];

            $context = self::collect_site_context($input);
            $prompt  = self::build_prompt($input, $context);

            $gemini = new AiTripGeminiClient(75);
            $result = $gemini->generate_json_with_schema(
                $prompt,
                self::get_response_schema(),
                ['temperature' => 0.8, 'max_tokens' => 6144]
            );

            if ($result['success']) {
                $data = $result['data'];
                $wpdb->update($table, [
                    'trip_title'            => $data['title'] ?? null,
                    'trip_summary'          => $data['summary'] ?? null,
                    'days_plan'             => wp_json_encode($data['days'] ?? []),
                    'total_budget_min'      => $data['total_budget_min'] ?? null,
                    'total_budget_max'      => $data['total_budget_max'] ?? null,
                    'currency'              => $data['currency'] ?? 'USD',
                    'suggested_hotels'      => wp_json_encode($data['recommended_hotel'] ?? null),
                    'suggested_restaurants' => wp_json_encode($data['tips'] ?? []),
                    'model_used'            => $result['usage']['model'] ?? null,
                    'tokens_used'           => $result['usage']['total_tokens'] ?? 0,
                    'generation_time_ms'    => $result['usage']['duration_ms'] ?? 0,
                    'status'                => 'completed',
                    'error_message'         => null,
                    'last_viewed_at'        => current_time('mysql'),
                ], ['id' => $plan_id]);

                error_log("✅ AI Trip #{$plan_id} completed in " . ($result['usage']['duration_ms'] ?? 0) . "ms");
            } else {
                error_log("❌ AI Trip #{$plan_id} attempt {$attempts} failed: " . $result['error']);

                if ($attempts >= self::MAX_ATTEMPTS) {
                    $wpdb->update($table, [
                        'status'        => 'failed',
                        'error_message' => $result['error'],
                    ], ['id' => $plan_id]);
                } else {
                    $wpdb->update($table, [
                        'error_message' => $result['error'],
                    ], ['id' => $plan_id]);
                }
            }
        } catch (\Throwable $e) {
            error_log('❌ AI Trip processor exception: ' . $e->getMessage());
        } finally {
            delete_transient(self::LOCK_KEY);
        }
    }

    /* ═══════════════════════════════════════════════════════════
       GET PLAN — با auto-trigger برای pending
    ═══════════════════════════════════════════════════════════ */
    public static function get_plan($request) {
        $plan_id = (int) $request->get_param('id');

        global $wpdb;
        $table = AiTripTable::get_table_name();

        $plan = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d",
            $plan_id
        ), ARRAY_A);

        if (!$plan) {
            return new \WP_Error('not_found', 'برنامه یافت نشد', ['status' => 404]);
        }

        if (!self::can_access_plan($plan)) {
            return new \WP_Error('forbidden', 'دسترسی غیرمجاز', ['status' => 403]);
        }

        /* ⭐ اگه pending بود، دوباره trigger کن (برای اطمینان) */
        if ($plan['status'] === 'pending' && !get_transient(self::LOCK_KEY)) {
            $created = strtotime($plan['created_at']);
            /* فقط اگه بیش از ۵ ثانیه گذشته (تا با trigger اولیه تداخل نکنه) */
            if (time() - $created > 5) {
                self::trigger_background_processing($plan_id);
            }
        }

        if ($plan['status'] === 'completed') {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$table} SET view_count = view_count + 1, last_viewed_at = NOW() WHERE id = %d",
                $plan_id
            ));
        }

        return rest_ensure_response(self::format_db_plan($plan));
    }

    public static function get_history($request) {
        global $wpdb;
        $table = AiTripTable::get_table_name();

        $user_id    = get_current_user_id();
        $session_id = $request->get_param('session_id');

        if ($user_id > 0) {
            $plans = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$table} WHERE user_id = %d AND status = 'completed' ORDER BY created_at DESC LIMIT 50",
                $user_id
            ), ARRAY_A);
        } elseif ($session_id) {
            $plans = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$table} WHERE session_id = %s AND status = 'completed' ORDER BY created_at DESC LIMIT 50",
                sanitize_text_field($session_id)
            ), ARRAY_A);
        } else {
            $plans = [];
        }

        return rest_ensure_response([
            'plans' => array_map([__CLASS__, 'format_db_plan'], $plans),
            'count' => count($plans),
        ]);
    }

    public static function delete_plan($request) {
        $plan_id = (int) $request->get_param('id');
        global $wpdb;
        $table = AiTripTable::get_table_name();

        $plan = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d", $plan_id
        ), ARRAY_A);

        if (!$plan) {
            return new \WP_Error('not_found', 'یافت نشد', ['status' => 404]);
        }
        if (!self::can_access_plan($plan)) {
            return new \WP_Error('forbidden', 'دسترسی غیرمجاز', ['status' => 403]);
        }

        $wpdb->delete($table, ['id' => $plan_id], ['%d']);
        return rest_ensure_response(['success' => true]);
    }

    /* ═══════════════════════════════════════════════════════════
       Queue fallback
    ═══════════════════════════════════════════════════════════ */
    public static function process_queue() {
        if (get_transient(self::LOCK_KEY)) return;

        global $wpdb;
        $table = AiTripTable::get_table_name();

        $pending = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE status = 'pending' AND attempts < %d ORDER BY id ASC LIMIT 1",
            self::MAX_ATTEMPTS
        ));

        if ($pending) {
            self::process_plan((int) $pending);
        }
    }

    /* ═══════════════════════════════════════════════════════════
       کش هوشمند + بقیه متدها (بدون تغییر)
    ═══════════════════════════════════════════════════════════ */
    private static function find_cached_plan(array $input): ?array {
        global $wpdb;
        $table = AiTripTable::get_table_name();

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE status = 'completed'
               AND destination = %s
               AND days = %d
               AND budget_level = %s
               AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
             ORDER BY created_at DESC LIMIT 1",
            $input['destination'],
            $input['days'],
            $input['budget_level']
        ), ARRAY_A);

        return $row ?: null;
    }

    private static function collect_site_context(array $input): array {
        $context = [
            'hotels' => [], 'tours' => [], 'restaurants' => [], 'destinations' => [],
        ];

        $city = $input['destination'];

        $hotels = self::find_posts_by_city('hotel', $city, 5);
        foreach ($hotels as $h) {
            $stars = (int) get_post_meta($h->ID, '_hotel_stars', true);
            $context['hotels'][] = [
                'id' => $h->ID, 'title' => $h->post_title,
                'slug' => $h->post_name, 'stars' => $stars ?: 3,
                'url' => '/hotels/' . $h->post_name,
            ];
        }

        $tours = self::find_posts_by_city('tour', $city, 3);
        foreach ($tours as $t) {
            $context['tours'][] = [
                'id' => $t->ID, 'title' => $t->post_title,
                'slug' => $t->post_name, 'url' => '/tours/' . $t->post_name,
            ];
        }

        $restaurants = self::find_posts_by_city('restaurant', $city, 5);
        foreach ($restaurants as $r) {
            $context['restaurants'][] = [
                'id' => $r->ID, 'title' => $r->post_title,
                'slug' => $r->post_name, 'url' => '/restaurants/' . $r->post_name,
            ];
        }

        $dests = self::find_posts_by_city('destination', $city, 10);
        foreach ($dests as $d) {
            $context['destinations'][] = [
                'id' => $d->ID, 'title' => $d->post_title,
                'slug' => $d->post_name, 'url' => '/destinations/' . $d->post_name,
            ];
        }

        return $context;
    }

    private static function find_posts_by_city(string $post_type, string $city, int $limit): array {
        $term = get_term_by('name', $city, 'tourism');
        if ($term && !is_wp_error($term)) {
            $posts = get_posts([
                'post_type'      => $post_type,
                'posts_per_page' => $limit,
                'post_status'    => 'publish',
                'tax_query'      => [[
                    'taxonomy' => 'tourism',
                    'field'    => 'term_id',
                    'terms'    => $term->term_id,
                ]],
            ]);
            if (!empty($posts)) return $posts;
        }

        return get_posts([
            'post_type'      => $post_type,
            'posts_per_page' => $limit,
            'post_status'    => 'publish',
            's'              => $city,
        ]);
    }

    private static function build_prompt(array $input, array $context): string {
        $budget_label = [
            'economy' => 'اقتصادی و به‌صرفه',
            'medium'  => 'متوسط و متعادل',
            'luxury'  => 'لوکس و بی‌نظیر',
        ][$input['budget_level']] ?? 'متوسط و متعادل';

        $interests_str = !empty($input['interests'])
            ? 'علاقه‌مندی‌های کاربر: ' . implode('، ', $input['interests'])
            : 'علاقه‌مندی خاصی ذکر نشده، ترکیبی متنوع پیشنهاد بده';

        $country     = !empty($input['country']) ? $input['country'] : 'نامشخص';
        $days        = (int) $input['days'];
        $travelers   = (int) $input['travelers'];
        $destination = $input['destination'];

        $hotels_list = !empty($context['hotels'])
            ? "هتل‌های موجود در سایت ما برای این شهر:\n"
            : "هتل خاصی در سایت ما برای این شهر ثبت نشده، خودت پیشنهاد بده.\n";
        if (!empty($context['hotels'])) {
            foreach ($context['hotels'] as $h) {
                $hotels_list .= "- «{$h['title']}» ({$h['stars']} ستاره) - slug: {$h['slug']}\n";
            }
        }

        $restaurants_list = !empty($context['restaurants'])
            ? "رستوران‌های موجود در سایت ما:\n"
            : "رستوران خاصی در سایت ما ثبت نشده، خودت پیشنهاد بده.\n";
        if (!empty($context['restaurants'])) {
            foreach ($context['restaurants'] as $r) {
                $restaurants_list .= "- «{$r['title']}» - slug: {$r['slug']}\n";
            }
        }

        $dests_list = !empty($context['destinations'])
            ? "جاذبه‌های گردشگری موجود در سایت ما:\n"
            : "جاذبه خاصی در سایت ما ثبت نشده، خودت پیشنهاد بده.\n";
        if (!empty($context['destinations'])) {
            foreach ($context['destinations'] as $d) {
                $dests_list .= "- «{$d['title']}» - slug: {$d['slug']}\n";
            }
        }

        return <<<PROMPT
تو یک برنامه‌ریز سفر حرفه‌ای، صمیمی و با تجربه هستی که مثل یک دوست متخصص به کاربر کمک می‌کنی بهترین برنامه سفر رو داشته باشه.

## اطلاعات درخواست کاربر:
- **مقصد:** {$destination}
- **کشور:** {$country}
- **مدت سفر:** {$days} روز
- **بودجه:** {$budget_label}
- **تعداد مسافر:** {$travelers} نفر
- {$interests_str}

## داده‌های واقعی سایت ما (حتماً از این‌ها استفاده کن):
{$hotels_list}
{$restaurants_list}
{$dests_list}

## دستورالعمل‌ها:
1. **لحن دوستانه و صمیمی:** با کاربر مثل یک دوست حرف بزن، نه رسمی. از عبارت‌هایی مثل "پیشنهاد می‌کنم"، "حتماً برو"، "خوشمزه‌ست" استفاده کن.
2. **اولویت با داده‌های سایت:** تا حد ممکن از هتل‌ها، رستوران‌ها و جاذبه‌های موجود در سایت ما استفاده کن. اگه چیزی نبود، خودت پیشنهاد بده.
3. **تنوع روزانه:** هر روز متفاوت باشه. صبح، ظهر، عصر و شب رو پر کن ولی خسته‌کننده نباشه.
4. **واقع‌بینانه باش:** فاصله‌ها رو در نظر بگیر، زمان استراحت بذار، غذا خوردن رو فراموش نکن.
5. **بودجه‌بندی:** برای هر روز یک بازه هزینه تخمینی (حداقل و حداکثر) بده.
6. **slug‌ها رو دقیق استفاده کن:** وقتی از هتل/رستوران سایت ما استفاده می‌کنی، حتماً slug دقیقش رو در فیلد `slug` بنویس.
7. **عنوان جذاب:** یک عنوان جذاب و صمیمی برای کل سفر بساز (مثلاً: "۵ روز رویایی در استانبول با طعم تاریخ و قهوه ترک").
8. **خلاصه:** یک پاراگراف خلاصه دوستانه از کل سفر بنویس.

حالا یک برنامه سفر کامل و جذاب برای {$days} روز در {$destination} بساز.
PROMPT;
    }

    private static function get_response_schema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'title'   => ['type' => 'string'],
                'summary' => ['type' => 'string'],
                'total_budget_min' => ['type' => 'number'],
                'total_budget_max' => ['type' => 'number'],
                'currency' => ['type' => 'string'],
                'tips' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'days' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'day_number' => ['type' => 'integer'],
                            'title' => ['type' => 'string'],
                            'theme' => ['type' => 'string'],
                            'activities' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'time' => ['type' => 'string'],
                                        'title' => ['type' => 'string'],
                                        'description' => ['type' => 'string'],
                                        'type' => [
                                            'type' => 'string',
                                            'enum' => ['visit', 'food', 'hotel', 'transport', 'activity', 'shopping', 'rest'],
                                        ],
                                        'slug' => ['type' => 'string'],
                                        'entity_type' => [
                                            'type' => 'string',
                                            'enum' => ['hotel', 'restaurant', 'destination', 'tour', 'custom'],
                                        ],
                                        'icon' => ['type' => 'string'],
                                    ],
                                    'required' => ['time', 'title', 'description', 'type'],
                                ],
                            ],
                            'budget_min' => ['type' => 'number'],
                            'budget_max' => ['type' => 'number'],
                            'tip_of_day' => ['type' => 'string'],
                        ],
                        'required' => ['day_number', 'title', 'theme', 'activities', 'budget_min', 'budget_max'],
                    ],
                ],
                'recommended_hotel' => [
                    'type' => 'object',
                    'properties' => [
                        'title' => ['type' => 'string'],
                        'slug' => ['type' => 'string'],
                        'reason' => ['type' => 'string'],
                    ],
                    'required' => ['title', 'reason'],
                ],
            ],
            'required' => ['title', 'summary', 'days', 'tips'],
        ];
    }

    /* ═══════════════════════════════════════════════════════════
       Rate Limiting
    ═══════════════════════════════════════════════════════════ */
    private static function check_rate_limit(): array {
        $user_id = get_current_user_id();
        if ($user_id > 0) {
            return self::check_user_rate_limit($user_id, 20);
        }
        return self::check_guest_rate_limit(self::get_client_ip(), 3);
    }

    private static function check_user_rate_limit(int $user_id, int $max_per_day): array {
        global $wpdb;
        $table = AiTripTable::get_table_name();

        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND DATE(created_at) = CURDATE()",
            $user_id
        ));

        $remaining = max(0, $max_per_day - $count);
        if ($count >= $max_per_day) {
            return [
                'allowed'   => false,
                'message'   => "سهمیه روزانه شما ({$max_per_day} برنامه) تمام شده. فردا دوباره امتحان کنید.",
                'remaining' => 0,
            ];
        }
        return ['allowed' => true, 'remaining' => $remaining];
    }

    private static function check_guest_rate_limit(string $ip, int $max_per_day): array {
        global $wpdb;
        $table = AiTripTable::get_table_name();

        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE ip_address = %s AND user_id IS NULL AND DATE(created_at) = CURDATE()",
            $ip
        ));

        $remaining = max(0, $max_per_day - $count);
        if ($count >= $max_per_day) {
            return [
                'allowed'   => false,
                'message'   => "سهمیه رایگان امروز شما ({$max_per_day} برنامه) تمام شد. برای استفاده نامحدود وارد شوید.",
                'remaining' => 0,
            ];
        }
        return ['allowed' => true, 'remaining' => $remaining];
    }

    private static function get_remaining_requests(): int {
        $user_id = get_current_user_id();
        if ($user_id > 0) {
            return self::check_user_rate_limit($user_id, 20)['remaining'];
        }
        return self::check_guest_rate_limit(self::get_client_ip(), 3)['remaining'];
    }

    /* ═══════════════════════════════════════════════════════════
       Helpers — بدون session_start
    ═══════════════════════════════════════════════════════════ */
    private static function get_session_id(): string {
        if (!empty($_COOKIE['ns_trip_sid'])) {
            return substr(sanitize_text_field($_COOKIE['ns_trip_sid']), 0, 64);
        }

        $sid = bin2hex(random_bytes(16));
        if (!headers_sent()) {
            setcookie('ns_trip_sid', $sid, time() + YEAR_IN_SECONDS, '/', '', false, true);
        }
        return $sid;
    }

    private static function get_client_ip(): string {
        $headers = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR',
        ];
        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = explode(',', $_SERVER[$header])[0];
                return trim($ip);
            }
        }
        return '127.0.0.1';
    }

    private static function can_access_plan(array $plan): bool {
        $user_id = get_current_user_id();
        if ($user_id > 0 && (int) $plan['user_id'] === $user_id) return true;
        if (empty($plan['user_id'])) return ($plan['session_id'] === self::get_session_id());
        return false;
    }

    private static function format_db_plan(array $row): array {
        return [
            'id'              => (int) $row['id'],
            'status'          => $row['status'],
            'attempts'        => (int) ($row['attempts'] ?? 0),
            'title'           => $row['trip_title'],
            'summary'         => $row['trip_summary'],
            'days'            => json_decode($row['days_plan'] ?? '[]', true),
            'tips'            => json_decode($row['suggested_restaurants'] ?? '[]', true),
            'total_budget_min' => $row['total_budget_min'] ? (float) $row['total_budget_min'] : null,
            'total_budget_max' => $row['total_budget_max'] ? (float) $row['total_budget_max'] : null,
            'currency'        => $row['currency'] ?? 'USD',
            'recommended_hotel' => json_decode($row['suggested_hotels'] ?? 'null', true),
            'input'           => [
                'destination'  => $row['destination'],
                'country'      => $row['country'],
                'days'         => (int) $row['days'],
                'budget_level' => $row['budget_level'],
                'interests'    => json_decode($row['interests'] ?? '[]', true),
                'travelers'    => (int) $row['travelers'],
                'start_date'   => $row['start_date'],
            ],
            'meta'            => [
                'model_used'         => $row['model_used'],
                'tokens_used'        => (int) $row['tokens_used'],
                'generation_time_ms' => (int) $row['generation_time_ms'],
                'created_at'         => $row['created_at'],
                'view_count'         => (int) $row['view_count'],
                'error_message'      => $row['error_message'] ?? null,
            ],
        ];
    }
}
