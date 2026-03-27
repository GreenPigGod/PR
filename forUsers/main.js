// main.js

'use strict';

// ── 定数 ─────────────────────────────────────────
const API         = '';             // forUsers/ 基準の相対パス
const SESSION_KEY = 'app_session';
const MOCK_MODE   = false;          // true にするとモックデータで動作（認証不要）

// ── セッション ────────────────────────────────────
function getSession() {
    return localStorage.getItem(SESSION_KEY) || '';
}

function authHeaders() {
    return {
        'Content-Type': 'application/json',
        'Authorization': 'Bearer ' + getSession(),
    };
}

// ── API 共通 ──────────────────────────────────────
async function apiFetch(path, options = {}) {
    const res = await fetch(API + path, {
        ...options,
        headers: { ...authHeaders(), ...(options.headers || {}) },
    });
    const text = await res.text();
    if (!res.ok) {
        let msg = `HTTP ${res.status}`;
        try { msg = JSON.parse(text).error || msg; } catch (_) {}
        throw new Error(msg);
    }
    return text ? JSON.parse(text) : {};
}

async function apiPost(path, body) {
    return apiFetch(path, { method: 'POST', body: JSON.stringify(body) });
}


// ── アプリ状態 ────────────────────────────────────
let appData = null; // { categories: [...], incompleteEvents: [...] }

// ── DOM 参照 ──────────────────────────────────────
let loginSection, mainSection, headerActions, categoriesEl, statusEl, syncBtn, logoutBtn;

// ── 初期化 ────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    loginSection  = document.getElementById('loginSection');
    mainSection   = document.getElementById('mainSection');
    headerActions = document.getElementById('headerActions');
    categoriesEl  = document.getElementById('categories');
    statusEl      = document.getElementById('status');
    syncBtn       = document.getElementById('syncBtn');
    logoutBtn     = document.getElementById('logoutBtn');

    syncBtn.addEventListener('click', loadData);
    logoutBtn.addEventListener('click', doLogout);

    if (MOCK_MODE || getSession()) {
        showMain();
        loadData();
    } else {
        showLogin();
    }
});

function showLogin() {
    loginSection.removeAttribute('hidden');
    mainSection.setAttribute('hidden', '');
    headerActions.setAttribute('hidden', '');
}

function showMain() {
    loginSection.setAttribute('hidden', '');
    mainSection.removeAttribute('hidden');
    headerActions.removeAttribute('hidden');
}

async function doLogout() {
    try { await apiPost('api/logout.php', {}); } catch (_) {}
    localStorage.removeItem(SESSION_KEY);
    categoriesEl.innerHTML = '';
    showLogin();
}

// ── データ取得 ────────────────────────────────────
async function loadData() {
    setStatus('読み込み中…');
    try {
        const data = await apiFetch(MOCK_MODE ? 'syncTasks_mock.php' : 'api/lw_sync.php', {
            method: MOCK_MODE ? 'GET' : 'POST',
            body:   MOCK_MODE ? undefined : '{}',
        });
        appData = data;
        renderAll(data);
        setStatus('');
    } catch (e) {
        setStatus('読み込み失敗: ' + e.message);
        if (e.message.startsWith('HTTP 401') || e.message === 'invalid_session' || e.message === 'expired_session') {
            localStorage.removeItem(SESSION_KEY);
            showLogin();
        }
    }
}

function setStatus(msg) {
    statusEl.textContent = msg;
}

// ── 描画 ──────────────────────────────────────────
function renderAll(data) {
    categoriesEl.innerHTML = '';

    (data.categories || []).forEach(cat => {
        categoriesEl.appendChild(buildCategoryEl(cat));
    });

    const incomplete = data.incompleteEvents || [];
    if (incomplete.length > 0) {
        categoriesEl.appendChild(buildIncompleteSectionEl(incomplete));
    }
}

function buildCategoryEl(cat) {
    const wrap = document.createElement('div');
    wrap.className = 'category';

    // ヘッダー（折りたたみ）
    const header = document.createElement('div');
    header.className = 'category-header';
    header.innerHTML = '';

    const left = document.createElement('div');
    left.className = 'category-header-left';

    const chevron = document.createElement('span');
    chevron.className = 'chevron';
    chevron.textContent = '▶';

    const name = document.createElement('span');
    name.className = 'category-name';
    name.textContent = cat.categoryName || '(無名)';

    const count = document.createElement('span');
    count.className = 'category-count';
    count.textContent = (cat.tasks || []).length + '件';

    left.append(chevron, name, count);
    header.appendChild(left);
    wrap.appendChild(header);

    // ボディ
    const body = document.createElement('div');
    body.className = 'category-body';

    const taskList = document.createElement('div');
    taskList.className = 'task-list';
    (cat.tasks || []).forEach(task => taskList.appendChild(buildTaskEl(task)));
    body.appendChild(taskList);
    wrap.appendChild(body);

    // 折りたたみ toggle
    header.addEventListener('click', () => {
        const open = body.classList.toggle('open');
        chevron.textContent = open ? '▼' : '▶';
    });

    return wrap;
}

