/* ═══════════════════════════════════════════════════════════
   DICT IMS v3  –  script.js
   Strict async/await, real-time polling, loading states,
   toast feedback, empty-tbody containers (no PHP echo in HTML)
═══════════════════════════════════════════════════════════ */

// ── API base path (works in any subfolder) ──
const API = (() => {
  const p    = window.location.pathname;
  const base = p.substring(0, p.lastIndexOf('/') + 1);
  return base + 'api/';
})();

// ── Global state ──
let CU           = null;   // current user
let allInterns   = [];     // admin intern cache
let todayRec     = {};     // intern today's attendance record
let sessionsCache = [];
let docsCache    = [];
let reportsCache = [];

// ── Polling handles ──
let pollNotifHandle = null;
let pollAdminHandle = null;

/* ═══════════════════════════════════════════════════════════
   INIT
═══════════════════════════════════════════════════════════ */
document.addEventListener('DOMContentLoaded', () => {
  startClock();
  setupNav();
  initDateInputs();
  checkSession();
});

function startClock() {
  const tick = () => {
    const n = new Date();
    setEl('clock-time', n.toLocaleTimeString('en-PH'));
    setEl('clock-date', n.toLocaleDateString('en-PH', {
      weekday: 'long', year: 'numeric', month: 'long', day: 'numeric'
    }));
  };
  tick();
  setInterval(tick, 1000);
}

function initDateInputs() {
  const m = document.getElementById('att-month');
  if (m) {
    const n = new Date();
    m.value = `${n.getFullYear()}-${String(n.getMonth() + 1).padStart(2, '0')}`;
  }
}

/* ═══════════════════════════════════════════════════════════
   AUTH
═══════════════════════════════════════════════════════════ */
async function checkSession() {
  try {
    const r = await api('auth.php?action=me');
    if (r.success) enterApp(r.user);
  } catch { /* stay on login */ }
}

async function handleLogin(e) {
  e.preventDefault();
  const username = document.getElementById('login-username').value.trim();
  const password = document.getElementById('login-password').value;
  const btn      = document.getElementById('login-btn');
  const msg      = document.getElementById('auth-message');

  msg.className   = 'auth-message';
  msg.textContent = '';
  // ── Loading state: disable button & show spinner ──
  setLoading(btn, true, 'Logging in…');

  try {
    const r = await api('auth.php', {
      method: 'POST',
      body: JSON.stringify({ action: 'login', username, password })
    });
    if (r.success) {
      enterApp(r);
    } else {
      setAuthError(r.message || r.error || 'Login failed.');
    }
  } catch (err) {
    if (err.data) {
      setAuthError(err.data.message || err.data.error || 'Login failed.');
    } else {
      demoLogin(username, password);
    }
  } finally {
    setLoading(btn, false, '<i class="fas fa-sign-in-alt"></i> Login');
  }
}

function demoLogin(u, p) {
  const demos = [
    { id: 1, username: 'admin',      password: 'Admin@1234',  role: 'admin',      province: '',       intern_id: null,     profile: { full_name: 'Administrator' } },
    { id: 2, username: 'supervisor1',password: 'Super@1234', role: 'supervisor',  province: 'Cauayan',intern_id: null,     profile: { full_name: 'Supervisor One' } },
    { id: 3, username: 'jdelacruz', password: 'Intern@1234', role: 'intern',      province: 'Cauayan',intern_id: '25-0001',profile: { full_name: 'Juan Dela Cruz', ojt_hours_required: 480, session_access: 0 } },
  ];
  const found = demos.find(d => d.username === u && d.password === p);
  found ? enterApp(found) : setAuthError('Invalid username or password.');
}

function setAuthError(msg) {
  const el = document.getElementById('auth-message');
  el.className   = 'auth-message error';
  el.textContent = msg;
}

function togglePw() {
  const inp  = document.getElementById('login-password');
  const icon = document.getElementById('pw-toggle');
  inp.type   = inp.type === 'password' ? 'text' : 'password';
  icon.classList.toggle('fa-eye');
  icon.classList.toggle('fa-eye-slash');
}

function enterApp(user) {
  CU = user;
  hide('auth-modal');
  show('sidebar');
  show('main-content');

  const name = user.profile?.full_name || user.username;
  setEl('sb-username', user.username);
  setEl('sb-role', ucfirst(user.role));
  setEl('welcome-name', name.split(' ')[0]);

  if (user.intern_id) { setEl('sb-intern-id', user.intern_id); show('sb-intern-id'); }
  else { const el = document.getElementById('sb-intern-id'); if (el) el.style.display = 'none'; }

  document.querySelectorAll('.intern-nav').forEach(el =>
    el.style.display = user.role === 'intern' ? 'flex' : 'none');
  document.querySelectorAll('.admin-nav').forEach(el =>
    el.style.display = (user.role === 'admin' || user.role === 'supervisor') ? 'flex' : 'none');

  show(user.role === 'intern' ? 'intern-dash' : 'admin-dash');
  hide(user.role === 'intern' ? 'admin-dash' : 'intern-dash');
  setEl('welcome-sub', user.role === 'intern'
    ? 'DICT Isabela – OJT Intern Dashboard'
    : 'DICT Isabela – Admin / Management Dashboard');

  // ── On-Load Protocol: hydrate all data tables via GET ──
  if (user.role === 'intern') {
    loadMyAttendance();
    loadMyDocs();
    loadMySessions();
    loadMyReports();
    loadProfile();
    loadTemplates();
    loadNotifications();
    startPolling();
  } else {
    loadAdminAtt();
    loadAdminDocs();
    loadAdminInterns();
    loadAdminReports();
    loadAdminSessions();
    loadAccessRequests();
    loadNotifications();
    startAdminPolling();
  }
}

async function logout() {
  stopPolling();
  try { await api('auth.php', { method: 'POST', body: JSON.stringify({ action: 'logout' }) }); } catch {}
  CU = null;
  hide('sidebar');
  hide('main-content');
  show('auth-modal');
  document.getElementById('login-username').value = '';
  document.getElementById('login-password').value = '';
  document.getElementById('auth-message').textContent = '';
  showPage('dashboard');
}

/* ═══════════════════════════════════════════════════════════
   POLLING — Real-time sync every 12 seconds
   Spec: setInterval → fetch latest → prepend new rows if detected
═══════════════════════════════════════════════════════════ */
function startPolling() {
  pollNotifHandle = setInterval(() => {
    loadNotifications();
    loadMyAttendance();
  }, 12000);
}

function startAdminPolling() {
  pollAdminHandle = setInterval(() => {
    loadNotifications();
    loadAdminAtt();      // refresh attendance table silently
    loadAdminDocs();     // catch new submissions
    loadAdminReports();  // catch new reports
    loadAdminInterns();  // keep stats live
  }, 12000);
}

function stopPolling() {
  if (pollNotifHandle) clearInterval(pollNotifHandle);
  if (pollAdminHandle) clearInterval(pollAdminHandle);
}

/* ═══════════════════════════════════════════════════════════
   NAVIGATION
═══════════════════════════════════════════════════════════ */
function setupNav() {
  document.querySelectorAll('.nav-item[data-page]').forEach(el =>
    el.addEventListener('click', e => { e.preventDefault(); showPage(el.dataset.page); }));
}

function showPage(id) {
  document.querySelectorAll('.page').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
  const page = document.getElementById(id);
  if (page) page.classList.add('active');
  const nav = document.querySelector(`.nav-item[data-page="${id}"]`);
  if (nav) nav.classList.add('active');
}

