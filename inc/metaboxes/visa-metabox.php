<?php
namespace NextSafar\MetaBoxes;

class VisaMetaBox {
    
    public static function register() {
        add_meta_box(
            'nextsafar_visa_banner',
            '🏞️ عکس بنر ویزا',
            [__CLASS__, 'render_banner'],
            'visa',
            'side',
            'default'
        );

        add_meta_box(
            'nextsafar_visa_prices',
            '💰 قیمت‌های مختلف ویزا',
            [__CLASS__, 'render_prices'],
            'visa',
            'normal',
            'high'
        );

        add_meta_box(
            'nextsafar_visa_info',
            '📋 اطلاعات ویزا',
            [__CLASS__, 'render_info'],
            'visa',
            'normal',
            'default'
        );

        add_meta_box(
            'nextsafar_visa_documents',
            '📄 مدارک مورد نیاز ویزا',
            [__CLASS__, 'render_documents'],
            'visa',
            'normal',
            'default'
        );

        add_meta_box(
            'nextsafar_visa_description',
            '📝 توضیحات ویزا',
            [__CLASS__, 'render_description'],
            'visa',
            'normal',
            'default'
        );
    }

    public static function register_hooks() {
        add_action('save_post_visa', [__CLASS__, 'save'], 10, 2);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
    }

    public static function enqueue_assets($hook) {
        global $post_type;
        
        if (($hook !== 'post.php' && $hook !== 'post-new.php') || $post_type !== 'visa') {
            return;
        }
        
        wp_enqueue_media();
        
        wp_enqueue_style(
            'nextsafar-visa-admin',
            NEXTSAFAR_URL . 'assets/visa.css',
            [],
            NEXTSAFAR_VERSION
        );
        
        // ⭐ لیست ارزهای صرافی را به جاوااسکریپت ارسال کن
        $currencies = \NextSafar\Admin\Exchange::get_supported_currencies();
        
        wp_enqueue_script(
            'nextsafar-visa-admin',
            NEXTSAFAR_URL . 'assets/visa.js',
            ['jquery'],
            NEXTSAFAR_VERSION,
            true
        );
        
        wp_localize_script('nextsafar-visa-admin', 'NextSafarCurrencies', $currencies);
    }

    // ========================================
    // ⭐ عکس بنر ویزا (در خود پست)
    // ========================================
    public static function render_banner($post) {
        wp_nonce_field('nextsafar_visa_meta', 'visa_meta_nonce');
        $banner = get_post_meta($post->ID, '_visa_banner', true);
        ?>
        <div class="ns-visa-banner-field">
            <input type="hidden" name="visa_banner" id="ns-visa-banner-input" value="<?= esc_attr($banner); ?>">
            
            <div class="ns-visa-banner-preview" id="ns-visa-banner-preview">
                <?php if ($banner) : ?>
                    <img src="<?= esc_url($banner); ?>" style="max-width:100%; height:auto; border-radius:6px;">
                <?php else : ?>
                    <div class="ns-visa-banner-placeholder">🏞️ بنری انتخاب نشده</div>
                <?php endif; ?>
            </div>
            
            <div class="ns-visa-banner-buttons" style="margin-top:10px;">
                <button type="button" class="button button-primary ns-visa-banner-upload">📁 انتخاب بنر</button>
                <button type="button" class="button ns-visa-banner-remove" <?= $banner ? '' : 'style="display:none;"'; ?>>✕ حذف</button>
            </div>
        </div>
        <?php
    }

