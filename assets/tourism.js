jQuery(function($) {

    /* ========== آپلود تصویر ========== */
    $(document).on('click', '.ns-tourism-upload', function(e) {
        e.preventDefault();

        var target = $(this).data('target');
        var $field = $(this).closest('.ns-tourism-image-field');

        var frame = wp.media({
            title: 'انتخاب تصویر',
            multiple: false
        });

        frame.on('select', function() {
            var att = frame.state().get('selection').first().toJSON();

            // ذخیره Attachment ID
            $('#' + target).val(att.id);

            // نمایش پیش‌نمایش
            $field.find('.ns-tourism-preview').html(
                '<img src="' + att.url + '" style="max-width:200px; height:auto; border-radius:4px; box-shadow:0 2px 8px rgba(0,0,0,0.1);">'
            );

            /* ✅ بعد از افزودن عکس:
               دکمه «انتخاب تصویر» مخفی و دکمه «✕» نمایان می‌شود */
            $field.find('.ns-tourism-upload').hide();
            $field.find('.ns-tourism-remove').show();
        });

        frame.open();
    });

    /* ========== حذف تصویر ========== */
    $(document).on('click', '.ns-tourism-remove', function(e) {
        e.preventDefault();

        var target = $(this).data('target');
        var $field = $(this).closest('.ns-tourism-image-field');

        // پاک کردن مقدار اینپوت
        $('#' + target).val('');

        // بازگرداندن placeholder
        $field.find('.ns-tourism-preview').html(
            '<div class="ns-no-image" style="padding:20px; text-align:center; background:#f0f0f1; border-radius:4px; color:#646970;">📷 تصویری انتخاب نشده</div>'
        );

        /* ✅ بعد از حذف عکس:
           دکمه «✕» مخفی و دکمه «انتخاب تصویر» دوباره نمایان می‌شود */
        $(this).hide();
        $field.find('.ns-tourism-upload').show();
    });

});