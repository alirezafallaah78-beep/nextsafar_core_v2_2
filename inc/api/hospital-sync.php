<?php
/**
 * HospitalSync — بازنویسی با استفاده از BaseSync
 * حذف کدهای تکراری
 */

namespace NextSafar\API;

if (!defined('ABSPATH')) exit;

// ⭐ لود کلاس پایه
require_once NEXTSAFAR_PATH . 'inc/api/base-sync.php';

class HospitalSync extends BaseSync {

    protected $post_type = 'hospital';

    /**
     * نام متد جستجو در کلاینت
     */
    protected function get_search_method(): string {
        return 'search_hospitals';
    }

    /**
     * سینک بیمارستان‌ها
     */
    public function sync(string $location, array $options = []): array {
        return $this->run_sync($location, $options);
    }

    /**
     * سازگاری با کد قدیمی
     */
    public function sync_hospitals($location, $options = []) {
        return $this->sync($location, $options);
    }

    /**
     * ذخیره یک بیمارستان
     */
    public function save(array $data): string {
        $result = $this->save_post($data);

        if ($result['action'] === 'failed') {
            return 'failed';
        }

        $post_id = $result['post_id'];

        $this->save_metaboxes($post_id, $data);
        $this->save_featured_image($post_id, $data);

        return $result['action'];
    }

    /**
     * سازگاری با کد قدیمی
     */
    public function save_hospital($data) {
        return $this->save($data);
    }

    /**
     * ذخیره متاباکس‌های اختصاصی بیمارستان
     */
    private function save_metaboxes(int $post_id, array $data): void {
        // ✅ استفاده از متد مشترک
        $this->save_geo_meta($post_id, $data);

        // ذخیره نوع بیمارستان
        if (!empty($data['type'])) {
            update_post_meta($post_id, '_hospital_type', sanitize_text_field($data['type']));
        }

        // تشخیص اورژانس
        $is_emergency = $this->detect_emergency($data);
        update_post_meta($post_id, '_hospital_emergency', $is_emergency ? 'yes' : 'no');

        // ⭐ استفاده از trait برای تکمیل آدرس و ساعت کاری
        $this->enrich_address_from_searchapi(
            $post_id,
            $data['name'],
            $data['city'] ?? '',
            $data['country'] ?? '',
            '_hospital_'
        );

        // بررسی مجدد اورژانس بر اساس ساعات کاری
        $work_time = get_post_meta($post_id, '_hospital_work_time', true);
        if (is_array($work_time) && $this->is_open_24_7($work_time)) {
            update_post_meta($post_id, '_hospital_emergency', 'yes');
        }
    }

    /**
     * تشخیص اورژانس بر اساس نام و نوع
     */
    private function detect_emergency(array $data): bool {
        $name = strtolower($data['name'] ?? '');
        $type = strtolower($data['type'] ?? '');

        $emergency_keywords = [
            'emergency', '24 hour', '24/7', 'trauma',
            'اورژانس', 'شبانه‌روزی',
        ];

        foreach ($emergency_keywords as $keyword) {
            if (strpos($name, $keyword) !== false || strpos($type, $keyword) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * بررسی 24/7 بودن
     */
    private function is_open_24_7($work_time): bool {
        if (empty($work_time) || !is_array($work_time)) return false;

        foreach ($work_time as $day => $slots) {
            if (!is_array($slots)) continue;

            foreach ($slots as $slot) {
                if (!is_array($slot)) continue;

                $from = $slot['from'] ?? '';
                $to = $slot['to'] ?? '';

                if ($from === '24h' || $to === '24h') {
                    return true;
                }

                if (stripos($from . $to, '24') !== false) {
                    return true;
                }
            }
        }

        return false;
    }
}