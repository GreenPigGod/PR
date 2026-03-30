// main.js
'use strict';

// ── 定数 ─────────────────────────────────────────
const SESSION_KEY = 'app_session';
const MOCK_MODE   = false;
const CATEGORIES  = ['商談', '修理', '巡回', 'その他'];

// ── セッション ────────────────────────────────────
function getSession() { return localStorage.getItem(SESSION_KEY) || ''; }
function authHeaders() {
    return {
        'Content-Type': 'application/json',
        'Authorization': 'Bearer ' + getSession(),
    };
}

// ── API 共通 ──────────────────────────────────────
async function apiFetch(path, options = {}) {
    const res = await fetch(path, {
        ...options,
        headers: { ...authHeaders(), ...(options.headers || {}) },
    });
    const text = await res.text();
    if (!res.ok) {
        let msg = 'HTTP ' + res.status;
        try { msg = JSON.parse(text).error || msg; } catch (_) {}
        throw new Error(msg);
    }
    return text ? JSON.parse(text) : {};
}

async function apiPost(path, body) {
    return apiFetch(path, { method: 'POST', body: JSON.stringify(body) });
}

// ── アプリ状態 ────────────────────────────────────
let appData = null;

// ── DOM 参照 ──────────────────────────────────────
let loginSection, mainSection, headerActions, categoriesEl, statusEl, syncBtn, logoutBtn, newTaskBtn;
let taskModal, taskModalTitle, taskForm;
let eventModal, eventModalTitle, eventForm;

// ── 初期化 ────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    loginSection    = document.getElementById('loginSection');
    mainSection     = document.getElementById('mainSection');
    headerActions   = document.getElementById('headerActions');
    categoriesEl    = document.getElementById('categories');
    statusEl        = document.getElementById('status');
    syncBtn         = document.getElementById('syncBtn');
    logoutBtn       = document.getElementById('logoutBtn');
    newTaskBtn      = document.getElementById('newTaskBtn');
    taskModal       = document.getElementById('taskModal');
    taskModalTitle  = document.getElementById('taskModalTitle');
    taskForm        = document.getElementById('taskForm');
    eventModal      = document.getElementById('eventModal');
    eventModalTitle = document.getElementById('eventModalTitle');
    eventForm       = document.getElementById('eventForm');

    syncBtn.addEventListener('click', loadData);
    logoutBtn.addEventListener('click', doLogout);
    newTaskBtn.addEventListener('click', () => openTaskModal(null, null));

    document.getElementById('taskModalClose').addEventListener('click', closeTaskModal);
    document.getElementById('taskCancelBtn').addEventListener('click', closeTaskModal);
    taskModal.addEventListener('click', e => { if (e.target === taskModal) closeTaskModal(); });
    taskForm.addEventListener('submit', handleTaskSubmit);

    document.getElementById('eventModalClose').addEventListener('click', closeEventModal);
    document.getElementById('eventCancelBtn').addEventListener('click', closeEventModal);
    eventModal.addEventListener('click', e => { if (e.target === eventModal) closeEventModal(); });
    eventForm.addEventListener('submit', handleEventSubmit);

    // 気持ちボタン
    eventForm.querySelectorAll('.emo-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            eventForm.querySelectorAll('.emo-btn').forEach(b => b.classList.remove('selected'));
            btn.classList.add('selected');
            eventForm.querySelector('[name=emo_score]').value = btn.dataset.score;
        });
    });

    if (MOCK_MODE || getSession()) {
        showMain();
        loadData();
    } else {
        showLogin();
    }
});

// ── 表示切替 ──────────────────────────────────────
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

// ── ログアウト ────────────────────────────────────
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

function setStatus(msg) { statusEl.textContent = msg; }

