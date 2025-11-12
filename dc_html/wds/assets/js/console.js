// console.js — UTC 时钟 + 健康探针 + tap-picker + 推荐时刻（查库判断是否已拉取）
document.addEventListener('DOMContentLoaded', async () => {
  // UTC 时钟
  const clock = document.querySelector('#utcClock');
  if (clock) {
    const tick = () => { clock.textContent = new Date().toISOString().replace('T',' ').replace('Z',' UTC'); };
    setInterval(tick, 1000); tick();
  }

  // 健康探针
  const ping = document.querySelector('#pingStatus');
  if (ping) {
    try {
      const r = await fetch('/health/status.php', { cache: 'no-store' });
      ping.textContent = r.ok ? '在线' : '异常';
    } catch { ping.textContent = '异常'; }
  }

  // tap-picker：整块点击弹出
  document.querySelectorAll('.tap-picker').forEach(box => {
    const inp = box.querySelector('input[type="date"],input[type="time"],input[type="datetime-local"],input[type="month"]');
    if (!inp) return;
    box.style.cursor = 'pointer';
    box.addEventListener('click', () => {
      if (typeof inp.showPicker === 'function') { try { inp.showPicker(); return; } catch {} }
      inp.focus();
      try { inp.dispatchEvent(new KeyboardEvent('keydown', {key:'Enter', bubbles:true})); } catch {}
    });
  });

  // ===== 推荐时刻（基于 DB 的已拉取判断） =====
  const recText = document.querySelector('#recStatus');
  const recTimesBox = document.querySelector('#recTimes');
  const SHOW_ORDER = ['07:15','11:15','13:15','19:15','01:15'];

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
      const slots = j.slots; // [{hm, target_epoch_ms, done}, ...]

      // 最近的时段（绝对时间差最小）
      let nearIdx = 0, nearAbs = Infinity;
      slots.forEach((s, i) => {
        const d = Math.abs(s.target_epoch_ms - now);
        if (d < nearAbs) { nearAbs = d; nearIdx = i; }
      });
      const near = slots[nearIdx];

      // 下一档：从“时间差为正的候选”里找最小；若都为过去，则取最小正差（+24h）
      let nextIdx = nearIdx, bestDiff = Infinity;
      slots.forEach((s, i) => {
        let d = s.target_epoch_ms - now;
        if (d <= 0) d += 24*60*60*1000; // 跨午夜
        if (d < bestDiff) { bestDiff = d; nextIdx = i; }
      });
      const next = slots[nextIdx];

      // 是否在窗口内（±30min）
      const inWindow = nearAbs <= 30*60*1000;

      let hotHM = SHOW_ORDER[nearIdx];
      let msg = '';

      if (!near.done) {
        // 最近档未拉取：高亮最近档
        hotHM = SHOW_ORDER[nearIdx];
        if (inWindow) {
          msg = '✅ 现在在建议窗口（±30 分钟内），且本时段尚未执行，建议立即拉取预报。';
        } else {
          const mins = Math.round((Math.abs(near.target_epoch_ms - now))/60000);
          const hrs  = Math.floor(mins/60), mm = mins%60;
          msg = `⏳ 建议在 ${hotHM} 拉取（约 ${hrs>0?hrs+' 小时 ':''}${pad2(mm)} 分钟后）。`;
        }
      } else {
        // 最近档已拉取：高亮“下一档”
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
