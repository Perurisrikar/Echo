document.addEventListener('DOMContentLoaded', () => {
  const dataEl = document.getElementById('predictor-data');
  if (!dataEl) return; // attendance data wasn't available; predictor isn't on the page

  const { total, pct, classesPerDay, holidays, workdays } = JSON.parse(dataEl.textContent);
  const WORKDAYS = new Set(workdays || []);
  const TOTAL = total, PCT = pct, CLASSES_PER_DAY = classesPerDay;
  const HOLIDAYS = new Set(holidays);

  const untilInput = document.getElementById('untilDate');
  const missInput = document.getElementById('missDays');
  const calcBtn = document.getElementById('calcBtn');
  const note = document.getElementById('workingDaysNote');
  const thresholdInput = document.getElementById('threshold');
  const pctBar = document.getElementById('pctBar');
  const statusPill = document.getElementById('statusPill');
  const predictOut = document.getElementById('predictOut');

  function statusFor(pct, threshold){
    if(pct >= threshold + 5) return {label:'HEALTHY', color:'var(--matcha-deep)'};
    if(pct >= threshold) return {label:'AT THE LINE', color:'var(--ochre)'};
    return {label:'BELOW THRESHOLD', color:'var(--brick)'};
  }

  function getThreshold(){
    let t = 75;
    try{ const v = localStorage.getItem('att_threshold'); if(v) t = parseFloat(v); }catch(e){}
    thresholdInput.value = t;
    return t;
  }

  function renderStatus(){
    const threshold = getThreshold();
    const st = statusFor(PCT, threshold);
    pctBar.style.background = st.color;
    statusPill.textContent = st.label;
    statusPill.style.background = st.color;
  }

  thresholdInput.addEventListener('change', renderStatus);

  function toDateStr(d){
    const tz = d.getTimezoneOffset();
    return new Date(d.getTime() - tz * 60000).toISOString().slice(0, 10);
  }

  function countWorkingDays(untilStr){
    const today = new Date(); today.setHours(0,0,0,0);
    const until = new Date(untilStr + 'T00:00:00');
    let count = 0;
    const d = new Date(today); d.setDate(d.getDate() + 1);
    while (d <= until) {
      const dow = d.getDay();
      const ds = toDateStr(d);
      const isOff = (dow === 0 || dow === 6) && !WORKDAYS.has(ds);
      if (!isOff && !HOLIDAYS.has(ds)) count++;
      d.setDate(d.getDate() + 1);
    }
    return count;
  }

  // Live note as the date changes — purely informational, nothing is
  // gated on this actually firing (some mobile browsers are flaky about
  // when 'change' fires on <input type="date">).
  function updateNote(){
    if (!untilInput.value) { note.textContent = ''; return; }
    const days = countWorkingDays(untilInput.value);
    if (days <= 0) {
      note.textContent = 'That date has no working days between now and then — pick a date further out.';
      return;
    }
    note.innerHTML = `That's <b style="color:var(--ink)">${days}</b> working day${days===1?'':'s'}
      — about <b style="color:var(--ink)">${days * CLASSES_PER_DAY}</b> classes at ${CLASSES_PER_DAY}/day.`;
  }
  untilInput.addEventListener('change', updateNote);
  untilInput.addEventListener('input', updateNote);

  // Calculate is always clickable; everything it needs is read fresh
  // from the inputs at click time, so nothing can leave it stuck disabled.
  calcBtn.addEventListener('click', () => {
    predictOut.classList.remove('hidden');

    if (!untilInput.value) {
      predictOut.innerHTML = '<div style="color:var(--brick);font-size:.85rem">Pick a date first.</div>';
      return;
    }
    const workingDaysCount = countWorkingDays(untilInput.value);
    if (workingDaysCount <= 0) {
      predictOut.innerHTML = '<div style="color:var(--brick);font-size:.85rem">That date has no working days between now and then.</div>';
      return;
    }
    // Blank days-to-miss just means "if I don't miss any".
    const missDaysRaw = missInput.value.trim() === '' ? 0 : parseInt(missInput.value, 10);
    if (isNaN(missDaysRaw) || missDaysRaw < 0 || missDaysRaw > workingDaysCount) {
      predictOut.innerHTML = `<div style="color:var(--brick);font-size:.85rem">Enter a number of days between 0 and ${workingDaysCount}.</div>`;
      return;
    }

    const upcoming = workingDaysCount * CLASSES_PER_DAY;
    const miss = missDaysRaw * CLASSES_PER_DAY;
    const attendedSoFar = Math.round(TOTAL * PCT / 100);
    const newTotal = TOTAL + upcoming;
    const newAttended = attendedSoFar + (upcoming - miss);
    const projected = newTotal > 0 ? (newAttended / newTotal * 100) : PCT;
    const threshold = getThreshold();
    const st = statusFor(projected, threshold);

    let maxMissDays = 0;
    for (let m = 0; m <= workingDaysCount; m++) {
      const missC = m * CLASSES_PER_DAY;
      const proj = (attendedSoFar + (upcoming - missC)) / newTotal * 100;
      if (proj >= threshold) maxMissDays = m; else break;
    }

    let warn = '';
    if (projected < threshold) {
      warn = `<div style="color:var(--brick);font-weight:600;margin-top:8px;">This drops you below the ${threshold}% requirement.</div>`;
    } else if (projected < threshold + 5) {
      warn = `<div style="color:var(--ochre);font-weight:600;margin-top:8px;">You'd stay above ${threshold}%, but with little room to spare.</div>`;
    }
    predictOut.innerHTML = `
      <div class="pct-label">Projected attendance</div>
      <div class="big mono" style="color:${st.color}">${projected.toFixed(2)}%</div>
      <div style="color:var(--ash);font-size:.8rem;margin-top:4px;">out of ${newTotal} classes held by then</div>
      ${warn}
      <div style="color:var(--ash);font-size:.8rem;margin-top:10px;">
        Of the ${workingDaysCount} upcoming working days, you can miss up to <b style="color:var(--ink)">${maxMissDays}</b> and stay at or above ${threshold}%.
      </div>`;
  });

  untilInput.min = toDateStr(new Date());
  renderStatus();
});
