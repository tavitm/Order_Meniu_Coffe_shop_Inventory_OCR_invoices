// Client-side order state shared by index.html and comanda.html.
// Kept in localStorage so it survives moving between the two pages; falls back to memory.
// The visit (table + cart) lives at most 4 hours, so a client coming back later is asked for the table again.
const Cart = (() => {
  const KEYS = { items: 'mocha_cart', table: 'mocha_table', client: 'mocha_client', started: 'mocha_started' };
  const TTL = 4 * 60 * 60 * 1000;
  const mem = {};
  const read = (k, def) => {
    try { const v = localStorage.getItem(k); return v == null ? def : JSON.parse(v); }
    catch { return k in mem ? mem[k] : def; }
  };
  const write = (k, v) => {
    mem[k] = v;
    try { v == null ? localStorage.removeItem(k) : localStorage.setItem(k, JSON.stringify(v)); } catch {}
  };

  const newId = () => {
    const b = new Uint8Array(16);
    crypto.getRandomValues(b);
    return [...b].map(x => x.toString(16).padStart(2, '0')).join('');
  };
  // Start the 4-hour clock at the first table choice or first product added.
  const touch = () => { if (!Number.isFinite(read(KEYS.started, null))) write(KEYS.started, Date.now()); };

  const started = read(KEYS.started, null);
  const expired = Number.isFinite(started) && Date.now() - started > TTL;
  if (expired) {
    write(KEYS.table, null);
    write(KEYS.items, {});
    write(KEYS.started, null);
  }

  const items = () => {
    const v = read(KEYS.items, {});
    return v && typeof v === 'object' ? v : {};
  };
  const setQty = (id, qty) => {
    const all = items();
    if (qty > 0) all[id] = Math.min(50, qty); else delete all[id];
    touch();
    write(KEYS.items, all);
  };

  return {
    items,
    setQty,
    add(id, n = 1) { setQty(id, (items()[id] || 0) + n); },
    clear() { write(KEYS.items, {}); },
    count() { return Object.values(items()).reduce((a, b) => a + b, 0); },
    table() { const t = read(KEYS.table, null); return Number.isInteger(t) ? t : null; },
    setTable(t) { touch(); write(KEYS.table, t); },
    /** True when an older visit (over 4 hours) was cleared on this page load. */
    expired,
    client() {
      let id = read(KEYS.client, null);
      if (typeof id !== 'string' || !/^[a-f0-9]{32}$/.test(id)) {
        id = newId();
        write(KEYS.client, id);
      }
      return id;
    },
    /** New anonymous identity for a new visit (only when nothing is left unpaid on the old one). */
    renewClient() { const id = newId(); write(KEYS.client, id); return id; },
    /** The server still has unpaid orders: keep the visit going from now. */
    keepAlive() { write(KEYS.started, Date.now()); },
  };
})();

/** Price string from menu.json ("14", "15.5") to integer bani; null when not orderable. */
function priceCents(p) {
  const s = String(p ?? '').trim().replace(',', '.');
  return /^\d+(\.\d{1,2})?$/.test(s) ? Math.round(parseFloat(s) * 100) : null;
}

/** 1450 -> "14,50", 1400 -> "14" */
function money(cents) {
  const v = cents / 100;
  return Number.isInteger(v) ? String(v) : v.toFixed(2).replace('.', ',');
}
