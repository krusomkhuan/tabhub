const fs = require('node:fs'), vm = require('node:vm'), path = require('node:path');
const assert = require('node:assert/strict'), { spawnSync } = require('node:child_process');
const root = path.resolve(__dirname, '..'), scope = {};
vm.createContext(scope);
vm.runInContext(fs.readFileSync(path.join(root, 'views/SchedCore.js'), 'utf8'), scope);
for (const file of ['App.html', 'GridCore.html', 'Extra.html']) {
  const code = fs.readFileSync(path.join(root, 'views', file), 'utf8').replace(/^<script>\s*/, '').replace(/<\/script>\s*$/, '');
  new vm.Script(code);
  if (file === 'GridCore.html') vm.runInContext(code, scope);
}
const p = { id: 'p', periods: '3', teaching_weeks: '15', hours_in: '2', hours_out: '1' };
const s = { id: 's', plan_id: 'p', teacher_ids: 't', course_id: 'c', group_id: 'g1', day: 1, start: 1, length: 3, hour_types: '["in","out","in"]' };
assert.deepEqual(Array.from(scope.Sched.hourTypes(s, p)), ['in', 'out', 'in']);
assert.deepEqual(Array.from(scope.Sched.hourTypes({ ...s, hour_types: 'invalid', hour_type: 'out' }, p)), ['out', 'out', 'out']);
const sheet = scope.Grid.teacherSheet({ settings: { periods_per_day: 3, days: '1,2', lunch_period: '', period_start: '08:00', period_minutes: 60 }, dayNames: ['Mon', 'Tue', 'Wed'], locked: [], teacher: { id: 't', name: 'Teacher' }, teacherId: 't',
  sessions: [s, { ...s, id: 's2', plan_id: 'p2', group_id: 'g2' }], groups: [], plc: [], course: () => ({ code: 'C', name: 'Course', theory: 1, practice: 2, credits: 2 }), group: id => id, room: () => '',
  hourType: (x, k) => scope.Sched.hourTypes(x, p)[k], deptName: '', semLabel: '', sign: '', color: () => '#fff' });
