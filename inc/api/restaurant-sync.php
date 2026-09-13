<?php
namespace NextSafar\API;

// ⭐ لود خودکار trait
if (!trait_exists('\\NextSafar\\Sync\\PlaceEnrichTrait')) {
    require_once NEXTSAFAR_PATH . 'inc/sync/place-enrich-trait.php';
}

use NextSafar\Sync\PlaceEnrichTrait;

class RestaurantSync {

    use PlaceEnrichTrait;

    private $client;
    private $debug_info = [];

    private static function get_type_mapping() {
        return [
            'restaurant'       => ['restaurant', 'dining', 'eatery'],
            'traditional'      => ['traditional', 'local cuisine', 'authentic'],
            'fast_food'        => ['fast food', 'burger', 'pizza', 'sandwich', 'fast casual'],
            'cafe'             => ['cafe', 'tea house', 'coffee shop'],
            'coffee_shop'      => ['specialty coffee', 'coffee roaster'],
            'street_food'      => ['street food', 'food stall', 'food truck'],
            'bakery'           => ['bakery', 'pastry', 'bread'],
            'bar'              => ['bar', 'lounge', 'pub', 'tavern'],
            'seafood'          => ['seafood', 'fish', 'sushi'],
            'steakhouse'       => ['steakhouse', 'steak', 'grill'],
            'buffet'           => ['buffet', 'smorgasbord'],
            'fine_dining'      => ['fine dining', 'gourmet', 'michelin'],
        ];
    }

    /**
     * ⭐ Mapping امکانات رستوران از SearchAPI به متاهای وردپرس
     * این mapping بیش از 60 امکان رایج رستوران را پوشش می‌دهد
     */
    private static function get_facility_mapping() {
        return [
            // 🌐 اینترنت و ارتباطات
            'restaurant_wifi'           => ['wifi', 'free wifi', 'wireless', 'internet', 'wi-fi'],
            'restaurant_parking'        => ['parking', 'free parking', 'valet parking', 'garage'],
            'restaurant_reservation'    => ['reservations', 'reservation', 'booking', 'takes reservations'],
            
            // 🍽️ خدمات غذا
            'restaurant_delivery'       => ['delivery', 'food delivery', 'home delivery'],
            'restaurant_takeout'        => ['takeout', 'take away', 'takeaway', 'pickup'],
            'restaurant_outdoor_seating'=> ['outdoor seating', 'patio', 'terrace', 'outdoor'],
            'restaurant_live_music'     => ['live music', 'live band', 'music'],
            
            // 💳 پرداخت
            'restaurant_credit_cards'   => ['credit cards', 'accepts credit cards', 'credit card'],
            'restaurant_cash_only'      => ['cash only'],
            
            // 👨‍👩‍👧 خانواده و کودکان
            'restaurant_high_chairs'    => ['high chairs', 'highchairs', 'kids seats'],
            'restaurant_kids_menu'      => ['kids menu', 'children menu', 'kid friendly'],
            'restaurant_wheelchair'     => ['wheelchair', 'accessible', 'handicap', 'disability'],
            
            // 🍷 نوشیدنی و بار
            'restaurant_full_bar'      => ['full bar', 'bar', 'alcohol', 'liquor'],
            'restaurant_wine_list'      => ['wine list', 'wine', 'wine bar'],
            'restaurant_beer'           => ['beer', 'craft beer', 'draft beer'],
            'restaurant_cocktails'      => ['cocktails', 'cocktail'],
            
            // 🍳 سرویس غذا
            'restaurant_breakfast'      => ['breakfast', 'brunch'],
            'restaurant_lunch'          => ['lunch'],
            'restaurant_dinner'         => ['dinner'],
            'restaurant_buffet'         => ['buffet'],
            'restaurant_table_service'  => ['table service', 'waiter', 'waitstaff'],
            'restaurant_seating'        => ['seating', 'sit down'],
            
            // 🌱 رژیم غذایی
            'restaurant_vegetarian'     => ['vegetarian', 'veggie', 'vegetarian options'],
            'restaurant_vegan'          => ['vegan', 'vegan options'],
            'restaurant_gluten_free'    => ['gluten free', 'gluten-free'],
            'restaurant_halal'          => ['halal'],
            'restaurant_kosher'         => ['kosher'],
            
            // 🎉 امکانات خاص
            'restaurant_private_dining' => ['private dining', 'private room', 'event space'],
            'restaurant_tv'             => ['tv', 'television', 'sports bar'],
            'restaurant_sports'         => ['sports', 'sports bar', 'game'],
            'restaurant_dancing'        => ['dancing', 'dance floor'],
            'restaurant_smoking'        => ['smoking', 'smoking area'],
            
            // 🏖️ فضای بیرونی
            'restaurant_garden'         => ['garden', 'garden seating'],
            'restaurant_rooftop'        => ['rooftop', 'roof top', 'terrace'],
            'restaurant_waterfront'     => ['waterfront', 'sea view', 'ocean view'],
            
            // 🎵 سرگرمی
            'restaurant_live_sport'     => ['live sport', 'sports tv'],
            'restaurant_dj'             => ['dj', 'disc jockey'],
            'restaurant_karaoke'        => ['karaoke'],
            
            // 🚗 پارکینگ و دسترسی
            'restaurant_street_parking'=> ['street parking'],
            'restaurant_valet'         => ['valet', 'valet service'],
            'restaurant_validated'     => ['validated parking'],
            
            // 📱 تکنولوژی
            'restaurant_digital_menu'  => ['digital menu', 'qr menu', 'qr code'],
            'restaurant_online_order'  => ['online ordering', 'order online'],
            
            // 🐾 حیوانات
            'restaurant_pet_friendly'  => ['pet friendly', 'pets allowed', 'dog friendly'],
            
            // 💼 تجاری
            'restaurant_business_meeting' => ['business meetings', 'meeting room'],
            'restaurant_groups'        => ['groups', 'large groups', 'parties'],
        ];
    }

