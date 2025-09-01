<?php
/**
 * Plugin Name: BuyTap Countdown Shortcode
 * Description: Shortcode [buytap_countdown] to show a countdown and temporarily reveal a form window using Luxon. Works with Elementor forms.
 * Version: 1.0.0
 * Author: Philip Osir
 */

if (!defined('ABSPATH')) exit;

// Add REST API endpoint for server time synchronization
add_action('rest_api_init', function () {
    register_rest_route('buytap/v1', '/now', [
        'methods' => 'GET',
        'callback' => 'buytap_get_server_time',
        'permission_callback' => '__return_true'
    ]);
    
    // Add endpoint to force close forms
    register_rest_route('buytap/v1', '/force-close', [
        'methods' => 'POST',
        'callback' => 'buytap_force_close_forms',
        'permission_callback' => '__return_true'
    ]);
});

function buytap_get_server_time() {
    return rest_ensure_response([
        'utc' => time(),
        'iso' => gmdate('c'),
        'timestamp' => microtime(true)
    ]);
}

function buytap_force_close_forms() {
    // This can be used to force close all forms if needed
    return rest_ensure_response(['success' => true]);
}

add_action('init', function () {
  add_shortcode('buytap_countdown', 'buytap_countdown_shortcode');
});

/**
 * Shortcode: [buytap_countdown]
 *
 * Attributes:
 * - form_selector:   CSS selector for the form container to show/hide (default "#buy-form")
 * - tz:              IANA timezone (default "Africa/Nairobi")
 * - mode:            "test" or "schedule" ("test" = opens in test_wait_seconds; "schedule" = use open_times)
 * - test_wait_seconds: seconds to wait before opening (default 60; only when mode="test")
 * - show_for_seconds: how long to keep form visible once opened (default 3600 = 1 hour)
 * - open_times:      comma separated HH:MM times (24h) for opening windows (default "09:00,19:00"; used when mode="schedule")
 * - heading:         heading text above the timer (default "Next Token Sale Starts In:")
 */

