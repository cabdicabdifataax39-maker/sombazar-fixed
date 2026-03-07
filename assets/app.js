// SomBazar App JS - ASCII only
const API_BASE = 'api';

// Token / Auth
const Auth = {
  getToken: () => localStorage.getItem('sb_token'),
  getUser:  () => { try { return JSON.parse(localStorage.getItem('sb_user')); } catch { return null; } },
  setSession(token, user) {
    localStorage.setItem('sb_token', token);
    localStorage.setItem('sb_user', JSON.stringify(user));
    document.dispatchEvent(new CustomEvent('auth:change', { detail: { user } }));
  },
  logout() {
    localStorage.removeItem('sb_token');
    localStorage.removeItem('sb_user');
    document.dispatchEvent(new CustomEvent('auth:change', { detail: { user: null } }));
    window.location.href = 'index.html';
  },
  isLoggedIn: () => !!localStorage.getItem('sb_token'),
  isAdmin:    () => { const u = Auth.getUser(); return u && !!u.isAdmin; },
};

// API Fetcher
async function apiFetch(endpoint, options = {}) {
  const token = Auth.getToken();
  const headers = { 'Content-Type': 'application/json', ...(options.headers || {}) };
  if (token) headers['Authorization'] = `Bearer ${token}`;

  let resp;
  try {
    resp = await fetch(endpoint, { ...options, headers });
  } catch (networkErr) {
    throw new Error('Cannot reach server. Is PHP/Apache running?');
  }

  const text = await resp.text();
  let json;
  try {
    json = JSON.parse(text);
  } catch (_) {
    // PHP returned an HTML error page  log it for debugging
    console.error('Raw API response:', text);
    throw new Error('Server error. Open the browser console (F12) for details.');
  }

  if (!json.success) {
    const err = new Error(json.error || 'Request failed.');
    // Extra fields (örn. account_status, days_left) hata objesine ekle
    Object.assign(err, json);
    throw err;
  }
  return json.data;
}

// API helpers

// ═══════════════════════════════════════════════════════════════
//  CSRF Token Yönetimi
// ═══════════════════════════════════════════════════════════════
const CSRF = (() => {
  let _token = null;

  async function refresh() {
    try {
      const r = await window.fetch('api/admin.php?action=csrf_token', {
        headers: Auth.getToken() ? { Authorization: 'Bearer ' + Auth.getToken() } : {}
      });
      const d = await r.json();
      if (d.success && d.data?.token) {
        _token = d.data.token;
        const meta = document.getElementById('csrfMeta');
        if (meta) meta.setAttribute('content', _token);
      }
    } catch { /* sessiz hata */ }
    return _token;
  }

  function get() {
    const meta = document.getElementById('csrfMeta');
    if (meta && meta.getAttribute('content')) _token = meta.getAttribute('content');
    return _token || '';
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => { if (Auth.getToken()) refresh(); });
  } else {
    if (Auth.getToken()) refresh();
  }

  return { get, refresh };
})();

const API = {
  // Auth
  login:    (email, password) => apiFetch(`${API_BASE}/auth.php?action=login`, { method: 'POST', body: JSON.stringify({ email, password }) }),
  register: (displayName, email, password) => apiFetch(`${API_BASE}/auth.php?action=register`, { method: 'POST', body: JSON.stringify({ displayName, email, password }) }),
  me:       () => apiFetch(`${API_BASE}/auth.php?action=me`),
  updateProfile: (data) => apiFetch(`${API_BASE}/auth.php?action=update`, { method: 'POST', body: JSON.stringify(data) }),

  // Listings
  getListings:   (params = {}) => apiFetch(`${API_BASE}/listings.php?action=list&${new URLSearchParams(params)}`),
  getListing:    (id) => apiFetch(`${API_BASE}/listings.php?action=get&id=${id}`),
  createListing: (data) => apiFetch(`${API_BASE}/listings.php?action=create`, { method: 'POST', body: JSON.stringify(data) }),
  updateListing: (id, data) => apiFetch(`${API_BASE}/listings.php?action=update&id=${id}`, { method: 'POST', body: JSON.stringify(data) }),
  deleteListing: (id) => apiFetch(`${API_BASE}/listings.php?action=delete&id=${id}`, { method: 'POST' }),
  markSold:      (id, status='sold') => apiFetch(`${API_BASE}/listings.php?action=mark_sold&id=${id}`, { method: 'POST', body: JSON.stringify({ status }) }),
  markActive:    (id) => apiFetch(`${API_BASE}/listings.php?action=mark_active&id=${id}`, { method: 'POST' }),
  editListingUrl:(id) => `edit.html?id=${id}`,
  myListings:    () => apiFetch(`${API_BASE}/listings.php?action=user_listings`),
  uploadImages:  (formData, listingId) => {
    const token = Auth.getToken();
    return fetch(`${API_BASE}/listings.php?action=upload_images&listing_id=${listingId}`, {
      method: 'POST',
      headers: { 'Authorization': `Bearer ${token}` },
      body: formData,
    }).then(r => r.json()).then(j => { if (!j.success) throw new Error(j.error); return j.data; });
  },

  // Messages
  getConversations:       () => apiFetch(`${API_BASE}/messages.php?action=conversations`),
  getMessages:            (convId) => apiFetch(`${API_BASE}/messages.php?action=messages&conversation_id=${convId}`),
  sendMessage:            (conversationId, text) => apiFetch(`${API_BASE}/messages.php?action=send`, { method: 'POST', body: JSON.stringify({ conversationId, text }) }),
  getOrCreateConversation:(otherUserId, listingId) => apiFetch(`${API_BASE}/messages.php?action=get_or_create`, { method: 'POST', body: JSON.stringify({ otherUserId, listingId }) }),

  // Favorites
  getFavorites:   () => apiFetch(`${API_BASE}/favorites.php?action=list`),
  addFavorite:    (listingId) => apiFetch(`${API_BASE}/favorites.php?action=add`, { method: 'POST', body: JSON.stringify({ listingId }) }),
  removeFavorite: (listingId) => apiFetch(`${API_BASE}/favorites.php?action=remove`, { method: 'POST', body: JSON.stringify({ listingId }) }),
  checkFavorite:  (listingId) => apiFetch(`${API_BASE}/favorites.php?action=check&listing_id=${listingId}`),

  // Upload avatar
  uploadAvatar: (formData) => {
    const token = Auth.getToken();
    return fetch(`${API_BASE}/upload.php`, {
      method: 'POST',
      headers: { 'Authorization': `Bearer ${token}` },
      body: formData,
    }).then(r => r.json()).then(j => { if (!j.success) throw new Error(j.error); return j.data; });
  },
};

// Toast notifications
function showToast(message, type = '') {
  let container = document.querySelector('.toast-container');
  if (!container) {
    container = document.createElement('div');
    container.className = 'toast-container';
    document.body.appendChild(container);
  }
  const toast = document.createElement('div');
  toast.className = `toast ${type}`;
  toast.textContent = message;
  container.appendChild(toast);
  setTimeout(() => toast.remove(), 3500);
}

// URL query param helper
function getParam(name) {
  return new URLSearchParams(window.location.search).get(name);
}

// Price formatter
function fmtPrice(listing) {
  return `${listing.currency || 'USD'} ${Number(listing.price).toLocaleString()}`;
}

// Auto-update header on every page
document.addEventListener('DOMContentLoaded', () => {
  const user      = Auth.getUser();
  const signinBtn = document.getElementById('header-signin');
  const userArea  = document.getElementById('header-user');

  const adminBtn = document.getElementById('admin-btn');
  if (user && signinBtn && userArea) {
    signinBtn.style.display = 'none';
    userArea.style.display  = 'flex';
    const avatar = userArea.querySelector('.header-avatar');
    if (avatar) {
      if (user.photoURL) {
        avatar.innerHTML = `<img src="${user.photoURL}" alt="${(user.displayName||'U')[0]}" style="width:32px;height:32px;border-radius:50%;object-fit:cover;display:block" onerror="this.parentElement.textContent='${(user.displayName||'U')[0].toUpperCase()}'">`;
      } else {
        avatar.textContent = (user.displayName || 'U')[0].toUpperCase();
      }
    }
    if (adminBtn) adminBtn.style.display = user.isAdmin ? 'inline-flex' : 'none';
  } else if (!user && signinBtn) {
    signinBtn.style.display = '';
    if (userArea) userArea.style.display = 'none';
    if (adminBtn) adminBtn.style.display = 'none';
  }

  // Offer badge — bekleyen/yanıtlanan teklif sayısını göster
  if (Auth.isLoggedIn()) {
    updateOfferBadge();
  }
  document.addEventListener('auth:change', ({ detail }) => {
    if (detail.user) updateOfferBadge();
    else {
      const ob = document.getElementById('offerNavBadge');
      if (ob) ob.style.display = 'none';
    }
  });

  // Badge'leri güncelle ve 30s interval başlat
  if (Auth.isLoggedIn()) {
    updateMsgBadge();
    setInterval(() => { updateOfferBadge(); updateMsgBadge(); }, 30000);
  }

  // Keep header in sync if auth changes
  document.addEventListener('auth:change', ({ detail }) => {
    const u = detail.user;
    if (u && signinBtn && userArea) {
      signinBtn.style.display = 'none';
      userArea.style.display  = 'flex';
      const avatar = userArea.querySelector('.header-avatar');
      if (avatar) {
        if (u.photoURL) {
          avatar.innerHTML = `<img src="${u.photoURL}" alt="${(u.displayName||'U')[0]}" style="width:32px;height:32px;border-radius:50%;object-fit:cover;display:block" onerror="this.parentElement.textContent='${(u.displayName||'U')[0].toUpperCase()}'">`;
        } else {
          avatar.textContent = (u.displayName || 'U')[0].toUpperCase();
        }
      }
      if (adminBtn) adminBtn.style.display = u.isAdmin ? 'inline-flex' : 'none';
    } else if (!u && signinBtn) {
      signinBtn.style.display = '';
      if (userArea) userArea.style.display = 'none';
      if (adminBtn) adminBtn.style.display = 'none';
    }
  });
});