/* ═══════════════════════════════════════════════════════════
   INTERN: ATTENDANCE
═══════════════════════════════════════════════════════════ */
async function loadMyAttendance() {
  const filter  = document.getElementById('att-filter')?.value || 'month';
  const status  = document.getElementById('att-status-filter')?.value || '';
  const monthEl = document.getElementById('att-month');
  let params    = `filter=${filter}&status=${encodeURIComponent(status)}`;
  if (filter === 'month' && monthEl?.value) {
    const [y, m] = monthEl.value.split('-');
    params += `&month=${m}&year=${y}`;
  }
  try {
    const r = await api(`attendance.php?${params}`);
    renderAttTable(r.records || []);
    updateOJTDisplay(r.total_hours || 0, CU?.profile?.ojt_hours_required || 480);
    const today = new Date().toISOString().split('T')[0];
    todayRec    = (r.records || []).find(x => x.attendance_date === today) || {};
    syncTimeBtns();
    updateDashAtt();
  } catch { renderAttTable([]); }
}

function renderAttTable(rows) {
  const el = document.getElementById('att-table');
  if (!el) return;
  if (!rows.length) {
    el.innerHTML = '<div class="empty"><i class="fas fa-calendar"></i><p>No attendance records for this period.</p></div>';
    return;
  }
  const badgeMap = {
    'Full Day': 'badge-full', 'Half Day': 'badge-half',
    'Early Out': 'badge-early', 'Absent': 'badge-absent', 'In Progress': 'badge-progress'
  };
  // Spec: inject rows into tbody — do NOT echo raw PHP inside HTML tables
  el.innerHTML = `<table>
    <thead><tr><th>Date</th><th>AM In</th><th>AM Out</th><th>PM In</th><th>PM Out</th><th>Hours</th><th>Minutes</th><th>Status</th></tr></thead>
    <tbody>${rows.map(r => `<tr>
      <td><strong>${fmtDate(r.attendance_date)}</strong></td>
      <td>${r.am_time_in || '—'}</td><td>${r.am_time_out || '—'}</td>
      <td>${r.pm_time_in || '—'}</td><td>${r.pm_time_out || '—'}</td>
      <td><strong>${(+r.hours_rendered || 0).toFixed(2)}h</strong></td>
      <td>${r.minutes_rendered || 0}m</td>
      <td><span class="badge ${badgeMap[r.attendance_status] || 'badge-absent'}">${r.attendance_status}</span></td>
    </tr>`).join('')}</tbody>
  </table>`;
}

function updateOJTDisplay(hoursFloat, target) {
  const mins    = Math.round(hoursFloat * 60);
  const h       = Math.floor(mins / 60), m = mins % 60;
  const rem     = Math.max(0, target - hoursFloat);
  const remMins = Math.round(rem * 60);
  const remH    = Math.floor(remMins / 60), remM = remMins % 60;
  const pct     = target > 0 ? Math.min(100, hoursFloat / target * 100) : 0;

  setEl('ojt-rendered',    `${h}h ${m}m`);
  setEl('ojt-remaining',   `${remH}h ${remM}m`);
  setEl('ojt-target',      `${target}h`);
  setEl('ojt-detail-left', `${h}h ${m}m rendered`);
  setEl('ojt-detail-right',`${remH}h ${remM}m remaining`);
  setEl('ojt-pct',         `${pct.toFixed(1)}%`);
  const bar = document.getElementById('ojt-bar');
  if (bar) bar.style.width = pct + '%';
}

async function doTime(session, action) {
  const btnId = `${session.toLowerCase()}-${action === 'time_in' ? 'in' : 'out'}`;
  const btn   = document.getElementById(btnId);
  // ── Loading state ──
  if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>'; }

  try {
    const r = await api('attendance.php', {
      method: 'POST',
      body: JSON.stringify({ action, session })
    });
    if (r.success) {
      toast(r.message || `${session} ${action === 'time_in' ? 'Time In' : 'Time Out'}: ${r.time}`, 'success');
      todayRec = r.record || todayRec;
      if (r.column) todayRec[r.column] = r.time;
      syncTimeBtns();
      updateDashAtt();
      // Spec: instantly update attendance table without page reload
      await loadMyAttendance();
    } else {
      toast(r.message || r.error || 'Error logging attendance.', 'error');
    }
  } catch (err) {
    if (err.data) {
      toast(err.data.message || err.data.error || 'Error.', 'error');
    } else {
      // Demo fallback
      const now  = new Date().toLocaleTimeString('en-PH');
      const cols = { AM_time_in: 'am_time_in', AM_time_out: 'am_time_out', PM_time_in: 'pm_time_in', PM_time_out: 'pm_time_out' };
      const col  = cols[`${session}_${action}`];
      if (col) todayRec[col] = now;
      syncTimeBtns();
      updateDashAtt();
      toast(`${session} ${action === 'time_in' ? 'Time In' : 'Time Out'} recorded: ${now}`, 'success');
    }
  } finally {
    if (btn) {
      const label = action === 'time_in' ? 'Time In' : 'Time Out';
      const icon  = action === 'time_in' ? 'sign-in-alt' : 'sign-out-alt';
      btn.innerHTML = `<i class="fas fa-${icon}"></i> ${label}`;
      // Re-evaluate disabled state from syncTimeBtns
      syncTimeBtns();
    }
  }
}

function syncTimeBtns() {
  const dis  = id => { const el = document.getElementById(id); if (el) { el.classList.add('disabled'); el.disabled = true; } };
  const enab = id => { const el = document.getElementById(id); if (el) { el.classList.remove('disabled'); el.disabled = false; } };
  const r    = todayRec;

  if (r.am_time_in)                          dis('am-in');  else enab('am-in');
  if (!r.am_time_in || r.am_time_out)        dis('am-out'); else enab('am-out');
  if (r.pm_time_in)                          dis('pm-in');  else enab('pm-in');
  if (!r.pm_time_in || r.pm_time_out)        dis('pm-out'); else enab('pm-out');

  const amSt = document.getElementById('am-status');
  const pmSt = document.getElementById('pm-status');
  if (amSt) amSt.textContent = r.am_time_in
    ? (r.am_time_out ? `✅ In: ${r.am_time_in} | Out: ${r.am_time_out}` : `🕐 In: ${r.am_time_in}`)
    : 'Not Started';
  if (pmSt) pmSt.textContent = r.pm_time_in
    ? (r.pm_time_out ? `✅ In: ${r.pm_time_in} | Out: ${r.pm_time_out}` : `🕐 In: ${r.pm_time_in}`)
    : 'Not Started';
}

function updateDashAtt() {
  const el = document.getElementById('d-att');
  if (!el) return;
  const r = todayRec;
  if (!r.am_time_in && !r.pm_time_in) { el.textContent = 'Not Yet Timed In'; return; }
  if (r.am_time_out && r.pm_time_out) { el.textContent = '✅ Full Day Complete'; return; }
  el.textContent = '🕐 In Progress';
}

function exportDTR() { toast('DTR export — connect PHP backend to generate PDF/DOCX.', 'info'); }
function clearAdminAttFilters() {
  ['adm-att-name', 'adm-att-date', 'adm-att-from', 'adm-att-to', 'adm-att-status'].forEach(id => {
    const el = document.getElementById(id); if (el) el.value = '';
  });
  loadAdminAtt();
}

/* ═══════════════════════════════════════════════════════════
   INTERN: DOCUMENTS
═══════════════════════════════════════════════════════════ */
async function loadMyDocs() {
  try {
    const r = await api(`documents.php?user_id=${CU.id}`);
    docsCache = r.documents || [];
    renderDocTable(docsCache);
    updateDocProgress(docsCache.length);

    if ((r.assignments || []).length) {
      show('doc-assignments-bar');
      document.getElementById('doc-assignments-list').innerHTML =
        r.assignments.map(a =>
          `<div style="padding:8px 0;border-bottom:1px solid var(--border);font-size:.83rem;">
            <strong>${a.title}</strong>${a.description ? ` — ${a.description}` : ''}
            ${a.file_path ? `<a href="${a.file_path}" download class="btn btn-ghost btn-sm" style="margin-left:8px;"><i class="fas fa-download"></i> Download</a>` : ''}
          </div>`
        ).join('');
    }
  } catch {}
}

