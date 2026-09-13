(function ($) {
  "use strict";

  var mediaFrame = null;

  $(document).ready(function () {
    initGallery();
    initSortable();
    initFeaturedDetection();
  });

  /**
   * راه‌اندازی گالری
   */
  function initGallery() {
    var $grid = $("#ns-gallery-grid");
    if (!$grid.length) return;

    // افزودن تصاویر
    $("#ns-gallery-add").on("click", function (e) {
      e.preventDefault();

      if (mediaFrame) {
        mediaFrame.open();
        return;
      }

      mediaFrame = wp.media({
        title: NextSafarGallery.media_title,
        button: { text: NextSafarGallery.media_button },
        multiple: true,
        library: { type: "image" },
      });

      mediaFrame.on("select", function () {
        var attachments = mediaFrame.state().get("selection").toJSON();
        attachments.forEach(addImageToGrid);
        updateHiddenInput();
        markTouched();
      });

      mediaFrame.open();
    });

    // حذف تصویر
    $grid.on("click", ".ns-gallery-remove", function () {
      $(this)
        .closest(".ns-gallery-item")
        .fadeOut(200, function () {
          $(this).remove();
          updateHiddenInput();
          markTouched();
        });
    });

    // مشاهده تصویر
    $grid.on("click", ".ns-gallery-view", function () {
      var url = $(this).closest(".ns-gallery-item").find("img").data("full");
      $("#ns-gallery-modal-img").attr("src", url);
      $("#ns-gallery-modal").fadeIn(200);
    });

    // بستن مودال
    $("#ns-gallery-modal").on(
      "click",
      ".ns-gallery-modal-close, .ns-gallery-modal-overlay",
      function () {
        $("#ns-gallery-modal").fadeOut(200);
      },
    );

    // پاک کردن همه
    $("#ns-gallery-clear").on("click", function () {
      if (!confirm(NextSafarGallery.confirm_del)) return;
      $grid.empty();
      updateHiddenInput();
      markTouched();
    });
  }

  /**
   * افزودن یک تصویر به گرید
   */
  function addImageToGrid(att) {
    var id = att.id;
    var thumb =
      att.sizes && att.sizes.thumbnail ? att.sizes.thumbnail.url : att.url;
    var full = att.sizes && att.sizes.large ? att.sizes.large.url : att.url;
    var alt = att.alt || att.title || "";

    // اگر قبلاً هست رد شو
    if ($('.ns-gallery-item[data-id="' + id + '"]').length) return;

    var $item = $(`
            <div class="ns-gallery-item" data-id="${id}">
                <img src="${thumb}" data-full="${full}" alt="${alt}" loading="lazy">
                <div class="ns-gallery-actions">
                    <button type="button" class="ns-gallery-view" title="مشاهده">🔍</button>
                    <button type="button" class="ns-gallery-remove" title="حذف">✕</button>
                </div>
                <div class="ns-gallery-drag-handle" title="جابجایی">⋮⋮</div>
            </div>
        `);

    $("#ns-gallery-grid").append($item);
  }

  /**
   * مرتب‌سازی با drag & drop
   */
  function initSortable() {
    if (!$.fn.sortable) return;

    $("#ns-gallery-grid").sortable({
      handle: ".ns-gallery-drag-handle",
      cursor: "move",
      tolerance: "pointer",
      update: function () {
        updateHiddenInput();
        markTouched();
      },
    });
  }

  /**
   * تشخیص تغییر دستی تصویر شاخص
   */
  function initFeaturedDetection() {
    // وقتی تصویر شاخص از باکس وردپرس تغییر می‌کند
    $(document).on(
      "click",
      "#set-post-thumbnail, #remove-post-thumbnail",
      function () {
        $("#ns-featured-touched").val("1");
      },
    );
  }

  /**
   * به‌روزرسانی hidden input
   */
  function updateHiddenInput() {
    var ids = [];
    $("#ns-gallery-grid .ns-gallery-item").each(function () {
      ids.push($(this).data("id"));
    });
    $("#ns-gallery-ids").val(ids.join(","));

    // به‌روزرسانی counter
    var count = ids.length;
    var $count = $(".ns-gallery-count");
    if ($count.length) {
      $count.text("(" + count + " تصویر)");
    }
  }

  /**
   * علامت‌گذاری که کاربر دستکاری کرده
   */
  function markTouched() {
    $("#ns-gallery-touched").val("1");
  }
})(jQuery);
