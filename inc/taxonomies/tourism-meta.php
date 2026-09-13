<?php
namespace NextSafar\Taxonomies;

class TourismMeta {
    
    public static function init() {
        add_action('tourism_add_form_fields', [__CLASS__, 'add_form_fields']);
        add_action('tourism_edit_form_fields', [__CLASS__, 'edit_form_fields']);
        add_action('created_tourism', [__CLASS__, 'save']);
        add_action('edited_tourism', [__CLASS__, 'save']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);

        // REST API
        add_action('rest_api_init', [__CLASS__, 'register_rest_fields']);

        /* ⭐ جدید: ستون‌های تصویر در جدول ادمین (قبلاً هوک نشده بودند) */
        add_filter('manage_edit-tourism_columns', [__CLASS__, 'add_columns']);
        add_filter('manage_tourism_custom_column', [__CLASS__, 'render_columns'], 10, 3);
    }
    
    public static function enqueue_assets($hook) {
        $screen = get_current_screen();
        if (!$screen || $screen->taxonomy !== 'tourism') return;
        
        wp_enqueue_media();
        
        wp_enqueue_style(
            'nextsafar-tourism-admin',
            NEXTSAFAR_URL . 'assets/tourism.css',
            [],
            NEXTSAFAR_VERSION
        );
        
        wp_enqueue_script(
            'nextsafar-tourism-admin',
            NEXTSAFAR_URL . 'assets/tourism.js',
            ['jquery'],
            NEXTSAFAR_VERSION,
            true
        );
        
        // Localize برای AJAX
        wp_localize_script('nextsafar-tourism-admin', 'NextSafarTourism', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('nextsafar_tourism_ajax')
        ]);
    }
    
    // ========== فیلدهای افزودن ==========
    public static function add_form_fields() {
        wp_nonce_field('nextsafar_tourism_meta', 'tourism_meta_nonce');
        
        echo '<h3>تصاویر شهر توریستی</h3>';
        echo '<div class="ns-tourism-images-grid">';
        
        self::render_image_field('tourism_image', 'تصویر شاخص', '');
        self::render_image_field('tourism_banner', 'تصویر بنر', '');
        self::render_image_field('tourism_flag', 'پرچم کشور', '');
        
        echo '</div>';
    }
    
    // ========== فیلدهای ویرایش ==========
    public static function edit_form_fields($term) {
        wp_nonce_field('nextsafar_tourism_meta', 'tourism_meta_nonce');
        
        $images = ['tourism_image', 'tourism_banner', 'tourism_flag'];
        $data = [];
        
        foreach ($images as $img) {
            $data[$img] = get_term_meta($term->term_id, '_' . $img, true);
        }
        
        echo '<tr><th colspan="2"><h3>تصاویر شهر توریستی</h3></th></tr>';
        echo '<tr><td colspan="2"><div class="ns-tourism-images-grid">';
        
        foreach ($images as $img) {
            $label = $img === 'tourism_image' ? 'تصویر شاخص' : ($img === 'tourism_banner' ? 'تصویر بنر' : 'پرچم کشور');
            self::render_image_field($img, $label, $data[$img] ?? '');
        }
        
        echo '</div></td></tr>';
    }
    
    // ========== رندر فیلد تصویر ==========
    private static function render_image_field($name, $label, $value) {
        $url = '';
        $image_id = 0;
        
        // اگر ID بود، تبدیل به URL
        if (is_numeric($value) && $value > 0) {
            $image_id = intval($value);
            $url = wp_get_attachment_image_url($image_id, 'medium');
        } elseif (!empty($value) && filter_var($value, FILTER_VALIDATE_URL)) {
            // اگر URL بود
            $url = esc_url($value);
        }
        
        ?>
        <div class="ns-tourism-image-field" data-field-name="<?php echo esc_attr($name); ?>">
            <label><?php echo esc_html($label); ?></label>
            
            <div class="ns-image-controls">
                <button type="button" class="button ns-tourism-upload" data-target="<?php echo esc_attr($name); ?>"
                    <?php echo $url ? 'style="display:none;"' : ''; ?>>
                    📁 انتخاب تصویر
                </button>
                <button type="button" class="button ns-tourism-remove" data-target="<?php echo esc_attr($name); ?>"
                    <?php echo $url ? '' : 'style="display:none;"'; ?>>
                    ✕
                </button>
            </div>
            
            <input type="hidden" 
                   name="<?php echo esc_attr($name); ?>" 
                   id="<?php echo esc_attr($name); ?>" 
                   value="<?php echo esc_attr($image_id); ?>"
                   class="ns-image-input">
            
            <div class="ns-tourism-preview" style="margin-top:10px;">
                <?php if ($url): ?>
                    <img src="<?php echo esc_url($url); ?>" 
                         style="max-width:200px; height:auto; border-radius:4px; box-shadow:0 2px 8px rgba(0,0,0,0.1);">
                <?php else: ?>
                    <div class="ns-no-image" style="padding:20px; text-align:center; background:#f0f0f1; border-radius:4px; color:#646970;">
                        تصویری انتخاب نشده
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
    
    // ========== ذخیره ==========
    public static function save($term_id) {
        // بررسی nonce
        if (!isset($_POST['tourism_meta_nonce']) || 
            !wp_verify_nonce($_POST['tourism_meta_nonce'], 'nextsafar_tourism_meta')) {
            error_log('❌ Tourism Meta: Nonce verification failed for term ' . $term_id);
            return;
        }
        
        // فقط فیلدهای تصویر
        $image_fields = ['tourism_image', 'tourism_banner', 'tourism_flag'];
        
        foreach ($image_fields as $field) {
            if (isset($_POST[$field])) {
                $value = intval($_POST[$field]);
                
                if ($value > 0) {
                    update_term_meta($term_id, '_' . $field, $value);
                    error_log("✅ Tourism Meta: Saved {$field} = {$value} for term {$term_id}");
                } else {
                    delete_term_meta($term_id, '_' . $field);
                    error_log("🗑️ Tourism Meta: Deleted {$field} for term {$term_id}");
                }
            }
        }
    }
    
    // ========== ستون‌های سفارشی ==========
    public static function add_columns($columns) {
        $new = [];
        foreach ($columns as $key => $val) {

            if ($key === 'name') {
                $new['image'] = 'تصویر';
            }

            $new[$key] = $val;
        }
        unset($new['posts']); // حذف ستون پیش‌فرض
        return $new;
    }
    
    public static function render_columns($content, $column_name, $term_id) {
        $image_fields = [
            'image'  => '_tourism_image',
        ];

        if (isset($image_fields[$column_name])) {
            $img_id = get_term_meta($term_id, $image_fields[$column_name], true);
            if (is_numeric($img_id) && $img_id > 0) {
                $img_url = wp_get_attachment_image_url($img_id, 'thumbnail');
                if ($img_url) {
                    return '<img src="' . esc_url($img_url) . '" style="width:50px;height:50px;object-fit:cover;border-radius:4px;">';
                }
            }
            return '—';
        }
        return $content;
    }
        
    // ========== REST API ==========
    public static function register_rest_fields() {
        register_rest_field('tourism', 'images', [
            'get_callback'    => [__CLASS__, 'get_rest_images'],
            'update_callback' => null,
            'schema'          => null,
        ]);
    }
    
    public static function get_rest_images($term) {
        $term_id = $term['id'];
        
        $images = [
            'image'  => '_tourism_image',
            'banner' => '_tourism_banner',
            'flag'   => '_tourism_flag'
        ];
        
        $data = [];
        
        foreach ($images as $key => $meta_key) {
            $image_id = get_term_meta($term_id, $meta_key, true);
            
            if (is_numeric($image_id) && $image_id > 0) {
                $data[$key] = [
                    'id'        => (int) $image_id,
                    'thumbnail' => wp_get_attachment_image_url($image_id, 'thumbnail') ?: '',
                    'medium'    => wp_get_attachment_image_url($image_id, 'medium') ?: '',
                    'large'     => wp_get_attachment_image_url($image_id, 'large') ?: '',
                    'full'      => wp_get_attachment_url($image_id) ?: '',
                    'alt'       => get_post_meta($image_id, '_wp_attachment_image_alt', true) ?: ''
                ];
            } else {
                $data[$key] = null;
            }
        }
        
        return $data;
    }
}