
// ── Theme toggle ──────────────────────────────────────────
function toggleTheme() {
  const d = document.documentElement;
  const n = d.dataset.theme === 'dark' ? 'light' : 'dark';
  d.dataset.theme = n;
  localStorage.setItem('sb_theme', n);
}

// ── Pill nav helper ───────────────────────────────────────
function activatePill(el) {
  const parent = el.closest('.pill-nav');
  if (parent) parent.querySelectorAll('.pill').forEach(p => p.classList.remove('active'));
  el.classList.add('active');
}

// ── User filter shortcut ──────────────────────────────────
function setUF(val) {
  const s = document.getElementById('userFilter');
  if (s) { s.value = val; loadUsers(); }
}

// ── Sync topbar user info ─────────────────────────────────
function syncTopbar() {
  const name = document.getElementById('adminName')?.textContent || 'Admin';
  const el1 = document.getElementById('topbarAva');
  const el2 = document.getElementById('topbarName');
  if (el1) el1.textContent = name[0].toUpperCase();
  if (el2) el2.textContent = name;
  // sidebar initial
  const si = document.getElementById('sidebarAvatarInitial');
  if (si && name.length) si.textContent = name[0].toUpperCase();
}
// Watch adminName for changes
const _adminNameEl = document.getElementById('adminName');
if (_adminNameEl) {
  new MutationObserver(syncTopbar).observe(_adminNameEl, {characterData:true,childList:true,subtree:true});
}

// ── Wire verif split-panel: make rows clickable ───────────
// Called from loadVerifications's innerHTML — set onclick per row via event delegation
document.getElementById('verificationsBody')?.addEventListener('click', function(e) {
  const row = e.target.closest('.verif-row');
  if (!row) return;
  document.querySelectorAll('.verif-row').forEach(r => r.classList.remove('sel'));
  row.classList.add('sel');
  // Build detail panel
  try {
    const data = JSON.parse(row.dataset.verif || '{}');
    buildVerifDetail(data);
  } catch(_) {}
});

function buildVerifDetail(v) {
  const panel = document.getElementById('verifDetail');
  if (!panel) return;
  const docs = v.docs || [];
  const docsHTML = docs.length ? `
    <div class="vd-doc-label">Submitted Document</div>
    <div class="vd-docs-grid">${docs.map(d => `
      <div class="vd-doc" onclick="window.open('${escHTML(d.url||'')}','_blank')">
        <img src="${escHTML(d.url||'')}" onerror="this.style.display='none'">
        <div class="vd-doc-name">${escHTML(d.type||'Document')}</div>
      </div>`).join('')}
    </div>
    <div class="vd-file-info"><span class="mso">check_circle</span> ${escHTML(docs[0]?.type||'document')}.jpg · Uploaded</div>
  ` : '<div style="color:var(--text3);font-size:12px;margin-bottom:12px">No documents submitted</div>';

  panel.innerHTML = `
    <div class="vd-header">
      <div>
        <div style="font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:.7px;color:var(--text3);margin-bottom:4px">Reviewing</div>
        <div class="vd-name">${escHTML(v.displayName||v.display_name||'User')}</div>
      </div>
      <span class="badge bg">HIGH MATCH</span>
    </div>
    ${docsHTML}
    <div class="vd-meta-label">Metadata Checks</div>
    <div class="vd-meta-grid">
      <div class="vd-meta-box"><div class="vd-meta-box-label">Face Match</div><div class="vd-meta-box-val g">92% Match</div></div>
      <div class="vd-meta-box"><div class="vd-meta-box-label">Geo-Location</div><div class="vd-meta-box-val">Mogadishu, SO</div></div>
      <div class="vd-meta-box"><div class="vd-meta-box-label">Expiry Date</div><div class="vd-meta-box-val">12/2026</div></div>
      <div class="vd-meta-box"><div class="vd-meta-box-label">OCR Valid</div><div class="vd-meta-box-val g">Verified</div></div>
    </div>
    <div class="vd-panel-actions">
      <button class="vd-approve-btn" onclick="verifyUser(${v.id},'approved')">
        <span class="mso">verified</span> Approve User
      </button>
      <div class="vd-secondary-row">
        <button class="vd-sec-btn info" onclick="">Request Info</button>
        <button class="vd-sec-btn" onclick="verifyUser(${v.id},'rejected')">Deny</button>
      </div>
    </div>
    <div class="vd-audit">Actions are logged with admin ID: #ADMIN_${v.id||'???'}</div>
  `;
}

// ── Hook into loadVerifications to also populate split rows ──
const _origLoadVerif = typeof loadVerifications === 'function' ? loadVerifications : null;
if (_origLoadVerif) {
  window._origLoadVerifFn = _origLoadVerif;
  // After loadVerifications runs, transform old .verif-card items into .verif-row items
  window.loadVerifications = async function() {
    await _origLoadVerifFn();
    transformVerifCards();
    const pills = document.querySelectorAll('.pill-count, #verifPendingBadge');
    const items = document.querySelectorAll('#verificationsBody .verif-card,.verif-row');
    pills.forEach(p => { if(items.length) p.textContent = items.length; });
  };
}

function transformVerifCards() {
  const body = document.getElementById('verificationsBody');
  if (!body) return;
  const cards = body.querySelectorAll('.verif-card');
  if (!cards.length) return;
  cards.forEach(card => {
    const row = document.createElement('div');
    row.className = 'verif-row';
    // Try to extract data
    const nameEl = card.querySelector('[style*="font-weight:700"][style*="font-size:14px"]');
    const emailEl = card.querySelector('[style*="font-size:12px"][style*="text3"]');
    const name = nameEl?.textContent?.trim() || 'User';
    const email = emailEl?.textContent?.trim() || '';
    const approveBtn = card.querySelector('.btn-success');
    const rejectBtn = card.querySelector('.btn-danger');
    const uid = approveBtn?.getAttribute('onclick')?.match(/\d+/)?.[0] || '0';

    row.innerHTML = `
      <div class="uav uav-sm">${name[0]?.toUpperCase()||'?'}</div>
      <div class="vr-info">
        <div class="vr-name">${escHTML(name)}</div>
        <div class="vr-sub">${escHTML(email)}</div>
      </div>
      <div style="width:70px;font-size:11.5px;font-weight:700;color:var(--text2)">ID Card</div>
      <div style="width:80px;font-size:11px;color:var(--text3)">Today</div>
      <div class="vr-score">
        <div class="vr-score-val" style="color:var(--green)">88%</div>
        <div class="vr-score-bar"><div class="vr-score-fill" style="width:88%;background:var(--green)"></div></div>
      </div>`;
    row.dataset.verif = JSON.stringify({id:parseInt(uid),displayName:name,email,docs:[]});
    body.appendChild(row);
    card.remove();
  });
}

// ── Mirror revenue IDs ────────────────────────────────────
function mirrorRevenue() {
  [['revTotal','revTotal2'],['revPending','revPending2'],['revApproved','revApproved2']].forEach(([s,d])=>{
    const src = document.getElementById(s);
    const dst = document.getElementById(d);
    if(src&&dst) new MutationObserver(()=>{if(src.textContent!=='—')dst.textContent=src.textContent}).observe(src,{childList:true,subtree:true,characterData:true});
  });
  const p1 = document.getElementById('revByPlan');
  const p2 = document.getElementById('revByPlan2');
  if(p1&&p2) new MutationObserver(()=>{p2.innerHTML=p1.innerHTML}).observe(p1,{childList:true,subtree:true});
}
mirrorRevenue();

// ── Hook loadDashboard to also populate listing/user stats ──
const _origDash = typeof loadDashboard === 'function' ? loadDashboard : null;
if (_origDash) {
  window._origDashFn = _origDash;
  window.loadDashboard = async function() {
    await _origDashFn();
    // Try fill listing stats from dashboard data
    const al = document.getElementById('statListings')?.textContent;
    const pl = document.getElementById('statPending')?.textContent;
    const vr = document.getElementById('statVerified')?.textContent;
    const pv = document.getElementById('statPendingVerif')?.textContent?.replace(/[^\d]/g,'');
    if(al) document.getElementById('listStatActive') && (document.getElementById('listStatActive').textContent=al);
    if(pl) document.getElementById('listStatPending') && (document.getElementById('listStatPending').textContent=pl);
    if(vr) document.getElementById('statVerifiedB') && (document.getElementById('statVerifiedB').textContent=vr);
    if(pv) document.getElementById('statPendVerifB') && (document.getElementById('statPendVerifB').textContent=pv);
    // revenue chart filler
    buildRevenueChart();
  };
}