// Listing card HTML  shared across listings.html and profile.html
function verifiedBadgeHTML(verified, badge) {
  if (!verified) return '';
  if (badge === 'premium') return '<span style="background:#fef3c7;color:#92400e;font-size:9px;font-weight:800;padding:1px 5px;border-radius:8px;margin-left:4px">&#11088; Premium</span>';
  return '<span style="background:#dcfce7;color:#166534;font-size:9px;font-weight:800;padding:1px 5px;border-radius:8px;margin-left:4px">&#10003;</span>';
}

function listingCardHTML(l) {
  const img     = (l.images && l.images.length > 0) ? l.images[0] : null;
  const emoji   = catEmoji(l.category);
  const typeTag = l.listingType === 'rent'
    ? `<span style="position:absolute;top:10px;left:10px;background:#2563eb;color:white;font-size:10px;font-weight:800;padding:3px 8px;border-radius:20px">FOR RENT</span>`
    : `<span style="position:absolute;top:10px;left:10px;background:#16a34a;color:white;font-size:10px;font-weight:800;padding:3px 8px;border-radius:20px">FOR SALE</span>`;
  
  // NEW badge: posted within last 24 hours
  const isNew = l.createdAt && (Date.now() - new Date(l.createdAt)) < 86400000;
  const newBadge = isNew ? `<span style="position:absolute;top:10px;right:10px;background:#f59e0b;color:white;font-size:9px;font-weight:900;padding:3px 8px;border-radius:20px;letter-spacing:.5px">NEW</span>` : '';

  // Build quick-detail chips (year, rooms, rental period, condition)
  const chips = [];
  if (l.year)         chips.push(`<span style="background:var(--light-gray);border-radius:6px;padding:2px 7px;font-size:10px;font-weight:700;color:var(--dark)"> ${l.year}</span>`);
  if (l.specs && l.specs.rooms) chips.push(`<span style="background:var(--light-gray);border-radius:6px;padding:2px 7px;font-size:10px;font-weight:700;color:var(--dark)"> ${l.specs.rooms}</span>`);
  if (l.specs && l.specs.area)  chips.push(`<span style="background:var(--light-gray);border-radius:6px;padding:2px 7px;font-size:10px;font-weight:700;color:var(--dark)"> ${l.specs.area}</span>`);
  if (l.listingType === 'rent' && l.rentalPeriod) chips.push(`<span style="background:#eff6ff;border-radius:6px;padding:2px 7px;font-size:10px;font-weight:700;color:#2563eb">/${l.rentalPeriod}</span>`);
  if (l.negotiable)   chips.push(`<span style="background:#f0fdf4;border-radius:6px;padding:2px 7px;font-size:10px;font-weight:700;color:#16a34a">Negotiable</span>`);

  return `
    <a href="listing.html?id=${l.id}" class="listing-card" style="text-decoration:none;display:block">
      <div style="position:relative;width:100%;height:180px;background:#f3f4f6;border-radius:12px 12px 0 0;overflow:hidden">
        ${img
          ? `<img loading="lazy" src="${img}" alt="${l.title}" style="width:100%;height:100%;object-fit:cover;transition:opacity .3s;opacity:0" onload="this.style.opacity=1" onerror="this.src='assets/icon-192.png';this.style.opacity=1">`
          : `<div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:40px">${emoji}</div>`
        }
        ${typeTag}
        ${l.featured ? `<span style="position:absolute;top:10px;right:10px;background:#f59e0b;color:white;font-size:10px;font-weight:800;padding:3px 8px;border-radius:20px"> FEATURED</span>` : newBadge}
      </div>
      <div style="padding:12px">
        <div style="font-size:13px;font-weight:800;color:var(--dark);white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${l.title}</div>
        <div style="font-size:16px;font-weight:800;color:var(--primary-color);margin:4px 0">${l.currency} ${Number(l.price).toLocaleString()}</div>
        ${chips.length ? `<div style="display:flex;flex-wrap:wrap;gap:4px;margin-bottom:6px">${chips.join('')}</div>` : ''}
        <div style="font-size:11px;color:var(--gray);display:flex;align-items:center;gap:4px">
           ${l.city} &nbsp;&nbsp;  ${l.views || 0}
        </div>
      </div>
    </a>`;
}

// Category emoji helper  used in listing.html and elsewhere
function catEmoji(category) {
  return { car:'', house:'', land:'', electronics:'', furniture:'', jobs:'', services:'', hotel:'' }[category] || '';
}

// Skeleton loading cards
function skeletonCards(count) {
  return Array(count).fill(`
    <div class="listing-card" style="pointer-events:none">
      <div class="skeleton" style="height:180px;border-radius:12px 12px 0 0"></div>
      <div style="padding:12px">
        <div class="skeleton" style="height:14px;border-radius:6px;margin-bottom:8px"></div>
        <div class="skeleton" style="height:18px;border-radius:6px;width:60%;margin-bottom:8px"></div>
        <div class="skeleton" style="height:11px;border-radius:6px;width:40%"></div>
      </div>
    </div>`).join('');
}

// ── Language System (EN / SO) ──────────────────────────────────
const TRANSLATIONS = {
  en: {
    // Index page
    'sb-sell-title': 'Sell Something?',
    'sb-sell-desc':  'Post your ad for free and reach thousands of buyers.',
    'sb-sell-btn':   '+ Post Free Ad',
    'sb-sellers-title': 'Top Sellers',
    'sb-tips-title': 'Safety Tips',
    'tip1': 'Meet in a safe, public place',
    'tip2': 'Never pay before seeing the item',
    'tip3': 'Verify seller identity',
    'tip4': 'Be wary of very low prices',
    // Navigation
    'nav-home': 'Home',
    'nav-listings': 'Listings',
    'nav-post': 'Post Ad',
    'nav-messages': 'Messages',
    'nav-profile': 'Profile',
    'nav-signin': 'Sign In',
    'btn-post-ad': '+ Post Ad',
    // Auth page
    'auth-title-login': 'Sign In',
    'auth-title-register': 'Register',
    'auth-email': 'Email Address',
    'auth-password': 'Password',
    'auth-name': 'Full Name',
    'auth-confirm-pass': 'Confirm Password',
    'auth-forgot': 'Forgot password?',
    'auth-btn-login': 'Sign In',
    'auth-btn-register': 'Create Account',
    'auth-btn-reset': 'Send Reset Email',
    'auth-terms': 'I agree to the Terms of Service and Privacy Policy',
    'auth-google': 'Continue with Google',
    // Listing page
    'listing-contact-seller': 'Contact Seller',
    'listing-send-msg': 'Send Message',
    'listing-whatsapp': 'WhatsApp',
    'listing-make-offer': 'Make an Offer',
    'listing-report': 'Report this listing',
    'listing-safety-title': 'Safety Tips',
    'listing-for-sale': 'For Sale',
    'listing-for-rent': 'For Rent',
    'listing-negotiable': 'Price Negotiable',
    'listing-views': 'views',
    'listing-similar': 'Similar Listings',
    'listing-description': 'Description',
    'listing-details': 'Details',
    'listing-location': 'Location',
    'listing-reviews': 'Reviews',
    // Post page
    'post-title': 'Post a Free Ad',
    'post-step-cat': 'Category',
    'post-step-details': 'Details',
    'post-step-price': 'Price & Location',
    'post-step-photos': 'Photos',
    'post-step-review': 'Review',
    'post-btn-next': 'Next',
    'post-btn-back': 'Back',
    'post-btn-publish': 'Publish Listing',
    // Profile page
    'profile-my-listings': 'My Listings',
    'profile-favorites': 'Favorites',
    'profile-settings': 'Settings',
    'profile-edit': 'Edit Profile',
    'profile-save': 'Save Changes',
    'profile-signout': 'Sign Out',
    'profile-delete': 'Delete Account',
    'profile-change-pass': 'Change Password',
    // Messages page
    'msg-title': 'Messages',
    'msg-no-msgs': 'No messages yet',
    'msg-type': 'Type a message...',
    'msg-search': 'Search conversations...',
    'msg-deleted': 'Message deleted',
    'msg-today': 'Today',
    'msg-yesterday': 'Yesterday',
    // Listings page
    'listings-title': 'All Listings',
    'listings-search': 'Search listings...',
    'listings-filter': 'Apply Filters',
    'listings-sort-new': 'Newest First',
    'listings-sort-price-asc': 'Price: Low to High',
    'listings-sort-price-desc': 'Price: High to Low',
    'listings-no-results': 'No listings found',
    // Errors & toasts
    'err-network': 'Cannot reach server. Is PHP/Apache running?',
    'err-server': 'Server error. Please try again.',
    'err-required': 'All fields are required',
    'err-email': 'Invalid email address',
    'err-password-short': 'Password must be at least 6 characters',
    'err-password-match': 'Passwords do not match',
    'err-email-taken': 'This email is already registered',
    'err-wrong-pass': 'Incorrect email or password',
    'err-terms': 'Please accept the Terms of Service',
    'toast-saved': 'Saved successfully!',
    'toast-deleted': 'Deleted',
    'toast-send-fail': 'Failed to send message',
    'toast-copied': 'Link copied!',
    'toast-offer-sent': 'Offer sent!',
    'toast-reported': 'Reported. Thank you.',
    'toast-fav-added': 'Added to favorites',
    'toast-fav-removed': 'Removed from favorites',
    'toast-listing-published': 'Listing published!',
    'toast-listing-deleted': 'Listing deleted',
    'toast-avatar-updated': 'Avatar updated!',
    'toast-pass-changed': 'Password changed successfully',
    // Footer
    'footer-desc': "Somaliland's largest online marketplace. Buy, sell and rent cars, houses, land and more.",
    'footer-cats-title': 'Categories',
    'footer-company-title': 'Company',
    'footer-legal-title': 'Legal',
    'footer-about': 'About Us',
    'footer-contact': 'Contact',
    'footer-careers': 'Careers',
    'footer-advertise': 'Advertise',
    'footer-terms': 'Terms of Service',
    'footer-privacy': 'Privacy Policy',
    'footer-cookies': 'Cookie Policy',
    'footer-report': 'Report Abuse',
    'footer-copy': '2026 SomBazar. All rights reserved.',
    // Categories
    'cat-car': 'Cars',
    'cat-house': 'Houses',
    'cat-land': 'Land',
    'cat-electronics': 'Electronics',
    'cat-furniture': 'Furniture',
    'cat-clothing': 'Clothing',
    'cat-jobs': 'Jobs',
    'cat-services': 'Services',
    'cat-all': 'All',
    // System messages (for notifications/emails)
    'sys-listing-approved': 'Your listing has been approved.',
    'sys-listing-rejected': 'Your listing has been rejected.',
    'sys-account-suspended': 'Your account has been suspended.',
    'sys-new-message': 'You have a new message from',
    'sys-offer-received': 'You received an offer on your listing',
    'sys-welcome': 'Welcome to SomBazar!',
  },
  so: {
    // Bogga hore
    'sb-sell-title': 'Ma Waxba Iibinaysaa?',
    'sb-sell-desc':  'Xayeysiiskaaga bilaash ku shid oo gaar u noqo kumanaan iibsade.',
    'sb-sell-btn':   '+ Xayeysiis Bilaash ah',
    'sb-sellers-title': 'Iibiyeyaasha Ugu Wanaagsan',
    'sb-tips-title': 'Talooyin Amaan',
    'tip1': 'Ku kulmid meel ammaan ah oo dadweyne',
    'tip2': 'Ha lacag bixin kahor inta aadan shayga arkin',
    'tip3': 'Aqoonsi iibiyaha',
    'tip4': 'U taxaddar qiimaha aad u hooseeysa',
    // Xiriirka
    'nav-home': 'Hoyga',
    'nav-listings': 'Xayeysiisyada',
    'nav-post': 'Xayeysiis Shid',
    'nav-messages': 'Farriimaha',
    'nav-profile': 'Xogta',
    'nav-signin': 'Gal',
    'btn-post-ad': '+ Xayeysiis',
    // Bogga galitaanka
    'auth-title-login': 'Gal',
    'auth-title-register': 'Diiwaan Geli',
    'auth-email': 'Cinwaanka Emailka',
    'auth-password': 'Furaha Sirta',
    'auth-name': 'Magaca Buuxa',
    'auth-confirm-pass': 'Xaqiiji Furaha',
    'auth-forgot': 'Furaha ma hilowday?',
    'auth-btn-login': 'Gal',
    'auth-btn-register': 'Abuuri Xisaab',
    'auth-btn-reset': 'Dir Emailka Dib-u-Dejinta',
    'auth-terms': 'Waxaan ku ogolahay Shuruudaha Adeegga iyo Siyaasadda Xogta',
    'auth-google': 'Ku Sii Google',
    // Bogga xayeysiiska
    'listing-contact-seller': 'La Xiriir Iibiyaha',
    'listing-send-msg': 'Dir Fariin',
    'listing-whatsapp': 'WhatsApp',
    'listing-make-offer': 'Samee Dalbin',
    'listing-report': 'Warbixin Xayeysiiska',
    'listing-safety-title': 'Talooyin Amaan',
    'listing-for-sale': 'Iib',
    'listing-for-rent': 'Kiiro',
    'listing-negotiable': 'Qiimaha Waa La Xagaajin Karaa',
    'listing-views': 'daawade',
    'listing-similar': 'Xayeysiisyada La Midka ah',
    'listing-description': 'Faahfaahin',
    'listing-details': 'Macluumaadka',
    'listing-location': 'Goobta',
    'listing-reviews': 'Faallooyin',
    // Bogga shidida
    'post-title': 'Xayeysiis Bilaash ah Shid',
    'post-step-cat': 'Qaybta',
    'post-step-details': 'Faahfaahinada',
    'post-step-price': 'Qiimaha & Goobta',
    'post-step-photos': 'Sawirrada',
    'post-step-review': 'Dib u Eeg',
    'post-btn-next': 'Xiga',
    'post-btn-back': 'Dib',
    'post-btn-publish': 'Baahiye Xayeysiiska',
    // Bogga xogta
    'profile-my-listings': 'Xayeysiisyadayda',
    'profile-favorites': 'La Jecelyahay',
    'profile-settings': 'Dejinta',
    'profile-edit': 'Wax ka Beddel Xogta',
    'profile-save': 'Kaydi Isbedelada',
    'profile-signout': 'Ka Bax',
    'profile-delete': 'Tirtir Xisaabta',
    'profile-change-pass': 'Beddel Furaha Sirta',
    // Farriimaha
    'msg-title': 'Farriimaha',
    'msg-no-msgs': 'Wali fariin kuma jirto',
    'msg-type': 'Qor fariin...',
    'msg-search': 'Raadi wada-xaajoodyo...',
    'msg-deleted': 'Farriimaha la tirtiray',
    'msg-today': 'Maanta',
    'msg-yesterday': 'Shalay',
    // Bogga xayeysiisyada
    'listings-title': 'Dhammaan Xayeysiisyada',
    'listings-search': 'Raadi xayeysiisyada...',
    'listings-filter': 'Isticmaal Shaandhaynta',
    'listings-sort-new': 'Cusub Hore',
    'listings-sort-price-asc': 'Qiimaha: Hoose ilaa Sare',
    'listings-sort-price-desc': 'Qiimaha: Sare ilaa Hoose',
    'listings-no-results': 'Xayeysiis lama helin',
    // Khaladaadka & ogeysiisyada
    'err-network': 'Kuma gaari karo server-ka. PHP/Apache ma shaqeynayaan?',
    'err-server': 'Khalad server. Fadlan mar kale isku day.',
    'err-required': 'Dhammaan goobaha ayaa looga baahan yahay',
    'err-email': 'Cinwaanka email-ka ma sax',
    'err-password-short': 'Furaha sirta waa inuu ahaadaa ugu yaraan 6 xaraf',
    'err-password-match': 'Furayaasha sirta ma waafaqsana',
    'err-email-taken': 'Email-kan horeba waa la diiwaan geliyay',
    'err-wrong-pass': 'Email-ka ama furaha sirta waa khalad',
    'err-terms': 'Fadlan aqbali Shuruudaha Adeegga',
    'toast-saved': 'Si guul leh ayaa loo keydiyay!',
    'toast-deleted': 'La tirtiray',
    'toast-send-fail': 'Farriimaha dirida ayaa fashilmay',
    'toast-copied': 'Xiriirka la koobiyeeyay!',
    'toast-offer-sent': 'Dalabka la diray!',
    'toast-reported': 'La warbixiyay. Mahadsanid.',
    'toast-fav-added': 'La jecelaatay ayaa lagu daray',
    'toast-fav-removed': 'La jecelaatay ayaa laga saaray',
    'toast-listing-published': 'Xayeysiiska la baahiyay!',
    'toast-listing-deleted': 'Xayeysiiska la tirtiray',
    'toast-avatar-updated': 'Sawirka la cusbooneysiiyay!',
    'toast-pass-changed': 'Furaha sirta si guul leh ayaa loo beddelay',
    // Buugga hoose
    'footer-desc': 'Suuqga ugu weyn ee onlaynka ah ee Somaliland. Iibso, iibi oo kireeyso gaadhi, guri, dhul iyo wax kale.',
    'footer-cats-title': 'Qaybaha',
    'footer-company-title': 'Shirkadda',
    'footer-legal-title': 'Sharci',
    'footer-about': 'Nagu Saabsan',
    'footer-contact': 'Xiriir',
    'footer-careers': 'Shaqooyin',
    'footer-advertise': 'Xayeysiis',
    'footer-terms': 'Shuruudaha Adeegga',
    'footer-privacy': 'Siyaasadda Xogta',
    'footer-cookies': 'Siyaasadda Cookie',
    'footer-report': 'Warbixin Xad-gudub',
    'footer-copy': '2026 SomBazar. Dhammaan xuquuqda way xidantahay.',
    // Qaybaha
    'cat-car': 'Gaadhiyaasha',
    'cat-house': 'Guriyaha',
    'cat-land': 'Dhulka',
    'cat-electronics': 'Elektaroonigga',
    'cat-furniture': 'Alaabta Guriga',
    'cat-clothing': 'Dhar',
    'cat-jobs': 'Shaqooyin',
    'cat-services': 'Adeegyada',
    'cat-all': 'Dhammaan',
    // Farriimaha nidaamka
    'sys-listing-approved': 'Xayeysiiskaaga waa la ansixiyay.',
    'sys-listing-rejected': 'Xayeysiiskaaga waa la diiday.',
    'sys-account-suspended': 'Xisaabkaagu waa la xannibaay.',
    'sys-new-message': 'Fariin cusub ayaad ka heshay',
    'sys-offer-received': 'Dalad ayaad ku heshay xayeysiiskaaga',
    'sys-welcome': 'Soo dhawoow SomBazar!',
  }
};

// Detect browser language on first visit
function detectLang() {
  const saved = localStorage.getItem('sb_lang');
  if (saved) return saved;
  const browser = (navigator.language || navigator.userLanguage || 'en').toLowerCase();
  return browser.startsWith('so') ? 'so' : 'en';
}

let currentLang = detectLang();

// Translate a key — use in JS code: t('toast-saved')
function t(key) {
  return (TRANSLATIONS[currentLang] || TRANSLATIONS.en)[key] || (TRANSLATIONS.en)[key] || key;
}

function applyLang(lang) {
  currentLang = lang;
  localStorage.setItem('sb_lang', lang);
  document.documentElement.setAttribute('lang', lang);

  // Update lang button
  const btn = document.getElementById('langBtn');
  if (btn) {
    btn.innerHTML = lang === 'en'
      ? '<span style="font-size:16px">&#x1F1F8;&#x1F1F4;</span> Somali'
      : '<span style="font-size:16px">&#x1F1EC;&#x1F1E7;</span> English';
  }

  // Apply all data-i18n elements
  document.querySelectorAll('[data-i18n]').forEach(el => {
    const key = el.getAttribute('data-i18n');
    const val = t(key);
    if (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA') {
      el.placeholder = val;
    } else if (el.hasAttribute('data-i18n-html')) {
      el.innerHTML = val;
    } else {
      el.textContent = val;
    }
  });

  // Apply by ID (legacy)
  const tr = TRANSLATIONS[lang] || TRANSLATIONS.en;
  Object.entries(tr).forEach(([id, text]) => {
    const el = document.getElementById(id);
    if (el) el.textContent = text;
  });

  // Update page title direction (Somali is LTR like English, no RTL needed)
  // Store for dynamic content
  window._lang = lang;
}

function toggleLang() {
  applyLang(currentLang === 'en' ? 'so' : 'en');
}



// Apply language on page load (auto-detect on first visit)
document.addEventListener('DOMContentLoaded', () => {
  applyLang(currentLang);
  if (document.getElementById('topSellersBox')) loadTopSellers();
});

// Load top sellers for sidebar
async function loadTopSellers() {
  const box = document.getElementById('topSellersBox');
  if (!box) return; // Only runs on pages that have this element
  try {
    const listings = await API.getListings({ pageSize: 20 });
    // Group by userId, pick top 4 unique sellers
    const seen = {};
    listings.forEach(l => {
      if (!seen[l.userId]) seen[l.userId] = { ...l.seller, userId: l.userId, count: 0 };
      seen[l.userId].count++;
    });
    const sellers = Object.values(seen).sort((a, b) => b.count - a.count).slice(0, 4);
    if (!sellers.length) { box.parentElement.style.display = 'none'; return; }
    box.innerHTML = sellers.map(s => `
      <div style="display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid var(--border-color)">
        <div style="width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,var(--primary-color),var(--secondary-color));color:white;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:14px;flex-shrink:0">
          ${(s.displayName || 'S')[0].toUpperCase()}
        </div>
        <div style="flex:1;min-width:0">
          <div style="font-size:13px;font-weight:700;color:var(--dark);white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
            ${s.displayName || 'Seller'}${s.verified ? ' [OK]' : ''}
          </div>
          <div style="font-size:11px;color:var(--gray)">${s.count} listing${s.count !== 1 ? 's' : ''}</div>
        </div>
      </div>`).join('');
  } catch(e) {
    const box = document.getElementById('topSellersBox');
    if (box) box.parentElement.style.display = 'none';
  }
}

//  New Auth API Methods 
API.forgotPassword = async (email) => {
  const r = await fetch(`${API_BASE}/auth.php?action=forgot_password`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ email }),
  });
  const d = await r.json();
  if (!d.success) throw new Error(d.error || 'Failed');
  return d.data;
};

API.resetPassword = async (token, newPassword, confirmPassword) => {
  const r = await fetch(`${API_BASE}/auth.php?action=reset_password`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ token, newPassword, confirmPassword }),
  });
  const d = await r.json();
  if (!d.success) throw new Error(d.error || 'Failed');
  return d.data;
};

API.changePassword = async (currentPassword, newPassword, confirmPassword) => {
  const r = await fetch(`${API_BASE}/auth.php?action=change_password`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${Auth.getToken()}` },
    body: JSON.stringify({ currentPassword, newPassword, confirmPassword }),
  });
  const d = await r.json();
  if (!d.success) throw new Error(d.error || 'Failed');
  return d.data;
};

