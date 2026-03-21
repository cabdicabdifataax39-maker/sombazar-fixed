

// ── Auth check ────────────────────────────────────────────
// Auth guard — wrapped for safety
(function() {
  try {
    if (!Auth.isLoggedIn()) {
      window.location.href = 'auth.html?redirect=admin.html';
      return;
    }
    if (!Auth.isAdmin()) {
      var d = document.createElement('div');
      d.style.cssText = 'display:flex;align-items:center;justify-content:center;height:100vh;font-family:sans-serif;flex-direction:column;gap:16px';
      d.innerHTML = '<div style="font-size:40px">⛔</div><h2>Admin Access Required</h2><p>You do not have permission.</p><a href="index.html" style="background:#ec5b13;color:white;padding:10px 24px;border-radius:8px;text-decoration:none;font-weight:700">← Go Back</a>';
      document.body.innerHTML = '';
      document.body.appendChild(d);
      throw new Error('Not admin');
    }
  } catch(e) {
    if (e.message !== 'Not admin') {
      console.warn('Auth check failed:', e);
      // If Auth is not yet available, try again after a tick
      setTimeout(function() {
        try {
          if (!Auth.isLoggedIn()) window.location.href = 'auth.html?redirect=admin.html';
        } catch(e2) { console.error('Auth unavailable:', e2); }
      }, 50);
    } else { throw e; }
  }
})();

// ── Live clock ────────────────────────────────────────────


function updateClock() {
  const el = document.getElementById('adminClock');
  if (el) el.textContent = new Date().toLocaleTimeString('en-US', { hour12: false });
}
setInterval(updateClock, 1000);
updateClock();

// ── Tab system ────────────────────────────────────────────
const ALL_TABS = ['dashboard','verifications','users','listings','payments','blacklist','log','offers','coupons','affiliates','settings','reviews','reports','analytics','revenue','messages','announcements','categories','push'];
const TAB_TITLES = {
  dashboard:'Dashboard', verifications:'Verifications', users:'Users',
  listings:'Listings', payments:'Payments', blacklist:'Blacklist',
  log:'Activity Log', offers:'Offers', coupons:'Coupons',
  affiliates:'Affiliates', settings:'Settings',
  reviews:'Reviews', reports:'Reports', analytics:'Analytics',
  revenue:'Revenue', messages:'Messages', announcements:'Announcements',
  categories:'Categories', push:'Push Notifications'
};

function showTab(tab) {
  ALL_TABS.forEach(t => {
    const el = document.getElementById('tab-' + t);
    if (el) el.classList.remove('active');
  });
  const active = document.getElementById('tab-' + tab);
  if (active) active.classList.add('active');

  document.querySelectorAll('.nav-item').forEach(item => {
    item.classList.remove('active');
    const oc = item.getAttribute('onclick') || '';
    if (oc.includes("'" + tab + "'")) item.classList.add('active');
  });

  const titleEl = document.getElementById('tabTitle');
  if (titleEl) titleEl.textContent = TAB_TITLES[tab] || tab;

  currentTab = tab;
  loadTabContent(tab);
}

let currentTab = 'dashboard';
let userPage = 1;

function refreshCurrentTab() { loadTabContent(currentTab); }

function loadTabContent(tab) {
  switch(tab) {
    case 'dashboard':     loadDashboard();    break;
    case 'verifications': loadVerifications();break;
    case 'users':         loadUsers();        break;
    case 'listings':      loadListingsAdmin();break;
    case 'payments':      loadPayments();     break;
    case 'blacklist':     loadBlacklist();    break;
    case 'log':           loadLog();          break;
    case 'offers':        loadOffers();       break;
    case 'coupons':       loadCoupons();      break;
    case 'affiliates':    loadAffiliates();   break;
    case 'settings':      loadSettings();     break;
    case 'reviews':       loadReviews();      break;
    case 'reports':       loadReports();      break;
    case 'analytics':     loadAnalytics();    break;
    case 'revenue':       loadRevenue();      break;
    case 'messages':      loadAdminMessages();break;
    case 'announcements': loadAnnouncements();break;
    case 'categories':    loadCategories();   break;
    case 'push':          loadPushNotifs();   break;
  }
}

// Set admin name
const _u = Auth.getUser();
if (_u) {
  const name = _u.displayName || 'Admin';
  document.getElementById('adminName').textContent = name;
  const initEl = document.getElementById('sidebarAvatarInitial');
  if (initEl) initEl.textContent = name[0].toUpperCase();
}

const API_ADMIN = 'api/admin.php';
let _csrfToken = null;

async function getCsrfToken() {
  if (_csrfToken) return _csrfToken;
  try {
    const r = await fetch(`${API_ADMIN}?action=csrf_token`, { headers: { 'Authorization': 'Bearer ' + (localStorage.getItem('sb_token') || '') } });
    const d = await r.json();
    if (d.success) { _csrfToken = d.data.csrf_token; return _csrfToken; }
  } catch(e) {}
  return '';
}

async function adminFetch(action, body = null) {
  const token = localStorage.getItem('sb_token') || (Auth.getToken ? Auth.getToken() : '') || '';
  if (!token) { window.location.href = 'auth.html?redirect=admin.html'; throw new Error('Not logged in'); }
  const headers = { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token };
  if (body) { const csrf = await getCsrfToken(); if (csrf) headers['X-CSRF-Token'] = csrf; }
  const opts = { headers };
  if (body) { opts.method = 'POST'; opts.body = JSON.stringify(body); }
  const r = await fetch(`${API_ADMIN}?action=${action}`, opts);
  const txt = await r.text();
  let d;
  try { d = JSON.parse(txt); } catch(e) { throw new Error('Server error'); }
  if (!d.success && d.error && d.error.includes('CSRF')) {
    _csrfToken = null;
    const csrf2 = await getCsrfToken();
    headers['X-CSRF-Token'] = csrf2;
    const r2 = await fetch(`${API_ADMIN}?action=${action}`, { ...opts, headers });
    const d2 = await r2.json();
    if (!d2.success) throw new Error(d2.error || 'Failed');
    return d2.data;
  }
  if (!d.success) throw new Error(d.error || 'Failed');
  return d.data;
}

function adminHeaders() {
  return { 'Authorization': 'Bearer ' + Auth.getToken(), 'Content-Type': 'application/json' };
}