    public function __construct($source = null) {
        $active_source = $source ?: get_option('nextsafar_active_source', 'searchapi');
        $this->debug_info['source'] = $active_source;

        $key = get_option('nextsafar_searchapi_key', '');
        $this->client = new SearchApiClient($key);

        $this->debug_info['has_api_key'] = !empty($key);
    }

    public function get_debug_info() {
        return $this->debug_info;
    }

    public function sync_restaurants($location, $options = []) {
        error_log('🚀 Starting restaurant sync for: ' . $location);

        $restaurants = $this->client->search_restaurants($location, $options);

        if (is_wp_error($restaurants)) {
            return $restaurants;
        }

        $this->debug_info['api_response_count'] = count($restaurants);

        $results = [
            'total'   => count($restaurants),
            'created' => 0,
            'updated' => 0,
            'failed'  => 0,
            'debug'   => $this->debug_info,
            'errors'  => [],
        ];

        foreach ($restaurants as $rest_data) {
            try {
                $result = $this->save_restaurant($rest_data);

                if ($result === 'created') {
                    $results['created']++;
                } elseif ($result === 'updated') {
                    $results['updated']++;
                } else {
                    $results['failed']++;
                }
            } catch (\Exception $e) {
                $results['failed']++;
                $results['errors'][] = $rest_data['name'] . ': ' . $e->getMessage();
                error_log('❌ Failed to save ' . $rest_data['name'] . ': ' . $e->getMessage());
            }
        }

        return $results;
    }

    public function save_restaurant($data) {
        $existing = $this->find_by_external_id($data['external_id']);

        if ($existing) {
            $post_id = $existing;
            $action = 'updated';
        } else {
            $post_id = wp_insert_post([
                'post_type'    => 'restaurant',
                'post_title'   => $data['name'],
                'post_status'  => 'publish',
                'post_content' => $data['description'] ?? '',
            ]);

            if (is_wp_error($post_id)) return 'failed';
            $action = 'created';
        }

        $this->save_metaboxes($post_id, $data);

        if (!empty($data['images']) && !has_post_thumbnail($post_id)) {
            ImageManager::set_featured_image($post_id, $data['images'][0], 'api');
        }

        return $action;
    }

