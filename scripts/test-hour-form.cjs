// Exercise the actual modal handler with a small DOM adapter; no database writes.
const fs = require('node:fs'), vm = require('node:vm'), assert = require('node:assert/strict');
let root = [], slotNodes = [], elements = {}, sent = null, slotHtml = '';
function attrs(text) { const out = {}; for (const m of text.matchAll(/([\w:-]+)(?:="([^"]*)")?/g)) out[m[1]] = m[2] === undefined ? '' : m[2]; return out; }
function nodes(html) {
  const result = [];
  for (const m of html.matchAll(/<input\b([^>]*)>|<select\b([^>]*)>([\s\S]*?)<\/select>/g)) {
    const a = attrs(m[1] || m[2]), x = { attrs: a, dataset: {}, value: a.value || '', disabled: false };
    for (const k of Object.keys(a)) if (k.startsWith('data-')) x.dataset[k.slice(5).replace(/-([a-z])/g, (_, c) => c.toUpperCase())] = a[k];
    if (m[3]) { const options = [...m[3].matchAll(/<option\b([^>]*)>/g)].map(v => attrs(v[1])); const selected = options.find(v => 'selected' in v) || options[0]; x.value = selected ? selected.value : ''; }
    if (a.id) elements[a.id] = x; result.push(x);
  }
  return result;
}
function queryAll(q) { return root.concat(slotNodes).filter(x => q.startsWith('[') && q.slice(1, -1) in x.attrs); }
const scope = { console, Set, $: q => q.startsWith('#') ? elements[q.slice(1)] : queryAll(q)[0], $$: queryAll,
  modal: html => { elements = {}; root = nodes(html); slotNodes = []; elements.haSave = {}; elements.haError = {}; elements.haSlots = { set innerHTML(v) { slotHtml = v; slotNodes = nodes(v); } }; return {}; },
  canEditPlan: () => true, myOutsidePlan: () => false, groupCode: id => id, semLabel: () => '2/2569', closeModal: () => {}, toast: () => {}, loadSchedule: () => Promise.resolve(),
  api: (fn, token, payload) => { sent = JSON.parse(JSON.stringify(payload)); return Promise.resolve(2); } };
vm.createContext(scope);
vm.runInContext(fs.readFileSync('views/SchedCore.js', 'utf8'), scope);
vm.runInContext(fs.readFileSync('views/GridCore.html', 'utf8').replace(/^<script>/, '').replace(/<\/script>\s*$/, ''), scope);
scope.esc = scope.Grid.esc;
const plans = [1, 2, 3].map(i => ({ id: 'p' + i, group_id: 'g' + i, semester_id: 'term', course_id: 'c', teacher_ids: 't', periods: '3', joint_key: 'broad' }));
const sessions = plans.flatMap((p, i) => i < 2 ? [{ id: p.id + 'a', plan_id: p.id, group_id: p.group_id, course_id: 'c', day: 1, start: 9, length: 2 }, { id: p.id + 'b', plan_id: p.id, group_id: p.group_id, course_id: 'c', day: 2, start: 4, length: 1 }] : [{ id: 'p3a', plan_id: p.id, group_id: p.group_id, course_id: 'c', day: 3, start: 6, length: 3 }]);
scope.S = { settings: { periods_per_day: 13, lunch_period: '5' }, days: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'], plans, sessions, token: '' };
scope.IDX = { Plans: Object.fromEntries(plans.map(p => [p.id, p])), Groups: {} };
scope.courseOf = () => ({ code: '30000-1501', name: 'Course', theory: 1, practice: 2, credits: 2 });
scope.ctx = () => ({ courses: { c: scope.courseOf() }, plans: scope.IDX.Plans });
const app = fs.readFileSync('views/App.html', 'utf8');
vm.runInContext(app.slice(app.indexOf('function hourAllocationModal('), app.indexOf('/* บันทึกประเภทคาบ:', app.indexOf('function hourAllocationModal('))), scope);
scope.hourAllocationModal(plans[0]);
assert.deepEqual(queryAll('[data-allocation-group]').map(x => x.value), ['p1', 'p2', '', '', '']);
assert(!slotHtml.includes('g3')); assert(slotHtml.includes('2 + 1'));
elements.haWeeks.value = '15';
const input = (plan, day, type) => queryAll('[data-ha-periods]').find(x => x.dataset.haPlan === plan && x.dataset.haDay === String(day) && x.dataset.haType === type);
assert.equal(input('p1', 1, 'in').value, '8,9');
// Filling one day must also restore an empty other day and synchronize its peer.
input('p1', 2, 'in').value = ''; input('p2', 2, 'in').value = '';
input('p1', 1, 'in').oninput();
assert.equal(input('p1', 2, 'in').value, '4'); assert.equal(input('p2', 2, 'in').value, '4');
elements.haSave.onclick();
assert(sent); assert.equal(sent.anchor_plan_id, 'p1'); assert.deepEqual(sent.slot_types.p1a, ['in', 'in']); assert.deepEqual(sent.slot_types.p1b, ['in']); assert.equal(sent.hours_in, '3');
// A duplicate between in/out must never submit.
sent = null; input('p1', 1, 'out').value = '8'; elements.haSave.onclick(); assert.equal(sent, null); assert.match(elements.haError.textContent, /ซ้ำ/);
// A number not present in that day's timetable must never submit.
input('p1', 1, 'out').value = ''; input('p1', 1, 'in').value = '8,12'; elements.haSave.onclick(); assert.equal(sent, null);
// Ambiguous mixed allocation asks for remaining periods rather than guessing.
scope.S.plans = plans.map(p => ({ ...p, periods: '4' })); scope.IDX.Plans = Object.fromEntries(scope.S.plans.map(p => [p.id, p]));
scope.S.sessions = sessions.map(s => s.day === 2 ? { ...s, length: 2 } : s);
scope.hourAllocationModal(scope.S.plans[0]); elements.haWeeks.value = '15'; elements.haIn.value = '2'; elements.haOut.value = '2';
input('p1', 1, 'in').value = '8'; input('p1', 1, 'out').value = '9'; input('p1', 1, 'out').oninput();
assert.equal(input('p1', 2, 'in').value, ''); assert.match(elements.haError.textContent, /ยังระบุ/);
input('p1', 2, 'in').value = '4'; input('p1', 2, 'in').oninput();
assert.equal(input('p1', 1, 'in').value, '8'); assert.equal(input('p1', 1, 'out').value, '9');
// Legacy outside labels on workplace lessons must resolve to inside, load only.
scope.S.plans = plans.map(p => ({ ...p, workplace: 'true', hour_type: 'out', hours_in: '0', hours_out: '3' }));
scope.IDX.Plans = Object.fromEntries(scope.S.plans.map(p => [p.id, p])); scope.S.sessions = sessions;
scope.hourAllocationModal(scope.S.plans[0]);
assert.equal(Number(elements.haIn.value), 3); assert.equal(Number(elements.haOut.value), 0);
assert.equal(elements.haOut.readOnly, true); assert.equal(input('p1', 1, 'in').value, '8,9');
console.log('PASS: actual form opens, correct learner pair, real period numbers, one-day linking, safe submit, mixed-day completion preserves source');
