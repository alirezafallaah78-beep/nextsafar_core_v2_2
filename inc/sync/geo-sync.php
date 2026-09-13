<?php

namespace NextSafar\Sync;

use NextSafar\Database\GeoTable;

if (!defined('ABSPATH')) exit;

/**
 * GeoSync — لایه واحد اعمال داده API روی _geo_* و جدول سفارشی
 * همه sync ها (hotel/restaurant/destination/hospital/airport) از اینجا رد می‌شوند
 */
class GeoSync {
    
    const FIELD_MAP = [
        'name_en' => 'name_en',
        'address' => 'address',
        'city' => 'city',
        'country' => 'country',
        'postal' => 'postal',
        'phone' => 'phone',
        'website' => 'website',
        'rating' => 'rating',
        'reviews_count' => 'reviews',
        'stars' => 'stars',
        'check_in_time' => 'checkin',
        'check_out_time' => 'checkout',
        'price_level' => 'price_level',
        'place_id' => 'place_id',
    ];

    /**
     * اعمال داده نرمال‌شده روی پست
     */
    public static function apply($post_id, array $data, $source = 'searchapi') {
        $locked = GeoSchema::get($post_id, 'source') === 'manual';

        if (!empty($data['external_id'])) {
            GeoSchema::set($post_id, 'external_id', $data['external_id']);
        }

        if (!$locked) {
            foreach (self::FIELD_MAP as $src => $field) {
                if (isset($data[$src]) && $data[$src] !== '' && $data[$src] !== null) {
                    GeoSchema::set($post_id, $field, (string) $data[$src]);
                }
            }

            if (!empty($data['lat']) && !empty($data['lng'])) {
                GeoSchema::set_latlng($post_id, $data['lat'], $data['lng']);
            }
        }

        GeoSchema::set($post_id, 'source', $source);
        GeoSchema::set($post_id, 'last_sync', current_time('mysql'));
        
        // ⭐ ذخیره در جدول سفارشی
        self::sync_to_custom_table($post_id);
    }

    /**
     * ⭐ همگام‌سازی داده‌های مکانی به جدول سفارشی
     */
    public static function sync_to_custom_table(int $post_id): bool {
        if (!class_exists('NextSafar\Database\GeoTable')) {
            error_log('❌ GeoTable class not found');
            return false;
        }

        $data = [
            'lat' => GeoSchema::get($post_id, 'lat'),
            'lng' => GeoSchema::get($post_id, 'lng'),
            'place_id' => GeoSchema::get($post_id, 'place_id'),
            'city' => GeoSchema::get($post_id, 'city'),
            'country' => GeoSchema::get($post_id, 'country'),
            'address' => GeoSchema::get($post_id, 'address'),
            'phone' => GeoSchema::get($post_id, 'phone'),
            'website' => GeoSchema::get($post_id, 'website'),
            'external_id' => GeoSchema::get($post_id, 'external_id'),
            'source' => GeoSchema::get($post_id, 'source'),
        ];

        // فقط اگر مختصات داریم ذخیره کن
        if (empty($data['lat']) || empty($data['lng']) || $data['lat'] === '0' || $data['lng'] === '0') {
            return false;
        }

        return GeoTable::upsert($post_id, $data);
    }

    /**
     * ⭐ جستجوی مکان‌های نزدیک برای یک پست
     */
    public static function get_nearby_places(int $post_id, float $radius_km = 5, ?string $post_type = null, int $limit = 20): array {
        if (!class_exists('NextSafar\Database\GeoTable')) {
            return [];
        }

        $geo_data = GeoTable::get_by_post_id($post_id);

        if (!$geo_data || empty($geo_data['lat']) || empty($geo_data['lng'])) {
            return [];
        }

        return GeoTable::find_nearby(
            (float) $geo_data['lat'],
            (float) $geo_data['lng'],
            $radius_km,
            $post_type,
            $limit
        );
    }
}