function escHTML(s) {
  return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
const esc = escHTML; // alias
function fmtDate(d) {
  if (!d) return '—';
  try {
    return new Date(d).toLocaleDateString('en-GB', { day:'2-digit', month:'short', year:'numeric', hour:'2-digit', minute:'2-digit' });
  } catch(e) { return d; }
}
function fmtMoney(v) { return '$' + parseFloat(v||0).toFixed(2); }


function debounce(fn, delay) {
  let t;
  return function(...args) { clearTimeout(t); t = setTimeout(() => fn.apply(this, args), delay); };
}

function showAdminToast(msg, type = 'success') {
  const container = document.getElementById('adminToastContainer');
  if (!container) { if (window.showToast) showToast(msg, type); return; }
  const toast = document.createElement('div');
  toast.className = `admin-toast ${type}`;
  toast.textContent = msg;
  container.appendChild(toast);
  setTimeout(() => { toast.style.opacity = '0'; toast.style.transition = 'opacity .3s'; setTimeout(() => toast.remove(), 300); }, 3000);
}

function closeModal(id) {
  const el = document.getElementById(id);
  if (el) el.classList.remove('open');
}

// ── Dashboard ─────────────────────────────────────────────
async function loadDashboard() {
  try {
    const d = await adminFetch('stats');
    const s = d.stats || d || {};
    const set = (id, val) => { const el = document.getElementById(id); if (el) { el.textContent = val ?? 0; el.classList.remove('skel'); el.style.width = ''; el.style.height = ''; } };

    set('statUsers',       s.total_users ?? 0);
    set('statVerified',    s.verified_users ?? 0);
    set('statListings',    s.active_listings ?? 0);
    set('statPending',     s.pending_listings ?? 0);
    set('statMessages',    s.total_messages ?? 0);
    set('statRevenue',     '$' + (s.total_revenue ?? 0));
    set('statPendingPay',  s.pending_payments ?? 0);
    set('statNewToday',    s.new_users_today ?? 0);
    set('statOffers',      s.total_offers ?? 0);
    set('statActiveOffers',s.pending_offers ?? 0);

    set('statUsersWeek',      '+' + (s.new_users_week ?? 0) + ' this week');
    set('statPendingVerif',   (s.pending_verif ?? 0) + ' pending');
    set('statListingsWeek',   '+' + (s.new_listings_week ?? 0) + ' this week');
    set('statPendingTxt',     (s.pending_listings ?? 0) > 0 ? 'Needs review' : 'All clear');
    set('statConversations',  (s.total_conversations ?? 0) + ' conversations');
    set('statPendingPayTxt',  (s.pending_payments ?? 0) > 0 ? 'Needs review' : 'All clear');
    set('statRevenueTxt',     'From approved payments');
    set('statNewTodayTxt',    (s.new_listings_today ?? 0) + ' listings today');
    set('statOffersTxt',      (s.accepted_offers ?? 0) + ' accepted');
    set('statActiveOffersTxt',(s.pending_offers ?? 0) > 0 ? 'Pending responses' : 'All clear');

    // Nav badges
    const pendPay = s.pending_payments ?? 0;
    const pendVerif = s.pending_verif ?? 0;
    const pendList = s.pending_listings ?? 0;
    const nb = (id, val) => { const el = document.getElementById(id); if (el) { el.textContent = val; el.style.display = val > 0 ? '' : 'none'; } };
    nb('navBadgePay', pendPay);
    nb('navBadgeVerif', pendVerif);
    nb('navBadgeListings', pendList);

    // Subtitle
    const sub = document.getElementById('dashSubtitle');
    if (sub) sub.textContent = `${s.total_users ?? 0} users · ${s.active_listings ?? 0} active listings · $${s.total_revenue ?? 0} revenue`;

    renderSignupsChart(s.signups_chart || []);
    renderCatsChart(s.categories || []);
  } catch(e) { console.warn('Dashboard error:', e.message); }
}

function renderSignupsChart(data) {
  const el = document.getElementById('signupsChart');
  if (!el) return;
  if (!data || !data.length) { el.innerHTML = '<div class="empty-state"><small>No signups in last 7 days</small></div>'; return; }
  const max = Math.max(...data.map(d => d.count), 1);
  el.innerHTML = '<div class="chart-bar-wrap">' + data.map(d => {
    const h = Math.max(Math.round((d.count / max) * 80), 4);
    const label = new Date(d.day).toLocaleDateString('en', { weekday: 'short' });
    return `<div class="chart-bar-col">
      <div class="chart-bar-val">${d.count}</div>
      <div class="chart-bar" style="height:${h}px" title="${d.count} on ${d.day}"></div>
      <div class="chart-bar-label">${label}</div>
    </div>`;
  }).join('') + '</div>';
}

function renderCatsChart(data) {
  const el = document.getElementById('catsChart');
  if (!el) return;
  if (!data || !data.length) { el.innerHTML = '<div class="empty-state"><small>No active listings</small></div>'; return; }
  const total = data.reduce((s, d) => s + d.count, 0);
  const colors = ['#f97316','#3b82f6','#10b981','#8b5cf6','#f59e0b','#ef4444','#06b6d4','#ec4899'];
  el.innerHTML = data.slice(0, 8).map((d, i) => {
    const pct = total > 0 ? Math.round((d.count / total) * 100) : 0;
    return `<div class="cat-row">
      <div class="cat-dot" style="background:${colors[i % colors.length]}"></div>
      <div class="cat-name">${escHTML(d.category)}</div>
      <div class="cat-bar-track"><div class="cat-bar-fill" style="width:${pct}%;background:${colors[i % colors.length]}"></div></div>
      <div class="cat-count">${d.count}</div>
    </div>`;
  }).join('');
}

// ── Verifications ─────────────────────────────────────────
const docLabels = {
  'national_id_front':'ID Front','national_id_back':'ID Back','selfie':'Selfie',
  'trade_license':'Trade License','authority_id':'Authority ID','office_photo':'Office',
  'id_front':'ID Front','id_back':'ID Back'
};

async function loadVerifications() {
  const list = document.getElementById('verificationsBody');
  if (!list) return;
  list.innerHTML = '<div class="empty-state"><p>Loading…</p></div>';
  try {
    const d = await adminFetch('verifications');
    const items = Array.isArray(d) ? d : (d.verifications || []);
    if (!items.length) {
      list.innerHTML = '<div class="empty-state"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg><p>All caught up!</p><small>No pending verifications</small></div>';
      return;
    }
    list.innerHTML = items.map(v => {
      const docs = v.docs || [];
      const docsHTML = docs.length ? `<div class="verif-docs">${docs.map(doc => {
        const label = docLabels[doc.type] || doc.type;
        const url = doc.url || '';
        return `<div class="doc-thumb" onclick="window.open('${escHTML(url)}','_blank')">
          <img src="${escHTML(url)}" onerror="this.parentElement.innerHTML='<a href=\\'${escHTML(url)}\\' target=\\'_blank\\' style=\\'display:flex;align-items:center;justify-content:center;height:56px;font-size:11px;color:var(--blue-t)\\'>📄 View</a>'">
          <div class="doc-thumb-label">${escHTML(label)}</div>
        </div>`;
      }).join('')}</div>` : '';

      return `<div class="verif-card">
        <div style="flex:1">
          <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px">
            <div class="u-avatar">${(v.displayName||v.display_name||'?')[0].toUpperCase()}</div>
            <div>
              <div style="font-weight:700;font-size:14px">${escHTML(v.displayName||v.display_name||'—')}</div>
              <div style="font-size:12px;color:var(--text3)">${escHTML(v.email||'')} · #${v.id}</div>
            </div>
            <span class="badge badge-yellow" style="margin-left:8px">Pending</span>
          </div>
          <div style="font-size:11px;color:var(--text3)">Submitted ${new Date(v.createdAt||v.created_at||Date.now()).toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'})}</div>
          ${docsHTML}
        </div>
        <div style="display:flex;flex-direction:column;gap:8px;flex-shrink:0">
          <button onclick="verifyUser(${v.id},'approved')" class="btn btn-success btn-sm">✓ Approve</button>
          <button onclick="verifyUser(${v.id},'rejected')" class="btn btn-danger btn-sm">✕ Reject</button>
        </div>
      </div>`;
    }).join('');
  } catch(e) { list.innerHTML = `<div class="empty-state"><p style="color:var(--red)">${e.message}</p></div>`; }
}

async function verifyUser(id, status) {
  const action = status === 'approved' ? 'approve' : 'reject';
  try {
    await adminFetch('verify_user', { user_id: id, action });
    showAdminToast(action === 'approve' ? '✓ User approved' : 'User rejected', action === 'approve' ? 'success' : 'error');
    loadVerifications();
    loadDashboard();
  } catch(e) { showAdminToast(e.message, 'error'); }
}

// ── Users ─────────────────────────────────────────────────
async function loadUsers() {
  const tbody = document.getElementById('usersBody');
  if (!tbody) return;
  tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:32px;color:var(--text3)">Loading…</td></tr>';
  try {
    const search = document.getElementById('userSearch')?.value || '';
    const filter = document.getElementById('userFilter')?.value || '';
    const d = await adminFetch(`users&q=${encodeURIComponent(search)}&status=${filter}&page=${userPage}`);
    const users = d.users || [];
    const countEl = document.getElementById('userCount');
    if (countEl) countEl.textContent = users.length;
    if (!users.length) {
      tbody.innerHTML = '<tr><td colspan="7"><div class="empty-state"><p>No users found</p></div></td></tr>';
      return;
    }
    tbody.innerHTML = users.map(u => {
      const name = escHTML(u.display_name || u.displayName || '—');
      const joined = u.createdAt || u.created_at ? new Date(u.createdAt||u.created_at).toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'2-digit'}) : '—';
      const statusBadge = u.banned
        ? '<span class="badge badge-red">Banned</span>'
        : u.verified
          ? '<span class="badge badge-green">Verified</span>'
          : '<span class="badge badge-yellow">Pending</span>';
      const planBadge = u.plan && u.plan !== 'free'
        ? `<span class="badge badge-purple" style="text-transform:capitalize">${escHTML(u.plan)}</span>`
        : `<span class="badge badge-gray">Free</span>`;
      return `<tr>
        <td>
          <div style="display:flex;align-items:center;gap:10px">
            <div class="u-avatar">${name[0]||'?'}</div>
            <div>
              <div style="font-weight:700">${name}</div>
              <div style="font-size:11px;color:var(--text3)">#${u.id}</div>
            </div>
          </div>
        </td>
        <td style="color:var(--text2)">${escHTML(u.email||'—')}</td>
        <td style="color:var(--text3)">${escHTML(u.phone||'—')}</td>
        <td>${statusBadge}</td>
        <td>${planBadge}</td>
        <td style="color:var(--text3);font-size:12px">${joined}</td>
        <td>
          <div class="action-group">
            <button onclick="${u.banned ? `unbanUser(${u.id})` : `openBanModal(${u.id})`}" class="act-btn ${u.banned ? 'act-approve' : 'act-delete'}">
              ${u.banned ? '✓ Unban' : '⊘ Ban'}
            </button>
          </div>
        </td>
      </tr>`;
    }).join('');
  } catch(e) { tbody.innerHTML = `<tr><td colspan="7" style="color:var(--red);padding:16px">${e.message}</td></tr>`; }
}

