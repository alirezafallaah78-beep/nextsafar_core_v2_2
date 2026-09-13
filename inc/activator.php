<?php

namespace NextSafar;

use NextSafar\Database\GeoTable;

class Activator {

    public static function activate() {
        
        // ⭐ ایجاد همه جداول سفارشی
        GeoTable::create_table();
        self::create_sync_log_table();
        self::create_hotel_prices_table();
        self::create_hotel_availability_table();
        self::create_price_history_table();
        self::create_filter_log_table();

        // ثبت پست‌تایپ‌ها و تاکسونومی‌ها
        Core::register_post_types();
        Core::register_taxonomies();
        
        // پاک‌سازی پیوندهای یکتا
        flush_rewrite_rules();
        
        // به‌روزرسانی نسخه
        update_option('nextsafar_version', NEXTSAFAR_VERSION);
        
        // زمان‌بندی Cron اخبار
        \NextSafar\API\NewsSync::schedule_cron();
        
        // ⭐ جداول سیستم اخبار
        \NextSafar\Database\NewsTables::create_sources_table();
        \NextSafar\Database\NewsTables::create_duplicates_table();
        \NextSafar\Database\NewsTables::create_ai_log_table();
        \NextSafar\Database\NewsTables::create_keywords_table();
    }
    
    /**
     * جدول لاگ همگام‌سازی
     */
    public static function create_sync_log_table(): void {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'api_sync_log';
        $charset_collate = $wpdb->get_charset_collate();
        
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name) return;
        
        $sql = "CREATE TABLE {$table_name} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            source VARCHAR(50) NOT NULL DEFAULT 'searchapi',
            entity_type VARCHAR(50) NOT NULL,
            status ENUM('running','completed','failed','partial') DEFAULT 'running',
            records_fetched INT DEFAULT 0,
            records_created INT DEFAULT 0,
            records_updated INT DEFAULT 0,
            records_failed INT DEFAULT 0,
            error_message TEXT,
            started_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            completed_at DATETIME DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY source (source),
            KEY entity_type (entity_type),
            KEY status (status),
            KEY started_at (started_at)
        ) {$charset_collate};";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    /**
     * ⭐ جدول قیمت‌های لحظه‌ای (آینده‌نگرانه)
     * دو حالت را پشتیبانی می‌کند:
     * - هتل دارای پست: hotel_post_id پر می‌شود
     * - هتل آنلاین (مثل آینده با پرتو): hotel_external_id پر می‌شود
     */
    public static function create_hotel_prices_table(): void {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'hotel_prices';
        $charset_collate = $wpdb->get_charset_collate();
        
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name) return;
        
        $sql = "CREATE TABLE {$table_name} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            hotel_post_id BIGINT UNSIGNED DEFAULT NULL COMMENT 'شناسه پست وردپرس (اگر هتل در دیتابیس ما باشد)',
            hotel_external_id VARCHAR(255) DEFAULT NULL COMMENT 'شناسه خارجی (برای هتل‌های آنلاین مثل پرتو)',
            check_in DATE NOT NULL,
            check_out DATE NOT NULL,
            room_type VARCHAR(100) DEFAULT 'standard',
            price DECIMAL(10,2) NOT NULL,
            currency VARCHAR(10) DEFAULT 'USD',
            source VARCHAR(50) DEFAULT 'searchapi',
            expires_at DATETIME DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY hotel_post_id (hotel_post_id),
            KEY hotel_external_id (hotel_external_id),
            KEY check_in (check_in),
            KEY check_out (check_out),
            KEY expires_at (expires_at),
            INDEX idx_post_dates (hotel_post_id, check_in, check_out),
            INDEX idx_ext_dates (hotel_external_id, check_in, check_out)
        ) {$charset_collate};";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    /**
     * ⭐ جدول موجودی اتاق‌ها (آینده‌نگرانه)
     */
    public static function create_hotel_availability_table(): void {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'hotel_availability';
        $charset_collate = $wpdb->get_charset_collate();
        
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name) return;
        
        $sql = "CREATE TABLE {$table_name} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            hotel_post_id BIGINT UNSIGNED DEFAULT NULL,
            hotel_external_id VARCHAR(255) DEFAULT NULL,
            check_in DATE NOT NULL,
            check_out DATE NOT NULL,
            room_type VARCHAR(100) DEFAULT 'standard',
            available TINYINT(1) DEFAULT 1,
            rooms_left INT DEFAULT 0,
            source VARCHAR(50) DEFAULT 'searchapi',
            expires_at DATETIME DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY hotel_post_id (hotel_post_id),
            KEY hotel_external_id (hotel_external_id),
            KEY check_in (check_in),
            KEY check_out (check_out),
            KEY expires_at (expires_at),
            INDEX idx_post_dates (hotel_post_id, check_in, check_out),
            INDEX idx_ext_dates (hotel_external_id, check_in, check_out)
        ) {$charset_collate};";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    /**
     * جدول تاریخچه قیمت‌ها
     */
    public static function create_price_history_table(): void {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'price_history';
        $charset_collate = $wpdb->get_charset_collate();
        
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name) return;
        
        $sql = "CREATE TABLE {$table_name} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            hotel_post_id BIGINT UNSIGNED DEFAULT NULL,
            hotel_external_id VARCHAR(255) DEFAULT NULL,
            check_in DATE NOT NULL,
            check_out DATE NOT NULL,
            price DECIMAL(10,2) NOT NULL,
            currency VARCHAR(10) DEFAULT 'USD',
            source VARCHAR(50) DEFAULT 'searchapi',
            recorded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY hotel_post_id (hotel_post_id),
            KEY hotel_external_id (hotel_external_id),
            KEY check_in (check_in),
            KEY recorded_at (recorded_at),
            INDEX idx_post_date (hotel_post_id, check_in)
        ) {$charset_collate};";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * جدول لاگ تصمیمات فیلتر اخبار
     */
    public static function create_filter_log_table(): void {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'ns_news_filter_log';
        $charset_collate = $wpdb->get_charset_collate();
        
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name) return;
        
        $sql = "CREATE TABLE {$table_name} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            news_title VARCHAR(500) NOT NULL,
            source_name VARCHAR(100),
            score INT DEFAULT 0,
            decision ENUM('publish', 'draft', 'delete') DEFAULT 'publish',
            reason TEXT,
            positive_matches TEXT,
            negative_matches TEXT,
            ai_checked TINYINT(1) DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_decision (decision),
            INDEX idx_created (created_at),
            INDEX idx_score (score)
        ) {$charset_collate};";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
}