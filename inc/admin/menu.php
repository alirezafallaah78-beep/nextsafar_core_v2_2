<?php
/**
 * مدیریت منوهای ادمین NextSafar
 * نسخه ۲.۰ - رفع باگ تکرار منو
 */

namespace NextSafar\Admin;

if (!defined('ABSPATH')) exit;

class Menu {

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'register_menus']);
    }

    public static function register_menus() {
        // === منوی اصلی ===
        add_menu_page(
            __('NextSafar', 'nextsafar'),
            __('NextSafar', 'nextsafar'),
            'manage_options',
            'nextsafar-settings',
            [Settings::class, 'render_settings_page'],
            'dashicons-globe',
            30
        );

        // === زیرمنو: تنظیمات (صفحه اصلی) ===
        add_submenu_page(
            'nextsafar-settings',
            __('تنظیمات API', 'nextsafar'),
            __('تنظیمات', 'nextsafar'),
            'manage_options',
            'nextsafar-settings',
            [Settings::class, 'render_settings_page']
        );

        // === زیرمنو: همگام‌سازی ===
        add_submenu_page(
            'nextsafar-settings',
            __('همگام‌سازی از API', 'nextsafar'),
            __('همگام‌سازی', 'nextsafar'),
            'manage_options',
            'nextsafar-sync',
            [SyncPage::class, 'render_sync_page']
        );

        // === زیرمنو: فیلتر اخبار ===
        add_submenu_page(
            'nextsafar-settings',
            __('فیلتر اخبار گردشگری', 'nextsafar'),
            __('فیلتر اخبار', 'nextsafar'),
            'manage_options',
            'nextsafar-news-filter',
            [NewsFilterSettings::class, 'render_page']
        );

        // === زیرمنو: نرخ ارز ===
        add_submenu_page(
            'nextsafar-settings',
            __('نرخ ارز', 'nextsafar'),
            __('نرخ ارز', 'nextsafar'),
            'manage_options',
            'nextsafar-exchange',
            [Exchange::class, 'render_page']
        );
    }
}