function openBanModal(id) {
  document.getElementById('banUserId').value = id;
  document.getElementById('banReason').value = '';
  document.getElementById('banModalTitle').textContent = 'Ban User #' + id;
  document.getElementById('banModal').classList.add('open');
}

async function unbanUser(id) {
  if (!confirm('Unban this user?')) return;
  try { await adminFetch('ban_user', { user_id: id, ban: false, reason: '' }); showAdminToast('User unbanned', 'success'); loadUsers(); }
  catch(e) { showAdminToast(e.message, 'error'); }
}

async function confirmBan() {
  const id = parseInt(document.getElementById('banUserId').value);
  const reason = document.getElementById('banReason').value.trim();
  if (!reason) { showAdminToast('Please enter a ban reason', 'error'); return; }
  try {
    await adminFetch('ban_user', { user_id: id, ban: true, reason });
    closeModal('banModal');
    showAdminToast('User banned', 'success');
    loadUsers();
  } catch(e) { showAdminToast(e.message, 'error'); }
}

// ── Listings ──────────────────────────────────────────────
var _bulkSelected = new Set();

async function loadListingsAdmin() {
  const tbody = document.getElementById('listingsBody');
  if (!tbody) return;
  tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:32px;color:var(--text3)">Loading…</td></tr>';
  try {
    const filter = document.getElementById('listingFilter')?.value || 'all';
    const search = encodeURIComponent(document.getElementById('listingSearch')?.value || '');
    const d = await adminFetch(`listings&status=${filter}&q=${search}`);
    const items = d.listings || [];
    const countEl = document.getElementById('listingsCount');
    if (countEl) countEl.textContent = items.length;
    if (!items.length) {
      tbody.innerHTML = '<tr><td colspan="8"><div class="empty-state"><p>No listings found</p></div></td></tr>';
      return;
    }
    const statusClass = { active:'badge-green', pending:'badge-yellow', sold:'badge-blue', rented:'badge-purple', rejected:'badge-red', deleted:'badge-gray', expired:'badge-gray' };
    tbody.innerHTML = items.map(l => {
      const sc = statusClass[l.status] || 'badge-gray';
      const dt = l.createdAt ? new Date(l.createdAt).toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'2-digit'}) : '—';
      const boostedBadge = l.boostedUntil && new Date(l.boostedUntil) > new Date() ? '<span class="badge badge-purple" style="margin-left:4px;font-size:9px">⚡ Boosted</span>' : '';
      return `<tr id="lrow-${l.id}">
        <td><input type="checkbox" class="bulk-cb" value="${l.id}" onchange="onBulkCbChange(${l.id},this.checked)"></td>
        <td>
          <a href="listing.html?id=${l.id}" target="_blank" style="font-weight:700;color:var(--primary);text-decoration:none;display:block;max-width:190px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${escHTML(l.title)}</a>
          <div style="display:flex;align-items:center;gap:4px;margin-top:2px">
            <span style="font-size:11px;color:var(--text3)">#${l.id} · ${escHTML(l.category||'—')}</span>
            ${boostedBadge}
          </div>
        </td>
        <td style="color:var(--text2);max-width:110px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${escHTML(l.seller||'—')}</td>
        <td style="font-weight:700;white-space:nowrap">$${Number(l.price||0).toLocaleString()}</td>
        <td><span class="badge ${sc}" style="text-transform:capitalize">${l.status}</span></td>
        <td style="color:var(--text3);text-align:center;font-family:var(--mono)">${l.views||0}</td>
        <td style="color:var(--text3);font-size:12px">${dt}</td>
        <td>
          <div class="action-group">
            <button onclick="approveListing(${l.id})" class="act-btn act-approve">✓</button>
            <button onclick="rejectListingAdmin(${l.id})" class="act-btn act-reject">✕</button>
            <button onclick="deleteListing(${l.id})" class="act-btn act-delete">🗑</button>
          </div>
        </td>
      </tr>`;
    }).join('');
  } catch(e) { tbody.innerHTML = `<tr><td colspan="8" style="color:var(--red);padding:16px">${e.message}</td></tr>`; }
}

// alias
function loadListings() { loadListingsAdmin(); }

function onBulkCbChange(id, checked) {
  if (checked) _bulkSelected.add(id); else _bulkSelected.delete(id);
  const bar = document.getElementById('bulkBar');
  const cnt = document.getElementById('bulkCount');
  if (bar) bar.classList.toggle('visible', _bulkSelected.size > 0);
  if (cnt) cnt.textContent = _bulkSelected.size + ' selected';
}

function toggleSelectAllListings(cb) {
  document.querySelectorAll('.bulk-cb').forEach(el => {
    el.checked = cb.checked;
    onBulkCbChange(parseInt(el.value), cb.checked);
  });
}

function rejectListingAdmin(id) {
  const reason = prompt('Rejection reason (shown to seller):', '');
  if (reason === null) return;
  adminFetch('reject_listing', { listing_id: id, note: reason || 'Does not meet our listing guidelines.' })
    .then(() => { showAdminToast('Listing rejected', 'success'); loadListingsAdmin(); })
    .catch(e => showAdminToast(e.message, 'error'));
}

async function approveListing(id) {
  try { await adminFetch('approve_listing', { listing_id: id }); showAdminToast('✓ Listing approved'); loadListingsAdmin(); }
  catch(e) { showAdminToast(e.message, 'error'); }
}

async function deleteListing(id) {
  if (!confirm('Delete this listing permanently?')) return;
  try { await adminFetch('delete_listing', { listing_id: id }); showAdminToast('Listing deleted'); loadListingsAdmin(); }
  catch(e) { showAdminToast(e.message, 'error'); }
}

function clearBulkSelect() {
  _bulkSelected.clear();
  document.querySelectorAll('.bulk-cb:checked').forEach(cb => cb.checked = false);
  const bar = document.getElementById('bulkBar');
  if (bar) bar.classList.remove('visible');
}

async function bulkAction(action) {
  const ids = Array.from(_bulkSelected);
  if (!ids.length) { showAdminToast('Nothing selected', 'info'); return; }
  if (!confirm(action + ' ' + ids.length + ' listings?')) return;
  try {
    for (const id of ids) {
      if (action === 'approve') await adminFetch('approve_listing', { listing_id: id });
      else if (action === 'delete') await adminFetch('delete_listing', { listing_id: id });
    }
    clearBulkSelect();
    loadListingsAdmin();
    showAdminToast(ids.length + ' listings ' + action + 'd', 'success');
  } catch(e) { showAdminToast(e.message, 'error'); }
}

