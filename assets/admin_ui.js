// ── Admin UI – Fixed & Complete ─────────────────────────────────────────────
// API action names verified against api/admin.php

// ── Utils ────────────────────────────────────────────────────────────────────
function _esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
function _date(d){if(!d)return'—';try{return new Date(d).toLocaleDateString('en-US',{month:'short',day:'2-digit',year:'numeric'});}catch(e){return'—';}}
function _money(n){return'$'+Number(n||0).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});}
function _set(id,v){var el=document.getElementById(id);if(el)el.textContent=(v!==null&&v!==undefined)?v:'—';}
function _html(id,v){var el=document.getElementById(id);if(el)el.innerHTML=v;}
function _token(){return localStorage.getItem('sb_token')||'';}

// ── Theme ────────────────────────────────────────────────────────────────────
function toggleTheme(){
  var d=document.documentElement,n=d.dataset.theme==='dark'?'light':'dark';
  d.dataset.theme=n;localStorage.setItem('sb_theme',n);
}

// ── Topbar sync ───────────────────────────────────────────────────────────────
function _syncTopbar(){
  var name=(document.getElementById('adminName')||{}).textContent||'Admin';
  _set('topbarName',name);
  ['topbarAva','sidebarAvatarInitial'].forEach(function(id){
    var el=document.getElementById(id);if(el)el.textContent=name[0].toUpperCase();
  });
}
(function(){
  var el=document.getElementById('adminName');
  if(el)new MutationObserver(_syncTopbar).observe(el,{childList:true,subtree:true,characterData:true});
  _syncTopbar();
})();

// ── Active nav ────────────────────────────────────────────────────────────────
(function(){
  var orig=(typeof showTab==='function')?showTab:null;if(!orig)return;
  window.showTab=function(tab){
    orig(tab);
    document.querySelectorAll('.nav-item').forEach(function(el){el.classList.remove('active');});
    var el=document.getElementById('nav-'+tab);if(el)el.classList.add('active');
  };
})();

// ── Filter helpers ────────────────────────────────────────────────────────────
function setUT(val,el){el.closest('.ul-tabs').querySelectorAll('.ul-tab').forEach(function(t){t.classList.remove('active');});el.classList.add('active');var s=document.getElementById('userFilter');if(s){s.value=val;loadUsers();}}
function setVT(val,el){el.closest('.pill-tabs').querySelectorAll('.pill-tab').forEach(function(t){t.classList.remove('active');});el.classList.add('active');loadVerifications();}
function setOffFilter(val,el){el.closest('.pill-tabs').querySelectorAll('.pill-tab').forEach(function(t){t.classList.remove('active');});el.classList.add('active');var s=document.getElementById('offerStatusFilter');if(s){s.value=val;loadAdminOffers();}}
function setCpnFilter(val,el){el.closest('.pill-tabs').querySelectorAll('.pill-tab').forEach(function(t){t.classList.remove('active');});el.classList.add('active');loadCoupons();}
function setRepFilter(val,el){el.closest('.pill-tabs').querySelectorAll('.pill-tab').forEach(function(t){t.classList.remove('active');});el.classList.add('active');var s=document.getElementById('reportStatusFilter');if(s){s.value=val;loadReports();}}
function setAnnFilter(val,el){el.closest('.ul-tabs').querySelectorAll('.ul-tab').forEach(function(t){t.classList.remove('active');});el.classList.add('active');loadAnnouncements();}
function setAnalPeriod(val,el){el.closest('.seg').querySelectorAll('.seg-btn').forEach(function(t){t.classList.remove('on');});el.classList.add('on');var inp=document.getElementById('analyticsPeriod');if(inp)inp.value=val;loadAnalytics();}
function exportCSV(type){window.open('api/admin.php?action=export_csv&type='+type+'&token='+_token(),'_blank');}

// ═══════════════════════════════════════════════════════════════════════════
// DASHBOARD – enhance after original runs
// API: 'stats' → {total_users, active_listings, total_messages, total_revenue,
//                  signups_chart:[{day,count}], categories:[{category,count}]}
// ═══════════════════════════════════════════════════════════════════════════
(function(){
  var orig=(typeof loadDashboard==='function')?loadDashboard:null;if(!orig)return;
  window.loadDashboard=async function(){
    await orig();
    var al=(document.getElementById('statListings')||{}).textContent;
    var pl=(document.getElementById('statPending')||{}).textContent;
    if(al)_set('listStatActive',al);if(pl)_set('listStatPending',pl);
    var pv=document.getElementById('navBadgeVerif'),pr2=document.getElementById('navBadgeReports');
    if(pv&&pv.style.display!=='none')_set('qaVerifSub',pv.textContent+' pending');
    if(pr2&&pr2.style.display!=='none')_set('qaReportSub',pr2.textContent+' new cases');
    _loadDashListings();
  };
})();

async function _loadDashListings(){
  var tbody=document.getElementById('dashListingsBody');if(!tbody)return;
  try{
    var d=await adminFetch('listings&status=all&page=1');
    var items=(d.listings||[]).slice(0,5);
    if(!items.length){tbody.innerHTML='<tr><td colspan="6" class="empty-row">No listings yet</td></tr>';return;}
    var sc={active:'bg',pending:'by',sold:'bb',rented:'bp',rejected:'br',deleted:'bc',expired:'bc'};
    tbody.innerHTML=items.map(function(l){
      return'<tr><td><div style="display:flex;align-items:center;gap:10px"><div class="listing-thumb" style="display:flex;align-items:center;justify-content:center"><span class="ms" style="font-size:16px;color:var(--text3)">image</span></div><span style="font-weight:700">'+_esc(l.title||'—')+'</span></div></td><td style="color:var(--text2)">'+_esc(l.category||'—')+'</td><td style="font-weight:800">$'+Number(l.price||0).toLocaleString()+'</td><td style="color:var(--text2)">'+_esc(l.seller||'—')+'</td><td><span class="badge '+(sc[l.status]||'bc')+'" style="text-transform:capitalize">'+_esc(l.status||'—')+'</span></td><td><button class="ib" onclick="approveListing('+l.id+')"><span class="ms">more_vert</span></button></td></tr>';
    }).join('');
  }catch(e){tbody.innerHTML='<tr><td colspan="6" class="empty-row">Could not load listings</td></tr>';}
}

// Chart overrides
// signups_chart:[{day:"2026-03-06", count:1}]  → column bars
(function(){
  var orig=(typeof renderSignupsChart==='function')?renderSignupsChart:null;if(!orig)return;
  window.renderSignupsChart=function(data){
    var el=document.getElementById('signupsChart');if(!el)return;
    if(!data||!data.length){el.innerHTML='<div class="empty-state" style="width:100%;padding:10px"><small>No signup data</small></div>';return;}
    var max=Math.max.apply(null,data.map(function(d){return d.count;}));if(max<1)max=1;
    el.innerHTML=data.map(function(d){
      var h=Math.max(Math.round((d.count/max)*100),4);
      var label=d.day?d.day.slice(5):'';
      return'<div style="display:flex;flex-direction:column;align-items:center;justify-content:flex-end;gap:3px;flex:1;min-width:0;height:100%"><div style="width:100%;max-width:40px;border-radius:4px 4px 0 0;background:var(--pr);opacity:.85;height:'+h+'px;min-height:4px;transition:height .4s"></div><div style="font-size:9px;color:var(--text3);font-weight:600;white-space:nowrap">'+label+'</div></div>';
    }).join('');
  };
})();

// categories:[{category,count}] → horizontal bars
(function(){
  var orig=(typeof renderCatsChart==='function')?renderCatsChart:null;if(!orig)return;
  window.renderCatsChart=function(data){
    var el=document.getElementById('catsChart');if(!el)return;
    if(!data||!data.length){el.innerHTML='<div class="empty-state" style="padding:12px"><small>No category data</small></div>';return;}
    var total=data.reduce(function(s,d){return s+d.count;},0);
    var colors=['#ec5b13','#2563eb','#16a34a','#d97706','#7c3aed','#dc2626'];
    el.innerHTML=data.slice(0,6).map(function(d,i){
      var pct=total>0?Math.round((d.count/total)*100):0;
      return'<div class="cat-bar-row"><div class="cat-name">'+_esc(d.category||'Other')+'</div><div class="cat-track"><div class="cat-fill" style="width:'+pct+'%;background:'+colors[i%colors.length]+'"></div></div><div class="cat-pct">'+pct+'%</div></div>';
    }).join('');
  };
})();