function buildTaskEl(task) {
    const wrap = document.createElement('div');
    wrap.className = 'task-item' + (task.completed === 'DONE' ? ' done' : '');

    const header = document.createElement('div');
    header.className = 'task-header';

    const nameEl = document.createElement('span');
    nameEl.className = 'task-name';
    nameEl.textContent = task.taskName || '';

    const meta = document.createElement('div');
    meta.className = 'task-meta-row';

    if (task.deadline) {
        const dl = document.createElement('span');
        dl.className = 'task-deadline';
        dl.textContent = '期限: ' + task.deadline;
        meta.appendChild(dl);
    }

    const nameWrap = document.createElement('div');
    nameWrap.className = 'task-name-wrap';
    nameWrap.append(nameEl, meta);

    const evs = task.events || [];
    const evToggle = document.createElement('button');
    evToggle.className = 'btn btn-sm btn-events-toggle';
    evToggle.textContent = '予定 (' + evs.length + ')';

    const actions = document.createElement('div');
    actions.className = 'task-actions';
    actions.appendChild(evToggle);

    header.append(nameWrap, actions);
    wrap.appendChild(header);

    // 予定セクション（折りたたみ）
    const evSection = document.createElement('div');
    evSection.className = 'event-section';
    evSection.setAttribute('hidden', '');

    const evList = document.createElement('div');
    evList.className = 'event-list';
    evs.forEach(ev => evList.appendChild(buildEventEl(ev)));
    evSection.appendChild(evList);

    evToggle.addEventListener('click', () => {
        const isHidden = evSection.hasAttribute('hidden');
        if (isHidden) {
            evSection.removeAttribute('hidden');
            evToggle.classList.add('active');
        } else {
            evSection.setAttribute('hidden', '');
            evToggle.classList.remove('active');
        }
    });

    wrap.appendChild(evSection);

    return wrap;
}

function buildEventEl(ev) {
    const row = document.createElement('div');
    row.className = 'event-row';

    const info = document.createElement('div');
    info.className = 'event-info';

    const titleEl = document.createElement('span');
    titleEl.className = 'event-title';
    titleEl.textContent = ev.title;

    const timeEl = document.createElement('span');
    timeEl.className = 'event-time';
    timeEl.textContent = fmtDt(ev.from) + ' 〜 ' + fmtDt(ev.until);

    info.append(titleEl, timeEl);

    if (ev.memo) {
        const memo = document.createElement('span');
        memo.className = 'event-memo';
        memo.textContent = ev.memo;
        info.appendChild(memo);
    }

    row.appendChild(info);
    return row;
}

function buildIncompleteSectionEl(events) {
    const wrap = document.createElement('div');
    wrap.className = 'category';

    const header = document.createElement('div');
    header.className = 'category-header';

    const left = document.createElement('div');
    left.className = 'category-header-left';

    const chevron = document.createElement('span');
    chevron.className = 'chevron';
    chevron.textContent = '▶';

    const name = document.createElement('span');
    name.className = 'category-name';
    name.textContent = 'LINEWORKSで作成した予定';

    const count = document.createElement('span');
    count.className = 'category-count';
    count.textContent = events.length + '件';

    left.append(chevron, name, count);
    header.appendChild(left);
    wrap.appendChild(header);

    const body = document.createElement('div');
    body.className = 'category-body';

    const evList = document.createElement('div');
    evList.className = 'event-list';

    events.forEach(ev => evList.appendChild(buildEventEl(ev)));

    body.appendChild(evList);
    wrap.appendChild(body);

    header.addEventListener('click', () => {
        const open = body.classList.toggle('open');
        chevron.textContent = open ? '▼' : '▶';
    });

    return wrap;
}

// ── ユーティリティ ─────────────────────────────────
function pad(n) {
    return String(n).padStart(2, '0');
}

function fmtDt(str) {
    if (!str) return '';
    try {
        const d = new Date(str);
        if (isNaN(d.getTime())) return str;
        return d.getFullYear() + '/' + pad(d.getMonth() + 1) + '/' + pad(d.getDate())
             + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes());
    } catch (_) { return str; }
}


