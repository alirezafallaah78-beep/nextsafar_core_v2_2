<?php

namespace NextSafar\API;

/**
 * WikipediaClient — دریافت اطلاعات از ویکی‌پدیا و Wikidata
 * پشتیبانی کامل از: infobox, Wikidata properties, multi-language, multi-value
 */
class WikipediaClient {

    private $lang;
    private $timeout = 15;

    public function __construct($lang = 'fa') {
        $this->lang = $lang;
    }

    /**
     * دریافت اطلاعات کامل مکان
     */
    public function get_place_info($name) {
        if (empty($name)) return null;

        // ۱. جستجو در ویکی‌پدیا (ابتدا فارسی، سپس انگلیسی)
        $search = $this->search($name);
        if (!$search) {
            error_log('⚠️ Wiki search failed for: ' . $name);
            return null;
        }

        // ۲. دریافت جزئیات صفحه (شامل fullurl صحیح)
        $details = $this->get_details($search['title'], $search['lang'] ?? $this->lang);

        // ۳. دریافت داده‌های ساختاری از Wikidata
        $wikidata = $this->get_wikidata_info($search['title'], $search['lang'] ?? $this->lang);

        $result = array_merge($search, $details ?? []);
        $result['wikidata'] = $wikidata;

        return $result;
    }

