import { questions, sanitize, isVisible, isNegative, scaleOptions, MAX_TEXT } from './logic.js';

const token = new URLSearchParams(location.search).get('t') ?? '';
const api = (action) => `${location.pathname}?t=${encodeURIComponent(token)}&a=${action}`;
const root = document.getElementById('app');
const NOTE = 'Kommentar hinzufügen (optional)';
const NOTE_NEGATIVE = 'Was läuft nicht gut? Schreib es uns – das hilft uns am meisten.';

let survey;
let state = { answers: {}, notes: {} };
let shown = []; // questions on the current page
let timer;

function h(tag, props = {}, ...kids) {
  const el = Object.assign(document.createElement(tag), props);
  el.append(...kids.flat().filter((k) => k != null && k !== false));
  return el;
}

const status = h('p', { className: 'status', role: 'status' });
const message = (title, text) => root.replaceChildren(h('h1', {}, title), h('p', {}, text));

async function save() {
  clearTimeout(timer);
  try {
    const r = await fetch(api('draft'), { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(state) });
    status.textContent = r.ok ? 'Zwischengespeichert ✓' : 'Speichern fehlgeschlagen';
  } catch {
    status.textContent = 'Offline – wird beim nächsten Mal gespeichert';
  }
}

function changed() {
  status.textContent = '';
  clearTimeout(timer);
  timer = setTimeout(save, 800);
  refresh();
}

// Show/hide dependent questions; open the comment box on negative answers.
function refresh() {
  const { answers } = sanitize(survey, state);
  for (const { q, el, note } of shown) {
    el.hidden = !isVisible(q, answers);
    if (!note) continue;
    const negative = isNegative(q, state.answers[q.id]);
    el.classList.toggle('negative', negative);
    if (negative) note.open = true;
    note.firstChild.textContent = negative ? NOTE_NEGATIVE : NOTE;
  }
}

function pick(q, value, checked) {
  if (q.type === 'multi') {
    const set = new Set(state.answers[q.id] ?? []);
    if (checked) set.add(value); else set.delete(value);
    state.answers[q.id] = [...set];
  } else {
    state.answers[q.id] = value;
  }
  changed();
}

function question(q) {
  let input;
  let note = null;
  if (q.type === 'text') {
    input = h('textarea', {
      rows: 4, maxLength: MAX_TEXT, ariaLabel: q.text, value: state.answers[q.id] ?? '',
      oninput: (e) => { state.answers[q.id] = e.target.value; changed(); },
    });
  } else {
    const options = q.type === 'scale' ? scaleOptions(q) : q.options.map((o) => [o, o]);
    input = h('div', { className: 'options' }, options.map(([value, label]) => h('label', {},
      h('input', {
        type: q.type === 'multi' ? 'checkbox' : 'radio', name: q.id,
        checked: [].concat(state.answers[q.id] ?? []).includes(value),
        onchange: (e) => pick(q, value, e.target.checked),
      }),
      h('span', {}, label))));
    note = h('details', { className: 'note', open: !!state.notes[q.id] }, h('summary', {}, NOTE),
      h('textarea', {
        rows: 3, maxLength: MAX_TEXT, ariaLabel: `Kommentar: ${q.text}`, value: state.notes[q.id] ?? '',
        oninput: (e) => { state.notes[q.id] = e.target.value; changed(); },
      }));
  }
  const el = h('fieldset', {}, h('legend', {}, q.text), input, note);
  shown.push({ q, el, note });
  return el;
}

async function submit(e) {
  if (!confirm('Jetzt absenden? Danach kannst du nichts mehr ändern.')) return;
  e.target.disabled = true;
  clearTimeout(timer);
  try {
    const r = await fetch(api('submit'), { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(state) });
    if (r.ok) message('Danke! 🚒', 'Deine Antworten wurden anonym gespeichert. Die Ergebnisse stellen wir allen in der Einheit vor.');
    else message('Absenden nicht möglich', (await r.json()).error);
  } catch {
    status.textContent = 'Keine Verbindung – bitte erneut versuchen.';
    e.target.disabled = false;
  }
}

function go(page) {
  save();
  show(page);
}

// page -1 = intro, 0..n-1 = sections, n = submit page
function show(page) {
  shown = [];
  const { sections } = survey;
  let body;
  if (page < 0) {
    body = [h('h1', {}, survey.title), h('p', {}, survey.intro),
      h('p', { className: 'card' }, h('strong', {}, '🔒 Anonym: '), survey.anonymity)];
  } else if (page === sections.length) {
    const count = Object.keys(sanitize(survey, state).answers).length;
    body = [h('h1', {}, 'Fast geschafft!'),
      h('p', {}, `Du hast ${count} Fragen beantwortet. Mit „Zurück“ kannst du noch alles ändern.`),
      h('p', { className: 'card' }, 'Nach dem Absenden kannst du deine Antworten nicht mehr ändern.'),
      h('button', { type: 'button', onclick: submit }, 'Jetzt verbindlich absenden')];
  } else {
    const s = sections[page];
    body = [h('progress', { max: sections.length, value: page + 1 }),
      h('p', { className: 'muted' }, `Teil ${page + 1} von ${sections.length}`),
      h('h1', {}, s.title), s.description && h('p', {}, s.description),
      ...questions(survey).filter((q) => q.section === s.id).map(question)];
  }
  const nav = h('nav', {},
    page >= 0 && h('button', { type: 'button', className: 'secondary', onclick: () => go(page - 1) }, 'Zurück'),
    page < sections.length && h('button', { type: 'button', onclick: () => go(page + 1) }, page < 0 ? 'Los geht’s' : 'Weiter'));
  root.replaceChildren(...body.filter(Boolean), nav, status);
  refresh();
  scrollTo(0, 0);
}

try {
  const r = await fetch(api('load'));
  const data = await r.json();
  if (!r.ok) message('Link ungültig', data.error);
  else if (data.submitted) message('Danke!', 'Du hast bereits teilgenommen. Deine Antworten sind anonym gespeichert und können nicht mehr geändert werden.');
  else if (data.closed) message('Umfrage beendet', 'Diese Umfrage ist leider schon beendet.');
  else {
    survey = data.survey;
    state = data.draft ?? state;
    document.title = survey.title;
    show(-1);
  }
} catch {
  message('Keine Verbindung', 'Bitte später erneut versuchen.');
}
