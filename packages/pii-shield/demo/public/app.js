// pii-shield demo. Talks to the local PHP API only; settings (never the text) are kept in localStorage.
'use strict';

const TYPES = [
  ['PERSON', 'Person'],
  ['ADDRESS', 'Adresse'],
  ['SVNR', 'Sozialversicherungsnr.'],
  ['IBAN', 'IBAN'],
  ['TAX_NUMBER', 'Steuernummer'],
  ['FILE_NUMBER', 'Aktenzahl'],
  ['EMAIL', 'E-Mail'],
  ['PHONE', 'Telefon'],
  ['BIRTH_DATE', 'Geburtsdatum'],
  ['LICENSE_PLATE', 'Kennzeichen'],
];
const TYPE_LABEL = Object.fromEntries(TYPES);
const DEFAULT_STRATEGY = { IBAN: { kind: 'partial', keepStart: 4, keepEnd: 4 }, PHONE: { kind: 'partial', keepStart: 0, keepEnd: 2 } };
const DETECTOR_LABEL = {
  at_svnr: 'Muster und Prüfziffer',
  iban: 'Muster und IBAN-Prüfsumme',
  tax_number: 'Steuernummer-Muster',
  de_tax_id: 'Steuer-ID mit Prüfziffer',
  email: 'E-Mail-Muster',
  phone: 'Telefonnummer-Muster',
  birth_date: 'Datum nach „geb.“ oder „geboren“',
  file_number: 'Stichwort wie „Zahl:“ oder „GZ“',
  license_plate: 'Stichwort „Kennzeichen“',
  salutation_block: 'Anrede im Adressblock',
  salutation_inline: 'Anrede im Text',
  postal_address: 'Adressblock',
  propagation: 'Weiteres Vorkommen eines Fundes',
};
const STORAGE_KEY = 'pii-shield-demo-settings';

const $ = (id) => document.getElementById(id);
const els = {
  typeList: $('typeList'), keepAuthority: $('keepAuthority'), highlight: $('highlight'), live: $('live'),
  allowlist: $('allowlist'), allowlistEmpty: $('allowlistEmpty'),
  input: $('input'), backdrop: $('backdrop'), legend: $('legend'),
  loadSample: $('loadSample'), clearText: $('clearText'), analyzeNow: $('analyzeNow'),
  result: $('result'), resultTitle: $('resultTitle'), resultIntro: $('resultIntro'), copyResult: $('copyResult'),
  vault: $('vault'), vaultId: $('vaultId'), vaultCipher: $('vaultCipher'), vaultTtl: $('vaultTtl'), mapping: $('mapping'),
  restoreSheet: $('restoreSheet'), answer: $('answer'), restore: $('restore'), useResult: $('useResult'),
  restored: $('restored'), restoreNotice: $('restoreNotice'), error: $('error'),
  popover: $('popover'), popoverSwatch: $('popoverSwatch'), popoverTitle: $('popoverTitle'), popoverClose: $('popoverClose'),
  popoverValue: $('popoverValue'), popoverReplacement: $('popoverReplacement'), popoverReplacementLabel: $('popoverReplacementLabel'),
  popoverDetector: $('popoverDetector'), popoverAction: $('popoverAction'),
};

const state = {
  mode: 'reversible',
  types: new Set(TYPES.map(([t]) => t)),
  strategies: {},
  keepAuthority: true,
  highlight: true,
  live: true,
  allowlist: [],
  analysis: null,
  samples: [],
  sampleIndex: 0,
  answerEdited: false,
  request: null,
};

