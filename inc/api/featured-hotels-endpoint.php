<?php
namespace NextSafar\API;

if (!defined('ABSPATH')) exit;

/**
 * Endpoint: /nextsafar/v1/featured-hotels
 * برمی‌گرداند: هتل‌های علامت‌گذاری‌شده با _hotel_featured=1
 */
class FeaturedHotelsEndpoint {

    const CACHE_KEY = 'ns_featured_hotels';
    const CACHE_DURATION = 1800; // 30 دقیقه

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'register']);
        add_action('save_post_hotel', [__CLASS__, 'flush']);
        add_action('deleted_post', [__CLASS__, 'flush']);
    }

    public static function flush() {
        delete_transient(self::CACHE_KEY);
    }

    public static function register() {
        register_rest_route('nextsafar/v1', '/featured-hotels', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'get'],
            'permission_callback' => '__return_true',
        ]);
    }

    public static function get() {
        $cached = get_transient(self::CACHE_KEY);
        if ($cached !== false) {
            return rest_ensure_response($cached);
        }

        /* گرفتن هتل‌های featured */
        $hotels = get_posts([
            'post_type'      => 'hotel',
            'posts_per_page' => 6,
            'post_status'    => 'publish',
            'orderby'        => 'date',
            'order'          => 'DESC',
            'meta_query'     => [[
                'key'     => '_hotel_featured',
                'value'   => '1',
                'compare' => '=',
            ]],
        ]);

        $out = [];
        foreach ($hotels as $hotel) {
            $out[] = self::format_hotel($hotel);
        }

        set_transient(self::CACHE_KEY, $out, self::CACHE_DURATION);
        return rest_ensure_response($out);
    }

    private static function format_hotel($hotel) {
        $image_id = get_post_thumbnail_id($hotel->ID);
        $image = $image_id ? wp_get_attachment_image_url($image_id, 'large') : null;

        /* گرفتن متاها */
        $stars = get_post_meta($hotel->ID, '_hotel_stars', true);
        $rating = get_post_meta($hotel->ID, '_hotel_rating', true);
        $amenities = get_post_meta($hotel->ID, '_hotel_amenities', true);

        /* پارس amenities */
        if (is_string($amenities)) {
            $amenities = maybe_unserialize($amenities);
        }
        if (!is_array($amenities)) {
            $amenities = [];
        }
        $top_amenities = array_slice($amenities, 0, 4);

        /* ═══ گرفتن شهر و کشور از tourism ═══ */
        $city = null;
        $country = null;
        $tourism_terms = wp_get_post_terms($hotel->ID, 'tourism', ['fields' => 'all']);
        
        if (!is_wp_error($tourism_terms) && !empty($tourism_terms)) {
            /* اولین ترم tourism = شهر */
            $city_term = $tourism_terms[0];
            $city = $city_term->name;
            
            /* اگه parent داشت = کشور والد */
            if ($city_term->parent > 0) {
                $parent = get_term($city_term->parent, 'tourism');
                if ($parent && !is_wp_error($parent)) {
                    $country = $parent->name;
                }
            }
        }

        /* فال‌بک: از متاهای قدیمی */
        if (!$city) {
            $city = get_post_meta($hotel->ID, '_hotel_city', true) ?: null;
        }
        if (!$country) {
            $country = get_post_meta($hotel->ID, '_hotel_country', true) ?: null;
        }

        return [
            'id'         => $hotel->ID,
            'title'      => wp_strip_all_tags($hotel->post_title),
            'slug'       => $hotel->post_name,
            'image'      => $image,
            'stars'      => $stars ? (int) $stars : null,
            'rating'     => $rating ? (float) $rating : null,
            'city'       => $city,
            'country'    => $country,
            'amenities'  => $top_amenities,
            'url'        => '/hotels/' . $hotel->post_name,
        ];
    }
}