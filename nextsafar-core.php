<?php
/**
 * Plugin Name: NextSafar Core
 * Description: پلاگین اصلی سایت nextsafar.com
 * Version: 1.2.0
 * Author: NextSafar Team
 * Text Domain: nextsafar
 * Requires PHP: 8.1
 */

if (!defined('ABSPATH')) exit;

define('NEXTSAFAR_VERSION', '1.2.0');
define('NEXTSAFAR_PATH', plugin_dir_path(__FILE__));
define('NEXTSAFAR_URL', plugin_dir_url(__FILE__));

// ═══════════════════════════════════════════════════════════
// ۱. AUTOLOADER — بارگذاری هوشمند کلاس‌ها
// ═══════════════════════════════════════════════════════════
require_once NEXTSAFAR_PATH . 'inc/autoloader.php';

// ═══════════════════════════════════════════════════════════
// ۲. CORE HOOKS — فعال‌سازی و غیرفعال‌سازی
// ═══════════════════════════════════════════════════════════
require_once NEXTSAFAR_PATH . 'inc/activator.php';
require_once NEXTSAFAR_PATH . 'inc/deactivator.php';
require_once NEXTSAFAR_PATH . 'inc/media.php';

// ═══════════════════════════════════════════════════════════
// ۳. DATABASE LAYER — جداول و Schema (قبل از همه چیز)
// ═══════════════════════════════════════════════════════════
require_once NEXTSAFAR_PATH . 'inc/database/geo-table.php';
require_once NEXTSAFAR_PATH . 'inc/database/schema-manager.php';
require_once NEXTSAFAR_PATH . 'inc/database/news-tables.php';

// ═══════════════════════════════════════════════════════════
// ۴. GEO SYSTEM — پایه داده‌های مکانی (قبل از Sync ها)
// ═══════════════════════════════════════════════════════════
require_once NEXTSAFAR_PATH . 'inc/sync/geo-schema.php';
require_once NEXTSAFAR_PATH . 'inc/sync/geo-sync.php';
require_once NEXTSAFAR_PATH . 'inc/sync/place-enrich-trait.php';
require_once NEXTSAFAR_PATH . 'inc/sync/migrate-geo-meta.php';

// ═══════════════════════════════════════════════════════════
// ۵. API CLIENTS — کلاینت‌های ارتباط با سرویس‌های خارجی
// ═══════════════════════════════════════════════════════════
require_once NEXTSAFAR_PATH . 'inc/api/base-client.php';
require_once NEXTSAFAR_PATH . 'inc/api/searchapi-client.php';
require_once NEXTSAFAR_PATH . 'inc/api/serpapi-client.php';
require_once NEXTSAFAR_PATH . 'inc/api/image-manager.php';
require_once NEXTSAFAR_PATH . 'inc/api/wikipedia-client.php';
require_once NEXTSAFAR_PATH . 'inc/api/rate-limiter.php';
require_once NEXTSAFAR_PATH . 'inc/api/gemini-client.php';
require_once NEXTSAFAR_PATH . 'inc/api/revalidation-webhook.php';

// ═══════════════════════════════════════════════════════════
// ۶. SYNC BASE — کلاس‌های پایه سینک
// ═══════════════════════════════════════════════════════════
require_once NEXTSAFAR_PATH . 'inc/api/base-sync.php';
require_once NEXTSAFAR_PATH . 'inc/api/batch-sync.php';

// ═══════════════════════════════════════════════════════════
// ۷. SYNC CLASSES — کلاس‌های سینک موجودیت‌ها
// ═══════════════════════════════════════════════════════════
require_once NEXTSAFAR_PATH . 'inc/api/hotel-sync.php';
require_once NEXTSAFAR_PATH . 'inc/api/destination-sync.php';
require_once NEXTSAFAR_PATH . 'inc/api/restaurant-sync.php';
require_once NEXTSAFAR_PATH . 'inc/api/hospital-sync.php';

// ═══════════════════════════════════════════════════════════
// ۸. NEWS AGGREGATOR SYSTEM — سیستم دریافت اخبار
// ═══════════════════════════════════════════════════════════
require_once NEXTSAFAR_PATH . 'inc/api/rss-fetcher.php';
require_once NEXTSAFAR_PATH . 'inc/api/newsapi-fetcher.php';
require_once NEXTSAFAR_PATH . 'inc/api/duplicate-checker.php';
require_once NEXTSAFAR_PATH . 'inc/api/ai-rewriter.php';
require_once NEXTSAFAR_PATH . 'inc/api/news-filter.php';
require_once NEXTSAFAR_PATH . 'inc/api/news-sync.php';

