/*
 * The ground pages' offline half, ported from Rihla's leader portal. Every
 * tap goes into IndexedDB first and the screen moves at once; the queue is
 * posted when there is a signal, in order, and each item is idempotent on
 * the server (a mark by count and person with the phone's time deciding, an
 * incident or a cash receipt by a uuid made here). A service worker keeps
 * the pages themselves available with no signal. The store holds names, so
 * it is cleared when the person signs out.
 */
const DB_NAME = 'hijaz-ground';
const OUTBOX = 'outbox';
const SNAPSHOTS = 'snapshots';

function openDatabase() {
    return new Promise((resolve, reject) => {
        const request = indexedDB.open(DB_NAME, 1);
        request.onupgradeneeded = () => {
            const db = request.result;
            if (!db.objectStoreNames.contains(OUTBOX)) db.createObjectStore(OUTBOX, { keyPath: 'id', autoIncrement: true });
            if (!db.objectStoreNames.contains(SNAPSHOTS)) db.createObjectStore(SNAPSHOTS, { keyPath: 'trip' });
        };
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });
}

function transact(db, store, mode, work) {
    return new Promise((resolve, reject) => {
        const tx = db.transaction(store, mode);
        const result = work(tx.objectStore(store));
        tx.oncomplete = () => resolve(result && result.result !== undefined ? result.result : result);
        tx.onerror = () => reject(tx.error);
        tx.onabort = () => reject(tx.error);
    });
}

async function queue(item) { const db = await openDatabase(); return transact(db, OUTBOX, 'readwrite', (s) => s.add(item)); }
async function queued() {
    const db = await openDatabase();
    return new Promise((resolve, reject) => {
        const request = db.transaction(OUTBOX, 'readonly').objectStore(OUTBOX).getAll();
        request.onsuccess = () => resolve(request.result || []);
        request.onerror = () => reject(request.error);
    });
}
async function forget(ids) { if (ids.length === 0) return; const db = await openDatabase(); return transact(db, OUTBOX, 'readwrite', (s) => { ids.forEach((id) => s.delete(id)); }); }
async function rememberSnapshot(trip, payload) { const db = await openDatabase(); return transact(db, SNAPSHOTS, 'readwrite', (s) => s.put({ trip: Number(trip), payload, at: new Date().toISOString() })); }

function status(headline, detail) {
    const banner = document.querySelector('[data-ground-status]');
    if (!banner) return;
    if (!headline) { banner.hidden = true; return; }
    banner.hidden = false;
    banner.querySelector('[data-ground-status-headline]').textContent = headline;
    banner.querySelector('[data-ground-status-detail]').textContent = detail || '';
}

async function reportQueue() {
    let waiting = [];
    try { waiting = await queued(); } catch (e) {
        status('This phone will not keep a local copy', 'Taps are sent as you make them. Do not rely on this page without a signal.');
        return;
    }
    if (waiting.length === 0) { status(navigator.onLine ? null : 'No signal', 'Everything you tapped is already sent.'); return; }
    status(waiting.length === 1 ? '1 item waiting to send' : `${waiting.length} items waiting to send`, navigator.onLine ? 'Sending…' : 'They go as soon as there is a signal. Keep this page open.');
}

let sending = false;

async function flush() {
    if (sending || !navigator.onLine) return;
    const page = document.querySelector('[data-sync-url]');
    if (!page) return;
    let items = [];
    try { items = await queued(); } catch (e) { return; }
    if (items.length === 0) { await reportQueue(); return; }
    sending = true;
    try {
        const response = await fetch(page.dataset.syncUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
            body: JSON.stringify({ items }),
        });
        if (!response.ok) { status('Could not send yet', 'Everything is still saved on this phone. Sign in again if this keeps happening.'); return; }
        const body = await response.json();
        const done = []; const refused = [];
        (body.results || []).forEach((r) => { if (r.result === 'refused') refused.push(r); else done.push(r.id); });
        await forget(done);
        if (refused.length > 0) {
            await forget(refused.map((r) => r.id));
            status('Some items were not accepted', refused[0].reason || 'Ask the office.');
            return;
        }
        await reportQueue();
    } catch (e) {
        status('No signal', 'Saved on this phone; it goes when there is one.');
    } finally { sending = false; }
}