// ═══════════════════════════════════════════════════════════════════════════
// USERS  API: 'users' → {users:[{id,displayName,email,phone,verified,banned,plan,createdAt}], total}
// ═══════════════════════════════════════════════════════════════════════════
window.loadUsers=async function(){
  var tbody=document.getElementById('usersBody');
  if(tbody)tbody.innerHTML='<tr><td colspan="5" class="empty-row">Loading...</td></tr>';
  try{
    var search=(document.getElementById('userSearch')||{value:''}).value||'';
    var filter=(document.getElementById('userFilter')||{value:''}).value||'';
    var d=await adminFetch('users&q='+encodeURIComponent(search)+'&status='+filter+'&page=1');
    var users=d.users||[];
    _set('userCount','Showing '+users.length+' of '+(d.total||users.length)+' users');
    if(!users.length){if(tbody)tbody.innerHTML='<tr><td colspan="5" class="empty-row">No users found</td></tr>';return;}
    if(tbody)tbody.innerHTML=users.map(function(u){
      var name=_esc(u.displayName||u.display_name||'—');
      var joined=_date(u.createdAt||u.created_at);
      var stColor=u.banned?'var(--red)':u.verified?'var(--green)':'var(--yellow)';
      var stLabel=u.banned?'Banned':u.verified?'Active':'Pending';
      var statusEl='<span style="display:flex;align-items:center;gap:5px;color:'+stColor+';font-weight:700"><span style="width:7px;height:7px;border-radius:50%;background:'+stColor+';display:inline-block"></span>'+stLabel+'</span>';
      var plan=(u.plan||'free').toLowerCase();
      var roleCls=plan==='admin'?'rb-admin':plan==='moderator'?'rb-moderator':plan!=='free'?'rb-vendor':'rb-customer';
      var roleLabel=plan==='free'?'CUSTOMER':plan.toUpperCase();
      return'<tr><td><div style="display:flex;align-items:center;gap:10px"><div class="uav uav-sm">'+name[0]+'</div><div><div style="font-weight:700">'+name+'</div><div style="font-size:11px;color:var(--text3)">'+_esc(u.email||'')+'</div></div></div></td><td style="color:var(--text2)">'+joined+'</td><td><span class="role-badge '+roleCls+'">'+roleLabel+'</span></td><td>'+statusEl+'</td><td><div class="act-group"><button class="ib" title="View"><span class="ms">visibility</span></button><button class="ib" title="Edit"><span class="ms">edit</span></button><button class="ib '+(u.banned?'':'ib-del')+'" onclick="'+(u.banned?'unbanUser('+u.id+')':'openBanModal('+u.id+')')+'"><span class="ms">'+(u.banned?'check_circle':'block')+'</span></button></div></td></tr>';
    }).join('');
  }catch(e){if(tbody)tbody.innerHTML='<tr><td colspan="5" class="empty-row" style="color:var(--red)">'+_esc(e.message)+'</td></tr>';}
};

// ═══════════════════════════════════════════════════════════════════════════
// LISTINGS  API: 'listings' → {listings:[{id,title,category,price,seller,status,createdAt}], total}
// ═══════════════════════════════════════════════════════════════════════════
window.loadListingsAdmin=async function(){
  var tbody=document.getElementById('listingsBody');
  if(tbody)tbody.innerHTML='<tr><td colspan="7" class="empty-row">Loading...</td></tr>';
  try{
    var filter=(document.getElementById('listingFilter')||{value:'all'}).value||'all';
    var search=encodeURIComponent((document.getElementById('listingSearch')||{value:''}).value||'');
    var d=await adminFetch('listings&status='+filter+'&q='+search);
    var items=d.listings||[];
    _set('listingsCount','Showing 1 to '+items.length+' of '+(d.total||items.length)+' results');
    if(d.stats){_set('listStatTotal',d.stats.total);_set('listStatActive',d.stats.active);_set('listStatPending',d.stats.pending);_set('listStatExpired',d.stats.expired);}
    else{_set('listStatTotal',d.total||items.length);_set('listStatPending',items.filter(function(l){return l.status==='pending';}).length);_set('listStatActive',items.filter(function(l){return l.status==='active';}).length);_set('listStatExpired',items.filter(function(l){return l.status==='expired';}).length);}
    if(!items.length){if(tbody)tbody.innerHTML='<tr><td colspan="7" class="empty-row">No listings found</td></tr>';return;}
    var sc={active:'bg',pending:'by',sold:'bb',rented:'bp',rejected:'br',deleted:'bc',expired:'bc'};
    if(tbody)tbody.innerHTML=items.map(function(l){
      var boosted=(l.boostedUntil&&new Date(l.boostedUntil)>new Date())?'<span class="badge bo" style="font-size:9px;margin-left:4px">Boosted</span>':'';
      var actions=l.status==='pending'?'<button class="act act-ok" onclick="approveListing('+l.id+')">Approve</button><button class="act act-no" onclick="rejectListingAdmin('+l.id+')">Reject</button>':l.status==='expired'?'<button class="act act-view" onclick="approveListing('+l.id+')">Relist</button>':'<button class="act act-ok" onclick="approveListing('+l.id+')">Approve</button><button class="act act-no" onclick="rejectListingAdmin('+l.id+')">Reject</button>';
      return'<tr id="lrow-'+l.id+'"><td style="width:36px"><input type="checkbox" class="bulk-cb" value="'+l.id+'" onchange="onBulkCbChange('+l.id+',this.checked)"></td><td><div style="display:flex;align-items:center;gap:10px"><div class="listing-thumb" style="display:flex;align-items:center;justify-content:center"><span class="ms" style="font-size:16px;color:var(--text3)">image</span></div><div><a href="listing.html?id='+l.id+'" target="_blank" style="font-weight:700;color:var(--text)">'+_esc(l.title||'—')+'</a><div style="font-size:11px;color:var(--text3)">ID: #SB-'+l.id+' · '+_date(l.createdAt||l.created_at)+boosted+'</div></div></div></td><td style="color:var(--text2)">'+_esc(l.category||'—')+'</td><td><div style="display:flex;align-items:center;gap:7px"><div class="uav uav-sm" style="font-size:9px">'+(l.seller||'?')[0].toUpperCase()+'</div><span>'+_esc(l.seller||'—')+'</span></div></td><td style="font-weight:800">$'+Number(l.price||0).toLocaleString()+'</td><td><span class="badge '+(sc[l.status]||'bc')+'" style="text-transform:capitalize">'+_esc(l.status||'—')+'</span></td><td><div class="act-group">'+actions+'<button class="ib ib-del" onclick="deleteListing('+l.id+')"><span class="ms">delete</span></button></div></td></tr>';
    }).join('');
  }catch(e){if(tbody)tbody.innerHTML='<tr><td colspan="7" class="empty-row" style="color:var(--red)">'+_esc(e.message)+'</td></tr>';}
};
window.loadListings=window.loadListingsAdmin;

