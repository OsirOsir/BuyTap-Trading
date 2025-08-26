<?php
/**
 * Plugin Name: BuyTap Countdown Shortcode
 * Description: Shortcode [buytap_countdown] to show a countdown and temporarily reveal a form window using Luxon. Works with Elementor forms.
 * Version: 1.0.0
 * Author: Philip Osir
 */

if (!defined('ABSPATH')) exit;

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

    <style>
      /* Scoped styles (minimal bleed) */
      #<?= $wrap_id; ?> {
        text-align: center;
        padding: 20px;
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
    </style>

    <script>
      (function() {
		  let formOpen = false; 
// 		  let storageListenerAttached = false;  // ✅ add this line here
        // Runs after Luxon loads (in footer)
        document.addEventListener('DOMContentLoaded', function () {
          if (!window.luxon) return;
          const DateTime = window.luxon.DateTime;

          const root = document.querySelector('[data-uid="<?= esc_js($uid); ?>"]');
          if (!root) return;

          // Read config
          const tz          = root.getAttribute('data-tz') || 'Africa/Nairobi';
          const mode        = root.getAttribute('data-mode') || 'test';
          const testWait    = parseInt(root.getAttribute('data-test-wait') || '60', 10);
          const showFor     = parseInt(root.getAttribute('data-show-for') || '3600', 10);
          const openTimes   = (root.getAttribute('data-open-times') || '09:00,19:00').split(',');
			const openDatesRaw = (root.getAttribute('data-open-dates') || '').trim();
			// Accept lines or comma-separated
			const openDates = openDatesRaw
			? openDatesRaw.split(/\n|,/).map(s => s.trim()).filter(Boolean)
			: [];

			// Read server time (UTC ISO) embedded by PHP
			const serverNowISO = root.getAttribute('data-server-now'); // e.g., "2025-08-26T12:34:56+00:00"
			const serverNowUTC = serverNowISO ? DateTime.fromISO(serverNowISO, { zone: 'utc' }) : null;

			// Capture the client time at load (UTC)
			const clientLoadUTC = DateTime.utc();

			// Compute offset: how much the client's clock differs from server clock
			const offsetMs = serverNowUTC ? (serverNowUTC.toMillis() - clientLoadUTC.toMillis()) : 0;

			// Helper: "now" according to the server, expressed in UTC
			function nowUTC() {
			  return DateTime.utc().plus({ milliseconds: offsetMs });
			}

			// Helper: "now" in the configured zone, but still anchored to server time
			function nowInZone() {
			  return nowUTC().setZone(tz);
			}

          const wrapEl      = document.getElementById('<?= esc_js($wrap_id); ?>');
		  const daysEl      = document.getElementById('days-<?= esc_js($uid); ?>');
          const hoursEl     = document.getElementById('<?= esc_js($hours_id); ?>');
          const minutesEl   = document.getElementById('<?= esc_js($mins_id); ?>');
          const secondsEl   = document.getElementById('<?= esc_js($secs_id); ?>');

          // Find the form using the selector (Elementor compatible)
          const formSelector = root.getAttribute('data-form-selector') || '#buy-form';
          const formEl = document.querySelector(formSelector);

			// ✅ Stable localStorage key (persists across refresh)
		  const selectorKey = (formSelector || '#buy-form').replace(/[^a-z0-9_-]/gi, '_');
		  const pageKey = (location.pathname || '/').replace(/[^a-z0-9_-]/gi, '_');
		  const keySuffix = (root.getAttribute('data-key-suffix') || '').replace(/[^a-z0-9_-]/gi, '_');
		  const LS_KEY = `buyFormHideAt_${selectorKey}_${pageKey}${keySuffix ? '_' + keySuffix : ''}`;
			
			// ✅ Global sync keys (shared across all pages)
			const G_KEY  = 'buytapSale_hideAt';
			const G_STAT = 'buytapSale_status';
			
			// ✅ Global cross-tab sync (listen for changes everywhere)
			if (!window.buytapStorageListenerAttached) {
				  window.addEventListener('storage', function (e) {
					if (e.key === G_KEY || e.key === G_STAT) {
					  requestAnimationFrame(updateCountdown);  // force re-check instantly
					}
				  });
				  window.buytapStorageListenerAttached = true;
				}

			
			function parseExplicitDate(entry, tz, fallbackTimeHHMM) {
			  // Accept "YYYY-MM-DD HH:MM" or "YYYY-MM-DD"
			  const hasTime = /\d{2}:\d{2}$/.test(entry);
			  let dt;

			  if (hasTime) {
				// Allow "YYYY-MM-DD HH:MM" (space) by converting to ISO "YYYY-MM-DDTHH:MM"
				const iso = entry.replace(' ', 'T');
				dt = DateTime.fromISO(iso, { zone: tz });
			  } else {
				// Date only → use first openTimes as the time
				const [hh, mm] = (fallbackTimeHHMM || '09:00').split(':').map(Number);
				dt = DateTime.fromISO(entry, { zone: tz }).set({ hour: hh, minute: mm, second: 0, millisecond: 0 });
			  }

			  return dt && dt.isValid ? dt : null;
			}

			function nextExplicitOpen() {
			  if (!openDates.length) return null;
			  const now = nowInZone();
			  let next = null;
			  for (const entry of openDates) {
				const dt = parseExplicitDate(entry, tz, openTimes[0] || '09:00');
				if (!dt) continue;
				if (dt > now && (!next || dt < next)) next = dt;
			  }
			  return next;
			}

			function nextScheduledOpen() {
			  const now = nowInZone();
			  const today = now.startOf('day');
			  let next = null;
			  for (let t of openTimes) {
				const [hh, mm] = t.trim().split(':').map(Number);
				const cand = today.set({ hour: hh, minute: mm, second: 0, millisecond: 0 });
				if (cand > now && (!next || cand < next)) next = cand;
			  }
			  if (!next) {
				const [hh, mm] = (openTimes[0] || '09:00').split(':').map(Number);
				next = today.plus({ days: 1 }).set({ hour: hh, minute: mm, second: 0, millisecond: 0 });
			  }
			  return next;
			}

          function getNextDropTime() {
			  if (mode === 'schedule') {
				// Prefer explicit dates if provided; otherwise use daily schedule
				const explicit = nextExplicitOpen();
				if (explicit) return explicit;
				return nextScheduledOpen();
			  }
			  // test mode
			  return nowInZone().plus({ seconds: testWait });
			}


          // LocalStorage key is unique per instance to allow multiple on a page
        

          let targetTime = getNextDropTime();

          function showFormThenHideLater(now) {
			  if (formOpen) return;   // prevent double triggering
			  formOpen = true;

			  if (formEl) formEl.style.display = 'block';
			  if (wrapEl) wrapEl.style.display = 'none';

			  const hideAt = now.plus({ seconds: showFor });
			  localStorage.setItem(LS_KEY, hideAt.toISO());
			  localStorage.setItem(LS_KEY + "_status", "open");   // mark as open
			  // ✅ Also set global keys so other pages can sync
			  localStorage.setItem(G_KEY, hideAt.toISO());
			  localStorage.setItem(G_STAT, "open");

			  // ✅ Cross-tab sync (only attach once)
			  if (!window.buytapStorageListenerAttached) {
				  window.addEventListener('storage', function (e) {
					if (e.key !== LS_KEY) return;
					const newVal = e.newValue;
					const now2 = nowInZone();
					if (newVal) {
					  const hideAtTime = DateTime.fromISO(newVal);
					  if (now2 < hideAtTime) {
						if (formEl) formEl.style.display = 'block';
						if (wrapEl) wrapEl.style.display = 'none';
						const msLeft = hideAtTime.diff(now2).toMillis();
						setTimeout(resetToCountdown, msLeft);
					  }
					} else {
					  resetToCountdown();
					}
				  });
				  window.buytapStorageListenerAttached = true;
				}
			  setTimeout(resetToCountdown, showFor * 1000);
			}

          function resetToCountdown() {
			  formOpen = false;   // <--- reset flag so it can reopen next cycle
			  if (formEl) formEl.style.display = 'none';
			  if (wrapEl) wrapEl.style.display = 'block';
			  localStorage.removeItem(LS_KEY);
			  localStorage.removeItem(LS_KEY + "_status");   // ✅ add this
			  targetTime = getNextDropTime();
			  requestAnimationFrame(updateCountdown);
			}

          function updateCountdown() {
            const now = nowInZone();

			  
			  // ✅ First check global state
			 const gHideAt = localStorage.getItem(G_KEY);
			 const gStatus = localStorage.getItem(G_STAT);
			 if (gHideAt && gStatus === "open") {
				  const gHideAtTime = DateTime.fromISO(gHideAt);
				  if (now < gHideAtTime) {
					  if (formEl) formEl.style.display = 'block';
					  if (wrapEl) wrapEl.style.display = 'none';
					  const msLeft = gHideAtTime.diff(now).toMillis();
					  setTimeout(resetToCountdown, msLeft);
					  return;
				  } else {
					  localStorage.removeItem(G_KEY);
					  localStorage.removeItem(G_STAT);
				  }
			  }
            // If there's a stored hideAt, respect it (form visible window)
            const storedHideAt = localStorage.getItem(LS_KEY);
			const storedStatus = localStorage.getItem(LS_KEY + "_status");
            if (storedHideAt && storedStatus === "open") {
              const hideAtTime = DateTime.fromISO(storedHideAt);
              if (now < hideAtTime) {
                // Show form; schedule reset when window ends
                if (formEl) formEl.style.display = 'block';
                if (wrapEl) wrapEl.style.display = 'none';
                const msLeft = hideAtTime.diff(now).toMillis();
                setTimeout(resetToCountdown, msLeft);
                return;
              } else {
                localStorage.removeItem(LS_KEY);
				localStorage.removeItem(LS_KEY + "_status");
              }
            }

            const diff = targetTime.diff(now, ['days','hours','minutes','seconds']).toObject();
			const d = String(Math.max(0, Math.floor(diff.days || 0))).padStart(2,'0');
            const h = String(Math.max(0, Math.floor(diff.hours || 0))).padStart(2,'0');
            const m = String(Math.max(0, Math.floor(diff.minutes || 0))).padStart(2,'0');
            const s = String(Math.max(0, Math.floor(diff.seconds || 0))).padStart(2,'0');

            if (daysEl) {
			if (parseInt(d) > 0) {
				daysEl.textContent = d;
				daysEl.parentElement.style.display = "block"; // show days block
			} else {
				daysEl.parentElement.style.display = "none";  // hide days block
			}
			}

			if (hoursEl)   hoursEl.textContent = h;
			if (minutesEl) minutesEl.textContent = m;
			if (secondsEl) secondsEl.textContent = s;


            // Time reached -> show form for the window duration
            if (
			  (diff.days || 0) <= 0 &&
			  (diff.hours || 0) <= 0 &&
			  (diff.minutes || 0) <= 0 &&
			  (diff.seconds || 0) <= 0
			) {
			  showFormThenHideLater(now);
			  return;
			}
            requestAnimationFrame(updateCountdown);
          }

          // Start
          // Start — set initial visibility based on persisted state to avoid flicker
			const nowStart = nowInZone();
			const storedHideAtStart = localStorage.getItem(LS_KEY);
			const storedStatusStart = localStorage.getItem(LS_KEY + "_status");

			if (
				storedStatusStart === "open" &&
				storedHideAtStart &&
				nowStart < DateTime.fromISO(storedHideAtStart)
			) {
				if (formEl) formEl.style.display = 'block';
				if (wrapEl) wrapEl.style.display = 'none';
			} else {
				if (formEl) formEl.style.display = 'none';
				if (wrapEl) wrapEl.style.display = 'block';
			}
			requestAnimationFrame(updateCountdown);
        });
      })();
    </script>
  </div>
  <?php
  return ob_get_clean();
}