function renderDocTable(docs) {
  const el = document.getElementById('doc-table');
  if (!el) return;
  if (!docs.length) {
    el.innerHTML = '<div class="empty"><i class="fas fa-file"></i><p>No documents submitted yet.</p></div>';
    return;
  }
  const badge = s => ({ pending: 'badge-pending', approved: 'badge-approved', rejected: 'badge-rejected' }[s] || 'badge-pending');
  // Spec: JS builds and injects rows — empty <tbody> in HTML
  el.innerHTML = `<table>
    <thead><tr><th>Title</th><th>Type</th><th>Notes</th><th>Submitted</th><th>Status</th><th>File</th><th></th></tr></thead>
    <tbody>${docs.map(d => `<tr>
      <td><strong>${d.title}</strong></td>
      <td>${docLabel(d.doc_type)}</td>
      <td style="max-width:200px;">${d.notes || '—'}</td>
      <td>${fmtDT(d.submitted_at)}</td>
      <td><span class="badge ${badge(d.status)}">${d.status}</span></td>
      <td>${d.file_path ? `<a href="${d.file_path}" download class="btn btn-ghost btn-sm"><i class="fas fa-download"></i></a>` : '—'}</td>
      <td>${d.status === 'pending' ? `<button class="btn btn-danger btn-sm" onclick="deleteDoc(${d.id})"><i class="fas fa-trash"></i></button>` : ''}</td>
    </tr>`).join('')}</tbody>
  </table>`;
}

function updateDocProgress(n) {
  const el = document.getElementById('doc-progress-badge');
  if (!el) return;
  el.textContent = `${Math.min(n, 6)} / 6`;
  el.className   = n >= 6 ? 'badge badge-approved' : 'badge badge-pending';
  setEl('d-docs', `${Math.min(n, 6)} / 6`);
}

function onFileSelect(inp) {
  const el = document.getElementById('doc-file-name');
  if (el && inp.files[0]) el.textContent = '📎 ' + inp.files[0].name;
}

function onAdminDocFileSelect(inp) {
  const el = document.getElementById('adm-doc-file-name');
  if (el && inp.files[0]) el.textContent = '📎 ' + inp.files[0].name;
}

async function submitDocument() {
  const typeEl  = document.getElementById('doc-type');
  const titleEl = document.getElementById('doc-title');
  const notesEl = document.getElementById('doc-notes');
  const fileEl  = document.getElementById('doc-file');

  const type  = typeEl?.value;
  const title = titleEl?.value.trim();
  const notes = notesEl?.value.trim();

  // ── Client-side validation (mirrors server-side) ──
  if (!type)  { toast('Select a document type.', 'error'); return; }
  if (!title) { toast('Enter a document title.', 'error'); return; }
  if (!notes) { toast('Note/Description is required.', 'error'); return; }
  if (!/^[A-Za-z\s]+-[A-Za-z]/.test(title)) {
    toast('Title format: LastName-Type (e.g. Dela Cruz-Waiver)', 'error'); return;
  }

  const btn = document.querySelector('#documents .btn-primary');
  // ── Spec: disable button & show loading state to prevent duplicate submissions ──
  setLoading(btn, true, 'Submitting…');

  try {
    // ── Spec: multipart/form-data so PHP reads $_POST and $_FILES ──
    const fd = new FormData();
    fd.append('doc_type', type);
    fd.append('title',    title);
    fd.append('notes',    notes);
    if (fileEl?.files[0]) fd.append('file', fileEl.files[0]);

    const res = await fetch(API + 'documents.php', {
      method: 'POST', credentials: 'include', body: fd
    });
    const r = await res.json();

    if (r.success) {
      // ── Spec: instantly append new row to Submitted Document History ──
      toast(r.message || 'Document submitted successfully!', 'success');
      typeEl.value = ''; titleEl.value = ''; notesEl.value = '';
      if (fileEl) fileEl.value = '';
      const nameEl = document.getElementById('doc-file-name');
      if (nameEl) nameEl.textContent = '';
      await loadMyDocs();
    } else {
      toast(r.message || r.error || 'Submission failed.', 'error');
    }
  } catch (err) {
    toast('Could not connect to server. Ensure XAMPP is running.', 'error');
  } finally {
    setLoading(btn, false, '<i class="fas fa-upload"></i> Submit Document');
  }
}

async function deleteDoc(id) {
  if (!confirm('Delete this document?')) return;
  try {
    await api(`documents.php?id=${id}`, { method: 'DELETE' });
    toast('Deleted.', 'success');
    // Spec: update DOM instantly without reload
    docsCache = docsCache.filter(d => d.id !== id);
    renderDocTable(docsCache);
    updateDocProgress(docsCache.length);
  } catch {
    docsCache = docsCache.filter(d => d.id !== id);
    renderDocTable(docsCache);
    updateDocProgress(docsCache.length);
    toast('Deleted.', 'success');
  }
}

/* ═══════════════════════════════════════════════════════════
   INTERN: LEARNING SESSIONS
═══════════════════════════════════════════════════════════ */
async function loadMySessions() {
  try {
    const r = await api(`sessions.php?province=${encodeURIComponent(CU.province || '')}`);
    sessionsCache = r.sessions || [];
    renderSessionList(sessionsCache, 'sessions-list', 'upcoming');
    const upcoming = sessionsCache.filter(s => s.session_date >= today());
    setEl('d-sess', upcoming.length);

    // Show/hide session creation access
    const hasAccess = CU?.profile?.session_access;
    const banner    = document.getElementById('access-request-banner');
    const createBar = document.getElementById('create-session-bar');
    if (banner)    banner.style.display    = hasAccess ? 'none' : 'flex';
    if (createBar) createBar.style.display = hasAccess ? 'block' : 'none';
  } catch {}
}

function renderSessionList(sessions, containerId, filter) {
  const el = document.getElementById(containerId);
  if (!el) return;
  const now = today();
  let list  = sessions;
  if (filter === 'upcoming') list = sessions.filter(s => s.session_date >= now);
  if (filter === 'past')     list = sessions.filter(s => s.session_date < now);

  if (!list.length) {
    el.innerHTML = '<div class="empty"><i class="fas fa-video"></i><p>No sessions found.</p></div>';
    return;
  }

  const platIcon = { 'Google Meet': 'fa-video', Zoom: 'fa-video-camera', Other: 'fa-link' };
  el.innerHTML = list.map(s => `
    <div class="session-item">
      <div class="session-item-header">
        <h4>${s.title}</h4>
        <span class="badge ${s.session_date >= now ? 'badge-full' : 'badge-absent'}">${s.session_date >= now ? 'Upcoming' : 'Past'}</span>
      </div>
      <div class="session-item-meta">
        <span><i class="fas fa-user"></i> ${s.host_name}</span>
        <span><i class="fas fa-calendar"></i> ${fmtDate(s.session_date)} at ${s.start_time}</span>
        <span><i class="fas ${platIcon[s.platform] || 'fa-link'}"></i> ${s.platform}</span>
        ${s.target_provinces ? `<span><i class="fas fa-map-marker-alt"></i> ${s.target_provinces}</span>` : ''}
      </div>
      ${s.meeting_link
        ? `<a href="${s.meeting_link}" target="_blank" class="btn btn-primary btn-sm" style="margin-top:8px;"><i class="fas fa-external-link-alt"></i> Join Session</a>`
        : ''}
      ${(CU.role === 'admin' || CU.role === 'supervisor')
        ? `<button class="btn btn-danger btn-sm" onclick="deleteSession(${s.id})" style="margin-top:8px;margin-left:6px;"><i class="fas fa-trash"></i></button>`
        : ''}
    </div>
  `).join('');
}

