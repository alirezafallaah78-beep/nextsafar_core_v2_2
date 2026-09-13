<?php
/**
 * مدیریت تصاویر: سایزبندی فقط کار Next.js است
 * وردپرس فقط نسخه full (با سقف ۲۵۶۰px خودش) را نگه می‌دارد
 */

if (!defined('ABSPATH')) exit;

/* ❌ حذف همه سایزهای میانه برای آپلودهای جدید */
add_filter('intermediate_image_sizes_advanced', '__return_empty_array');

/* ✅ سقف ۲۵۶۰ پیکسل برای تصاویر غول‌پیکر (پیش‌فرض WP، صریح نگه می‌داریم) */
add_filter('big_image_size_threshold', function () {
    return 2560;
});

/* ✅ کیفیت نسخه full کمی بالاتر (پیش‌فوردپرس ۸۲ است) */
add_filter('wp_editor_set_quality', function () {
    return 90;
});