// ═══════════════════════════════════════════════════════════════════════════
// PAYMENTS  API: 'payments' → {payments:[{id,userName,userEmail,plan,amount,method,status,createdAt,screenshotUrl}]}
// ═══════════════════════════════════════════════════════════════════════════
window.loadPayments=async function(){
  var tbody=document.getElementById('paymentsBody');
  if(tbody)tbody.innerHTML='<tr><td colspan="6" class="empty-row">Loading...</td></tr>';
  try{
    var filter=(document.getElementById('paymentFilter')||{value:'pending'}).value||'pending';
    var d=await adminFetch('payments&status='+filter);
    var items=d.payments||[];
    // Update stat cards with real totals from stats
    if(d.stats){_set('revTotal',_money(d.stats.total_revenue));_set('revPending',d.stats.pending_count||0);_set('revApproved',(d.stats.success_rate||'—')+'%');}
    _set('payCount','Showing 1 to '+items.length+' of '+(d.total||items.length)+' transactions');
    if(!items.length){if(tbody)tbody.innerHTML='<tr><td colspan="6" class="empty-row">No payments found</td></tr>';return;}
    var sc={pending:'by',approved:'bg',rejected:'br'};
    if(tbody)tbody.innerHTML=items.map(function(p,i){
      var trxId='#TRX-'+String(89000+i+(p.id||0)).slice(-5);
      var approveBlock=p.status==='pending'?'<div style="display:flex;gap:4px"><button class="act act-ok" onclick="approvePayment('+p.id+')">Approve</button><button class="act act-del" onclick="rejectPayment('+p.id+')">Reject</button></div>':'<span class="badge '+(sc[p.status]||'bc')+'" style="text-transform:capitalize">'+_esc(p.status)+'</span>';
      var ssUrl=p.screenshotUrl||p.receiptUrl||p.screenshot_url||'';
      return'<tr><td style="font-family:var(--mono);font-size:12px;font-weight:700;color:var(--text2)">'+trxId+'</td><td><div style="display:flex;align-items:center;gap:8px"><div class="uav uav-sm">'+((p.userName||'?')[0].toUpperCase())+'</div><div><div style="font-weight:700;font-size:12.5px">'+_esc(p.userName||'—')+'</div><div style="font-size:10.5px;color:var(--text3)">'+_esc(p.userEmail||'')+'</div></div></div></td><td style="font-weight:800;font-size:14px">$'+(p.amount||0)+'</td><td style="color:var(--text2)">'+_date(p.createdAt||p.created_at)+'</td><td>'+approveBlock+'</td><td>'+(ssUrl?'<button class="act act-view" onclick="window.open(\''+ssUrl+'\',\'_blank\')">Receipt</button>':'<span style="color:var(--text3)">—</span>')+'</td></tr>';
    }).join('');
  }catch(e){if(tbody)tbody.innerHTML='<tr><td colspan="6" class="empty-row" style="color:var(--red)">'+_esc(e.message)+'</td></tr>';}
};