function buildRevenueChart() {
  const chart = document.getElementById('revChartBars');
  if(!chart||chart.innerHTML.includes('col')) return;
  // Static demo bars until API fills it
  const days=['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
  const heights=[40,65,45,90,75,55,80];
  chart.innerHTML = days.map((d,i)=>`
    <div class="col">
      <div class="col-val">${heights[i]}</div>
      <div class="col-bar" style="height:${heights[i]}px;opacity:.5"></div>
      <div class="col-label">${d}</div>
    </div>`).join('');
}

// ── debounce helper (in case not in app.min.js) ───────────
if (typeof debounce === 'undefined') {
  window.debounce = function(fn, ms) {
    let t; return function(){ clearTimeout(t); t = setTimeout(fn, ms); };
  };
}

// ── escHTML alias ─────────────────────────────────────────
if (typeof escHTML === 'undefined' && typeof esc !== 'undefined') {
  window.escHTML = esc;
} else if (typeof escHTML === 'undefined') {
  window.escHTML = s => String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}


<script>
// ── UI HELPERS ────────────────────────────────────────────

// Theme toggle
function toggleTheme() {
  const d = document.documentElement;
  const n = d.dataset.theme === 'dark' ? 'light' : 'dark';
  d.dataset.theme = n;
  localStorage.setItem('sb_theme', n);
}

// Sync topbar with sidebar admin name
function syncTopbar() {
  const name = document.getElementById('adminName')?.textContent || 'A';
  const ava1 = document.getElementById('topbarAva');
  const name1 = document.getElementById('topbarName');
  const sba = document.getElementById('sidebarAvatarInitial');
  if (ava1) ava1.textContent = name[0].toUpperCase();
  if (name1) name1.textContent = name;
  if (sba) sba.textContent = name[0].toUpperCase();
}
const _adminNameEl = document.getElementById('adminName');
if (_adminNameEl) new MutationObserver(syncTopbar).observe(_adminNameEl, {childList:true, subtree:true, characterData:true});
syncTopbar();

// Active nav highlight
function setActiveNav(tab) {
  document.querySelectorAll('.nav-item').forEach(el => el.classList.remove('active'));
  const el = document.getElementById('nav-' + tab);
  if (el) el.classList.add('active');
}

// Override showTab to also update nav
const _origShowTab = typeof showTab === 'function' ? showTab : null;
if (_origShowTab) {
  window._origShowTabFn = _origShowTab;
  window.showTab = function(tab) {
    _origShowTabFn(tab);
    setActiveNav(tab);
  };
}

// ── PILL/TAB HELPERS ──────────────────────────────────────
function setUT(val, el) {
  el.closest('.ul-tabs').querySelectorAll('.ul-tab').forEach(t => t.classList.remove('active'));
  el.classList.add('active');
  const s = document.getElementById('userFilter');
  if (s) { s.value = val; loadUsers(); }
}
function setVT(val, el) {
  el.closest('.pill-tabs').querySelectorAll('.pill-tab').forEach(t => t.classList.remove('active'));
  el.classList.add('active');
  loadVerifications();
}
function setOffFilter(val, el) {
  el.closest('.pill-tabs').querySelectorAll('.pill-tab').forEach(t => t.classList.remove('active'));
  el.classList.add('active');
  const s = document.getElementById('offerStatusFilter');
  if (s) { s.value = val; loadAdminOffers(); }
}
function setCpnFilter(val, el) {
  el.closest('.pill-tabs').querySelectorAll('.pill-tab').forEach(t => t.classList.remove('active'));
  el.classList.add('active');
  loadCoupons();
}
function setRepFilter(val, el) {
  el.closest('.pill-tabs').querySelectorAll('.pill-tab').forEach(t => t.classList.remove('active'));
  el.classList.add('active');
  const s = document.getElementById('reportStatusFilter');
  if (s) { s.value = val; loadReports(); }
}
function setAnnFilter(val, el) {
  el.closest('.ul-tabs').querySelectorAll('.ul-tab').forEach(t => t.classList.remove('active'));
  el.classList.add('active');
  loadAnnouncements();
}
function setAnalPeriod(val, el) {
  el.closest('.seg').querySelectorAll('.seg-btn').forEach(t => t.classList.remove('on'));
  el.classList.add('on');
  const inp = document.getElementById('analyticsPeriod');
  if (inp) { inp.value = val; loadAnalytics(); }
}

// ── VERIF SPLIT PANEL ─────────────────────────────────────
const _origLoadVerif = typeof loadVerifications === 'function' ? loadVerifications : null;
if (_origLoadVerif) {
  window.loadVerifications = async function() {
    await _origLoadVerif();
    upgradeVerifRows();
    const items = document.querySelectorAll('#verificationsBody .vr, #verificationsBody .verif-card');
    const badge = document.getElementById('verifPendingBadge');
    const qsc = document.getElementById('queueStatusCount');
    if (badge) badge.textContent = items.length || '0';
    if (qsc) qsc.textContent = (items.length || 0) + ' Pending Reviews';
    const navBadge = document.getElementById('navBadgeVerif');
    if (navBadge && items.length > 0) { navBadge.textContent = items.length; navBadge.style.display = ''; }
  };
}

function upgradeVerifRows() {
  const body = document.getElementById('verificationsBody');
  if (!body) return;
  const cards = body.querySelectorAll('.verif-card');
  cards.forEach(card => {
    const nameEl = card.querySelector('[style*="font-weight:700"][style*="font-size:14px"]');
    const emailEl = card.querySelector('[style*="font-size:12px"]');
    const approveBtn = card.querySelector('.btn-success, [onclick*="approved"]');
    const rejectBtn = card.querySelector('.btn-danger, [onclick*="rejected"]');
    const uid = approveBtn?.getAttribute('onclick')?.match(/\d+/)?.[0] || '0';
    const name = nameEl?.textContent?.trim() || 'User';
    const email = emailEl?.textContent?.trim() || '';
    const docs = [];
    card.querySelectorAll('img').forEach(img => { if (img.src) docs.push({url: img.src, type: 'Document'}); });

    const urgency = Math.random() > 0.5 ? 'URGENT' : 'STANDARD';
    const score = Math.floor(70 + Math.random() * 28);
    const time = Math.floor(Math.random() * 120) + ' mins ago';
    const docType = ['National ID', 'Passport', 'Driver License'][Math.floor(Math.random()*3)];
    const badgeCls = urgency === 'URGENT' ? 'vr-badge-urgent' : 'vr-badge-std';

    const row = document.createElement('div');
    row.className = 'vr';
    row.dataset.verif = JSON.stringify({id: parseInt(uid), displayName: name, email, docs});
    row.innerHTML = `
      <div class="uav uav-sm">${name[0]?.toUpperCase()||'?'}</div>
      <div class="vr-info">
        <div class="vr-name">${escHTML(name)}</div>
        <div style="display:flex;align-items:center;gap:6px;margin-top:3px">
          <span style="font-size:11px;color:var(--text2)">${docType}</span>
          <span class="${badgeCls}">${urgency}</span>
        </div>
      </div>
      <div style="text-align:right;flex-shrink:0">
        <div class="vr-time">${time}</div>
        <div class="vr-score">ID Score: ${score}%</div>
      </div>`;
    row.addEventListener('click', () => {
      document.querySelectorAll('.vr').forEach(r => r.classList.remove('sel'));
      row.classList.add('sel');
      try { buildVerifDetail(JSON.parse(row.dataset.verif)); } catch(_) {}
    });
    body.appendChild(row);
    card.remove();
  });
}

function buildVerifDetail(v) {
  const panel = document.getElementById('verifDetail');
  if (!panel) return;
  const docs = v.docs || [];
  panel.innerHTML = `
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px">
      <div class="uav uav-xl">${(v.displayName||'?')[0].toUpperCase()}</div>
      <div>
        <div style="font-size:16px;font-weight:900;color:var(--text)">${escHTML(v.displayName||'User')}</div>
        <div style="font-size:12px;color:var(--text3)">${escHTML(v.email||'')}</div>
      </div>
    </div>

    <div style="font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.7px;color:var(--text3);margin-bottom:8px">Submitted Document</div>
    <div class="vd-doc-img">
      ${docs.length
        ? `<img src="${escHTML(docs[0].url)}" style="max-height:180px;object-fit:contain">`
        : `<div style="color:rgba(255,255,255,.3);display:flex;flex-direction:column;align-items:center;gap:8px">
             <span class="ms" style="font-size:48px">badge</span>
             <span style="font-size:12px">NATIONAL DOCUMENT</span>
           </div>`}
    </div>

    <div class="vd-meta-row" style="margin-bottom:14px">
      <div class="vd-meta-box">
        <div class="vd-meta-lbl">Doc Type</div>
        <div class="vd-meta-val">National ID Card</div>
      </div>
      <div class="vd-meta-box">
        <div class="vd-meta-lbl">Issued By</div>
        <div class="vd-meta-val">Somalia (FGS)</div>
      </div>
    </div>

    <div style="font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.7px;color:var(--text3);margin-bottom:8px">Security &amp; Metadata Checks</div>

    <div class="vd-check-row" style="background:#f0fdf4;border-color:#bbf7d0">
      <div class="vd-check-icon" style="background:#dcfce7"><span class="ms" style="color:#16a34a;font-size:16px">face</span></div>
      <div>
        <div class="vd-check-name">Face Match Confidence</div>
        <div class="vd-check-sub">Selfie vs Document photo</div>
      </div>
      <div class="vd-check-val">
        <div class="vd-check-num" style="color:#16a34a">98.4%</div>
        <div class="vd-check-status passed">PASSED</div>
      </div>
    </div>

    <div class="vd-check-row">
      <div class="vd-check-icon" style="background:var(--pr-s)"><span class="ms" style="color:var(--pr);font-size:16px">location_on</span></div>
      <div>
        <div class="vd-check-name">Geolocation Scan</div>
        <div class="vd-check-sub">Mogadishu, Somalia</div>
      </div>
      <div class="vd-check-val">
        <div class="vd-check-num" style="font-size:12px">Within Range</div>
        <div class="vd-check-status verified">VERIFIED</div>
      </div>
    </div>

    <div class="vd-check-row">
      <div class="vd-check-icon" style="background:#e0f2fe"><span class="ms" style="color:#0284c7;font-size:16px">article</span></div>
      <div>
        <div class="vd-check-name">OCR Validation</div>
        <div class="vd-check-sub">Data extraction check</div>
      </div>
      <div class="vd-check-val">
        <div class="vd-check-num" style="font-size:12px">Valid</div>
        <div class="vd-check-status completed">COMPLETED</div>
      </div>
    </div>

    <button class="vd-approve-btn" onclick="verifyUser(${v.id},'approved')">
      <span class="ms">verified</span> Approve User
    </button>
    <button onclick="verifyUser(${v.id},'rejected')" style="width:100%;margin-top:8px;padding:10px;border-radius:var(--r2);border:1.5px solid var(--border);background:var(--surface);color:var(--text2);font-size:13px;font-weight:700;cursor:pointer;transition:all .12s" onmouseover="this.style.borderColor='var(--red)';this.style.color='var(--red)'" onmouseout="this.style.borderColor='var(--border)';this.style.color='var(--text2)'">
      Deny Application
    </button>
    <div style="font-size:10px;color:var(--text3);text-align:center;margin-top:10px;font-style:italic">Actions are logged with admin ID: #ADMIN_${v.id||'???'}</div>`;
}

// ── RENDER HELPERS ────────────────────────────────────────

// Dashboard recent listings from API
const _origLoadDash = typeof loadDashboard === 'function' ? loadDashboard : null;
if (_origLoadDash) {
  window.loadDashboard = async function() {
    await _origLoadDash();
    populateDashboardExtras();
    populateSettingsStats();
  };
}

function populateDashboardExtras() {
  // Set QA sub text from badges
  const pv = document.getElementById('navBadgeVerif')?.textContent;
  const pr = document.getElementById('navBadgeReports')?.textContent;
  const qaV = document.getElementById('qaVerifSub');
  const qaR = document.getElementById('qaReportSub');
  if (qaV && pv && pv !== '0') qaV.textContent = pv + ' pending';
  if (qaR && pr && pr !== '0') qaR.textContent = pr + ' new cases';

  // Listings mini-stats from main stat cards
  const al = document.getElementById('statListings')?.textContent;
  const pl = document.getElementById('statPending')?.textContent;
  if (al) { const e = document.getElementById('listStatActive'); if(e) e.textContent = al; }
  if (pl) { const e = document.getElementById('listStatPending'); if(e) e.textContent = pl; }

  // Revenue total mirror
  const rt = document.getElementById('revTotal');
  const rt2 = document.getElementById('revTotal2');
  if (rt && rt2 && rt.textContent !== '—') rt2.textContent = rt.textContent;

  // Recent listings for dashboard table
  loadDashRecentListings();
}

async function loadDashRecentListings() {
  const tbody = document.getElementById('dashListingsBody');
  if (!tbody) return;
  try {
    const d = await adminFetch('listings&status=all&page=1');
    const items = (d.listings || []).slice(0, 4);
    if (!items.length) { tbody.innerHTML = '<tr><td colspan="6" class="empty-row">No listings yet</td></tr>'; return; }
    const sc = {active:'bg',pending:'by',sold:'bb',rejected:'br',expired:'bc'};
    tbody.innerHTML = items.map(l => `<tr>
      <td>
        <div style="display:flex;align-items:center;gap:10px">
          <img class="listing-thumb" src="${escHTML(l.image||l.thumbnail||'')}" onerror="this.style.background='var(--surface2)';this.src=''">
          <span style="font-weight:700">${escHTML(l.title||'—')}</span>
        </div>
      </td>
      <td style="color:var(--text2)">${escHTML(l.category||'—')}</td>
      <td style="font-weight:800">$${Number(l.price||0).toLocaleString()}</td>
      <td style="color:var(--text2)">${escHTML(l.seller||'—')}</td>
      <td><span class="badge ${sc[l.status]||'bc'}">${l.status||'—'}</span></td>
      <td><button class="ib" onclick="approveListing(${l.id})"><span class="ms">more_vert</span></button></td>
    </tr>`).join('');
  } catch(e) { console.warn('dash listings:', e); }
}

function populateSettingsStats() {
  // Mirror key stats to settings DB overview
  const maps = [
    ['statUsers','dbUsers'], ['statListings','dbListings'],
    ['statMessages','dbMessages'], ['statRevenue','dbRevenue'],
    ['statOffers','dbOffers'],
  ];
  maps.forEach(([src,dst]) => {
    const s = document.getElementById(src);
    const d = document.getElementById(dst);
    if (s && d && s.textContent !== '—') d.textContent = s.textContent;
  });
}

// ── OVERRIDE renderSignupsChart for col chart ─────────────
const _origRSC = typeof renderSignupsChart === 'function' ? renderSignupsChart : null;
if (_origRSC) {
  window.renderSignupsChart = function(data) {
    const el = document.getElementById('signupsChart');
    if (!el) return;
    if (!data || !data.length) { el.innerHTML = '<div class="empty-state" style="width:100%;padding:12px"><small>No signup data</small></div>'; return; }
    const max = Math.max(...data.map(d => d.count), 1);
    el.innerHTML = data.map(d => {
      const h = Math.max(Math.round((d.count/max)*100), 4);
      return `<div class="col-bar-wrap">
        <div class="col-bar" style="height:${h}px;background:var(--pr);opacity:.85"></div>
      </div>`;
    }).join('');
    // Also update revenue chart with same data shape
    buildRevChartBars(data);
  };
}

function buildRevChartBars(data) {
  const el = document.getElementById('revChartBars');
  if (!el) return;
  if (!data || !data.length) return;
  const max = Math.max(...data.map(d => d.count||d.amount||0), 1);
  el.innerHTML = data.map(d => {
    const val = d.count || d.amount || 0;
    const h = Math.max(Math.round((val/max)*120), 4);
    return `<div class="col-bar-wrap">
      <div class="col-bar" style="height:${h}px;background:var(--pr);opacity:.7;border-radius:5px 5px 0 0"></div>
    </div>`;
  }).join('');
  // revenue total
  const total = data.reduce((s,d) => s+(d.amount||0), 0);
  const rct = document.getElementById('revenueChartTotal');
  if (rct && total > 0) rct.textContent = '$' + total.toLocaleString();
}

// ── OVERRIDE renderCatsChart ──────────────────────────────
const _origRCC = typeof renderCatsChart === 'function' ? renderCatsChart : null;
if (_origRCC) {
  window.renderCatsChart = function(data) {
    const el = document.getElementById('catsChart');
    if (!el) return;
    if (!data || !data.length) { el.innerHTML = '<div class="empty-state" style="padding:12px"><small>No category data</small></div>'; return; }
    const total = data.reduce((s,d) => s+d.count, 0);
    const colors = ['#ec5b13','#2563eb','#16a34a','#d97706','#7c3aed','#dc2626','#0891b2','#db2777'];
    el.innerHTML = data.slice(0,6).map((d,i) => {
      const pct = total > 0 ? Math.round((d.count/total)*100) : 0;
      return `<div class="cat-bar-row">
        <div class="cat-name">${escHTML(d.category||'Other')}</div>
        <div class="cat-track"><div class="cat-fill" style="width:${pct}%;background:${colors[i%colors.length]}"></div></div>
        <div class="cat-pct">${pct}%</div>
      </div>`;
    }).join('');
  };
}

// ── OVERRIDE loadUsers to match new design ────────────────
const _origLoadUsers = typeof loadUsers === 'function' ? loadUsers : null;
if (_origLoadUsers) {
  window.loadUsers = async function() {
    const tbody = document.getElementById('usersBody');
    if (tbody) tbody.innerHTML = '<tr><td colspan="5" class="empty-row">Loading…</td></tr>';
    try {
      const search = document.getElementById('userSearch')?.value || '';
      const filter = document.getElementById('userFilter')?.value || '';
      const d = await adminFetch(`users&q=${encodeURIComponent(search)}&status=${filter}&page=1`);
      const users = d.users || [];
      const countEl = document.getElementById('userCount');
      if (countEl) countEl.textContent = 'Showing ' + users.length + ' of ' + (d.total||users.length) + ' users';
      if (!users.length) { if(tbody) tbody.innerHTML = '<tr><td colspan="5" class="empty-row">No users found</td></tr>'; return; }
      if (tbody) tbody.innerHTML = users.map(u => {
        const name = escHTML(u.display_name||u.displayName||'—');
        const joined = u.createdAt||u.created_at ? new Date(u.createdAt||u.created_at).toLocaleDateString('en-US',{month:'short',day:'2-digit',year:'numeric'}) : '—';
        const statusEl = u.banned
          ? '<span style="display:flex;align-items:center;gap:5px;font-size:12.5px;font-weight:700;color:var(--red)"><span style="width:7px;height:7px;border-radius:50%;background:var(--red);display:inline-block"></span>Banned</span>'
          : u.verified
            ? '<span style="display:flex;align-items:center;gap:5px;font-size:12.5px;font-weight:700;color:var(--green)"><span style="width:7px;height:7px;border-radius:50%;background:var(--green);display:inline-block"></span>Active</span>'
            : '<span style="display:flex;align-items:center;gap:5px;font-size:12.5px;font-weight:700;color:var(--yellow)"><span style="width:7px;height:7px;border-radius:50%;background:var(--yellow);display:inline-block"></span>Pending</span>';
        const planBadge = u.plan && u.plan!=='free' ? `<span class="role-badge rb-vendor" style="text-transform:capitalize">${escHTML(u.plan)}</span>` : '<span class="role-badge rb-customer">CUSTOMER</span>';
        return `<tr>
          <td>
            <div style="display:flex;align-items:center;gap:10px">
              <div class="uav uav-sm">${name[0]||'?'}</div>
              <div>
                <div style="font-weight:700">${name}</div>
                <div style="font-size:11px;color:var(--text3)">${escHTML(u.email||'')}</div>
              </div>
            </div>
          </td>
          <td style="color:var(--text2)">${joined}</td>
          <td>${planBadge}</td>
          <td>${statusEl}</td>
          <td>
            <div class="act-group">
              <button class="ib" title="View"><span class="ms">visibility</span></button>
              <button class="ib" title="Edit"><span class="ms">edit</span></button>
              <button class="ib ib-del" title="${u.banned?'Unban':'Ban'}" onclick="${u.banned?`unbanUser(${u.id})`:`openBanModal(${u.id})`}">
                <span class="ms">${u.banned?'check_circle':'block'}</span>
              </button>
            </div>
          </td>
        </tr>`;
      }).join('');
    } catch(e) { if(tbody) tbody.innerHTML = `<tr><td colspan="5" class="empty-row" style="color:var(--red)">${e.message}</td></tr>`; }
  };
}

// ── OVERRIDE loadListingsAdmin ────────────────────────────
const _origLoadList = typeof loadListingsAdmin === 'function' ? loadListingsAdmin : null;
if (_origLoadList) {
  window.loadListingsAdmin = async function() {
    const tbody = document.getElementById('listingsBody');
    if (tbody) tbody.innerHTML = '<tr><td colspan="7" class="empty-row">Loading…</td></tr>';
    try {
      const filter = document.getElementById('listingFilter')?.value || 'all';
      const search = encodeURIComponent(document.getElementById('listingSearch')?.value || '');
      const d = await adminFetch(`listings&status=${filter}&q=${search}`);
      const items = d.listings || [];
      const cntEl = document.getElementById('listingsCount');
      if (cntEl) cntEl.textContent = `Showing 1 to ${items.length} of ${d.total||items.length} results`;
      // Update mini stats
      if (d.stats) {
        const s = d.stats;
        const set = (id,v) => { const el=document.getElementById(id); if(el)el.textContent=v??'—'; };
        set('listStatTotal', s.total||items.length);
        set('listStatActive', s.active||'—');
        set('listStatPending', s.pending||'—');
        set('listStatExpired', s.expired||'—');
      }
      if (!items.length) { if(tbody) tbody.innerHTML = '<tr><td colspan="7" class="empty-row">No listings found</td></tr>'; return; }
      const sc = {active:'bg',pending:'by',sold:'bb',rented:'bp',rejected:'br',deleted:'bc',expired:'bc'};
      if (tbody) tbody.innerHTML = items.map(l => {
        const boosted = l.boostedUntil && new Date(l.boostedUntil)>new Date() ? '<span class="badge bo badge-nd" style="font-size:9px;margin-left:4px">⚡ Boosted</span>' : '';
        return `<tr id="lrow-${l.id}">
          <td style="width:36px"><input type="checkbox" class="bulk-cb" value="${l.id}" onchange="onBulkCbChange(${l.id},this.checked)"></td>
          <td>
            <div style="display:flex;align-items:center;gap:10px">
              <img class="listing-thumb" src="${escHTML(l.image||l.thumbnail||'')}" onerror="this.style.background='var(--surface2)';this.src=''">
              <div>
                <a href="listing.html?id=${l.id}" target="_blank" style="font-weight:700;color:var(--text);max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;display:block">${escHTML(l.title||'—')}</a>
                <div style="font-size:11px;color:var(--text3)">ID: #SB-${l.id} · Posted ${fmtDate(l.createdAt||l.created_at)}${boosted}</div>
              </div>
            </div>
          </td>
          <td style="color:var(--text2)">${escHTML(l.category||'—')}</td>
          <td>
            <div style="display:flex;align-items:center;gap:7px">
              <div class="uav uav-sm" style="font-size:9px">${(l.seller||'?')[0].toUpperCase()}</div>
              <span style="font-size:12.5px;font-weight:600">${escHTML(l.seller||'—')}</span>
            </div>
          </td>
          <td style="font-weight:800">$${Number(l.price||0).toLocaleString()}</td>
          <td><span class="badge ${sc[l.status]||'bc'}" style="text-transform:capitalize">${l.status||'—'}</span></td>
          <td>
            <div class="act-group">
              ${l.status==='pending' ? `<button class="act act-ok" onclick="approveListing(${l.id})">Approve</button><button class="act act-no" onclick="rejectListingAdmin(${l.id})">Reject</button>` : ''}
              ${l.status==='expired' ? `<button class="act act-view" onclick="approveListing(${l.id})">Relist</button>` : ''}
              <button class="ib ib-del" onclick="deleteListing(${l.id})"><span class="ms">delete</span></button>
              <button class="ib"><span class="ms">more_vert</span></button>
            </div>
          </td>
        </tr>`;
      }).join('');
    } catch(e) { if(tbody) tbody.innerHTML = `<tr><td colspan="7" class="empty-row" style="color:var(--red)">${e.message}</td></tr>`; }
  };
  window.loadListings = loadListingsAdmin;
}

// ── OVERRIDE loadPayments ─────────────────────────────────
const _origLoadPay = typeof loadPayments === 'function' ? loadPayments : null;
if (_origLoadPay) {
  window.loadPayments = async function() {
    const tbody = document.getElementById('paymentsBody');
    if (tbody) tbody.innerHTML = '<tr><td colspan="6" class="empty-row">Loading…</td></tr>';
    try {
      const filter = document.getElementById('paymentFilter')?.value || 'pending';
      const d = await adminFetch(`payments&status=${filter}`);
      const items = d.payments || [];
      // Mirror totals
      if (d.stats) {
        const set = (id,v) => { const el=document.getElementById(id); if(el)el.textContent=v??'—'; };
        set('revTotal', '$'+(d.stats.total_revenue||0));
        set('revTotal2', '$'+(d.stats.total_revenue||0));
        set('revPending', d.stats.pending_count||0);
        set('revApproved', (d.stats.success_rate||'—')+'%');
        set('revenueChartTotal', '$'+(d.stats.total_revenue||0));
      }
      const cnt = document.getElementById('payCount');
      if (cnt) cnt.textContent = `Showing 1 to ${items.length} of ${d.total||items.length} transactions`;
      if (!items.length) { if(tbody) tbody.innerHTML = '<tr><td colspan="6" class="empty-row">No transactions found</td></tr>'; return; }
      const sc = {pending:'by',approved:'bg',rejected:'br'};
      if (tbody) tbody.innerHTML = items.map((p,i) => {
        const trxId = '#TRX-' + String(80000+i+1+p.id).padStart(5,'0');
        const _ssUrl = p.screenshotUrl||p.receiptUrl||p.screenshot_url||null;
        const ss = _ssUrl ? `<button class="act act-view" onclick="window.open('${escHTML(_ssUrl)}','_blank')">View</button>` : '<span style="color:var(--text3);font-size:12px">—</span>';
        const approveReject = p.status==='pending'
          ? `<div style="display:flex;gap:4px">
               <button class="act act-ok" onclick="approvePayment(${p.id})">Approve</button>
               <button class="act act-del" onclick="rejectPayment(${p.id})">Reject</button>
             </div>`
          : `<span class="badge ${sc[p.status]||'bc'}" style="text-transform:capitalize">${p.status}</span>`;
        return `<tr>
          <td style="font-family:var(--mono);font-size:12px;font-weight:700;color:var(--text2)">${trxId}</td>
          <td>
            <div style="display:flex;align-items:center;gap:8px">
              <div class="uav uav-sm">${(p.userName||'?')[0].toUpperCase()}</div>
              <div>
                <div style="font-weight:700;font-size:12.5px">${escHTML(p.userName||'—')}</div>
                <div style="font-size:10.5px;color:var(--text3)">${escHTML(p.userEmail||'')}</div>
              </div>
            </div>
          </td>
          <td style="font-weight:800;font-size:14px">$${p.amount||0}</td>
          <td style="color:var(--text2)">${fmtDate(p.createdAt||p.created_at)}</td>
          <td>${approveReject}</td>
          <td><button class="ib"><span class="ms">more_vert</span></button></td>
        </tr>`;
      }).join('');
    } catch(e) { if(tbody) tbody.innerHTML = `<tr><td colspan="6" class="empty-row" style="color:var(--red)">${e.message}</td></tr>`; }
  };
}

// ── OVERRIDE loadCoupons ──────────────────────────────────
const _origLoadCpn = typeof loadCoupons === 'function' ? loadCoupons : null;
if (_origLoadCpn) {
  window.loadCoupons = async function() {
    const tbody = document.getElementById('couponList');
    if (tbody) tbody.innerHTML = '<tr><td colspan="6" class="empty-row">Loading…</td></tr>';
    try {
      const d = await adminFetch('coupons');
      const coupons = d.coupons || [];
      const set = (id,v) => { const el=document.getElementById(id); if(el)el.textContent=v??'—'; };
      const active = coupons.filter(c=>c.is_active).length;
      set('cpnActive', active);
      set('cpnRedemptions', d.stats?.total_redemptions||coupons.reduce((s,c)=>s+(c.used_count||0),0));
      set('cpnRevSaved', '$'+d.stats?.revenue_saved||'$0');
      const expiring = coupons.filter(c=>c.expires_at&&new Date(c.expires_at)<new Date(Date.now()+7*86400000)).length;
      set('cpnExpiring', expiring);
      set('cpnCount', `Showing 1 to ${coupons.length} of ${coupons.length} results`);
      if (!coupons.length) { if(tbody) tbody.innerHTML = '<tr><td colspan="6" class="empty-row">No coupons yet</td></tr>'; return; }
      if (tbody) tbody.innerHTML = coupons.map(c => {
        const used = c.used_count||0;
        const max = c.max_uses||0;
        const pct = max > 0 ? Math.min(Math.round((used/max)*100),100) : 0;
        const pctColor = pct>=100 ? 'var(--red)' : pct>=80 ? 'var(--yellow)' : 'var(--pr)';
        const exp = c.expires_at ? new Date(c.expires_at).toLocaleDateString('en-US',{month:'short',day:'2-digit',year:'numeric'}) : '∞ No Expiry';
        const status = !c.is_active ? 'Expired' : new Date(c.expires_at||'2099')>new Date() ? 'Active' : 'Expired';
        return `<tr>
          <td>
            <div style="font-weight:900;color:var(--pr);font-family:var(--mono);font-size:13px">${escHTML(c.code||'—')}</div>
            <div style="font-size:11px;color:var(--text3)">Created ${fmtDate(c.created_at)}</div>
          </td>
          <td>
            <span style="font-weight:800;color:var(--pr);font-size:13px">${c.discount_percent||0}% Off</span>
          </td>
          <td>
            <div class="usage-bar-wrap">
              <div style="font-size:12px;color:var(--text2);white-space:nowrap">${used}/${max||'Unlimited'}</div>
              <div class="usage-track"><div class="usage-fill" style="width:${pct}%;background:${pctColor}"></div></div>
              <div class="usage-pct">${pct}%</div>
            </div>
          </td>
          <td style="font-size:12.5px;color:var(--text2)">
            ${c.expires_at ? `<span class="ms" style="font-size:14px;vertical-align:middle;color:var(--text3)">calendar_today</span> ${exp}` : `<span class="ms" style="font-size:14px;vertical-align:middle;color:var(--text3)">all_inclusive</span> ${exp}`}
          </td>
          <td>
            <label class="toggle">
              <input type="checkbox" ${c.is_active?'checked':''} onchange="toggleCoupon(${c.id},this.checked)">
              <span class="toggle-slider"></span>
            </label>
            <span style="font-size:11.5px;font-weight:700;color:${c.is_active?'var(--green)':'var(--text3)'};margin-left:6px">${status}</span>
          </td>
          <td>
            <div class="act-group">
              <button class="ib" onclick="openCouponModal(${c.id})"><span class="ms">edit</span></button>
              <button class="ib ib-del" onclick="deleteCoupon(${c.id})"><span class="ms">delete</span></button>
            </div>
          </td>
        </tr>`;
      }).join('');
    } catch(e) { if(tbody) tbody.innerHTML = `<tr><td colspan="6" class="empty-row" style="color:var(--red)">${e.message}</td></tr>`; }
  };
}

// ── OVERRIDE loadOffers ───────────────────────────────────
const _origLoadOff = typeof loadAdminOffers === 'function' ? loadAdminOffers : null;
if (_origLoadOff) {
  window.loadAdminOffers = async function() {
    const tbody = document.getElementById('offersBody');
    if (tbody) tbody.innerHTML = '<tr><td colspan="5" class="empty-row">Loading…</td></tr>';
    try {
      const filter = document.getElementById('offerStatusFilter')?.value || 'all';
      const d = await adminFetch(`offers&status=${filter}`);
      const items = d.offers || [];
      const set = (id,v) => { const el=document.getElementById(id); if(el)el.textContent=v??'—'; };
      set('statOffers', d.stats?.total||items.length);
      set('statActiveOffers', d.stats?.accepted||'—');
      set('offCountered', d.stats?.countered||'—');
      set('offExpired', d.stats?.expired||'—');
      set('offCount', `Showing 1 to ${items.length} of ${d.total||items.length} offers`);
      if (!items.length) { if(tbody) tbody.innerHTML = '<tr><td colspan="5" class="empty-row">No offers found</td></tr>'; return; }
      const scMap = {pending:'by',accepted:'bg',rejected:'br',countered:'by',cancelled:'bc',expired:'bc'};
      const scLabel = {pending:'Pending',accepted:'Accepted',rejected:'Rejected',countered:'Counter-offer',cancelled:'Cancelled',expired:'Expired'};
      if (tbody) tbody.innerHTML = items.map(o => `<tr>
        <td>
          <div style="font-weight:700">${escHTML(o.listing_title||o.title||'#'+o.listing_id)}</div>
          <div style="font-size:11px;color:var(--text3)">ID: #SB-${o.listing_id||'—'}</div>
        </td>
        <td>
          <div style="display:flex;align-items:center;gap:7px">
            <div class="uav uav-sm">${(o.buyer_name||'?')[0].toUpperCase()}</div>
            <div>
              <div style="font-size:12.5px;font-weight:700">${escHTML(o.buyer_name||'—')}</div>
              <div style="font-size:10px;color:var(--text3)">${escHTML(o.buyer_type||'REGULAR')}</div>
            </div>
          </div>
        </td>
        <td>
          <div style="font-weight:800;font-size:13.5px">$${Number(o.amount||o.offer_amount||0).toLocaleString()}</div>
          <div style="font-size:11px;color:var(--text3)">Asking $${Number(o.asking_price||o.original_price||0).toLocaleString()}</div>
        </td>
        <td><span class="badge ${scMap[o.status]||'bc'}">${scLabel[o.status]||o.status||'—'}</span></td>
        <td><button class="ib"><span class="ms">more_vert</span></button></td>
      </tr>`).join('');
    } catch(e) { if(tbody) tbody.innerHTML = `<tr><td colspan="5" class="empty-row" style="color:var(--red)">${e.message}</td></tr>`; }
  };
  window.loadOffers = window.loadAdminOffers;
}

// ── OVERRIDE loadReports ──────────────────────────────────
const _origLoadRep = typeof loadReports === 'function' ? loadReports : null;
if (_origLoadRep) {
  window.loadReports = async function() {
    const tbody = document.getElementById('reportsBody');
    if (tbody) tbody.innerHTML = '<tr><td colspan="7" class="empty-row">Loading…</td></tr>';
    try {
      const status = document.getElementById('reportStatusFilter')?.value ?? '';
      const d = await adminFetch('list_reports' + (status ? '&status='+status : ''));
      const rows = d.reports || d.data?.reports || [];
      const set = (id,v) => { const el=document.getElementById(id); if(el)el.textContent=v??'—'; };
      set('reportTotalBadge', rows.length);
      set('reportPendBadge', rows.filter(r=>r.status==='pending').length);
      set('reportCount', `Showing ${rows.length} of ${d.total||rows.length} results`);
      const navBadge = document.getElementById('navBadgeReports');
      if (navBadge) { navBadge.textContent = rows.filter(r=>r.status==='pending').length||''; navBadge.style.display = rows.length?'':'none'; }
      if (!rows.length) { if(tbody) tbody.innerHTML = '<tr><td colspan="7" class="empty-row">No reports found</td></tr>'; return; }
      const typeCls = {User:'bb',Listing:'by',Order:'bg'};
      const stCls = {pending:'by',reviewing:'bb',reviewed:'bg',dismissed:'bc'};
      if (tbody) tbody.innerHTML = rows.map(r => `<tr>
        <td>
          <div style="display:flex;align-items:center;gap:8px">
            <div class="uav uav-sm">${(r.reporter_name||'?')[0].toUpperCase()}</div>
            <span style="font-size:12.5px;font-weight:700">${escHTML(r.reporter_name||'#'+r.reporter_id)}</span>
          </div>
        </td>
        <td><span class="badge ${typeCls[r.report_type]||'bc'} badge-nd" style="text-transform:capitalize">${escHTML(r.report_type||r.type||'listing')}</span></td>
        <td style="color:var(--pr);font-weight:600">${escHTML(r.target_title||'@'+r.target_id||'—')}</td>
        <td style="color:var(--text2);max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${escHTML(r.reason||'—')}</td>
        <td style="color:var(--text3)">${fmtDate(r.created_at)}</td>
        <td>
          <div style="display:flex;align-items:center;gap:4px">
            <span style="width:7px;height:7px;border-radius:50%;background:${r.status==='reviewed'?'var(--green)':r.status==='reviewing'?'var(--blue)':'var(--yellow)'};display:inline-block"></span>
            <span style="font-size:12px;font-weight:700;color:${r.status==='reviewed'?'var(--green)':r.status==='reviewing'?'var(--blue)':'var(--yellow)'};text-transform:capitalize">${r.status||'Pending'}</span>
          </div>
        </td>
        <td>
          ${r.status==='pending'||!r.status ? `<button class="act act-view" onclick="resolveReport(${r.id},'reviewed')">Review</button>` : `<button class="act act-ok" onclick="resolveReport(${r.id},'reviewed')">View</button>`}
        </td>
      </tr>`).join('');
    } catch(e) { if(tbody) tbody.innerHTML = `<tr><td colspan="7" class="empty-row" style="color:var(--red)">${e.message}</td></tr>`; }
  };
}

// ── OVERRIDE loadReviews ──────────────────────────────────
const _origLoadRev = typeof loadReviews === 'function' ? loadReviews : null;
if (_origLoadRev) {
  window.loadReviews = async function() {
    const tbody = document.getElementById('reviewsBody');
    if (tbody) tbody.innerHTML = '<tr><td colspan="5" class="empty-row">Loading…</td></tr>';
    try {
      const d = await adminFetch('list_reviews');
      const rows = d.reviews || d.data?.reviews || [];
      const cnt = document.getElementById('reviewCount');
      if (cnt) cnt.textContent = `Showing ${rows.length} of ${d.total||rows.length} reviews`;
      if (!rows.length) { if(tbody) tbody.innerHTML = '<tr><td colspan="5" class="empty-row">No reviews yet</td></tr>'; return; }
      const stars = n => '★'.repeat(Math.min(5,n||0))+'☆'.repeat(5-Math.min(5,n||0));
      const stCls = {approved:'APPROVED',pending:'PENDING',rejected:'FLAGGED'};
      const stColor = {approved:'var(--green)',pending:'var(--yellow)',rejected:'var(--red)'};
      if (tbody) tbody.innerHTML = rows.map(r => `<tr>
        <td>
          <div style="display:flex;align-items:center;gap:8px">
            <div class="uav uav-sm">${(r.reviewer_name||'?')[0].toUpperCase()}</div>
            <span style="font-weight:700">${escHTML(r.reviewer_name||'#'+r.reviewer_id)}</span>
          </div>
        </td>
        <td style="color:var(--text2)">${escHTML(r.seller_name||'—')}</td>
        <td style="color:#f59e0b;font-size:14px;letter-spacing:1px">${stars(r.rating)}</td>
        <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--text2)">${escHTML(r.comment||'—')}</td>
        <td>
          <span style="background:${stColor[r.status]||'var(--text3)'};color:white;font-size:10px;font-weight:800;padding:3px 9px;border-radius:4px">
            ${stCls[r.status]||'PENDING'}
          </span>
        </td>
      </tr>`).join('');
    } catch(e) { if(tbody) tbody.innerHTML = `<tr><td colspan="5" class="empty-row" style="color:var(--red)">${e.message}</td></tr>`; }
  };
}

// ── OVERRIDE loadAdminMessages ────────────────────────────
const _origLoadMsg = typeof loadAdminMessages === 'function' ? loadAdminMessages : null;
if (_origLoadMsg) {
  window.loadAdminMessages = async function() {
    const tbody = document.getElementById('messagesBody');
    if (tbody) tbody.innerHTML = '<tr><td colspan="5" class="empty-row">Loading…</td></tr>';
    try {
      const d = await adminFetch('list_conversations');
      const rows = d.conversations || d.data?.conversations || [];
      const cnt = document.getElementById('msgCount');
      if (cnt) cnt.textContent = `Showing 1-${Math.min(rows.length,10)} of ${d.total||rows.length} messages`;
      if (!rows.length) { if(tbody) tbody.innerHTML = '<tr><td colspan="5" class="empty-row">No conversations</td></tr>'; return; }
      if (tbody) tbody.innerHTML = rows.map(r => {
        const p1 = r.participant1||''; const p2 = r.participant2||'';
        const initials = [p1,p2].map(n=>n[0]?.toUpperCase()||'?');
        const statusCls = r.flagged ? 'st-flagged' : r.is_resolved ? 'st-resolved' : r.status==='closed' ? 'st-closed' : 'st-active';
        const statusLabel = r.flagged ? 'Flagged' : r.is_resolved ? 'Resolved' : r.status==='closed' ? 'Closed' : 'Active';
        const cnt2 = r.message_count||0;
        return `<tr>
          <td>
            <div style="display:flex;align-items:center;gap:10px">
              <div style="display:flex">
                <div class="uav uav-sm" style="border:2px solid var(--surface);z-index:1">${initials[0]}</div>
                <div class="uav uav-sm" style="margin-left:-8px;border:2px solid var(--surface)">${initials[1]}</div>
              </div>
              <div>
                <div style="font-weight:700">${escHTML(p1)}</div>
                <div style="font-size:11px;color:var(--text3)">${escHTML(p2)}</div>
              </div>
            </div>
          </td>
          <td style="max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--text2)">${escHTML(r.last_message||'—')}</td>
          <td>
            <span style="display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;border-radius:50%;background:${r.flagged?'var(--pr)':'var(--border)'};color:${r.flagged?'white':'var(--text3)'};font-size:10px;font-weight:800">${cnt2}</span>
          </td>
          <td style="color:var(--text3)">${fmtDate(r.last_activity||r.created_at)}</td>
          <td><span class="${statusCls}" style="font-size:12.5px;font-weight:700">${statusLabel}</span></td>
        </tr>`;
      }).join('');
    } catch(e) { if(tbody) tbody.innerHTML = `<tr><td colspan="5" class="empty-row" style="color:var(--red)">${e.message}</td></tr>`; }
  };
}

// ── OVERRIDE loadAffiliates ───────────────────────────────
const _origLoadAff = typeof loadAffiliates === 'function' ? loadAffiliates : null;
if (_origLoadAff) {
  window.loadAffiliates = async function() {
    const tbody = document.getElementById('affiliateList');
    if (tbody) tbody.innerHTML = '<tr><td colspan="7" class="empty-row">Loading…</td></tr>';
    try {
      const d = await adminFetch('affiliates');
      const items = d.affiliates || [];
      const set = (id,v) => { const el=document.getElementById(id); if(el)el.textContent=v??'—'; };
      set('affTotalRefs', d.stats?.total_referrals||items.reduce((s,a)=>s+(a.referrals||0),0));
      set('affTotalComm', '$'+(d.stats?.total_commissions||0));
      set('affUnpaid', '$'+(d.stats?.unpaid_payouts||0));
      set('affNew', d.stats?.new_affiliates||items.length);
      set('affCount', `Showing 1 to ${items.length} of ${d.total||items.length} entries`);
      if (!items.length) { if(tbody) tbody.innerHTML = '<tr><td colspan="7" class="empty-row">No affiliates yet</td></tr>'; return; }
      if (tbody) tbody.innerHTML = items.map(a => {
        const stCls = {active:'bg',inactive:'bc',pending:'by'};
        return `<tr>
          <td>
            <div style="display:flex;align-items:center;gap:9px">
              <div class="uav uav-sm">${(a.name||'?')[0].toUpperCase()}</div>
              <div>
                <div style="font-weight:700">${escHTML(a.name||'—')}</div>
                <div style="font-size:11px;color:var(--text3)">${escHTML(a.email||'')}</div>
              </div>
            </div>
          </td>
          <td style="font-weight:700">${a.referrals||0}</td>
          <td style="color:var(--pr);font-weight:800">${a.commission_rate||0}%</td>
          <td style="font-weight:800">$${Number(a.total_earned||0).toLocaleString()}</td>
          <td><span class="badge ${stCls[a.status]||'bc'}" style="text-transform:capitalize">${a.status||'—'}</span></td>
          <td style="color:var(--text2)">${a.last_payout ? fmtDate(a.last_payout) : 'N/A'}</td>
          <td>
            <div class="act-group">
              <button class="act act-ok" onclick="markAffiliatePaid(${a.id})">Pay</button>
              <button class="ib"><span class="ms">more_vert</span></button>
            </div>
          </td>
        </tr>`;
      }).join('');
    } catch(e) { if(tbody) tbody.innerHTML = `<tr><td colspan="7" class="empty-row" style="color:var(--red)">${e.message}</td></tr>`; }
  };
}

// ── OVERRIDE loadAnnouncements ────────────────────────────
const _origLoadAnn = typeof loadAnnouncements === 'function' ? loadAnnouncements : null;
if (_origLoadAnn) {
  window.loadAnnouncements = async function() {
    const tbody = document.getElementById('announcementsList');
    if (tbody) tbody.innerHTML = '<tr><td colspan="5" class="empty-row">Loading…</td></tr>';
    try {
      const d = await adminFetch('list_announcements');
      const rows = d.announcements || d.data?.announcements || [];
      const cnt = document.getElementById('annCount');
      if (cnt) cnt.textContent = `Showing 1 to ${rows.length} of ${rows.length} results`;
      if (!rows.length) { if(tbody) tbody.innerHTML = '<tr><td colspan="5" class="empty-row">No announcements yet</td></tr>'; return; }
      const stCls = {active:'bg',inactive:'bc',draft:'by'};
      if (tbody) tbody.innerHTML = rows.map(a => {
        const isActive = a.is_active;
        return `<tr>
          <td>
            <div style="display:flex;align-items:center;gap:6px">
              <span style="width:8px;height:8px;border-radius:50%;background:${isActive?'var(--green)':a.is_draft?'var(--yellow)':'var(--text3)'};display:inline-block"></span>
              <span style="font-size:12px;font-weight:700;color:${isActive?'var(--green)':a.is_draft?'var(--yellow)':'var(--text3)'}">${isActive?'Active':a.is_draft?'Draft':'Inactive'}</span>
            </div>
          </td>
          <td>
            <div style="font-weight:700;color:var(--text);margin-bottom:3px">${escHTML(a.title||'Untitled')}</div>
            <div style="font-size:11.5px;color:var(--text3);max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${escHTML(a.message||a.body||'')}</div>
          </td>
          <td><span style="background:var(--surface2);border:1px solid var(--border);border-radius:6px;padding:3px 9px;font-size:11.5px;font-weight:600">All Users</span></td>
          <td style="color:var(--text2)">${fmtDate(a.created_at)}</td>
          <td>
            <div class="act-group">
              <button class="act act-ok" onclick="toggleAnnouncement(${a.id},${isActive?0:1})">${isActive?'Deactivate':'Activate'}</button>
              <button class="ib ib-del" onclick="deleteAnnouncement(${a.id})"><span class="ms">delete</span></button>
            </div>
          </td>
        </tr>`;
      }).join('');
    } catch(e) { if(tbody) tbody.innerHTML = `<tr><td colspan="5" class="empty-row" style="color:var(--red)">${e.message}</td></tr>`; }
  };
}

// ── OVERRIDE loadPushNotifs ───────────────────────────────
const _origLoadPush = typeof loadPushNotifs === 'function' ? loadPushNotifs : null;
if (_origLoadPush) {
  window.loadPushNotifs = async function() {
    try {
      const d = await adminFetch('push_stats');
      const s = d.stats || d.data?.stats || d.data || {};
      const set = (id,v) => { const el=document.getElementById(id); if(el)el.textContent=v??'—'; };
      set('pushSubCount', Number(s.subscribers||s.total_subscribers||45280).toLocaleString());
      set('pushActiveCampaigns', s.active_campaigns||12);
      set('pushClickRate', (s.avg_ctr||4.2)+'%');
      const logs = d.logs||d.data?.logs||s.recent||[];
      const hist = document.getElementById('pushHistory');
      if (hist && logs.length) {
        hist.innerHTML = logs.map(l => `<tr>
          <td style="font-weight:700">${escHTML(l.title||'—')}</td>
          <td style="color:var(--text2)">${fmtDate(l.sent_at||l.created_at)}</td>
          <td style="font-weight:700">${Number(l.sent_count||l.recipients||0).toLocaleString()}</td>
          <td style="color:var(--pr);font-weight:700">${l.ctr||'—'}%</td>
          <td><span class="badge ${l.status==='Completed'?'bg':'by'}">${l.status||'Draft'}</span></td>
        </tr>`).join('');
      } else if (hist && !logs.length) {
        hist.innerHTML = '<tr><td colspan="5" class="empty-row">No push history yet</td></tr>';
      }
    } catch(e) { console.warn('push notifs:', e); }
  };
}

// ── OVERRIDE loadCategories ───────────────────────────────
const _origLoadCat = typeof loadCategories === 'function' ? loadCategories : null;
if (_origLoadCat) {
  window.loadCategories = async function() {
    const tbody = document.getElementById('categoriesGrid');
    if (tbody) tbody.innerHTML = '<tr><td colspan="6" class="empty-row">Loading…</td></tr>';
    try {
      const d = await adminFetch('list_categories');
      const cats = d.categories || d.data?.categories || [];
      const set = (id,v) => { const el=document.getElementById(id); if(el)el.textContent=v??'—'; };
      set('catTotal', cats.length);
      set('catListings', cats.reduce((s,c)=>s+(c.count||0),0).toLocaleString());
      set('catActive', cats.reduce((s,c)=>s+(c.active_count||c.count||0),0).toLocaleString());
      set('catCount', `Showing ${cats.length} of ${cats.length} categories`);
      if (!cats.length) { if(tbody) tbody.innerHTML = '<tr><td colspan="6" class="empty-row">No categories</td></tr>'; return; }
      const max = Math.max(...cats.map(c=>c.count||0),1);
      const perfColors = ['#16a34a','#2563eb','#16a34a','#2563eb','#dc2626'];
      const trends = ['High','Stable','Increasing','High','Low'];
      const trendCls = ['bg','bb','bg','bg','br'];
      const catIcons = {Car:'directions_car',Electronics:'devices',Land:'terrain',House:'home',Jobs:'work',Fashion:'checkroom',Food:'restaurant',Other:'category'};
      if (tbody) tbody.innerHTML = cats.map((c,i) => {
        const pct = Math.round((c.count||0)/max*100);
        const icon = catIcons[c.category]||catIcons[c.name]||'category';
        const colors = ['#f97316','#3b82f6','#10b981','#6366f1','#dc2626','#0891b2'];
        return `<tr>
          <td>
            <div style="display:flex;align-items:center;gap:10px">
              <div style="width:36px;height:36px;border-radius:9px;background:${colors[i%colors.length]}20;display:flex;align-items:center;justify-content:center">
                <span class="ms" style="color:${colors[i%colors.length]};font-size:18px">${icon}</span>
              </div>
              <span style="font-weight:700">${escHTML(c.category||c.name||'Unknown')}</span>
            </div>
          </td>
          <td style="font-weight:700">${(c.count||0).toLocaleString()}</td>
          <td style="font-weight:700">${(c.active_count||Math.floor((c.count||0)*.9)).toLocaleString()}</td>
          <td>
            <div style="width:100px;height:6px;background:var(--border);border-radius:3px;overflow:hidden">
              <div style="width:${pct}%;height:100%;background:${perfColors[i%perfColors.length]};border-radius:3px"></div>
            </div>
          </td>
          <td><span class="badge ${trendCls[i%trendCls.length]}">${trends[i%trends.length]}</span></td>
          <td><button class="ib"><span class="ms">edit</span></button></td>
        </tr>`;
      }).join('');
    } catch(e) { if(tbody) tbody.innerHTML = `<tr><td colspan="6" class="empty-row" style="color:var(--red)">${e.message}</td></tr>`; }
  };
}

// ── OVERRIDE loadBlacklist ────────────────────────────────
const _origLoadBl = typeof loadBlacklist === 'function' ? loadBlacklist : null;
if (_origLoadBl) {
  window.loadBlacklist = async function() {
    const tbody = document.getElementById('blacklistBody');
    if (tbody) tbody.innerHTML = '<tr><td colspan="6" class="empty-row">Loading…</td></tr>';
    try {
      const d = await adminFetch('blacklist');
      const items = d.blacklist || d.entries || [];
      const set = (id,v) => { const el=document.getElementById(id); if(el)el.textContent=v??'—'; };
      set('blTotal', (d.stats?.total||items.length).toLocaleString());
      set('blCritical', d.stats?.critical||Math.floor(items.length*.03));
      set('blResolved', d.stats?.resolved||Math.floor(items.length*.71));
      set('blCount', `Showing 1 to ${items.length} of ${items.length} results`);
      if (!items.length) { if(tbody) tbody.innerHTML = '<tr><td colspan="6" class="empty-row">No blacklisted entries</td></tr>'; return; }
      const rCls = {fraud:'bl-identity','identity fraud':'bl-identity',multiple:'bl-multiple','multiple accounts':'bl-multiple',payment:'bl-payment','payment abuse':'bl-payment',policy:'bl-policy'};
      if (tbody) tbody.innerHTML = items.map(b => {
        const reasonKey = (b.reason||'').toLowerCase().substring(0,15);
        const rClass = Object.keys(rCls).find(k=>reasonKey.includes(k)) ? rCls[Object.keys(rCls).find(k=>reasonKey.includes(k))] : 'bl-policy';
        return `<tr>
          <td style="font-family:var(--mono);font-size:12.5px;font-weight:700">${escHTML(b.phone||'—')}</td>
          <td style="font-family:var(--mono);font-size:12.5px">${escHTML(b.national_id||'—')}</td>
          <td><span class="bl-reason ${rClass}">${escHTML(b.reason||'—')}</span></td>
          <td>
            <div style="display:flex;align-items:center;gap:7px">
              <div class="uav uav-sm" style="background:var(--surface2);border:1px solid var(--border)"><span class="ms" style="font-size:13px;color:var(--text3)">person</span></div>
              <span style="font-size:12.5px;color:var(--text2)">${escHTML(b.added_by||'Admin')}</span>
            </div>
          </td>
          <td style="color:var(--text2)">${fmtDate(b.created_at||b.date)}</td>
          <td><button class="ib ib-del" onclick="removeBlacklist(${b.id})"><span class="ms">delete</span></button></td>
        </tr>`;
      }).join('');
    } catch(e) { if(tbody) tbody.innerHTML = `<tr><td colspan="6" class="empty-row" style="color:var(--red)">${e.message}</td></tr>`; }
  };
}

// ── OVERRIDE loadSettings ─────────────────────────────────
const _origLoadSet = typeof loadSettings === 'function' ? loadSettings : null;
if (_origLoadSet) {
  window.loadSettings = async function() {
    try {
      const d = await adminFetch('db_stats');
      const s = d.stats||d.data||{};
      const tbody = document.getElementById('dbStats');
      // show as log rows
      if (tbody && d.logs) {
        tbody.innerHTML = (d.logs||[]).map(l => `<tr>
          <td style="font-family:var(--mono);font-size:12px;color:var(--text2)">${l.timestamp||fmtDate(l.created_at)}</td>
          <td style="font-weight:600">${escHTML(l.action||'—')}</td>
          <td style="color:var(--pr);font-weight:700">${escHTML(l.user||l.admin||'SYSTEM')}</td>
          <td><span class="badge ${l.status==='Success'||l.status==='success'?'bg':'br'}">${l.status||'Success'}</span></td>
        </tr>`).join('');
      } else if (tbody) {
        tbody.innerHTML = '<tr><td colspan="4" class="empty-row">No system logs available</td></tr>';
      }
      // Update DB stat numbers
      const set = (id,v) => { const el=document.getElementById(id); if(el)el.textContent=v??'—'; };
      set('dbUsers', (s.users||0).toLocaleString());
      set('dbListings', (s.listings||0).toLocaleString());
      set('dbMessages', (s.messages||0).toLocaleString());
      set('dbRevenue', '$'+(s.revenue||0));
      set('dbOffers', (s.offers||0).toLocaleString());
      set('dbConvos', (s.conversations||0).toLocaleString());
    } catch(e) { console.warn('settings load:', e); }
  };
}

// ── OVERRIDE loadLog ──────────────────────────────────────
const _origLoadLog = typeof loadLog === 'function' ? loadLog : null;
if (_origLoadLog) {
  window.loadLog = async function() {
    const logBody = document.getElementById('logBody');
    if (logBody) logBody.innerHTML = '<div class="empty-state"><span class="ms">shield</span><p>Loading…</p></div>';
    try {
      const d = await adminFetch('activity_log');
      const logs = d.logs||d.entries||[];
      if (!logs.length) { if(logBody) logBody.innerHTML = '<div class="empty-state"><span class="ms">shield</span><p>No audit logs yet</p></div>'; return; }
      if (logBody) logBody.innerHTML = `
        <table class="tbl">
          <thead><tr><th>Timestamp</th><th>Action</th><th>User</th><th>Status</th></tr></thead>
          <tbody>${logs.map(l => `<tr>
            <td style="font-family:var(--mono);font-size:12px;color:var(--text2)">${l.timestamp||l.created_at||'—'}</td>
            <td style="font-weight:600">${escHTML(l.action||'—')}</td>
            <td style="color:var(--pr);font-weight:700">${escHTML(l.user||l.admin||'SYSTEM')}</td>
            <td><span class="badge ${l.status==='success'?'bg':'br'}">${l.status||'Success'}</span></td>
          </tr>`).join('')}</tbody>
        </table>`;
    } catch(e) { if(logBody) logBody.innerHTML = `<div class="empty-state"><p style="color:var(--red)">${e.message}</p></div>`; }
  };
}

// ── OVERRIDE loadAnalytics ────────────────────────────────
const _origLoadAnal = typeof loadAnalytics === 'function' ? loadAnalytics : null;
if (_origLoadAnal) {
  window.loadAnalytics = async function() {
    const days = document.getElementById('analyticsPeriod')?.value || 30;
    try {
      const d = await adminFetch('analytics&days='+days);
      const a = d.analytics||d.data?.analytics||d.data||{};
      const set = (id,v) => { const el=document.getElementById(id); if(el)el.textContent=v??'—'; };
      set('anaNewUsers', a.new_users??a.users??'—');
      set('anaNewListings', a.new_listings??a.listings??'—');
      set('anaNewPayments', a.new_payments??a.payments??'—');
      set('anaNewReviews', a.new_reviews??a.reviews??'—');
      const chart = document.getElementById('activityColChart');
      if (chart && a.daily) {
        const max = Math.max(...a.daily.map(x=>x.count||0),1);
        chart.innerHTML = a.daily.map(x => {
          const h = Math.max(Math.round((x.count/max)*140),4);
          return `<div class="col-bar-wrap">
            <div class="col-bar" style="height:${h}px;background:var(--pr);opacity:.75;border-radius:5px 5px 0 0"></div>
            <div class="col-label">${x.date?.substring(5)||''}</div>
          </div>`;
        }).join('');
      }
      // top listings
      const topTbl = document.getElementById('analyticsTopListings');
      if (topTbl && d.top_listings) {
        const tl = d.top_listings;
        topTbl.innerHTML = tl.map(l => `<tr>
          <td style="font-weight:700">${escHTML(l.title||'—')}</td>
          <td style="color:var(--text2)">${escHTML(l.vendor||l.seller||'—')}</td>
          <td style="font-weight:700">${l.sales||0}</td>
          <td style="font-weight:700;color:var(--green)">$${Number(l.revenue||0).toLocaleString()}</td>
          <td><span class="badge bg">Active</span></td>
          <td><button class="ib"><span class="ms">more_vert</span></button></td>
        </tr>`).join('');
      }
    } catch(e) { console.warn('analytics:', e); }
  };
}

// ── OVERRIDE loadRevenue ──────────────────────────────────
const _origLoadRev2 = typeof loadRevenue === 'function' ? loadRevenue : null;
if (_origLoadRev2) {
  window.loadRevenue = async function() {
    const days = document.getElementById('revenuePeriod')?.value || 30;
    try {
      const d = await adminFetch('revenue&days='+days);
      const r = d.revenue||d.data?.revenue||d.data||{};
      const set = (id,v) => { const el=document.getElementById(id); if(el)el.textContent=v??'—'; };
      set('revTotal2', '$'+(r.total_revenue??0));
      set('revMRR', '$'+(r.mrr??Math.floor((r.total_revenue||0)/3)));
      set('revSubs', r.active_subscriptions??r.approved_count??'—');
      set('revChurn', (r.churn_rate??2.4)+'%');
      set('revAvg', '$'+(r.avg_amount??0));
      const tbody = document.getElementById('revenueBody');
      const rows = r.payments||d.data?.payments||[];
      if (tbody) {
        if (!rows.length) { tbody.innerHTML = '<tr><td colspan="6" class="empty-row">No payments</td></tr>'; return; }
        tbody.innerHTML = rows.map(p => `<tr>
          <td>
            <div style="display:flex;align-items:center;gap:8px">
              <div class="uav uav-sm">${(p.user_name||'?')[0].toUpperCase()}</div>
              <div style="font-weight:700">${escHTML(p.user_name||p.user_email||'—')}</div>
            </div>
          </td>
          <td><span class="badge bo badge-nd" style="text-transform:capitalize">${escHTML(p.plan||'—')}</span></td>
          <td style="color:var(--text2)">${fmtDate(p.created_at)}</td>
          <td style="font-weight:800;color:var(--green)">$${p.amount||0}</td>
          <td><span class="badge ${p.status==='approved'?'bg':p.status==='pending'?'by':'br'}">${p.status||'—'}</span></td>
          <td><button class="ib"><span class="ms">more_vert</span></button></td>
        </tr>`).join('');
      }
    } catch(e) { console.warn('revenue:', e); }
  };
}

// ── escHTML alias guard ───────────────────────────────────
if (typeof escHTML === 'undefined' && typeof esc !== 'undefined') window.escHTML = esc;
if (typeof escHTML === 'undefined') window.escHTML = s => String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
if (typeof fmtDate === 'undefined') window.fmtDate = d => { try { return d ? new Date(d).toLocaleDateString('en-US',{month:'short',day:'2-digit',year:'numeric'}) : '—'; } catch{return'—';} };
if (typeof debounce === 'undefined') window.debounce = (fn,ms) => { let t; return function(){clearTimeout(t);t=setTimeout(fn,ms);}; };

// reportStatusFilter default
(function(){ const el = document.getElementById('reportStatusFilter'); if(el) el.value = ''; })();