API.deleteAccount = async (password) => {
  const r = await fetch(`${API_BASE}/auth.php?action=delete_account`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${Auth.getToken()}` },
    body: JSON.stringify({ password }),
  });
  const d = await r.json();
  if (!d.success) throw new Error(d.error || 'Failed');
  return d.data;
};

API.getFavorites = async () => {
  const r = await fetch(`${API_BASE}/favorites.php?action=list`, {
    headers: { 'Authorization': `Bearer ${Auth.getToken()}` },
  });
  const d = await r.json();
  if (!d.success) throw new Error(d.error || 'Failed');
  return d.data;
};


//  Draft / Auto-Save 
const DRAFT_KEY = 'sb_post_draft';

function saveDraft(data) {
  try { localStorage.setItem(DRAFT_KEY, JSON.stringify({ ...data, savedAt: Date.now() })); } catch(e) {}
}

function loadDraft() {
  try {
    const d = JSON.parse(localStorage.getItem(DRAFT_KEY) || 'null');
    if (!d) return null;
    // Expire after 7 days
    if (Date.now() - d.savedAt > 7 * 86400000) { localStorage.removeItem(DRAFT_KEY); return null; }
    return d;
  } catch(e) { return null; }
}

function clearDraft() {
  localStorage.removeItem(DRAFT_KEY);
}

//  Masked Phone Number 
function maskPhone(phone) {
  if (!phone) return '';
  const p = phone.replace(/\D/g, '');
  if (p.length < 6) return phone;
  return p.slice(0, 3) + '***' + p.slice(-3);
}

// ── Messaging API ──────────────────────────────────────────────
API.getConversations = () => apiFetch('api/messages.php?action=conversations');
API.getMessages      = (convId) => apiFetch(`api/messages.php?action=messages&conversation_id=${convId}`);
API.sendMessage      = (convId, text, imageUrl) => apiFetch('api/messages.php?action=send', {
  method: 'POST', body: JSON.stringify({ conversationId: convId, text: text||'', imageUrl: imageUrl||null })
});
API.getOrCreateConversation = (otherUserId, listingId) => apiFetch('api/messages.php?action=get_or_create', {
  method: 'POST', body: JSON.stringify({ otherUserId, listingId })
});
API.setTyping = (convId, typing) => apiFetch('api/messages.php?action=typing', {
  method: 'POST', body: JSON.stringify({ conversationId: convId, typing })
});
API.deleteMessage = (messageId) => apiFetch('api/messages.php?action=delete_message', {
  method: 'POST', body: JSON.stringify({ messageId })
});
API.getUnreadCount = () => apiFetch('api/messages.php?action=unread_count');

// ── NAVBAR BADGE'LERİ ────────────────────────────────────────
async function updateOfferBadge() {
  const badge = document.getElementById('offerNavBadge');
  if (!badge || !Auth.isLoggedIn()) return;
  try {
    const r = await fetch('api/offers.php?action=my_offers&type=sent', {
      headers: { Authorization: 'Bearer ' + Auth.getToken() }
    });
    const d = await r.json();
    if (!d.success) return;
    // Yanıt bekleyen (countered = satıcıdan cevap geldi) ve yeni kabul/red
    const actionNeeded = (d.data.offers || []).filter(o =>
      o.status === 'countered' // satıcı karşı teklif verdi, alıcı cevap beklenıyor
    ).length;
    if (actionNeeded > 0) {
      badge.textContent   = actionNeeded;
      badge.style.display = '';
    } else {
      badge.style.display = 'none';
    }
  } catch(e) {}
}

// Mesaj badge'i de güncelle
async function updateMsgBadge() {
  const badge = document.getElementById('msgBadge');
  if (!badge || !Auth.isLoggedIn()) return;
  try {
    const d = await API.getUnreadCount();
    const count = d.unread || 0;
    if (count > 0) {
      badge.textContent   = count > 9 ? '9+' : count;
      badge.style.display = '';
    } else {
      badge.style.display = 'none';
    }
  } catch(e) {}
}

// ── Notification System ──────────────────────────────────────────
const Notif = {
  _unread: 0,
  _polling: null,
  _lastCount: 0,
  _pushEnabled: false,

  async init() {
    if (!Auth.isLoggedIn()) return;
    await this.refresh();
    this._polling = setInterval(() => this.refresh(), 30000);
    this._initPush();
    document.addEventListener('visibilitychange', () => {
      if (!document.hidden) this.refresh();
    });
  },

  stop() {
    clearInterval(this._polling);
    this._polling = null;
  },

  async refresh() {
    if (!Auth.isLoggedIn()) return;
    try {
      const r = await fetch('api/notifications.php?action=unread_count', {
        headers: { Authorization: 'Bearer ' + Auth.getToken() }
      });
      const d = await r.json();
      if (!d.success) return;
      const { total, messages, offers } = d.data;

      // Sekme başlığı
      const base = document.title.replace(/^\(\d+\)\s*/, '');
      document.title = total > 0 ? `(${total}) ${base}` : base;

      // Navbar rozetler
      this._setBadge('msgBadge',      messages);
      this._setBadge('offerBadge',    offers);
      this._setBadge('offerNavBadge', offers);  // profile navbar
      this._setBadge('notifBadge',   total);
      // Mobil bottom nav rozetler
      this._setBadge('msgBadgeMob',   messages);
      this._setBadge('notifBadgeMob', total);

      // Yeni bildirim geldiyse in-app toast göster
      if (total > this._lastCount && this._lastCount >= 0) {
        const diff = total - this._lastCount;
        if (diff > 0 && this._lastCount >= 0) this._showInAppToast(diff);
      }
      this._lastCount = total;
      this._unread = total;
    } catch(e) {}
  },

  _setBadge(id, count) {
    const el = document.getElementById(id);
    if (!el) return;
    if (count > 0) {
      el.textContent = count > 99 ? '99+' : count;
      el.style.display = 'inline-flex';
    } else {
      el.style.display = 'none';
    }
  },

  _showInAppToast(count) {
    // Zaten bir tane varsa gösterme
    if (document.getElementById('_notifToast')) return;
    const toast = document.createElement('div');
    toast.id = '_notifToast';
    toast.innerHTML = `
      <div style="display:flex;align-items:center;gap:10px">
        <span style="font-size:20px">🔔</span>
        <div>
          <div style="font-weight:700;font-size:13px">You have ${count} new notification${count>1?'s':''}</div>
          <div style="font-size:11px;opacity:.85;margin-top:1px">Tap to view</div>
        </div>
        <button onclick="document.getElementById('_notifToast').remove()" style="background:none;border:none;color:white;font-size:18px;cursor:pointer;margin-left:8px;opacity:.7">×</button>
      </div>`;
    Object.assign(toast.style, {
      position: 'fixed', bottom: '80px', right: '16px', zIndex: '9999',
      background: '#1e293b', color: 'white', padding: '12px 16px',
      borderRadius: '14px', boxShadow: '0 8px 32px rgba(0,0,0,.3)',
      cursor: 'pointer', maxWidth: '300px', animation: 'slideUp .3s ease',
      fontSize: '13px'
    });
    toast.onclick = (e) => {
      if (e.target.tagName === 'BUTTON') return;
      toast.remove();
      window.location.href = 'profile.html?tab=notifications';
    };
    document.body.appendChild(toast);
    setTimeout(() => toast?.remove(), 6000);
  },

  async loadDropdown() {
    const wrap = document.getElementById('notifDropdown');
    if (!wrap) return;
    wrap.innerHTML = '<div style="padding:20px;text-align:center;color:#94a3b8;font-size:13px">Loading…</div>';

    const svgIcon = (type) => {
      const icons = {
        message:       `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>`,
        offer:         `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>`,
        offer_accepted:`<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>`,
        offer_rejected:`<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>`,
        offer_counter: `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 14 4 9 9 4"/><path d="M20 20v-7a4 4 0 0 0-4-4H4"/></svg>`,
        review:        `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>`,
        listing:       `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/></svg>`,
        system:        `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>`,
      };
      return icons[type] || icons.system;
    };

    const iconColor = (type) => ({
      message: '#3b82f6', offer: '#f97316', offer_accepted: '#22c55e',
      offer_rejected: '#ef4444', offer_counter: '#a855f7', review: '#f59e0b',
      listing: '#10b981', system: '#64748b'
    })[type] || '#64748b';

    const iconBg = (type) => ({
      message: '#eff6ff', offer: '#fff7ed', offer_accepted: '#f0fdf4',
      offer_rejected: '#fef2f2', offer_counter: '#faf5ff', review: '#fffbeb',
      listing: '#f0fdf4', system: '#f8fafc'
    })[type] || '#f8fafc';

    try {
      const r = await fetch('api/notifications.php?action=list&limit=10', {
        headers: { Authorization: 'Bearer ' + Auth.getToken() }
      });
      const d = await r.json();
      if (!d.success || !d.data.notifications.length) {
        wrap.innerHTML = `<div style="padding:32px 20px;text-align:center;color:#94a3b8">
          <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#cbd5e1" stroke-width="1.5" style="margin:0 auto 8px;display:block"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
          <div style="font-size:13px;font-weight:600">No notifications yet</div>
        </div>`;
        return;
      }
      const items = d.data.notifications.map(n => `
        <a href="${n.link || '#'}" onclick="Notif.markRead(${n.id})" style="display:flex;gap:12px;padding:12px 16px;text-decoration:none;color:inherit;border-bottom:1px solid #f1f5f9;background:${n.isRead?'white':'#fafbff'};transition:background .15s;align-items:flex-start" onmouseenter="this.style.background='#f8fafc'" onmouseleave="this.style.background='${n.isRead?'white':'#fafbff'}'">
          <div style="width:36px;height:36px;border-radius:10px;background:${iconBg(n.type)};display:flex;align-items:center;justify-content:center;flex-shrink:0;color:${iconColor(n.type)}">${svgIcon(n.type)}</div>
          <div style="flex:1;min-width:0;padding-top:1px">
            <div style="font-size:13px;font-weight:${n.isRead?'500':'700'};color:#1e293b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${n.title}</div>
            ${n.body ? `<div style="font-size:11px;color:#64748b;margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${n.body}</div>` : ''}
            <div style="font-size:10px;color:#94a3b8;margin-top:4px;display:flex;align-items:center;gap:4px">
              <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
              ${n.timeAgo}
            </div>
          </div>
          ${!n.isRead ? '<div style="width:7px;height:7px;background:#f97316;border-radius:50%;flex-shrink:0;margin-top:6px"></div>' : ''}
        </a>`).join('');
      wrap.innerHTML = items + `
        <div style="padding:12px 16px;display:flex;align-items:center;justify-content:space-between;background:#fafafa">
          <button onclick="Notif.markAllRead()" style="background:none;border:none;color:#f97316;font-size:12px;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:4px">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
            Mark all read
          </button>
          <a href="profile.html?tab=notifications" style="color:#64748b;font-size:12px;font-weight:600;text-decoration:none;display:flex;align-items:center;gap:3px">
            See all
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
          </a>
        </div>`;
    } catch(e) {
      wrap.innerHTML = '<div style="padding:16px;color:#ef4444;font-size:13px">Could not load notifications</div>';
    }
  },

  async markRead(id) {
    try {
      await fetch('api/notifications.php?action=mark_read', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Authorization: 'Bearer ' + Auth.getToken() },
        body: JSON.stringify({ notification_id: id })
      });
      this.refresh();
    } catch(e) {}
  },

  async markAllRead() {
    try {
      await fetch('api/notifications.php?action=mark_all_read', {
        method: 'POST',
        headers: { Authorization: 'Bearer ' + Auth.getToken() }
      });
      this.refresh();
      const wrap = document.getElementById('notifDropdown');
      if (wrap) this.loadDropdown();
    } catch(e) {}
  },

  // Push notification izni ve subscription
  async _initPush() {
    if (!('Notification' in window) || !('serviceWorker' in navigator)) return;
    if (Notification.permission === 'granted') {
      this._subscribePush();
    }
    // İzin daha istenmemişse otomatik sormuyoruz - kullanıcı bell'e tıklayınca soracağız
  },

  async requestPushPermission() {
    if (!('Notification' in window)) {
      showToast('Push notifications not supported in this browser', 'error');
      return;
    }
    const perm = await Notification.requestPermission();
    if (perm === 'granted') {
      showToast('Push notifications enabled! 🔔', 'success');
      this._subscribePush();
    } else {
      showToast('Push notifications blocked', 'error');
    }
  },

  async _subscribePush() {
    try {
      if (!('serviceWorker' in navigator)) return;
      const reg = await navigator.serviceWorker.ready;
      const sub = await reg.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: this._urlBase64ToUint8Array(
          'BEl62iUYgUivxIkv69yViEuiBIa-Ib9-SkvMeAtA3LFgDzkrxZJjSgSnfckjBJuBkr3qBUYIHBQFLXYp5Nksh8U'
        )
      });
      await fetch('api/notifications.php?action=push_subscribe', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Authorization: 'Bearer ' + Auth.getToken() },
        body: JSON.stringify(sub.toJSON())
      });
    } catch(e) {}
  },

  _urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - base64String.length % 4) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const raw = window.atob(base64);
    return Uint8Array.from([...raw].map(c => c.charCodeAt(0)));
  },
};

// Notif dropdown toggle
function toggleNotifDropdown() {
  const dd = document.getElementById('notifDropdown');
  if (!dd) return;
  const isOpen = dd.style.display !== 'none';
  // Tüm dropdown'ları kapat
  document.querySelectorAll('.notif-dropdown').forEach(el => el.style.display = 'none');
  if (!isOpen) {
    dd.style.display = 'block';
    Notif.loadDropdown();
    // Dışarı tıklayınca kapat
    setTimeout(() => {
      document.addEventListener('click', function _close(e) {
        if (!dd.contains(e.target) && e.target.id !== 'notifBell') {
          dd.style.display = 'none';
          document.removeEventListener('click', _close);
        }
      });
    }, 100);
  }
}

// DOMContentLoaded'da Notif.init çağır
document.addEventListener('DOMContentLoaded', () => {
  if (Auth.isLoggedIn()) {
    Notif.init();
  }
});
document.addEventListener('auth:change', (e) => {
  if (e.detail?.loggedIn) {
    Notif.init();
  } else {
    Notif.stop();
    ['msgBadge','offerBadge','notifBadge','msgBadgeMob','notifBadgeMob'].forEach(id => {
      const el = document.getElementById(id);
      if (el) el.style.display = 'none';
    });
    document.title = document.title.replace(/^\(\d+\)\s*/, '');
  }
});

// ── PWA Install Prompt ────────────────────────────────────────────────
let _deferredInstall = null;

window.addEventListener('beforeinstallprompt', e => {
  e.preventDefault();
  _deferredInstall = e;

  // 3 saniye sonra banner göster (kullanıcıyı bunaltma)
  setTimeout(() => {
    // Daha önce reddettiyse gösterme
    if (localStorage.getItem('pwa_dismissed')) return;
    showInstallBanner();
  }, 3000);
});

function showInstallBanner() {
  if (document.getElementById('pwa-install-banner')) return;
  const banner = document.createElement('div');
  banner.id = 'pwa-install-banner';
  banner.style.cssText = `
    position:fixed;bottom:80px;left:50%;transform:translateX(-50%);
    background:#0c1445;color:white;border-radius:16px;padding:14px 18px;
    display:flex;align-items:center;gap:12px;z-index:8000;
    box-shadow:0 8px 32px rgba(0,0,0,.3);max-width:360px;width:calc(100% - 32px);
    animation:slideUp .3s cubic-bezier(0.34,1.56,0.64,1)
  `;
  banner.innerHTML = `
    <div style="width:40px;height:40px;background:#f97316;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
    </div>
    <div style="flex:1;min-width:0">
      <div style="font-weight:800;font-size:13px">Install SomBazar</div>
      <div style="font-size:11px;opacity:.7;margin-top:2px">Add to your home screen</div>
    </div>
    <button onclick="doInstallPWA()" style="background:#f97316;color:white;border:none;border-radius:8px;padding:7px 14px;font-size:12px;font-weight:700;cursor:pointer;white-space:nowrap">Install</button>
    <button onclick="dismissInstall()" style="background:rgba(255,255,255,.1);color:white;border:none;border-radius:8px;padding:7px 10px;font-size:16px;cursor:pointer;line-height:1">&#x2715;</button>
  `;
  document.body.appendChild(banner);
}

async function doInstallPWA() {
  if (!_deferredInstall) return;
  _deferredInstall.prompt();
  const { outcome } = await _deferredInstall.userChoice;
  if (outcome === 'accepted') localStorage.setItem('pwa_installed', '1');
  _deferredInstall = null;
  document.getElementById('pwa-install-banner')?.remove();
}

function dismissInstall() {
  localStorage.setItem('pwa_dismissed', '1');
  const b = document.getElementById('pwa-install-banner');
  if (b) { b.style.animation = 'slideDown .2s ease forwards'; setTimeout(() => b.remove(), 200); }
}

window.addEventListener('appinstalled', () => {
  document.getElementById('pwa-install-banner')?.remove();
  _deferredInstall = null;
});

// ── Arama Autocomplete ────────────────────────────────────────────────
(function() {
  const SUGGESTIONS = [
    'Toyota Hilux','Toyota Land Cruiser','Nissan Patrol','Honda CR-V',
    'House Hargeisa','Villa for rent','Apartment Berbera',
    'iPhone 14','Samsung Galaxy','Laptop','MacBook',
    'Sofa set','Dining table','Bedroom furniture',
    'Security guard','Electrician','Plumber','Driver',
    'Goats for sale','Camels','Cattle',
    'Hotel Hargeisa','Guest house',
    'Accountant job','Secretary','Engineer',
  ];

  function initAutocomplete(inputId, formId) {
    const input = document.getElementById(inputId);
    if (!input) return;

    // Dropdown container oluştur
    const wrap = input.parentElement;
    wrap.style.position = 'relative';
    const dd = document.createElement('div');
    dd.id = inputId + '-ac';
    dd.style.cssText = `
      position:absolute;top:calc(100% + 4px);left:0;right:0;
      background:white;border-radius:12px;border:1px solid #e2e8f0;
      box-shadow:0 8px 24px rgba(0,0,0,.12);z-index:1000;
      overflow:hidden;display:none;max-height:280px;overflow-y:auto
    `;
    wrap.appendChild(dd);

    let timer;
    input.addEventListener('input', () => {
      clearTimeout(timer);
      const q = input.value.trim().toLowerCase();
      if (q.length < 2) { dd.style.display = 'none'; return; }
      timer = setTimeout(() => {
        // Recent searches
        const recent = JSON.parse(localStorage.getItem('sb_recent_searches') || '[]');
        const recentMatches = recent.filter(r => r.toLowerCase().includes(q)).slice(0, 3);
        // Static suggestions
        const sugg = SUGGESTIONS.filter(s => s.toLowerCase().includes(q)).slice(0, 5);
        const all = [...new Set([...recentMatches, ...sugg])].slice(0, 7);
        if (!all.length) { dd.style.display = 'none'; return; }

        dd.innerHTML = all.map((s, i) => {
          const isRecent = i < recentMatches.length;
          return `<div class="ac-item" style="display:flex;align-items:center;gap:10px;padding:10px 14px;cursor:pointer;font-size:13px;color:#0f172a;transition:background .1s" onmousedown="event.preventDefault()" onclick="acSelect('${inputId}','${formId}','${s.replace(/'/g,"\\'")}')">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="${isRecent ? '#f97316' : '#94a3b8'}" stroke-width="2">${isRecent ? '<path d="M12 2a10 10 0 1 0 10 10"/><polyline points="12 6 12 12 16 14"/>' : '<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>'}
            </svg>
            <span>${s.replace(new RegExp(q,'gi'), m => `<strong>${m}</strong>`)}</span>
          </div>`;
        }).join('');

        // Hover efekti
        dd.querySelectorAll('.ac-item').forEach(el => {
          el.addEventListener('mouseover', () => el.style.background = '#f8fafc');
          el.addEventListener('mouseout',  () => el.style.background = '');
        });

        dd.style.display = '';
      }, 200);
    });

    input.addEventListener('keydown', e => {
      if (e.key === 'Escape') dd.style.display = 'none';
      if (e.key === 'ArrowDown') {
        const items = dd.querySelectorAll('.ac-item');
        if (items.length) { items[0].focus(); e.preventDefault(); }
      }
    });

    input.addEventListener('blur', () => setTimeout(() => dd.style.display = 'none', 150));
  }

  function acSelect(inputId, formId, value) {
    const input = document.getElementById(inputId);
    if (input) input.value = value;
    document.getElementById(inputId + '-ac').style.display = 'none';
    // Recent searches'e kaydet
    const recent = JSON.parse(localStorage.getItem('sb_recent_searches') || '[]');
    const updated = [value, ...recent.filter(r => r !== value)].slice(0, 8);
    localStorage.setItem('sb_recent_searches', JSON.stringify(updated));
    // Formu submit et
    const form = document.getElementById(formId);
    if (form) form.dispatchEvent(new Event('submit', { bubbles: true }));
    else window.location.href = `listings.html?q=${encodeURIComponent(value)}`;
  }
  window.acSelect = acSelect;

  // Header search'e ekle (tüm sayfalar)
  document.addEventListener('DOMContentLoaded', () => {
    initAutocomplete('headerSearchInput', 'headerSearchForm');
    initAutocomplete('heroSearchInput', 'heroForm');
  });
})();


// ═══════════════════════════════════════════════════════════════
//  Hamburger Menü (Mobil Nav Drawer)
// ═══════════════════════════════════════════════════════════════
(function initHamburger() {
  const LINKS = [
    { href: 'index.html',    icon: '<path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>',  label: 'Home' },
    { href: 'listings.html', icon: '<line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/>', label: 'Browse' },
    { href: 'messages.html', icon: '<path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/>', label: 'Messages', badgeId: 'hamMsgBadge' },
    { href: 'profile.html',  icon: '<path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/>', label: 'Profile' },
    { href: 'packages.html', icon: '<path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/>',  label: 'Plans' },
    { href: 'safety.html',   icon: '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>', label: 'Safety Tips' },
    { href: 'contact.html',  icon: '<path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07A19.5 19.5 0 013.07 8.81 19.79 19.79 0 01.22 2.2 2 2 0 012.22 0h3a2 2 0 012 1.72c.127.96.361 1.903.7 2.81a2 2 0 01-.45 2.11L6.91 7.09c1.07 1.92 2.37 3.66 3.9 5.19l.59-.59a2 2 0 012.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0118 14h.92"/>', label: 'Contact' },
  ];

  function buildDrawer() {
    if (document.getElementById('mobileNavDrawer')) return;

    // Overlay
    const overlay = document.createElement('div');
    overlay.id = 'mobileNavOverlay';
    overlay.className = 'mobile-nav-overlay';
    overlay.onclick = closeDrawer;
    document.body.appendChild(overlay);

    // Drawer
    const drawer = document.createElement('div');
    drawer.id = 'mobileNavDrawer';
    drawer.className = 'mobile-nav-drawer';
    drawer.setAttribute('role', 'dialog');
    drawer.setAttribute('aria-label', 'Navigation menu');
    drawer.setAttribute('aria-modal', 'true');

    const isLoggedIn = !!Auth.getToken();
    const links = isLoggedIn ? LINKS : LINKS.filter(l => !['messages.html','profile.html'].includes(l.href));

    drawer.innerHTML = `
      <div class="mobile-nav-drawer-header">
        <a class="mobile-nav-drawer-logo" href="index.html"><span>SB</span>SomBazar</a>
        <button class="mobile-nav-close" onclick="closeHamburger()" aria-label="Close menu">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
      </div>
      <nav class="mobile-nav-links" aria-label="Mobile navigation">
        ${links.map(l => `
          <a href="${l.href}" class="mobile-nav-link">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">${l.icon}</svg>
            ${l.label}
            ${l.badgeId ? `<span id="${l.badgeId}" class="nav-badge" style="display:none">0</span>` : ''}
          </a>`).join('')}
      </nav>
      <div class="mobile-nav-footer">
        <a href="post.html" class="mobile-nav-post-btn">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
          Post Ad
        </a>
        <div class="mobile-nav-theme-row">
          <span>Dark Mode</span>
          <button class="dark-mode-btn" onclick="toggleDarkMode()" aria-label="Toggle dark mode">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
          </button>
        </div>
        ${isLoggedIn ? '' : '<a href="auth.html" style="display:block;text-align:center;padding:11px;border:1px solid #e2e8f0;border-radius:10px;font-weight:700;font-size:14px;color:#0f172a;text-decoration:none">Sign In / Register</a>'}
      </div>
    `;

    document.body.appendChild(drawer);
  }

  function openDrawer() {
    buildDrawer();
    document.getElementById('mobileNavDrawer').classList.add('open');
    document.getElementById('mobileNavOverlay').classList.add('open');
    document.body.style.overflow = 'hidden';
    document.querySelector('.hamburger-btn')?.classList.add('open');
    // Focus trap
    setTimeout(() => document.querySelector('#mobileNavDrawer .mobile-nav-close')?.focus(), 100);
  }

  function closeDrawer() {
    document.getElementById('mobileNavDrawer')?.classList.remove('open');
    document.getElementById('mobileNavOverlay')?.classList.remove('open');
    document.body.style.overflow = '';
    document.querySelector('.hamburger-btn')?.classList.remove('open');
  }

  // Hamburger butonlarını DOM'a ekle (tüm header'lara)
  function injectHamburgerBtns() {
    const headers = document.querySelectorAll('header, .topbar, nav.header-nav');
    headers.forEach(h => {
      if (h.querySelector('.hamburger-btn')) return;
      const btn = document.createElement('button');
      btn.className = 'hamburger-btn';
      btn.setAttribute('aria-label', 'Open navigation menu');
      btn.setAttribute('aria-expanded', 'false');
      btn.setAttribute('aria-controls', 'mobileNavDrawer');
      btn.innerHTML = '<span></span><span></span><span></span>';
      btn.onclick = openDrawer;
      // Header'ın sağ kısmına ekle
      const actions = h.querySelector('.header-actions, .topbar-actions, nav');
      if (actions) {
        actions.insertBefore(btn, actions.firstChild);
      } else {
        h.appendChild(btn);
      }
    });
  }

  // Escape ile kapat
  document.addEventListener('keydown', e => { if (e.key === 'Escape') closeDrawer(); });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', injectHamburgerBtns);
  } else {
    injectHamburgerBtns();
  }

  window.openHamburger  = openDrawer;
  window.closeHamburger = closeDrawer;
})();

// ── Scroll-to-top butonu ──────────────────────────────────────
(function() {
  const btn = document.createElement('button');
  btn.id = 'scrollTopBtn';
  btn.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="18 15 12 9 6 15"/></svg>';
  btn.setAttribute('aria-label', 'Scroll to top');
  btn.setAttribute('title', 'Back to top');
  btn.style.cssText = `
    position:fixed; bottom:80px; right:16px; width:44px; height:44px;
    background:#f97316; color:white; border:none; border-radius:50%;
    cursor:pointer; display:none; align-items:center; justify-content:center;
    box-shadow:0 4px 16px rgba(249,115,22,.4); z-index:5000;
    transition:opacity .2s, transform .2s; font-family:inherit;
  `;
  btn.addEventListener('click', () => window.scrollTo({ top: 0, behavior: 'smooth' }));
  document.addEventListener('DOMContentLoaded', () => document.body.appendChild(btn));

  let ticking = false;
  window.addEventListener('scroll', () => {
    if (!ticking) {
      requestAnimationFrame(() => {
        btn.style.display = window.scrollY > 400 ? 'flex' : 'none';
        ticking = false;
      });
      ticking = true;
    }
  }, { passive: true });
})();


// ═══════════════════════════════════════════════════════════════
//  Dark Mode Yönetimi
// ═══════════════════════════════════════════════════════════════
const DarkMode = (() => {
  const PREF_KEY = 'sb_theme';

  function get() {
    const saved = localStorage.getItem(PREF_KEY);
    if (saved) return saved;
    return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  }

  function set(theme) {
    document.documentElement.setAttribute('data-theme', theme);
    localStorage.setItem(PREF_KEY, theme);
    updateBtns(theme);
  }

  function toggle() {
    set(get() === 'dark' ? 'light' : 'dark');
  }

  function updateBtns(theme) {
    document.querySelectorAll('.dark-mode-btn').forEach(btn => {
      btn.innerHTML = theme === 'dark'
        ? '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>'
        : '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>';
      btn.setAttribute('aria-label', theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode');
      btn.setAttribute('title', theme === 'dark' ? 'Light mode' : 'Dark mode');
    });
  }

  // Başlangıçta temayı uygula — FOUC önlemek için ASAP
  function init() {
    const theme = get();
    document.documentElement.setAttribute('data-theme', theme);
    // Toggle butonlarını güncelle (sayfa yüklendikten sonra)
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', () => updateBtns(theme));
    } else {
      updateBtns(theme);
    }
    // Sistem tercihi değişince güncelle
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', e => {
      if (!localStorage.getItem(PREF_KEY)) set(e.matches ? 'dark' : 'light');
    });
  }

  init();
  return { get, set, toggle };
})();

window.toggleDarkMode = () => DarkMode.toggle();


// ═══════════════════════════════════════════════════════════════
//  Lazy Loading — IntersectionObserver + WebP Support
// ═══════════════════════════════════════════════════════════════
const LazyLoad = (() => {
  // WebP desteği tespiti
  const supportsWebP = (() => {
    try {
      return document.createElement('canvas')
        .toDataURL('image/webp').startsWith('data:image/webp');
    } catch { return false; }
  })();

  // IntersectionObserver ile lazy loading
  const observer = ('IntersectionObserver' in window)
    ? new IntersectionObserver((entries, obs) => {
        entries.forEach(entry => {
          if (!entry.isIntersecting) return;
          const img = entry.target;
          const src = img.dataset.src;
          if (src) {
            img.src = supportsWebP && img.dataset.srcWebp ? img.dataset.srcWebp : src;
            img.removeAttribute('data-src');
            img.removeAttribute('data-src-webp');
            img.classList.add('loaded');
          }
          obs.unobserve(img);
        });
      }, { rootMargin: '200px 0px', threshold: 0.01 })
    : null;

  function observe(img) {
    if (observer) {
      observer.observe(img);
    } else {
      // Fallback — hemen yükle
      if (img.dataset.src) img.src = img.dataset.src;
    }
  }

  // Tüm lazy img'leri init et
  function init(container = document) {
    container.querySelectorAll('img[data-src]').forEach(observe);
  }

  // Dinamik eklenen içerikler için
  function observeNew(container) {
    container.querySelectorAll('img[data-src]').forEach(observe);
  }

  return { init, observe, observeNew, supportsWebP };
})();

// Sayfa yüklenince tüm lazy görselleri başlat
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => LazyLoad.init());
} else {
  LazyLoad.init();
}

// ── Loading Spinner / Overlay ──────────────────────────────────
const Spinner = (() => {
  let _overlay = null;

  function show(msg = 'Loading…') {
    if (!_overlay) {
      _overlay = document.createElement('div');
      _overlay.id = 'globalSpinner';
      _overlay.style.cssText = `
        position:fixed;inset:0;background:rgba(0,0,0,.35);
        display:flex;align-items:center;justify-content:center;
        z-index:99999;backdrop-filter:blur(2px);
      `;
      _overlay.innerHTML = `
        <div style="background:white;border-radius:16px;padding:28px 36px;text-align:center;
                    box-shadow:0 16px 48px rgba(0,0,0,.2);min-width:160px">
          <div style="width:40px;height:40px;border:3px solid #f1f5f9;border-top-color:#f97316;
                      border-radius:50%;animation:spin .7s linear infinite;margin:0 auto 14px"></div>
          <div id="spinnerMsg" style="font-size:14px;font-weight:600;color:#0f172a">${msg}</div>
        </div>
      `;
    } else {
      document.getElementById('spinnerMsg').textContent = msg;
    }
    document.body.appendChild(_overlay);
  }

  function hide() {
    _overlay?.remove();
    _overlay = null;
  }

  function update(msg) {
    if (_overlay) document.getElementById('spinnerMsg').textContent = msg;
  }

  return { show, hide, update };
})();

// spin keyframe ekle
(function(){
  if (!document.querySelector('#spinKeyframe')) {
    const s = document.createElement('style');
    s.id = 'spinKeyframe';
    s.textContent = '@keyframes spin{to{transform:rotate(360deg)}}';
    document.head.appendChild(s);
  }
})();

// ── RequestIdleCallback Polyfill + Yardımcılar ────────────────
const idle = window.requestIdleCallback
  || ((fn, opts) => setTimeout(() => fn({ didTimeout: false, timeRemaining: () => 50 }), opts?.timeout || 1));

// İdl'da çalıştır — düşük öncelikli işler için
function whenIdle(fn) { idle(fn, { timeout: 2000 }); }

// ── Web Share API + Copy Link ──────────────────────────────────
async function shareOrCopy(title, text, url) {
  url = url || window.location.href;
  if (navigator.share) {
    try {
      await navigator.share({ title, text, url });
      return true;
    } catch(e) {
      if (e.name === 'AbortError') return false; // kullanıcı iptal etti
    }
  }
  // Fallback: clipboard kopyala
  return copyToClipboard(url);
}

async function copyToClipboard(text) {
  try {
    await navigator.clipboard.writeText(text);
    showToast('🔗 Link copied to clipboard!', 'success');
    return true;
  } catch {
    // Eski tarayıcı fallback
    const el = document.createElement('textarea');
    el.value = text;
    el.style.cssText = 'position:fixed;opacity:0;pointer-events:none';
    document.body.appendChild(el);
    el.select();
    const ok = document.execCommand('copy');
    el.remove();
    if (ok) showToast('🔗 Link copied!', 'success');
    return ok;
  }
}

window.shareOrCopy   = shareOrCopy;
window.copyToClipboard = copyToClipboard;

// ── Print Listing ──────────────────────────────────────────────
function printListing() {
  window.print();
}
window.printListing = printListing;

// ── Geolocation Helper ─────────────────────────────────────────
const Geo = (() => {
  async function getCurrentCity() {
    return new Promise((res, rej) => {
      if (!navigator.geolocation) { rej(new Error('Geolocation not supported')); return; }
      navigator.geolocation.getCurrentPosition(
        async pos => {
          try {
            const { latitude, longitude } = pos.coords;
            const r = await fetch(`https://nominatim.openstreetmap.org/reverse?lat=${latitude}&lon=${longitude}&format=json`, {
              headers: { 'Accept-Language': 'en' }
            });
            const d = await r.json();
            const city = d.address?.city || d.address?.town || d.address?.village || '';
            res({ city, latitude, longitude, raw: d.address });
          } catch { res({ city: '', latitude: pos.coords.latitude, longitude: pos.coords.longitude }); }
        },
        err => rej(err),
        { timeout: 8000, maximumAge: 300000 }  // 5 dakika cache
      );
    });
  }

  return { getCurrentCity };
})();

