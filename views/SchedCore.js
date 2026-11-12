/**
 * ตรวจการชน + จัดตารางอัตโนมัติ
 * ส่วน "แกนกลาง" (Sched.*) เป็นฟังก์ชันล้วน ไม่เรียก Apps Script — ใช้ได้ทั้งฝั่งเซิร์ฟเวอร์และทดสอบ
 */
var Sched = (function () {

  function ids(s) {
    return String(s || '').split(',').map(function (x) { return x.trim(); }).filter(String);
  }

  function hourTypes(s, p, course) {
    if (String(s.workplace) === 'true' || String((p || {}).workplace) === 'true' || (p || {}).course_type === 'internship' || (course || {}).course_type === 'internship') return Array.from({ length: Number(s.length) }, function () { return 'in'; });
    var types;
    try { types = JSON.parse(s.hour_types || 'null'); } catch (e) { types = null; }
    if (Array.isArray(types) && types.length === Number(s.length) && types.every(function (x) { return ['in', 'out', 'none'].indexOf(x) >= 0; })) return types;
    return Array.from({ length: Number(s.length) }, function () { return s.hour_type || (p || {}).hour_type || 'in'; });
  }

  function hourGroupKey(p, sessions) {
    var slots = [];
    sessions.forEach(function (s) {
      if (String(s.plan_id) !== String(p.id)) return;
      for (var k = 0; k < Number(s.length); k++) slots.push(Number(s.day) + ':' + (Number(s.start) + k));
    });
    if (!slots.length || !p.joint_key) return 'P:' + p.id;
    return 'J:' + p.joint_key + '|' + (p.semester_id || '') + '|' + (p.course_id || '') + '|' + ids(p.teacher_ids).sort().join(',') + '|' + (String(p.workplace) === 'true' ? 'workplace' : 'college') + '|' + slots.sort().join(',');
  }

  function parsePeriodNumbers(value) {
    var text = String(value || '').trim().replace(/[๐-๙]/g, function (c) { return c.charCodeAt(0) - 3664; });
    if (!text) return [];
    var out = [];
    text.split(/[,\s]+/).forEach(function (part) {
      var m = /^([1-9][0-9]*)(?:-([1-9][0-9]*))?$/.exec(part);
      if (!m) throw new Error('กรอกเลขคาบ เช่น 1,2 หรือ 8-9');
      var from = Number(m[1]), to = m[2] ? Number(m[2]) : from;
      if (to < from || to > 100) throw new Error('ช่วงเลขคาบไม่ถูกต้อง');
      for (var p = from; p <= to; p++) { if (out.indexOf(p) >= 0) throw new Error('กรอกเลขคาบซ้ำ: ' + p); out.push(p); }
    });
    return out;
  }

  /** เชื่อมวันอื่นเฉพาะเมื่อประเภทชัดเจน ห้ามเดาประเภทของคาบผสม */
  function linkPeriodDays(days, sourceDay, inValue, outValue, hoursIn, hoursOut, need) {
    var inside = parsePeriodNumbers(inValue), outside = parsePeriodNumbers(outValue), source = days[sourceDay] || [];
    var entered = inside.concat(outside);
    if (new Set(entered).size !== entered.length) throw new Error('เลขคาบซ้ำระหว่างใน/นอก');
    if (entered.some(function (p) { return source.indexOf(p) < 0; })) throw new Error('มีเลขคาบที่ไม่มีในตารางของวันนี้');
    if (entered.length !== source.length || !source.length) return null;
    var result = {}, remaining = 0;
    Object.keys(days).forEach(function (day) { result[day] = { in: [], out: [] }; if (String(day) !== String(sourceDay)) remaining += days[day].length; });
    result[sourceDay] = { in: inside, out: outside };
    var uniform = !outside.length ? 'in' : !inside.length ? 'out' : '';
    if (uniform) { hoursIn = uniform === 'in' ? need : 0; hoursOut = uniform === 'out' ? need : 0; }
    var leftIn = Number(hoursIn) - inside.length, leftOut = Number(hoursOut) - outside.length;
    if (leftIn < 0 || leftOut < 0 || Number(hoursIn) + Number(hoursOut) !== need) throw new Error('จำนวนคาบใน/นอกของวันนี้ไม่ตรงกับยอดตามแผน');
    var type = uniform || (leftOut === 0 && leftIn >= remaining ? 'in' : leftIn === 0 && leftOut >= remaining ? 'out' : '');
    if (type) Object.keys(days).forEach(function (day) { if (String(day) !== String(sourceDay)) result[day][type] = days[day].slice(); });
    return { days: result, hoursIn: Number(hoursIn), hoursOut: Number(hoursOut), unresolved: remaining > 0 && !type };
  }

  /** สร้างบริบทจากข้อมูลดิบ */
  function makeCtx(o) {
    var ctx = {
      periods: Number(o.settings.periods_per_day || 12),
      lunch: ids(o.settings.lunch_period).map(Number),
      days: ids(o.settings.days || '0,1,2,3,4').map(Number),
      locked: {}, teachers: {}, rooms: {}, groups: {}, courses: {}, plans: {}
    };
    (o.locked || []).forEach(function (l) {
      if (String(l.day) === '' || String(l.day) === '*') ctx.days.forEach(function (d) { ctx.locked[d + '-' + l.period] = l.label || 'ล็อก'; });
      else ctx.locked[l.day + '-' + l.period] = l.label || 'ล็อก';
    });
    (o.teachers || []).forEach(function (t) {
      var un = {};
      ids(t.unavailable).forEach(function (x) { un[x.replace(':', '-')] = 1; }); // รูปแบบ "วัน-คาบ" เช่น 0-1,0-2
      // คาบ PLC ถือเป็นเวลาที่ครูไม่ว่าง: คาบรายบุคคลใช้แทนคาบของแผนก
      var slots = o.plc || [], own = slots.filter(function (x) { return String(x.teacher_id || '') === String(t.id); });
      (own.length ? own : slots.filter(function (x) { return !x.teacher_id && String(x.department_id || '') === String(t.department_id || ''); })).forEach(function (x) {
        for (var k = 0, n = Number(x.length) || 1; k < n; k++) un[x.day + '-' + (Number(x.start) + k)] = 1;
      });
      ctx.teachers[t.id] = { name: t.name, max: Number(t.max_per_day) || 0, un: un };
    });
    (o.rooms || []).forEach(function (r) { ctx.rooms[r.id] = { name: r.name, shareable: String(r.shareable) === 'true' }; });
    (o.groups || []).forEach(function (g) { ctx.groups[g.id] = g; });
    (o.courses || []).forEach(function (c) { ctx.courses[c.id] = c; });
    (o.plans || []).forEach(function (p) { ctx.plans[p.id] = p; });
    return ctx;
  }

  function overlap(a, b) {
    var a1 = Number(a.start), a2 = a1 + Number(a.length) - 1;
    var b1 = Number(b.start), b2 = b1 + Number(b.length) - 1;
    return String(a.day) === String(b.day) && a1 <= b2 && b1 <= a2;
  }

  function jointOf(s, ctx) {
    var p = ctx.plans[s.plan_id];
    return p && p.joint_key ? String(p.joint_key) : '';
  }

  /**
   * ตรวจว่าวางคาบ cand ได้ไหม — คืนรายการปัญหา [{type, msg, hard}]
   * hard=true = ห้ามวาง, hard=false = คำเตือน
   */
  function check(cand, sessions, ctx) {
    var out = [];
    var start = Number(cand.start), len = Number(cand.length), day = Number(cand.day);
    if (ctx.days.indexOf(day) < 0) out.push({ type: 'day', msg: 'วันนี้ไม่ได้เปิดให้จัดตาราง', hard: true });
    if (start < 1 || start + len - 1 > ctx.periods) out.push({ type: 'range', msg: 'เกินจำนวนคาบต่อวัน', hard: true });
    for (var p = start; p < start + len; p++) {
      if (ctx.lunch.indexOf(p) >= 0) { out.push({ type: 'lunch', msg: 'ทับเวลาพักเที่ยง', hard: true }); break; }
      if (ctx.locked[day + '-' + p]) { out.push({ type: 'locked', msg: 'ทับชั่วโมงที่ล็อก: ' + ctx.locked[day + '-' + p], hard: true }); break; }
    }
    var cj = jointOf(cand, ctx);
    var cTeachers = ids(cand.teacher_ids);
    var dayLoad = {};
    sessions.forEach(function (s) {
      if (s.id && cand.id && String(s.id) === String(cand.id)) return;
      if (String(s.day) === String(day)) {
        ids(s.teacher_ids).forEach(function (t) { dayLoad[t] = (dayLoad[t] || 0) + Number(s.length); });
      }
      if (!overlap(cand, s)) return;
      var sameJoint = cj && cj === jointOf(s, ctx);
      if (String(s.group_id) === String(cand.group_id)) {
        out.push({ type: 'group', msg: 'กลุ่มเรียนมีวิชาอื่นในช่วงนี้แล้ว (' + courseLabel(s, ctx) + ')', hard: true, with: s.id });
      }
      if (!sameJoint) {
        var shared = ids(s.teacher_ids).filter(function (t) { return cTeachers.indexOf(t) >= 0; });
        shared.forEach(function (t) {
          out.push({ type: 'teacher', msg: 'ครู ' + tName(t, ctx) + ' สอน ' + groupLabel(s, ctx) + ' อยู่แล้ว', hard: true, with: s.id });
        });
        if (cand.room_id && String(s.room_id) === String(cand.room_id) && !(ctx.rooms[cand.room_id] && ctx.rooms[cand.room_id].shareable)) {
          out.push({ type: 'room', msg: 'ห้อง ' + rName(cand.room_id, ctx) + ' ถูกใช้โดย ' + groupLabel(s, ctx), hard: true, with: s.id });
        }
      }
    });
    cTeachers.forEach(function (t) {
      var T = ctx.teachers[t];
      if (!T) return;
      for (var p = start; p < start + len; p++) {
        if (T.un[day + '-' + p]) { out.push({ type: 'unavail', msg: 'ครู ' + T.name + ' ไม่ว่างวัน/คาบนี้', hard: true }); break; }
      }
      if (T.max && (dayLoad[t] || 0) + len > T.max) out.push({ type: 'maxday', msg: 'ครู ' + T.name + ' สอนเกิน ' + T.max + ' คาบในวันนี้', hard: false });
    });
    return out;
  }

  function tName(id, ctx) { return (ctx.teachers[id] && ctx.teachers[id].name) || id; }
  function rName(id, ctx) { return (ctx.rooms[id] && ctx.rooms[id].name) || id; }
  function groupLabel(s, ctx) { var g = ctx.groups[s.group_id]; return g ? (g.code || g.name) : s.group_id; }
  function courseLabel(s, ctx) { var c = ctx.courses[s.course_id]; return c ? c.code + ' ' + c.name : s.course_id; }

  /** จำนวนคาบต่อสัปดาห์ของแผน */
  function planPeriods(p, ctx) {
    var n = Number(p.periods);
    var cc = ctx.courses && ctx.courses[p.course_id];
    if (!n && cc && cc.course_type === 'project') return 4; // ระเบียบข้อ 7: วิชาโครงงาน 4 หน่วยกิต = 4 ชม./สัปดาห์/ห้อง
    if (!n) { var c = ctx.courses[p.course_id]; n = c ? (Number(c.theory) || 0) + (Number(c.practice) || 0) : 0; }
    return n;
  }

  function splitError(p, ctx) {
    var split = String(p.split || '').trim();
    if (!split) return '';
    if (!/^[1-9][0-9]*(\+[1-9][0-9]*)*$/.test(split)) return 'ระบุการแบ่งคาบเป็นจำนวนเต็มบวก เช่น 2+1';
    if (split.split('+').map(Number).reduce(function (a, b) { return a + b; }, 0) !== planPeriods(p, ctx)) return 'ผลรวมการแบ่งคาบต้องเท่ากับจำนวนคาบต่อสัปดาห์';
    return '';
  }

  /** แบ่งเป็นก้อน เช่น "2+2" หรืออัตโนมัติ (ก้อนละไม่เกิน 4 คาบ) */
  function splitBlocks(p, ctx) {
    var n = planPeriods(p, ctx);
    if (!n) return [];
    var parts = String(p.split || '').split('+').map(Number).filter(function (x) { return x > 0; });
    var sum = parts.reduce(function (a, b) { return a + b; }, 0);
    if (parts.length && sum === n) return parts;
    if (n <= 4) return [n];
    var k = Math.ceil(n / 4), base = Math.floor(n / k), extra = n % k, res = [];
    for (var i = 0; i < k; i++) res.push(base + (i < extra ? 1 : 0));
    return res;
  }

  /** ก้อนที่ยังไม่ได้ลงตาราง ของแต่ละแผน */
  function remainingBlocks(p, sessions, ctx) {
    var blocks = splitBlocks(p, ctx).slice().sort(function (a, b) { return b - a; });
    var placed = sessions.filter(function (s) { return String(s.plan_id) === String(p.id); }).map(function (s) { return Number(s.length); });
    var left = planPeriods(p, ctx) - placed.reduce(function (a, b) { return a + b; }, 0);
    if (left <= 0) return [];
    placed.forEach(function (len) { var i = blocks.indexOf(len); if (i >= 0) blocks.splice(i, 1); });
    var sum = blocks.reduce(function (a, b) { return a + b; }, 0);
    if (sum !== left) blocks = splitBlocks({ periods: left, split: '' }, ctx);
    return blocks;
  }

  /** รวมแผนที่เรียนร่วม (joint_key เดียวกัน) เป็นหน่วยเดียว */
  function buildUnits(plans, sessions, ctx) {
    var byKey = {}, units = [];
    plans.forEach(function (p) {
      if (!ids(p.teacher_ids).length && !p.group_id) return;
      var key = p.joint_key ? 'J:' + p.joint_key : 'P:' + p.id;
      if (!byKey[key]) { byKey[key] = { key: key, plans: [] }; units.push(byKey[key]); }
      byKey[key].plans.push(p);
    });
    var blocks = [];
    units.forEach(function (u) {
      var pattern = null;
      u.plans.forEach(function (p) {
        var parts = splitBlocks(p, ctx).slice().sort(function (a, b) { return a - b; }).join('+');
        if (pattern !== null && pattern !== parts) throw new Error('การแบ่งคาบของกลุ่มที่ใช้รหัสเรียนร่วมเดียวกันไม่ตรงกัน กรุณาตรวจแผนของทุกกลุ่มในชุดเรียนร่วมก่อนจัดอัตโนมัติ');
        pattern = parts;
      });
      /* ก้อนที่เหลือคิดรายกลุ่ม กลุ่มที่ลงครบแล้วไม่ถูกเพิ่มคาบซ้ำ และวางร่วมกันเฉพาะกลุ่มที่ขาดก้อนเดียวกัน */
      var byRemain = {}, order = [];
      u.plans.forEach(function (p) {
        var left = remainingBlocks(p, sessions, ctx);
        if (!left.length) return;
        var sig = left.slice().sort(function (a, b) { return a - b; }).join('+');
        if (!byRemain[sig]) { byRemain[sig] = { key: u.key + '#' + sig, plans: [] }; order.push(sig); }
        byRemain[sig].plans.push(p);
      });
      order.forEach(function (sig) {
        var sub = byRemain[sig];
        remainingBlocks(sub.plans[0], sessions, ctx).forEach(function (len) { blocks.push({ unit: sub, length: len }); });
      });
    });
    return blocks;
  }

  function teacherLoad(plans, ctx) {
    var load = {};
    plans.forEach(function (p) { ids(p.teacher_ids).forEach(function (t) { load[t] = (load[t] || 0) + planPeriods(p, ctx); }); });
    return load;
  }

  // ---------- ดัชนีการใช้ช่องเวลาแบบ bitmask (เร็ว) ----------
  function Occ(ctx) {
    this.ctx = ctx; this.m = {}; this.load = {}; this.gc = {};
    this.blocked = {};
    var self = this;
    ctx.days.forEach(function (d) {
      var b = 0;
      ctx.lunch.forEach(function (p) { b |= (1 << p); });
      for (var p = 1; p <= ctx.periods; p++) if (ctx.locked[d + '-' + p]) b |= (1 << p);
      self.blocked[d] = b;
    });
    this.un = {};
    Object.keys(ctx.teachers).forEach(function (t) {
      var u = {}; Object.keys(ctx.teachers[t].un).forEach(function (k) { var x = k.split('-'); u[x[0]] = (u[x[0]] || 0) | (1 << Number(x[1])); });
      self.un[t] = u;
    });
  }
  Occ.prototype.keys = function (s) {
    var k = ['g:' + s.group_id];
    ids(s.teacher_ids).forEach(function (t) { k.push('t:' + t); });
    if (s.room_id && !(this.ctx.rooms[s.room_id] && this.ctx.rooms[s.room_id].shareable)) k.push('r:' + s.room_id);
    return k;
  };
  Occ.prototype.mask = function (st, len) { return ((1 << len) - 1) << st; };
  Occ.prototype.add = function (s, sign) {
    var self = this, d = Number(s.day), mk = this.mask(Number(s.start), Number(s.length));
    // แผนเรียนร่วม: ครู/ห้องนับครั้งเดียวต่อหน่วย (ใช้ตัวนับ)
    this.keys(s).forEach(function (k) {
      var row = self.m[k] || (self.m[k] = {});
      var cnt = row['c' + d] || (row['c' + d] = {});
      for (var p = Number(s.start); p < Number(s.start) + Number(s.length); p++) {
        cnt[p] = (cnt[p] || 0) + sign;
        if (cnt[p] > 0) row[d] = (row[d] || 0) | (1 << p); else row[d] = (row[d] || 0) & ~(1 << p);
      }
    });
    var gk = s.group_id + '|' + s.course_id;
    this.gc[gk] = this.gc[gk] || {}; this.gc[gk][d] = (this.gc[gk][d] || 0) + sign;
    ids(s.teacher_ids).forEach(function (t) { var L = self.load[t] || (self.load[t] = {}); L[d] = (L[d] || 0) + sign * Number(s.length); });
  };
  Occ.prototype.used = function (k, d) { var r = this.m[k]; return (r && r[d]) || 0; };
  function popcount(x) { var c = 0; while (x) { x &= x - 1; c++; } return c; }

  function unitInfo(block, ctx) {
    var plans = block.unit.plans, t = {}, r = {};
    plans.forEach(function (p) {
      ids(p.teacher_ids).forEach(function (x) { t[x] = 1; });
      if (p.room_id && !(ctx.rooms[p.room_id] && ctx.rooms[p.room_id].shareable)) r[p.room_id] = 1;
    });
    return { teachers: Object.keys(t), rooms: Object.keys(r), groups: plans.map(function (p) { return String(p.group_id); }) };
  }

  /** ช่องที่วางได้ทั้งหมดของก้อน เรียงจากดีที่สุด */
  function slotsFor(block, occ, ctx, rnd) {
    var info = block.info || (block.info = unitInfo(block, ctx));
    var res = [], len = block.length, lunch = ctx.lunch[0];
    ctx.days.forEach(function (d) {
      for (var st = 1; st + len - 1 <= ctx.periods; st++) {
        var mk = occ.mask(st, len);
        if (occ.blocked[d] & mk) continue;
        var ok = true, i, score = st * 1.5, soft = 0;
        for (i = 0; ok && i < info.groups.length; i++) if (occ.used('g:' + info.groups[i], d) & mk) ok = false;
        for (i = 0; ok && i < info.teachers.length; i++) {
          var t = info.teachers[i];
          if (occ.used('t:' + t, d) & mk) ok = false;
          else if (occ.un[t] && (occ.un[t][d] & mk)) ok = false;
          else { var T = ctx.teachers[t]; if (T && T.max && ((occ.load[t] && occ.load[t][d]) || 0) + len > T.max) soft++; }
        }
        for (i = 0; ok && i < info.rooms.length; i++) if (occ.used('r:' + info.rooms[i], d) & mk) ok = false;
        if (!ok) continue;
        score += soft * 40;
        block.unit.plans.forEach(function (p) {
          score += popcount(occ.used('g:' + p.group_id, d)) * 2;
          var g = occ.gc[p.group_id + '|' + p.course_id]; if (g && g[d] > 0) score += 60;
        });
        if (lunch && st < lunch && st + len - 1 > lunch) score += 1000;
        score += rnd() * 3;
        res.push({ day: d, start: st, score: score });
      }
    });
    res.sort(function (a, b) { return a.score - b.score; });
    return res;
  }

  function makeSessions(block, slot) {
    return block.unit.plans.map(function (p) {
      return { plan_id: p.id, group_id: p.group_id, course_id: p.course_id, teacher_ids: p.teacher_ids, room_id: p.room_id || '', day: slot.day, start: slot.start, length: block.length };
    });
  }

  function mulberry(seed) {
    return function () {
      seed |= 0; seed = seed + 0x6D2B79F5 | 0;
      var t = Math.imul(seed ^ seed >>> 15, 1 | seed);
      t = t + Math.imul(t ^ t >>> 7, 61 | t) ^ t;
      return ((t ^ t >>> 14) >>> 0) / 4294967296;
    };
  }

  /**
   * จัดอัตโนมัติ
   * fixed = คาบที่มีอยู่แล้ว (ไม่ย้าย), plans = แผนที่ต้องจัด
   * คืน {added: [sessions...], unplaced: [{plan_id, plans, length}]}
   */
  function auto(plans, fixed, ctx, opt) {
    opt = opt || {};
    var attempts = opt.attempts || 8;
    var deadline = opt.deadline || (Date.now() + 240000);
    var load = teacherLoad(plans, ctx);
    var best = null;
    for (var a = 0; a < attempts && Date.now() < deadline; a++) {
      var rnd = mulberry(1234 + a * 7919);
      var occ = new Occ(ctx);
      fixed.forEach(function (s) { occ.add(s, 1); });
      var blocks = buildUnits(plans, fixed, ctx);
      blocks.forEach(function (b) {
        b.info = unitInfo(b, ctx);
        b.diff = b.length * 10 + b.unit.plans.length * 15 +
          b.info.teachers.reduce(function (m, t) { return Math.max(m, load[t] || 0); }, 0) +
          (b.info.rooms.length ? 8 : 0) + rnd() * (a === 0 ? 0 : 15);
      });
      blocks.sort(function (x, y) { return y.diff - x.diff; });
      var placed = [], unplaced = [];
      blocks.forEach(function (b) {
        var c = slotsFor(b, occ, ctx, rnd)[0];
        if (!c) { unplaced.push(b); return; }
        b.slot = c; b.sessions = makeSessions(b, c);
        b.sessions.forEach(function (s) { occ.add(s, 1); });
        placed.push(b);
      });
      // ซ่อม: ย้ายก้อนที่ขวางอยู่ (ไม่แตะคาบเดิม) แล้ววางก้อนค้าง
      for (var pass = 0; pass < 3 && unplaced.length && Date.now() < deadline; pass++) {
        unplaced = repair(unplaced, placed, occ, ctx, rnd, deadline);
      }
      var added = [];
      placed.forEach(function (b) { b.sessions.forEach(function (s) { s.id = 'new' + added.length; added.push(s); }); });
      var res = { added: added, unplaced: unplaced.map(function (b) { return { plan_id: b.unit.plans[0].id, plans: b.unit.plans.map(function (p) { return p.id; }), length: b.length }; }) };
      if (!best || res.unplaced.length < best.unplaced.length) best = res;
      if (best.unplaced.length === 0) break;
    }
    return best || { added: [], unplaced: [] };
  }

  function blocksAt(b, day, st, placed, ctx) {
    var info = b.info, mkEnd = st + b.length - 1, out = [];
    placed.forEach(function (x) {
      if (Number(x.slot.day) !== day) return;
      var e = x.slot.start + x.length - 1;
      if (x.slot.start > mkEnd || e < st) return;
      var xi = x.info;
      var hit = xi.groups.some(function (g) { return info.groups.indexOf(g) >= 0; }) ||
        xi.teachers.some(function (t) { return info.teachers.indexOf(t) >= 0; }) ||
        xi.rooms.some(function (r) { return info.rooms.indexOf(r) >= 0; });
      if (hit) out.push(x);
    });
    return out;
  }

  function repair(unplaced, placed, occ, ctx, rnd, deadline) {
    var still = [];
    unplaced.forEach(function (b) {
      if (Date.now() > deadline) { still.push(b); return; }
      var done = false;
      // ช่องที่ติดเฉพาะก้อนอื่นที่ย้ายได้
      var options = [];
      ctx.days.forEach(function (d) {
        for (var st = 1; st + b.length - 1 <= ctx.periods; st++) {
          var mk = occ.mask(st, b.length);
          if (occ.blocked[d] & mk) continue;
          if (b.info.teachers.some(function (t) { return occ.un[t] && (occ.un[t][d] & mk); })) continue;
          var vs = blocksAt(b, d, st, placed, ctx);
          // ส่วนที่ขวางต้องมาจากก้อนที่ย้ายได้เท่านั้น
          vs.forEach(function (v) { v.sessions.forEach(function (s) { occ.add(s, -1); }); });
          var free = !b.info.groups.some(function (g) { return occ.used('g:' + g, d) & mk; }) &&
            !b.info.teachers.some(function (t) { return occ.used('t:' + t, d) & mk; }) &&
            !b.info.rooms.some(function (r) { return occ.used('r:' + r, d) & mk; });
          vs.forEach(function (v) { v.sessions.forEach(function (s) { occ.add(s, 1); }); });
          if (free && vs.length && vs.length <= 2) options.push({ day: d, start: st, victims: vs, r: rnd() + vs.length });
        }
      });
      options.sort(function (x, y) { return x.r - y.r; });
      for (var i = 0; i < options.length && !done && i < 40; i++) {
        var o = options[i];
        o.victims.forEach(function (v) { v.sessions.forEach(function (s) { occ.add(s, -1); }); });
        var mine = makeSessions(b, o);
        mine.forEach(function (s) { occ.add(s, 1); });
        var moves = [], ok = true;
        for (var k = 0; k < o.victims.length && ok; k++) {
          var v = o.victims[k];
          var c = slotsFor(v, occ, ctx, rnd)[0];
          if (!c) { ok = false; break; }
          var ns = makeSessions(v, c); ns.forEach(function (s) { occ.add(s, 1); });
          moves.push({ v: v, slot: c, sessions: ns });
        }
        if (ok) {
          moves.forEach(function (m) { m.v.slot = m.slot; m.v.sessions = m.sessions; });
          b.slot = { day: o.day, start: o.start }; b.sessions = mine; placed.push(b);
          done = true;
        } else {
          moves.forEach(function (m) { m.sessions.forEach(function (s) { occ.add(s, -1); }); });
          mine.forEach(function (s) { occ.add(s, -1); });
          o.victims.forEach(function (v) { v.sessions.forEach(function (s) { occ.add(s, 1); }); });
        }
      }
      if (!done) still.push(b);
    });
    return still;
  }

  /** วิชาฝึกงาน/ทวิภาคี/เรียนที่สถานประกอบการ (ระเบียบข้อ 6) */
  function isWorkplace(planOrSession, ctx) {
    var p = ctx.plans[planOrSession.plan_id] || planOrSession;
    var c = ctx.courses[p.course_id];
    return String(p.workplace) === 'true' || !!(c && c.course_type === 'internship');
  }

  return { linkPeriodDays: linkPeriodDays, parsePeriodNumbers: parsePeriodNumbers, hourGroupKey: hourGroupKey, hourTypes: hourTypes, isWorkplace: isWorkplace, ids: ids, makeCtx: makeCtx, check: check, auto: auto, splitError: splitError, splitBlocks: splitBlocks, remainingBlocks: remainingBlocks, planPeriods: planPeriods };
})();