// ── Payments ──────────────────────────────────────────────
async function loadPayments() {
  const tbody = document.getElementById('paymentsBody');
  if (!tbody) return;
  tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:32px;color:var(--text3)">Loading…</td></tr>';
  try {
    const filter = document.getElementById('paymentFilter')?.value || 'pending';
    const d = await adminFetch(`payments&status=${filter}`);
    const items = d.payments || [];
    if (!items.length) {
      tbody.innerHTML = '<tr><td colspan="8"><div class="empty-state"><p>No payments</p></div></td></tr>';
      return;
    }
    const statusClass = { pending:'badge-yellow', approved:'badge-green', rejected:'badge-red' };
    tbody.innerHTML = items.map(p => {
      const sc = statusClass[p.status] || 'badge-gray';
      const dt = p.createdAt ? new Date(p.createdAt).toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'2-digit'}) : '—';
      const _ssUrl = p.screenshotUrl || p.receiptUrl || p.screenshot_url || null;
      const screenshotHTML = _ssUrl
        ? `<a href="${escHTML(_ssUrl)}" target="_blank" rel="noopener" class="btn btn-ghost btn-xs">📎 View</a>`
        : '<span style="color:var(--text3);font-size:12px">—</span>';
      const couponHTML = p.couponCode
        ? `<div style="margin-top:3px"><span style="font-size:10px;background:var(--primary-s);color:var(--primary);padding:1px 6px;border-radius:4px;font-weight:700">${escHTML(p.couponCode)} −$${p.discountAmount||0}</span></div>`
        : '';
      return `<tr>
        <td>
          <div style="font-weight:700">${escHTML(p.userName||'—')}</div>
          <div style="font-size:11px;color:var(--text3)">${escHTML(p.userEmail||'')}</div>
        </td>
        <td><span class="badge badge-purple" style="text-transform:capitalize">${escHTML(p.plan||'—')}</span></td>
        <td>
          <div style="font-weight:800;font-family:var(--mono)">$${p.amount}</div>
          ${couponHTML}
        </td>
        <td><span class="method-badge">${escHTML(p.method||'—')}</span></td>
        <td><span style="font-family:var(--mono);font-size:12px;color:var(--text2)">${escHTML(p.referenceCode||'—')}</span></td>
        <td>${screenshotHTML}</td>
        <td style="font-size:12px;color:var(--text3)">${dt}</td>
        <td>
          ${p.status === 'pending'
            ? `<div class="action-group">
                <button onclick="approvePayment(${p.id})" class="act-btn act-approve">✓ Approve</button>
                <button onclick="rejectPayment(${p.id})" class="act-btn act-delete">✕ Reject</button>
              </div>`
            : `<span class="badge ${sc}" style="text-transform:capitalize">${p.status}</span>`
          }
        </td>
      </tr>`;
    }).join('');
  } catch(e) { tbody.innerHTML = `<tr><td colspan="8" style="color:var(--red);padding:16px">${e.message}</td></tr>`; }
}

async function approvePayment(id) {
  try { await adminFetch('approve_payment', { payment_id: id }); showAdminToast('✓ Payment approved', 'success'); loadPayments(); loadDashboard(); }
  catch(e) { showAdminToast(e.message, 'error'); }
}
async function rejectPayment(id) {
  if (!confirm('Reject this payment?')) return;
  try { await adminFetch('reject_payment', { payment_id: id }); showAdminToast('Payment rejected'); loadPayments(); }
  catch(e) { showAdminToast(e.message, 'error'); }
}

// ── Blacklist ─────────────────────────────────────────────
async function loadBlacklist() {
  const list = document.getElementById('blacklistBody');
  if (!list) return;
  list.innerHTML = '<tr><td colspan="6" style="padding:20px;color:var(--text3);text-align:center">Loading…</td></tr>';
  try {
    const d = await adminFetch('blacklist');
    const items = Array.isArray(d) ? d : (d.blacklist || []);
    if (!items.length) { list.innerHTML = '<tr><td colspan="6"><div class="empty-state"><p>Blacklist is empty</p></div></td></tr>'; return; }
    list.innerHTML = items.map(b => `<tr>
      <td style="font-family:var(--mono)">${escHTML(b.phone||'—')}</td>
      <td style="font-family:var(--mono)">${escHTML(b.national_id||'—')}</td>
      <td style="color:var(--text2)">${escHTML(b.reason||'—')}</td>
      <td style="color:var(--text3)">${escHTML(b.added_by_name||'Admin')}</td>
      <td style="font-size:11px;color:var(--text3)">${b.created_at ? new Date(b.created_at).toLocaleDateString() : '—'}</td>
      <td><button onclick="removeBlacklist(${b.id})" class="act-btn act-delete">Remove</button></td>
    </tr>`).join('');
  } catch(e) { list.innerHTML = `<tr><td colspan="6" style="color:var(--red);padding:16px">${e.message}</td></tr>`; }
}

async function removeBlacklist(id) {
  try { await adminFetch('remove_blacklist', { id }); showAdminToast('Removed from blacklist'); loadBlacklist(); }
  catch(e) { showAdminToast(e.message, 'error'); }
}

async function addToBlacklist() {
  const phone  = document.getElementById('blPhone')?.value.trim();
  const natId  = document.getElementById('blNatId')?.value.trim();
  const reason = document.getElementById('blReason')?.value.trim();
  if (!phone && !natId) return showAdminToast('Enter phone or national ID', 'error');
  if (!reason) return showAdminToast('Reason is required', 'error');
  try { await adminFetch('add_blacklist', { phone, national_id: natId, reason }); showAdminToast('Added to blacklist'); loadBlacklist(); }
  catch(e) { showAdminToast(e.message, 'error'); }
}

// ── Activity Log ──────────────────────────────────────────
const actionColors = {
  approve:'var(--green)', reject:'var(--red)', ban:'var(--red)', delete:'var(--red)',
  create:'var(--blue)', update:'var(--yellow)', login:'var(--purple)', export:'var(--primary)'
};

async function loadLog() {
  const list = document.getElementById('logBody');
  if (!list) return;
  list.innerHTML = '<div class="empty-state"><p>Loading…</p></div>';
  try {
    const d = await adminFetch('log');
    const items = Array.isArray(d) ? d : (d.log || []);
    if (!items.length) { list.innerHTML = '<div class="empty-state"><p>No log entries yet</p></div>'; return; }
    list.innerHTML = items.map(l => {
      const color = actionColors[l.action?.toLowerCase()] || 'var(--primary)';
      const time = new Date(l.created_at).toLocaleString('en-GB',{day:'2-digit',month:'short',hour:'2-digit',minute:'2-digit'});
      return `<div class="log-item">
        <div class="log-dot" style="background:${color}"></div>
        <span class="log-action">${escHTML(l.action||'—')}</span>
        <span class="log-note">${escHTML(l.note||l.target||'')}</span>
        <span class="log-time">${time}</span>
      </div>`;
    }).join('');
  } catch(e) { list.innerHTML = `<div class="empty-state"><p style="color:var(--red)">${e.message}</p></div>`; }
}

// ── Offers ────────────────────────────────────────────────
async function loadOffers() {
  const tbody = document.getElementById('offersBody');
  if (!tbody) return;
  tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:32px;color:var(--text3)">Loading…</td></tr>';
  try {
    const statusFilter = document.getElementById('offerStatusFilter')?.value || 'all';
    const d = await adminFetch('all_offers');
    let items = d.offers || [];
    if (statusFilter !== 'all') items = items.filter(o => o.status === statusFilter);
    if (!items.length) { tbody.innerHTML = '<tr><td colspan="7"><div class="empty-state"><p>No offers found</p></div></td></tr>'; return; }
    const statusClass = { pending:'badge-yellow', accepted:'badge-green', rejected:'badge-red', countered:'badge-purple', cancelled:'badge-gray', expired:'badge-gray' };
    tbody.innerHTML = items.map(o => `<tr>
      <td><a href="listing.html?id=${o.listingId}" target="_blank" style="color:var(--primary);font-weight:600;text-decoration:none">${escHTML(o.listingTitle||'#'+o.listingId)}</a></td>
      <td style="color:var(--text2)">${escHTML(o.buyerName||'—')}</td>
      <td style="color:var(--text2)">${escHTML(o.sellerName||'—')}</td>
      <td style="font-weight:700;font-family:var(--mono)">$${Number(o.amount||0).toLocaleString()}</td>
      <td style="text-align:center"><span style="background:var(--surface2);border:1px solid var(--border);border-radius:6px;padding:2px 8px;font-size:11px;font-weight:700">${o.round||1}</span></td>
      <td><span class="badge ${statusClass[o.status]||'badge-gray'}" style="text-transform:capitalize">${o.status}</span></td>
      <td style="font-size:12px;color:var(--text3)">${o.createdAt ? new Date(o.createdAt).toLocaleDateString('en-GB') : '—'}</td>
    </tr>`).join('');
  } catch(e) { tbody.innerHTML = `<tr><td colspan="7" style="color:var(--red);padding:16px">${e.message}</td></tr>`; }
}

