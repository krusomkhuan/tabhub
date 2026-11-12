// Run: node scripts/test-joint-partial.cjs [path-to-php]
// เรียนร่วม 3 กลุ่ม: 2 กลุ่มลงครบแล้ว อีกกลุ่มยังไม่ลง → จัดอัตโนมัติต้องเพิ่มคาบให้เฉพาะกลุ่มที่ขาด
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const { spawnSync } = require('node:child_process');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const scope = {};
vm.createContext(scope);
vm.runInContext(fs.readFileSync(path.join(root, 'views/SchedCore.js'), 'utf8'), scope);
const S = scope.Sched;
const plain = x => JSON.parse(JSON.stringify(x));
// แผนแรกคือกลุ่มที่ยังไม่ลง (เป็นตัวที่เคยถูกใช้คำนวณแทนทั้งชุด)
const plans = ['g3', 'g1', 'g2'].map((g, i) => ({ id: 'p' + i, group_id: g, course_id: 'c', teacher_ids: 't', room_id: '', periods: '3', split: '', joint_key: 'trio' }));
const options = { settings: { periods_per_day: 8, days: '0,1,2,3,4', lunch_period: '' }, courses: [{ id: 'c', theory: '1', practice: '2' }], groups: [], rooms: [], teachers: [], plans, locked: [] };
const ctx = S.makeCtx(options);
const fixed = [
  { id: 'a', plan_id: 'p1', group_id: 'g1', course_id: 'c', teacher_ids: 't', room_id: '', day: 0, start: 1, length: 2 },
  { id: 'b', plan_id: 'p1', group_id: 'g1', course_id: 'c', teacher_ids: 't', room_id: '', day: 1, start: 1, length: 1 },
  { id: 'c', plan_id: 'p2', group_id: 'g2', course_id: 'c', teacher_ids: 't', room_id: '', day: 2, start: 1, length: 1 },
  { id: 'd', plan_id: 'p2', group_id: 'g2', course_id: 'c', teacher_ids: 't', room_id: '', day: 3, start: 1, length: 2 },
];
const check = res => {
  assert.equal(res.unplaced.length, 0);
  assert.deepEqual(res.added.map(s => [s.plan_id, s.length]), [['p0', 3]]);
};
const js = plain(S.auto(plans, fixed, ctx, { attempts: 1 }));
check(js);
const phpCode = `<?php
require 'src/compat.php'; require 'src/core.php'; require 'src/sched.php';
$o = json_decode('${JSON.stringify(options)}', true); $ctx = sched_ctx($o);
$fixed = json_decode('${JSON.stringify(fixed)}', true);
echo json_encode(sched_auto($o['plans'], $fixed, $ctx, ['attempts' => 1]));`;
const result = spawnSync(process.argv[2] || 'php', [], { cwd: root, input: phpCode, encoding: 'utf8' });
assert.equal(result.status, 0, result.stderr);
const php = JSON.parse(result.stdout);
check(php);
assert.deepEqual(php, js);
console.log('PASS: joint set with groups already complete only adds periods to the group still missing them (PHP + JS)');
