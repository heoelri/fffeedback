// Browser-side rules. Mirrors questions()/sanitize() in lib.php; tests/logic.test.mjs checks both agree.
export const SCALES = {
  rate: ['sehr gut', 'gut', 'mittel', 'schlecht', 'sehr schlecht'],
  agree: ['trifft voll zu', 'trifft eher zu', 'teils/teils', 'trifft eher nicht zu', 'trifft nicht zu'],
};
export const NA = 'na';
export const NA_LABEL = 'Kann ich nicht beurteilen';
export const MAX_TEXT = 2000;

// All questions in order, plus an automatic free-text question per section.
export function questions(survey) {
  return survey.sections.flatMap((s) => [
    ...s.questions.map((q) => ({ ...q, section: s.id })),
    ...(s.freeText === false ? [] : [{
      id: `${s.id}_frei`, type: 'text', section: s.id,
      text: `Möchtest du zum Thema „${s.title}“ noch etwas sagen?`,
    }]),
  ]);
}

export function scaleOptions(q) {
  return [...SCALES[q.scale ?? 'rate'].map((label, i) => [i + 1, label]), [NA, NA_LABEL]];
}

const matches = (value, list) => [].concat(value ?? []).some((v) => list.includes(v));

export const isVisible = (q, answers) => !q.showIf || matches(answers[q.showIf.q], q.showIf.in);

export const isNegative = (q, v) => (q.type === 'scale' ? v === 4 || v === 5 : matches(v, q.negative ?? []));

function isValid(q, v) {
  switch (q.type) {
    case 'scale': return v === NA || (Number.isInteger(v) && v >= 1 && v <= 5);
    case 'single': return q.options.includes(v);
    case 'multi': return Array.isArray(v) && v.length > 0 && new Set(v).size === v.length && v.every((x) => q.options.includes(x));
    case 'text': return typeof v === 'string' && v.trim() !== '';
    default: return false;
  }
}

const clip = (s) => s.trim().slice(0, MAX_TEXT);

// Keeps only valid answers to visible questions. Single pass works because showIf may only reference earlier questions.
export function sanitize(survey, input) {
  const answers = {};
  const notes = {};
  for (const q of questions(survey)) {
    if (!isVisible(q, answers)) continue;
    const v = input?.answers?.[q.id];
    if (isValid(q, v)) answers[q.id] = q.type === 'text' ? clip(v) : v;
    const n = input?.notes?.[q.id];
    if (q.type !== 'text' && typeof n === 'string' && n.trim()) notes[q.id] = clip(n);
  }
  return { answers, notes };
}
