<?php
/**
 * NextSafar Core Class
 * هماهنگ‌کننده اصلی سیستم
 * 
 * @version 2.2.0 - اصلاح زمان‌بندی HotelFeaturedMeta
 */

namespace NextSafar;

if (!defined('ABSPATH')) exit;

class Core {
    
    public static function init() {
        // ✅ بارگذاری RateLimiter در ابتدا
        require_once __DIR__ . '/api/rate-limiter.php';
        
        // ثبت Post Types و Taxonomies
        add_action('init', [__CLASS__, 'register_post_types']);
        add_action('init', [__CLASS__, 'register_taxonomies']);
        add_action('add_meta_boxes', [__CLASS__, 'register_metaboxes']);
        
        add_action('init', function() {
            \NextSafar\Database\GeoTable::maybe_upgrade();
        });
        
        MetaBoxes\HotelFeaturedMeta::init();
        API\RevalidationWebhook::init();
        
        // ===== MetaBox Hooks =====
        MetaBoxes\GeoMetaBox::register_hooks();
        MetaBoxes\HotelMetaBox::register_hooks();
        MetaBoxes\GalleryMetaBox::register_hooks();
        MetaBoxes\AirportMetaBox::register_hooks();
        MetaBoxes\AirportFacilities::register_hooks();
        MetaBoxes\AirportServices::register_hooks();
        MetaBoxes\AirportAirlines::register_hooks();
        MetaBoxes\FlagMetaBox::register_hooks();
        MetaBoxes\DestinationMetaBox::register_hooks();
        MetaBoxes\RestaurantMetaBox::register_hooks();
        MetaBoxes\RestaurantFacilities::register_hooks();
        MetaBoxes\HospitalMetaBox::register_hooks();
        MetaBoxes\TourMetaBox::register_hooks();
        MetaBoxes\VisaMetaBox::register_hooks();
        MetaBoxes\TravelGuideMetaBox::register_hooks();

        // ===== Tourism System =====
        Taxonomies\TourismMeta::init();

        // ===== Taxonomy Meta =====
        Taxonomies\TourCategoryMeta::init();

        // ===== ⭐ Cron ها =====
        add_action('ns_enrich_locations_cron', [__CLASS__, 'run_enrich_locations']);
        
        // ✅ مهم: ثبت hook برای Cron اخبار
        \NextSafar\API\NewsSync::init();
        
        add_action('save_post', function($post_id, $post) {
            $allowed_types = ['hotel', 'airport', 'destination', 'restaurant', 'hospital'];
            
            if (in_array($post->post_type, $allowed_types) && $post->post_status === 'publish') {
                \NextSafar\Sync\GeoSync::sync_to_custom_table($post_id);
            }
        }, 20, 2);
        
        if (is_admin()) {
            Admin\Menu::init();
            Admin\SyncPage::init();
            Taxonomies\TermImages::init();
        }

        API\MenuEndpoint::init();
        API\SearchEndpoint::init();
        API\StatsEndpoint::init();
        API\WorldEndpoint::init();
        API\CountryPostsEndpoint::init();
        API\FeaturedHotelsEndpoint::init();

        /* ═══ AI Trip Planner ═══ */
        Database\AiTripTable::init();
        API\AiTripPlannerEndpoint::init();
        Admin\AiTripSettings::init();    
    }

    public static function register_post_types() {
        PostTypes\Hotel::register();
        PostTypes\Airport::register();
        PostTypes\Destination::register();
        PostTypes\Restaurant::register();
        PostTypes\Hospital::register();
        PostTypes\Tour::register();
        PostTypes\Visa::register();
        PostTypes\TravelGuide::register();
        PostTypes\TravelNews::register();
    }

    public static function register_taxonomies() {
        Taxonomies\HotelCategory::register();
        Taxonomies\HotelTag::register();
        Taxonomies\AirportCategory::register();
        Taxonomies\AirportTag::register();
        Taxonomies\DestinationCategory::register();
        Taxonomies\DestinationTag::register();
        Taxonomies\RestaurantCategory::register();
        Taxonomies\RestaurantTag::register();
        Taxonomies\HospitalCategory::register();
        Taxonomies\HospitalTag::register();
        Taxonomies\TourCategory::register();
        Taxonomies\TourTag::register();
        Taxonomies\VisaCategory::register();
        Taxonomies\VisaTag::register();
        Taxonomies\TravelGuideCategory::register();
        Taxonomies\TravelGuideTag::register();
        Taxonomies\TravelNewsCategory::register();
        Taxonomies\TravelNewsTag::register();
        Taxonomies\Tourism::register();
    }

    public static function register_metaboxes() {
        MetaBoxes\HotelMetaBox::register();
        MetaBoxes\GalleryMetaBox::register();
        MetaBoxes\AirportMetaBox::register();
        MetaBoxes\AirportFacilities::register();
        MetaBoxes\AirportServices::register();
        MetaBoxes\AirportAirlines::register();
        MetaBoxes\FlagMetaBox::register();
        MetaBoxes\DestinationMetaBox::register();
        MetaBoxes\RestaurantMetaBox::register();
        MetaBoxes\RestaurantFacilities::register();
        MetaBoxes\HospitalMetaBox::register();
        MetaBoxes\TourMetaBox::register();
        MetaBoxes\VisaMetaBox::register();
        MetaBoxes\TravelGuideMetaBox::register();
        MetaBoxes\TravelNewsMetaBox::register();
    }
    
    /**
     * اجرای cron غنی‌سازی لوکیشن‌ها
     */
    public static function run_enrich_locations() {
        error_log('🌍 NextSafar Enrich Locations Cron started');
    }
}