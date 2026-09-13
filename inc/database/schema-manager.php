<?php
/**
 * SchemaManager — مدیریت نسخه و ارتقاء جداول دیتابیس
 * این کلاس مسئول ساخت، ارتقاء و نگهداری همه جداول سفارشی است
 */

namespace NextSafar\Database;

use NextSafar\Activator;

if (!defined('ABSPATH')) exit;

class SchemaManager {
    
    /** گزینه ذخیره نسخه دیتابیس */
    const DB_VERSION_OPTION = 'nextsafar_db_version';
    
    /** نسخه فعلی ساختار دیتابیس */
    const CURRENT_VERSION = '1.1.0';
    
    /**
     * راه‌اندازی هوک‌ها
     */
    public static function init(): void {
        add_action('admin_init', [__CLASS__, 'maybe_upgrade']);
    }
    
    /**
     * بررسی و ارتقاء خودکار در صورت نیاز
     */
    public static function maybe_upgrade(): void {
        $installed = get_option(self::DB_VERSION_OPTION, '0.0.0');
        
        if (version_compare($installed, self::CURRENT_VERSION, '<')) {
            self::upgrade();
        }
    }
    
    public static function upgrade(): void {
        global $wpdb;
        
        error_log('🔄 NextSafar: شروع ارتقاء دیتابیس به نسخه ' . self::CURRENT_VERSION);
        
        // ساخت/ارتقاء همه جداول
        GeoTable::create_table();
        self::upgrade_sync_log_table();
        self::upgrade_hotel_prices_table();
        self::upgrade_hotel_availability_table();
        self::upgrade_price_history_table();
        
        // ✅ خط جدید: جدول‌های سیستم اخبار
        \NextSafar\Database\NewsTables::create_sources_table();
        \NextSafar\Database\NewsTables::create_duplicates_table();
        \NextSafar\Database\NewsTables::create_ai_log_table();
        \NextSafar\Database\NewsTables::create_keywords_table();
        \NextSafar\Activator::create_filter_log_table();
        
        // ذخیره نسخه جدید
        update_option(self::DB_VERSION_OPTION, self::CURRENT_VERSION);
        
        error_log('✅ NextSafar: ارتقاء دیتابیس کامل شد');
    }
    
    /**
     * ⭐ بازسازی جداول خالی (فقط وقتی هیچ داده‌ای ندارند)
     * برای محیط توسعه/لوکال مناسب است
     */
    public static function rebuild_empty_tables(): array {
        global $wpdb;
        $results = [];
        
        $tables = [
            'api_sync_log',
            'hotel_prices',
            'hotel_availability',
            'price_history',
        ];
        
        foreach ($tables as $table) {
            $full_name = $wpdb->prefix . $table;
            
            // بررسی وجود جدول
            if ($wpdb->get_var("SHOW TABLES LIKE '$full_name'") !== $full_name) {
                $results[$table] = 'جدول وجود نداشت';
                continue;
            }
            
            // بررسی خالی بودن
            $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$full_name}");
            
            if ($count === 0) {
                // حذف و بازسازی
                $wpdb->query("DROP TABLE {$full_name}");
                $results[$table] = 'حذف و برای بازسازی آماده شد';
            } else {
                $results[$table] = "دارای {$count} رکورد است - حذف نشد";
            }
        }
        
        // بازسازی همه جداول
        self::upgrade_sync_log_table();
        self::upgrade_hotel_prices_table();
        self::upgrade_hotel_availability_table();
        self::upgrade_price_history_table();
        
        // به‌روزرسانی نسخه
        update_option(self::DB_VERSION_OPTION, self::CURRENT_VERSION);
        
