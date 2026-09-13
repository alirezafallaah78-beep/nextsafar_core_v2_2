<?php
namespace NextSafar\API;

if (!defined('ABSPATH')) exit;

/**
 * نقشه جهانی داده (نسخه ۲ — tourism-based)
 * 
 * منطق:
 *  - ترم‌های tourism با parent=0 = کشور (دارای تصویر/بنر/پرچم)
 *  - ترم‌های tourism با parent>0 = شهر (دارای تصویر/بنر)
 *  - شمارش پست‌ها از term_relationships
 *  - نزدیک‌ترین تور از tour_category هم‌نام شهر (سطح ۲، زیردسته‌هاش سطح ۳)
 *  - ویزا از پست‌های visa وصل‌شده به ترم کشور
 */
class WorldEndpoint {

    const CACHE_KEY      = 'ns_geo_world_v2';
    const CACHE_DURATION = 3600;
    const TAXONOMY       = 'tourism';
    const TOUR_TAX       = 'tour_category';

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);

        /* باطل‌سازی کش با هر تغییر در داده‌ها */
        add_action('save_post', [__CLASS__, 'flush']);
        add_action('deleted_post', [__CLASS__, 'flush']);
        add_action('created_' . self::TAXONOMY, [__CLASS__, 'flush']);
        add_action('edited_'  . self::TAXONOMY, [__CLASS__, 'flush']);
        add_action('delete_'  . self::TAXONOMY, [__CLASS__, 'flush']);
        add_action('created_' . self::TOUR_TAX, [__CLASS__, 'flush']);
        add_action('edited_'  . self::TOUR_TAX, [__CLASS__, 'flush']);
        add_action('delete_'  . self::TOUR_TAX, [__CLASS__, 'flush']);
    }

    public static function flush() {
        delete_transient(self::CACHE_KEY);
    }

    public static function register_routes() {
        register_rest_route('nextsafar/v1', '/geo/world', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'get_world'],
            'permission_callback' => '__return_true',
        ]);
    }

    public static function get_world() {
        $cached = get_transient(self::CACHE_KEY);
        if ($cached !== false) {
            return ['countries' => $cached, 'cached' => true];
        }

        /* ۱) همه ترم‌های tourism */
        $terms = get_terms([
            'taxonomy'   => self::TAXONOMY,
            'hide_empty' => false,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ]);
        if (is_wp_error($terms)) {
            return ['countries' => []];
        }

        /* ۲) دسته‌بندی بر اساس کشور/شهر */
        $countries        = [];
        $cities_by_parent = [];
        $city_term_ids    = [];   /* ⭐ جدید: لیست آیدی شهرها */

        foreach ($terms as $t) {
            if ((int) $t->parent === 0) {
                $countries[$t->term_id] = self::build_country($t);
            } else {
                $cities_by_parent[(int) $t->parent][] = $t;
                $city_term_ids[] = (int) $t->term_id;   /* ⭐ آیدی خود شهر */
            }
        }

        /* ۳) شمارش همه پست‌ها در یک کوئری — با آیدی شهرها ✅ */
        $counts = self::count_all_posts($city_term_ids);

        /* ۴) کش همه ویزاها (بر اساس اتصال به کشور) */
        $visas = self::get_visas_by_country(array_keys($countries));

        /* ۵) تکمیل هر کشور */
        $output = [];
        foreach ($countries as $country_id => $country) {
            $country_cities = $cities_by_parent[$country_id] ?? [];
            if (empty($country_cities)) continue; /* کشور بدون شهر نمایش داده نمی‌شه */

            $cities_data  = [];
            $totals       = ['dest' => 0, 'hotel' => 0, 'tour' => 0, 'other' => 0];

            foreach ($country_cities as $city_term) {
                $city_counts = $counts[$city_term->term_id] ?? [];
                $next_tour   = self::find_next_tour_for_city($city_term);

                $city = [
                    'id'        => $city_term->term_id,
                    'name'      => $city_term->name,
                    'slug'      => $city_term->slug,
                    'image'     => self::term_image_url($city_term->term_id, 'tourism_image', 'medium'),
                    'banner'    => self::term_image_url($city_term->term_id, 'tourism_banner', 'medium'),
                    'counts'    => $city_counts,
                    'next_tour' => $next_tour,
                ];

                $cities_data[] = $city;

                /* تجمیع برای totals کشور */
                $totals['dest']  += (int) ($city_counts['destination'] ?? 0);
                $totals['hotel'] += (int) ($city_counts['hotel'] ?? 0);
                $totals['tour']  += (int) ($city_counts['tour'] ?? 0);
                $totals['other'] += (int) ($city_counts['restaurant'] ?? 0)
                                  + (int) ($city_counts['hospital'] ?? 0)
                                  + (int) ($city_counts['airport'] ?? 0)
                                  + (int) ($city_counts['travelguide'] ?? 0);
            }

            /* مرتب‌سازی شهرها بر اساس مجموع پست‌ها (داغ‌ترین اول) */
            usort($cities_data, function ($a, $b) {
                $sumA = array_sum($a['counts']);
                $sumB = array_sum($b['counts']);
                return $sumB <=> $sumA;
            });

            $score = $totals['dest'] + ($totals['hotel'] * 2) + ($totals['tour'] * 2) + $totals['other'];

            $country['cities']  = $cities_data;
            $country['totals']  = $totals;
            $country['score']   = $score;
            $country['visa']    = $visas[$country_id] ?? null;

            $output[] = $country;
        }

        /* مرتب‌سازی کشورها بر اساس score */
        usort($output, fn($a, $b) => $b['score'] <=> $a['score']);

        set_transient(self::CACHE_KEY, $output, self::CACHE_DURATION);

        return ['countries' => $output];
    }

    /* ═══════════════════════════════════════════════════════════
       ساخت پایه داده‌های کشور (بدون cities)
    ═══════════════════════════════════════════════════════════ */
    private static function build_country(\WP_Term $t): array {
        return [
            'id'     => $t->term_id,
            'name'   => $t->name,
            'slug'   => $t->slug,
            'image'  => self::term_image_url($t->term_id, 'tourism_image', 'large'),
            'banner' => self::term_image_url($t->term_id, 'tourism_banner', 'large'),
            'flag'   => self::term_image_url($t->term_id, 'tourism_flag', 'medium'),
        ];
    }

    /* ═══════════════════════════════════════════════════════════
       URL تصویر ترم (هم attachment ID و هم URL قدیمی)
    ═══════════════════════════════════════════════════════════ */
    private static function term_image_url(int $term_id, string $meta_key, string $size = 'medium'): ?string {
        $value = get_term_meta($term_id, '_' . $meta_key, true);
        if (empty($value)) return null;

        if (is_numeric($value)) {
            return wp_get_attachment_image_url((int) $value, $size)
                ?: wp_get_attachment_url((int) $value)
                ?: null;
        }
        return filter_var($value, FILTER_VALIDATE_URL) ? esc_url_raw($value) : null;
    }

    /* ═══════════════════════════════════════════════════════════
       شمارش همه پست‌های وصل‌شده به ترم‌های شهر در یک کوئری
       خروجی: [term_id => [post_type => count]]
    ═══════════════════════════════════════════════════════════ */
    private static function count_all_posts(array $city_term_ids): array {
        if (empty($city_term_ids)) return [];

        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($city_term_ids), '%d'));

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT tt.term_id, p.post_type, COUNT(*) AS cnt
             FROM {$wpdb->term_relationships} tr
             INNER JOIN {$wpdb->term_taxonomy} tt
                     ON tt.term_taxonomy_id = tr.term_taxonomy_id
                    AND tt.taxonomy = %s
                    AND tt.term_id IN ($placeholders)
             INNER JOIN {$wpdb->posts} p
                     ON p.ID = tr.object_id
                    AND p.post_status = 'publish'
                    AND p.post_type IN ('hotel','destination','tour','restaurant','hospital','airport','travelguide')
             GROUP BY tt.term_id, p.post_type",
            array_merge([self::TAXONOMY], $city_term_ids)
        ));

        $out = [];
        foreach ((array) $rows as $r) {
            $out[(int) $r->term_id][$r->post_type] = (int) $r->cnt;
        }
        return $out;
    }

    /* ═══════════════════════════════════════════════════════════
       کش ویزاها: پست visa که به ترم کشور در tourism وصل شده
       خروجی: [country_term_id => {title, slug}]
    ═══════════════════════════════════════════════════════════ */
    private static function get_visas_by_country(array $country_term_ids): array {
        if (empty($country_term_ids)) return [];

        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($country_term_ids), '%d'));

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT tt.term_id, p.post_title, p.post_name
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->term_relationships} tr
                     ON tr.object_id = p.ID
             INNER JOIN {$wpdb->term_taxonomy} tt
                     ON tt.term_taxonomy_id = tr.term_taxonomy_id
                    AND tt.taxonomy = %s
                    AND tt.term_id IN ($placeholders)
             WHERE p.post_type = 'visa'
               AND p.post_status = 'publish'
             GROUP BY tt.term_id
             ORDER BY p.post_date DESC",
            array_merge([self::TAXONOMY], $country_term_ids)
        ));

        $out = [];
        foreach ((array) $rows as $r) {
            $out[(int) $r->term_id] = [
                'title' => wp_strip_all_tags($r->post_title),
                'slug'  => $r->post_name,
            ];
        }
        return $out;
    }

    /* ═══════════════════════════════════════════════════════════
       نزدیک‌ترین تور برای یک شهر
       منطق:
         1) پیدا کن tour_category با slug یا name هم‌نام شهر
         2) زیردسته‌های آن (سطح ۳ = تورهای واقعی) رو بگیر
         3) هر زیردسته departure_date_en داره → نزدیک‌ترین آینده رو انتخاب کن
    ═══════════════════════════════════════════════════════════ */
    private static function find_next_tour_for_city(\WP_Term $city_term): ?array {
        global $wpdb;

        /* ۱) پیدا کردن tour_category سطح شهر:
           اول تطبیق دقیق، بعد تطبیق شامل (مثل «تور استانبول») */
        $city_tour_cat = $wpdb->get_row($wpdb->prepare(
            "SELECT t.term_id
             FROM {$wpdb->terms} t
             INNER JOIN {$wpdb->term_taxonomy} tt
                     ON tt.term_id = t.term_id AND tt.taxonomy = %s
             WHERE t.slug = %s OR t.name = %s
             LIMIT 1",
            self::TOUR_TAX,
            $city_term->slug,
            $city_term->name
        ));

        /* ⭐ فال‌بک: نام شامل (「تور استانبول」 شامل 「استانبول」) */
        if (!$city_tour_cat) {
            $like = '%' . $wpdb->esc_like($city_term->name) . '%';
            $city_tour_cat = $wpdb->get_row($wpdb->prepare(
                "SELECT t.term_id
                 FROM {$wpdb->terms} t
                 INNER JOIN {$wpdb->term_taxonomy} tt
                         ON tt.term_id = t.term_id AND tt.taxonomy = %s
                 WHERE t.name LIKE %s
                 LIMIT 1",
                self::TOUR_TAX,
                $like
            ));
        }

        if (!$city_tour_cat) return null;

        /* ۲) زیردسته‌های سطح ۳ (تورهای واقعی) با تاریخ اعزام آینده */
        $tours = $wpdb->get_results($wpdb->prepare(
            "SELECT t.term_id, t.name, t.slug,
                    m_dep.meta_value AS departure_en,
                    m_dur.meta_value AS duration,
                    m_tr.meta_value  AS transport,
                    m_al.meta_value  AS airline
             FROM {$wpdb->terms} t
             INNER JOIN {$wpdb->term_taxonomy} tt
                     ON tt.term_id = t.term_id
                    AND tt.taxonomy = %s
                    AND tt.parent = %d
             LEFT JOIN {$wpdb->termmeta} m_dep
                    ON m_dep.term_id = t.term_id AND m_dep.meta_key = 'departure_date_en'
             LEFT JOIN {$wpdb->termmeta} m_dur
                    ON m_dur.term_id = t.term_id AND m_dur.meta_key = 'duration'
             LEFT JOIN {$wpdb->termmeta} m_tr
                    ON m_tr.term_id = t.term_id AND m_tr.meta_key = 'transport'
             LEFT JOIN {$wpdb->termmeta} m_al
                    ON m_al.term_id = t.term_id AND m_al.meta_key = 'airline'
             WHERE m_dep.meta_value IS NOT NULL
               AND m_dep.meta_value <> ''
               AND STR_TO_DATE(m_dep.meta_value, '%%Y-%%m-%%d') >= CURDATE()
             ORDER BY STR_TO_DATE(m_dep.meta_value, '%%Y-%%m-%%d') ASC
             LIMIT 5",
            self::TOUR_TAX,
            (int) $city_tour_cat->term_id
        ));

        if (empty($tours)) return null;

        $t = $tours[0];
        return [
            'title'        => wp_strip_all_tags($t->name),
            'slug'         => $t->slug,
            'departure_en' => $t->departure_en,
            'departure_fa' => self::gregorian_to_jalali_str($t->departure_en),
            'nights'       => (int) $t->duration ?: null,
            'transport'    => $t->transport ?: null,
            'airline'      => $t->airline ?: null,
        ];
    }

    /* ═══════════════════════════════════════════════════════════
       تبدیل میلادی به شمسی (فرمت YYYY/MM/DD)
    ═══════════════════════════════════════════════════════════ */
    private static function gregorian_to_jalali_str(string $gregorian): string {
        $ts = strtotime($gregorian);
        if (!$ts) return $gregorian;

        $j = \IntlDateFormatter::createFromPattern('y/M/d', 'fa_IR@calendar=persian')
            ?->format($ts);
        if (!$j) {
            /* fallback: فقط از ارقام فارسی استفاده کن */
            $j = date('Y/m/d', $ts);
        }
        return $j;
    }
}