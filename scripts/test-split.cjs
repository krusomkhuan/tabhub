// Run: node scripts/test-split.cjs [path-to-php]
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const { spawnSync } = require('node:child_process');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const scope = {};
vm.createContext(scope);
vm.runInContext(fs.readFileSync(path.join(root, 'views/SchedCore.js'), 'utf8'), scope);
new vm.Script(fs.readFileSync(path.join(root, 'views/App.html'), 'utf8').replace(/^<script>\s*/, '').replace(/<\/script>\s*$/, ''));
const S = scope.Sched;
const plain = x => JSON.parse(JSON.stringify(x));
const plans = ['g1', 'g2'].map((g, i) => ({ id: 'p' + i, group_id: g, course_id: 'c', teacher_ids: 't', room_id: '', periods: '3', split: '2+1', joint_key: 'pair' }));
const options = { settings: { periods_per_day: 3, days: '1,2', lunch_period: '' }, courses: [{ id: 'c', theory: '1', practice: '2' }], groups: [], rooms: [], teachers: [], plans,
  locked: [{ day: 1, period: 3 }, { day: 2, period: 2 }, { day: 2, period: 3 }] };
const ctx = S.makeCtx(options);
for (const split of ['2+1', '1+2', '1+1+1', '', '3']) assert.equal(S.splitError({ ...plans[0], split }, ctx), '');
for (const split of ['2+2', '0+3', '1.5+1.5', '2+-1+2', '2+1x', '2+', '2++1']) assert.notEqual(S.splitError({ ...plans[0], split }, ctx), '');
const fixed = plans.map(p => ({ ...p, plan_id: p.id, day: 1, start: 1, length: 2, id: 's' + p.id }));
assert.deepEqual(plain(S.remainingBlocks(plans[0], fixed, ctx)), [1]);
const auto = plain(S.auto(plans, [], ctx, { attempts: 1 }));
assert.equal(auto.unplaced.length, 0);
assert.equal(auto.added.length, 4);
for (const p of plans) {
  const sessions = auto.added.filter(s => s.plan_id === p.id);
  assert.deepEqual(sessions.map(s => [s.day, s.start, s.length]), [[1, 1, 2], [2, 1, 1]]);
  assert.deepEqual(plain(S.remainingBlocks(p, sessions, ctx)), []);
}
const continued = plain(S.auto(plans, fixed, ctx, { attempts: 1 }));
assert.deepEqual(continued.added.map(s => s.length), [1, 1]);
assert.throws(() => S.auto([plans[0], { ...plans[1], split: '3' }], [], ctx), /เรียนร่วม/);
const phpCode = `<?php
require 'src/compat.php'; require 'src/core.php'; require 'src/sched.php';
$o = json_decode('${JSON.stringify(options)}', true); $ctx = sched_ctx($o); $p = $o['plans'][0];
foreach (['2+1','1+2','1+1+1','','3'] as $v) { $p['split']=$v; if(sched_split_error($p,$ctx)!=='') throw new Exception('valid split rejected'); }
foreach (['2+2','0+3','1.5+1.5','2+-1+2','2+1x','2+','2++1'] as $v) { $p['split']=$v; if(sched_split_error($p,$ctx)==='') throw new Exception('invalid split accepted'); }
$p=$o['plans'][0]; $fixed=[];
foreach($o['plans'] as $x) $fixed[]=array_merge($x,['id'=>'s'.$x['id'],'plan_id'=>$x['id'],'day'=>1,'start'=>1,'length'=>2]);
if(sched_remaining($p,$fixed,$ctx)!==[1]) throw new Exception('remaining must be 1');
if(sched_over_problem([$p],$fixed,[],1,null,$ctx)!==null) throw new Exception('2+1 rejected');
if(!sched_over_problem([$p],$fixed,[],2,null,$ctx)) throw new Exception('2+2 must exceed plan');
$mismatch=$o['plans']; $mismatch[1]['split']='3'; $blocked=false;
try { sched_auto($mismatch,[],$ctx); } catch(Exception $e) { $blocked=true; }
if(!$blocked) throw new Exception('joint split mismatch must be reported');
echo json_encode(['auto'=>sched_auto($o['plans'],[],$ctx,['attempts'=>1]),'continued'=>sched_auto($o['plans'],$fixed,$ctx,['attempts'=>1])]);`;
const result = spawnSync(process.argv[2] || 'php', [], { cwd: root, input: phpCode, encoding: 'utf8' });
assert.equal(result.status, 0, result.stderr);
const server = JSON.parse(result.stdout);
assert.deepEqual(server.auto, auto);
assert.deepEqual(server.continued, continued);
console.log('PASS: PHP/JS split validation, joint 2+1 across days, partial placement, completion, over-plan guard, App syntax');