// ═══════════════════════════════════════════════════════════
// ۹. POST TYPES — پست تایپ‌های سیستم
// ═══════════════════════════════════════════════════════════
require_once NEXTSAFAR_PATH . 'inc/posttypes/hotel.php';
require_once NEXTSAFAR_PATH . 'inc/posttypes/airport.php';
require_once NEXTSAFAR_PATH . 'inc/posttypes/destination.php';
require_once NEXTSAFAR_PATH . 'inc/posttypes/restaurant.php';
require_once NEXTSAFAR_PATH . 'inc/posttypes/hospital.php';
require_once NEXTSAFAR_PATH . 'inc/posttypes/tour.php';
require_once NEXTSAFAR_PATH . 'inc/posttypes/visa.php';
require_once NEXTSAFAR_PATH . 'inc/posttypes/travelguide.php';
require_once NEXTSAFAR_PATH . 'inc/posttypes/travelnews.php';

// ═══════════════════════════════════════════════════════════
// ۱۰. TAXONOMIES — دسته‌بندی‌ها و برچسب‌ها
// ═══════════════════════════════════════════════════════════
require_once NEXTSAFAR_PATH . 'inc/taxonomies/hotel-category.php';
require_once NEXTSAFAR_PATH . 'inc/taxonomies/hotel-tag.php';
require_once NEXTSAFAR_PATH . 'inc/taxonomies/airport-category.php';
require_once NEXTSAFAR_PATH . 'inc/taxonomies/airport-tag.php';
require_once NEXTSAFAR_PATH . 'inc/taxonomies/destination-category.php';
require_once NEXTSAFAR_PATH . 'inc/taxonomies/destination-tag.php';
require_once NEXTSAFAR_PATH . 'inc/taxonomies/restaurant-category.php';
require_once NEXTSAFAR_PATH . 'inc/taxonomies/restaurant-tag.php';
require_once NEXTSAFAR_PATH . 'inc/taxonomies/hospital-category.php';
require_once NEXTSAFAR_PATH . 'inc/taxonomies/hospital-tag.php';
require_once NEXTSAFAR_PATH . 'inc/taxonomies/tour-category.php';
require_once NEXTSAFAR_PATH . 'inc/taxonomies/tour-tag.php';
require_once NEXTSAFAR_PATH . 'inc/taxonomies/tour-category-meta.php';
require_once NEXTSAFAR_PATH . 'inc/taxonomies/visa-category.php';
require_once NEXTSAFAR_PATH . 'inc/taxonomies/visa-tag.php';
require_once NEXTSAFAR_PATH . 'inc/taxonomies/travelguide-category.php';
require_once NEXTSAFAR_PATH . 'inc/taxonomies/travelguide-tag.php';
require_once NEXTSAFAR_PATH . 'inc/taxonomies/travelnews-category.php';
require_once NEXTSAFAR_PATH . 'inc/taxonomies/travelnews-tag.php';
require_once NEXTSAFAR_PATH . 'inc/taxonomies/term-images.php';

// ===== Tourism System =====
require_once NEXTSAFAR_PATH . 'inc/taxonomies/tourism.php';
require_once NEXTSAFAR_PATH . 'inc/taxonomies/tourism-meta.php';

// ═══════════════════════════════════════════════════════════
// ۱۱. METABOXES — باکس‌های اطلاعاتی در ویرایشگر
// ═══════════════════════════════════════════════════════════
require_once NEXTSAFAR_PATH . 'inc/metaboxes/hotel-metabox.php';
require_once NEXTSAFAR_PATH . 'inc/metaboxes/hotel-featured-meta.php';
require_once NEXTSAFAR_PATH . 'inc/metaboxes/airport-metabox.php';
require_once NEXTSAFAR_PATH . 'inc/metaboxes/airport-facilities.php';
require_once NEXTSAFAR_PATH . 'inc/metaboxes/airport-services.php';
require_once NEXTSAFAR_PATH . 'inc/metaboxes/airport-airlines.php';
require_once NEXTSAFAR_PATH . 'inc/metaboxes/flag-metabox.php';
require_once NEXTSAFAR_PATH . 'inc/metaboxes/destination-metabox.php';
require_once NEXTSAFAR_PATH . 'inc/metaboxes/gallery-metabox.php';
require_once NEXTSAFAR_PATH . 'inc/metaboxes/restaurant-metabox.php';
require_once NEXTSAFAR_PATH . 'inc/metaboxes/restaurant-facilities.php';
require_once NEXTSAFAR_PATH . 'inc/metaboxes/hospital-metabox.php';
require_once NEXTSAFAR_PATH . 'inc/metaboxes/tour-metabox.php';
require_once NEXTSAFAR_PATH . 'inc/metaboxes/visa-metabox.php';
require_once NEXTSAFAR_PATH . 'inc/metaboxes/travelguide-metabox.php';
require_once NEXTSAFAR_PATH . 'inc/metaboxes/travelnews-metabox.php';
require_once NEXTSAFAR_PATH . 'inc/metaboxes/geo-metabox.php';