// ── 描画 ──────────────────────────────────────────
function renderAll(data) {
    categoriesEl.innerHTML = '';

    const completedTasks = [];

    (data.categories || []).forEach(cat => {
        const incompleteTasks = (cat.tasks || []).filter(t => t.completed !== 'DONE');
        const doneTasks       = (cat.tasks || []).filter(t => t.completed === 'DONE');
        doneTasks.forEach(t => completedTasks.push({ task: t, cat }));

        if (incompleteTasks.length > 0) {
            categoriesEl.appendChild(buildCategoryEl({ ...cat, tasks: incompleteTasks }));
        }
    });

    if (completedTasks.length > 0) {
        categoriesEl.appendChild(buildCompletedSectionEl(completedTasks));
    }

    const incomplete = data.incompleteEvents || [];
    if (incomplete.length > 0) {
        categoriesEl.appendChild(buildIncompleteSectionEl(incomplete));
    }
}

function buildCategoryEl(cat) {
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
    name.textContent = cat.categoryName || '(無名)';

    const count = document.createElement('span');
    count.className = 'category-count';
    count.textContent = (cat.tasks || []).length + '件';

    left.append(chevron, name, count);
    header.appendChild(left);
    wrap.appendChild(header);

    const body = document.createElement('div');
    body.className = 'category-body';

    const taskList = document.createElement('div');
    taskList.className = 'task-list';
    (cat.tasks || []).forEach(task => taskList.appendChild(buildTaskEl(task, cat)));
    body.appendChild(taskList);
    wrap.appendChild(body);

    header.addEventListener('click', () => {
        const open = body.classList.toggle('open');
        chevron.textContent = open ? '▼' : '▶';
    });

    return wrap;
}