// ---------- settings persistence (no text, ever) ----------
function loadSettings() {
  try {
    const saved = JSON.parse(localStorage.getItem(STORAGE_KEY) || 'null');
    if (!saved) return;
    state.mode = saved.mode === 'irreversible' ? 'irreversible' : 'reversible';
    state.types = new Set((saved.types || []).filter((t) => TYPE_LABEL[t]));
    state.strategies = saved.strategies || {};
    state.keepAuthority = saved.keepAuthority !== false;
    state.highlight = saved.highlight !== false;
    state.live = saved.live !== false;
  } catch { /* storage unavailable: defaults */ }
}
function saveSettings() {
  try {
    localStorage.setItem(STORAGE_KEY, JSON.stringify({
      mode: state.mode, types: [...state.types], strategies: state.strategies,
      keepAuthority: state.keepAuthority, highlight: state.highlight, live: state.live,
    }));
  } catch { /* ignore */ }
}

// ---------- helpers ----------
const escapeHtml = (s) => s.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const strategyFor = (type) => state.strategies[type] || DEFAULT_STRATEGY[type] || { kind: 'label', keepStart: 2, keepEnd: 2 };

function debounce(fn, ms) {
  let timer;
  return (...args) => { clearTimeout(timer); timer = setTimeout(() => fn(...args), ms); };
}

async function post(path, body, signal = undefined) {
  const response = await fetch(path, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
    signal,
  });
  const data = await response.json();
  if (!response.ok) throw new Error(data.error || `Fehler ${response.status}`);
  return data;
}

function showError(message) {
  els.error.textContent = message;
  els.error.hidden = !message;
}

// ---------- settings panel ----------
function renderTypeList() {
  els.typeList.innerHTML = '';
  const counts = {};
  for (const f of state.analysis?.findings || []) counts[f.type] = (counts[f.type] || 0) + 1;

  for (const [type, label] of TYPES) {
    const li = document.createElement('li');
    li.className = 'type-row';
    li.dataset.type = type;
    const id = `type-${type}`;
    li.innerHTML = `
      <input type="checkbox" id="${id}" ${state.types.has(type) ? 'checked' : ''}>
      <label for="${id}"><span class="swatch"></span>${escapeHtml(label)}</label>
      <span class="count" aria-label="Funde">${counts[type] || ''}</span>`;
    li.querySelector('input').addEventListener('change', (e) => {
      e.target.checked ? state.types.add(type) : state.types.delete(type);
      saveSettings();
      renderTypeList();
      analyze();
    });

    if (state.mode === 'irreversible' && state.types.has(type)) {
      const s = strategyFor(type);
      const row = document.createElement('div');
      row.className = 'strategy';
      row.innerHTML = `
        <select aria-label="Maskierung für ${escapeHtml(label)}">
          <option value="label">als [${type}]</option>
          <option value="partial">teilweise</option>
          <option value="full">vollständig ****</option>
        </select>
        <span ${s.kind === 'partial' ? '' : 'hidden'}>vorne</span>
        <input type="number" min="0" max="20" value="${s.keepStart ?? 0}" aria-label="Zeichen vorne behalten" ${s.kind === 'partial' ? '' : 'hidden'}>
        <span ${s.kind === 'partial' ? '' : 'hidden'}>hinten</span>
        <input type="number" min="0" max="20" value="${s.keepEnd ?? 0}" aria-label="Zeichen hinten behalten" ${s.kind === 'partial' ? '' : 'hidden'}>`;
      const [select, start, end] = row.querySelectorAll('select, input');
      select.value = s.kind;
      const update = () => {
        state.strategies[type] = { kind: select.value, keepStart: clampInt(start.value), keepEnd: clampInt(end.value) };
        saveSettings();
        renderTypeList();
        analyze();
      };
      select.addEventListener('change', update);
      start.addEventListener('change', update);
      end.addEventListener('change', update);
      li.append(row);
    }
    els.typeList.append(li);
  }
}
const clampInt = (v) => Math.max(0, Math.min(20, parseInt(v, 10) || 0));

function renderAllowlist() {
  els.allowlist.innerHTML = '';
  for (const value of state.allowlist) {
    const li = document.createElement('li');
    li.textContent = value.replace(/\n/g, ' ');
    const remove = document.createElement('button');
    remove.type = 'button';
    remove.textContent = '×';
    remove.setAttribute('aria-label', `„${value}“ wieder schwärzen`);
    remove.addEventListener('click', () => { unallow(value); });
    li.append(remove);
    els.allowlist.append(li);
  }
  els.allowlistEmpty.hidden = state.allowlist.length > 0;
}