function filterSessions(filter, btn) {
  document.querySelectorAll('#sessions .tab-btn').forEach(b => b.classList.remove('active'));
  if (btn) btn.classList.add('active');
  renderSessionList(sessionsCache, 'sessions-list', filter);
}

async function requestSessionAccess() {
  const btn = document.getElementById('request-access-btn');
  setLoading(btn, true, 'Requesting…');
  try {
    const r = await api('sessions.php', { method: 'POST', body: JSON.stringify({ action: 'request_access' }) });
    if (r.success) {
      toast('Access request submitted! Awaiting admin approval.', 'success');
      btn.disabled = true;
      btn.textContent = '✅ Request Sent';
    } else {
      toast(r.message || r.error || 'Error.', 'error');
    }
  } catch {
    toast('Request submitted! (Demo)', 'success');
  } finally {
    setLoading(btn, false, '<i class="fas fa-paper-plane"></i> Request Access');
  }
}

async function deleteSession(id) {
  if (!confirm('Delete this session?')) return;
  try {
    await api(`sessions.php?id=${id}`, { method: 'DELETE' });
    toast('Session deleted.', 'success');
    sessionsCache = sessionsCache.filter(s => s.id !== id);
    renderSessionList(sessionsCache, 'adm-sessions-list', 'all');
    loadMySessions();
  } catch {
    toast('Deleted. (Demo)', 'success');
  }
}

/* ═══════════════════════════════════════════════════════════
   INTERN: WEEKLY REPORTS
═══════════════════════════════════════════════════════════ */
async function loadMyReports() {
  try {
    const r = await api('reports.php');
    reportsCache = r.reports || [];
    renderReportTable(reportsCache);
    setEl('d-rpts', reportsCache.length);
  } catch {}
}

async function loadTemplates() {
  try {
    const r = await api('reports.php?templates=1');
    const tmpl = r.templates || [];
    const card = document.getElementById('templates-card');
    const list = document.getElementById('templates-list');
    if (tmpl.length && card && list) {
      card.style.display = 'block';
      list.innerHTML = tmpl.map(t =>
        `<div style="padding:8px 0;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;">
          <div><strong style="font-size:.85rem;">${t.title}</strong><p style="font-size:.77rem;color:var(--muted);">${t.description || ''}</p></div>
          <a href="${t.file_path || '#'}" download class="btn btn-ghost btn-sm"><i class="fas fa-download"></i> Download</a>
        </div>`
      ).join('');
    }
  } catch {}
}

function renderReportTable(reports) {
  const el = document.getElementById('reports-table');
  if (!el) return;
  if (!reports.length) {
    el.innerHTML = '<div class="empty"><i class="fas fa-file-alt"></i><p>No reports submitted yet.</p></div>';
    return;
  }
  const badge = s => ({
    submitted: 'badge-pending', reviewed: 'badge-pending', approved: 'badge-approved'
  }[s] || 'badge-pending');
  el.innerHTML = `<table>
    <thead><tr><th>Title</th><th>Week</th><th>Date Range</th><th>Summary</th><th>Submitted</th><th>Status</th><th>Actions</th></tr></thead>
    <tbody>${reports.map(r => `<tr>
      <td><strong>${r.title}</strong></td>
      <td>Week ${r.week_number}</td>
      <td>${r.week_range || '—'}</td>
      <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${r.summary || '—'}</td>
      <td>${fmtDate(r.submitted_at)}</td>
      <td><span class="badge ${badge(r.status)}">${r.status}</span></td>
      <td style="display:flex;gap:6px;">
        ${r.file_path ? `<a href="${r.file_path}" download class="btn btn-ghost btn-sm"><i class="fas fa-download"></i></a>` : '<button class="btn btn-ghost btn-sm" disabled title="No file"><i class="fas fa-download"></i></button>'}
        <button class="btn btn-danger btn-sm" onclick="deleteReport(${r.id})"><i class="fas fa-trash"></i></button>
      </td>
    </tr>`).join('')}</tbody>
  </table>`;
}

function onReportFileSelect(inp) {
  const el = document.getElementById('report-file-name');
  if (el && inp.files[0]) el.textContent = '📎 ' + inp.files[0].name;
}

async function submitReport() {
  const titleEl   = document.getElementById('rpt-title');
  const weekEl    = document.getElementById('rpt-week');
  const rangeEl   = document.getElementById('rpt-range');
  const summaryEl = document.getElementById('rpt-summary');
  const fileEl    = document.getElementById('report-file');

  const title   = titleEl?.value.trim();
  const week    = weekEl?.value.trim();
  const range   = rangeEl?.value.trim();
  const summary = summaryEl?.value.trim();

  if (!title)   { toast('Enter report title.', 'error'); return; }
  if (!week)    { toast('Enter week number.', 'error'); return; }
  if (!summary) { toast('Enter a summary of activities.', 'error'); return; }
  if (!/^[A-Za-z\s]+-Week-\d+$/i.test(title)) {
    toast('Title format: LastName-Week-N (e.g. Dela Cruz-Week-1)', 'error'); return;
  }
  const wkNum = parseInt(week, 10);
  if (isNaN(wkNum) || wkNum < 1 || wkNum > 52) {
    toast('Week number must be a whole number between 1 and 52.', 'error'); return;
  }

  const btn = document.querySelector('#reports .btn-primary[onclick="submitReport()"]');
  setLoading(btn, true, 'Submitting…');

  try {
    // ── Spec: multipart/form-data so PHP reads $_POST + $_FILES ──
    const fd = new FormData();
    fd.append('title',       title);
    fd.append('week_number', wkNum);
    fd.append('week_range',  range || '');
    fd.append('summary',     summary);
    if (fileEl?.files[0]) fd.append('file', fileEl.files[0]);

    const res = await fetch(API + 'reports.php', {
      method: 'POST', credentials: 'include', body: fd
    });
    const r = await res.json();

    if (r.success) {
      toast(r.message || 'Report submitted successfully!', 'success');
      [titleEl, weekEl, rangeEl, summaryEl].forEach(el => { if (el) el.value = ''; });
      if (fileEl) fileEl.value = '';
      const nameEl = document.getElementById('report-file-name');
      if (nameEl) nameEl.textContent = '';
      // Spec: instantly append new row to Report History table
      await loadMyReports();
    } else {
      toast(r.message || r.error || 'Submission failed.', 'error');
    }
  } catch {
    toast('Could not connect to server.', 'error');
  } finally {
    setLoading(btn, false, '<i class="fas fa-paper-plane"></i> Submit Report');
  }
}

async function deleteReport(id) {
  if (!confirm('Delete this report?')) return;
  try {
    await api(`reports.php?id=${id}`, { method: 'DELETE' });
    toast('Deleted.', 'success');
    reportsCache = reportsCache.filter(r => r.id !== id);
    renderReportTable(reportsCache);
    setEl('d-rpts', reportsCache.length);
  } catch {
    reportsCache = reportsCache.filter(r => r.id !== id);
    renderReportTable(reportsCache);
    toast('Deleted.', 'success');
  }
}