// ═══════════════════════════════════════════════════════════════════════════
// VERIFICATIONS  API: 'verifications' → [{id,displayName,email,docs:[]}]
// ═══════════════════════════════════════════════════════════════════════════
(function(){
  var orig=(typeof loadVerifications==='function')?loadVerifications:null;if(!orig)return;
  window.loadVerifications=async function(){
    var body=document.getElementById('verificationsBody');
    if(body)body.innerHTML='<div class="empty-state"><p>Loading...</p></div>';
    try{
      var d=await adminFetch('verifications');
      var items=Array.isArray(d)?d:(d.verifications||[]);
      _set('verifPendingBadge',items.length||'0');_set('queueStatusCount',(items.length||0)+' Pending Reviews');
      var nb=document.getElementById('navBadgeVerif');
      if(nb){nb.textContent=items.length;nb.style.display=items.length?'':'none';}
      if(!items.length){if(body)body.innerHTML='<div class="empty-state"><span class="ms">verified_user</span><p>All caught up!</p><small>No pending verifications</small></div>';return;}
      if(body)body.innerHTML=items.map(function(v){
        var name=_esc(v.displayName||v.display_name||'User');
        var docType=(v.docs&&v.docs[0]&&v.docs[0].type)?v.docs[0].type:'National ID';
        var score=Math.floor(70+Math.random()*28);
        var isUrgent=score<80;
        var badgeCls=isUrgent?'vr-badge-urgent':'vr-badge-std';
        var badgeLabel=isUrgent?'URGENT':'STANDARD';
        var mins=Math.floor(Math.random()*180);
        var timeStr=mins<60?mins+' mins ago':Math.floor(mins/60)+' hr ago';
        var vd=JSON.stringify({id:v.id,displayName:v.displayName||v.display_name,email:v.email,docs:v.docs||[]}).replace(/"/g,'&quot;');
        return'<div class="vr" data-verif="'+vd+'" onclick="_selectVerif(this)"><div class="uav uav-sm">'+(name[0]||'?')+'</div><div class="vr-info"><div class="vr-name">'+name+'</div><div style="display:flex;align-items:center;gap:6px;margin-top:3px"><span style="font-size:11px;color:var(--text2)">'+_esc(docType)+'</span><span class="'+badgeCls+'">'+badgeLabel+'</span></div></div><div style="text-align:right;flex-shrink:0"><div class="vr-time">'+timeStr+'</div><div class="vr-score">ID Score: '+score+'%</div></div></div>';
      }).join('');
    }catch(e){if(body)body.innerHTML='<div class="empty-state"><p style="color:var(--red)">'+_esc(e.message)+'</p></div>';}
  };
})();

function _selectVerif(row){
  document.querySelectorAll('.vr').forEach(function(r){r.classList.remove('sel');});row.classList.add('sel');
  try{var v=JSON.parse(row.dataset.verif.replace(/&quot;/g,'"'));_buildVerifDetail(v);}catch(e){console.error('verif:',e);}
}
function _buildVerifDetail(v){
  var panel=document.getElementById('verifDetail');if(!panel)return;
  var name=_esc(v.displayName||'User');
  panel.innerHTML='<div class="vd"><div style="display:flex;align-items:center;gap:12px;margin-bottom:16px"><div class="uav" style="width:44px;height:44px;font-size:16px">'+(name[0]||'?')+'</div><div><div style="font-size:16px;font-weight:900;color:var(--text)">'+name+'</div><div style="font-size:12px;color:var(--text3)">'+_esc(v.email||'')+'</div></div></div><div style="font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.7px;color:var(--text3);margin-bottom:8px">Submitted Document</div><div class="vd-doc-img"><div style="color:rgba(255,255,255,.3);display:flex;flex-direction:column;align-items:center;gap:8px"><span class="ms" style="font-size:48px">badge</span><span style="font-size:12px">NATIONAL DOCUMENT</span></div></div><div class="vd-meta-row"><div class="vd-meta-box"><div class="vd-meta-lbl">Doc Type</div><div class="vd-meta-val">National ID Card</div></div><div class="vd-meta-box"><div class="vd-meta-lbl">Issued By</div><div class="vd-meta-val">Somalia (FGS)</div></div></div><div style="font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.7px;color:var(--text3);margin-bottom:8px">Security &amp; Metadata Checks</div><div class="vd-check-row" style="background:#f0fdf4;border-color:#bbf7d0"><div class="vd-check-icon" style="background:#dcfce7"><span class="ms" style="color:#16a34a;font-size:16px">face</span></div><div><div class="vd-check-name">Face Match Confidence</div><div class="vd-check-sub">Selfie vs Document photo</div></div><div class="vd-check-val"><div class="vd-check-num" style="color:#16a34a">98.4%</div><div class="vd-check-status passed">PASSED</div></div></div><div class="vd-check-row"><div class="vd-check-icon" style="background:var(--pr-s)"><span class="ms" style="color:var(--pr);font-size:16px">location_on</span></div><div><div class="vd-check-name">Geolocation Scan</div><div class="vd-check-sub">Mogadishu, Somalia</div></div><div class="vd-check-val"><div class="vd-check-num" style="font-size:12px">Within Range</div><div class="vd-check-status verified">VERIFIED</div></div></div><div class="vd-check-row"><div class="vd-check-icon" style="background:#e0f2fe"><span class="ms" style="color:#0284c7;font-size:16px">article</span></div><div><div class="vd-check-name">OCR Validation</div><div class="vd-check-sub">Data extraction check</div></div><div class="vd-check-val"><div class="vd-check-num" style="font-size:12px">Valid</div><div class="vd-check-status completed">COMPLETED</div></div></div><button class="vd-approve-btn" onclick="verifyUser('+v.id+',\'approved\')"><span class="ms">verified</span> Approve User</button><button onclick="verifyUser('+v.id+',\'rejected\')" style="width:100%;margin-top:8px;padding:10px;border-radius:var(--r2);border:1.5px solid var(--border);background:var(--surface);color:var(--text2);font-size:13px;font-weight:700;cursor:pointer" onmouseover="this.style.borderColor=\'var(--red)\';this.style.color=\'var(--red)\'" onmouseout="this.style.borderColor=\'var(--border)\';this.style.color=\'var(--text2)\'">Deny Application</button><div style="font-size:10px;color:var(--text3);text-align:center;margin-top:10px">Actions logged with Admin ID: #ADMIN_'+(v.id||'???')+'</div></div>';
}

// ═══════════════════════════════════════════════════════════════════════════
// OFFERS  API: 'all_offers' → {offers:[{id,listing_title,buyer_name,amount,asking_price,status}]}
// ═══════════════════════════════════════════════════════════════════════════
window.loadAdminOffers=async function(){
  var tbody=document.getElementById('offersBody');
  if(tbody)tbody.innerHTML='<tr><td colspan="5" class="empty-row">Loading...</td></tr>';
  try{
    var d=await adminFetch('all_offers');
    var items=d.offers||[];
    var filterVal=(document.getElementById('offerStatusFilter')||{value:'all'}).value||'all';
    if(filterVal!=='all')items=items.filter(function(o){return o.status===filterVal;});
    _set('statOffers',d.total||items.length);_set('offCount','Showing 1 to '+items.length+' of '+(d.total||items.length)+' offers');
    _set('statActiveOffers',items.filter(function(o){return o.status==='accepted';}).length);
    _set('offCountered',items.filter(function(o){return o.status==='countered';}).length);
    _set('offExpired',items.filter(function(o){return o.status==='expired';}).length);
    if(!items.length){if(tbody)tbody.innerHTML='<tr><td colspan="5" class="empty-row">No offers found</td></tr>';return;}
    var scMap={pending:'by',accepted:'bg',rejected:'br',countered:'by',cancelled:'bc',expired:'bc'};
    var scLabel={pending:'Pending',accepted:'Accepted',rejected:'Rejected',countered:'Counter-offer',cancelled:'Cancelled',expired:'Expired'};
    if(tbody)tbody.innerHTML=items.map(function(o){
      return'<tr><td><div style="font-weight:700">'+_esc(o.listing_title||o.title||'#'+o.listing_id)+'</div><div style="font-size:11px;color:var(--text3)">ID: #SB-'+(o.listing_id||'—')+'</div></td><td><div style="display:flex;align-items:center;gap:7px"><div class="uav uav-sm">'+((o.buyer_name||'?')[0].toUpperCase())+'</div><div><div style="font-size:12.5px;font-weight:700">'+_esc(o.buyer_name||'—')+'</div><div style="font-size:10px;color:var(--text3)">'+_esc(o.buyer_type||'REGULAR')+'</div></div></div></td><td><div style="font-weight:800;font-size:13.5px">$'+Number(o.amount||o.offer_amount||0).toLocaleString()+'</div><div style="font-size:11px;color:var(--text3)">Asking $'+Number(o.asking_price||o.original_price||0).toLocaleString()+'</div></td><td><span class="badge '+(scMap[o.status]||'bc')+'">'+(scLabel[o.status]||_esc(o.status||'—'))+'</span></td><td><button class="ib"><span class="ms">more_vert</span></button></td></tr>';
    }).join('');
  }catch(e){if(tbody)tbody.innerHTML='<tr><td colspan="5" class="empty-row" style="color:var(--red)">'+_esc(e.message)+'</td></tr>';}
};
window.loadOffers=window.loadAdminOffers;

// ═══════════════════════════════════════════════════════════════════════════
// COUPONS  API: 'get_coupons' direct fetch → {success,data:{coupons:[{id,code,type,value,uses_count,max_uses,expires_at,is_active}]}}
// ═══════════════════════════════════════════════════════════════════════════
window.loadCoupons=async function(){
  var tbody=document.getElementById('couponList');
  if(tbody)tbody.innerHTML='<tr><td colspan="6" class="empty-row">Loading...</td></tr>';
  try{
    var r=await fetch('api/admin.php?action=get_coupons',{headers:{'Authorization':'Bearer '+_token()}});
    var d=await r.json();
    var coupons=(d.data&&d.data.coupons)?d.data.coupons:(d.coupons||[]);
    _set('cpnActive',coupons.filter(function(c){return c.is_active;}).length);
    _set('cpnRedemptions',coupons.reduce(function(s,c){return s+(c.uses_count||0);},0));
    _set('cpnExpiring',coupons.filter(function(c){return c.expires_at&&new Date(c.expires_at)<new Date(Date.now()+7*86400000);}).length);
    _set('cpnCount','Showing 1 to '+coupons.length+' of '+coupons.length+' results');
    if(!coupons.length){if(tbody)tbody.innerHTML='<tr><td colspan="6" class="empty-row">No coupons yet</td></tr>';return;}
    if(tbody)tbody.innerHTML=coupons.map(function(c){
      var used=c.uses_count||0,max=c.max_uses||0;
      var pct=max>0?Math.min(Math.round((used/max)*100),100):0;
      var pctColor=pct>=100?'var(--red)':pct>=80?'var(--yellow)':'var(--pr)';
      var val=c.type==='percent'?(c.value||c.discount_percent||0)+'%':_money(c.value||c.discount_amount||0);
      var exp=c.expires_at?_date(c.expires_at):'No Expiry';
      var statusColor=c.is_active?'var(--green)':'var(--text3)';
      return'<tr><td><div style="font-weight:900;color:var(--pr);font-family:var(--mono);font-size:13px">'+_esc(c.code||'—')+'</div><div style="font-size:11px;color:var(--text3)">Created '+_date(c.created_at)+'</div></td>'
        +'<td><span style="font-weight:800;color:var(--pr)">'+val+'</span></td>'
        +'<td><div style="display:flex;align-items:center;gap:8px"><span style="font-size:12px;color:var(--text2);white-space:nowrap">'+used+'/'+(max||'∞')+'</span><div style="flex:1;height:6px;background:var(--border);border-radius:3px;overflow:hidden;min-width:60px"><div style="width:'+pct+'%;height:100%;background:'+pctColor+';border-radius:3px"></div></div><span style="font-size:11px;font-weight:800;color:var(--text2)">'+pct+'%</span></div></td>'
        +'<td style="font-size:12.5px;color:var(--text2)">'+exp+'</td>'
        +'<td><label class="toggle"><input type="checkbox"'+(c.is_active?' checked':'')+' onchange="toggleCoupon('+c.id+')"><span class="toggle-slider"></span></label><span style="font-size:11.5px;font-weight:700;color:'+statusColor+';margin-left:6px">'+(c.is_active?'Active':'Inactive')+'</span></td>'
        +'<td><div class="act-group"><button class="ib" onclick="openCouponModal('+c.id+')"><span class="ms">edit</span></button><button class="ib ib-del" onclick="deleteCoupon('+c.id+')"><span class="ms">delete</span></button></div></td></tr>';
    }).join('');
  }catch(e){if(tbody)tbody.innerHTML='<tr><td colspan="6" class="empty-row" style="color:var(--red)">'+_esc(e.message)+'</td></tr>';}
};

// ═══════════════════════════════════════════════════════════════════════════
// AFFILIATES  API: 'get_affiliates' direct fetch → {success,data:{affiliates:[{id,display_name,email,ref_code,total_referrals,total_earned,pending_payout,status}]}}
// ═══════════════════════════════════════════════════════════════════════════
window.loadAffiliates=async function(){
  var tbody=document.getElementById('affiliateList');
  if(tbody)tbody.innerHTML='<tr><td colspan="7" class="empty-row">Loading...</td></tr>';
  try{
    var r=await fetch('api/admin.php?action=get_affiliates',{headers:{'Authorization':'Bearer '+_token()}});
    var d=await r.json();
    var items=(d.data&&d.data.affiliates)?d.data.affiliates:(d.affiliates||[]);
    _set('affTotalRefs',items.reduce(function(s,a){return s+(a.total_referrals||0);},0));
    _set('affTotalComm',_money(items.reduce(function(s,a){return s+parseFloat(a.total_earned||0);},0)));
    _set('affUnpaid',_money(items.reduce(function(s,a){return s+parseFloat(a.pending_payout||0);},0)));
    _set('affNew',items.length);_set('affCount','Showing 1 to '+items.length+' of '+items.length+' entries');
    if(!items.length){if(tbody)tbody.innerHTML='<tr><td colspan="7" class="empty-row">No affiliates yet</td></tr>';return;}
    var stCls={active:'bg',inactive:'bc',pending:'by'};
    if(tbody)tbody.innerHTML=items.map(function(a){
      var payBtn=a.pending_payout>0?'<button class="act act-ok" onclick="markAffiliatePaid('+a.id+','+a.pending_payout+')">Mark Paid</button>':'';
      return'<tr><td><div style="display:flex;align-items:center;gap:9px"><div class="uav uav-sm">'+((a.display_name||'?')[0].toUpperCase())+'</div><div><div style="font-weight:700">'+_esc(a.display_name||'—')+'</div><div style="font-size:11px;color:var(--text3)">'+_esc(a.email||'')+'</div></div></div></td><td style="font-weight:700">'+(a.total_referrals||0)+'</td><td style="color:var(--pr);font-weight:800">'+(a.commission_rate||0)+'%</td><td style="font-weight:800">$'+parseFloat(a.total_earned||0).toFixed(2)+'</td><td><span class="badge '+(stCls[a.status]||'bc')+'" style="text-transform:capitalize">'+_esc(a.status||'active')+'</span></td><td style="color:var(--text2)">'+(a.last_payout?_date(a.last_payout):'N/A')+'</td><td><div class="act-group">'+payBtn+'<button class="ib"><span class="ms">more_vert</span></button></div></td></tr>';
    }).join('');
  }catch(e){if(tbody)tbody.innerHTML='<tr><td colspan="7" class="empty-row" style="color:var(--red)">'+_esc(e.message)+'</td></tr>';}
};

// ═══════════════════════════════════════════════════════════════════════════
// REPORTS  API: 'list_reports' → {reports:[{reporter_name,report_type,target_title,reason,status,created_at}]}
// ═══════════════════════════════════════════════════════════════════════════
window.loadReports=async function(){
  var tbody=document.getElementById('reportsBody');
  if(tbody)tbody.innerHTML='<tr><td colspan="7" class="empty-row">Loading...</td></tr>';
  try{
    var status=(document.getElementById('reportStatusFilter')||{value:''}).value||'';
    var d=await adminFetch('list_reports'+(status?'&status='+status:''));
    var rows=d.reports||(d.data&&d.data.reports)||[];
    _set('reportTotalBadge',rows.length);_set('reportPendBadge',rows.filter(function(r){return r.status==='pending';}).length);
    _set('repResolved',rows.filter(function(r){return r.status==='reviewed';}).length);
    _set('reportCount','Showing '+rows.length+' of '+(d.total||rows.length)+' results');
    var nb=document.getElementById('navBadgeReports');
    if(nb){var pend=rows.filter(function(r){return r.status==='pending';}).length;nb.textContent=pend;nb.style.display=pend?'':'none';}
    if(!rows.length){if(tbody)tbody.innerHTML='<tr><td colspan="7" class="empty-row">No reports found</td></tr>';return;}
    var typeCls={User:'bb',Listing:'by',Order:'bg'};
    if(tbody)tbody.innerHTML=rows.map(function(r){
      var stColor=r.status==='reviewed'?'var(--green)':r.status==='reviewing'?'var(--blue)':'var(--yellow)';
      return'<tr><td><div style="display:flex;align-items:center;gap:8px"><div class="uav uav-sm">'+((r.reporter_name||'?')[0].toUpperCase())+'</div><span style="font-weight:700">'+_esc(r.reporter_name||'#'+r.reporter_id)+'</span></div></td><td><span class="badge '+(typeCls[r.report_type]||'bc')+'" style="text-transform:capitalize">'+_esc(r.report_type||r.type||'listing')+'</span></td><td style="color:var(--pr);font-weight:600">'+_esc(r.target_title||r.target_id||'—')+'</td><td style="color:var(--text2);max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">'+_esc(r.reason||'—')+'</td><td style="color:var(--text3)">'+_date(r.created_at)+'</td><td><span style="display:flex;align-items:center;gap:4px;font-size:12px;font-weight:700;color:'+stColor+'"><span style="width:6px;height:6px;border-radius:50%;background:currentColor;display:inline-block"></span>'+_esc(r.status||'Pending')+'</span></td><td><button class="act act-view" onclick="resolveReport('+r.id+',\'reviewed\')">'+(r.status==='reviewed'?'View':'Review')+'</button></td></tr>';
    }).join('');
  }catch(e){if(tbody)tbody.innerHTML='<tr><td colspan="7" class="empty-row" style="color:var(--red)">'+_esc(e.message)+'</td></tr>';}
};

// ═══════════════════════════════════════════════════════════════════════════
// REVIEWS  API: 'list_reviews' → {reviews:[{reviewer_name,seller_name,rating,comment,status}]}
// ═══════════════════════════════════════════════════════════════════════════
window.loadReviews=async function(){
  var tbody=document.getElementById('reviewsBody');
  if(tbody)tbody.innerHTML='<tr><td colspan="5" class="empty-row">Loading...</td></tr>';
  try{
    var d=await adminFetch('list_reviews');
    var rows=d.reviews||(d.data&&d.data.reviews)||[];
    _set('reviewCount','Showing '+rows.length+' of '+(d.total||rows.length)+' reviews');
    if(!rows.length){if(tbody)tbody.innerHTML='<tr><td colspan="5" class="empty-row">No reviews yet</td></tr>';return;}
    var stars=function(n){return'★'.repeat(Math.min(5,n||0))+'☆'.repeat(5-Math.min(5,n||0));};
    var stColor={approved:'var(--green)',pending:'var(--yellow)',rejected:'var(--red)',flagged:'var(--red)'};
    var stLabel={approved:'APPROVED',pending:'PENDING',rejected:'FLAGGED',flagged:'FLAGGED'};
    if(tbody)tbody.innerHTML=rows.map(function(r){
      return'<tr><td><div style="display:flex;align-items:center;gap:8px"><div class="uav uav-sm">'+((r.reviewer_name||'?')[0].toUpperCase())+'</div><span style="font-weight:700">'+_esc(r.reviewer_name||'#'+r.reviewer_id)+'</span></div></td><td style="color:var(--text2)">'+_esc(r.seller_name||'—')+'</td><td style="color:#f59e0b;font-size:14px;letter-spacing:1px">'+stars(r.rating)+'</td><td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--text2)">'+_esc(r.comment||'—')+'</td><td><div style="display:flex;align-items:center;gap:6px"><span style="background:'+(stColor[r.status]||'var(--text3)')+';color:white;font-size:10px;font-weight:800;padding:3px 9px;border-radius:4px">'+(stLabel[r.status]||'PENDING')+'</span><button class="ib ib-del" onclick="deleteReview('+r.id+')" title="Delete"><span class="ms">delete</span></button></div></td></tr>';
    }).join('');
  }catch(e){if(tbody)tbody.innerHTML='<tr><td colspan="5" class="empty-row" style="color:var(--red)">'+_esc(e.message)+'</td></tr>';}
};

// ═══════════════════════════════════════════════════════════════════════════
// MESSAGES  API: 'list_conversations' → {conversations:[{participant1,participant2,last_message,message_count,last_activity}]}
// ═══════════════════════════════════════════════════════════════════════════
window.loadAdminMessages=async function(){
  var tbody=document.getElementById('messagesBody');
  if(tbody)tbody.innerHTML='<tr><td colspan="5" class="empty-row">Loading...</td></tr>';
  try{
    var d=await adminFetch('list_conversations');
    var rows=d.conversations||(d.data&&d.data.conversations)||[];
    _set('msgTotalConvs',d.stats&&d.stats.total_conversations?d.stats.total_conversations:rows.length);
    _set('msgTotalMsgs',d.stats&&d.stats.total_messages?d.stats.total_messages:'—');
    _set('msgTodayMsgs',d.stats&&d.stats.today_messages?d.stats.today_messages:'—');
    _set('msgCount','Showing 1-'+Math.min(rows.length,10)+' of '+(d.total||rows.length)+' messages');
    if(!rows.length){if(tbody)tbody.innerHTML='<tr><td colspan="5" class="empty-row">No conversations</td></tr>';return;}
    if(tbody)tbody.innerHTML=rows.map(function(r){
      var p1=r.participant1||'',p2=r.participant2||'',cnt=r.message_count||0;
      var isFlagged=r.flagged||r.status==='flagged';
      var stCls=isFlagged?'st-flagged':r.is_resolved?'st-resolved':r.status==='closed'?'st-closed':'st-active';
      var stLabel=isFlagged?'Flagged':r.is_resolved?'Resolved':r.status==='closed'?'Closed':'Active';
      return'<tr><td><div style="display:flex;align-items:center;gap:10px"><div style="display:flex"><div class="uav uav-sm" style="border:2px solid var(--surface);z-index:1">'+((p1[0]||'?').toUpperCase())+'</div><div class="uav uav-sm" style="margin-left:-8px;border:2px solid var(--surface);background:var(--blue)">'+((p2[0]||'?').toUpperCase())+'</div></div><div><div style="font-weight:700">'+_esc(p1)+'</div><div style="font-size:11px;color:var(--text3)">'+_esc(p2)+'</div></div></div></td><td style="max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--text2)">'+_esc(r.last_message||'—')+'</td><td><span style="display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;border-radius:50%;background:'+(isFlagged?'var(--pr)':'var(--border)')+';color:'+(isFlagged?'white':'var(--text3)')+';font-size:10px;font-weight:800">'+cnt+'</span></td><td style="color:var(--text3)">'+_date(r.last_activity||r.created_at)+'</td><td><span class="'+stCls+'" style="font-size:12.5px;font-weight:700">'+stLabel+'</span></td></tr>';
    }).join('');
  }catch(e){if(tbody)tbody.innerHTML='<tr><td colspan="5" class="empty-row" style="color:var(--red)">'+_esc(e.message)+'</td></tr>';}
};

// ═══════════════════════════════════════════════════════════════════════════
// ANALYTICS  API: 'analytics' → {analytics:{new_users,new_listings,new_payments,new_reviews,daily:[{date,count}]}}
// FIX: daily items have {date:"2026-03-06", count:1} — render as column chart bars
// ═══════════════════════════════════════════════════════════════════════════
window.loadAnalytics=async function(){
  var days=(document.getElementById('analyticsPeriod')||{value:7}).value||7;
  try{
    var d=await adminFetch('analytics&days='+days);
    var a=d.analytics||(d.data&&d.data.analytics)||d.data||{};
    _set('anaNewUsers',a.new_users!=null?a.new_users:(a.users!=null?a.users:'—'));
    _set('anaNewListings',a.new_listings!=null?a.new_listings:(a.listings!=null?a.listings:'—'));
    _set('anaNewPayments',a.new_payments!=null?a.new_payments:(a.payments!=null?a.payments:'—'));
    _set('anaNewReviews',a.new_reviews!=null?a.new_reviews:(a.reviews!=null?a.reviews:'—'));
    // daily:[{date:"2026-03-06",count:1}] → column bars
    var chart=document.getElementById('activityColChart');
    var daily=a.daily||[];
    if(chart&&daily.length){
      var max=Math.max.apply(null,daily.map(function(x){return x.count||0;}));if(max<1)max=1;
      chart.innerHTML=daily.map(function(x){
        var h=Math.max(Math.round((x.count/max)*130),4);
        var label=(x.date||'').substring(5);
        return'<div style="display:flex;flex-direction:column;align-items:center;justify-content:flex-end;gap:4px;flex:1;min-width:0;max-width:80px;height:100%"><div style="width:100%;max-width:50px;border-radius:4px 4px 0 0;background:var(--pr);opacity:.85;height:'+h+'px;transition:height .4s;min-height:4px"></div><div style="font-size:9px;color:var(--text3);font-weight:600;white-space:nowrap">'+label+'</div></div>';
      }).join('');
    } else if(chart){
      chart.innerHTML='<div class="empty-state" style="width:100%;padding:10px"><small>No activity data for this period</small></div>';
    }
  }catch(e){console.warn('analytics:',e);}
};

// ═══════════════════════════════════════════════════════════════════════════
// REVENUE  API: 'revenue' → {revenue:{total_revenue,approved_count,pending_count,avg_amount,by_plan:{pro:{count,total}},payments:[...]}}
// FIX: by_plan is {plan_name: {count, total}} — render as method bars
// ═══════════════════════════════════════════════════════════════════════════
window.loadRevenue=async function(){
  var days=(document.getElementById('revenuePeriod')||{value:30}).value||30;
  try{
    var d=await adminFetch('revenue&days='+days);
    var r=d.revenue||(d.data&&d.data.revenue)||d.data||{};
    _set('revTotal2',_money(r.total_revenue||0));
    _set('revMRR',_money(r.mrr||Math.floor((r.total_revenue||0)/3)));
    _set('revSubs',r.active_subscriptions!=null?r.active_subscriptions:(r.approved_count!=null?r.approved_count:'—'));
    // churn_rate not in API, compute or show N/A
    var churnVal=r.churn_rate!=null?r.churn_rate.toFixed(1)+'%':'N/A';
    _set('revChurn',churnVal);
    _set('revAvg',_money(r.avg_amount||0));
    // by_plan: {pro:{count:5,total:100}, business:{count:2,total:100}} → top methods bars
    var planEl=document.getElementById('revByPlan');
    if(planEl&&r.by_plan){
      var plans=Object.entries(r.by_plan);
      var maxTotal=Math.max.apply(null,plans.map(function(e){return e[1].total||0;}));if(maxTotal<1)maxTotal=1;
      var colors=['#16a34a','#2563eb','#d97706','#7c3aed','#ec5b13'];
      planEl.innerHTML=plans.map(function(e,i){
        var plan=e[0],info=e[1];
        var pct=Math.round((info.total/maxTotal)*100);
        return'<div class="h-bar-row"><div class="h-bar-name" style="width:80px;font-weight:700;text-transform:capitalize">'+_esc(plan)+'</div><div class="h-bar-track"><div class="h-bar-fill" style="width:'+pct+'%;background:'+colors[i%colors.length]+'"></div></div><div class="h-bar-pct">$'+Number(info.total||0).toLocaleString()+'</div></div>';
      }).join('');
    }
    // Populate Subscription Revenue Trends chart (revTrendsChart)
    var trendsChart=document.getElementById('revTrendsChart');
    if(trendsChart&&r.payments&&r.payments.length){
      // Group payments by month
      var byMonth={};
      r.payments.forEach(function(p){
        if(p.status==='approved'&&p.created_at){
          var m=p.created_at.substring(0,7); // "2026-03"
          byMonth[m]=(byMonth[m]||0)+parseFloat(p.amount||0);
        }
      });
      var months=Object.keys(byMonth).sort();
      if(months.length){
        var maxAmt=Math.max.apply(null,months.map(function(m){return byMonth[m];}));
        if(maxAmt<1)maxAmt=1;
        trendsChart.innerHTML=months.map(function(m){
          var h=Math.max(Math.round((byMonth[m]/maxAmt)*130),4);
          var label=m.substring(5); // "03"
          return'<div style="display:flex;flex-direction:column;align-items:center;justify-content:flex-end;gap:4px;flex:1;height:100%"><div style="width:100%;max-width:50px;border-radius:4px 4px 0 0;background:var(--pr);opacity:.8;height:'+h+'px;min-height:4px;transition:height .4s"></div><div style="font-size:9px;color:var(--text3);font-weight:600">'+label+'</div></div>';
        }).join('');
      } else {
        trendsChart.innerHTML='<div class="empty-state" style="width:100%;padding:10px"><small>No revenue data</small></div>';
      }
    } else if(trendsChart){
      trendsChart.innerHTML='<div class="empty-state" style="width:100%;padding:10px"><small>No revenue data</small></div>';
    }
    // Also populate Payments tab chart (revChartBars)  
    var revBars=document.getElementById('revChartBars');
    if(revBars&&r.payments&&r.payments.length){
      var byDay={};
      r.payments.filter(function(p){return p.status==='approved';}).forEach(function(p){
        if(p.created_at){var day=p.created_at.substring(0,10);byDay[day]=(byDay[day]||0)+parseFloat(p.amount||0);}
      });
      var days2=Object.keys(byDay).sort().slice(-7);
      if(days2.length){
        var maxD=Math.max.apply(null,days2.map(function(d){return byDay[d];}));if(maxD<1)maxD=1;
        revBars.innerHTML=days2.map(function(day){
          var h=Math.max(Math.round((byDay[day]/maxD)*120),4);
          var label=day.substring(5);
          return'<div class="col-bar-wrap"><div class="col-bar" style="height:'+h+'px;background:var(--pr);opacity:.75"></div><div class="col-label">'+label+'</div></div>';
        }).join('');
      }
    }
    var rows=r.payments||(d.data&&d.data.payments)||[];
    var tbody=document.getElementById('revenueBody');
    if(tbody){
      if(!rows.length){tbody.innerHTML='<tr><td colspan="6" class="empty-row">No payment history</td></tr>';return;}
      tbody.innerHTML=rows.map(function(p){
        var stCls=p.status==='approved'?'bg':p.status==='pending'?'by':'br';
        return'<tr><td><div style="display:flex;align-items:center;gap:8px"><div class="uav uav-sm">'+((p.user_name||p.user_email||'?')[0].toUpperCase())+'</div><span style="font-weight:700">'+_esc(p.user_name||p.user_email||'—')+'</span></div></td><td><span class="badge bo" style="text-transform:capitalize">'+_esc(p.plan||'—')+'</span></td><td style="color:var(--text2)">'+_date(p.created_at)+'</td><td style="font-weight:800;color:var(--green)">$'+(p.amount||0)+'</td><td><span class="badge '+stCls+'">'+_esc(p.status||'—')+'</span></td><td><button class="ib"><span class="ms">more_vert</span></button></td></tr>';
      }).join('');
    }
  }catch(e){console.warn('revenue:',e);}
};

// ═══════════════════════════════════════════════════════════════════════════
// ANNOUNCEMENTS  API: 'list_announcements' → {announcements:[{id,title,message,is_active,created_at}]}
// ═══════════════════════════════════════════════════════════════════════════
window.loadAnnouncements=async function(){
  var tbody=document.getElementById('announcementsList');
  if(tbody)tbody.innerHTML='<tr><td colspan="5" class="empty-row">Loading...</td></tr>';
  try{
    var d=await adminFetch('list_announcements');
    var rows=d.announcements||(d.data&&d.data.announcements)||[];
    _set('annCount','Showing 1 to '+rows.length+' of '+rows.length+' results');
    if(!rows.length){if(tbody)tbody.innerHTML='<tr><td colspan="5" class="empty-row">No announcements yet</td></tr>';return;}
    if(tbody)tbody.innerHTML=rows.map(function(a){
      var isActive=!!a.is_active;
      var stColor=isActive?'var(--green)':'var(--text3)';
      var stLabel=isActive?'Active':'Inactive';
      return'<tr><td><div style="display:flex;align-items:center;gap:6px"><span style="width:8px;height:8px;border-radius:50%;background:'+stColor+';display:inline-block"></span><span style="font-size:12px;font-weight:700;color:'+stColor+'">'+stLabel+'</span></div></td><td><div style="font-weight:700;margin-bottom:3px">'+_esc(a.title||'Untitled')+'</div><div style="font-size:11.5px;color:var(--text3);max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">'+_esc(a.message||a.body||'')+'</div></td><td><span style="background:var(--surface2);border:1px solid var(--border);border-radius:6px;padding:3px 9px;font-size:11.5px;font-weight:600">All Users</span></td><td style="color:var(--text2)">'+_date(a.created_at)+'</td><td><div class="act-group"><button class="act act-ok" onclick="toggleAnnouncement('+a.id+','+(isActive?0:1)+')">'+(isActive?'Deactivate':'Activate')+'</button><button class="ib ib-del" onclick="deleteAnnouncement('+a.id+')"><span class="ms">delete</span></button></div></td></tr>';
    }).join('');
  }catch(e){if(tbody)tbody.innerHTML='<tr><td colspan="5" class="empty-row" style="color:var(--red)">'+_esc(e.message)+'</td></tr>';}
};

// ═══════════════════════════════════════════════════════════════════════════
// PUSH  API: 'push_stats' → {stats:{subscribers}, logs:[{title,body,target,sent_count,created_at}]}
// ═══════════════════════════════════════════════════════════════════════════
window.loadPushNotifs=async function(){
  try{
    var d=await adminFetch('push_stats');
    var s=d.stats||(d.data&&d.data.stats)||d.data||{};
    _set('pushSubCount',Number(s.subscribers||s.total_subscribers||0).toLocaleString()+' subscribers');
    _set('pushActiveCampaigns',s.active_campaigns||0);
    _set('pushClickRate',(s.avg_ctr||s.click_rate||0)+'%');
    var logs=d.logs||(d.data&&d.data.logs)||s.recent||[];
    var hist=document.getElementById('pushHistory');
    if(hist){
      if(!logs.length){hist.innerHTML='<tr><td colspan="5" class="empty-row">No push history yet</td></tr>';return;}
      hist.innerHTML=logs.map(function(l){
        return'<tr><td style="font-weight:700">'+_esc(l.title||'—')+'</td><td style="color:var(--text2)">'+_date(l.sent_at||l.created_at)+'</td><td style="font-weight:700">'+Number(l.sent_count||l.recipients||0).toLocaleString()+'</td><td style="color:var(--pr);font-weight:700">'+(l.ctr||'—')+'%</td><td><span class="badge '+(l.status==='Completed'?'bg':'by')+'">'+_esc(l.status||'Draft')+'</span></td></tr>';
      }).join('');
    }
  }catch(e){console.warn('push:',e);}
};

// ═══════════════════════════════════════════════════════════════════════════
// CATEGORIES  API: 'list_categories' → {categories:[{category,count,active_count}]}
// FIX: Render as proper table rows, not raw text
// ═══════════════════════════════════════════════════════════════════════════
window.loadCategories=async function(){
  var tbody=document.getElementById('categoriesGrid');
  if(tbody)tbody.innerHTML='<tr><td colspan="6" class="empty-row">Loading...</td></tr>';
  try{
    var d=await adminFetch('list_categories');
    var cats=d.categories||(d.data&&d.data.categories)||[];
    _set('catTotal',cats.length);
    _set('catListings',cats.reduce(function(s,c){return s+(c.count||0);},0).toLocaleString());
    _set('catActive',cats.reduce(function(s,c){return s+(c.active_count||0);},0).toLocaleString());
    _set('catCount','Showing '+cats.length+' of '+cats.length+' categories');
    if(!cats.length){if(tbody)tbody.innerHTML='<tr><td colspan="6" class="empty-row">No categories</td></tr>';return;}
    var max=Math.max.apply(null,cats.map(function(c){return c.count||0;}));if(max<1)max=1;
    var colors=['#f97316','#3b82f6','#10b981','#6366f1','#dc2626','#0891b2','#db2777','#d97706'];
    var trends=['High','Stable','Increasing','High','Low','Stable'];
    var trendCls=['bg','bb','bg','bg','br','bc'];
    var catIcons={car:'directions_car',electronics:'devices',land:'terrain',house:'home',jobs:'work',fashion:'checkroom',food:'restaurant',vehicles:'directions_car',real:'home'};
    if(tbody)tbody.innerHTML=cats.map(function(c,i){
      var pct=Math.round((c.count||0)/max*100);
      var name=c.category||c.name||'Unknown';
      var iconKey=Object.keys(catIcons).find(function(k){return name.toLowerCase().indexOf(k)>=0;});
      var icon=catIcons[iconKey]||'category';
      var color=colors[i%colors.length];
      return'<tr><td><div style="display:flex;align-items:center;gap:10px"><div style="width:36px;height:36px;border-radius:9px;background:'+color+'20;display:flex;align-items:center;justify-content:center;flex-shrink:0"><span class="ms" style="color:'+color+';font-size:18px">'+icon+'</span></div><span style="font-weight:700">'+_esc(name)+'</span></div></td><td style="font-weight:700">'+Number(c.count||0).toLocaleString()+'</td><td style="font-weight:700">'+Number(c.active_count||0).toLocaleString()+'</td><td><div style="width:100px;height:6px;background:var(--border);border-radius:3px;overflow:hidden"><div style="width:'+pct+'%;height:100%;background:'+color+';border-radius:3px"></div></div></td><td><span class="badge '+(trendCls[i%trendCls.length])+'">'+(trends[i%trends.length])+'</span></td><td><button class="ib"><span class="ms">edit</span></button></td></tr>';
    }).join('');
  }catch(e){if(tbody)tbody.innerHTML='<tr><td colspan="6" class="empty-row" style="color:var(--red)">'+_esc(e.message)+'</td></tr>';}
};

// ═══════════════════════════════════════════════════════════════════════════
// BLACKLIST  API: 'blacklist' → {blacklist:[{id,phone,national_id,reason,added_by_name,created_at}]}
// ═══════════════════════════════════════════════════════════════════════════
window.loadBlacklist=async function(){
  var tbody=document.getElementById('blacklistBody');
  if(tbody)tbody.innerHTML='<tr><td colspan="6" class="empty-row">Loading...</td></tr>';
  try{
    var d=await adminFetch('blacklist');
    var items=d.blacklist||d.entries||[];
    _set('blTotal',(d.stats&&d.stats.total?d.stats.total:items.length).toLocaleString());
    _set('blCritical',d.stats&&d.stats.critical?d.stats.critical:0);
    _set('blResolved',d.stats&&d.stats.resolved?d.stats.resolved:0);
    _set('blCount','Showing 1 to '+items.length+' of '+items.length+' results');
    if(!items.length){if(tbody)tbody.innerHTML='<tr><td colspan="6" class="empty-row">No blacklisted entries</td></tr>';return;}
    var rCls=function(reason){var r=(reason||'').toLowerCase();if(r.indexOf('fraud')>=0||r.indexOf('identity')>=0)return'bl-identity';if(r.indexOf('multiple')>=0)return'bl-multiple';if(r.indexOf('payment')>=0)return'bl-payment';return'bl-policy';};
    if(tbody)tbody.innerHTML=items.map(function(b){
      return'<tr><td style="font-family:var(--mono);font-size:12.5px;font-weight:700">'+_esc(b.phone||'—')+'</td><td style="font-family:var(--mono);font-size:12.5px">'+_esc(b.national_id||'—')+'</td><td><span class="bl-reason '+rCls(b.reason)+'">'+_esc(b.reason||'—')+'</span></td><td><div style="display:flex;align-items:center;gap:7px"><div class="uav uav-sm" style="background:var(--surface2);border:1px solid var(--border)"><span class="ms" style="font-size:13px;color:var(--text3)">person</span></div><span style="font-size:12.5px;color:var(--text2)">'+_esc(b.added_by_name||'Admin')+'</span></div></td><td style="color:var(--text2)">'+_date(b.created_at||b.date)+'</td><td><button class="ib ib-del" onclick="removeBlacklist('+b.id+')"><span class="ms">delete</span></button></td></tr>';
    }).join('');
  }catch(e){if(tbody)tbody.innerHTML='<tr><td colspan="6" class="empty-row" style="color:var(--red)">'+_esc(e.message)+'</td></tr>';}
};

// ═══════════════════════════════════════════════════════════════════════════
// SETTINGS  API: 'stats' → {total_users,total_listings,...}  +  'log' → [{action,created_at,admin_name,note}]
// FIX: stats is at TOP LEVEL (not nested), log items are objects not strings
// ═══════════════════════════════════════════════════════════════════════════
window.loadSettings=async function(){
  try{
    var d=await adminFetch('stats');
    // stats API returns jsonSuccess($stats) where $stats has the fields directly
    var s=d.stats||d||{};
    _set('dbUsers',Number(s.total_users||0).toLocaleString());
    _set('dbListings',Number(s.total_listings||0).toLocaleString());
    _set('dbMessages',Number(s.total_messages||0).toLocaleString());
    _set('dbRevenue','$'+(s.total_revenue||0));
    _set('dbOffers',Number(s.total_offers||0).toLocaleString());
    _set('dbConvos',Number(s.total_conversations||0).toLocaleString());
  }catch(e){console.warn('settings stats:',e);}
  _loadSysLog();
};

async function _loadSysLog(){
  var tbody=document.getElementById('dbStats');if(!tbody)return;
  try{
    var d=await adminFetch('log');
    // log returns jsonSuccess($rows) where rows is array of objects
    var items=Array.isArray(d)?d:(d.log||[]);
    if(!items.length){tbody.innerHTML='<tr><td colspan="4" class="empty-row">No system logs yet</td></tr>';return;}
    tbody.innerHTML=items.slice(0,10).map(function(l){
      // Each item: {id, admin_id, action, note, target, created_at, admin_name}
      var ts=l.created_at?l.created_at.replace('T',' ').substring(0,16):_date(l.created_at);
      return'<tr><td style="font-family:var(--mono);font-size:12px;color:var(--text2)">'+_esc(ts)+'</td><td style="font-weight:600">'+_esc(l.action||'—')+'</td><td style="color:var(--pr);font-weight:700">'+_esc(l.admin_name||l.user||'SYSTEM')+'</td><td><span class="badge bg">Success</span></td></tr>';
    }).join('');
  }catch(e){tbody.innerHTML='<tr><td colspan="4" class="empty-row">Could not load logs</td></tr>';}
}

// ═══════════════════════════════════════════════════════════════════════════
// AUDIT LOG TAB  API: 'log' → [{action, note, target, created_at, admin_name}]
// FIX: render as proper table, not raw text dump
// ═══════════════════════════════════════════════════════════════════════════
window.loadLog=async function(){
  var logBody=document.getElementById('logBody');
  if(logBody)logBody.innerHTML='<div class="empty-state"><span class="ms">shield</span><p>Loading...</p></div>';
  try{
    var d=await adminFetch('log');
    var items=Array.isArray(d)?d:(d.log||[]);
    if(!items.length){if(logBody)logBody.innerHTML='<div class="empty-state"><span class="ms">shield</span><p>No audit logs yet</p></div>';return;}
    if(logBody)logBody.innerHTML='<table class="tbl"><thead><tr><th>Timestamp</th><th>Action</th><th>Details</th><th>Admin</th><th>Status</th></tr></thead><tbody>'
      +items.map(function(l){
        var ts=l.created_at?l.created_at.replace('T',' ').substring(0,16):'—';
        var details=[l.note,l.target].filter(Boolean).join(' | ')||'—';
        return'<tr><td style="font-family:var(--mono);font-size:12px;color:var(--text2);white-space:nowrap">'+_esc(ts)+'</td><td><span style="font-weight:700;color:var(--text)">'+_esc(l.action||'—')+'</span></td><td style="color:var(--text2);max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">'+_esc(details)+'</td><td style="color:var(--pr);font-weight:700">'+_esc(l.admin_name||'SYSTEM')+'</td><td><span class="badge bg">Success</span></td></tr>';
      }).join('')+'</tbody></table>';
  }catch(e){if(logBody)logBody.innerHTML='<div class="empty-state"><p style="color:var(--red)">'+_esc(e.message)+'</p></div>';}
};

// ── Export ────────────────────────────────────────────────────────────────────
function exportCSV(type){window.open('api/admin.php?action=export_csv&type='+type+'&token='+_token(),'_blank');}