function paint(row, state) {
    row.dataset.groundState = state;
    row.querySelectorAll('[data-ground-mark]').forEach((button) => {
        const chosen = button.dataset.groundMark === state;
        button.className = 'min-h-11 rounded-lg border px-2 text-sm font-medium ' + (chosen ? 'border-brand-green-dark bg-brand-green-light text-brand-green-dark' : 'border-brand-sky text-brand-muted');
    });
    const label = row.querySelector('[data-ground-mark-status]');
    if (label) label.textContent = { present: 'Here', absent: 'Not here', excused: 'Excused' }[state] || 'Not counted';
    const tally = document.querySelector('[data-ground-tally]');
    if (tally) {
        const rows = Array.from(document.querySelectorAll('[data-ground-traveller]'));
        const count = (s) => rows.filter((r) => r.dataset.groundState === s).length;
        tally.textContent = `${count('present')} here · ${count('absent')} not here · ${count('excused')} excused · ${count('')} not counted`;
    }
}

function wireCount() {
    const page = document.querySelector('[data-ground-count]');
    if (!page) return;
    const rollCallId = Number(page.dataset.groundCount);
    page.querySelectorAll('[data-ground-traveller]').forEach((row) => {
        const id = Number(row.dataset.groundTraveller);
        row.querySelectorAll('[data-ground-mark]').forEach((button) => {
            button.addEventListener('click', async () => {
                const state = button.dataset.groundMark;
                paint(row, state);
                try { await queue({ type: 'mark', roll_call_id: rollCallId, booking_pilgrim_id: id, state, marked_at: new Date().toISOString() }); }
                catch (e) { status('This phone will not keep a local copy', 'Sending directly instead.'); }
                await reportQueue();
                flush();
            });
        });
    });
}

function wireTrip() {
    const page = document.querySelector('[data-ground-trip]');
    if (!page) return;
    const tripId = Number(page.dataset.groundTrip);

    // The incident form queues when the phone keeps a store, so it works with no signal; otherwise it posts as a form.
    const form = page.querySelector('[data-ground-incident]');
    if (form) {
        form.addEventListener('submit', async (event) => {
            if (navigator.onLine) return;
            event.preventDefault();
            const data = Object.fromEntries(new FormData(form).entries());
            try {
                await queue({ type: 'incident', trip_id: tripId, client_uuid: data.client_uuid, severity: data.severity, category: data.category, booking_pilgrim_id: data.booking_pilgrim_id || null, summary: data.summary, location: data.location, detail: data.detail, happened_at: new Date().toISOString() });
                form.reset();
                status('Incident saved on this phone', 'It reaches the office when there is a signal.');
            } catch (e) { status('No signal and no local copy', 'Try again when there is a signal.'); }
        });
    }

    page.querySelectorAll('[data-ground-collect]').forEach((button) => {
        button.addEventListener('click', async () => {
            const row = button.closest('[data-ground-cash]');
            if (!row || !window.confirm('Record ' + button.textContent.trim().replace(/^Paid /, '') + ' received in cash?')) return;
            button.disabled = true;
            button.textContent = 'Recorded';
            button.className = 'min-h-11 whitespace-nowrap rounded-lg border border-brand-sky px-3 font-semibold text-brand-muted';
            try {
                await queue({ type: 'cash', excursion_booking_id: Number(row.dataset.groundCash), amount_minor: Number(button.dataset.amount), client_uuid: crypto.randomUUID ? crypto.randomUUID() : String(Date.now()) + Math.random(), paid_at: new Date().toISOString() });
            } catch (e) { status('This phone will not keep a local copy', 'Record it in the portal instead.'); }
            await reportQueue();
            flush();
        });
    });
}

async function keepSnapshot() {
    const page = document.querySelector('[data-snapshot-url]');
    if (!page || !navigator.onLine) return;
    try {
        const response = await fetch(page.dataset.snapshotUrl, { headers: { Accept: 'application/json' } });
        if (response.ok) await rememberSnapshot(page.dataset.groundTrip, await response.json());
    } catch (e) { /* the page rendered from the server; nothing to say */ }
}

function registerWorker() {
    const body = document.body;
    if (!body.dataset.serviceWorker || !('serviceWorker' in navigator)) return;
    navigator.serviceWorker.register('/ground-sw.js', { scope: '/ground/' }).catch(() => {});
}

export function startGround() {
    document.querySelectorAll('form[action$="/logout"]').forEach((form) => form.addEventListener('submit', () => { indexedDB.deleteDatabase(DB_NAME); }));
    if (!document.querySelector('[data-ground-count], [data-ground-trip], [data-service-worker]')) return;
    registerWorker();
    wireCount();
    wireTrip();
    keepSnapshot();
    reportQueue();
    flush();
    window.addEventListener('online', () => { reportQueue(); flush(); });
    window.addEventListener('offline', reportQueue);
}