function allow(value) {
  if (!state.allowlist.includes(value)) state.allowlist.push(value);
  renderAllowlist();
  analyze();
}
function unallow(value) {
  state.allowlist = state.allowlist.filter((v) => v !== value);
  renderAllowlist();
  analyze();
}

// ---------- analysis ----------
const analyzeDebounced = debounce(() => analyze(), 300);

async function analyze() {
  const text = els.input.value;
  closePopover();
  if (state.request) state.request.abort();
  if (!text.trim()) {
    state.analysis = null;
    render();
    return;
  }
  const controller = new AbortController();
  state.request = controller;
  const strategies = {};
  for (const type of state.types) strategies[type] = strategyFor(type);

  try {
    const analysis = await post('/api/analyze', {
      text,
      mode: state.mode,
      types: [...state.types],
      strategies,
      keepAuthorityContacts: state.keepAuthority,
      allowlist: state.allowlist,
    }, controller.signal);
    if (controller.signal.aborted) return;
    state.analysis = { ...analysis, text };
    showError('');
    render(true);
  } catch (e) {
    if (e.name !== 'AbortError') showError(`Prüfung fehlgeschlagen: ${e.message}`);
  }
}

function render(fresh = false) {
  renderBackdrop();
  renderLegend();
  renderTypeList();
  renderResult(fresh);
  renderVault();
  renderRestoreAvailability();
}

/** Builds highlight HTML for the editor backdrop. Offsets from PHP are code points. */
function renderBackdrop() {
  const text = els.input.value;
  const chars = Array.from(text);
  const spans = [];
  const a = state.analysis;
  if (state.highlight && a && a.text === text) {
    for (const f of a.findings) spans.push({ start: f.offset, end: f.offset + f.length, finding: f });
    for (const value of state.allowlist) {
      const needle = Array.from(value);
      for (let i = 0; i + needle.length <= chars.length; i++) {
        if (chars.slice(i, i + needle.length).join('') === value && !spans.some((s) => i < s.end && s.start < i + needle.length)) {
          spans.push({ start: i, end: i + needle.length, allowed: value });
        }
      }
    }
  }
  spans.sort((x, y) => x.start - y.start);

  let html = '';
  let cursor = 0;
  for (const s of spans) {
    html += escapeHtml(chars.slice(cursor, s.start).join(''));
    const inner = escapeHtml(chars.slice(s.start, s.end).join(''));
    html += s.finding
      ? `<mark class="finding" data-type="${s.finding.type}" data-index="${s.finding.index}">${inner}</mark>`
      : `<mark class="allowed" data-allowed="${escapeHtml(s.allowed)}">${inner}</mark>`;
    cursor = s.end;
  }
  html += escapeHtml(chars.slice(cursor).join(''));
  // A trailing newline needs content to take up height.
  els.backdrop.innerHTML = html + (text.endsWith('\n') ? ' ' : '');
  syncScroll();
}

function renderLegend() {
  const findings = state.analysis?.findings || [];
  if (!els.input.value.trim()) {
    els.legend.innerHTML = '<span class="empty">Noch kein Text.</span>';
    return;
  }
  if (!findings.length) {
    els.legend.innerHTML = '<span class="empty">Keine personenbezogenen Daten gefunden.</span>';
    return;
  }
  const counts = {};
  for (const f of findings) counts[f.type] = (counts[f.type] || 0) + 1;
  els.legend.innerHTML = TYPES.filter(([t]) => counts[t])
    .map(([t, label]) => `<span data-type="${t}"><span class="swatch"></span>${escapeHtml(label)} ${counts[t]}</span>`)
    .join('');
}

