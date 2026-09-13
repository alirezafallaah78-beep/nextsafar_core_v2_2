<?php
namespace NextSafar\API;

if (!defined('ABSPATH')) exit;

/**
 * Webhook: اطلاع به Next.js هنگام تغییر داده‌ها
 */
class RevalidationWebhook {

    const NEXTJS_URL = 'http://localhost:3000'; // در production: https://nextsafar.com
    const SECRET     = 'your-secret-key-here-change-this'; // باید با .env.local یکی باشه

    public static function init() {
        /* هتل‌ها */
        add_action('save_post_hotel', [__CLASS__, 'on_hotel_change'], 20, 2);
        add_action('delete_post', [__CLASS__, 'on_hotel_change'], 20, 2);
        
        /* پست‌های دیگه */
        add_action('save_post_destination', [__CLASS__, 'on_content_change'], 20, 2);
        add_action('save_post_tour', [__CLASS__, 'on_content_change'], 20, 2);
        add_action('save_post_travelguide', [__CLASS__, 'on_content_change'], 20, 2);
        
        /* Tourism taxonomy */
        add_action('edited_tourism', [__CLASS__, 'on_tourism_change']);
        add_action('created_tourism', [__CLASS__, 'on_tourism_change']);
    }

    /* ─── تغییر هتل → revalidate صفحه اصلی + لیست هتل‌ها ─── */
    public static function on_hotel_change($post_id, $post = null) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!$post || $post->post_status !== 'publish') return;

        self::trigger_revalidation([
            '/',           // صفحه اصلی (Featured Hotels)
            '/hotels',     // لیست هتل‌ها
            '/hotels/' . $post->post_name, // جزئیات این هتل
        ]);
    }

    /* ─── تغییر محتوای عمومی ─── */
    public static function on_content_change($post_id, $post = null) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!$post || $post->post_status !== 'publish') return;

        self::trigger_revalidation(['/']);
    }

    /* ─── تغییر tourism ─── */
    public static function on_tourism_change($term_id) {
        self::trigger_revalidation(['/']);
    }

    /* ─── فراخوانی API Next.js ─── */
    private static function trigger_revalidation(array $paths) {
        $url = self::NEXTJS_URL . '/api/revalidate';
        
        $response = wp_remote_post($url, [
            'timeout' => 5,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => json_encode([
                'secret' => self::SECRET,
                'paths'  => $paths,
            ]),
        ]);

        if (is_wp_error($response)) {
            error_log('❌ Revalidation failed: ' . $response->get_error_message());
            return;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            error_log('❌ Revalidation error: HTTP ' . $code);
            return;
        }

        error_log('✅ Revalidated: ' . implode(', ', $paths));
    }
}