/* ═══════════════════════════════════════════════════════════
   INTERN: PROFILE SETTINGS
═══════════════════════════════════════════════════════════ */
async function loadProfile() {
  try {
    const r = await api('interns.php?me=1');
    const p = r.profile || {};
    const setV = (id, v) => { const el = document.getElementById(id); if (el && v != null) el.value = v; };
    setV('s-name', p.full_name); setV('s-age', p.age);       setV('s-gender', p.gender);
    setV('s-email', p.email);   setV('s-contact', p.contact_number); setV('s-address', p.address);
    setV('s-school', p.school); setV('s-course', p.course);
    setV('s-province', p.province); setV('s-ojt', p.ojt_hours_required);
    if (p.profile_photo) {
      const el = document.getElementById('photo-preview');
      if (el) el.innerHTML = `<img src="${p.profile_photo}" style="width:70px;height:70px;border-radius:50%;object-fit:cover;">`;
    }
    if (CU) CU.profile = { ...(CU.profile || {}), ...p };
  } catch {}
}

function onPhotoSelect(inp) {
  if (!inp.files[0]) return;
  const reader = new FileReader();
  reader.onload = e => {
    const el = document.getElementById('photo-preview');
    if (el) el.innerHTML = `<img src="${e.target.result}" style="width:70px;height:70px;border-radius:50%;object-fit:cover;">`;
  };
  reader.readAsDataURL(inp.files[0]);
}

async function saveProfile() {
  const data = {
    full_name:         document.getElementById('s-name')?.value,
    age:               document.getElementById('s-age')?.value,
    gender:            document.getElementById('s-gender')?.value,
    email:             document.getElementById('s-email')?.value,
    contact_number:    document.getElementById('s-contact')?.value,
    address:           document.getElementById('s-address')?.value,
    school:            document.getElementById('s-school')?.value,
    course:            document.getElementById('s-course')?.value,
    province:          document.getElementById('s-province')?.value,
    ojt_hours_required: document.getElementById('s-ojt')?.value,
  };

  const btn = document.querySelector('#settings .btn-primary');
  setLoading(btn, true, 'Saving…');

  try {
    const r = await api('interns.php', { method: 'PUT', body: JSON.stringify(data) });
    if (r.success) {
      toast(r.message || 'Profile saved!', 'success');
      if (CU) CU.profile = { ...(CU.profile || {}), ...data };
    } else {
      toast(r.message || r.error || 'Failed to save.', 'error');
    }
  } catch {
    toast('Profile saved! (Demo)', 'success');
  } finally {
    setLoading(btn, false, '<i class="fas fa-save"></i> Save Profile');
  }
}

/* ═══════════════════════════════════════════════════════════
   ADMIN: ATTENDANCE MONITOR
═══════════════════════════════════════════════════════════ */
async function loadAdminAtt() {
  const name   = document.getElementById('adm-att-name')?.value || '';
  const date   = document.getElementById('adm-att-date')?.value || '';
  const from   = document.getElementById('adm-att-from')?.value || '';
  const to     = document.getElementById('adm-att-to')?.value || '';
  const status = document.getElementById('adm-att-status')?.value || '';
  let params   = 'user_id=0';
  if (name)   params += `&intern_name=${encodeURIComponent(name)}`;
  if (date)   params += `&date=${date}`;
  if (from)   params += `&from=${from}`;
  if (to)     params += `&to=${to}`;
  if (status) params += `&status=${encodeURIComponent(status)}`;
  try {
    const r = await api(`attendance.php?${params}`);
    renderAdminAttTable(r.records || []);
  } catch { renderAdminAttTable([]); }
}

function renderAdminAttTable(rows) {
  const body = document.getElementById('adm-att-body');
  if (!body) return;
  const badgeMap = {
    'Full Day': 'badge-full', 'Half Day': 'badge-half',
    'Early Out': 'badge-early', 'Absent': 'badge-absent', 'In Progress': 'badge-progress'
  };
  body.innerHTML = rows.length
    ? rows.map(r => `<tr>
        <td><strong>${r.full_name || '—'}</strong></td>
        <td><span class="intern-id-badge">${r.intern_id || '—'}</span></td>
        <td>${r.am_time_in || '—'}</td>
        <td>${r.am_time_out || '—'}</td>
        <td>${r.pm_time_in || '—'}</td>
        <td>${r.pm_time_out || '—'}</td>
        <td>${r.province || '—'}</td>
        <td>${fmtDate(r.attendance_date)}</td>
        <td><strong>${(+r.hours_rendered || 0).toFixed(2)}h</strong></td>
        <td><span class="badge ${badgeMap[r.attendance_status] || 'badge-absent'}">${r.attendance_status}</span></td>
      </tr>`).join('')
    : '<tr><td colspan="10" class="empty"><i class="fas fa-calendar"></i> No records found.</td></tr>';
}

/* ═══════════════════════════════════════════════════════════
   ADMIN: DOCUMENTS
═══════════════════════════════════════════════════════════ */
async function loadAdminDocs() {
  const name = document.getElementById('adm-doc-name')?.value || '';
  const type = document.getElementById('adm-doc-type')?.value || '';
  const id   = document.getElementById('adm-doc-id')?.value || '';
  let p = '';
  if (name) p += `&name=${encodeURIComponent(name)}`;
  if (type) p += `&type=${type}`;
  if (id)   p += `&intern_id=${encodeURIComponent(id)}`;
  try {
    const r = await api(`documents.php?${p.replace(/^&/, '')}`);
    renderAdminDocTable(r.documents || []);
    setEl('ad-docs', (r.documents || []).filter(d => d.status === 'pending').length);
  } catch {}
}

function renderAdminDocTable(docs) {
  const body = document.getElementById('adm-doc-body');
  if (!body) return;
  const badge = s => ({ pending: 'badge-pending', approved: 'badge-approved', rejected: 'badge-rejected' }[s] || 'badge-pending');
  body.innerHTML = docs.length
    ? docs.map(d => `<tr>
        <td><strong>${d.full_name || '—'}</strong></td>
        <td><span class="intern-id-badge">${d.intern_id || '—'}</span></td>
        <td>${d.title}</td>
        <td>${docLabel(d.doc_type)}</td>
        <td style="max-width:140px;">${d.notes || '—'}</td>
        <td>${fmtDate(d.submitted_at)}</td>
        <td><span class="badge ${badge(d.status)}">${d.status}</span></td>
        <td style="display:flex;gap:6px;">
          ${d.file_path ? `<a href="${d.file_path}" download class="btn btn-ghost btn-sm"><i class="fas fa-download"></i></a>` : ''}
          ${d.status === 'pending' ? `
          <button class="btn btn-success btn-sm" onclick="reviewDoc(${d.id},'approved')"><i class="fas fa-check"></i></button>
          <button class="btn btn-danger btn-sm"  onclick="reviewDoc(${d.id},'rejected')"><i class="fas fa-times"></i></button>` : '—'}
        </td>
      </tr>`).join('')
    : '<tr><td colspan="8" class="empty">No submissions found.</td></tr>';
}

async function reviewDoc(id, status) {
  try {
    const r = await api('documents.php', { method: 'PUT', body: JSON.stringify({ id, status }) });
    toast(r.message || `Document ${status}!`, status === 'approved' ? 'success' : 'error');
    loadAdminDocs();
  } catch {
    toast(`Marked as ${status}. (Demo)`, status === 'approved' ? 'success' : 'error');
  }
}

