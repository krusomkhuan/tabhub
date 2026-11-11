/**
 * แกนคำนวณค่าสอนเกินภาระงาน (ฟังก์ชันล้วน ใช้ได้ทั้งเซิร์ฟเวอร์และเบราว์เซอร์)
 * อ้างอิง: ระเบียบวิทยาลัยการอาชีพโพนทอง (บันทึกการประชุม 12 ต.ค. 2569) และกติกาแบบการเบิกของระบบ TAB
 *
 * หน่วยพื้นฐาน = "ชั่วโมงสอนจริง" หนึ่งคาบ ในวันที่หนึ่ง (key = date|sessionId|period)
 * แต่ละชั่วโมงเลือกเป็น '' (ไม่ใช้) / 'L' (ภาระ) / 'C' (เบิก)
 */
var Ot = (function () {
  var LEVELS = ['vc', 'vs', 'bd'];
  var LEVEL_TH = { vc: 'ปวช.', vs: 'ปวส.', bd: 'ปตรี.' };
  var METHOD_TH = {
    combined_trim: 'รวมแล้วตัด', reject: 'ปฏิเสธการเลือกที่เกิน', none: 'ไม่มีเพดานรวม',
    free: 'แบบอิสระ (ผู้ตรวจตรวจเอง)', fill_first: 'แบบวันหยุด (เติม ปวช. ก่อน)'
  };
  var EMPLOY_TH = { gov: 'ข้าราชการครู', civil: 'พนักงานราชการ', perm: 'ลูกจ้างประจำ', contract: 'ครูอัตราจ้าง', other: 'อื่นๆ' };

  function n(v) { var x = Number(v); return isNaN(x) ? 0 : x; }
  function pad(x) { return (x < 10 ? '0' : '') + x; }
  function ymd(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
  function parse(s) { var p = String(s).split('-'); return new Date(Number(p[0]), Number(p[1]) - 1, Number(p[2])); }
  /** 0=จันทร์ ... 6=อาทิตย์ */
  function dow(d) { return (d.getDay() + 6) % 7; }

  /** ระดับของกลุ่มเรียน → vc/vs/bd */
  function levelOf(group) {
    var s = String((group && (group.level || group.year_level)) || '');
    if (/ปวส|vs/i.test(s)) return 'vs';
    if (/ปตรี|ป\.ตรี|ปริญญา|bd/i.test(s)) return 'bd';
    return 'vc';
  }

  /** ทฤษฎี/ปฏิบัติ ของรายวิชา */
  function kindOf(course) {
    if (!course) return 'theory';
    if (course.kind === 'theory' || course.kind === 'practice') return course.kind;
    return n(course.practice) > n(course.theory) ? 'practice' : 'theory';
  }

  /** แปลงแถวแบบการเบิกจากชีตเป็นวัตถุ */
  function scheme(row) {
    var s = { id: row.id, name: row.name, total_cap: n(row.total_cap), cap_method: row.cap_method || 'combined_trim', levels: {} };
    LEVELS.forEach(function (l) {
      s.levels[l] = {
        enabled: String(row[l + '_enabled']) === 'true',
        load: n(row[l + '_load']), min: row[l + '_min'] === '' || row[l + '_min'] === undefined ? 1 : n(row[l + '_min']),
        max: n(row[l + '_max']), rate: n(row[l + '_rate'])
      };
    });
    return s;
  }

  /** อายุงาน (ปี) จากวันเริ่มงาน */
  function serviceYears(start, atDate) {
    if (!start) return null;
    var a = parse(String(start).slice(0, 10)), b = atDate ? parse(atDate) : new Date();
    var y = b.getFullYear() - a.getFullYear();
    if (b.getMonth() < a.getMonth() || (b.getMonth() === a.getMonth() && b.getDate() < a.getDate())) y--;
    return Math.max(0, y);
  }

  /**
   * เพดานรายบุคคล (ระเบียบข้อ 5)
   * ครูอัตราจ้าง: อายุงาน 0-3 ปี = 6, 4-6 ปี = 7, 7-9 ปี = 8 (ตาราง contract_caps ในตั้งค่า)
   * รางวัลชนะเลิศระดับชาติ + ผ่านคณะกรรมการสถานศึกษา: +1 ชม. (ข้อ 5.6) รวมไม่เกินเพดานวิทยาลัย
   */
  function personalCap(teacher, settings, atDate) {
    var collegeCap = n(settings.ot_max_cap) || 12;
    var cap = collegeCap, note = [];
    if (teacher.employment === 'contract') {
      var yrs = serviceYears(teacher.service_start, atDate);
      var table = String(settings.ot_contract_caps || '0-3:6,4-6:7,7-9:8,10-99:8').split(',').map(function (x) {
        var a = x.split(':'), r = a[0].split('-'); return { from: n(r[0]), to: n(r[1]), cap: n(a[1]) };
      });
      if (yrs === null) { cap = table[0].cap; note.push('ไม่ได้ระบุวันเริ่มงาน ใช้เพดานอายุงานต่ำสุด ' + cap + ' ชม.'); }
      else {
        var hit = table.filter(function (t) { return yrs >= t.from && yrs <= t.to; })[0] || table[table.length - 1];
        cap = hit.cap; note.push('อัตราจ้าง อายุงาน ' + yrs + ' ปี เพดาน ' + cap + ' ชม.');
      }
    }
    if (String(teacher.award_bonus) === 'true') { cap = Math.min(collegeCap, cap + 1); note.push('สิทธิ์เพิ่ม 1 ชม. (รางวัลระดับชาติ ข้อ 5.6)'); }
    // เพดานรายบุคคลที่ผู้ดูแลระบบ/งานหลักสูตรฯ กำหนด ใช้แทนค่าที่คำนวณ ไม่เกินเพดานวิทยาลัย (ต้องตรงกับ ot.php)
    if (String(teacher.ot_cap == null ? '' : teacher.ot_cap).trim() !== '' && !isNaN(Number(teacher.ot_cap))) {
      cap = Math.max(0, Math.min(collegeCap, Number(teacher.ot_cap)));
      note = ['กำหนดเพดานรายบุคคล ' + cap + ' ชม.'];
    }
    return { cap: cap, note: note.join(' · ') };
  }

  /** วันในรอบการเบิก แบ่งเป็นสัปดาห์ (จันทร์–อาทิตย์) */
  function weeksOf(round) {
    var a = parse(round.start_date), b = parse(round.end_date), weeks = [], cur = null;
    for (var d = new Date(a); d <= b; d.setDate(d.getDate() + 1)) {
      if (!cur || dow(d) === 0) { cur = { index: weeks.length + 1, dates: [] }; weeks.push(cur); }
      cur.dates.push(ymd(d));
    }
    return weeks;
  }

  function blockedAt(blocked, date, day, period) {
    return (blocked || []).filter(function (b) {
      if (b.start_date && date < String(b.start_date).slice(0, 10)) return false;
      if (b.end_date && date > String(b.end_date).slice(0, 10)) return false;
      if (b.day !== '' && b.day !== undefined && b.day !== null && String(b.day) !== String(day)) return false;
      var f = n(b.period_from) || 1, t = n(b.period_to) || (b.period_from ? f : 99);
      return period >= f && period <= t;
    })[0];
  }

  function absentAt(absences, teacherId, date, period) {
    return (absences || []).filter(function (a) {
      if (String(a.teacher_id) !== String(teacherId) && a.teacher_id !== '*') return false;
      if (String(a.date).slice(0, 10) !== date) return false;
      var f = n(a.period_from) || 1, t = n(a.period_to) || 99;
      return period >= f && period <= t;
    })[0];
  }

  /**
   * ชั่วโมงสอนจริงของครูในรอบ (รวมสอนแทน/สอนชดเชย)
   * D: {settings, teacher, round, sessions(ภาคเรียน), groups{}, courses{}, absences[], blocked[], subs[]}
   */
  function hoursFor(D) {
    var days = String(D.settings.days || '0,1,2,3,4').split(',').map(Number);
    var tid = String(D.teacher.id);
    var mine = D.sessions.filter(function (s) { return String(s.teacher_ids).split(',').indexOf(tid) >= 0; });
    var weeks = weeksOf(D.round);
    weeks.forEach(function (w) {
      w.items = [];
      w.dates.forEach(function (date) {
        var day = dow(parse(date));
        if (days.indexOf(day) < 0) return;
        mine.forEach(function (s) {
          if (Number(s.day) !== day) return;
          for (var p = Number(s.start); p < Number(s.start) + Number(s.length); p++) {
            w.items.push(item(D, s, date, day, p, tid, null));
          }
        });
      });
      // สอนแทน / สอนชดเชย ที่ครูคนนี้เป็นผู้สอน
      (D.subs || []).forEach(function (r) {
        if (String(r.teacher_id) !== tid || w.dates.indexOf(String(r.date).slice(0, 10)) < 0 || String(r.status) === 'cancelled') return;
        var s = D.sessions.filter(function (x) { return String(x.id) === String(r.session_id); })[0] ||
          { id: r.session_id, group_id: r.group_id, course_id: r.course_id, teacher_ids: r.original_teacher_id };
        var date = String(r.date).slice(0, 10), day = dow(parse(date));
        for (var p = n(r.start); p < n(r.start) + n(r.length); p++) w.items.push(item(D, s, date, day, p, tid, r));
      });
      w.items.sort(function (a, b) { return a.date < b.date ? -1 : a.date > b.date ? 1 : a.period - b.period; });
    });
    return weeks;
  }

  function ownSessionAt(sessions, tid, day, p, exceptId) {
    return (sessions || []).some(function (x) {
      return String(x.id) !== exceptId && n(x.day) === day && String(x.teacher_ids).split(',').indexOf(tid) >= 0 && p >= n(x.start) && p < n(x.start) + n(x.length);
    });
  }

  function item(D, s, date, day, p, tid, sub) {
    var g = D.groups[s.group_id] || {}, c = D.courses[s.course_id] || {};
    var it = {
      key: date + '|' + s.id + '|' + p + (sub ? '|' + sub.id : ''), date: date, day: day, period: p, session_id: s.id,
      group_id: s.group_id, course_id: s.course_id, level: levelOf(g), kind: kindOf(c), course_type: c.course_type || '',
      students: n(g.students), blocked: '', absent: '', sub: sub ? sub.type : '', sub_id: sub ? sub.id : ''
    };
    var b = blockedAt(D.blocked, date, day, p);
    if (b) it.blocked = b.name || b.label || 'ห้ามเบิก';
    if (!sub) {
      var a = absentAt(D.absences, tid, date, p);
      if (a) it.absent = a.type || 'ลา';
      // ครูผู้สอนเดิมที่มีผู้สอนแทน/ชดเชยในวันนั้น = ไม่ได้สอนจริง
      // ยกเว้นสอนชดเชยที่รายงานผลแล้ว: นับชั่วโมงที่คาบเดิมตามตารางสอน แทนวันที่สอนชดเชย (ต้องตรงกับ ot.php)
      var madeUp = null, other = false;
      (D.subs || []).forEach(function (r) {
        if (String(r.original_teacher_id) === tid && String(r.session_id) === String(s.id) && String(r.original_date || r.date).slice(0, 10) === date && String(r.status) !== 'cancelled' && String(r.status) !== 'deleted') {
          if (makeupReported(r, tid)) { madeUp = r; return; }
          other = true;
          it.absent = it.absent || (r.type === 'makeup' ? 'สอนชดเชยวันอื่น' : 'มีผู้สอนแทน');
        }
      });
      if (madeUp && !other) { it.absent = ''; it.made_up = String(madeUp.date).slice(0, 10); }
    } else if (sub.type === 'sub' && ownSessionAt(D.sessions, tid, day, p, String(s.id))) {
      // ระเบียบข้อ 8: ครูผู้สอนแทนเบิกได้เฉพาะคาบที่ตารางสอนของตนเองว่าง (ต้องตรงกับ ot.php)
      it.absent = 'ดูแลแทนขณะมีคาบสอนของตนเอง — เบิกไม่ได้';
    } else if (makeupReported(sub, tid)) {
      // นับไว้ที่คาบเดิมแล้ว วันสอนชดเชยจึงไม่นับซ้ำ
      it.absent = 'นับที่คาบเดิม ' + String(sub.original_date || sub.date).slice(0, 10);
    } else if (sub.type === 'makeup' && String(sub.report_done) !== 'true') {
      it.pending = 'รอแนบรายงานผลการสอนชดเชย';
    }
    return it;
  }

  function makeupReported(r, tid) {
    return r.type === 'makeup' && String(r.report_done) === 'true' && String(r.original_teacher_id) === tid && String(r.teacher_id) === tid;
  }

  /** หากฎเบิกรายหัวที่ใช้กับชั่วโมงนี้ */
  function perHeadRule(it, rules, course) {
    if (!it.students) return null;
    var sg = (course && course.subject_group) || '';
    // เกณฑ์มีหลายช่วงจำนวนคน ต้องใช้ช่วงที่แคบที่สุดที่เข้าเงื่อนไข และเกณฑ์ของกลุ่มวิชานั้นก่อนเกณฑ์ทุกกลุ่ม
    return (rules || []).filter(function (r) {
      return r.track === it.level && r.course_kind === it.kind && (!r.subject_group || !sg || r.subject_group === sg) && it.students < n(r.below_students);
    }).sort(function (a, b) { return n(a.below_students) - n(b.below_students) || (b.subject_group ? 1 : 0) - (a.subject_group ? 1 : 0); })[0] || null;
  }

  /**
   * ตรวจและคิดเงินของ 1 สัปดาห์
   * sel: {key: 'L'|'C'}, ph: {course_id|group_id: true} (เปิดเบิกรายหัว)
   * คืน {byLevel, claimHours, amount, errors[], warnings[], paid:{level: hours}}
   */
  function evalWeek(items, sel, ph, sch, cap, ctx) {
    var res = { byLevel: {}, errors: [], warnings: [], amount: 0, claimHours: 0, perHeadAmount: 0, paid: {} };
    LEVELS.forEach(function (l) { res.byLevel[l] = { taught: 0, load: 0, claim: 0, perhead: 0 }; });
    var subUsed = {};
    var pno = function (p) { return Grid.periodNo(S.settings, p); };
    items.forEach(function (it) {
      var v = sel[it.key] || '';
      var B = res.byLevel[it.level];
      if (!it.absent) B.taught++;
      if (!v) return;
      if (it.blocked) { res.errors.push(it.date + ' คาบ ' + pno(it.period) + ': ' + it.blocked + ' — เลือกไม่ได้'); return; }
      if (it.absent) { res.errors.push(it.date + ' คาบ ' + pno(it.period) + ': ' + it.absent + ' — ไม่นับเป็นชั่วโมงสอน'); return; }
      if (v === 'L') B.load++;
      if (v === 'C') {
        if (it.pending) res.errors.push(it.date + ' คาบ ' + pno(it.period) + ': ' + it.pending + ' (ระเบียบข้อ 8)');
        if (it.sub === 'sub') subUsed[it.sub_id] = (subUsed[it.sub_id] || 0) + 1;
        var rule = ph && ph[it.course_id + '|' + it.group_id] ? perHeadRule(it, ctx && ctx.perHead, ctx && ctx.courses && ctx.courses[it.course_id]) : null;
        if (rule) { B.perhead++; res.perHeadAmount += n(rule.rate_per_head); } // อัตราในเกณฑ์เป็นยอดต่อชั่วโมงของทั้งกลุ่มแล้ว ไม่คูณจำนวนคนซ้ำ
        else B.claim++;
      }
    });
    Object.keys(subUsed).forEach(function (sid) {
      var q = ctx && ctx.subQuota ? ctx.subQuota[sid] : undefined;
      if (q !== undefined && subUsed[sid] > q) res.errors.push('สอนแทนเบิก ' + subUsed[sid] + ' ชม. เกินสิทธิ์คงเหลือของครูผู้สอนเดิม (' + q + ' ชม.) — ระเบียบข้อ 8');
    });
    var free = sch.cap_method === 'free';
    var totalCap = sch.cap_method === 'none' ? 999 : Math.min(sch.total_cap || 999, cap);
    if (sch.cap_method === 'none' && cap < 12) totalCap = cap;
    var levelsClaimed = 0;
    LEVELS.forEach(function (l) {
      var B = res.byLevel[l], L = sch.levels[l], claim = B.claim + B.perhead;
      if (!claim && !B.load) return;
      if (!L.enabled) { res.errors.push('แบบการเบิกนี้ไม่ได้เปิดใช้ระดับ ' + LEVEL_TH[l]); return; }
      if (claim) levelsClaimed++;
      if (free) return;
      if (claim && B.load < L.load) res.errors.push(LEVEL_TH[l] + ': ภาระยังไม่ครบ (' + B.load + '/' + L.load + ' ชม.) — ต้องครบก่อนจึงเบิกได้');
      if (L.max && claim > L.max) res.errors.push(LEVEL_TH[l] + ': เบิกเกินสูงสุดของระดับ (' + claim + '/' + L.max + ' ชม.)');
    });
    if (!free && levelsClaimed > 1) LEVELS.forEach(function (l) {
      var B = res.byLevel[l], L = sch.levels[l], claim = B.claim + B.perhead;
      if (claim && L.min && claim < L.min) res.errors.push(LEVEL_TH[l] + ': เบิกน้อยกว่าขั้นต่ำ ' + L.min + ' ชม.');
    });
    if (sch.cap_method === 'fill_first') {
      LEVELS.forEach(function (l) { var L = sch.levels[l], B = res.byLevel[l]; if (L.enabled && B.taught && B.load < L.load) res.errors.push(LEVEL_TH[l] + ': แบบวันหยุดต้องครบภาระทุกระดับก่อน'); });
    }
    // ชั่วโมงที่จ่ายจริงต่อระดับ
    var paid = {}; LEVELS.forEach(function (l) { paid[l] = res.byLevel[l].claim; });
    var phHours = LEVELS.reduce(function (a, l) { return a + res.byLevel[l].perhead; }, 0);
    var total = LEVELS.reduce(function (a, l) { return a + paid[l]; }, 0) + phHours;
    if (total > totalCap) {
      if (sch.cap_method === 'combined_trim') {
        var over = total - totalCap;
        var order = LEVELS.slice().sort(function (a, b) { return paid[b] - paid[a]; }); // ฝั่งมากถูกตัด
        order.forEach(function (l) { var cut = Math.min(over, paid[l]); paid[l] -= cut; over -= cut; });
        res.warnings.push('เกินเพดานรวม ' + totalCap + ' ชม. — ตัดฝั่งที่เบิกมากกว่า (รวมแล้วตัด)');
      } else {
        res.errors.push('ชั่วโมงเบิกรวม ' + total + ' เกินเพดานรวม ' + totalCap + ' ชม.');
      }
    }
    if (sch.cap_method === 'fill_first' && ctx && ctx.refAmount) {
      var amt0 = LEVELS.reduce(function (a, l) { return a + paid[l] * sch.levels[l].rate; }, 0);
      var vs = sch.levels.vs.rate || 0;
      if (amt0 > ctx.refAmount + vs) res.errors.push('แบบวันหยุด: ยอดเงินเกินยอดอ้างอิงแบบระดับเดียว (' + ctx.refAmount + ' บาท) เกิน 1 ชั่วโมงสุดท้าย');
    }
    LEVELS.forEach(function (l) { res.amount += paid[l] * (sch.levels[l].rate || 0); });
    res.amount += res.perHeadAmount;
    res.paid = paid;
    res.claimHours = LEVELS.reduce(function (a, l) { return a + paid[l]; }, 0) + phHours;
    res.cap = totalCap;
    if (free) res.warnings.push('แบบอิสระ: ไม่ตรวจภาระ/เพดานรายระดับ — ผู้ตรวจต้องตรวจเอง');
    return res;
  }

  /** เลือกภาระ/เบิกอัตโนมัติ: ภาระก่อนตามลำดับวันเวลา ที่เหลือเป็นเบิก (ไม่เกินสูงสุดและเพดาน) */
  function autoSelect(items, sch, cap) {
    var sel = {}, cnt = {};
    var totalCap = sch.cap_method === 'none' ? cap : Math.min(sch.total_cap || 999, cap);
    LEVELS.forEach(function (l) { cnt[l] = { L: 0, C: 0 }; });
    var usable = items.filter(function (it) { return !it.blocked && !it.absent; });
    // ชั่วโมงปกติเป็นภาระก่อน ชั่วโมงสอนแทน/ชดเชยเป็นเบิก
    usable.sort(function (a, b) { return (a.sub ? 1 : 0) - (b.sub ? 1 : 0); });
    usable.forEach(function (it) {
      var L = sch.levels[it.level]; if (!L || !L.enabled) return;
      if (cnt[it.level].L < L.load && !it.sub) { sel[it.key] = 'L'; cnt[it.level].L++; }
    });
    // ระดับที่อัตราสูงกว่าเบิกก่อน (ได้ประโยชน์สูงสุดภายใต้กติกา)
    var order = LEVELS.slice().sort(function (a, b) { return (sch.levels[b].rate || 0) - (sch.levels[a].rate || 0); });
    var total = 0;
    order.forEach(function (l) {
      var L = sch.levels[l]; if (!L.enabled) return;
      if (cnt[l].L < L.load && sch.cap_method !== 'free') return; // ภาระไม่ครบ เบิกไม่ได้
      usable.forEach(function (it) {
        if (it.level !== l || sel[it.key] || it.pending) return;
        if (L.max && cnt[l].C >= L.max) return;
        if (total >= totalCap) return;
        sel[it.key] = 'C'; cnt[l].C++; total++;
      });
    });
    return sel;
  }

  /** จำนวนเงินเป็นตัวอักษรไทย */
  function bahtText(num) {
    num = Math.round(Number(num) * 100) / 100;
    var D = ['', 'หนึ่ง', 'สอง', 'สาม', 'สี่', 'ห้า', 'หก', 'เจ็ด', 'แปด', 'เก้า'], P = ['', 'สิบ', 'ร้อย', 'พัน', 'หมื่น', 'แสน', 'ล้าน'];
    function read(s) {
      var out = '', len = s.length;
      for (var i = 0; i < len; i++) {
        var d = Number(s[i]), pos = len - i - 1;
        if (pos >= 6) { return read(s.slice(0, len - 6)) + 'ล้าน' + read(s.slice(len - 6)); }
        if (!d) continue;
        if (pos === 0 && d === 1 && len > 1) out += 'เอ็ด';
        else if (pos === 1 && d === 2) out += 'ยี่';
        else if (pos === 1 && d === 1) out += '';
        else out += D[d];
        out += P[pos];
      }
      return out;
    }
    var parts = num.toFixed(2).split('.'), b = Number(parts[0]), s = Number(parts[1]);
    if (!b && !s) return 'ศูนย์บาทถ้วน';
    var t = (b ? read(String(b)) + 'บาท' : '');
    return t + (s ? read(String(s)) + 'สตางค์' : 'ถ้วน');
  }

  return {
    LEVELS: LEVELS, LEVEL_TH: LEVEL_TH, METHOD_TH: METHOD_TH, EMPLOY_TH: EMPLOY_TH,
    levelOf: levelOf, kindOf: kindOf, scheme: scheme, serviceYears: serviceYears, personalCap: personalCap,
    weeksOf: weeksOf, hoursFor: hoursFor, evalWeek: evalWeek, autoSelect: autoSelect, bahtText: bahtText, perHeadRule: perHeadRule, ymd: ymd, parse: parse, dow: dow
  };
})();