// ═══════════════════════════════════════════════════════════
// ۱۲. ADMIN — پنل مدیریت
// ═══════════════════════════════════════════════════════════
require_once NEXTSAFAR_PATH . 'inc/admin/assets.php';
require_once NEXTSAFAR_PATH . 'inc/admin/settings.php';
require_once NEXTSAFAR_PATH . 'inc/admin/exchange.php';
require_once NEXTSAFAR_PATH . 'inc/admin/sync-page.php';
require_once NEXTSAFAR_PATH . 'inc/admin/news-filter-settings.php';
require_once NEXTSAFAR_PATH . 'inc/admin/menu.php';
include_once NEXTSAFAR_PATH . 'inc/admin/icon-menu.php';

// ═══════════════════════════════════════════════════════════
// ۱۳. REST API ENDPOINTS — نقاط پایانی API
// ═══════════════════════════════════════════════════════════
require_once NEXTSAFAR_PATH . 'inc/api/search-endpoint.php';
require_once NEXTSAFAR_PATH . 'inc/api/news-endpoint.php';
require_once NEXTSAFAR_PATH . 'inc/api/menu-endpoint.php';
require_once NEXTSAFAR_PATH . 'inc/api/stats-endpoint.php';
require_once NEXTSAFAR_PATH . 'inc/api/world-endpoint.php';
require_once NEXTSAFAR_PATH . 'inc/api/country-posts-endpoint.php';
require_once NEXTSAFAR_PATH . 'inc/api/featured-hotels-endpoint.php';

// ═══ AI TRIP PLANNER ═══
require_once NEXTSAFAR_PATH . 'inc/database/ai-trip-table.php';
require_once NEXTSAFAR_PATH . 'inc/api/ai-trip-gemini-client.php';
require_once NEXTSAFAR_PATH . 'inc/api/ai-trip-planner-endpoint.php';
require_once NEXTSAFAR_PATH . 'inc/admin/ai-trip-settings.php';

// ═══════════════════════════════════════════════════════════
// ۱۴. CORE — هسته اصلی پلاگین (آخر از همه)
// ═══════════════════════════════════════════════════════════
require_once NEXTSAFAR_PATH . 'inc/core.php';

// ═══════════════════════════════════════════════════════════
// ACTIVATION / DEACTIVATION
// ═══════════════════════════════════════════════════════════
register_activation_hook(__FILE__, ['NextSafar\Activator', 'activate']);
register_deactivation_hook(__FILE__, ['NextSafar\Deactivator', 'deactivate']);

// ═══════════════════════════════════════════════════════════
// BOOTSTRAP — راه‌اندازی پلاگین
// ═══════════════════════════════════════════════════════════
add_action('plugins_loaded', function () {
    NextSafar\Core::init();
    \NextSafar\Database\SchemaManager::init();
    \NextSafar\API\NewsSync::init();
    \NextSafar\Admin\Assets::init();
    \NextSafar\Admin\Menu::init();
}, 10);

// ═══════════════════════════════════════════════════════════
// SETTINGS — تنظیمات وردپرس
// ═══════════════════════════════════════════════════════════
add_action('init', function () {
    register_setting('nextsafar_settings', 'nextsafar_google_places_key', [
        'sanitize_callback' => 'sanitize_text_field',
    ]);
    register_setting('nextsafar_settings', 'nextsafar_searchapi_key', [
        'sanitize_callback' => 'sanitize_text_field',
    ]);
    register_setting('nextsafar_settings', 'nextsafar_serpapi_key', [
        'sanitize_callback' => 'sanitize_text_field',
    ]);
});

// ═══════════════════════════════════════════════════════════
// MENUS — فهرست‌های وردپرس
// ═══════════════════════════════════════════════════════════
add_action('after_setup_theme', function () {
    register_nav_menus([
        'mainmenu' => __('منوی اصلی', 'nextsafar'),
        'secmenu'  => __('منوی دسته‌ها', 'nextsafar'),
    ]);
});

// ═══════════════════════════════════════════════════════════
// LAZY INIT — بررسی وجود جداول در هر بارگذاری
// ═══════════════════════════════════════════════════════════
add_action('admin_init', [\NextSafar\API\NewsSync::class, 'ensure_tables_exist']);
add_action('rest_api_init', [\NextSafar\API\NewsSync::class, 'ensure_tables_exist']);

/**
 * پاکسازی هوک‌ها هنگام غیرفعال شدن پلاگین
 */
add_action('deactivate_' . plugin_basename(__FILE__), function () {
    // ✅ فقط هوک‌های سیستم اول (news-sync.php)
    wp_clear_scheduled_hook('nextsafar_news_hourly_tick');
    wp_clear_scheduled_hook('nextsafar_news_retry_hook');
    delete_transient('ns_news_sync_lock');
    delete_transient('ns_news_last_cron_run');
    error_log('🧹 NextSafar: All cron hooks cleared on plugin deactivation');
});