function renderResult(fresh) {
  const a = state.analysis;
  const reversible = state.mode === 'reversible';
  els.resultTitle.textContent = reversible ? 'Geschwärzt' : 'Maskiert';
  els.resultIntro.textContent = reversible
    ? 'So sieht das Sprachmodell den Text.'
    : 'Endgültig maskiert, etwa für Logs.';
  els.result.dataset.empty = 'Das Ergebnis erscheint hier, sobald im Original Text steht.';
  els.result.classList.toggle('no-highlight', !state.highlight);
  if (!a) {
    els.result.innerHTML = '';
    return;
  }
  const byIndex = Object.fromEntries(a.findings.map((f) => [f.index, f]));
  els.result.innerHTML = a.segments.map((s) => {
    if (s.finding === null) return escapeHtml(s.text);
    const f = byIndex[s.finding];
    const cls = reversible ? 'bar' : 'masked';
    return `<span class="${cls}" data-type="${f.type}" title="${escapeHtml(TYPE_LABEL[f.type])}">${escapeHtml(s.text)}</span>`;
  }).join('');
  if (fresh) {
    els.result.classList.remove('is-fresh');
    void els.result.offsetWidth;
    els.result.classList.add('is-fresh');
  }
}

function renderVault() {
  const a = state.analysis;
  const show = state.mode === 'reversible' && a && a.vaultId;
  els.vault.hidden = !show;
  if (!show) return;
  els.vaultId.textContent = a.vaultId;
  const bytes = atob(a.storedPayload).length;
  els.vaultCipher.textContent = `${a.storedPayload.slice(0, 120)}… (${bytes} Byte, verschlüsselt)`;
  els.mapping.innerHTML = Object.entries(a.mapping)
    .map(([placeholder, value]) => `<tr><td>${escapeHtml(placeholder)}</td><td>${escapeHtml(value)}</td></tr>`)
    .join('');
}

function renderRestoreAvailability() {
  const reversible = state.mode === 'reversible';
  els.restoreSheet.classList.toggle('is-disabled', !reversible);
  els.restore.disabled = !reversible;
  els.useResult.disabled = !reversible;
  if (!reversible) {
    els.restored.dataset.empty = 'Maskierte Texte lassen sich nicht wiederherstellen. Wechsle zu „Schwärzen“.';
    els.restored.innerHTML = '';
    els.restoreNotice.hidden = true;
    return;
  }
  els.restored.dataset.empty = 'Die wiederhergestellte Antwort erscheint hier.';
  if (!state.answerEdited && state.analysis) els.answer.value = state.analysis.output;
}

// ---------- restore ----------
async function restore() {
  const a = state.analysis;
  if (!a || !a.vaultId) {
    showRestoreNotice('Erst einen Text schwärzen, dann die Antwort wiederherstellen.', false);
    return;
  }
  try {
    const response = await post('/api/restore', { text: els.answer.value, vaultId: a.vaultId });
    const typeOf = (placeholder) => (placeholder.match(/^⟦([A-Z_]+)_\d+⟧$/) || [])[1];
    els.restored.classList.toggle('no-highlight', !state.highlight);
    els.restored.innerHTML = response.segments.map((s) => {
      if (s.status === 'plain') return escapeHtml(s.text);
      if (s.status === 'unknown') return `<span class="unknown" title="Unbekannter Platzhalter">${escapeHtml(s.text)}</span>`;
      return `<span class="restored-value" data-type="${typeOf(s.placeholder) || ''}" title="${escapeHtml(s.placeholder)}">${escapeHtml(s.text)}</span>`;
    }).join('');
    if (response.unknown.length) {
      showRestoreNotice(`Nicht aufgelöst, weil das Modell sie erfunden hat: ${response.unknown.join(', ')}`, false);
    } else {
      showRestoreNotice('Alle Platzhalter wurden aufgelöst.', true);
    }
  } catch (e) {
    showRestoreNotice(e.message === 'The mapping has expired or does not exist. Mask the text again.'
      ? 'Die Zuordnung ist abgelaufen. Den Text bitte neu schwärzen.'
      : `Wiederherstellen fehlgeschlagen: ${e.message}`, false);
  }
}
function showRestoreNotice(message, ok) {
  els.restoreNotice.textContent = message;
  els.restoreNotice.classList.toggle('is-ok', ok);
  els.restoreNotice.hidden = false;
}

