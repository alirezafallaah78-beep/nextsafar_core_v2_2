<?php
/**
 * RelevanceFilter — نسخه ۲.۰ (سازگاری)
 * ✅ نکته ۲: دیگر لیست کلمات جداگانه در آپشن‌ها ندارد؛ همه چیز از NewsFilter (منبع واحد)
 */

namespace NextSafar\API;

if (!defined('ABSPATH')) exit;

class RelevanceFilter {

    public function __construct($ai_client = null) {}

    public function is_relevant(array $item): array {
        if (get_option('nextsafar_news_relevance_filter') !== '1') {
            return ['relevant' => true, 'method' => 'disabled'];
        }
        $r = (new NewsFilter())->analyze_item($item);
        return [
            'relevant' => ($r['decision'] !== 'delete'),
            'method'   => 'newsfilter',
            'score'    => $r['score'],
            'reason'   => $r['reason'],
            'matched'  => $r['positive_matches'],
        ];
    }
}