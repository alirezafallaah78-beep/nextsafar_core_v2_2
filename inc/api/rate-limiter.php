<?php
/**
 * NextSafar Rate Limiter
 * کنترل نرخ درخواست‌های API برای جلوگیری از block شدن
 * 
 * @version 1.0.0
 */

namespace NextSafar\API;

if (!defined('ABSPATH')) exit;

class RateLimiter {
    
    /**
     * @var array<string, float> آخرین زمان درخواست هر سرویس
     */
    private static $last_request = [];
    
    /**
     * @var array<string, int> شمارنده درخواست‌های متوالی
     */
    private static $request_count = [];
    
    /**
     * @var array<string, int> حداکثر درخواست‌های مجاز قبل از تاخیر
     */
    private const MAX_BURST = [
        'rss' => 10,
        'gnews' => 5,
        'newsdata' => 5,
        'currents' => 5,
        'mediastack' => 5,
        'searchapi' => 3,
        'serpapi' => 3,
    ];
    
    /**
     * تاخیر در صورت نیاز
     * 
     * @param string $service نام سرویس (rss, gnews, ...)
     * @param float $seconds حداقل فاصله بین درخواست‌ها
     */
    public static function wait_if_needed(string $service, float $seconds = 1.0): void {
        $now = microtime(true);
        $last = self::$last_request[$service] ?? 0;
        $count = self::$request_count[$service] ?? 0;
        $elapsed = $now - $last;
        
        // افزایش شمارنده
        self::$request_count[$service] = $count + 1;
        
        // اگر به سقف burst رسیدیم، استراحت بیشتری بدهیم
        $max_burst = self::MAX_BURST[$service] ?? 5;
        if (self::$request_count[$service] >= $max_burst) {
            $seconds = max($seconds, 3.0); // حداقل 3 ثانیه استراحت
            self::$request_count[$service] = 0;
        }
        
        // اگر زمان کافی نگذشته، صبر کن
        if ($elapsed < $seconds) {
            $sleep_microseconds = ($seconds - $elapsed) * 1000000;
            usleep((int) $sleep_microseconds);
        }
        
        self::$last_request[$service] = microtime(true);
    }
    
    /**
     * ریست کردن وضعیت یک سرویس
     */
    public static function reset(string $service): void {
        unset(self::$last_request[$service]);
        unset(self::$request_count[$service]);
    }
    
    /**
     * ریست کامل همه سرویس‌ها
     */
    public static function reset_all(): void {
        self::$last_request = [];
        self::$request_count = [];
    }
    
    /**
     * دریافت وضعیت فعلی (برای دیباگ)
     */
    public static function get_status(): array {
        return [
            'last_request' => self::$last_request,
            'request_count' => self::$request_count,
        ];
    }
}