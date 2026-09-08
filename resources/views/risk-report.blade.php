<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Risk Report</title>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f5f5f3; color: #1a1a1a; padding: 2rem; }
    .container { max-width: 900px; margin: 0 auto; }
    .header { margin-bottom: 1.5rem; }
    .header h1 { font-size: 22px; font-weight: 500; color: #1a1a1a; }
    .header p { font-size: 13px; color: #888; margin-top: 4px; }

    /* premium glossy orb */
    .orb {
      width: 44px; height: 44px; border-radius: 50%; flex-shrink: 0;
      box-shadow:
        0 4px 10px rgba(0,0,0,0.18),
        inset 0 -4px 8px rgba(0,0,0,0.18),
        inset 0 3px 6px rgba(255,255,255,0.45);
    }
    .orb.sm { width: 12px; height: 12px; box-shadow: inset 0 -2px 4px rgba(0,0,0,0.2), inset 0 1px 2px rgba(255,255,255,0.5); }
    .orb.green  { background: radial-gradient(circle at 30% 30%, #7DFFB0 0%, #34C759 45%, #1E8E3E 100%); }
    .orb.yellow { background: radial-gradient(circle at 30% 30%, #FFF3A0 0%, #FFD60A 45%, #C9A300 100%); }
    .orb.orange { background: radial-gradient(circle at 30% 30%, #FFD199 0%, #FF9F0A 45%, #C76A00 100%); }
    .orb.red    { background: radial-gradient(circle at 30% 30%, #FF9A93 0%, #FF3B30 45%, #B71C1C 100%); }

    /* risk category cards */
    .risk-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
      gap: 14px;
      margin-bottom: 1.5rem;
    }
    .risk-card {
      background: #fff;
      border-radius: 14px;
      border: 0.5px solid rgba(0,0,0,0.08);
      padding: 1.1rem 1.25rem;
      display: flex;
      align-items: center;
      gap: 14px;
      cursor: pointer;
      transition: box-shadow .15s ease, transform .15s ease;
    }
    .risk-card:hover { box-shadow: 0 8px 24px rgba(0,0,0,0.08); transform: translateY(-2px); }
    .risk-card .info .label { font-size: 12px; color: #888; font-weight: 500; }
    .risk-card .info .count { font-size: 24px; font-weight: 600; color: #1a1a1a; line-height: 1.25; margin-top: 2px; }

    .chart-card { background: #fff; border-radius: 12px; border: 0.5px solid rgba(0,0,0,0.1); padding: 1.5rem; }
    .chart-wrap { position: relative; width: 100%; height: 320px; }
    .click-hint { font-size: 12px; color: #aaa; margin-top: 10px; text-align: center; }

    /* simple hover tooltip */
    .tooltip {
      position: fixed; background: #1a1a1a; color: #fff;
      border-radius: 6px; padding: 6px 10px; font-size: 12px;
      pointer-events: none; display: none; z-index: 9999;
      white-space: nowrap;
    }

    /* modal overlay */
    .overlay {
      position: fixed; inset: 0;
      background: rgba(0,0,0,0.45);
      z-index: 1000;
      display: none;
      align-items: center;
      justify-content: center;
    }
    .overlay.active { display: flex; }

    /* modal box */
    .modal {
      background: #fff;
      border-radius: 14px;
      width: 90%;
      max-width: 560px;
      max-height: 80vh;
      display: flex;
      flex-direction: column;
      overflow: hidden;
      box-shadow: 0 8px 40px rgba(0,0,0,0.18);
    }

    .modal-header {
      padding: 1.25rem 1.5rem 1rem;
      border-bottom: 0.5px solid rgba(0,0,0,0.08);
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-shrink: 0;
    }

    .modal-header-left { display: flex; align-items: center; gap: 10px; }
    .modal-header-left h2 { font-size: 16px; font-weight: 500; color: #1a1a1a; }
    .modal-header-left p  { font-size: 12px; color: #888; margin-top: 2px; }

    .modal-close {
      background: none; border: none; cursor: pointer;
      font-size: 20px; color: #888; line-height: 1;
      padding: 4px 8px; border-radius: 6px;
    }
    .modal-close:hover { background: #f0f0ee; color: #1a1a1a; }

    .modal-body {
      overflow-y: auto;
      flex: 1;
      padding: 0.5rem 0;
    }

    .modal-table {
      width: 100%;
      border-collapse: collapse;
      font-size: 13px;
    }

    .modal-table thead th {
      position: sticky;
      top: 0;
      background: #fff;
      padding: 8px 1.5rem;
      text-align: left;
      font-weight: 500;
      font-size: 12px;
      color: #888;
      border-bottom: 0.5px solid rgba(0,0,0,0.08);
    }

    .modal-table tbody tr:hover { background: #f9f9f7; }

    .modal-table tbody td {
      padding: 10px 1.5rem;
      border-bottom: 0.5px solid rgba(0,0,0,0.05);
      color: #1a1a1a;
    }

    .modal-table tbody tr:last-child td { border-bottom: none; }

    .error { font-size: 13px; color: #c0392b; margin-top: 1rem; }
  </style>
</head>
<body>

<div class="container">
  <div class="header">
    <h1>Risk profile summary</h1>
    <p id="sub">Fetching data…</p>
  </div>

  <div class="risk-grid" id="riskGrid"></div>

  <div class="chart-card">
    <div class="chart-wrap">
      <canvas id="riskChart" role="img" aria-label="Bar chart showing borrower count per risk category"></canvas>
    </div>
    <p class="click-hint">Click a card or bar to view borrower details</p>
  </div>
</div>

<!-- hover tooltip -->
<div class="tooltip" id="tooltip"></div>

<!-- modal -->
<div class="overlay" id="overlay">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-header-left">
        <span class="orb sm" id="modal-orb"></span>
        <div>
          <h2 id="modal-title"></h2>
          <p id="modal-sub"></p>
        </div>
      </div>
      <button class="modal-close" id="modal-close" aria-label="Close">✕</button>
    </div>
    <div class="modal-body">
      <table class="modal-table">
        <thead>
          <tr>
            <th>#</th>
            <th>Borrower ID</th>
            <th>Customer Name</th>
            <th>Loan Amount</th>
          </tr>
        </thead>
        <tbody id="modal-rows"></tbody>
      </table>
    </div>
  </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.js"></script>
<script>
  const API_URL = 'http://13.205.48.55/api/risk-profiles';

  const STOPS = {
    green:  ['#7DFFB0','#34C759','#1E8E3E'],
    yellow: ['#FFF3A0','#FFD60A','#C9A300'],
    orange: ['#FFD199','#FF9F0A','#C76A00'],
    red:    ['#FF9A93','#FF3B30','#B71C1C'],
  };

  const ORB_CLASS = {
    'Low Risk':            'green',
    'Medium Risk':         'yellow',
    'High Risk':           'orange',
    'Expected Default':    'red',
    'Expected to Default': 'red',
  };

  function orbClassFor(label) {
    if (ORB_CLASS[label]) return ORB_CLASS[label];
    const l = label.toLowerCase();
    if (l.includes('low'))     return 'green';
    if (l.includes('medium'))  return 'yellow';
    if (l.includes('high'))    return 'orange';
    if (l.includes('default')) return 'red';
    return 'green';
  }

// Per-bar radial gradient using the bar's own geometry, so each bar
  // gets a sphere-style highlight matching the orb cards.
  function radialFill(ctx, element, stops) {
    if (!element) return stops[1];
    const { x, y, base, width } = element.getProps(['x', 'y', 'base', 'width'], true);
    const left = x - width / 2;
    const top = y;
    const h = base - top;
    if (!isFinite(h) || h <= 0) return stops[1];
    const cx = x;
    const cy = top + h / 2;
    const hx = left + width * 0.3;
    const hy = top + h * 0.3;
    const r = Math.max(width, h) * 0.75;
    const g = ctx.createRadialGradient(hx, hy, 0, cx, cy, r);
    g.addColorStop(0, stops[0]);
    g.addColorStop(0.45, stops[1]);
    g.addColorStop(1, stops[2]);
    return g;
  }

  // Glossy top highlight overlay, drawn after bars to mimic the orb's
  // glass-sphere sheen.
  const glossPlugin = {
    id: 'gloss',
    afterDatasetsDraw(chart) {
      const { ctx } = chart;
      const meta = chart.getDatasetMeta(0);
      meta.data.forEach((bar) => {
        const { x, y, base, width } = bar.getProps(['x', 'y', 'base', 'width'], true);
        const left = x - width / 2;
        const top = y;
        const h = base - top;
        if (h <= 0) return;
        const radius = Math.min(8, width / 2);
        ctx.save();
        ctx.beginPath();
        ctx.moveTo(left + radius, top);
        ctx.lineTo(left + width - radius, top);
        ctx.arcTo(left + width, top, left + width, top + radius, radius);
        ctx.lineTo(left + width, top + h * 0.55);
        ctx.lineTo(left, top + h * 0.55);
        ctx.lineTo(left, top + radius);
        ctx.arcTo(left, top, left + radius, top, radius);
        ctx.closePath();
        const grad = ctx.createLinearGradient(0, top, 0, top + h * 0.55);
        grad.addColorStop(0, 'rgba(255,255,255,0.35)');
        grad.addColorStop(1, 'rgba(255,255,255,0)');
        ctx.fillStyle = grad;
        ctx.fill();
        ctx.restore();
      });
    }
  };

  const tooltip   = document.getElementById('tooltip');
  const overlay   = document.getElementById('overlay');
  const modalRows = document.getElementById('modal-rows');
  const modalOrb  = document.getElementById('modal-orb');
  const subEl     = document.getElementById('sub');

  function openModal(label, count, data) {
    modalOrb.className = 'orb sm ' + orbClassFor(label);
    document.getElementById('modal-title').textContent = label;
    document.getElementById('modal-sub').textContent   = count + ' borrower' + (count !== 1 ? 's' : '');
    modalRows.innerHTML = '';

    data.forEach((r, i) => {
      const amt = r['Loan Amount (Final)']
        ? Number(r['Loan Amount (Final)']).toLocaleString('en-IN', { style: 'currency', currency: 'INR', maximumFractionDigits: 0 })
        : '—';
      modalRows.insertAdjacentHTML('beforeend', `
        <tr>
          <td style="color:#aaa">${i + 1}</td>
          <td>${r['Borrower ID'] || '—'}</td>
          <td>${r['Customer Name'] || '—'}</td>
          <td>${amt}</td>
        </tr>
      `);
    });

    overlay.classList.add('active');
  }

  function closeModal() {
    overlay.classList.remove('active');
  }

  document.getElementById('modal-close').addEventListener('click', closeModal);
  overlay.addEventListener('click', (e) => { if (e.target === overlay) closeModal(); });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeModal(); });

  async function init() {
    let raw;
    try {
      const res = await fetch(API_URL);
      if (!res.ok) throw new Error('HTTP ' + res.status);
      const json = await res.json();
      raw = json.data;
    } catch (e) {
      subEl.style.color = '#c0392b';
      subEl.textContent = 'Could not load data: ' + e.message;
      return;
    }

    const labels       = Object.keys(raw);
    const counts       = labels.map(l => raw[l].count);
    const total        = counts.reduce((a, b) => a + b, 0);
    const classes      = labels.map(l => orbClassFor(l));
    const stopsByLabel = classes.map(c => STOPS[c]);
    const borderColors = stopsByLabel.map(s => s[2]);

    subEl.textContent = total + ' total borrowers across ' + labels.length + ' risk categories';

    // risk category cards
    const gridEl = document.getElementById('riskGrid');
    labels.forEach((l, i) => {
      const card = document.createElement('div');
      card.className = 'risk-card';
      card.innerHTML = `
        <span class="orb ${classes[i]}"></span>
        <div class="info">
          <div class="label">${l}</div>
          <div class="count">${counts[i]}</div>
        </div>
      `;
      card.addEventListener('click', () => openModal(l, counts[i], raw[l].data || []));
      gridEl.appendChild(card);
    });

    const chart = new Chart(document.getElementById('riskChart'), {
      type: 'bar',
      plugins: [glossPlugin],
      data: {
        labels,
        datasets: [{
          label: 'Borrower count',
          data: counts,
          backgroundColor: (context) => radialFill(context.chart.ctx, context.element, stopsByLabel[context.dataIndex]),
          borderColor: borderColors,
          borderWidth: 1.5,
          borderRadius: 8,
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false }, tooltip: { enabled: false } },
        scales: {
          y: { beginAtZero: true, ticks: { stepSize: 1, color: '#888' }, grid: { color: 'rgba(0,0,0,0.06)' } },
          x: { ticks: { color: '#888' }, grid: { display: false } }
        },
        onHover: (event, elements) => {
          event.native.target.style.cursor = elements.length ? 'pointer' : 'default';
        }
      }
    });

    const canvas = document.getElementById('riskChart');

    // hover — simple label + count tooltip
    canvas.addEventListener('mousemove', (e) => {
      const pts = chart.getElementsAtEventForMode(e, 'nearest', { intersect: true }, false);
      if (!pts.length) { tooltip.style.display = 'none'; return; }
      const idx = pts[0].index;
      tooltip.textContent    = labels[idx] + ': ' + counts[idx] + ' borrowers';
      tooltip.style.display  = 'block';
      tooltip.style.left     = (e.clientX + 12) + 'px';
      tooltip.style.top      = (e.clientY - 28) + 'px';
    });

    canvas.addEventListener('mouseleave', () => { tooltip.style.display = 'none'; });

    // click — open modal
    canvas.addEventListener('click', (e) => {
      const pts = chart.getElementsAtEventForMode(e, 'nearest', { intersect: true }, false);
      if (!pts.length) return;
      const idx   = pts[0].index;
      const label = labels[idx];
      openModal(label, counts[idx], raw[label].data || []);
    });
  }

  init();
</script>
</body>
</html>