async function assignDocument() {
  const targetId = document.getElementById('adm-doc-target')?.value || '';
  const title    = document.getElementById('adm-doc-assign-title')?.value.trim();
  const desc     = document.getElementById('adm-doc-assign-desc')?.value.trim();
  const fileEl   = document.getElementById('adm-doc-file');

  if (!title) { toast('Enter a document title.', 'error'); return; }

  const btn = document.querySelector('#doc-outbound-tab .btn-primary');
  setLoading(btn, true, 'Sending…');

  const fd = new FormData();
  fd.append('action', 'assign');
  fd.append('title',  title);
  if (desc)     fd.append('description', desc);
  if (targetId) fd.append('target_user_id', targetId);
  if (fileEl?.files[0]) fd.append('file', fileEl.files[0]);

  try {
    const res = await fetch(API + 'documents.php', {
      method: 'POST', credentials: 'include', body: fd
    });
    const r = await res.json();
    if (r.success) {
      toast(r.message || 'Document assignment sent!', 'success');
      document.getElementById('adm-doc-assign-title').value = '';
      document.getElementById('adm-doc-assign-desc').value  = '';
      if (fileEl) fileEl.value = '';
      const nameEl = document.getElementById('adm-doc-file-name');
      if (nameEl) nameEl.textContent = '';
    } else {
      toast(r.message || r.error || 'Assignment failed.', 'error');
    }
  } catch {
    toast('Could not connect to server.', 'error');
  } finally {
    setLoading(btn, false, '<i class="fas fa-paper-plane"></i> Send Request');
  }
}

function switchDocTab(tab, btn) {
  document.querySelectorAll('#doc-admin .tab-btn').forEach(b => b.classList.remove('active'));
  if (btn) btn.classList.add('active');
  document.getElementById('doc-inbound-tab').classList.toggle('active', tab === 'inbound');
  document.getElementById('doc-outbound-tab').classList.toggle('active', tab === 'outbound');
  if (tab === 'outbound') populateInternDropdown('adm-doc-target');
}

/* ═══════════════════════════════════════════════════════════
   ADMIN: SESSIONS
═══════════════════════════════════════════════════════════ */
async function loadAdminSessions() {
  try {
    const r = await api('sessions.php');
    sessionsCache = r.sessions || [];
    renderSessionList(sessionsCache, 'adm-sessions-list', 'all');
  } catch {}
}

async function loadAccessRequests() {
  try {
    const r = await api('sessions.php?action=access_requests');
    const list = r.requests || [];
    const badge = document.getElementById('req-badge');
    if (badge) { badge.textContent = list.length; badge.style.display = list.length ? 'inline-block' : 'none'; }
    const el = document.getElementById('access-requests-list');
    if (!el) return;
    if (!list.length) {
      el.innerHTML = '<div class="empty"><i class="fas fa-inbox"></i><p>No pending access requests.</p></div>';
      return;
    }
    el.innerHTML = `<div class="card"><table><thead><tr><th>Intern</th><th>Intern ID</th><th>Requested</th><th>Actions</th></tr></thead>
      <tbody>${list.map(req => `<tr>
        <td><strong>${req.full_name || req.username}</strong></td>
        <td><span class="intern-id-badge">${req.intern_id || '—'}</span></td>
        <td>${fmtDT(req.requested_at)}</td>
        <td style="display:flex;gap:6px;">
          <button class="btn btn-success btn-sm" onclick="reviewAccessReq(${req.id},'approved')"><i class="fas fa-check"></i> Approve</button>
          <button class="btn btn-danger btn-sm"  onclick="reviewAccessReq(${req.id},'denied')"><i class="fas fa-times"></i> Deny</button>
        </td>
      </tr>`).join('')}</tbody></table></div>`;
  } catch {}
}

async function reviewAccessReq(id, decision) {
  try {
    const r = await api('sessions.php', { method: 'PUT', body: JSON.stringify({ request_id: id, decision }) });
    toast(r.message || `Request ${decision}!`, decision === 'approved' ? 'success' : 'error');
    loadAccessRequests();
    loadAdminInterns();
  } catch {
    toast(`${ucfirst(decision)}. (Demo)`, decision === 'approved' ? 'success' : 'error');
  }
}

function openSessionModal() { show('session-modal'); }

async function createSession() {
  const provinces = [...document.querySelectorAll('.province-check input:checked')].map(x => x.value);
  const data = {
    action:           'create',
    title:            document.getElementById('sm-title')?.value.trim(),
    host_name:        document.getElementById('sm-host')?.value.trim(),
    platform:         document.getElementById('sm-platform')?.value,
    meeting_link:     document.getElementById('sm-link')?.value.trim(),
    session_date:     document.getElementById('sm-date')?.value,
    start_time:       document.getElementById('sm-start')?.value,
    end_time:         document.getElementById('sm-end')?.value,
    target_provinces: provinces,
  };
  if (!data.title || !data.session_date || !data.start_time) {
    toast('Fill in required fields (title, date, start time).', 'error'); return;
  }

  const btn = document.querySelector('#session-modal .btn-primary');
  setLoading(btn, true, 'Creating…');

  try {
    const r = await api('sessions.php', { method: 'POST', body: JSON.stringify(data) });
    if (r.success) {
      toast(r.message || 'Session created!', 'success');
      closeModal('session-modal');
      loadAdminSessions();
      loadMySessions();
    } else {
      toast(r.message || r.error || 'Error.', 'error');
    }
  } catch {
    toast('Session created! (Demo)', 'success');
    closeModal('session-modal');
  } finally {
    setLoading(btn, false, '<i class="fas fa-plus"></i> Create Session');
  }
}

function switchSessAdminTab(tab, btn) {
  document.querySelectorAll('#sessions-admin .tab-btn').forEach(b => b.classList.remove('active'));
  if (btn) btn.classList.add('active');
  document.getElementById('adm-sessions-tab').classList.toggle('active', tab === 'sessions');
  document.getElementById('adm-requests-tab').classList.toggle('active', tab === 'requests');
  if (tab === 'requests') loadAccessRequests();
}

/* ═══════════════════════════════════════════════════════════
   ADMIN: WEEKLY REPORTS
═══════════════════════════════════════════════════════════ */
async function loadAdminReports() {
  const week = document.getElementById('adm-rpt-week')?.value || '';
  const from = document.getElementById('adm-rpt-from')?.value || '';
  const to   = document.getElementById('adm-rpt-to')?.value || '';
  let p = '';
  if (week) p += `&week=${week}`;
  if (from) p += `&from=${from}`;
  if (to)   p += `&to=${to}`;
  try {
    const r    = await api(`reports.php?${p.replace(/^&/, '')}`);
    const rows = r.reports || [];
    setEl('ad-rpts', rows.filter(r => r.status === 'submitted').length);
    const body = document.getElementById('adm-rpt-body');
    if (!body) return;
    const badge = s => ({
      submitted: 'badge-pending', reviewed: 'badge-pending', approved: 'badge-approved'
    }[s] || 'badge-pending');
    body.innerHTML = rows.length
      ? rows.map(r => `<tr>
          <td><strong>${r.full_name || '—'}</strong></td>
          <td><span class="intern-id-badge">${r.intern_id || '—'}</span></td>
          <td><strong>${r.title}</strong></td>
          <td>Week ${r.week_number}</td>
          <td>${r.week_range || '—'}</td>
          <td style="max-width:180px;">${r.summary || '—'}</td>
          <td>${fmtDate(r.submitted_at)}</td>
          <td><span class="badge ${badge(r.status)}">${r.status}</span></td>
          <td style="display:flex;gap:6px;">
            ${r.file_path ? `<a href="${r.file_path}" download class="btn btn-ghost btn-sm"><i class="fas fa-download"></i></a>` : ''}
            ${r.status !== 'approved'
              ? `<button class="btn btn-success btn-sm" onclick="reviewReport(${r.id},'approved')"><i class="fas fa-check"></i> Approve</button>`
              : '—'}
          </td>
        </tr>`).join('')
      : '<tr><td colspan="9" class="empty">No reports found.</td></tr>';
  } catch {}
}

async function reviewReport(id, status) {
  try {
    const r = await api('reports.php', { method: 'PUT', body: JSON.stringify({ id, status }) });
    toast(r.message || 'Report approved!', 'success');
    loadAdminReports();
  } catch {
    toast('Approved. (Demo)', 'success');
  }
}