function loadAdminOffers() { loadOffers(); }

// ── Coupons ───────────────────────────────────────────────
function toggleCouponForm() { openCouponModal(); }

function openCouponModal() {
  document.getElementById('couponModal').classList.add('open');
  ['couponCode','couponDiscount','couponMax','couponExpiry'].forEach(id => { const el = document.getElementById(id); if (el) el.value = ''; });
}

function closeCouponModal() {
  document.getElementById('couponModal').classList.remove('open');
}

document.addEventListener('keydown', e => { if (e.key === 'Escape') { closeCouponModal(); closeModal('banModal'); } });

async function loadCoupons() {
  const list = document.getElementById('couponList');
  try {
    const r = await fetch('api/admin.php?action=get_coupons', { headers: adminHeaders() });
    const d = await r.json();
    if (!d.success || !d.data.coupons.length) {
      list.innerHTML = '<div class="empty-state"><p>No coupons yet</p><small>Create your first discount code</small></div>';
      return;
    }
    list.innerHTML = '<div class="table-wrap"><table><thead><tr><th>Code</th><th>Type</th><th>Value</th><th>Uses</th><th>Expires</th><th>Status</th><th>Actions</th></tr></thead><tbody>' +
      d.data.coupons.map(cp => `<tr>
        <td><span class="coupon-code">${escHTML(cp.code)}</span></td>
        <td style="text-transform:capitalize;color:var(--text2)">${cp.type}</td>
        <td style="font-weight:700">${cp.type === 'percent' ? cp.value + '%' : '$' + cp.value}</td>
        <td style="color:var(--text3)">${cp.uses_count}${cp.max_uses > 0 ? '/' + cp.max_uses : ' / ∞'}</td>
        <td style="color:var(--text3);font-size:12px">${cp.expires_at ? new Date(cp.expires_at).toLocaleDateString() : '∞'}</td>
        <td><span class="badge ${cp.is_active ? 'badge-green' : 'badge-gray'}">${cp.is_active ? 'Active' : 'Inactive'}</span></td>
        <td><div class="action-group">
          <button onclick="toggleCoupon(${cp.id})" class="act-btn act-view">${cp.is_active ? 'Disable' : 'Enable'}</button>
          <button onclick="deleteCoupon(${cp.id})" class="act-btn act-delete">Delete</button>
        </div></td>
      </tr>`).join('') +
      '</tbody></table></div>';
  } catch(e) { list.innerHTML = `<div class="empty-state"><p style="color:var(--red)">${e.message}</p></div>`; }
}

async function createCoupon() {
  const code  = (document.getElementById('couponCode')?.value || '').trim().toUpperCase();
  const value = parseFloat(document.getElementById('couponDiscount')?.value || 0);
  const uses  = parseInt(document.getElementById('couponMax')?.value || 0) || 0;
  const exp   = document.getElementById('couponExpiry')?.value || '';
  const type  = 'percent';
  if (!code || !value) return showAdminToast('Code and discount % required', 'error');
  try {
    const r = await fetch('api/admin.php?action=create_coupon', {
      method: 'POST', headers: { ...adminHeaders(), 'Content-Type': 'application/json' },
      body: JSON.stringify({ code, type, value, max_uses: uses, expires_at: exp || null })
    });
    const d = await r.json();
    if (!d.success) throw new Error(d.error);
    showAdminToast('✓ Coupon ' + code + ' created!', 'success');
    closeCouponModal();
    loadCoupons();
  } catch(e) { showAdminToast(e.message, 'error'); }
}
// Alias: admin.html calls saveCoupon() but function is createCoupon()
const saveCoupon = createCoupon;

async function toggleCoupon(id) {
  const r = await fetch('api/admin.php?action=toggle_coupon&id=' + id, { headers: adminHeaders() });
  const d = await r.json();
  if (d.success) { showAdminToast('Coupon updated'); loadCoupons(); }
}

async function deleteCoupon(id) {
  if (!confirm('Delete this coupon?')) return;
  const r = await fetch('api/admin.php?action=delete_coupon&id=' + id, { headers: adminHeaders() });
  const d = await r.json();
  if (d.success) { showAdminToast('Deleted', 'success'); loadCoupons(); }
}

// ── Affiliates ────────────────────────────────────────────
async function loadAffiliates() {
  const list = document.getElementById('affiliateList');
  try {
    const r = await fetch('api/admin.php?action=get_affiliates', { headers: adminHeaders() });
    const d = await r.json();
    if (!d.success || !d.data.affiliates.length) {
      list.innerHTML = '<tr><td colspan="8" class="empty-row">No affiliates yet. Users can apply from their profile page.</td></tr>';
      return;
    }
    list.innerHTML = d.data.affiliates.map(a => {
      const isPending  = !a.is_active && a.status !== 'rejected';
      const isApproved = !!parseInt(a.is_active);
      const statusBadge = isPending
        ? '<span style="background:#fef9c3;color:#854d0e;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700">Pending</span>'
        : isApproved
          ? '<span style="background:#dcfce7;color:#166534;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700">Active</span>'
          : '<span style="background:#fee2e2;color:#991b1b;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700">Inactive</span>';
      const payBtn = a.pending_payout > 0
        ? `<button onclick="markAffiliatePaid(${a.id},${a.pending_payout})" class="act-btn act-approve">Mark Paid $${parseFloat(a.pending_payout).toFixed(2)}</button>`
        : '';
      const approveBtn = isPending
        ? `<button onclick="approveAffiliate(${a.user_id})" class="act-btn act-approve">Approve</button>`
        : '';
      const toggleBtn = `<button onclick="toggleAffiliate(${a.id})" class="act-btn act-view">${isApproved ? 'Disable' : 'Enable'}</button>`;
      return `<tr>
        <td><div style="font-weight:700">${escHTML(a.display_name)}</div><div style="font-size:11px;color:var(--text3)">${escHTML(a.email)}</div></td>
        <td>${a.ref_code ? `<span class="coupon-code">${escHTML(a.ref_code)}</span>` : '—'}</td>
        <td>${statusBadge}</td>
        <td style="font-weight:700;font-family:var(--mono)">${a.total_referrals}</td>
        <td style="font-weight:700;font-family:var(--mono)">$${parseFloat(a.total_earned).toFixed(2)}</td>
        <td style="font-weight:700;color:${a.pending_payout > 0 ? 'var(--primary)' : 'var(--text3)'}">$${parseFloat(a.pending_payout).toFixed(2)}</td>
        <td style="font-family:var(--mono);font-size:11px">${a.commission_rate}%</td>
        <td style="display:flex;gap:4px;flex-wrap:wrap">${approveBtn}${toggleBtn}${payBtn}</td>
      </tr>`;
    }).join('');
  } catch(e) { list.innerHTML = `<tr><td colspan="8" style="color:var(--red);padding:12px">${e.message}</td></tr>`; }
}

async function approveAffiliate(userId) {
  if (!confirm('Approve this affiliate?')) return;
  const r = await fetch('api/admin.php?action=approve_affiliate', {
    method: 'POST', headers: { ...adminHeaders(), 'Content-Type': 'application/json' },
    body: JSON.stringify({ user_id: userId })
  });
  const d = await r.json();
  if (d.success) { showAdminToast('✓ Affiliate approved — code: ' + d.data.ref_code, 'success'); loadAffiliates(); }
  else showAdminToast(d.error || 'Failed', 'error');
}