function buildTaskEl(task, cat) {
    const wrap = document.createElement('div');
    wrap.className = 'task-item' + (task.completed === 'DONE' ? ' done' : '');

    const header = document.createElement('div');
    header.className = 'task-header';

    const checkbox = document.createElement('input');
    checkbox.type = 'checkbox';
    checkbox.className = 'task-checkbox';
    checkbox.checked = task.completed === 'DONE';
    checkbox.addEventListener('change', () => toggleComplete(task, checkbox.checked));

    const nameEl = document.createElement('span');
    nameEl.className = 'task-name task-name-link';
    nameEl.textContent = task.taskName || '';
    nameEl.addEventListener('click', () => openTaskModal(task, cat));

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
    evToggle.type = 'button';
    evToggle.className = 'btn btn-sm btn-events-toggle';
    evToggle.textContent = '予定 (' + evs.length + ')';

    const actions = document.createElement('div');
    actions.className = 'task-actions';
    actions.appendChild(evToggle);

    header.append(checkbox, nameWrap, actions);
    wrap.appendChild(header);

    // 予定セクション（折りたたみ）
    const evSection = document.createElement('div');
    evSection.className = 'event-section';
    evSection.setAttribute('hidden', '');

    const evList = document.createElement('div');
    evList.className = 'event-list';
    evs.forEach(ev => evList.appendChild(buildEventEl(ev, task)));
    evSection.appendChild(evList);

    const addEvBtn = document.createElement('button');
    addEvBtn.type = 'button';
    addEvBtn.className = 'btn btn-sm btn-add-event';
    addEvBtn.textContent = '＋ 予定追加';
    addEvBtn.addEventListener('click', () => openEventModal(null, task));
    evSection.appendChild(addEvBtn);

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

function buildEventEl(ev, task) {
    const row = document.createElement('div');
    row.className = 'event-row event-row-clickable';

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
    row.addEventListener('click', () => openEventModal(ev, task));
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
    events.forEach(ev => evList.appendChild(buildEventEl(ev, null)));
    body.appendChild(evList);
    wrap.appendChild(body);

    header.addEventListener('click', () => {
        const open = body.classList.toggle('open');
        chevron.textContent = open ? '▼' : '▶';
    });

    return wrap;
}

// ── 完了済みセクション ────────────────────────────
function buildCompletedSectionEl(completedTasks) {
    const wrap = document.createElement('div');
    wrap.className = 'category category-completed';

    const header = document.createElement('div');
    header.className = 'category-header';

    const left = document.createElement('div');
    left.className = 'category-header-left';

    const chevron = document.createElement('span');
    chevron.className = 'chevron';
    chevron.textContent = '▶';

    const name = document.createElement('span');
    name.className = 'category-name';
    name.textContent = '完了済みのタスク';

    const count = document.createElement('span');
    count.className = 'category-count';
    count.textContent = completedTasks.length + '件';

    left.append(chevron, name, count);
    header.appendChild(left);
    wrap.appendChild(header);

    const body = document.createElement('div');
    body.className = 'category-body';

    const taskList = document.createElement('div');
    taskList.className = 'task-list';
    completedTasks.forEach(({ task, cat }) => taskList.appendChild(buildTaskEl(task, cat)));
    body.appendChild(taskList);
    wrap.appendChild(body);

    header.addEventListener('click', () => {
        const open = body.classList.toggle('open');
        chevron.textContent = open ? '▼' : '▶';
    });

    return wrap;
}

// ── 完了トグル ────────────────────────────────────
async function toggleComplete(task, isDone) {
    try {
        await apiPost('../setTaskCompletion.php', {
            taskId:    task.taskId,
            completed: isDone,
        });
        await loadData();
    } catch (err) {
        alert('エラー: ' + err.message);
    }
}

// ── タスクモーダル ────────────────────────────────
let currentTask = null;
let currentCat  = null;

function openTaskModal(task, cat) {
    currentTask = task;
    currentCat  = cat;
    taskModalTitle.textContent = task ? 'タスクを編集' : 'タスクを作成';

    let customer = '', requirement = '', note = '', dueDate = '', category = 'その他', juchuNum = '';

    if (task) {
        const parts = (task.taskName || '').split('@');
        customer    = parts[0] || '';
        requirement = parts.slice(1).join('@') || '';
        note        = task.content || '';
        dueDate     = task.deadline || '';
        category    = cat ? (cat.categoryName || 'その他') : 'その他';
        juchuNum    = task.juchuNum || '';
    }

    const f = taskForm;
    f.querySelector('[name=customer]').value    = customer;
    f.querySelector('[name=requirement]').value = requirement;
    f.querySelector('[name=note]').value        = note;
    f.querySelector('[name=dueDate]').value     = dueDate;
    f.querySelector('[name=juchu_num]').value   = juchuNum;

    // カテゴリ: プリセット外の値は先頭に動的追加
    const sel = f.querySelector('[name=category]');
    sel.querySelectorAll('[data-dynamic]').forEach(o => o.remove());
    if (category && !CATEGORIES.includes(category)) {
        const opt = document.createElement('option');
        opt.value = category;
        opt.textContent = category;
        opt.dataset.dynamic = '1';
        sel.insertBefore(opt, sel.firstChild);
    }
    sel.value = category;

    taskModal.removeAttribute('hidden');
    f.querySelector('[name=customer]').focus();
}

function closeTaskModal() {
    taskModal.setAttribute('hidden', '');
    currentTask = null;
    currentCat  = null;
}

async function handleTaskSubmit(e) {
    e.preventDefault();
    const f = taskForm;
    const customer    = f.querySelector('[name=customer]').value.trim();
    const requirement = f.querySelector('[name=requirement]').value.trim();
    const note        = f.querySelector('[name=note]').value.trim();
    const dueDate     = f.querySelector('[name=dueDate]').value || null;
    const category    = f.querySelector('[name=category]').value;
    const juchuNum    = f.querySelector('[name=juchu_num]').value.trim();

    if (!customer || !requirement) {
        alert('顧客名と用件は必須です');
        return;
    }

    const title = customer + '@' + requirement;
    const submitBtn = f.querySelector('[type=submit]');
    submitBtn.disabled = true;
    submitBtn.textContent = '送信中…';

    try {
        if (currentTask) {
            await apiPost('../updateTask.php', {
                taskId:    currentTask.taskId,
                title,
                content:   note || title,
                dueDate,
                category,
                juchu_num: juchuNum,
            });
        } else {
            await apiPost('../createTask.php', {
                title,
                content:   note || title,
                dueDate,
                category,
                juchu_num: juchuNum,
            });
        }
        closeTaskModal();
        await loadData();
    } catch (err) {
        alert('エラー: ' + err.message);
    } finally {
        submitBtn.disabled = false;
        submitBtn.textContent = '保存';
    }
}

// ── 予定モーダル ──────────────────────────────────
let currentEvent     = null;
let currentEventTask = null;

function openEventModal(ev, task) {
    currentEvent     = ev;
    currentEventTask = task;
    eventModalTitle.textContent = ev ? '予定を編集' : '予定を追加';

    const f = eventForm;
    f.querySelector('[name=evTitle]').value = ev ? ev.title : (task ? task.taskName : '');
    f.querySelector('[name=evStartDate]').value = ev ? toDatePart(ev.from)  : '';
    f.querySelector('[name=evStartTime]').value = ev ? toTimePart(ev.from)  : '';
    f.querySelector('[name=evEndDate]').value   = ev ? toDatePart(ev.until) : '';
    f.querySelector('[name=evEndTime]').value   = ev ? toTimePart(ev.until) : '';
    f.querySelector('[name=evNote]').value  = ev ? (ev.memo || '') : '';

    const emoScore = ev ? (ev.emo_score || 0) : 0;
    f.querySelector('[name=emo_score]').value = emoScore;
    f.querySelectorAll('.emo-btn').forEach(btn => {
        btn.classList.toggle('selected', parseInt(btn.dataset.score) === emoScore);
    });

    eventModal.removeAttribute('hidden');
}

function closeEventModal() {
    eventModal.setAttribute('hidden', '');
    currentEvent     = null;
    currentEventTask = null;
}

async function handleEventSubmit(e) {
    e.preventDefault();
    const f = eventForm;
    const title     = f.querySelector('[name=evTitle]').value.trim();
    const startDate = f.querySelector('[name=evStartDate]').value;
    const startTime = f.querySelector('[name=evStartTime]').value;
    const endDate   = f.querySelector('[name=evEndDate]').value;
    const endTime   = f.querySelector('[name=evEndTime]').value;
    const note      = f.querySelector('[name=evNote]').value.trim();
    const emoScore  = parseInt(f.querySelector('[name=emo_score]').value) || 0;

    if (!startDate || !startTime || !endDate || !endTime) {
        alert('開始・終了の日付と時刻は必須です');
        return;
    }

    const start = startDate + 'T' + startTime;
    const end   = endDate   + 'T' + endTime;

    const submitBtn = f.querySelector('[type=submit]');
    submitBtn.disabled = true;
    submitBtn.textContent = '送信中…';

    try {
        if (currentEvent) {
            // updateEvent.php: startDateTime/endDateTime "YYYY-MM-DDTHH:MM:SS"
            const updatePayload = {
                eventId:       currentEvent.eventId,
                calendarId:    currentEvent.calendarId,
                title,
                startDateTime: start + ':00',
                endDateTime:   end   + ':00',
                note,
                emo_score:     emoScore,
            };
            console.log('[updateEvent] payload:', updatePayload);
            await apiPost('../updateEvent.php', updatePayload);
        } else {
            // createEvent.php: start/end ISO形式 "YYYY-MM-DDTHH:MM:SS+09:00"
            await apiPost('../createEvent.php', {
                title,
                start:  start + ':00+09:00',
                end:    end   + ':00+09:00',
                note,
                taskId: currentEventTask ? currentEventTask.taskId : null,
            });
        }
        closeEventModal();
        await loadData();
    } catch (err) {
        alert('エラー: ' + err.message);
    } finally {
        submitBtn.disabled = false;
        submitBtn.textContent = '保存';
    }
}

// ── ユーティリティ ─────────────────────────────────
function pad(n) { return String(n).padStart(2, '0'); }

function fmtDt(str) {
    if (!str) return '';
    try {
        const d = new Date(str.replace(' ', 'T'));
        if (isNaN(d.getTime())) return str;
        return d.getFullYear() + '/' + pad(d.getMonth() + 1) + '/' + pad(d.getDate())
             + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes());
    } catch (_) { return str; }
}

// "2026-03-30 10:00:00" → "2026-03-30"
function toDatePart(str) {
    if (!str) return '';
    return str.substring(0, 10);
}

// "2026-03-30 10:00:00" → "10:00"
function toTimePart(str) {
    if (!str) return '';
    const s = str.replace(' ', 'T');
    return s.length >= 16 ? s.substring(11, 16) : '';
}