// ---------- popover on findings in the original ----------
function codePointIndex(text, utf16Index) {
  return Array.from(text.slice(0, utf16Index)).length;
}

function openPopoverAtCaret() {
  const a = state.analysis;
  if (!a || a.text !== els.input.value || els.input.selectionStart !== els.input.selectionEnd) return;
  const index = codePointIndex(els.input.value, els.input.selectionStart);
  const finding = a.findings.find((f) => index >= f.offset && index < f.offset + f.length);
  if (finding) {
    openPopover(els.backdrop.querySelector(`mark[data-index="${finding.index}"]`), finding);
    return;
  }
  for (const mark of els.backdrop.querySelectorAll('mark.allowed')) {
    const start = offsetOfMark(mark);
    if (index >= start && index < start + Array.from(mark.textContent).length) {
      openPopover(mark, null, mark.dataset.allowed);
      return;
    }
  }
  closePopover();
}

function offsetOfMark(mark) {
  let offset = 0;
  for (let node = mark.previousSibling; node; node = node.previousSibling) offset += Array.from(node.textContent).length;
  return offset;
}

function openPopover(mark, finding, allowedValue = null) {
  if (!mark || !state.highlight) return;
  syncScroll(); // the textarea may have scrolled to the caret just now
  document.querySelectorAll('mark.is-active').forEach((m) => m.classList.remove('is-active'));
  mark.classList.add('is-active');

  const p = els.popover;
  if (finding) {
    p.dataset.type = finding.type;
    els.popoverTitle.textContent = TYPE_LABEL[finding.type];
    els.popoverValue.textContent = finding.value;
    els.popoverValue.className = 'value';
    els.popoverReplacementLabel.textContent = state.mode === 'reversible' ? 'Wird zu' : 'Maskiert als';
    els.popoverReplacement.textContent = finding.replacement;
    els.popoverDetector.textContent = `${DETECTOR_LABEL[finding.detector] || finding.detector}, Sicherheit ${Math.round(finding.confidence * 100)} %`;
    els.popoverAction.textContent = 'Nicht schwärzen';
    els.popoverAction.onclick = () => { closePopover(); allow(finding.value); };
  } else {
    delete p.dataset.type;
    els.popoverTitle.textContent = 'Bleibt stehen';
    els.popoverValue.textContent = allowedValue;
    els.popoverValue.className = 'value';
    els.popoverReplacementLabel.textContent = 'Grund';
    els.popoverReplacement.textContent = 'Steht auf der Liste „Nie schwärzen“.';
    els.popoverDetector.textContent = '–';
    els.popoverAction.textContent = 'Wieder schwärzen';
    els.popoverAction.onclick = () => { closePopover(); unallow(allowedValue); };
  }
  els.popoverSwatch.hidden = !finding;
  p.hidden = false;

  const rect = mark.getClientRects()[0] || mark.getBoundingClientRect();
  const width = p.offsetWidth;
  const left = Math.max(8, Math.min(window.scrollX + rect.left, window.scrollX + document.documentElement.clientWidth - width - 8));
  p.style.left = `${left}px`;
  p.style.top = `${window.scrollY + rect.bottom + 8}px`;
}

function closePopover() {
  els.popover.hidden = true;
  document.querySelectorAll('mark.is-active').forEach((m) => m.classList.remove('is-active'));
}

function syncScroll() {
  els.backdrop.scrollTop = els.input.scrollTop;
  els.backdrop.scrollLeft = els.input.scrollLeft;
}

// ---------- samples ----------
async function loadSamples() {
  try {
    const response = await fetch('/api/samples');
    state.samples = await response.json();
  } catch { state.samples = []; }
  els.loadSample.hidden = state.samples.length === 0;
}

