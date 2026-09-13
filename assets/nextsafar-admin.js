/**
 * NextSafar Admin Scripts
 * جاوااسکریپت مرکزی و مشترک برای تمام صفحات ادمین
 *
 * @version 1.0.0
 */

(function ($) {
  "use strict";

  // ============================================
  // Namespace
  // ============================================
  window.NextSafar = window.NextSafar || {};

  var NS = NextSafar;

  // ============================================
  // Utilities
  // ============================================
  NS.utils = {
    /**
     * فرمت کردن اعداد با جداکننده هزارگان
     */
    formatNumber: function (num) {
      if (typeof num === "undefined" || num === null) return "0";
      return Number(num).toLocaleString("fa-IR");
    },

    /**
     * فرمت کردن قیمت با تومان
     */
    formatPrice: function (amount, currency) {
      var formatted = NS.utils.formatNumber(amount);
      if (currency === "USD") return "$" + formatted;
      if (currency === "IRR") return formatted + " ریال";
      return formatted + " تومان";
    },

    /**
     * فرمت کردن تاریخ شمسی
     */
    formatDate: function (dateString) {
      if (!dateString) return "—";
      try {
        var date = new Date(dateString);
        return date.toLocaleDateString("fa-IR", {
          year: "numeric",
          month: "long",
          day: "numeric",
          hour: "2-digit",
          minute: "2-digit",
        });
      } catch (e) {
        return dateString;
      }
    },

    /**
     * فرمت کردن مدت زمان
     */
    formatDuration: function (seconds) {
      if (seconds < 60) return seconds + " ثانیه";
      if (seconds < 3600) return Math.floor(seconds / 60) + " دقیقه";
      return Math.floor(seconds / 3600) + " ساعت";
    },

    /**
     * نمایش نوتیفیکیشن
     */
    showNotice: function (message, type) {
      type = type || "success";

      var $notice = $(
        '<div class="notice notice-' +
          type +
          ' is-dismissible"><p>' +
          message +
          "</p></div>",
      );
      $(".nextsafar-wrap h1").after($notice);

      // حذف خودکار بعد از 5 ثانیه
      setTimeout(function () {
        $notice.fadeOut(300, function () {
          $(this).remove();
        });
      }, 5000);
    },

    /**
     * نمایش خطا در لاگ
     */
    logError: function (message) {
      if (window.console && console.error) {
        console.error("[NextSafar]", message);
      }
    },

    /**
     * نمایش لودینگ
     */
    showLoading: function ($container) {
      $container.html(
        '<div class="ns-loading">' +
          '<span class="ns-spinner"></span>' +
          "<span>" +
          nextsafarAdmin.strings.loading +
          "</span>" +
          "</div>",
      );
    },

    /**
     * مخفی کردن لودینگ
     */
    hideLoading: function ($container) {
      $container.empty();
    },
  };

  // ============================================
  // AJAX Helper
  // ============================================
  NS.ajax = {
    /**
     * ارسال درخواست AJAX با مدیریت خطا
     */
    request: function (action, data, callbacks) {
      var defaults = {
        url: nextsafarAdmin.ajaxUrl,
        type: "POST",
        data: $.extend(
          {
            action: action,
            nonce: nextsafarAdmin.syncNonce,
          },
          data,
        ),
      };

      return $.ajax(defaults)
        .done(function (response) {
          if (response.success) {
            if (callbacks.success) callbacks.success(response.data);
          } else {
            var message =
              response.data && response.data.message
                ? response.data.message
                : nextsafarAdmin.strings.error;
            NS.utils.showNotice(message, "error");
            if (callbacks.error) callbacks.error(response.data);
          }
        })
        .fail(function (xhr, status, error) {
          NS.utils.logError("AJAX Error: " + status + " - " + error);
          NS.utils.showNotice(nextsafarAdmin.strings.error, "error");
          if (callbacks.error)
            callbacks.error({ status: status, error: error });
        });
    },

    /**
     * دریافت آمار کلی
     */
    getStats: function (callbacks) {
      return NS.ajax.request("nextsafar_get_sources_stats", {}, callbacks);
    },

    /**
     * بازنشانی منابع
     */
    resetSources: function (callbacks) {
      return NS.ajax.request("nextsafar_reset_sources", {}, callbacks);
    },

    /**
     * دریافت لیست مدل‌های AI
     */
    getAIModels: function (provider, callbacks) {
      return NS.ajax.request(
        "nextsafar_get_ai_models",
        {
          provider: provider,
        },
        callbacks,
      );
    },
  };

  // ============================================
  // UI Components
  // ============================================
  NS.ui = {
    /**
     * به‌روزرسانی نوار پیشرفت
     */
    updateProgress: function ($container, percent, label) {
      var $fill = $container.find(".ns-progress-fill");
      var $label = $container.find(".ns-progress-label span:last-child");

      $fill.css("width", percent + "%");
      if (label) {
        $label.text(label);
      }
    },

    /**
     * افزودن به لاگ سینک
     */
    appendLog: function ($log, message) {
      $log.append(message + "\n");
      $log.scrollTop($log[0].scrollHeight);
    },

    /**
     * پاک کردن لاگ سینک
     */
    clearLog: function ($log) {
      $log.empty();
    },
  };

  // ============================================
  // Initialize
  // ============================================
  $(document).ready(function () {
    // فعال‌سازی تولتیپ‌ها در صورت وجود
    if ($.fn.tooltip) {
      $("[data-tooltip]").tooltip();
    }

    // مدیریت فرم‌های تنظیمات
    $(".nextsafar-wrap form").on("submit", function (e) {
      var $form = $(this);
      var $submit = $form.find('[type="submit"]');

      // غیرفعال کردن دکمه در حین ارسال
      $submit.prop("disabled", true).addClass("ns-btn-loading");
    });
  });
})(jQuery);
