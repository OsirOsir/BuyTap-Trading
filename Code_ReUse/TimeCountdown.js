<!-- Luxon Library -->
<link href="https://fonts.googleapis.com/css2?family=Oswald:wght@400;700&display=swap" rel="stylesheet">

<script src="https://cdn.jsdelivr.net/npm/luxon@3.3.0/build/global/luxon.min.js"></script>

<div id="custom-countdown">
  <h2>Next Token Sale Starts In:</h2>
</div>

<div id="custom-countdown">
  <div id="timer">
    <div class="time-block">
      <div class="time-number" id="hours">00</div>
      <div class="time-label">Hours</div>
    </div>
    <div class="time-block">
      <div class="time-number" id="minutes">00</div>
      <div class="time-label">Minutes</div>
    </div>
    <div class="time-block">
      <div class="time-number" id="seconds">00</div>
      <div class="time-label">Seconds</div>
    </div>
  </div>
</div>


<style>
  #custom-countdown {
    text-align: center;
    padding: 20px;
  }

  #timer {
    display: flex;
    justify-content: center;
    gap: 20px;
    align-items: center;
  }

  .time-block {
    text-align: center;
  }

  .time-number {
    font-size: 80px;
    font-family: 'Oswald', sans-serif; /* <- Tall font her*/
    font-weight: bold;
    color: #ff2c77;
  }

  .time-label {
    font-size: 15px;
    margin-top: 40px;
    color: white;
  }
</style>


<!-- Luxon Countdown Script -->
<script>
  document.addEventListener("DOMContentLoaded", function () {
    const DateTime = luxon.DateTime;
    const formEl = document.getElementById("buy-form");

    const hoursEl = document.getElementById("hours");
    const minutesEl = document.getElementById("minutes");
    const secondsEl = document.getElementById("seconds");

    function getNextDropTime() {
      const now = DateTime.now().setZone("Africa/Nairobi");
      const today9am = now.set({ hour: 9, minute: 0, second: 0, millisecond: 0 });
      const today7pm = now.set({ hour: 19, minute: 0, second: 0, millisecond: 0 });

      if (now < today9am) return today9am;
      if (now < today7pm) return today7pm;
      return today9am.plus({ days: 1 });
    }
    
    let targetTime = getNextDropTime();

    function updateCountdown() {
      const now = DateTime.now().setZone("Africa/Nairobi");
      const diff = targetTime.diff(now, ['hours', 'minutes', 'seconds']).toObject();

      const h = String(Math.floor(diff.hours)).padStart(2, '0');
      const m = String(Math.floor(diff.minutes)).padStart(2, '0');
      const s = String(Math.floor(diff.seconds)).padStart(2, '0');

      if (hoursEl) hoursEl.textContent = h;
      if (minutesEl) minutesEl.textContent = m;
      if (secondsEl) secondsEl.textContent = s;

    // ⏳ Check if form should still be showing after reload
const storedHideAt = localStorage.getItem("buyFormHideAt");
    if (storedHideAt) {
      const hideAtTime = DateTime.fromISO(storedHideAt);
      const now = DateTime.now().setZone("Africa/Nairobi");
    
      if (now < hideAtTime) {
        // Show form again and skip countdown
        if (formEl) formEl.style.display = "block";
        const countdownWrapper = document.getElementById("custom-countdown");
        if (countdownWrapper) countdownWrapper.style.display = "none";
    
        const msLeft = hideAtTime.diff(now).toMillis();
        setTimeout(() => {
          if (formEl) formEl.style.display = "none";
          if (countdownWrapper) countdownWrapper.style.display = "block";
          localStorage.removeItem("buyFormHideAt");
          targetTime = getNextDropTime();
          updateCountdown();
        }, msLeft);

    return;
  } else {
    // Expired
    localStorage.removeItem("buyFormHideAt");
  }
}

    if (diff.hours <= 0 && diff.minutes <= 0 && diff.seconds <= 0) {
  const countdownWrapper = document.getElementById("custom-countdown");

  if (formEl) formEl.style.display = "block";
  if (countdownWrapper) countdownWrapper.style.display = "none";

  // ✅ Save the time when form should disappear
  const hideAt = now.plus({ hours: 1 });
  localStorage.setItem("buyFormHideAt", hideAt.toISO());

  setTimeout(() => {
    if (formEl) formEl.style.display = "none";
    if (countdownWrapper) countdownWrapper.style.display = "block";
    localStorage.removeItem("buyFormHideAt");

    targetTime = getNextDropTime();
    updateCountdown();
  }, 3600000); // 1 hour   60000 1 minute test 

  return;
}

 else {
        requestAnimationFrame(updateCountdown);
      }
    }

    updateCountdown();
  });
</script>