// ---------- wiring ----------
function initSettingsPanel() {
  // Wide: settings always open beside the panes. Narrower: folded bar, so all three panes fit.
  const wide = window.matchMedia('(min-width: 1500px)');
  const settings = $('settings');
  const apply = () => { settings.open = wide.matches; };
  apply();
  wide.addEventListener('change', apply);
  settings.addEventListener('toggle', () => { if (wide.matches && !settings.open) settings.open = true; });
}

function init() {
  initSettingsPanel();
  loadSettings();
  document.querySelector(`input[name="mode"][value="${state.mode}"]`).checked = true;
  els.keepAuthority.checked = state.keepAuthority;
  els.highlight.checked = state.highlight;
  els.live.checked = state.live;
  els.analyzeNow.hidden = state.live;
  els.vaultTtl.textContent = '15';

  document.querySelectorAll('input[name="mode"]').forEach((radio) => radio.addEventListener('change', () => {
    state.mode = radio.value;
    saveSettings();
    analyze();
    renderTypeList();
  }));
  document.querySelectorAll('[data-types]').forEach((button) => button.addEventListener('click', () => {
    state.types = button.dataset.types === 'all' ? new Set(TYPES.map(([t]) => t)) : new Set();
    saveSettings();
    renderTypeList();
    analyze();
  }));
  els.keepAuthority.addEventListener('change', () => { state.keepAuthority = els.keepAuthority.checked; saveSettings(); analyze(); });
  els.highlight.addEventListener('change', () => { state.highlight = els.highlight.checked; saveSettings(); closePopover(); render(); });
  els.live.addEventListener('change', () => { state.live = els.live.checked; els.analyzeNow.hidden = state.live; saveSettings(); if (state.live) analyze(); });

  els.input.addEventListener('input', () => {
    renderBackdrop(); // keep layout in sync immediately; highlights return after the next analysis
    if (state.live) analyzeDebounced();
  });
  els.input.addEventListener('scroll', syncScroll);
  els.input.addEventListener('click', openPopoverAtCaret);
  els.input.addEventListener('keyup', (e) => { if (e.key === 'Enter' && e.altKey) openPopoverAtCaret(); });
  els.analyzeNow.addEventListener('click', analyze);

  els.loadSample.addEventListener('click', () => {
    if (!state.samples.length) return;
    els.input.value = state.samples[state.sampleIndex % state.samples.length].text;
    state.sampleIndex++;
    state.answerEdited = false;
    analyze();
  });
  els.clearText.addEventListener('click', () => {
    els.input.value = '';
    els.answer.value = '';
    els.restored.innerHTML = '';
    els.restoreNotice.hidden = true;
    state.answerEdited = false;
    analyze();
    els.input.focus();
  });
  els.copyResult.addEventListener('click', async () => {
    if (!state.analysis) return;
    try {
      await navigator.clipboard.writeText(state.analysis.output);
      els.copyResult.textContent = 'Kopiert';
      setTimeout(() => { els.copyResult.textContent = 'Kopieren'; }, 1500);
    } catch { showError('Kopieren ist in diesem Browser nicht erlaubt.'); }
  });

  els.answer.addEventListener('input', () => { state.answerEdited = true; });
  els.useResult.addEventListener('click', () => { state.answerEdited = false; renderRestoreAvailability(); });
  els.restore.addEventListener('click', restore);

  els.popoverClose.addEventListener('click', closePopover);
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closePopover(); });
  document.addEventListener('click', (e) => {
    if (!els.popover.hidden && !els.popover.contains(e.target) && e.target !== els.input) closePopover();
  });
  let lastWidth = window.innerWidth;
  window.addEventListener('resize', () => {
    // Layout only changes with the width; ignore height-only resizes (mobile toolbars, screenshots).
    if (window.innerWidth !== lastWidth) closePopover();
    lastWidth = window.innerWidth;
  });

  renderTypeList();
  renderAllowlist();
  render();
  loadSamples();
}

init();