async function toggleAffiliate(id) {
  const r = await fetch('api/admin.php?action=toggle_affiliate', {
    method: 'POST', headers: { ...adminHeaders(), 'Content-Type': 'application/json' },
    body: JSON.stringify({ id })
  });
  const d = await r.json();
  if (d.success) { showAdminToast('Affiliate updated', 'success'); loadAffiliates(); }
  else showAdminToast(d.error || 'Failed', 'error');
}

async function markAffiliatePaid(id, amount) {
  if (!confirm(`Mark $${amount} as paid to this affiliate?`)) return;
  const r = await fetch('api/admin.php?action=affiliate_payout', {
    method: 'POST', headers: { ...adminHeaders(), 'Content-Type': 'application/json' },
    body: JSON.stringify({ affiliate_id: id, amount })
  });
  const d = await r.json();
  if (d.success) { showAdminToast('✓ Payout recorded', 'success'); loadAffiliates(); }
  else showAdminToast(d.error || 'Failed', 'error');
}

// ── Settings ──────────────────────────────────────────────
async function loadSettings() {
  load2FAStatus();
  loadLastBackupTime();
  try {
    const d = await adminFetch('stats');
    const s = d.stats || d || {};
    const dbEl = document.getElementById('dbStats');
    if (dbEl) {
      dbEl.innerHTML = `
        <div class="db-stat-box"><div class="db-stat-num">${s.total_users ?? '—'}</div><div class="db-stat-lbl">Users</div></div>
        <div class="db-stat-box"><div class="db-stat-num">${s.total_listings ?? '—'}</div><div class="db-stat-lbl">Listings</div></div>
        <div class="db-stat-box"><div class="db-stat-num">${s.total_messages ?? '—'}</div><div class="db-stat-lbl">Messages</div></div>
        <div class="db-stat-box"><div class="db-stat-num" style="color:var(--green)">$${s.total_revenue ?? 0}</div><div class="db-stat-lbl">Revenue</div></div>
        <div class="db-stat-box"><div class="db-stat-num">${s.total_offers ?? '—'}</div><div class="db-stat-lbl">Offers</div></div>
        <div class="db-stat-box"><div class="db-stat-num">${s.total_conversations ?? '—'}</div><div class="db-stat-lbl">Convos</div></div>`;
    }
  } catch(e) { const el = document.getElementById('dbStats'); if (el) el.innerHTML = '<div class="empty-state"><small>Could not load stats</small></div>'; }
}

// ── Helpers ───────────────────────────────────────────────
function logout() {
  if (window.Auth) Auth.clearSession();
  else { localStorage.removeItem('sb_token'); localStorage.removeItem('sb_user'); }
  window.location.href = 'auth.html';
}

function exportCSV(type) {
  const token = (window.Auth ? Auth.getToken() : localStorage.getItem('sb_token')) || '';
  window.open('api/admin.php?action=export_csv&type=' + encodeURIComponent(type) + '&token=' + token, '_blank');
}

async function cleanExpired() {
  if (!confirm('Clean expired data?')) return;
  try {
    await adminFetch('clean');
    showAdminToast('✓ Cleanup complete', 'success');
    const msg = document.getElementById('maintenanceMsg');
    if (msg) msg.textContent = 'Cleanup completed at ' + new Date().toLocaleTimeString();
    loadOffers();
  } catch(e) { showAdminToast(e.message, 'error'); }
}

function confirmReject() {
  const id = window._rejectListingId;
  const reason = (document.getElementById('rejectReason') || {}).value || '';
  if (!id) return;
  adminFetch('reject_listing', { listing_id: id, reason })
    .then(() => { showAdminToast('Listing rejected', 'success'); loadListingsAdmin(); closeModal('rejectModal'); })
    .catch(e => showAdminToast(e.message, 'error'));
}

// ── Init ──────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  showTab('dashboard');
});

// ── Reports ──────────────────────────────────────────────────────────────
async function loadReports() {
  const status = document.getElementById('reportStatusFilter')?.value ?? 'pending';
  const tbody = document.getElementById('reportsBody');
  if (!tbody) return;
  tbody.innerHTML = '<tr><td colspan="6" class="empty-row">Loading…</td></tr>';
  try {
    const d = await adminFetch('list_reports' + (status ? '&status=' + status : ''));
    const rows = d.reports || d.data?.reports || [];
    if (!rows.length) { tbody.innerHTML = '<tr><td colspan="6" class="empty-row">No reports found</td></tr>'; return; }
    const rc2 = document.getElementById('reportCount');
    if(rc2) rc2.textContent = rows.length + ' reports';
    const badge = document.getElementById('navBadgeReports');
    if (badge && status === 'pending') { badge.textContent = rows.length; badge.style.display = rows.length ? '' : 'none'; }
    tbody.innerHTML = rows.map(r => `<tr>
      <td><span style="font-weight:600;color:var(--text)">${esc(r.reporter_name || r.reporter_email || '#'+r.reporter_id)}</span></td>
      <td><span class="badge badge-yellow">${esc(r.report_type||r.type||'listing')}</span></td>
      <td style="color:var(--text2)">${esc(r.target_title||r.target_id||'—')}</td>
      <td style="color:var(--text2);max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc(r.reason||'—')}</td>
      <td style="color:var(--text3)">${fmtDate(r.created_at)}</td>
      <td style="text-align:right;display:flex;gap:6px;justify-content:flex-end">
        <button class="btn btn-xs" style="background:var(--green-s);color:var(--green-t);border:none" onclick="resolveReport(${r.id},'reviewed')">✓ Resolve</button>
        <button class="btn btn-xs" style="background:var(--red-s);color:var(--red-t);border:none" onclick="resolveReport(${r.id},'dismissed')">Dismiss</button>
      </td>
    </tr>`).join('');
  } catch(e) { tbody.innerHTML = `<tr><td colspan="6" class="empty-row">${e.message}</td></tr>`; }
}
async function resolveReport(id, status) {
  const action = status === 'dismissed' ? 'dismiss' : 'resolve';
  const d = await adminFetch('resolve_report', { report_id: id, action, status });
  if (d.success !== false) { showAdminToast('Report updated', 'success'); loadReports(); }
  else showAdminToast(d.error || 'Failed', 'error');
}

// ── Reviews ──────────────────────────────────────────────────────────────
async function loadReviews() {
  const tbody = document.getElementById('reviewsBody');
  if (!tbody) return;
  tbody.innerHTML = '<tr><td colspan="6" class="empty-row">Loading…</td></tr>';
  try {
    const d = await adminFetch('list_reviews');
    const rows = d.reviews || d.data?.reviews || [];
    if (!rows.length) { tbody.innerHTML = '<tr><td colspan="6" class="empty-row">No reviews yet</td></tr>'; return; }
    const stars = n => '★'.repeat(Math.max(0,Math.min(5,n||0))) + '☆'.repeat(5 - Math.max(0,Math.min(5,n||0)));
    const rc = document.getElementById('reviewCount');
    if(rc) rc.textContent = rows.length + ' reviews';
    tbody.innerHTML = rows.map(r => `<tr>
      <td>${esc(r.reviewer_name||'#'+r.reviewer_id)}</td>
      <td>${esc(r.seller_name||'#'+r.seller_id)}</td>
      <td style="color:#f59e0b">${stars(r.rating)} <span style="color:var(--text3);font-size:11px">${r.rating}/5</span></td>
      <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--text2)">${esc(r.comment||'—')}</td>
      <td>${fmtDate(r.created_at)}</td>
      <td style="text-align:right"><button class="btn btn-xs" style="background:var(--red-s);color:var(--red-t);border:none" onclick="deleteReview(${r.id})">Delete</button></td>
    </tr>`).join('');
  } catch(e) { tbody.innerHTML = `<tr><td colspan="6" class="empty-row">${e.message}</td></tr>`; }
}
async function deleteReview(id) {
  if (!confirm('Delete this review?')) return;
  const d = await adminFetch('delete_review', { id });
  if (d.success !== false) { showAdminToast('Review deleted', 'success'); loadReviews(); }
  else showAdminToast(d.error || 'Failed', 'error');
}