function buytap_countdown_shortcode($atts = []) {
  $opts = [
  'mode'              => get_option('buytap_mode','test'),
  'test_wait_seconds' => (int) get_option('buytap_test_wait_seconds', 60),
  'show_for_seconds'  => (int) get_option('buytap_show_for_seconds', 3600),
  'open_times'        => get_option('buytap_open_times','09:00,19:00'),
  'tz'                => get_option('buytap_timezone','Africa/Nairobi'),
  'open_dates'        => get_option('buytap_open_dates',''), // optional
];

$a = shortcode_atts([
  'form_selector'     => '#buy-form',
  'tz'                => $opts['tz'],
  'mode'              => $opts['mode'],
  'test_wait_seconds' => $opts['test_wait_seconds'],
  'show_for_seconds'  => $opts['show_for_seconds'],
  'open_times'        => $opts['open_times'],
  'open_dates'        => $opts['open_dates'],
	'key_suffix'        => '',
  // 'heading'           => 'Next Token Sale Starts In:',
//   'heading'  		  => '⏳BuyTap Official Launch — 9th September:',
//   'heading'           => '⏳ “Launching BuyTap on 9/9 — Get Ready!”:',
   'heading'          => '⏳ Launch Day: 9th September :',
], $atts, 'buytap_countdown');


  // Enqueue Luxon + font once
  wp_enqueue_script(
    'luxon',
    'https://cdn.jsdelivr.net/npm/luxon@3.3.0/build/global/luxon.min.js',
    [],
    null,
    true
  );
  wp_enqueue_style(
    'buytap-countdown-oswald',
    'https://fonts.googleapis.com/css2?family=Oswald:wght@400;700&display=swap',
    [],
    null
  );
	// Make the REST URL available to the inline script
	wp_register_script('buytap-now-stub', false); // a dummy handle to localize against
	wp_enqueue_script('buytap-now-stub');
	wp_localize_script('buytap-now-stub', 'buytapAjax', [
	  'nowUrl' => esc_url_raw( rest_url('buytap/v1/now') ),
	]);

  // Unique IDs so multiple shortcodes can coexist
  $uid = 'btc_' . wp_generate_password(8, false, false);
  $wrap_id   = "custom-countdown-$uid";
  $timer_id  = "timer-$uid";
  $hours_id  = "hours-$uid";
  $mins_id   = "minutes-$uid";
  $secs_id   = "seconds-$uid";

	
	// Add this before ob_start()
	$server_now_iso = gmdate('c'); // e.g., "2025-08-26T12:34:56+00:00"
  // Data attributes for the script
  $data_attrs = sprintf(
  ' data-form-selector="%s" data-tz="%s" data-mode="%s" data-test-wait="%d" data-show-for="%d" data-open-times="%s" data-open-dates="%s" data-uid="%s" data-key-suffix="%s" data-server-now="%s" ',
  esc_attr($a['form_selector']),
  esc_attr($a['tz']),
  esc_attr($a['mode']),
  (int)$a['test_wait_seconds'],
  (int)$a['show_for_seconds'],
  esc_attr($a['open_times']),
  esc_attr($a['open_dates']),
  esc_attr($uid),
  esc_attr($a['key_suffix']),
  esc_attr($server_now_iso)
);

	// Make the REST URL available to the inline script
	wp_register_script('buytap-now-stub', false); // a dummy handle to localize against
	wp_enqueue_script('buytap-now-stub');
	wp_localize_script('buytap-now-stub', 'buytapAjax', [
	  'nowUrl' => esc_url_raw( rest_url('buytap/v1/now') ),
	]);


  ob_start(); ?>
  <div class="buytap-countdown-wrapper" <?= $data_attrs; ?>>
  <div id="<?= esc_attr($wrap_id); ?>">
    <div style="text-align:center;">
      <h2><?= esc_html($a['heading']); ?></h2>
    </div>

    <div id="<?= esc_attr($timer_id); ?>" role="timer" aria-live="polite" class="buytap-timer">
		<div class="time-block">
			<div class="time-number" id="days-<?= esc_attr($uid); ?>">00</div>
			<div class="time-label">Days</div>
		</div>
		<div class="time-block">
        <div class="time-number" id="<?= esc_attr($hours_id); ?>">00</div>
        <div class="time-label">Hours</div>
      </div>
      <div class="time-block">
        <div class="time-number" id="<?= esc_attr($mins_id); ?>">00</div>
        <div class="time-label">Minutes</div>
      </div>
      <div class="time-block">
        <div class="time-number" id="<?= esc_attr($secs_id); ?>">00</div>
        <div class="time-label">Seconds</div>
      </div>
    </div>
    </div>

    <!-- Critical pre-hide: hide the target form before JS decides -->
    <style id="bt-prehide-<?= esc_attr($uid); ?>">
      <?= esc_html($a['form_selector']); ?> { display: none !important; }
    </style>

    <style>
      /* Scoped styles (minimal bleed) */
      #<?= $wrap_id; ?> {
        text-align: center;
        padding: 20px;
        /* ↓ Add this ↓ */
        visibility: hidden; /* prevent timer flash before JS decides */

      }
      #<?= $timer_id; ?> {
        display: flex;
        justify-content: center;
        gap: 20px;
        align-items: center;
      }
      #<?= $wrap_id; ?> .time-block { text-align: center; }
      #<?= $wrap_id; ?> .time-number {
        font-size: 80px;
        font-family: 'Oswald', sans-serif;
        font-weight: bold;
        color: #ff2c77;
      }
      #<?= $wrap_id; ?> .time-label {
        font-size: 15px;
        margin-top: 40px;
        color: #fff;
      }
		/* Robust visibility helpers (avoid Elementor/theme conflicts) */
		.bty-hidden { display: none !important; }
		.bty-open   { display: block; }

    </style>

    <script>
