const { chromium } = require('playwright'); const fs = require('fs');
(async () => {
  const raw = fs.readFileSync('/tmp/evm-cookie.txt','utf8').trim(); const [name, ...rest] = raw.split('=');
  const b = await chromium.launch();
  const ctx = await b.newContext({ viewport: { width: 1440, height: 1050 } });
  await ctx.addCookies([{ name, value: rest.join('='), domain: 'dekadentallasers.com', path: '/' }]);
  const p = await ctx.newPage(); const errs = []; p.on('pageerror', e => errs.push(e.message.slice(0,90)));
  let ok = false, r = null;
  for (let i = 0; i < 12 && !ok; i++) {
    await p.goto('https://dekadentallasers.com/events-manager/?event_action=edit&event_id=7909&cb=' + Date.now(), { waitUntil: 'networkidle', timeout: 60000 });
    await p.click('[data-step-nav="5"]'); await p.waitForTimeout(400);
    await p.click('.anchor-event-email-open[data-email-type="reminder"]');
    await p.waitForTimeout(2200);
    await p.click('[data-email-modal="reminder"] [data-email-view="html"]');
    await p.waitForTimeout(800);
    r = await p.evaluate(() => {
      const s = document.querySelector('[data-email-modal="reminder"] .anchor-event-email-source');
      const lines = (s.value||'').split('\n');
      return { lines: lines.length, longest: Math.max.apply(null, lines.map(l=>l.length)),
               bigGaps: (s.value||'').match(/ {6,}/g) ? (s.value.match(/ {6,}/g).length) : 0,
               sample: lines.filter(l => l.includes('{')).slice(0,3) };
    });
    ok = r.bigGaps === 0;
    if (!ok) await p.waitForTimeout(6000);
  }
  console.log(JSON.stringify({lines:r.lines, longest:r.longest, runsOf6PlusSpaces:r.bigGaps}));
  console.log(r.sample.join('\n'));
  console.log('JS errors:', errs.length ? errs.slice(0,2) : 'none');
  await b.close();
})();