    // ========================================
    // قیمت‌های ویزا (با سلکت واحد پول از صرافی)
    // ========================================
    public static function render_prices($post) {
        wp_nonce_field('nextsafar_visa_meta', 'visa_meta_nonce');
        $visa_prices = get_post_meta($post->ID, '_visa_prices', true);
        if (!is_array($visa_prices)) $visa_prices = [];
        
        // ⭐ لیست ارزهای صرافی
        $currencies = \NextSafar\Admin\Exchange::get_supported_currencies();
        ?>
        <div class="nextsafar-visa-metabox">
            <div class="ns-visa-prices-wrapper" id="visa-prices-wrapper">
                <?php foreach ($visa_prices as $index => $price_item) : ?>
                    <div class="ns-visa-price-group">
                        <input type="text" name="visa_prices[<?= $index; ?>][type]" 
                               placeholder="نوع (سینگل/مولتی)" 
                               value="<?= esc_attr($price_item['type'] ?? ''); ?>">
                        <input type="text" name="visa_prices[<?= $index; ?>][duration]" 
                               placeholder="مدت (مثلاً ۱۰ روز)" 
                               value="<?= esc_attr($price_item['duration'] ?? ''); ?>">
                        <input type="text" name="visa_prices[<?= $index; ?>][person]" 
                               placeholder="شخص (بزرگسال/کودک)" 
                               value="<?= esc_attr($price_item['person'] ?? ''); ?>">
                        <input type="text" name="visa_prices[<?= $index; ?>][price]" 
                               placeholder="قیمت" 
                               value="<?= esc_attr($price_item['price'] ?? ''); ?>">
                        
                        <!-- ⭐ واحد پول از صرافی -->
                        <select name="visa_prices[<?= $index; ?>][currency]">
                            <option value="">-- واحد پول --</option>
                            <?php foreach ($currencies as $code => $name) : ?>
                                <option value="<?= esc_attr($code); ?>" 
                                        <?= selected($price_item['currency'] ?? '', $code); ?>>
                                    <?= esc_html($name); ?> (<?= esc_html($code); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <button type="button" class="ns-remove-visa-price">✕</button>
                    </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="ns-btn-add" id="add-visa-price">➕ افزودن قیمت جدید</button>
        </div>
        <?php
    }

    public static function render_info($post) {
        $visa_issue = get_post_meta($post->ID, '_visa_issue', true);
        $visa_expiry = get_post_meta($post->ID, '_visa_expiry', true);
        ?>
        <div class="nextsafar-visa-metabox">
            <div class="ns-visa-info-grid">
                <div class="ns-field">
                    <label>⏱️ زمان اخذ ویزا:</label>
                    <input type="text" name="visa_issue" value="<?= esc_attr($visa_issue); ?>" 
                           class="ns-input" placeholder="مثلاً ۱۰ روز کاری">
                </div>
                <div class="ns-field">
                    <label>📅 اعتبار ویزا پس از صدور:</label>
                    <input type="text" name="visa_expiry" value="<?= esc_attr($visa_expiry); ?>" 
                           class="ns-input" placeholder="مثلاً ۹۰ روز">
                </div>
            </div>
        </div>
        <?php
    }

    public static function render_documents($post) {
        $fields = self::get_visa_doc_fields();
        $saved_data = get_post_meta($post->ID, '_visa_docs', true);
        if (!is_array($saved_data)) $saved_data = [];
        ?>
        <div class="nextsafar-visa-metabox">
            <div class="ns-visa-docs-grid">
                <?php foreach ($fields as $field) :
                    $checked = (isset($saved_data[$field]['checked']) && $saved_data[$field]['checked'] === true) ? 'checked' : '';
                    $text = $saved_data[$field]['text'] ?? '';
                ?>
                    <div class="ns-visa-doc-item">
                        <label class="ns-visa-doc-label">
                            <input type="checkbox" name="visa_docs[<?= esc_attr($field); ?>][checked]" 
                                   value="1" <?= $checked; ?>>
                            <span><?= esc_html($field); ?></span>
                        </label>
                        <input type="text" name="visa_docs[<?= esc_attr($field); ?>][text]" 
                               value="<?= esc_attr($text); ?>" 
                               placeholder="توضیحات..." 
                               class="ns-visa-doc-text">
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    public static function render_description($post) {
        $content = get_post_meta($post->ID, '_visa_description', true);
        wp_editor($content, 'visa_description', [
            'textarea_name' => 'visa_description',
            'textarea_rows' => 10,
            'media_buttons' => true,
            'tinymce'       => true,
            'quicktags'     => true,
        ]);
    }

    public static function save($post_id, $post) {
        if (!isset($_POST['visa_meta_nonce']) || !wp_verify_nonce($_POST['visa_meta_nonce'], 'nextsafar_visa_meta')) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;

        // ۱. ⭐ عکس بنر
        if (isset($_POST['visa_banner'])) {
            if (!empty($_POST['visa_banner'])) {
                update_post_meta($post_id, '_visa_banner', esc_url_raw($_POST['visa_banner']));
            } else {
                delete_post_meta($post_id, '_visa_banner');
            }
        }

        // ۲. قیمت‌ها
        if (isset($_POST['visa_prices']) && is_array($_POST['visa_prices'])) {
            $prices = array_map(function($item) {
                return [
                    'type'         => sanitize_text_field($item['type'] ?? ''),
                    'duration'     => sanitize_text_field($item['duration'] ?? ''),
                    'person'       => sanitize_text_field($item['person'] ?? ''),
                    'price'        => sanitize_text_field($item['price'] ?? ''),
                    'currency'     => sanitize_text_field($item['currency'] ?? ''),
                ];
            }, $_POST['visa_prices']);
            update_post_meta($post_id, '_visa_prices', $prices);
        } else {
            delete_post_meta($post_id, '_visa_prices');
        }

        // ۳. اطلاعات ویزا
        if (isset($_POST['visa_issue'])) {
            update_post_meta($post_id, '_visa_issue', sanitize_text_field($_POST['visa_issue']));
        }
        if (isset($_POST['visa_expiry'])) {
            update_post_meta($post_id, '_visa_expiry', sanitize_text_field($_POST['visa_expiry']));
        }

        // ۴. توضیحات ویزا
        if (isset($_POST['visa_description'])) {
            update_post_meta($post_id, '_visa_description', wp_kses_post($_POST['visa_description']));
        }

        // ۵. مدارک ویزا
        $fields = self::get_visa_doc_fields();
        if (isset($_POST['visa_docs'])) {
            $data = [];
            foreach ($fields as $field) {
                $checked = isset($_POST['visa_docs'][$field]['checked']);
                $text = isset($_POST['visa_docs'][$field]['text']) ? sanitize_text_field($_POST['visa_docs'][$field]['text']) : '';
                $data[$field] = ['checked' => $checked, 'text' => $text];
            }
            update_post_meta($post_id, '_visa_docs', $data);
        } else {
            update_post_meta($post_id, '_visa_docs', []);
        }
    }

    public static function get_visa_doc_fields() {
        return [
            'پاسپورت', 'عکس پرسنلی', 'شناسنامه', 'کارت ملی', 'بلیط پرواز',
            'ووچر هتل', 'بیمه مسافرتی', 'فرم اطلاعات', 'تمکن مالی', 'برنامه سفر',
            'آدرس میزبان', 'نامه اشتغال به کار', 'اصل و ترجمه سند ملکی', 'انگشت نگاری',
            'ضمانت بازگشت', 'پاسپورت قدیمی', 'گواهی اشتغال به تحصیل', 'دعوت نامه',
            'کارت پایان خدمت', 'واکسن', 'مدارک همسر (خانم)', 'رضایت‌نامه محضری',
        ];
    }
}