    private function find_by_external_id($external_id) {
        global $wpdb;

        $post_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta}
             WHERE meta_key = '_geo_external_id' AND meta_value = %s LIMIT 1",
            $external_id
        ));

        return $post_id ? intval($post_id) : null;
    }

    private function save_metaboxes($post_id, $data) {
        \NextSafar\Sync\GeoSync::apply($post_id, $data, $data['source'] ?? 'searchapi');

        // نوع رستوران
        $mapped_type = $this->map_restaurant_type($data['type'] ?? '');
        if ($mapped_type) {
            update_post_meta($post_id, '_restaurant_type', $mapped_type);
        }

        // سطح قیمت
        if (!empty($data['price_level'])) {
            $price_text = $this->convert_price_level($data['price_level']);
            if ($price_text) {
                update_post_meta($post_id, '_restaurant_average_price', $price_text);
            }
        }

        // ⭐ لینک منو (با مدیریت آرایه و آبجکت)
        $this->save_menu_link($post_id, $data['menu_link'] ?? '');

        // ⭐ ذخیره امکانات از extensions
        $this->save_facilities($post_id, $data['extensions'] ?? []);

        // استفاده از trait برای تکمیل آدرس و ساعت کاری
        $this->enrich_address_from_searchapi(
            $post_id, 
            $data['name'], 
            $data['city'] ?? '', 
            $data['country'] ?? '',
            '_restaurant_'
        );
    }

    /**
     * ⭐ ذخیره لینک منو با مدیریت آرایه و آبجکت
     */
    private function save_menu_link($post_id, $menu_data) {
        $menu_url = '';

        if (empty($menu_data)) {
            return;
        }

        // حالت ۱: رشته ساده
        if (is_string($menu_data)) {
            $menu_url = $menu_data;
        }
        // حالت ۲: آرایه
        elseif (is_array($menu_data)) {
            foreach ($menu_data as $item) {
                if (is_string($item) && !empty($item)) {
                    $menu_url = $item;
                    break;
                }
                // اگر آرایه‌ای از آبجکت‌ها بود
                if (is_array($item) && !empty($item['link'])) {
                    $menu_url = $item['link'];
                    break;
                }
                if (is_array($item) && !empty($item['url'])) {
                    $menu_url = $item['url'];
                    break;
                }
            }
        }
        // حالت ۳: آبجکت
        elseif (is_object($menu_data)) {
            $menu_url = $menu_data->link ?? $menu_data->url ?? '';
        }

        // sanitize و ذخیره
        if (!empty($menu_url)) {
            $menu_url = esc_url_raw($menu_url);
            if (!empty($menu_url)) {
                update_post_meta($post_id, '_restaurant_menu_link', $menu_url);
                error_log('✅ Menu link saved for restaurant ' . $post_id . ': ' . $menu_url);
            }
        }
    }

    /**
     * ⭐ ذخیره امکانات به صورت checkbox (تیک زدن)
     * از mapping تعریف شده استفاده می‌کند
     */
    private function save_facilities($post_id, $extensions) {
        if (empty($extensions) || !is_array($extensions)) {
            return;
        }

        $mapping = self::get_facility_mapping();
        $saved_count = 0;

        // تبدیل همه extensions به lowercase برای مقایسه آسان
        $extensions_lower = array_map('strtolower', $extensions);
        $extensions_text = implode(' ', $extensions_lower);

        foreach ($mapping as $meta_key => $keywords) {
            foreach ($keywords as $keyword) {
                $keyword_lower = strtolower($keyword);
                
                // بررسی وجود keyword در extensions
                foreach ($extensions_lower as $ext) {
                    if (strpos($ext, $keyword_lower) !== false) {
                        update_post_meta($post_id, '_' . $meta_key, 'yes');
                        $saved_count++;
                        break 2; // از حلقه بیرونی هم خارج شو
                    }
                }
            }
        }

        if ($saved_count > 0) {
            error_log("✅ Saved {$saved_count} facilities for restaurant {$post_id}");
        }
    }

    private function map_restaurant_type($google_type) {
        if (empty($google_type)) return '';

        $type_lower = strtolower($google_type);
        $mapping = self::get_type_mapping();

        foreach ($mapping as $our_type => $keywords) {
            foreach ($keywords as $keyword) {
                if (strpos($type_lower, $keyword) !== false) {
                    return $our_type;
                }
            }
        }

        return '';
    }
}