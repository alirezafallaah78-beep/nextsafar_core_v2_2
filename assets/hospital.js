(function ($) {
  "use strict";

  $(document).ready(function () {
    console.log("✅ NextSafar Hospital JS loaded");

    // ساعات کاری - تعطیل
    $(document)
      .off("change.nsHospHours", ".worktime-checkbox-off")
      .on("change.nsHospHours", ".worktime-checkbox-off", function () {
        var $day = $(this).closest(".ns-wh-day");
        if ($(this).is(":checked")) {
          $day.find('input[type="time"]').val("").prop("disabled", true);
          $day.find(".worktime-checkbox-24h").prop("checked", false);
        } else {
          $day.find('input[type="time"]').prop("disabled", false);
        }
      });

    // ساعات کاری - ۲۴ ساعته
    $(document)
      .off("change.nsHospHours24", ".worktime-checkbox-24h")
      .on("change.nsHospHours24", ".worktime-checkbox-24h", function () {
        var $day = $(this).closest(".ns-wh-day");
        if ($(this).is(":checked")) {
          $day.find('input[type="time"]').val("").prop("disabled", true);
          $day.find(".worktime-checkbox-off").prop("checked", false);
        } else {
          $day.find('input[type="time"]').prop("disabled", false);
        }
      });

    // هنگام تایپ در فیلدهای زمان
    $(document)
      .off("change.nsHospTime", '.ns-wh-time input[type="time"]')
      .on("change.nsHospTime", '.ns-wh-time input[type="time"]', function () {
        var $day = $(this).closest(".ns-wh-day");
        if ($(this).val()) {
          $day
            .find(".worktime-checkbox-off, .worktime-checkbox-24h")
            .prop("checked", false);
        }
      });

    // اعمال وضعیت اولیه
    $(".worktime-checkbox-off, .worktime-checkbox-24h").each(function () {
      if ($(this).is(":checked")) {
        $(this).trigger("change");
      }
    });
  });
})(jQuery);
