<?php
/**
 * NextSafar Image Manager — نسخه ۳.۱
 * ✅ نکته ۴: ensure_featured_image() تضمین می‌کند پست بدون تصویر شاخص نماند
 */

namespace NextSafar\API;

if (!defined('ABSPATH')) exit;

class ImageManager {

    const MAX_FILE_SIZE      = 5242880;
    const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'];
    const DOWNLOAD_TIMEOUT   = 30;

    public static function set_featured_image(int $post_id, string $image_url = '', string $source = 'api'): bool {
        if (empty($image_url)) return false;

        $image_url = self::normalize_url($image_url);
        if (!self::is_valid_image_url($image_url)) return false;

        if (has_post_thumbnail($post_id)) return true;

        try {
            $attachment_id = self::download_and_attach($image_url, $post_id);
            if (is_wp_error($attachment_id)) {
                error_log("⚠️ ImageManager: Download failed for post #{$post_id}: " . $attachment_id->get_error_message());
                return false;
            }
            if ($attachment_id > 0) {
                set_post_thumbnail($post_id, $attachment_id);
                update_post_meta($post_id, '_ns_featured_image_source', sanitize_text_field($source));
                update_post_meta($post_id, '_ns_featured_image_url', esc_url_raw($image_url));
                error_log("🖼️ ImageManager: Featured image set for post #{$post_id} (attachment #{$attachment_id})");
                return true;
            }
            return false;
        } catch (\Throwable $e) {
            error_log("❌ ImageManager: Error for post #{$post_id}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * ✅ تضمین تصویر شاخص: زنجیره fallback
     * ۱) تصویر آیتم  ۲) اولین تصویر محتوا  ۳) og:image صفحه منبع  ۴) placeholder لوکال
     */
    public static function ensure_featured_image(int $post_id, string $preferred = '', string $source_link = ''): bool {
        if (has_post_thumbnail($post_id)) return true;

        $candidates = [];
        if (!empty($preferred)) $candidates[] = $preferred;

        $in_content = self::extract_first_image(get_post_field('post_content', $post_id) ?: '');
        if ($in_content) $candidates[] = $in_content;

        if (!empty($source_link)) {
            $og = self::fetch_og_image($source_link);
            if ($og) $candidates[] = $og;
        }
        $ph = self::placeholder_url();
        if ($ph) $candidates[] = $ph;

        foreach (array_unique(array_filter($candidates)) as $url) {
            if (self::set_featured_image($post_id, $url, 'fallback')) return true;
        }
        error_log("⚠️ ImageManager: no image available for post #{$post_id}");
        return false;
    }

    /** ✅ رگکس اصلاح‌شده با گروه captura */
    public static function extract_first_image(string $content): ?string {
        if (preg_match('/<img[^>]+src=[\'"]([^\'"]+)[\'"]/i', $content, $m)) return $m[1];
        if (preg_match('/<figure[^>]+data-url=[\'"]([^\'"]+)[\'"]/i', $content, $m)) return $m[1];
        return null;
    }

    private static function fetch_og_image(string $url): ?string {
        $key = 'ns_og_' . md5($url);
        $cached = get_transient($key);
        if ($cached !== false) return $cached ?: null;

        $res = wp_remote_get($url, ['timeout' => 10, 'redirection' => 3, 'sslverify' => false]);
        if (is_wp_error($res)) { set_transient($key, '', HOUR_IN_SECONDS); return null; }

        $html = wp_remote_retrieve_body($res);
        $img  = null;
        if (preg_match('/<meta[^>]+property=[\'"]og:image[\'"][^>]+content=[\'"]([^\'"]+)[\'"]/i', $html, $m)) $img = $m[1];
        elseif (preg_match('/<meta[^>]+content=[\'"]([^\'"]+)[\'"][^>]+property=[\'"]og:image[\'"]/i', $html, $m)) $img = $m[1];
        set_transient($key, $img ?: '', DAY_IN_SECONDS);
        return $img;
    }

    private static function placeholder_url(): string {
        $file = plugin_dir_path(__FILE__) . 'assets/placeholder-news.jpg';
        return file_exists($file) ? plugin_dir_url(__FILE__) . 'assets/placeholder-news.jpg' : '';
    }

    /* ═══════════════ بدون تغییر ═══════════════ */

    private static function normalize_url(string $url): string {
        if (empty($url)) return '';
        $url = trim($url);
        if (strpos($url, '//') === 0) $url = 'https:' . $url;
        elseif (strpos($url, '/') === 0) $url = site_url($url);
        elseif (!preg_match('/^https?:\/\//i', $url)) $url = site_url('/' . $url);

        if (preg_match('/[^\x20-\x7f]/', $url)) {
            $parts = parse_url($url);
            if ($parts === false || empty($parts['host'])) return $url;
            $scheme = $parts['scheme'] ?? 'https';
            $host   = $parts['host'];
            $port   = isset($parts['port']) ? ':' . $parts['port'] : '';
            $path   = '';
            if (isset($parts['path']) && $parts['path'] !== '') {
                $segs = array_map(function ($s) {
                    return preg_match('/%[0-9A-Fa-f]{2}/', $s) ? $s : rawurlencode($s);
                }, explode('/', $parts['path']));
                $path = implode('/', $segs);
            }
            $query = isset($parts['query']) ? '?' . $parts['query'] : '';
            $url = "{$scheme}://{$host}{$port}{$path}{$query}";
        }
        return $url;
    }

    private static function is_valid_image_url(string $url): bool {
        if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) return false;
        $scheme = strtolower(parse_url($url, PHP_URL_SCHEME) ?? '');
        if (!in_array($scheme, ['http', 'https'], true)) return false;
        $path = parse_url($url, PHP_URL_PATH);
        if (!empty($path)) {
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if (!empty($ext) && !in_array($ext, self::ALLOWED_EXTENSIONS, true)) return false;
        }
        return true;
    }

    private static function download_and_attach(string $url, int $post_id) {
        if (!function_exists('media_handle_sideload')) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }
        $tmp = download_url($url, self::DOWNLOAD_TIMEOUT);
        if (is_wp_error($tmp)) return $tmp;

        $size = @filesize($tmp);
        if ($size === false || $size > self::MAX_FILE_SIZE) { @unlink($tmp); return new \WP_Error('image_too_large', 'image > 5MB'); }

        $info = @getimagesize($tmp);
        if ($info === false) {
            if (self::get_file_mime($tmp) !== 'image/svg+xml') { @unlink($tmp); return new \WP_Error('not_an_image', 'not an image'); }
        } elseif ($info[0] < 100 || $info[1] < 100) { @unlink($tmp); return new \WP_Error('image_too_small', '< 100px'); }

        $filename = self::extract_filename($url) ?: ('ns_image_' . $post_id . '_' . time() . '.jpg');
        $file_array = ['name' => sanitize_file_name($filename), 'tmp_name' => $tmp, 'size' => $size];
        $att = media_handle_sideload($file_array, $post_id);
        if (is_wp_error($att)) @unlink($tmp);
        return $att;
    }

    private static function extract_filename(string $url): string {
        $path = parse_url($url, PHP_URL_PATH);
        if (empty($path)) return '';
        $filename = sanitize_file_name(urldecode(basename($path)));
        if (!pathinfo($filename, PATHINFO_EXTENSION)) $filename .= '.jpg';
        return $filename;
    }

    private static function get_file_mime(string $file): string {
        $info = @getimagesize($file);
        if ($info !== false && isset($info['mime'])) return $info['mime'];
        $ft = wp_check_filetype($file);
        if (!empty($ft['type'])) return $ft['type'];
        $h = @file_get_contents($file, false, null, 0, 12);
        if ($h === false) return 'application/octet-stream';
        if (str_starts_with($h, "\xFF\xD8")) return 'image/jpeg';
        if (str_starts_with($h, "\x89PNG")) return 'image/png';
        if (str_starts_with($h, 'GIF8')) return 'image/gif';
        if (str_starts_with($h, 'RIFF') && strpos($h, 'WEBP') !== false) return 'image/webp';
        return 'application/octet-stream';
    }

    public static function strip_images_from_content(string $content): string {
        $content = preg_replace('/<img[^>]*>/i', '', $content);
        $content = preg_replace('/<figure[^>]*>.*?<\/figure>/is', '', $content);
        $content = preg_replace('/<p>\s*<\/p>/i', '', $content);
        return trim($content);
    }

    public static function get_featured_image_data(int $post_id): array {
        $data = ['has_thumbnail' => false, 'id' => 0, 'url' => '', 'width' => 0, 'height' => 0, 'alt' => '',
                 'source' => get_post_meta($post_id, '_ns_featured_image_source', true) ?: ''];
        $tid = get_post_thumbnail_id($post_id);
        if ($tid > 0) {
            $data['has_thumbnail'] = true; $data['id'] = $tid;
            $img = wp_get_attachment_image_src($tid, 'full');
            if ($img) { $data['url'] = $img[0]; $data['width'] = $img[1]; $data['height'] = $img[2]; }
            $data['alt'] = get_post_meta($tid, '_wp_attachment_image_alt', true);
        }
        return $data;
    }
}