window.Geo = Geo;

// ═══════════════════════════════════════════════════════════════
//  IndexedDB — Offline Veri Katmanı
// ═══════════════════════════════════════════════════════════════
const SomDB = (() => {
  const DB_NAME    = 'sombazar-app';
  const DB_VERSION = 1;
  let _db = null;

  const STORES = {
    listings:         { keyPath: 'id' },
    recentlyViewed:   { keyPath: 'id' },
    draftListings:    { keyPath: 'draftId', autoIncrement: true },
    pendingOffers:    { keyPath: 'id',      autoIncrement: true },
    pendingMessages:  { keyPath: 'id',      autoIncrement: true },
    pendingFavorites: { keyPath: 'id',      autoIncrement: true },
    cachedSearches:   { keyPath: 'query' },
    userPrefs:        { keyPath: 'key' },
  };

  function open() {
    if (_db) return Promise.resolve(_db);
    return new Promise((res, rej) => {
      const req = indexedDB.open(DB_NAME, DB_VERSION);
      req.onupgradeneeded = ev => {
        const db = ev.target.result;
        Object.entries(STORES).forEach(([name, opts]) => {
          if (!db.objectStoreNames.contains(name)) {
            const store = db.createObjectStore(name, opts);
            // İndeksler
            if (name === 'recentlyViewed') store.createIndex('viewedAt', 'viewedAt');
            if (name === 'listings')       store.createIndex('category', 'category');
            if (name === 'cachedSearches') store.createIndex('cachedAt', 'cachedAt');
          }
        });
      };
      req.onsuccess = ev => { _db = ev.target.result; res(_db); };
      req.onerror   = ev => rej(ev.target.error);
    });
  }

  function tx(storeName, mode = 'readonly') {
    return open().then(db => db.transaction(storeName, mode).objectStore(storeName));
  }

  function get(storeName, key) {
    return tx(storeName).then(store => idbReq(store.get(key)));
  }

  function getAll(storeName, index, query) {
    return tx(storeName).then(store => {
      const src = index ? store.index(index) : store;
      return idbReq(src.getAll(query));
    });
  }

  function put(storeName, value) {
    return tx(storeName, 'readwrite').then(store => idbReq(store.put(value)));
  }

  function add(storeName, value) {
    return tx(storeName, 'readwrite').then(store => idbReq(store.add(value)));
  }

  function del(storeName, key) {
    return tx(storeName, 'readwrite').then(store => idbReq(store.delete(key)));
  }

  function clear(storeName) {
    return tx(storeName, 'readwrite').then(store => idbReq(store.clear()));
  }

  function count(storeName) {
    return tx(storeName).then(store => idbReq(store.count()));
  }

  function idbReq(req) {
    return new Promise((res, rej) => {
      req.onsuccess = () => res(req.result);
      req.onerror   = () => rej(req.error);
    });
  }

  // ── Public API ──────────────────────────────────────────────

  // Son görüntülenen ilanları kaydet (max 20)
  async function trackView(listing) {
    try {
      await put('recentlyViewed', { ...listing, viewedAt: Date.now() });
      // 20'den fazlaysa eskisini sil
      const all = await getAll('recentlyViewed', 'viewedAt');
      if (all.length > 20) {
        const oldest = all.sort((a,b) => a.viewedAt - b.viewedAt)[0];
        await del('recentlyViewed', oldest.id);
      }
    } catch(e) { /* IDB desteklenmiyor */ }
  }

  // Son görüntülenen ilanları getir
  async function getRecentlyViewed() {
    try {
      const all = await getAll('recentlyViewed', 'viewedAt');
      return all.sort((a,b) => b.viewedAt - a.viewedAt).slice(0, 10);
    } catch { return []; }
  }

  // İlan taslağı kaydet (post.html otomatik kayıt)
  async function saveDraft(data) {
    try {
      const existing = await getAll('draftListings');
      if (existing.length) {
        await put('draftListings', { ...existing[0], ...data, savedAt: Date.now() });
        return existing[0].draftId;
      }
      return await add('draftListings', { ...data, savedAt: Date.now() });
    } catch { return null; }
  }

  async function getDraft() {
    try {
      const all = await getAll('draftListings');
      return all.sort((a,b) => b.savedAt - a.savedAt)[0] || null;
    } catch { return null; }
  }

  async function clearDraft() {
    try { await clear('draftListings'); } catch {}
  }

  // Offline teklif kuyruğu
  async function queueOffer(data, token) {
    try {
      const id = await add('pendingOffers', { data, token, queuedAt: Date.now() });
      // SW'ye haber ver
      swMessage({ type: 'QUEUE_OFFER', payload: { data, token } });
      return id;
    } catch { return null; }
  }

  // Offline mesaj kuyruğu
  async function queueMessage(data, token) {
    try {
      const id = await add('pendingMessages', { data, token, queuedAt: Date.now() });
      swMessage({ type: 'QUEUE_MESSAGE', payload: { data, token } });
      return id;
    } catch { return null; }
  }

  // Offline favori kuyruğu
  async function queueFavorite(listingId, action, token) {
    try {
      await add('pendingFavorites', { data: { listingId, action }, token, queuedAt: Date.now() });
      swMessage({ type: 'QUEUE_FAVORITE', payload: { data: { listingId, action }, token } });
    } catch {}
  }

  // Arama sonuçlarını önbelleğe al (5 dk TTL)
  async function cacheSearch(query, results) {
    try {
      await put('cachedSearches', { query, results, cachedAt: Date.now() });
    } catch {}
  }

  async function getCachedSearch(query) {
    try {
      const entry = await get('cachedSearches', query);
      if (!entry) return null;
      if (Date.now() - entry.cachedAt > 5 * 60 * 1000) return null; // 5 dk TTL
      return entry.results;
    } catch { return null; }
  }

  // Kullanıcı tercihlerini kaydet
  async function setPref(key, value) {
    try { await put('userPrefs', { key, value, updatedAt: Date.now() }); } catch {}
  }

  async function getPref(key, defaultVal = null) {
    try {
      const entry = await get('userPrefs', key);
      return entry ? entry.value : defaultVal;
    } catch { return defaultVal; }
  }

  // SW'ye mesaj gönder
  function swMessage(msg) {
    if ('serviceWorker' in navigator && navigator.serviceWorker.controller) {
      navigator.serviceWorker.controller.postMessage(msg);
    }
  }

  // Background sync kayıt
  async function registerSync(tag) {
    try {
      const reg = await navigator.serviceWorker.ready;
      if ('sync' in reg) await reg.sync.register(tag);
    } catch {}
  }

  // IDB'nin desteklenip desteklenmediğini kontrol et
  const isSupported = (() => {
    try { return 'indexedDB' in window && window.indexedDB !== null; }
    catch { return false; }
  })();

  return {
    open, get, getAll, put, add, del, clear, count,
    trackView, getRecentlyViewed,
    saveDraft, getDraft, clearDraft,
    queueOffer, queueMessage, queueFavorite,
    cacheSearch, getCachedSearch,
    setPref, getPref,
    registerSync, swMessage,
    isSupported,
  };
})();

