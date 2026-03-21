/**
 * SomBazar — Notification System
 * Handles: badge counts, dropdown list, mark read, push permission
 */
(function() {
  'use strict';

  const API_BASE = 'api/notifications.php';
  let pollTimer = null;

  // ── Public API ────────────────────────────────────────────────
  window.Notif = {
    init,
    poll,
    requestPushPermission,
  };

  window.toggleNotifDropdown = function() {
    const drop = document.getElementById('notifDropdown');
    if (!drop) return;
    const isOpen = drop.style.display !== 'none';
    if (isOpen) {
      drop.style.display = 'none';
    } else {
      drop.style.display = 'block';
      loadNotifList();
    }
    // close avatar dropdown if open
    const avatarDrop = document.getElementById('avatarDropdown');
    if (avatarDrop) avatarDrop.style.display = 'none';
  };

  // Close dropdown when clicking outside
  document.addEventListener('click', function(e) {
    const wrap = document.getElementById('notifBellWrap');
    if (wrap && !wrap.contains(e.target)) {
      const drop = document.getElementById('notifDropdown');
      if (drop) drop.style.display = 'none';
    }
  });

  // ── Init ─────────────────────────────────────────────────────
  function init() {
    const token = localStorage.getItem('sb_token');
    if (!token) return;
    fetchUnreadCount();
    // Poll every 30 seconds
    if (pollTimer) clearInterval(pollTimer);
    pollTimer = setInterval(fetchUnreadCount, 30000);
  }

  function poll() { fetchUnreadCount(); }

  // ── Unread Count ─────────────────────────────────────────────
  async function fetchUnreadCount() {
    const token = localStorage.getItem('sb_token');
    if (!token) return;
    try {
      const res = await fetch(API_BASE + '?action=unread_count', {
        headers: { 'Authorization': 'Bearer ' + token }
      });
      const data = await res.json();
      if (!data.success) return;
      const d = data.data;

      // Bell badge
      setBadge('notifBadge', d.total);
      // Message badge
      setBadge('msgBadge', d.messages);
      // Offer badge
      setBadge('offerNavBadge', d.offers);
    } catch(e) {}
  }

  function setBadge(id, count) {
    const el = document.getElementById(id);
    if (!el) return;
    if (count > 0) {
      el.textContent = count > 99 ? '99+' : count;
      el.style.display = 'flex';
    } else {
      el.style.display = 'none';
    }
  }

  // ── Notification List ─────────────────────────────────────────
  async function loadNotifList() {
    const token = localStorage.getItem('sb_token');
    const list = document.getElementById('notifList');
    if (!list || !token) return;

    list.innerHTML = '<div style="padding:20px;text-align:center;color:#94a3b8;font-size:13px">Loading...</div>';

    try {
      const res = await fetch(API_BASE + '?action=list&limit=20', {
        headers: { 'Authorization': 'Bearer ' + token }
      });
      const data = await res.json();
      const notifs = data.data?.notifications || [];

      if (!notifs.length) {
        list.innerHTML = `
          <div style="padding:32px 20px;text-align:center">
            <div style="font-size:32px;margin-bottom:8px">🔔</div>
            <p style="font-size:13px;color:#94a3b8;font-weight:600">No notifications yet</p>
            <p style="font-size:12px;color:#cbd5e1;margin-top:4px">We'll notify you about messages, offers, and updates</p>
          </div>`;
        return;
      }

      list.innerHTML = notifs.map(n => notifItemHTML(n)).join('');

      // Mark all as read
      markAllRead();
      setBadge('notifBadge', 0);

    } catch(e) {
      list.innerHTML = '<div style="padding:20px;text-align:center;color:#ef4444;font-size:13px">Could not load notifications</div>';
    }
  }

  function notifItemHTML(n) {
    const icons = {
      message:  '💬',
      offer:    '🏷️',
      offer_accepted: '✅',
      offer_rejected: '❌',
      offer_countered: '🔄',
      listing_approved: '🎉',
      listing_rejected: '⚠️',
      review:   '⭐',
      payment:  '💳',
      system:   '📢',
    };
    const icon = icons[n.type] || n.icon || '🔔';
    const bg   = n.isRead ? '' : 'background:#fff7ed;';
    const dot  = n.isRead ? '' : '<span style="width:8px;height:8px;background:#f97316;border-radius:50%;flex-shrink:0;margin-top:4px"></span>';
    const link = n.link || '#';

    return `
      <a href="${link}" style="display:flex;align-items:flex-start;gap:10px;padding:12px 14px;text-decoration:none;color:inherit;border-bottom:1px solid #f1f5f9;${bg}transition:background .15s"
         onmouseover="this.style.background='#fef6ee'" onmouseout="this.style.background='${n.isRead ? '' : '#fff7ed'}'"
         onclick="Notif._markRead(${n.id})">
        <span style="font-size:20px;flex-shrink:0;margin-top:2px">${icon}</span>
        <div style="flex:1;min-width:0">
          <p style="font-size:13px;font-weight:700;color:#0f172a;margin:0 0 2px;line-height:1.3">${escHtml(n.title)}</p>
          ${n.body ? `<p style="font-size:12px;color:#64748b;margin:0 0 4px;line-height:1.4;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical">${escHtml(n.body)}</p>` : ''}
          <span style="font-size:11px;color:#94a3b8">${n.timeAgo}</span>
        </div>
        ${dot}
      </a>`;
  }

  Notif._markRead = async function(id) {
    const token = localStorage.getItem('sb_token');
    if (!token) return;
    try {
      await fetch(API_BASE + '?action=mark_read', {
        method: 'POST',
        headers: { 'Authorization': 'Bearer ' + token, 'Content-Type': 'application/json' },
        body: JSON.stringify({ notification_id: id })
      });
    } catch(e) {}
  };

  async function markAllRead() {
    const token = localStorage.getItem('sb_token');
    if (!token) return;
    try {
      await fetch(API_BASE + '?action=mark_all_read', {
        method: 'POST',
        headers: { 'Authorization': 'Bearer ' + token }
      });
    } catch(e) {}
  }

  // ── Push Permission ───────────────────────────────────────────
  async function requestPushPermission() {
    if (!('Notification' in window) || !('serviceWorker' in navigator)) {
      alert('Push notifications are not supported in this browser.');
      return;
    }
    const permission = await Notification.requestPermission();
    if (permission !== 'granted') return;

    try {
      // Get VAPID key
      const token = localStorage.getItem('sb_token');
      const res   = await fetch(API_BASE + '?action=vapid_key', {
        headers: { 'Authorization': 'Bearer ' + token }
      });
      const data = await res.json();
      if (!data.data?.publicKey) {
        console.log('Push not configured on server');
        return;
      }

      const reg = await navigator.serviceWorker.ready;
      const sub = await reg.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: urlBase64ToUint8Array(data.data.publicKey)
      });

      await fetch(API_BASE + '?action=push_subscribe', {
        method: 'POST',
        headers: { 'Authorization': 'Bearer ' + token, 'Content-Type': 'application/json' },
        body: JSON.stringify(sub.toJSON())
      });
    } catch(e) {
      console.log('Push subscribe error:', e);
    }
  }

  function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - base64String.length % 4) % 4);
    const base64  = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const raw     = window.atob(base64);
    const arr     = new Uint8Array(raw.length);
    for (let i = 0; i < raw.length; i++) arr[i] = raw.charCodeAt(i);
    return arr;
  }

  function escHtml(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  // ── Auto-init when user is logged in ─────────────────────────
  // Wait for app.min.js to set up Auth, then init
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function() {
      setTimeout(init, 500);
    });
  } else {
    setTimeout(init, 500);
  }

})();
