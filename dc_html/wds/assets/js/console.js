// console.js — status, tap-picker, recommended slots (DB-checked)
document.addEventListener('DOMContentLoaded', async () => {
  const clock = document.querySelector('#utcClock');
  if (clock) {
    const tick = () => { clock.textContent = new Date().toISOString().replace('T',' ').replace('Z',' UTC'); };
    setInterval(tick, 1000); tick();
  }

  const ping = document.querySelector('#pingStatus');
  if (ping) {
    try {
      const r = await fetch('/health/status.php', { cache: 'no-store' });
      ping.textContent = r.ok ? '在线' : '异常';
    } catch { ping.textContent = '异常'; }
  }

  document.querySelectorAll('.tap-picker').forEach(box => {
    const inp = box.querySelector('input[type=\"date\"],input[type=\"time\"],input[type=\"datetime-local\"],input[type=\"month\"]');
    if (!inp) return;
    box.style.cursor = 'pointer';
    
    // NEW: Add a click listener directly to the input to ensure it opens the picker 
    // when clicked, and stop propagation to prevent the 'box' listener from re-firing.
    inp.addEventListener('click', (e) => {
      // Prevents the click from bubbling to the 'box' listener below
      e.stopPropagation();
      if (typeof inp.showPicker === 'function') { try { inp.showPicker(); return; } catch {} }
    });

    // Existing: Handle click on the container (for clicks on the label text and padding)
    box.addEventListener('click', () => {
      if (typeof inp.showPicker === 'function') { try { inp.showPicker(); return; } catch {} }
      inp.focus(); try { inp.dispatchEvent(new KeyboardEvent('keydown', {key:'Enter', bubbles:true})); } catch {}
    });
  });

  const recText = document.querySelector('#recStatus');
  const recTimesBox = document.querySelector('#recTimes');
  const SHOW_ORDER = ['01:15','11:15','13:15','16:15','19:15'];

  function renderPills(hotHM) {
    if (!recTimesBox) return;
    recTimesBox.innerHTML = '';
    SHOW_ORDER.forEach(hm => {
      const s = document.createElement('span');
      s.className = 'pill' + (hm===hotHM ? ' pill-hot' : '');
      s.textContent = hm;
      recTimesBox.appendChild(s);
    });
  }
  const pad2 = (n)=> n<10?('0'+n):String(n);

  async function updateRec() {
    if (!recText || !recTimesBox) return;
    try {
      const rsp = await fetch('/wds/console/rec_status.php', { cache: 'no-store' });
      if (!rsp.ok) throw new Error('rec_status fetch failed');
      const j = await rsp.json();

      const now = j.now_epoch_ms;
      const slots = j.slots;

      let nearIdx = 0, nearAbs = Infinity;
      slots.forEach((s, i) => {
        const d = Math.abs(s.target_epoch_ms - now);
        if (d < nearAbs) { nearAbs = d; nearIdx = i; }
      });
      let nextIdx = nearIdx, bestDiff = Infinity;
      slots.forEach((s, i) => {
        let d = s.target_epoch_ms - now;
        if (d <= 0) d += 24*60*60*1000;
        if (d < bestDiff) { bestDiff = d; nextIdx = i; }
      });

      const near = slots[nearIdx];
      const next = slots[nextIdx];
      const inWindow = nearAbs <= 30*60*1000;

      let hotHM = SHOW_ORDER[nearIdx];
      let msg = '';

      if (!near.done) {
        hotHM = SHOW_ORDER[nearIdx];
        if (inWindow) {
          msg = '✅ 现在在建议窗口（±30 分钟内），且本时段尚未执行，建议立即拉取预报。';
        } else {
          const mins = Math.round((Math.abs(near.target_epoch_ms - now))/60000);
          const hrs  = Math.floor(mins/60), mm = mins%60;
          msg = `⏳ 建议在 ${hotHM} 拉取（约 ${hrs>0?hrs+' 小时 ':''}${pad2(mm)} 分钟后）。`;
        }
      } else {
        hotHM = SHOW_ORDER[nextIdx];
        const mins = Math.round(bestDiff/60000);
        const hrs  = Math.floor(mins/60), mm = mins%60;
        msg = `✅ 本时段已执行；建议在 ${hotHM} 再拉取（约 ${hrs>0?hrs+' 小时 ':''}${pad2(mm)} 分钟后）。`;
      }

      renderPills(hotHM);
      recText.textContent = msg;
    } catch (e) {
      recText.textContent = '状态获取失败';
    }
  }

  updateRec();
  setInterval(updateRec, 30 * 1000);
});