assert.match(sheet, /รวมใน: 2/);
assert.match(sheet, /รวมนอก: 1/);
const code = `<?php
require 'src/compat.php'; require 'src/core.php'; require 'src/sched.php';
$p=json_decode('${JSON.stringify(p)}',true); $s=json_decode('${JSON.stringify(s)}',true); $ctx=['courses'=>[]];
if(sched_allocation_error($p,[$s],$ctx)!=='') throw new Exception('valid allocation rejected');
$bad=$p; $bad['hours_in']='3'; if(sched_allocation_error($bad,[$s],$ctx)==='') throw new Exception('sum mismatch accepted');
$bad=$p; $bad['teaching_weeks']='0'; if(sched_allocation_error($bad,[$s],$ctx)==='') throw new Exception('zero weeks accepted');
$bad=$p; $bad['hours_in']='1.5'; if(sched_allocation_error($bad,[$s],$ctx)==='') throw new Exception('fraction accepted');
$bad=$s; $bad['hour_types']='["in","in","in"]'; if(sched_allocation_error($p,[$bad],$ctx)==='') throw new Exception('quota exceeded');
$bad=$s; $bad['hour_types']='["in","none","in"]'; if(sched_allocation_error($p,[$bad],$ctx)==='') throw new Exception('unassigned period accepted');
$partial=$s; $partial['length']=2; $partial['hour_types']='["in","out"]'; if(sched_allocation_error($p,[$partial],$ctx)!=='') throw new Exception('partial allocation rejected');
if(sched_allocation_error(['id'=>'p','periods'=>'3'],[$s],$ctx)!=='') throw new Exception('legacy plan rejected');
if(sched_hour_types($s,$p)!==['in','out','in']) throw new Exception('period resolution failed');
echo 'PASS';`;
const r = spawnSync(process.argv[2] || 'php', [], { cwd: root, input: code, encoding: 'utf8' });
assert.equal(r.status, 0, r.stderr); assert.equal(r.stdout, 'PASS');
const apiSource = fs.readFileSync(path.join(root, 'src/api_core.php'), 'utf8');
const start = apiSource.indexOf('function api_planHourAllocation(');
const apiFunction = apiSource.slice(start, apiSource.indexOf('\n/**', start));
const integration = `<?php
function str($x){return (string)$x;} function num($x){return is_numeric($x)?0+$x:0;}
function auth_user($x){return $GLOBALS['me'];} function with_lock($f){return $f();}
function readTable($n){return $GLOBALS['tables'][$n]??[];}
function idx_table($n){return array_column(readTable($n),null,'id');}
function find_row($rows,$id){foreach($rows as $k=>$r) if($r['id']===$id)return $k;return null;}
function writeTable($n,$rows){$GLOBALS['tables'][$n]=$rows;$GLOBALS['writes']++;}
function log_action($a,$b,$c){}
require 'src/sched.php';
${apiFunction}
$me=['role'=>'teacher','teacher_id'=>'t']; $writes=0;
$tables=['Teachers'=>[['id'=>'t','department_id'=>'general']], 'Groups'=>[['id'=>'g1','department_id'=>'tech'],['id'=>'g2','department_id'=>'tech']], 'Courses'=>[]];
$p=['id'=>'p1','semester_id'=>'term','teacher_ids'=>'t','group_id'=>'g1','course_id'=>'c','periods'=>'3','joint_key'=>'pair'];
$tables['Plans']=[$p,array_merge($p,['id'=>'p2','group_id'=>'g2'])];
$s=['id'=>'s1','plan_id'=>'p1','day'=>'1','start'=>'1','length'=>'3'];
$tables['Sessions']=[$s,array_merge($s,['id'=>'s2','plan_id'=>'p2'])];
$payload=['plan_ids'=>['p1','p2'],'weeks'=>'15','hours_in'=>'2','hours_out'=>'1','slot_types'=>['s1'=>['in','out','in'],'s2'=>['in','out','in']]];
$initial=$tables; $bad=$payload; $bad['slot_types']['s2']=['in','in','in'];
try {api_planHourAllocation('',$bad);throw new Exception('invalid accepted');} catch(Exception $e) {if($e->getMessage()==='invalid accepted')throw $e;}
if($writes || $tables!==$initial)throw new Exception('failed request changed data');
$me['teacher_id']='other'; try {api_planHourAllocation('',$payload);throw new Exception('unauthorized accepted');} catch(Exception $e) {if($e->getMessage()==='unauthorized accepted')throw $e;}
if($writes)throw new Exception('unauthorized writes'); $me['teacher_id']='t';
if(api_planHourAllocation('',$payload)!==2)throw new Exception('expected two plans');
if($tables['Plans'][0]['teaching_weeks']!=='15' || $tables['Plans'][1]['hours_out']!=='1')throw new Exception('metadata not saved');
foreach($tables['Sessions'] as $i=>$s){foreach(['day','start','length','id','plan_id'] as $key) if($s[$key]!==$initial['Sessions'][$i][$key])throw new Exception('schedule moved');}
$bad=$payload; $bad['slot_types']['unknown']=['in']; $before=$writes;
try {api_planHourAllocation('',$bad);throw new Exception('stale accepted');} catch(Exception $e) {if($e->getMessage()==='stale accepted')throw $e;}
if($writes!==$before)throw new Exception('stale writes');
$tables['Plans'][]=array_merge($p,['id'=>'p3','group_id'=>'g3']);
$tables['Sessions'][]=array_merge($s,['id'=>'s3','plan_id'=>'p3','day'=>'3']);
$bad=$payload; $bad['plan_ids'][]='p3'; $bad['slot_types']['s3']=['in','out','in'];
try {api_planHourAllocation('',$bad);throw new Exception('unrelated group accepted');} catch(Exception $e) {if($e->getMessage()==='unrelated group accepted')throw $e;}
if($writes!==$before)throw new Exception('unrelated group written');
$bad=$payload; $bad['anchor_plan_id']='p3';
try {api_planHourAllocation('',$bad);throw new Exception('wrong anchor accepted');} catch(Exception $e) {if($e->getMessage()==='wrong anchor accepted')throw $e;}
if($writes!==$before)throw new Exception('wrong anchor written');
echo 'PASS';`;
const it = spawnSync(process.argv[2] || 'php', [], { cwd: root, input: integration, encoding: 'utf8' });
assert.equal(it.status, 0, it.stderr); assert.equal(it.stdout, 'PASS');
console.log('PASS: mixed periods, weekly total/quota, weeks validation, legacy data, partial schedules, joint teacher totals, JS syntax');
console.log('PASS: allocation API permissions, multi-group save, rejection without writes, stale sessions, timetable preserved');