// ── Messages ─────────────────────────────────────────────────────────────
async function loadAdminMessages() {
  const tbody = document.getElementById('messagesBody');
  if (!tbody) return;
  tbody.innerHTML = '<tr><td colspan="4" class="empty-row">Loading…</td></tr>';
  try {
    const d = await adminFetch('list_conversations');
    const rows = d.conversations || d.data?.conversations || [];
    const stats = d.stats || d.data?.stats || {};
    const set = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = v ?? '—'; };
    set('msgTotalConvs', stats.total_conversations ?? rows.length);
    set('msgTotalMsgs', stats.total_messages ?? '—');
    set('msgTodayMsgs', stats.today_messages ?? '—');
    if (!rows.length) { tbody.innerHTML = '<tr><td colspan="4" class="empty-row">No conversations</td></tr>'; return; }
    tbody.innerHTML = rows.map(r => `<tr>
      <td>${esc(r.participant1||'')} ↔ ${esc(r.participant2||'')}</td>
      <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--text3)">${esc(r.last_message||'—')}</td>
      <td>${r.message_count||0}</td>
      <td>${fmtDate(r.last_activity||r.created_at)}</td>
    </tr>`).join('');
  } catch(e) { tbody.innerHTML = `<tr><td colspan="4" class="empty-row">${e.message}</td></tr>`; }
}

// ── Analytics ────────────────────────────────────────────────────────────
async function loadAnalytics() {
  const days = document.getElementById('analyticsPeriod')?.value || 30;
  try {
    const d = await adminFetch('analytics&days=' + days);
    const a = d.analytics || d.data?.analytics || d.data || {};
    const set = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = v ?? '—'; };
    set('anaNewUsers', a.new_users ?? a.users);
    set('anaNewListings', a.new_listings ?? a.listings);
    set('anaNewPayments', a.new_payments ?? a.payments);
    set('anaNewReviews', a.new_reviews ?? a.reviews);
    const chart = document.getElementById('analyticsChart');
    if (chart && a.daily) {
      const max = Math.max(...a.daily.map(x => x.count || 0), 1);
      chart.innerHTML = a.daily.map(x => `
        <div class="chart-bar-row">
          <span class="chart-bar-date">${x.date}</span>
          <div class="chart-bar-track">
            <div class="chart-bar-fill" style="width:${Math.round(x.count/max*100)}%"></div>
          </div>
          <span class="chart-bar-val">${x.count}</span>
        </div>`).join('');
    }
  } catch(e) { console.error('Analytics error:', e); }
}

// ── Revenue ──────────────────────────────────────────────────────────────
async function loadRevenue() {
  const days = document.getElementById('revenuePeriod')?.value || 30;
  try {
    const d = await adminFetch('revenue&days=' + days);
    const r = d.revenue || d.data?.revenue || d.data || {};
    const set = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = v ?? '—'; };
    set('revTotal', '$' + (r.total_revenue ?? 0));
    set('revApproved', r.approved_count ?? '—');
    set('revPending', r.pending_count ?? '—');
    set('revAvg', '$' + (r.avg_amount ?? 0));
    const tbody = document.getElementById('revenueBody');
    const rows = r.payments || d.data?.payments || [];
    if (tbody) {
      if (!rows.length) { tbody.innerHTML = '<tr><td colspan="6" class="empty-row">No payments</td></tr>'; }
      else tbody.innerHTML = rows.map(p => `<tr>
        <td>${esc(p.user_name||p.user_email||'#'+p.user_id)}</td>
        <td><span class="badge">${esc(p.plan||'—')}</span></td>
        <td style="color:#22c55e;font-weight:700">$${p.amount||0}</td>
        <td>${esc(p.payment_method||'—')}</td>
        <td>${fmtDate(p.created_at)}</td>
        <td><span class="badge ${p.status==='approved'?'badge-success':p.status==='pending'?'badge-warn':''}">${esc(p.status||'—')}</span></td>
      </tr>`).join('');
    }
    const plans = document.getElementById('revByPlan');
    if (plans && r.by_plan) {
      const max = Math.max(...Object.values(r.by_plan).map(x => x.total||0), 1);
      plans.innerHTML = Object.entries(r.by_plan).map(([plan, info]) => `
        <div class="plan-bar-row">
          <span class="plan-name">${plan}</span>
          <div class="plan-bar-track">
            <div class="plan-bar-fill" style="width:${Math.round((info.total||0)/max*100)}%"></div>
          </div>
          <span class="plan-bar-info">$${info.total||0}</span>
          <span class="plan-bar-count">${info.count||0}x</span>
        </div>`).join('');
    }
  } catch(e) { console.error('Revenue error:', e); }
}

// ── Announcements ────────────────────────────────────────────────────────
async function loadAnnouncements() {
  const list = document.getElementById('announcementsList');
  if (!list) return;
  list.innerHTML = '<div style="padding:40px;text-align:center;color:var(--text3)">Loading…</div>';
  try {
    const d = await adminFetch('list_announcements');
    const rows = d.announcements || d.data?.announcements || [];
    if (!rows.length) { list.innerHTML = '<div style="padding:40px;text-align:center;color:var(--text3)">No announcements yet</div>'; return; }
    list.innerHTML = rows.map(a => `
      <div class="announcement-card ${a.is_active?'active':''}">
        <div style="flex:1">
          <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px">
            <span style="font-weight:700;font-size:14px;color:var(--text)">${esc(a.title||'Untitled')}</span>
            <span class="badge ${a.is_active?'badge-success':'badge-gray'}">${a.is_active?'Active':'Inactive'}</span>
          </div>
          <div style="color:var(--text2);font-size:13px;line-height:1.5;margin-bottom:8px">${esc(a.message||a.body||'')}</div>
          <div style="color:var(--text3);font-size:11px">${fmtDate(a.created_at)}</div>
        </div>
        <div style="display:flex;gap:6px;flex-shrink:0;align-items:flex-start">
          <button class="btn btn-xs btn-ghost" onclick="toggleAnnouncement(${a.id},${a.is_active?0:1})">${a.is_active?'Deactivate':'Activate'}</button>
          <button class="btn btn-xs" style="background:var(--red-s);color:var(--red-t);border:none" onclick="deleteAnnouncement(${a.id})">Delete</button>
        </div>
      </div>`).join('');
  } catch(e) { list.innerHTML = `<div style="padding:40px;text-align:center;color:#ef4444">${e.message}</div>`; }
}
function showAnnouncementModal() {
  const title = prompt('Announcement title:');
  if (!title) return;
  const message = prompt('Announcement message:');
  if (!message) return;
  adminFetch('create_announcement', { title, message, is_active: 1 }).then(d => {
    if (d.success !== false) { showAdminToast('Announcement created', 'success'); loadAnnouncements(); }
    else showAdminToast(d.error || 'Failed', 'error');
  });
}
async function toggleAnnouncement(id, is_active) {
  const d = await adminFetch('update_announcement', { id, is_active });
  if (d.success !== false) { showAdminToast('Updated', 'success'); loadAnnouncements(); }
  else showAdminToast(d.error || 'Failed', 'error');
}
async function deleteAnnouncement(id) {
  if (!confirm('Delete this announcement?')) return;
  const d = await adminFetch('delete_announcement', { id });
  if (d.success !== false) { showAdminToast('Deleted', 'success'); loadAnnouncements(); }
  else showAdminToast(d.error || 'Failed', 'error');
}

// ── Push Notifications ───────────────────────────────────────────────────
async function loadPushNotifs() {
  try {
    const d = await adminFetch('push_stats');
    const s = d.stats || d.data?.stats || d.data || {};
    const el = document.getElementById('pushSubCount');
    if (el) el.textContent = (s.subscribers ?? s.total_subscribers ?? '—') + ' subscribers';
    const hist = document.getElementById('pushHistory');
    const logs = d.logs || d.data?.logs || s.recent || [];
    if (hist && logs.length) {
      hist.innerHTML = logs.map(l => `
        <div class="push-history-item">
          <div class="push-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:15px;height:15px"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/></svg>
          </div>
          <div style="flex:1">
            <div style="font-weight:600;font-size:13px;color:var(--text);margin-bottom:2px">${esc(l.title||'—')}</div>
            <div style="font-size:12px;color:var(--text3);margin-bottom:4px">${esc(l.body||'')}</div>
            <div style="font-size:11px;color:var(--text3)">Sent to: ${esc(l.target||'all')} · ${l.sent_count||0} delivered · ${fmtDate(l.sent_at||l.created_at)}</div>
          </div>
        </div>`).join('');
    }
  } catch(e) { console.error('Push error:', e); }
}
async function sendPushNotification() {
  const title = document.getElementById('pushTitle')?.value?.trim();
  const body  = document.getElementById('pushBody')?.value?.trim();
  const target = document.getElementById('pushTarget')?.value || 'all';
  if (!title || !body) { showAdminToast('Title and message required', 'error'); return; }
  if (!confirm(`Send push to all ${target} users?`)) return;
  const d = await adminFetch('send_push', { title, body, target });
  if (d.success !== false) {
    showAdminToast('Push sent!', 'success');
    document.getElementById('pushTitle').value = '';
    document.getElementById('pushBody').value = '';
    loadPushNotifs();
  } else showAdminToast(d.error || 'Failed', 'error');
}