// ═══════════════════════════════════════════════════════════════
//  Standalone (PWA) Mod Tespiti & UI Ayarlamaları
// ═══════════════════════════════════════════════════════════════
(function detectStandalone() {
  const isStandalone =
    window.matchMedia('(display-mode: standalone)').matches ||
    window.navigator.standalone === true ||         // iOS Safari
    document.referrer.includes('android-app://');   // TWA

  if (!isStandalone) return;

  // body'ye class ekle — CSS'de özel stiller için
  document.documentElement.classList.add('pwa-standalone');

  // Topbar'daki geri ok butonunu göster (tarayıcı back butonu yok)
  document.addEventListener('DOMContentLoaded', () => {
    // Eğer history stack boşsa ana sayfaya yönlendir
    const addBackBtn = () => {
      const topbar = document.querySelector('.topbar, .nav-bar, header');
      if (!topbar || document.querySelector('.pwa-back-btn')) return;
      if (window.history.length <= 1) return; // anasayfada değilse

      const btn = document.createElement('button');
      btn.className = 'pwa-back-btn';
      btn.setAttribute('aria-label', 'Go back');
      btn.innerHTML = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>';
      btn.style.cssText = `
        background:none;border:none;cursor:pointer;padding:8px;
        color:var(--dark,#0f172a);display:flex;align-items:center;
        border-radius:8px;transition:background .15s;
      `;
      btn.addEventListener('click', () => window.history.back());
      btn.addEventListener('mouseover', () => btn.style.background = '#f1f5f9');
      btn.addEventListener('mouseout',  () => btn.style.background = '');
      topbar.insertBefore(btn, topbar.firstChild);
    };
    addBackBtn();

    // Status bar rengi (iOS)
    const meta = document.querySelector('meta[name="theme-color"]');
    if (!meta) {
      const m = document.createElement('meta');
      m.name = 'theme-color';
      m.content = '#f97316';
      document.head.appendChild(m);
    }
  });

  // Display mode değişikliklerini dinle
  window.matchMedia('(display-mode: standalone)').addEventListener('change', e => {
    document.documentElement.classList.toggle('pwa-standalone', e.matches);
  });
})();

