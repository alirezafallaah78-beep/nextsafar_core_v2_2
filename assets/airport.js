(function ($) {
  "use strict";

  // ⭐ جلوگیری از bind چندباره با namespace
  $(document).ready(function () {
    console.log("✅ NextSafar Airport JS loaded");

    // ابتدا همه handlerهای قبلی را off کن، سپس bind کن
    $(document)
      .off("click.nsAccordion", ".ns-amenity-header")
      .on("click.nsAccordion", ".ns-amenity-header", function (e) {
        e.preventDefault();
        e.stopPropagation();
        e.stopImmediatePropagation(); // ⭐ جلوگیری از trigger چندباره

        var $header = $(this);
        var target = $header.data("target");
        var $body = $("#" + target);

        if (!$body.length) return;

        // ⭐ استفاده از attribute به جای data برای state
        var isOpen = $header.attr("data-open") === "true";

        // توقف انیمیشن‌های قبلی
        $body.stop(true, true);

        if (isOpen) {
          // بستن
          $body.slideUp(200, function () {
            $header.removeClass("active");
            $header.attr("data-open", "false");
          });
        } else {
          // باز کردن
          $header.addClass("active");
          $body.slideDown(200, function () {
            $header.attr("data-open", "true");
          });
        }

        console.log("Accordion toggled:", target, "open:", !isOpen);
      });

    // ========== افزودن آیتم تکرارشونده ==========
    $(document)
      .off("click.nsAddItem", ".ns-btn-add.add-item")
      .on("click.nsAddItem", ".ns-btn-add.add-item", function (e) {
        e.preventDefault();
        e.stopPropagation();

        var $wrapper = $(this).closest(".ns-repeatable-wrapper");
        var fieldName = $wrapper.data("name");
        var $list = $wrapper.find(".ns-repeatable-list");
        var $firstItem = $list.find(".ns-repeatable-item").first();

        if ($firstItem.length) {
          var $newItem = $firstItem.clone();
          $newItem.find("input").val("");
          $list.append($newItem);
        } else {
          var html =
            '<div class="ns-repeatable-item">' +
            '<input type="text" name="' +
            fieldName +
            '[]" placeholder="مورد سفارشی..." />' +
            '<button type="button" class="remove-item">✕</button>' +
            "</div>";
          $list.append(html);
        }
      });

    // ========== حذف آیتم تکرارشونده ==========
    $(document)
      .off("click.nsRemoveItem", ".ns-repeatable-item .remove-item")
      .on(
        "click.nsRemoveItem",
        ".ns-repeatable-item .remove-item",
        function (e) {
          e.preventDefault();
          e.stopPropagation();

          var $item = $(this).closest(".ns-repeatable-item");
          var $list = $item.closest(".ns-repeatable-list");
          var $siblings = $list.find(".ns-repeatable-item");

          if ($siblings.length > 1) {
            $item.fadeOut(200, function () {
              $(this).remove();
            });
          } else {
            $item.find("input").val("");
          }
        },
      );

    // ========== خطوط هوایی ==========
    $(document)
      .off("click.nsAddAirline", "#add-airline")
      .on("click.nsAddAirline", "#add-airline", function (e) {
        e.preventDefault();
        e.stopPropagation();

        var $wrapper = $("#airlines-wrapper");
        var airlineIndex = $wrapper.find(".ns-airline-item").length;

        var html =
          '<div class="ns-airline-item" data-index="' +
          airlineIndex +
          '">' +
          '<input type="text" name="airlines[' +
          airlineIndex +
          '][name]" placeholder="نام ایرلاین (مثلاً: ماهان)">' +
          '<input type="url" name="airlines[' +
          airlineIndex +
          '][link]" placeholder="لینک سایت (اختیاری)">' +
          '<button type="button" class="remove-airline" title="حذف">✕</button>' +
          "</div>";

        $wrapper.append(html);
      });

    $(document)
      .off("click.nsRemoveAirline", ".ns-airline-item .remove-airline")
      .on(
        "click.nsRemoveAirline",
        ".ns-airline-item .remove-airline",
        function (e) {
          e.preventDefault();
          e.stopPropagation();
          $(this)
            .closest(".ns-airline-item")
            .fadeOut(200, function () {
              $(this).remove();
            });
        },
      );

    // ⭐ مقداردهی اولیه state برای آکاردئون‌ها (از inline style)
    $(".ns-amenity-header").each(function () {
      var $header = $(this);
      var target = $header.data("target");
      var $body = $("#" + target);

      if ($body.length) {
        var display = $body.css("display");
        var isOpen = display !== "none" && display !== "";
        $header.attr("data-open", isOpen ? "true" : "false");
      }
    });
  });
})(jQuery);