// ── Categories ───────────────────────────────────────────────────────────
async function loadCategories() {
  const grid = document.getElementById('categoriesGrid');
  if (!grid) return;
  grid.innerHTML = '<div style="padding:40px;text-align:center;color:var(--text3)">Loading…</div>';
  try {
    const d = await adminFetch('list_categories');
    const cats = d.categories || d.data?.categories || [];
    if (!cats.length) { grid.innerHTML = '<div style="padding:40px;text-align:center;color:var(--text3)">No data</div>'; return; }
    const max = Math.max(...cats.map(c => c.count||0), 1);
    grid.innerHTML = cats.map(cat => `
      <div class="cat-card">
        <div class="cat-name">${esc(cat.category||cat.name||'Unknown')}</div>
        <div class="cat-bar-track">
          <div class="cat-bar-fill" style="width:${Math.round((cat.count||0)/max*100)}%"></div>
        </div>
        <div class="cat-meta">
          <span class="cat-total">${cat.count||0} listings</span>
          <span>${cat.active_count||0} active</span>
        </div>
      </div>`).join('');
  } catch(e) { grid.innerHTML = `<div style="padding:40px;text-align:center;color:#ef4444">${e.message}</div>`; }
}

// ── Save Announcement (modal submit) ──────────────────────────────────────
async function saveAnnouncement() {
  const title = document.getElementById('annTitle')?.value?.trim();
  const body  = document.getElementById('annBody')?.value?.trim();
  if (!title || !body) { showAdminToast('Title and message required', 'error'); return; }
  try {
    await adminFetch('create_announcement', { title, message: body, is_active: 1 });
    showAdminToast('✔ Announcement published!', 'success');
    closeModal('announcementModal');
    loadAnnouncements();
  } catch(e) { showAdminToast(e.message, 'error'); }
}

function showAnnouncementModal() {
  const el = document.getElementById('announcementModal');
  if (el) el.classList.add('open');
  ['annTitle','annBody'].forEach(id => { const e = document.getElementById(id); if (e) e.value = ''; });
}

// ════════════════════════════════════════════════════
// 2FA FUNCTIONS
// ════════════════════════════════════════════════════

async function load2FAStatus() {
  try {
    const r = await fetch('api/admin.php?action=get_2fa_status', {
      headers: { Authorization: 'Bearer ' + Auth.getToken() }
    }).then(r => r.json());
    const enabled = r.data?.totp_enabled;
    const txt = document.getElementById('twoFAStatusText');
    if (txt) {
      txt.textContent = enabled ? '✅ Enabled' : 'Not Enabled';
      txt.style.color = enabled ? '#22c55e' : '#94a3b8';
    }
    const setupArea = document.getElementById('twoFASetupArea');
    const disablePanel = document.getElementById('twoFADisablePanel');
    if (enabled) {
      if (setupArea) setupArea.style.display = 'none';
      if (disablePanel) disablePanel.style.display = 'block';
    } else {
      if (setupArea) setupArea.style.display = 'block';
      if (disablePanel) disablePanel.style.display = 'none';
    }
  } catch(e) {}
}

async function setup2FA() {
  const btn = document.getElementById('btn2FASetup');
  if (btn) { btn.disabled = true; btn.textContent = 'Loading...'; }
  try {
    const r = await fetch('api/auth.php?action=2fa_setup', {
      method: 'POST',
      headers: { Authorization: 'Bearer ' + Auth.getToken() }
    }).then(r => r.json());
    if (!r.success) { showToast(r.error || 'Error', 'error'); return; }
    const panel = document.getElementById('twoFAQRPanel');
    const qr    = document.getElementById('twoFAQR');
    const secret = document.getElementById('twoFASecret');
    if (panel) panel.style.display = 'block';
    if (qr) qr.src = r.data.qr_url;
    if (secret) secret.textContent = r.data.secret;
    if (btn) btn.style.display = 'none';
  } catch(e) {
    showToast('Error setting up 2FA', 'error');
  } finally {
    if (btn) { btn.disabled = false; btn.textContent = 'Enable 2FA'; }
  }
}

async function verify2FA() {
  const code = document.getElementById('twoFACode')?.value.trim();
  const msg  = document.getElementById('twoFAMsg');
  if (!code || code.length !== 6) { if (msg) { msg.textContent = 'Enter 6-digit code'; msg.style.color = '#ef4444'; } return; }
  try {
    const r = await fetch('api/auth.php?action=2fa_verify', {
      method: 'POST',
      headers: { Authorization: 'Bearer ' + Auth.getToken(), 'Content-Type': 'application/json' },
      body: JSON.stringify({ code })
    }).then(r => r.json());
    if (r.success) {
      if (msg) { msg.textContent = '✅ 2FA enabled!'; msg.style.color = '#22c55e'; }
      showToast('2FA enabled successfully!', 'success');
      setTimeout(load2FAStatus, 1000);
    } else {
      if (msg) { msg.textContent = r.error || 'Invalid code'; msg.style.color = '#ef4444'; }
    }
  } catch(e) { if (msg) { msg.textContent = 'Error'; msg.style.color = '#ef4444'; } }
}

async function disable2FA() {
  const pass = document.getElementById('twoFADisablePass')?.value;
  const code = document.getElementById('twoFADisableCode')?.value.trim();
  const msg  = document.getElementById('twoFADisableMsg');
  if (!pass || !code) { if (msg) { msg.textContent = 'Password and code required'; msg.style.color = '#ef4444'; } return; }
  try {
    const r = await fetch('api/auth.php?action=2fa_disable', {
      method: 'POST',
      headers: { Authorization: 'Bearer ' + Auth.getToken(), 'Content-Type': 'application/json' },
      body: JSON.stringify({ code, password: pass })
    }).then(r => r.json());
    if (r.success) {
      showToast('2FA disabled', 'success');
      load2FAStatus();
    } else {
      if (msg) { msg.textContent = r.error || 'Invalid credentials'; msg.style.color = '#ef4444'; }
    }
  } catch(e) {}
}

// ════════════════════════════════════════════════════
// BACKUP FUNCTIONS
// ════════════════════════════════════════════════════

async function downloadBackup(type) {
  const btn = document.getElementById('btnBackupFull');
  showToast('Preparing backup...', '');
  try {
    const r = await fetch(`api/admin.php?action=backup&type=${type}`, {
      headers: { Authorization: 'Bearer ' + Auth.getToken() }
    });
    if (!r.ok) throw new Error('Backup failed');
    const blob = await r.blob();
    const url  = URL.createObjectURL(blob);
    const a    = document.createElement('a');
    const now  = new Date().toISOString().slice(0,19).replace(/:/g,'-');
    a.href     = url;
    a.download = `sombazar-backup-${type}-${now}.json`;
    a.click();
    URL.revokeObjectURL(url);
    // Update last backup time
    const el = document.getElementById('lastBackupTime');
    if (el) el.textContent = new Date().toLocaleString();
    localStorage.setItem('sb_last_backup', new Date().toISOString());
    showToast('Backup downloaded!', 'success');
  } catch(e) {
    showToast('Backup failed: ' + e.message, 'error');
  }
}

function loadLastBackupTime() {
  const el = document.getElementById('lastBackupTime');
  const last = localStorage.getItem('sb_last_backup');
  if (el && last) el.textContent = new Date(last).toLocaleString();
}
