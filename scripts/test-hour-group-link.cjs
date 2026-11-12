const fs = require('node:fs'), vm = require('node:vm'), assert = require('node:assert/strict'), { spawnSync } = require('node:child_process');
const scope = { Grid: { periodNo: (s, p) => p }, S: { settings: {} } }; vm.createContext(scope);
for (const file of ['SchedCore.js', 'OtCore.js']) vm.runInContext(fs.readFileSync('views/' + file, 'utf8'), scope);
const plain = x => JSON.parse(JSON.stringify(x)), S = scope.Sched, O = scope.Ot;
const plans = ['p1', 'p2', 'p3'].map((id, i) => ({ id, semester_id: 'term', group_id: 'g' + i, course_id: 'c', teacher_ids: 't', joint_key: 'broad', periods: '3' }));
const sessions = plans.flatMap((p, i) => i < 2 ? [{ id: p.id + 'a', plan_id: p.id, day: 1, start: 9, length: 2 }, { id: p.id + 'b', plan_id: p.id, day: 2, start: 4, length: 1 }] : [{ id: p.id + 'a', plan_id: p.id, day: 3, start: 6, length: 3 }]);
assert.equal(S.hourGroupKey(plans[0], sessions), S.hourGroupKey(plans[1], sessions));
assert.notEqual(S.hourGroupKey(plans[0], sessions), S.hourGroupKey(plans[2], sessions));
assert.notEqual(S.hourGroupKey(plans[0], []), S.hourGroupKey(plans[1], []));
assert.deepEqual(plain(S.parsePeriodNumbers('๑,๒ 8-9 12')), [1, 2, 8, 9, 12]);
for (const bad of ['0', '1.5', '2,2', '9-8', 'x']) assert.throws(() => S.parsePeriodNumbers(bad));
const days = { 1: [8, 9], 2: [4] };
let linked = plain(S.linkPeriodDays(days, '1', '8,9', '', 3, 0, 3));
assert.deepEqual(linked.days['2'].in, [4]); assert.equal(linked.hoursIn, 3);
linked = plain(S.linkPeriodDays(days, '2', '', '4', 0, 3, 3));
assert.deepEqual(linked.days['1'].out, [8, 9]);
linked = plain(S.linkPeriodDays(days, '1', '8', '9', 2, 1, 3));
assert.deepEqual(linked.days['2'].in, [4]); assert.equal(linked.unresolved, false);
linked = plain(S.linkPeriodDays({ 1: [8, 9], 2: [4, 5] }, '1', '8', '9', 2, 2, 4));
assert.equal(linked.unresolved, true); assert.deepEqual(linked.days['2'], { in: [], out: [] });
assert.throws(() => S.linkPeriodDays(days, '1', '8', '8,9', 2, 1, 3));
assert.throws(() => S.linkPeriodDays(days, '1', '8,12', '', 3, 0, 3));
assert.deepEqual(plain(S.hourTypes({ length: 2, hour_type: 'out', hour_types: '["out","out"]' }, { workplace: 'true' })), ['in', 'in']);
const D = { settings: { days: '1,2' }, teacher: { id: 't' }, round: { start_date: '2026-11-09', end_date: '2026-11-13' },
  sessions: sessions.filter(s => s.plan_id === 'p1').map(s => ({ ...s, teacher_ids: 't', group_id: 'g', course_id: 'c', workplace: true })),
  groups: { g: { level: 'ปวส.', students: '20' } }, courses: { c: {} }, absences: [], blocked: [], subs: [] };
const items = plain(O.hoursFor(D))[0].items; assert.equal(items.length, 3); assert(items.every(x => x.no_claim));
const sch = { total_cap: 12, cap_method: 'reject', levels: {} };
for (const lv of ['vc', 'vs', 'bd']) sch.levels[lv] = { enabled: true, load: 1, min: 0, max: 12, rate: 100 };
const forceC = Object.fromEntries(items.map(x => [x.key, 'C']));
const denied = plain(O.evalWeek(items, forceC, { 'c|g': true }, sch, 12, {}));
assert.equal(denied.amount, 0); assert.equal(denied.claimHours, 0); assert.equal(denied.errors.length, 3);
const auto = plain(O.autoSelect(items, sch, 12)); assert.deepEqual(Object.values(auto), ['L']);
assert.equal(O.evalWeek(items, auto, {}, sch, 12, {}).byLevel.vs.load, 1);
const code = `<?php
require 'src/compat.php';
function num($v){return is_numeric($v)?0+$v:0;} function js_true($v){return !in_array($v,[null,'',false,0],true);} function period_no($p){return $p;}
require 'src/sched.php'; require 'src/ot.php';
$plans=json_decode('${JSON.stringify(plans)}',true); $sessions=json_decode('${JSON.stringify(sessions)}',true);
if(sched_hour_group_key($plans[0],$sessions)!==sched_hour_group_key($plans[1],$sessions) || sched_hour_group_key($plans[0],$sessions)===sched_hour_group_key($plans[2],$sessions)) throw new Exception('groups merged incorrectly');
$p=array_merge($plans[0],['teaching_weeks'=>'15','hours_in'=>'3','hours_out'=>'0']);
if(sched_allocation_error($p,$sessions,['courses'=>[]])!=='')throw new Exception('2+1 must count as 3');
$sessions[]=['id'=>'extra','plan_id'=>'p1','day'=>4,'start'=>1,'length'=>1];
if(strpos(sched_allocation_error($p,$sessions,['courses'=>[]]),'เกินแผน')===false)throw new Exception('four periods accepted');
$p['workplace']='true'; if(sched_hour_types(['length'=>2,'hour_type'=>'out'],$p)!==['in','in'])throw new Exception('workplace must be in');
$D=json_decode('${JSON.stringify(D)}',true); $sch=json_decode('${JSON.stringify(sch)}',true);
$items=ot_hours_for($D)[0]['items']; $sel=[];foreach($items as $it){if(empty($it['no_claim']))throw new Exception('missing workplace guard');$sel[$it['key']]='C';}
$r=ot_eval_week($items,$sel,['c|g'=>true],$sch,12,[]);
if($r['amount']!=0 || $r['claimHours']!=0 || count($r['errors'])!==3)throw new Exception('workplace paid');
$r=ot_eval_week($items,[$items[0]['key']=>'L'],[],$sch,12,[]);if($r['byLevel']['vs']['load']!==1)throw new Exception('workplace load lost');
echo 'PASS';`;
const r = spawnSync(process.argv[2] || 'php', [], { input: code, encoding: 'utf8' }); assert.equal(r.status, 0, r.stderr); assert.equal(r.stdout, 'PASS');
console.log('PASS: group separation, numeric periods, cross-day linking, mixed ambiguity, 2+1 weekly cap, workplace in/load-only, no automatic/manual/per-head payment');
