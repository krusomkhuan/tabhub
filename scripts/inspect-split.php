<?php
/** Read-only audit: php scripts/inspect-split.php [semester-id] */
require __DIR__.'/../src/core.php';
require __DIR__.'/../src/sched.php';
$sem=$argv[1]??readSettings()['default_semester_id'];
$ts=array_column(readTable('Teachers'),null,'id'); $cs=array_column(readTable('Courses'),null,'id'); $gs=array_column(readTable('Groups'),null,'id');
$ps=array_values(array_filter(readTable('Plans'),fn($p)=>$p['semester_id']===$sem));
$ss=array_values(array_filter(readTable('Sessions'),fn($s)=>$s['semester_id']===$sem));
$ctx=['courses'=>$cs]; $rows=[]; $teachers=[];
foreach($ps as $p){
 $sessions=array_values(array_filter($ss,fn($s)=>$s['plan_id']===$p['id']));
 if(count($sessions)<2) continue;
 $parts=array_map(fn($s)=>(int)$s['length'],$sessions); $need=sched_plan_periods($p,$ctx);
 if($need!=3 || array_sum($parts)!=3) continue;
 foreach(sched_ids($p['teacher_ids']) as $tid) $teachers[$tid]=$ts[$tid]['name']??$tid;
 $rows[]=['plan_id'=>$p['id'],'teacher'=>implode(', ',array_map(fn($id)=>$ts[$id]['name']??$id,sched_ids($p['teacher_ids']))),'group'=>$gs[$p['group_id']]['code']??'', 'course'=>$cs[$p['course_id']]['code']??'', 'split'=>$p['split'],'actual'=>implode('+',$parts),'sessions'=>array_map(fn($s)=>['id'=>$s['id'],'day'=>$s['day'],'start'=>$s['start'],'length'=>$s['length']],$sessions)];
}
echo json_encode(['semester'=>$sem,'plans'=>count($rows),'teachers'=>count($teachers),'teacher_names'=>array_values($teachers),'rows'=>$rows],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)."\n";
