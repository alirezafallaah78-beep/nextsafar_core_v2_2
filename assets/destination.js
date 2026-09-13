(function ($) {
  "use strict";

  $(document).ready(function () {
    console.log("✅ NextSafar Destination JS loaded");

    // ========== منطق ساعات کاری ==========
    // وقتی "تعطیل" چک شد، فیلدهای زمان غیرفعال شوند
    $(".worktime-checkbox-off").on("change", function () {
      var $day = $(this).closest(".ns-wh-day");
      if ($(this).is(":checked")) {
        $day.find('input[type="time"]').val("").prop("disabled", true);
        $day.find(".worktime-checkbox-24h").prop("checked", false);
      } else {
        $day.find('input[type="time"]').prop("disabled", false);
      }
    });

    // وقتی "۲۴ ساعته" چک شد، فیلدهای زمان پر شوند و غیرفعال شوند
    $(".worktime-checkbox-24h").on("change", function () {
      var $day = $(this).closest(".ns-wh-day");
      if ($(this).is(":checked")) {
        $day.find('input[type="time"]').val("").prop("disabled", true);
        $day.find(".worktime-checkbox-off").prop("checked", false);
      } else {
        $day.find('input[type="time"]').prop("disabled", false);
      }
    });

    // هنگام لود صفحه، وضعیت اولیه را اعمال کن
    $(".worktime-checkbox-off, .worktime-checkbox-24h").each(function () {
      if ($(this).is(":checked")) {
        $(this).trigger("change");
      }
    });

    // هنگام تایپ در فیلدهای زمان، چک‌باکس‌ها را غیرفعال کن
    $('input[type="time"]').on("change", function () {
      var $day = $(this).closest(".ns-wh-day");
      if ($(this).val()) {
        $day
          .find(".worktime-checkbox-off, .worktime-checkbox-24h")
          .prop("checked", false);
      }
    });
  });
})(jQuery);