async function uploadTemplate() {
  const title  = document.getElementById('tmpl-title')?.value.trim();
  const desc   = document.getElementById('tmpl-desc')?.value.trim();
  const fileEl = document.getElementById('tmpl-file');

  if (!title) { toast('Enter template title.', 'error'); return; }
  if (!fileEl?.files[0]) { toast('Please select a file.', 'error'); return; }

  const btn = document.querySelector('#adm-rpt-templates-tab .btn-primary');
  setLoading(btn, true, 'Uploading…');

  const fd = new FormData();
  fd.append('action',      'upload_template');
  fd.append('title',       title);
  if (desc) fd.append('description', desc);
  fd.append('file', fileEl.files[0]);

  try {
    const res = await fetch(API + 'reports.php', {
      method: 'POST', credentials: 'include', body: fd
    });
    const r = await res.json();
    if (r.success) {
      toast(r.message || 'Template uploaded!', 'success');
      document.getElementById('tmpl-title').value = '';
      document.getElementById('tmpl-desc').value  = '';
      fileEl.value = '';
    } else {
      toast(r.message || r.error || 'Upload failed.', 'error');
    }
  } catch {
    toast('Could not connect to server.', 'error');
  } finally {
    setLoading(btn, false, '<i class="fas fa-upload"></i> Upload Template');
  }
}

function switchRptAdminTab(tab, btn) {
  document.querySelectorAll('#reports-admin .tab-btn').forEach(b => b.classList.remove('active'));
  if (btn) btn.classList.add('active');
  document.getElementById('adm-rpt-submissions-tab').classList.toggle('active', tab === 'submissions');
  document.getElementById('adm-rpt-templates-tab').classList.toggle('active',  tab === 'templates');
}

/* ═══════════════════════════════════════════════════════════
   ADMIN: INTERN MANAGEMENT
═══════════════════════════════════════════════════════════ */
async function loadAdminInterns() {
  const search   = document.getElementById('im-search')?.value || '';
  const province = document.getElementById('im-province')?.value || '';
  const status   = document.getElementById('im-status')?.value || '';
  let p = '';
  if (search)   p += `&search=${encodeURIComponent(search)}`;
  if (province) p += `&province=${encodeURIComponent(province)}`;
  if (status)   p += `&status=${status}`;
  try {
    const r = await api(`interns.php?${p.replace(/^&/, '')}`);
    allInterns = r.interns || [];
    renderInternTable(allInterns);
    updateInternStats(allInterns);
  } catch { renderInternTable([]); }
}

function filterInternTable() { loadAdminInterns(); }

function renderInternTable(interns) {
  const body = document.getElementById('im-table-body');
  if (!body) return;

  if (!interns.length) {
    body.innerHTML = '<tr><td colspan="9" class="empty"><i class="fas fa-users"></i> No interns found.</td></tr>';
    return;
  }

  const stBadge = s => ({
    active: 'badge-full', graduated: 'badge-half', inactive: 'badge-absent'
  }[s] || 'badge-absent');

  let html = '';
  const provinces = ['Tuguegarao', 'Quirino', 'Cauayan', 'Santiago', 'Batanes', 'Nueva Vizcaya'];

  provinces.forEach(prov => {
    const group = interns.filter(i => i.province === prov || i.user_province === prov);
    if (!group.length) return;
    html += `<tr class="group-row"><td colspan="9" style="background:var(--navy);color:#fff;font-weight:700;font-size:.78rem;padding:6px 14px;letter-spacing:.05em;">
      <i class="fas fa-map-marker-alt"></i> ${prov} — ${group.length} intern${group.length !== 1 ? 's' : ''}
    </td></tr>`;
    group.forEach(i => {
      const target = (+i.ojt_hours_required || 480);
      const done   = (+i.hours_rendered || 0);
      const pct    = target > 0 ? Math.min(100, done / target * 100).toFixed(0) : 0;
      html += `<tr>
        <td><span class="intern-id-badge">${i.intern_id || '—'}</span></td>
        <td><strong>${i.full_name || '(No name yet)'}</strong></td>
        <td>${i.username}</td>
        <td>${i.province || i.user_province || '—'}</td>
        <td>
          <div style="display:flex;align-items:center;gap:8px;">
            <div style="flex:1;height:6px;background:var(--border);border-radius:3px;">
              <div style="width:${pct}%;height:100%;background:var(--gold);border-radius:3px;"></div>
            </div>
            <span style="font-size:.75rem;font-weight:600;color:var(--navy);">${pct}%</span>
          </div>
          <small style="color:var(--muted);font-size:.72rem;">${done.toFixed(1)}h / ${target}h</small>
        </td>
        <td>${i.doc_count || 0} / 6</td>
        <td>
          <label class="switch" title="${i.session_access ? 'Revoke' : 'Grant'} session access">
            <input type="checkbox" ${i.session_access ? 'checked' : ''} onchange="toggleSessionAccess(${i.user_id}, this.checked)">
            <span class="slider"></span>
          </label>
        </td>
        <td><span class="badge ${stBadge(i.status)}">${i.status}</span></td>
        <td style="display:flex;gap:6px;">
          <button class="btn btn-ghost btn-sm" onclick="setInternStatus(${i.user_id}, '${i.status === 'active' ? 'inactive' : 'active'}')" title="${i.status === 'active' ? 'Deactivate' : 'Activate'}">
            <i class="fas fa-${i.status === 'active' ? 'user-slash' : 'user-check'}"></i>
          </button>
          <button class="btn btn-danger btn-sm" onclick="deleteIntern(${i.user_id})" title="Remove intern"><i class="fas fa-trash"></i></button>
        </td>
      </tr>`;
    });
  });

  // Interns with no province
  const ungrouped = interns.filter(i => !i.province && !i.user_province);
  if (ungrouped.length) {
    html += `<tr class="group-row"><td colspan="9" style="background:var(--border);font-weight:700;font-size:.78rem;padding:6px 14px;">Unassigned Province</td></tr>`;
    ungrouped.forEach(i => {
      html += `<tr><td colspan="9"><em>${i.full_name || i.username}</em></td></tr>`;
    });
  }

  body.innerHTML = html;
}

function updateInternStats(interns) {
  const active   = interns.filter(i => i.status === 'active').length;
  const docsDone = interns.filter(i => (+i.doc_count || 0) >= 6).length;
  const avgOJT   = interns.length
    ? interns.reduce((s, i) => s + (i.ojt_hours_required > 0 ? i.hours_rendered / i.ojt_hours_required * 100 : 0), 0) / interns.length
    : 0;
  const access = interns.filter(i => i.session_access).length;
  setEl('im-total',  active);
  setEl('im-docs',   docsDone);
  setEl('im-ojt',    avgOJT.toFixed(1) + '%');
  setEl('im-access', access + ' intern' + (access !== 1 ? 's' : ''));
  setEl('ad-total',  active);
}

function openEnrollModal() { show('enroll-modal'); }