// ═══════════════════════════════════════════════════════════════
//  SW → App Mesaj Dinleyicisi (sync tamamlandı bildirimleri)
// ═══════════════════════════════════════════════════════════════
if ('serviceWorker' in navigator) {
  navigator.serviceWorker.addEventListener('message', e => {
    const { type, tag } = e.data || {};
    if (type === 'SYNC_COMPLETE') {
      if (tag === 'offers')   showToast('✅ Offline offer sent successfully', 'success');
      if (tag === 'messages') showToast('✅ Offline message delivered', 'success');
    }
    if (type === 'CACHE_SIZE') {
      console.info(`[SomBazar] SW cache: ${e.data.size} items`);
    }
  });

  // SW güncelleme kontrolü — yeni sw varsa bildir
  navigator.serviceWorker.ready.then(reg => {
    reg.addEventListener('updatefound', () => {
      const newSW = reg.installing;
      newSW?.addEventListener('statechange', () => {
        if (newSW.state === 'installed' && navigator.serviceWorker.controller) {
          // Sayfa güncellemesi var — kullanıcıya sor
          const toast = document.createElement('div');
          toast.style.cssText = `
            position:fixed;bottom:80px;left:50%;transform:translateX(-50%);
            background:#0f172a;color:white;border-radius:14px;padding:12px 18px;
            display:flex;align-items:center;gap:12px;z-index:9999;
            box-shadow:0 8px 32px rgba(0,0,0,.3);max-width:340px;width:calc(100% - 32px);
            font-size:13px;font-weight:600;
          `;
          toast.innerHTML = `
            <span style="flex:1">🆕 New version available</span>
            <button onclick="window.location.reload()" style="background:#f97316;color:white;border:none;border-radius:8px;padding:6px 14px;font-size:12px;font-weight:700;cursor:pointer">Refresh</button>
            <button onclick="this.parentElement.remove()" style="background:rgba(255,255,255,.1);color:white;border:none;border-radius:8px;padding:6px 10px;cursor:pointer">✕</button>
          `;
          document.body?.appendChild(toast);
          setTimeout(() => toast?.remove(), 15000);
        }
      });
    });
  });
}

// ═══════════════════════════════════════════════════════════════
//  Periodic Background Sync Kaydı (deneysel API)
// ═══════════════════════════════════════════════════════════════
async function registerPeriodicSync() {
  try {
    const reg = await navigator.serviceWorker.ready;
    if ('periodicSync' in reg) {
      const status = await navigator.permissions.query({ name: 'periodic-background-sync' });
      if (status.state === 'granted') {
        await reg.periodicSync.register('check-notifications', {
          minInterval: 60 * 60 * 1000 // 1 saatte bir
        });
      }
    }
  } catch { /* desteklenmiyor, sessizce geç */ }
}
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', registerPeriodicSync);
} else {
  registerPeriodicSync();
}