(function () {
  // Enhanced storage wrapper with cross-tab synchronization
  const storage = (() => {
    try {
      const test = '__bt_storage_test__';
      localStorage.setItem(test, '1');
      localStorage.removeItem(test);
      return {
        getItem: (key) => localStorage.getItem(key),
        setItem: (key, value) => {
          localStorage.setItem(key, value);
          // Dispatch custom event for cross-tab communication
          window.dispatchEvent(new CustomEvent('buytapStorageChange', {
            detail: { key, value }
          }));
        },
        removeItem: (key) => {
          localStorage.removeItem(key);
          window.dispatchEvent(new CustomEvent('buytapStorageChange', {
            detail: { key, value: null }
          }));
        }
      };
    } catch (e) {
      return {
        getItem: () => null,
        setItem: () => {},
        removeItem: () => {}
      };
    }
  })();

  function buytapBoot() {
    if (!window.luxon) {
      console.error('Luxon not loaded');
      return;
    }
    const DateTime = window.luxon.DateTime;

    const root = document.querySelector('[data-uid="<?= esc_js($uid); ?>"]');
    if (!root) return;

    // --- Read config
    const tz = root.getAttribute('data-tz') || 'Africa/Nairobi';
    const mode = root.getAttribute('data-mode') || 'test';
    const testWait = Number(root.getAttribute('data-test-wait')) || 60;
    const showFor = Number(root.getAttribute('data-show-for')) || 60; // Using your 1 minute setting
    const openTimes = (root.getAttribute('data-open-times') || '09:00,19:00')
      .split(',').map(s => s.trim()).filter(Boolean);

    const openDatesRaw = (root.getAttribute('data-open-dates') || '').trim();
    const openDates = openDatesRaw
      ? openDatesRaw.split(/\n|,/).map(s => s.trim()).filter(Boolean)
      : [];

    // --- Server-anchored time with better precision
    const serverNowISO = root.getAttribute('data-server-now');
    let baseServerUTC = serverNowISO
      ? DateTime.fromISO(serverNowISO, { zone: 'utc' })
      : DateTime.utc();

    const perfStart = performance.now();
    let lastServerSync = Date.now();
    
    function nowUTC() {
      const elapsedMs = performance.now() - perfStart;
      return baseServerUTC.plus({ milliseconds: elapsedMs });
    }
    
    function nowInZone() {
      return nowUTC().setZone(tz);
    }

    // --- Elements
    const wrapEl = document.getElementById('<?= esc_js($wrap_id); ?>');
    const daysEl = document.getElementById('days-<?= esc_js($uid); ?>');
    const hoursEl = document.getElementById('<?= esc_js($hours_id); ?>');
    const minutesEl = document.getElementById('<?= esc_js($mins_id); ?>');
    const secondsEl = document.getElementById('<?= esc_js($secs_id); ?>');

    // Show/hide helpers
    function setShown(el, shown) {
      if (!el) return;
      el.classList.toggle('bty-hidden', !shown);
      el.classList.toggle('bty-open', shown);
    }

    // Form handling
    const formSelector = root.getAttribute('data-form-selector') || '#buy-form';
    const formEl = document.querySelector(formSelector);

    function lockForm() {
      if (!formEl) return;
      formEl.querySelectorAll('input, select, textarea, button').forEach(el => {
        el.disabled = true;
        el.classList.add('buytap-disabled');
      });
    }
    
    function unlockForm() {
      if (!formEl) return;
      formEl.querySelectorAll('input, select, textarea, button').forEach(el => {
        el.disabled = false;
        el.classList.remove('buytap-disabled');
      });
    }

    // Initial state
    setShown(formEl, false);
    setShown(wrapEl, true);

    // --- Storage keys with better isolation
    const selectorKey = (formSelector || '#buy-form').replace(/[^a-z0-9_-]/gi, '_');
    const pageKey = (location.pathname || '/').replace(/[^a-z0-9_-]/gi, '_');
    const keySuffix = (root.getAttribute('data-key-suffix') || '').replace(/[^a-z0-9_-]/gi, '_');
    
    const LS_KEY = `buyFormHideAt_${selectorKey}_${pageKey}${keySuffix ? '_' + keySuffix : ''}`;
    const LS_STATUS = `buyFormStatus_${selectorKey}_${pageKey}${keySuffix ? '_' + keySuffix : ''}`;
    const LS_NEXT = `buyFormNext_${selectorKey}_${pageKey}${keySuffix ? '_' + keySuffix : ''}`;

    const G_KEY = 'buytapSale_hideAt';
    const G_STATUS = 'buytapSale_status';
    const G_NEXT = 'buytapSale_nextOpenMs';

    function hashConfig() {
      const cfgString = JSON.stringify({ tz, mode, testWait, showFor, openTimes, openDates });
      let hash = 0;
      for (let i = 0; i < cfgString.length; i++) {
        hash = ((hash << 5) - hash) + cfgString.charCodeAt(i);
        hash |= 0;
      }
      return hash.toString();
    }
    
    const configHash = hashConfig();

    let targetTime = null;
    let openUntilMs = null;
    let formOpen = false;
    let closeSyncTimer = null;
    let countdownTimer = null;

    // Enhanced server time sync with retry logic
    async function syncWithServerTime() {
      try {
        const now = Date.now();
        // Only sync with server every 30 seconds to avoid too many requests
        if (now - lastServerSync < 30000 && baseServerUTC.isValid) {
          return true;
        }
        
        const response = await fetch(buytapAjax.nowUrl, {
          cache: 'no-store',
          headers: {
            'Cache-Control': 'no-cache, no-store, must-revalidate',
            'Pragma': 'no-cache'
          }
        });
        
        if (response.ok) {
          const data = await response.json();
          baseServerUTC = DateTime.fromSeconds(data.utc, { zone: 'utc' });
          lastServerSync = Date.now();
          return true;
        }
      } catch (error) {
        console.warn('Server time sync failed, using client time:', error);
      }
      return false;
    }

    // Enhanced form close check with multiple verification methods
    async function checkAndCloseFormIfNeeded() {
      if (!openUntilMs) return;
      
      // Method 1: Check server time directly
      await syncWithServerTime();
      const serverTimeMs = nowUTC().toMillis();
      
      // Method 2: Check localStorage for cross-tab consistency
      const storedHideAt = Number(storage.getItem(LS_KEY));
      const storedStatus = storage.getItem(LS_STATUS);
      
      // Method 3: Check global storage for cross-account consistency
      const globalHideAt = Number(storage.getItem(G_KEY));
      const globalStatus = storage.getItem(G_STATUS);
      
      // If ANY source indicates the form should be closed, close it
      if (
        serverTimeMs >= openUntilMs ||
        (storedHideAt && serverTimeMs >= storedHideAt && storedStatus !== "open") ||
        (globalHideAt && serverTimeMs >= globalHideAt && globalStatus !== "open")
      ) {
        resetToCountdown();
        return true;
      }
      
      return false;
    }

    function showFormThenHideLater() {
      if (formOpen) return;
      formOpen = true;

      setShown(formEl, true);
      unlockForm();
      setShown(wrapEl, false);

      const hideAtUTC = nowUTC().plus({ seconds: showFor });
      openUntilMs = hideAtUTC.toMillis();

      // Update all storage locations for consistency
      storage.setItem(LS_KEY, String(openUntilMs));
      storage.setItem(LS_STATUS, "open");
      storage.setItem(G_KEY, String(openUntilMs));
      storage.setItem(G_STATUS, "open");

      // Start aggressive synchronization while form is open
      if (!closeSyncTimer) {
        closeSyncTimer = setInterval(checkAndCloseFormIfNeeded, 1000); // Check every second
      }

      // Also set a client-side timeout as backup
      setTimeout(checkAndCloseFormIfNeeded, showFor * 1000);
    }

    function resetToCountdown() {
      if (closeSyncTimer) {
        clearInterval(closeSyncTimer);
        closeSyncTimer = null;
      }
      
      if (countdownTimer) {
        cancelAnimationFrame(countdownTimer);
      }
      
      formOpen = false;
      setShown(formEl, false);
      lockForm();
      setShown(wrapEl, true);

      // Remove the pre-hide style
      const prehide = document.getElementById('bt-prehide-<?= esc_js($uid); ?>');
      if (prehide) prehide.remove();
      
      if (wrapEl) wrapEl.style.visibility = 'visible';

      // Clear all storage entries
      storage.removeItem(LS_KEY);
      storage.removeItem(LS_STATUS);
      storage.removeItem(G_KEY);
      storage.removeItem(G_STATUS);

      openUntilMs = null;
      calculateNextTargetTime();
      startCountdown();
    }

    function calculateNextTargetTime() {
      if (mode === 'schedule') {
        // Your existing schedule logic
        const now = nowInZone();
        const today = now.startOf('day');
        let next = null;
        
        for (let t of openTimes) {
          const [hh, mm] = t.split(':').map(Number);
          const cand = today.set({ hour: hh, minute: mm, second: 0, millisecond: 0 });
          if (cand > now && (!next || cand < next)) next = cand;
        }
        
        if (!next) {
          const [hh, mm] = (openTimes[0] || '09:00').split(':').map(Number);
          next = today.plus({ days: 1 }).set({ hour: hh, minute: mm, second: 0, millisecond: 0 });
        }
        
        targetTime = next;
      } else {
        // Test mode - open after X seconds
        targetTime = nowInZone().plus({ seconds: testWait });
      }
      
      // Store for cross-tab consistency
      storage.setItem(LS_NEXT, String(targetTime.toMillis()));
      storage.setItem(G_NEXT, String(targetTime.toMillis()));
    }

    function startCountdown() {
      countdownTimer = requestAnimationFrame(updateCountdown);
    }

    function updateCountdown() {
      if (!targetTime || formOpen) return;
      
      const now = nowInZone();
      const diff = targetTime.diff(now, ['days', 'hours', 'minutes', 'seconds']).toObject();
      
      // Update display
      if (daysEl) {
        const d = String(Math.max(0, Math.floor(diff.days || 0))).padStart(2, '0');
        daysEl.textContent = d;
        daysEl.parentElement.style.display = parseInt(d, 10) > 0 ? "block" : "none";
      }
      
      if (hoursEl) hoursEl.textContent = String(Math.max(0, Math.floor(diff.hours || 0))).padStart(2, '0');
      if (minutesEl) minutesEl.textContent = String(Math.max(0, Math.floor(diff.minutes || 0))).padStart(2, '0');
      if (secondsEl) secondsEl.textContent = String(Math.max(0, Math.floor(diff.seconds || 0))).padStart(2, '0');

      // Check if it's time to open the form
      if (diff.days <= 0 && diff.hours <= 0 && diff.minutes <= 0 && diff.seconds <= 0) {
        showFormThenHideLater();
        return;
      }

      countdownTimer = requestAnimationFrame(updateCountdown);
    }

    // Initialize
    function initializeCountdown() {
      // Check if form should be open from previous session
      const storedHideAt = Number(storage.getItem(LS_KEY));
      const storedStatus = storage.getItem(LS_STATUS);
      const nowUTCms = nowUTC().toMillis();
      
      if (storedHideAt && storedStatus === "open" && nowUTCms < storedHideAt) {
        // Form was previously open and should still be open
        openUntilMs = storedHideAt;
        setShown(formEl, true);
        unlockForm();
        setShown(wrapEl, false);
        
        // Start checking for when to close
        closeSyncTimer = setInterval(checkAndCloseFormIfNeeded, 1000);
      } else {
        // Start fresh countdown
        setShown(formEl, false);
        lockForm();
        setShown(wrapEl, true);
        calculateNextTargetTime();
        startCountdown();
      }
      
      // Make visible
      const prehide = document.getElementById('bt-prehide-<?= esc_js($uid); ?>');
      if (prehide) prehide.remove();
      if (wrapEl) wrapEl.style.visibility = 'visible';
    }

    // Set up cross-tab communication
    if (!window.buytapCrossTabInitialized) {
      window.addEventListener('storage', function(e) {
        if (e.key && e.key.startsWith('buytapSale_')) {
          checkAndCloseFormIfNeeded();
        }
      });
      
      window.addEventListener('buytapStorageChange', function(e) {
        if (e.detail.key && e.detail.key.startsWith('buytapSale_')) {
          checkAndCloseFormIfNeeded();
        }
      });
      
      window.buytapCrossTabInitialized = true;
    }

    // Start everything
    initializeCountdown();
    
    // Periodically sync with server time (every 5 minutes)
    setInterval(syncWithServerTime, 300000);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', buytapBoot, { once: true });
  } else {
    buytapBoot();
  }
})();
</script>

  </div>
  <?php
  return ob_get_clean();
}