    /**
     * جستجوی مقاله در ویکی‌پدیا
     */
    private function search($query) {
        $url = "https://{$this->lang}.wikipedia.org/w/api.php";
        $params = [
            'action'   => 'query',
            'list'     => 'search',
            'srsearch' => $query,
            'srlimit'  => 1,
            'format'   => 'json',
        ];

        $response = wp_remote_get(add_query_arg($params, $url), [
            'timeout' => $this->timeout,
            'headers' => ['Accept' => 'application/json'],
        ]);

        if (is_wp_error($response)) {
            error_log('❌ Wiki search error: ' . $response->get_error_message());
            return null;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        $results = $data['query']['search'] ?? [];

        if (empty($results)) {
            // اگر در فارسی پیدا نشد، انگلیسی را امتحان کن
            if ($this->lang === 'fa') {
                $en_client = new self('en');
                $en_result = $en_client->search($query);
                if ($en_result) {
                    $en_result['lang'] = 'en';
                    return $en_result;
                }
            }
            return null;
        }

        return [
            'title'   => $results[0]['title'],
            'pageid'  => $results[0]['pageid'],
            'snippet' => wp_strip_all_tags($results[0]['snippet'] ?? ''),
            'lang'    => $this->lang,
        ];
    }

    /**
     * دریافت جزئیات صفحه (شامل fullurl صحیح)
     */
    private function get_details($title, $lang = 'fa') {
        $url = "https://{$lang}.wikipedia.org/w/api.php";
        $params = [
            'action'      => 'query',
            'titles'      => $title,
            'prop'        => 'extracts|info|pageimages|pageprops',
            'exintro'     => 1,
            'explaintext' => 1,
            'inprop'      => 'url',
            'pithumbsize' => 500,
            'format'      => 'json',
        ];

        $response = wp_remote_get(add_query_arg($params, $url), [
            'timeout' => $this->timeout,
        ]);

        if (is_wp_error($response)) return null;

        $data = json_decode(wp_remote_retrieve_body($response), true);
        $pages = $data['query']['pages'] ?? [];
        if (empty($pages)) return null;

        $page = reset($pages);
        if (isset($page['missing'])) return null;

        // ⭐ استفاده از fullurl که خود API می‌دهد (URL صحیح فارسی/انگلیسی)
        $fullurl = $page['fullurl'] ?? '';
        if (empty($fullurl)) {
            // Fallback: ساخت URL دستی با encoding صحیح
            $encoded_title = str_replace(' ', '_', $title);
            $fullurl = "https://{$lang}.wikipedia.org/wiki/{$encoded_title}";
        }

        return [
            'title'         => $page['title'] ?? '',
            'excerpt'       => $page['extract'] ?? '',
            'url'           => $fullurl,
            'thumbnail'     => $page['thumbnail']['source'] ?? '',
            'wikibase_item' => $page['pageprops']['wikibase_item'] ?? null,
        ];
    }

    /**
     * ⭐ دریافت داده‌های ساختاری از Wikidata
     */
    private function get_wikidata_info($wikipedia_title, $lang = 'fa') {
        // ۱. گرفتن شناسه Wikidata
        $url = "https://{$lang}.wikipedia.org/w/api.php";
        $params = [
            'action' => 'query',
            'titles' => $wikipedia_title,
            'prop'   => 'pageprops',
            'format' => 'json',
        ];

        $response = wp_remote_get(add_query_arg($params, $url), [
            'timeout' => $this->timeout,
        ]);

        if (is_wp_error($response)) return null;

        $data = json_decode(wp_remote_retrieve_body($response), true);
        $pages = $data['query']['pages'] ?? [];
        if (empty($pages)) return null;

        $page = reset($pages);
        $wikidata_id = $page['pageprops']['wikibase_item'] ?? null;

        if (!$wikidata_id) {
            error_log('⚠️ No Wikidata ID for: ' . $wikipedia_title);
            return null;
        }

        error_log('🔗 Wikidata ID: ' . $wikidata_id);

        // ۲. دریافت claims از Wikidata
        $wd_url = "https://www.wikidata.org/w/api.php";
        $wd_params = [
            'action' => 'wbgetentities',
            'ids'    => $wikidata_id,
            'props'  => 'claims',
            'format' => 'json',
        ];

        $wd_response = wp_remote_get(add_query_arg($wd_params, $wd_url), [
            'timeout' => $this->timeout,
        ]);

        if (is_wp_error($wd_response)) return null;

        $wd_data = json_decode(wp_remote_retrieve_body($wd_response), true);
        $entities = $wd_data['entities'] ?? [];
        if (empty($entities[$wikidata_id]['claims'])) {
            error_log('⚠️ No claims found for: ' . $wikidata_id);
            return null;
        }

        $claims = $entities[$wikidata_id]['claims'];

        // ۳. استخراج داده‌ها (با پشتیبانی از چند مقداری)
        $result = [
            'opened'            => $this->extract_time_claim($claims, 'P571'),
            'height'            => $this->extract_quantity_claim($claims, 'P2048'),
            'architect'         => $this->extract_multiple_entity_labels($claims, 'P84'),
            'style'             => $this->extract_multiple_entity_labels($claims, 'P149'),
            'owner'             => $this->extract_multiple_entity_labels($claims, 'P127'),
            'floors'            => $this->extract_quantity_claim($claims, 'P1101'),
            'former_names'      => $this->extract_multiple_entity_labels($claims, 'P7383'),
            'structural_system' => $this->extract_multiple_entity_labels($claims, 'P186'),
        ];

        // پاکسازی مقادیر خالی (بدون حذف "0" یا رشته‌های "false")
        $result = array_filter($result, function($v) {
            return $v !== null && $v !== '' && $v !== [];
        });

        if (!empty($result)) {
            error_log('✅ Wikidata extracted: ' . implode(', ', array_keys($result)));
        } else {
            error_log('⚠️ No Wikidata fields extracted for: ' . $wikipedia_title);
        }

        return $result;
    }

    /**
     * استخراج مقدار زمانی (سال)
     */
    private function extract_time_claim($claims, $property_id) {
        if (!isset($claims[$property_id][0])) return null;

        $mainsnak = $claims[$property_id][0]['mainsnak'] ?? [];
        if (($mainsnak['datatype'] ?? '') !== 'time') return null;

        $value = $mainsnak['datavalue']['value']['time'] ?? '';
        if (preg_match('/[+-]?(\d{4})/', $value, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * استخراج مقدار عددی (ارتفاع، طبقات)
     */
    private function extract_quantity_claim($claims, $property_id) {
        if (!isset($claims[$property_id][0])) return null;

        $mainsnak = $claims[$property_id][0]['mainsnak'] ?? [];
        if (($mainsnak['datatype'] ?? '') !== 'quantity') return null;

        $amount = ltrim($mainsnak['datavalue']['value']['amount'] ?? '', '+');
        $unit = $mainsnak['datavalue']['value']['unit'] ?? '';

        $unit_name = '';
        if (strpos($unit, 'Q11573') !== false || strpos($unit, 'Q253276') !== false) {
            $unit_name = 'متر';
        } elseif (strpos($unit, 'Q3710') !== false) {
            $unit_name = 'فوت';
        } elseif (strpos($unit, 'Q174728') !== false) {
            $unit_name = 'سانتی‌متر';
        }

        return $unit_name ? $amount . ' ' . $unit_name : $amount;
    }

    /**
     * ⭐ استخراج چند لیبل entity (برای properties چند مقداری)
     * مثال: چند معمار، چند سبک معماری، چند مالک
     */
    private function extract_multiple_entity_labels($claims, $property_id) {
        if (!isset($claims[$property_id]) || empty($claims[$property_id])) {
            return null;
        }

        $entity_ids = [];
        foreach ($claims[$property_id] as $claim) {
            $mainsnak = $claim['mainsnak'] ?? [];
            if (($mainsnak['datatype'] ?? '') !== 'wikibase-item') continue;
            $entity_id = $mainsnak['datavalue']['value']['id'] ?? '';
            if ($entity_id) $entity_ids[] = $entity_id;
        }

        if (empty($entity_ids)) return null;

        // دریافت لیبل‌ها به صورت دسته‌ای (تا ۵۰ تا در یک درخواست)
        $labels = $this->get_entities_labels_batch($entity_ids);

        return !empty($labels) ? implode('، ', $labels) : null;
    }

    /**
     * دریافت لیبل چندین entity در یک درخواست (بهینه)
     */
    private function get_entities_labels_batch(array $entity_ids) {
        if (empty($entity_ids)) return [];

        $url = "https://www.wikidata.org/w/api.php";
        $params = [
            'action'    => 'wbgetentities',
            'ids'       => implode('|', array_slice($entity_ids, 0, 50)),
            'props'     => 'labels',
            'languages' => 'fa|en',
            'format'    => 'json',
        ];

        $response = wp_remote_get(add_query_arg($params, $url), [
            'timeout' => 10,
        ]);

        if (is_wp_error($response)) {
            error_log('❌ Wikidata batch labels error: ' . $response->get_error_message());
            return [];
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        $entities = $data['entities'] ?? [];

        $labels = [];
        foreach ($entity_ids as $id) {
            if (!isset($entities[$id])) continue;

            // اولویت: فارسی → انگلیسی
            if (!empty($entities[$id]['labels']['fa']['value'])) {
                $labels[] = $entities[$id]['labels']['fa']['value'];
            } elseif (!empty($entities[$id]['labels']['en']['value'])) {
                $labels[] = $entities[$id]['labels']['en']['value'];
            }
        }

        return $labels;
    }
}