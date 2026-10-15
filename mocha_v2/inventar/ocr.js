// Invoice reading on the phone: Tesseract OCR (self-hosted in ocr/), then the table rows are rebuilt from the word
// positions and the product lines, supplier, invoice number and date are guessed. Everything runs in the browser;
// the admin checks the result in the receipt form before anything is saved.
const InvoiceOCR = (() => {
  const BASE = new URL('ocr/', location.href).href;
  let worker = null;
  let starting = null;
  let onProgress = null;

  const loadScript = src => new Promise((resolve, reject) => {
    const s = document.createElement('script');
    s.src = src;
    s.onload = resolve;
    s.onerror = () => reject(new Error('Nu am putut încărca modulul de citire. Verifică conexiunea.'));
    document.head.append(s);
  });

  /** One Tesseract worker for the whole receipt (the engine and the Romanian data load once, ~6 MB, then stay cached). */
  async function getWorker() {
    if (worker) return worker;
    starting ??= (async () => {
      if (!window.Tesseract) await loadScript(BASE + 'tesseract.min.js');
      const w = await Tesseract.createWorker('ron', 1, {
        workerPath: BASE + 'worker.min.js',
        corePath: BASE + 'core',
        langPath: BASE + 'lang',
        workerBlobURL: false, // a same-origin worker keeps the page's security policy simple (no blob: workers)
        logger: m => onProgress && onProgress(m),
      });
      await w.setParameters({ preserve_interword_spaces: '1' });
      worker = w;
      return w;
    })().catch(err => { starting = null; throw err; });
    return starting;
  }

  /** Free the engine's memory (about 100 MB on a phone) when the receipt form closes. */
  async function stop() {
    const w = worker;
    worker = null;
    starting = null;
    if (w) await w.terminate().catch(() => {});
  }

  /** Photo file -> canvas no larger than maxSide, with the phone's EXIF rotation applied. */
  async function toCanvas(file, maxSide) {
    const url = URL.createObjectURL(file);
    try {
      // onload, not img.decode(): decode() waits for the page to be painted, which may not have happened yet right
      // after coming back from the camera app (it then never resolves).
      const img = new Image();
      await new Promise((resolve, reject) => {
        img.onload = resolve;
        img.onerror = () => reject(new Error('Poza nu poate fi deschisă. Încearcă din nou sau alege alt format (JPG).'));
        img.src = url;
      });
      const scale = Math.min(1, maxSide / Math.max(img.naturalWidth, img.naturalHeight));
      const c = document.createElement('canvas');
      c.width = Math.round(img.naturalWidth * scale);
      c.height = Math.round(img.naturalHeight * scale);
      c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
      return c;
    } finally {
      URL.revokeObjectURL(url);
    }
  }

  /** Smaller JPEG kept with the receipt as the invoice archive. */
  async function archiveCopy(file) {
    const c = await toCanvas(file, 1800);
    return new Promise(resolve => c.toBlob(resolve, 'image/jpeg', 0.8));
  }

  /** Grayscale with the darkest/lightest 1% stretched to black/white: helps with shadows and yellowish paper. */
  function enhance(c) {
    const ctx = c.getContext('2d');
    const img = ctx.getImageData(0, 0, c.width, c.height);
    const d = img.data;
    const hist = new Uint32Array(256);
    for (let i = 0; i < d.length; i += 4) {
      const g = (d[i] * 299 + d[i + 1] * 587 + d[i + 2] * 114) / 1000 | 0;
      d[i] = g;
      hist[g]++;
    }
    const n = d.length / 4;
    let lo = 0, hi = 255, acc = 0;
    while (lo < 255 && (acc += hist[lo]) < n * 0.01) lo++;
    acc = 0;
    while (hi > 0 && (acc += hist[hi]) < n * 0.01) hi--;
    const span = Math.max(1, hi - lo);
    for (let i = 0; i < d.length; i += 4) {
      const v = Math.max(0, Math.min(255, (d[i] - lo) * 255 / span));
      d[i] = d[i + 1] = d[i + 2] = v;
    }
    ctx.putImageData(img, 0, 0);
    return c;
  }

  /** OCR one page: returns the words with their boxes (from Tesseract's TSV output). */
  async function readPage(file, progress) {
    onProgress = progress;
    const w = await getWorker();
    const c = enhance(await toCanvas(file, 2600));
    const { data } = await w.recognize(c, {}, { tsv: true, text: false });
    return { width: c.width, height: c.height, words: parseTSV(data.tsv || '') };
  }

  function parseTSV(tsv) {
    const words = [];
    for (const line of tsv.split('\n')) {
      const f = line.split('\t');
      if (f[0] !== '5' || f.length < 12) continue;
      const text = f.slice(11).join(' ').trim();
      const conf = Number(f[10]);
      if (!text || conf < 0) continue;
      const [x, y, w, h] = f.slice(6, 10).map(Number);
      words.push({ text, conf, x, y, w, h, cx: x + w / 2, cy: y + h / 2, line: f.slice(1, 5).join('-') });
    }
    return words;
  }

  /* ---------- rows ---------- */

  const median = a => { if (!a.length) return 0; const s = [...a].sort((p, q) => p - q); return s[s.length >> 1]; };

  /**
   * Rebuild the visual rows of the page. Tesseract often reads a table column by column; grouping words by height
   * (after correcting a slightly tilted photo) puts each product back on one row, left to right.
   */
  function buildRows(page) {
    const words = page.words;
    if (!words.length) return [];
    // Tilt: median slope of Tesseract's own long text lines.
    const byLine = new Map();
    for (const w of words) (byLine.get(w.line) || byLine.set(w.line, []).get(w.line)).push(w);
    const slopes = [];
    for (const ws of byLine.values()) {
      if (ws.length < 3) continue;
      const xs = ws.map(w => w.cx), ys = ws.map(w => w.cy);
      const mx = xs.reduce((a, b) => a + b) / xs.length, my = ys.reduce((a, b) => a + b) / ys.length;
      let num = 0, den = 0;
      for (let i = 0; i < xs.length; i++) { num += (xs[i] - mx) * (ys[i] - my); den += (xs[i] - mx) ** 2; }
      if (den > 0 && Math.max(...xs) - Math.min(...xs) > page.width * 0.15) slopes.push(num / den);
    }
    const slope = Math.max(-0.15, Math.min(0.15, median(slopes)));
    const items = words.map(w => ({ ...w, ty: w.cy - slope * w.cx })).sort((a, b) => a.ty - b.ty);
    const mh = median(words.map(w => w.h)) || 20;
    const rows = [];
    for (const w of items) {
      const r = rows[rows.length - 1];
      if (r && Math.abs(w.ty - r.ty) < mh * 0.6) {
        r.words.push(w);
        r.ty = r.words.reduce((a, b) => a + b.ty, 0) / r.words.length;
      } else rows.push({ ty: w.ty, words: [w] });
    }
    for (const r of rows) {
      r.words.sort((a, b) => a.x - b.x);
      // Segments: pieces of the row separated by a wide gap (e.g. supplier on the left, client on the right).
      r.segments = [];
      let seg = null;
      for (const w of r.words) {
        if (seg && w.x - seg.x1 < mh * 2.2) { seg.words.push(w); seg.x1 = w.x + w.w; }
        else r.segments.push(seg = { x0: w.x, x1: w.x + w.w, words: [w] });
      }
      for (const s of r.segments) s.text = s.words.map(w => w.text).join(' ');
      r.text = r.words.map(w => w.text).join(' ');
    }
    return rows;
  }

  /* ---------- numbers and product lines ---------- */

  const UNIT = /^(buc|bucati|bucăți|bc|kg|g|gr|l|lt|ltr|litri|ml|bax|baxuri|set|seturi|pach|pachet|pac|pk|cut|cutie|cutii|sticla|sticlă|st|fl|flacon|punga|pungă|doza|doză|cs|lada|ladă|bidon|sac|rola|rolă|pcs|pc|buc\.)\.?$/i;
  const PCT = /^\d{1,2}([.,]\d+)?%$/;

  /** "1.234,56" / "12,5" / "24.000" / "2,10" -> number; null when the token is not a number. Fixes O/l read instead of 0/1. */
  function num(t) {
    if (!/\d/.test(t)) return null;
    let s = t.replace(/^[(\[]|[)\]:;]$/g, '');
    if (!/^-?[\dOolI|.,]+$/.test(s)) return null;
    s = s.replace(/[Oo]/g, '0').replace(/[lI|]/g, '1');
    const neg = s.startsWith('-');
    s = s.replace(/^-/, '');
    if (s.includes(',') && s.includes('.')) {
      const dec = s.lastIndexOf(',') > s.lastIndexOf('.') ? ',' : '.';
      s = s.split(dec === ',' ? '.' : ',').join('').replace(dec, '.');
    } else if (s.includes(',')) {
      const parts = s.split(',');
      s = parts.length > 2 ? parts.slice(0, -1).join('') + '.' + parts[parts.length - 1] : s.replace(',', '.');
    } else if ((s.match(/\./g) || []).length > 1) {
      const parts = s.split('.');
      s = parts[parts.length - 1].length === 3 ? parts.join('') : parts.slice(0, -1).join('') + '.' + parts[parts.length - 1];
    }
    if (!/^\d+(\.\d+)?$/.test(s)) return null;
    const v = parseFloat(s);
    return neg ? -v : v;
  }

  const SKIP = /\b(total|subtotal|de plat[aă]|semn[aă]tur|cota|baza|sold|transport\s+total|nr\.?\s*crt|denumire|cantitate|pre[tț]\s+unitar|u\.?\s*m\.?$)/i;
  const CONTACT = /\b(telefon|tel\.?|fax|e-?mail|iban|cont|banca|cif|cui|reg\.?\s*com|adresa|cod\s+fiscal)\b/i;
  const IGNORE = /\b(sgr|garan[tț]ie|ambalaj|transport|tax[aă]|discount|reducere)\b/i;

  /**
   * A product row: name, then unit / quantity / unit price / value (+ VAT columns).
   * Quantity and price are the pair whose product matches a later value on the row; without such a match the row is
   * still offered, marked for checking.
   */
  function parseLine(row) {
    const toks = row.words.map(w => w.text);
    const kind = toks.map(t => PCT.test(t) ? 'pct' : num(t) !== null ? 'num' : UNIT.test(t) ? 'unit' : 'word');
    let z = toks.length;
    while (z > 0 && kind[z - 1] !== 'word') z--;
    const nums = [];
    for (let i = z; i < toks.length; i++) if (kind[i] === 'num') nums.push({ i, v: num(toks[i]) });
    if (nums.length < 2) return null;
    let qi = -1, pi = -1, value = null;
    search:
    for (let a = 0; a < nums.length; a++) {
      for (let b = a + 1; b < nums.length; b++) {
        for (let c = b + 1; c < nums.length; c++) {
          const want = nums[a].v * nums[b].v;
          if (nums[a].v > 0 && nums[b].v > 0 && Math.abs(want - nums[c].v) <= Math.max(0.02, Math.abs(want) * 0.015)) {
            qi = a; pi = b; value = nums[c].v;
            break search;
          }
        }
      }
    }
    const checked = qi >= 0;
    // Quantity unreadable (smudged digit)? Unit price and value are usually still there: quantity = value / price.
    let derived = null;
    if (!checked) {
      for (let b = 0; b < nums.length && !derived; b++) {
        for (let c = b + 1; c < nums.length; c++) {
          const r = nums[c].v / nums[b].v;
          if (nums[b].v > 0 && r >= 1 && r <= 100000 && Math.abs(r - Math.round(r)) <= r * 0.002) {
            derived = { qty: Math.round(r), price: nums[b].v, value: nums[c].v, at: nums[b].i };
            break;
          }
        }
      }
    }
    if (!checked && !derived) {
      const lastUnit = toks.slice(z).map((t, k) => kind[z + k] === 'unit' ? z + k : -1).filter(k => k >= 0).pop();
      const after = lastUnit != null ? nums.findIndex(n => n.i > lastUnit) : 0;
      if (after < 0 || after + 1 >= nums.length) return null;
      qi = after; pi = after + 1;
    }
    const qty = derived ? derived.qty : nums[qi].v;
    const price = derived ? derived.price : nums[pi].v;
    if (derived) value = derived.value;
    // Where the quantity column is. When it was unreadable, skip what is left of it (e.g. "<)") back to the unit or the name.
    let qtyAt = derived ? derived.at : nums[qi].i;
    if (derived) while (qtyAt > 0 && kind[qtyAt - 1] !== 'unit' && (toks[qtyAt - 1].length <= 2 || !/\p{L}{2}/u.test(toks[qtyAt - 1]))) qtyAt--;
    let unit = '';
    let nameEnd = qtyAt;
    if (kind[qtyAt - 1] === 'unit') { unit = toks[qtyAt - 1]; nameEnd = qtyAt - 1; }
    else if (kind[qtyAt + 1] === 'unit') unit = toks[qtyAt + 1];
    else {
      const u = toks.slice(z, qtyAt).filter((t, k) => kind[z + k] === 'unit').pop();
      if (u) unit = u;
    }
    // Name: the words before the quantity; leading row numbers / product codes dropped.
    let start = 0;
    while (start < nameEnd - 1 && (kind[start] === 'num' || /^\d+[.)]?$/.test(toks[start]))) start++;
    // The row number column misread as a letter or two ("S" for 5, "șl" for 1).
    if (start === 0 && nameEnd > 1 && toks[0].length <= 2 && /^[\p{L}\d|.)]+$/u.test(toks[0])) start = 1;
    let name = toks.slice(start, nameEnd).join(' ');
    name = name.replace(/\s+[|]\s+/g, ' ').replace(/^[^\p{L}\d]+|[^\p{L}\d%)]+$/gu, '').trim();
    if ((name.match(/\p{L}/gu) || []).length < 3 || SKIP.test(name) || CONTACT.test(row.text)) return null;
    if (!(qty > 0) || qty > 100000 || !(price > 0)) return null;
    return {
      name,
      unit: unit.replace(/\.$/, '').toLowerCase(),
      qty,
      price,
      value: value ?? +(qty * price).toFixed(2),
      checked,
      ignore: IGNORE.test(name),
      text: row.text,
    };
  }

  /* ---------- header: supplier, number, date ---------- */

  const COMPANY = /\b(s\.?\s?r\.?\s?l\.?|s\.?\s?a\.?|s\.?\s?n\.?\s?c\.?|p\.?\s?f\.?\s?a\.?|i\.?\s?i\.?)(\s|$|,)/i;
  const BUYER = /cump[aă]r[aă]tor|client|beneficiar/i;

  function parseHeader(rows, width) {
    const out = { supplier: '', doc_no: '', doc_date: '' };
    const top = rows.slice(0, Math.max(8, Math.ceil(rows.length * 0.45)));
    // Supplier: "Furnizor: ..." or the first company name on the left side of the page.
    for (let i = 0; i < top.length && !out.supplier; i++) {
      for (const s of top[i].segments) {
        const m = s.text.match(/furnizor\w*\s*[:.]?\s*(.*)$/i);
        if (m) {
          const next = top[i + 1]?.segments.find(x => x.x0 < s.x1);
          out.supplier = m[1].trim().length > 2 ? m[1] : next ? next.text : '';
          break;
        }
      }
    }
    if (!out.supplier) {
      const s = top.flatMap(r => r.segments).find(s => s.x0 < width * 0.5 && COMPANY.test(s.text) && !BUYER.test(s.text));
      if (s) out.supplier = s.text;
    }
    out.supplier = out.supplier.replace(/^[^\p{L}\d]+|[^\p{L}\d.)]+$/gu, '').slice(0, 80);

    const all = rows.map(r => r.text);
    const text = all.join('\n');
    const m = text.match(/seria\s*[:.]?\s*([A-Z]{1,8})\s*(?:nr\.?|num[aă]r(?:ul)?)\s*[:.]?\s*(\d{1,10})/i)
      || text.match(/factur[aă](?:\s+fiscal[aă])?\s*(?:nr\.?|num[aă]r(?:ul)?)\s*[:.]?\s*([A-Z]{0,8}[\s-]?\d+(?:[-/]\d+)*)/i)
      || text.match(/\bnr\.?\s*(?:factur[aă]|document)\s*[:.]?\s*([A-Z]{0,8}[\s-]?\d+(?:[-/]\d+)*)/i);
    if (m) out.doc_no = (m[2] ? `${m[1]} ${m[2]}` : m[1]).replace(/\s+/g, ' ').trim().toUpperCase();

    const toIso = (d, mo, y) => {
      y = y.length === 2 ? '20' + y : y;
      const iso = `${y}-${mo.padStart(2, '0')}-${d.padStart(2, '0')}`;
      const t = new Date(iso + 'T12:00:00');
      return !isNaN(t) && t.getMonth() + 1 === Number(mo) && Number(y) > 2000 ? iso : '';
    };
    const dm = text.match(/data\s*(?:factur(?:ii|a|ă)?|emiterii|emitere|documentului)?\s*[:.]?\s*(\d{1,2})[./-](\d{1,2})[./-](\d{2,4})/i);
    if (dm) out.doc_date = toIso(dm[1], dm[2], dm[3]);
    if (!out.doc_date) {
      for (const t of top.map(r => r.text)) {
        const d = t.match(/\b(\d{1,2})[./-](\d{1,2})[./-](20\d{2}|\d{2})\b/);
        if (d && (out.doc_date = toIso(d[1], d[2], d[3]))) break;
      }
    }
    return out;
  }

  /* ---------- matching invoice names to stock items ---------- */

  const norm = s => String(s).normalize('NFD').replace(/\p{M}/gu, '').toLowerCase().replace(/,/g, '.').replace(/[^a-z0-9.%]+/g, ' ').trim();
  const grams = s => {
    const t = ` ${norm(s)} `;
    const g = new Set();
    for (let i = 0; i < t.length - 2; i++) g.add(t.slice(i, i + 3));
    return g;
  };
  const dice = (a, b) => {
    if (!a.size || !b.size) return 0;
    let n = 0;
    for (const x of a) if (b.has(x)) n++;
    return 2 * n / (a.size + b.size);
  };

  /** Pack sizes in a text, in ml or g: "0,25L" / "250 ml" -> 250, "1kg" -> 1000. */
  const sizes = s => {
    const out = new Set();
    for (const m of norm(s).matchAll(/(\d+(?:\.\d+)?)\s*(ml|cl|l|g|gr|kg)\b/g)) {
      const k = { ml: 1, cl: 10, l: 1000, g: 1, gr: 1, kg: 1000 }[m[2]];
      out.add(Math.round(parseFloat(m[1]) * k));
    }
    return out;
  };
  const words = s => norm(s).split(' ').filter(w => w.length > 1 && !/\d/.test(w) && !/^(ml|cl|kg|gr|buc|cu|de|la|si|in)$/.test(w));

  /**
   * Best stock item for an invoice line: first the wording learned from earlier receipts (with its unit conversion,
   * so only for the same invoice unit: 1 BAX and 1 BUC of the same drink convert differently), then the item names:
   * how many words of the item's name appear on the invoice, how similar the texts are, and whether the pack size
   * agrees (0,25L = 250 ml). Returns {item, factor, how} or null.
   */
  function match(name, unit, items, aliases) {
    const g = grams(name);
    let best = null;
    for (const a of aliases) {
      if (!a.name) [a.name, a.unit = ''] = a.text.split(' @');
      if (a.unit !== norm(unit || '')) continue;
      const s = dice(g, a.grams ??= grams(a.name));
      if (s >= 0.72 && (!best || s > best.score)) best = { item: a.item, factor: a.factor, how: 'learned', score: s };
    }
    if (best) return best;
    const have = new Set(words(name));
    const sz = sizes(name);
    for (const it of items) {
      const w = it.words ??= words(it.name);
      if (!w.length) continue;
      const contained = w.filter(x => have.has(x)).length / w.length;
      if (contained < 0.5) continue;
      const isz = it.sizes ??= sizes(it.name + ' ' + (it.variant || ''));
      const sizeScore = sz.size && isz.size ? ([...isz].some(v => sz.has(v)) ? 0.15 : -0.3) : 0;
      const s = 0.6 * contained + 0.4 * dice(g, it.grams ??= grams(it.name + ' ' + (it.variant || ''))) + sizeScore + (it.tracked ? 0.05 : 0);
      if (s >= 0.6 && (!best || s > best.score)) best = { item: it.id, factor: 1000, how: 'guess', score: s };
    }
    return best;
  }

  /* ---------- unit conversion: invoice unit -> stock unit ---------- */

  // One physical item (bottle, can, sack...) vs a box holding several items.
  const SINGLE = ['buc', 'bucati', 'bc', 'pcs', 'pc', 'sticla', 'st', 'doza', 'fl', 'flacon', 'bidon', 'sac', 'punga', 'rola', 'cana', 'borcan'];
  const PACK = ['bax', 'baxuri', 'cut', 'cutie', 'cutii', 'pac', 'pach', 'pachet', 'set', 'seturi', 'lada', 'pk', 'cs', 'carton', 'bx'];
  const MEASURE = { g: ['mass', 1, ['g', 'gr', 'grame']], kg: ['mass', 1000, ['kg', 'kgr']], ml: ['vol', 1, ['ml']], l: ['vol', 1000, ['l', 'lt', 'ltr', 'litri', 'litru']] };
  const canon = u => {
    const n = norm(u || '').replace(/\.+$/, '');
    if (!n) return '';
    if (SINGLE.includes(n)) return 'buc';
    if (PACK.includes(n)) return 'pack';
    for (const [k, [, , names]] of Object.entries(MEASURE)) if (names.includes(n)) return k;
    return n;
  };
  /** Pack sizes with their dimension: "0,25L" -> {v: 250, dim: 'vol'} (ml), "1kg" -> {v: 1000, dim: 'mass'} (g). */
  const typedSizes = s => [...norm(s).matchAll(/(\d+(?:\.\d+)?)\s*(ml|cl|l|g|gr|kg)\b/g)].map(m => ({
    v: parseFloat(m[1]) * { ml: 1, cl: 10, l: 1000, g: 1, gr: 1, kg: 1000 }[m[2]],
    dim: ['ml', 'cl', 'l'].includes(m[2]) ? 'vol' : 'mass',
  }));
  /** Items per box written in the name: "24x0,25L", "(set 50 buc)", "cutie 1000", "24 buc/bax". 0 when absent. */
  function packCount(text) {
    const raw = String(text).toLowerCase().replace(/×/g, 'x');
    const t = norm(text);
    const m = raw.match(/\b(\d{1,4})\s*x\s*\d+(?:[.,]\d+)?\s*(?:ml|cl|l|g|gr|kg)\b/)
      || t.match(/\b(?:bax|set|pachet|pac|cutie|cut|lada)\s*(?:de\s*)?(\d{1,4})\b/)
      || t.match(/\b(\d{1,4})\s*(?:buc|bucati|plicuri|pahare|sticle|doze|role|bucati)\s*(?:\/|pe|per|la)\s*(?:bax|set|pachet|cutie|lada)\b/)
      || t.match(/\b(\d{1,4})\s*\/\s*(?:bax|set|pachet|cutie|lada)\b/);
    const n = m ? Number(m[1]) : 0;
    return n >= 2 && n <= 5000 ? n : 0;
  }

  /**
   * How many stock units one invoice unit brings (thousandths), and where that came from:
   * same unit / metric (g -> kg) / the article's own box size / the count written in the name / the weight or volume
   * in the name (stock kept in kg or l) / a typical drinks box (estimate). factor null = unknown: the admin must fill it.
   */
  function convert(line, item) {
    const u = canon(line.unit), s = canon(item.unit);
    if (!u || u === s) return { factor: 1000, source: 'same' };
    const mu = MEASURE[u], ms = MEASURE[s];
    if (mu && ms && mu[0] === ms[0]) return { factor: Math.round(mu[1] / ms[1] * 1000), source: 'metric' };
    if (u === 'pack' && item.pack) return { factor: item.pack, source: 'item' };
    const count = packCount(line.name || '');
    const size = typedSizes(line.name || '')[0] || typedSizes(`${item.name} ${item.variant || ''}`)[0];
    if (ms) {
      // Stock kept by weight / volume: one piece = the size in the name; a box needs the item count as well.
      if (size && size.dim === ms[0] && (u === 'buc' || (u === 'pack' && count))) {
        return { factor: Math.round((u === 'pack' ? count : 1) * size.v / ms[1] * 1000), source: 'size' };
      }
      return { factor: null, source: null };
    }
    if (s === 'buc' && u === 'pack') {
      if (count) return { factor: count * 1000, source: 'text' };
      if (size && size.dim === 'vol') {
        const n = /\b(doza|doze|can)\b/.test(norm(line.name || '')) || size.v <= 330 ? 24 : size.v <= 500 ? 12 : 6;
        return { factor: n * 1000, source: 'typical' };
      }
    }
    return { factor: null, source: null };
  }
  const isPack = u => canon(u) === 'pack';

  /** All pages -> {header, lines}. */
  function analyse(pages) {
    const lines = [];
    let header = { supplier: '', doc_no: '', doc_date: '' };
    pages.forEach((p, n) => {
      const rows = buildRows(p);
      if (n === 0) header = parseHeader(rows, p.width);
      for (const r of rows) { const l = parseLine(r); if (l) lines.push(l); }
    });
    return { header, lines };
  }

  /** How a confirmed line is remembered: the invoice wording plus its unit ("coca cola 0.25l sticla @bax"). */
  const aliasText = (name, unit) => norm(name) + (unit ? ' @' + norm(unit) : '');

  return { readPage, archiveCopy, analyse, match, aliasText, convert, isPack, norm, stop, _test: { num, parseLine, buildRows, parseHeader, parseTSV } };
})();
