<?php
namespace NextSafar\API;

class MenuEndpoint {
    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
    }

    public static function register_routes() {
        register_rest_route('nextsafar/v1', '/menus/(?P<location>[a-zA-Z0-9_-]+)', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'get_menu'],
            'permission_callback' => '__return_true',
            'args'                => [
                'location' => [
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);
    }

    public static function get_menu($request) {
        $location = $request->get_param('location');

        // بررسی وجود location
        $locations = get_nav_menu_locations();
        if (!isset($locations[$location])) {
            return new \WP_Error(
                'menu_not_found',
                "Menu location '{$location}' not found",
                ['status' => 404]
            );
        }

        $menu_id   = $locations[$location];
        $menu_items = wp_get_nav_menu_items($menu_id);

        if (!$menu_items) {
            return [];
        }

        // ساخت درخت سلسله‌مراتبی
        $items = self::build_tree($menu_items);

        return [
            'location' => $location,
            'items'    => $items,
        ];
    }

    private static function build_tree($items, $parent_id = 0) {
        $branch = [];

        foreach ($items as $item) {
            if ((int) $item->menu_item_parent === $parent_id) {
                $children = self::build_tree($items, $item->ID);
                
                $branch[] = [
                    'id'       => (int) $item->ID,
                    'title'    => $item->title,
                    'url'      => $item->url,
                    'target'   => $item->target ?: '_self',
                    'classes'  => implode(' ', $item->classes),
                    'object'   => $item->object,
                    'object_id'=> (int) $item->object_id,
                    'children' => $children,
                ];
            }
        }

        return $branch;
    }
}