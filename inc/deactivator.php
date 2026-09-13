<?php
namespace NextSafar;

if (!defined('ABSPATH')) exit;

/**
 * Deactivator — مدیریت غیرفعال‌سازی پلاگین
 * 
 * ⚠️ توجه: در این متد جداول حذف نمی‌شوند.
 * حذف جداول فقط در زمان حذف کامل پلاگین (uninstall) انجام می‌شود
 * تا اگر کاربر پلاگین را موقتاً غیرفعال کرد، داده‌هایش حفظ شود.
 */
class Deactivator {

    /**
     * اجرای عملیات غیرفعال‌سازی
     */
    public static function deactivate() {
        
        // ۱. پاک‌سازی همه Cron Jobs
        self::clear_all_cron_jobs();
        
        // ۲. پاک‌سازی Rewrite Rules
        flush_rewrite_rules();
        
        // ۳. پاک‌سازی کش‌های موقت (اختیاری)
        self::clear_temporary_transients();
        
        // ۴. ثبت زمان غیرفعال‌سازی (برای دیباگ)
        update_option('nextsafar_deactivated_at', current_time('mysql'));
        
        error_log('✅ NextSafar Core: پلاگین با موفقیت غیرفعال شد');
    }

    /**
     * پاک‌سازی همه Cron Jobs پلاگین
     */
    private static function clear_all_cron_jobs(): void {
        $cron_hooks = [
            // Cron سیستم اخبار
            'nextsafar_news_cron_hook',
            
            // Cron های دیگر (در صورت وجود)
            'ns_enrich_locations_cron',
            'nextsafar_exchange_update_cron',
            'nextsafar_sync_cron',
        ];
        
        foreach ($cron_hooks as $hook) {
            wp_clear_scheduled_hook($hook);
            error_log('🗑️ NextSafar: Cron hook cleared: ' . $hook);
        }
    }

    /**
     * پاک‌سازی Transient های موقت (نه دائمی)
     * 
     * توجه: فقط transient های کش را پاک می‌کنیم، نه داده‌های مهم
     */
    private static function clear_temporary_transients(): void {
        global $wpdb;
        
        // لیست پترن‌های transient موقت
        $patterns = [
            'ns_ai_rates_cache',
            'visa_api_rates_cache',
            'nextsafar_news_overview_stats',
            'nextsafar_news_public_stats',
            'ns_ai_consecutive_fails',
            'ns_ai_fail_count',
            'ns_ai_disabled_until',
        ];
        
        foreach ($patterns as $pattern) {
            delete_transient($pattern);
        }
        
        // پاک‌سازی کش‌های maps با پترن
        $wpdb->query(
            "DELETE FROM {$wpdb->options} 
             WHERE option_name LIKE '_transient_ns_maps_%' 
             OR option_name LIKE '_transient_timeout_ns_maps_%'"
        );
        
        error_log('🗑️ NextSafar: Temporary transients cleared');
    }
}