        return $results;
    }
    
    /**
     * جدول لاگ سینک
     */
    private static function upgrade_sync_log_table(): void {
        Activator::create_sync_log_table();
    }
    
    /**
     * ⭐ ارتقاء جدول قیمت‌ها با پشتیبانی از ساختار قدیمی و جدید
     */
    private static function upgrade_hotel_prices_table(): void {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'hotel_prices';
        
        // اگر جدول وجود ندارد، بساز
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") !== $table_name) {
            Activator::create_hotel_prices_table();
            return;
        }
        
        // بررسی فیلدها
        $columns = wp_list_pluck($wpdb->get_results("SHOW COLUMNS FROM {$table_name}"), 'Field');
        
        $has_old = in_array('hotel_id', $columns);
        $has_post_id = in_array('hotel_post_id', $columns);
        $has_ext_id = in_array('hotel_external_id', $columns);
        
        // حالت ۱: ساختار قدیمی (فقط hotel_id) → تبدیل به جدید
        if ($has_old && !$has_post_id) {
            $wpdb->query("ALTER TABLE {$table_name} 
                CHANGE COLUMN hotel_id hotel_post_id BIGINT UNSIGNED DEFAULT NULL,
                ADD COLUMN hotel_external_id VARCHAR(255) DEFAULT NULL AFTER hotel_post_id,
                ADD KEY hotel_external_id (hotel_external_id)");
            
            error_log('✅ جدول قیمت‌ها از ساختار قدیمی ارتقا یافت');
            return;
        }
        
        // حالت ۲: فیلد خارجی ندارد → اضافه کن
        if ($has_post_id && !$has_ext_id) {
            $wpdb->query("ALTER TABLE {$table_name} 
                ADD COLUMN hotel_external_id VARCHAR(255) DEFAULT NULL AFTER hotel_post_id,
                ADD KEY hotel_external_id (hotel_external_id)");
            
            error_log('✅ فیلد hotel_external_id به جدول قیمت‌ها اضافه شد');
        }
    }
    
    /**
     * ⭐ ارتقاء جدول موجودی اتاق‌ها
     */
    private static function upgrade_hotel_availability_table(): void {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'hotel_availability';
        
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") !== $table_name) {
            Activator::create_hotel_availability_table();
            return;
        }
        
        $columns = wp_list_pluck($wpdb->get_results("SHOW COLUMNS FROM {$table_name}"), 'Field');
        
        $has_old = in_array('hotel_id', $columns);
        $has_post_id = in_array('hotel_post_id', $columns);
        $has_ext_id = in_array('hotel_external_id', $columns);
        
        if ($has_old && !$has_post_id) {
            $wpdb->query("ALTER TABLE {$table_name} 
                CHANGE COLUMN hotel_id hotel_post_id BIGINT UNSIGNED DEFAULT NULL,
                ADD COLUMN hotel_external_id VARCHAR(255) DEFAULT NULL AFTER hotel_post_id,
                ADD KEY hotel_external_id (hotel_external_id)");
            
            error_log('✅ جدول موجودی از ساختار قدیمی ارتقا یافت');
            return;
        }
        
        if ($has_post_id && !$has_ext_id) {
            $wpdb->query("ALTER TABLE {$table_name} 
                ADD COLUMN hotel_external_id VARCHAR(255) DEFAULT NULL AFTER hotel_post_id,
                ADD KEY hotel_external_id (hotel_external_id)");
        }
    }
    
    /**
     * ⭐ ارتقاء جدول تاریخچه قیمت‌ها
     */
    private static function upgrade_price_history_table(): void {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'price_history';
        
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") !== $table_name) {
            Activator::create_price_history_table();
            return;
        }
        
        $columns = wp_list_pluck($wpdb->get_results("SHOW COLUMNS FROM {$table_name}"), 'Field');
        
        $has_old = in_array('hotel_id', $columns);
        $has_post_id = in_array('hotel_post_id', $columns);
        $has_ext_id = in_array('hotel_external_id', $columns);
        
        if ($has_old && !$has_post_id) {
            $wpdb->query("ALTER TABLE {$table_name} 
                CHANGE COLUMN hotel_id hotel_post_id BIGINT UNSIGNED DEFAULT NULL,
                ADD COLUMN hotel_external_id VARCHAR(255) DEFAULT NULL AFTER hotel_post_id,
                ADD KEY hotel_external_id (hotel_external_id)");
            
            error_log('✅ جدول تاریخچه قیمت از ساختار قدیمی ارتقا یافت');
            return;
        }
        
        if ($has_post_id && !$has_ext_id) {
            $wpdb->query("ALTER TABLE {$table_name} 
                ADD COLUMN hotel_external_id VARCHAR(255) DEFAULT NULL AFTER hotel_post_id,
                ADD KEY hotel_external_id (hotel_external_id)");
        }
    }
    
    /**
     * دریافت نسخه فعلی دیتابیس
     */
    public static function get_installed_version(): string {
        return get_option(self::DB_VERSION_OPTION, '0.0.0');
    }
}