async function enrollIntern() {
  const internId = document.getElementById('e-id')?.value.trim();
  const username = document.getElementById('e-username')?.value.trim();
  const password = document.getElementById('e-password')?.value;
  const hours    = document.getElementById('e-hours')?.value || 480;
  const province = document.getElementById('e-province')?.value || '';

  // ── Client-side validation mirrors server-side ──
  if (!internId || !username || !password) {
    toast('Intern ID, username and password are all required.', 'error'); return;
  }
  if (!/^\d{2}-\d{4}$/.test(internId)) {
    toast('Intern ID must be YY-XXXX format (e.g. 25-0001).', 'error'); return;
  }

  const btn = document.querySelector('#enroll-modal .btn-primary');
  setLoading(btn, true, 'Enrolling…');

  try {
    const r = await api('interns.php', {
      method: 'POST',
      body: JSON.stringify({ intern_id: internId, username, password, ojt_hours_required: hours, province })
    });
    if (r.success) {
      toast(r.message || 'Intern enrolled successfully!', 'success');
      closeModal('enroll-modal');
      ['e-id', 'e-username', 'e-password'].forEach(id => {
        const el = document.getElementById(id); if (el) el.value = '';
      });
      document.getElementById('e-hours').value    = '480';
      document.getElementById('e-province').value = '';
      // Spec: instantly update the intern table
      await loadAdminInterns();
    } else {
      toast(r.message || r.error || 'Enrollment failed.', 'error');
    }
  } catch (err) {
    if (err.data) {
      toast(err.data.message || err.data.error || 'Enrollment failed.', 'error');
    } else {
      toast('Could not connect to server. Check XAMPP is running and the database is set up.', 'error');
    }
  } finally {
    setLoading(btn, false, '<i class="fas fa-plus"></i> Enroll');
  }
}

async function toggleSessionAccess(uid, grant) {
  try {
    const r = await api('interns.php', {
      method: 'PUT',
      body: JSON.stringify({ action: 'toggle_session_access', user_id: uid, session_access: grant ? 1 : 0 })
    });
    toast(r.message || (grant ? 'Session access granted.' : 'Access revoked.'), grant ? 'success' : 'info');
    loadAdminInterns();
  } catch {
    toast(grant ? 'Granted. (Demo)' : 'Revoked. (Demo)', 'success');
  }
}

async function setInternStatus(uid, status) {
  try {
    const r = await api('interns.php', { method: 'PUT', body: JSON.stringify({ action: 'set_status', user_id: uid, status }) });
    toast(r.message || `Intern set to ${status}.`, 'success');
    loadAdminInterns();
  } catch {
    toast(`Status updated. (Demo)`, 'success');
  }
}

async function deleteIntern(uid) {
  if (!confirm('Permanently delete this intern? This cannot be undone.')) return;
  try {
    await api(`interns.php?user_id=${uid}`, { method: 'DELETE' });
    toast('Intern removed.', 'success');
    loadAdminInterns();
  } catch {
    toast('Removed. (Demo)', 'success');
  }
}

function populateInternDropdown(elId) {
  const el = document.getElementById(elId);
  if (!el) return;
  const curr = el.value;
  el.innerHTML = '<option value="">All Interns (Broadcast)</option>' +
    allInterns.map(i =>
      `<option value="${i.user_id}" ${curr == i.user_id ? 'selected' : ''}>${i.full_name || i.username} (${i.intern_id || '—'})</option>`
    ).join('');
}

/* ═══════════════════════════════════════════════════════════
   NOTIFICATIONS
═══════════════════════════════════════════════════════════ */
async function loadNotifications() {
  try {
    const r = await api('notifications.php');
    const n      = r.notifications || [];
    const unread = r.unread || 0;

    ['notif-dot', 'notif-dot2', 'intern-notif-dot'].forEach(id => {
      const el = document.getElementById(id);
      if (el) el.style.display = unread > 0 ? 'inline-block' : 'none';
    });

    const adminEl = document.getElementById('notif-list');
    if (adminEl) {
      adminEl.innerHTML = n.length
        ? _renderNotifList(n)
        : '<div class="empty"><i class="fas fa-bell"></i><p>No notifications.</p></div>';
    }

    const internEl = document.getElementById('intern-notif-list');
    if (internEl) {
      internEl.innerHTML = n.length
        ? _renderNotifList(n)
        : '<div class="empty"><i class="fas fa-bell"></i><p>No notifications.</p></div>';
    }

    // Dashboard document alert for interns
    const docNotifs  = n.filter(x => x.type === 'document_request' && !x.is_read);
    const dashAlert  = document.getElementById('dash-doc-alert');
    const alertText  = document.getElementById('dash-doc-alert-text');
    if (dashAlert && docNotifs.length) {
      dashAlert.style.display = 'flex';
      if (alertText) alertText.textContent = docNotifs[0].message + (docNotifs.length > 1 ? ` (+${docNotifs.length - 1} more)` : '');
    }
  } catch {}
}

function _renderNotifList(n) {
  return n.map(x => `
    <div class="notif-item" onclick="markNotifRead(${x.id})" style="cursor:pointer;">
      <div class="notif-dot ${!x.is_read ? 'unread' : ''}"></div>
      <div class="notif-text"><h4>${x.title}</h4><p>${x.message}</p></div>
      <span class="notif-time">${fmtDT(x.created_at)}</span>
    </div>`).join('');
}

async function markNotifRead(id) {
  try { await api('notifications.php', { method: 'PUT', body: JSON.stringify({ id }) }); loadNotifications(); } catch {}
}

async function markAllRead() {
  try {
    await api('notifications.php', { method: 'PUT', body: JSON.stringify({}) });
    loadNotifications();
  } catch {
    toast('Marked all read. (Demo)', 'success');
  }
}

/* ═══════════════════════════════════════════════════════════
   HELPERS
═══════════════════════════════════════════════════════════ */

// ── Core fetch wrapper (enforces credentials, surfaces errors) ──
async function api(endpoint, opts = {}) {
  const url  = endpoint.startsWith('http') ? endpoint : API + endpoint;
  const defs = {
    headers:     { 'Content-Type': 'application/json' },
    credentials: 'include',
  };
  // Don't override Content-Type for FormData (browser sets boundary)
  if (opts.body instanceof FormData) {
    delete defs.headers['Content-Type'];
  }
  const res  = await fetch(url, { ...defs, ...opts });
  const data = await res.json().catch(() => ({ error: `Server returned HTTP ${res.status}` }));
  if (!res.ok) throw Object.assign(new Error(data.message || data.error || `HTTP ${res.status}`), { data });
  return data;
}

// ── Loading state helper ──
function setLoading(btn, loading, label) {
  if (!btn) return;
  btn.disabled  = loading;
  btn.innerHTML = loading ? '<i class="fas fa-spinner fa-spin"></i> ' + label : label;
}

// ── Toast (Spec: non-intrusive corner popup) ──
function toast(msg, type = '') {
  const c = document.getElementById('toast-box');
  if (!c) return;
  const el   = document.createElement('div');
  const icon = { success: 'check-circle', error: 'exclamation-circle', info: 'info-circle' }[type] || 'info-circle';
  el.className  = `toast ${type}`;
  el.innerHTML  = `<i class="fas fa-${icon}"></i> ${msg}`;
  c.appendChild(el);
  setTimeout(() => el.classList.add('toast-exit'), 3500);
  setTimeout(() => el.remove(), 4000);
}

function closeModal(id) { const el = document.getElementById(id); if (el) el.style.display = 'none'; }
function show(id) { const el = document.getElementById(id); if (el) el.style.display = ''; }
function hide(id) { const el = document.getElementById(id); if (el) el.style.display = 'none'; }
function setEl(id, v) { const el = document.getElementById(id); if (el) el.textContent = v; }
function ucfirst(s) { return s ? s.charAt(0).toUpperCase() + s.slice(1) : s; }
function today()  { return new Date().toISOString().split('T')[0]; }

function fmtDate(s) {
  if (!s) return '—';
  return new Date(s).toLocaleDateString('en-PH', { year: 'numeric', month: 'short', day: 'numeric' });
}
function fmtDT(s) {
  if (!s) return '—';
  return new Date(s).toLocaleString('en-PH', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
}
function docLabel(t) {
  return {
    resume: '📄 Resume', endorsement: '📜 Endorsement', application: '📝 Application',
    nda: '🔒 NDA', waiver: '⚠️ Waiver', medical: '🏥 Medical', other: '📁 Other'
  }[t] || t;
}
