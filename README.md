# ระบบจัดตารางเรียน–ตารางสอน
### วิทยาลัยการอาชีพโพนทอง · PHP + MariaDB + Bootstrap 5 + jQuery

## ความสามารถ
- **จัดตารางเรียน–ตารางสอน:** จัดอัตโนมัติ ลากวาง เตือนเมื่อครู ห้อง หรือกลุ่มเรียนชนกัน
- **สอนแทนและสอนชดเชย:** ตรวจสิทธิ์คงเหลือ และติดตามการแนบรายงานผลการสอนชดเชย
- **รายงานการสอนออนไลน์:** ครูรายงานรายคาบ แผนกตรวจและอนุมัติ มีสรุปการส่งรายครู
- **นิเทศนักศึกษาฝึกงาน:** ทะเบียนนักศึกษาในสถานประกอบการ นัดหมาย บันทึกผลนิเทศ และสรุปรายครูนิเทศ
- **ค่าสอนเกินภาระงาน:** ใบเบิก ใบสรุปเสนอเบิก และงบหน้าแผนกวิชา
- **รายงาน:** หน้าเผยแพร่สาธารณะ และส่งออกเป็น Excel (.xlsx)
- **สิทธิ์ผู้ใช้ 3 ระดับ:** ผู้ดูแลระบบ / ตัวแทนแผนก / ครู

---

## สิ่งที่ต้องมี
| โปรแกรม | รุ่น |
|---|---|
| PHP (พร้อม `pdo_mysql`, `mbstring`, `zlib`, `openssl`) | 8.1 ขึ้นไป |
| Apache | 2.4 |
| MariaDB | 10.3 ขึ้นไป (แนะนำ 10.11) |

XAMPP มีครบทุกตัว แถว "MySQL" ใน XAMPP Control Panel คือ MariaDB

## ติดตั้งบน Windows (XAMPP)
1. วางโปรเจกต์ไว้ที่ `C:\xampp\htdocs\tabhub`
2. เปิด Apache และ MySQL ใน XAMPP Control Panel
3. คัดลอก `.env.example` เป็น `.env` แล้วใส่ค่าฐานข้อมูล
4. ดับเบิลคลิก `install.bat` เพื่อสร้างตาราง ถ้ายังไม่มีผู้ดูแลระบบ ระบบจะแสดงรหัสผ่านชั่วคราวของบัญชี `admin` ครั้งเดียว
5. เพิ่มข้อความนี้ท้ายไฟล์ `C:\xampp\apache\conf\extra\httpd-vhosts.conf` แล้วรีสตาร์ต Apache

```apache
Listen 8080
<VirtualHost *:8080>
    ServerName localhost
    DocumentRoot "C:/xampp/htdocs/tabhub/public"
    <Directory "C:/xampp/htdocs/tabhub/public">
        Options -Indexes
        AllowOverride None
        Require all granted
        DirectoryIndex index.php
        FallbackResource /index.php
    </Directory>
</VirtualHost>
```

6. เปิด http://localhost:8080 ส่วนเครื่องอื่นในวิทยาลัยเข้าที่ `http://IP-ของเครื่องนี้:8080` โดยต้องอนุญาตพอร์ต 8080 ใน Windows Firewall ก่อน

> โปรเจกต์นี้ไม่มีข้อมูลจริงของวิทยาลัยใน Git ให้นำเข้าจากไฟล์สำรองหรือจากระบบเดิม

---

## คำสั่งที่ใช้บ่อย
| คำสั่ง | ใช้ทำอะไร |
|---|---|
| `php scripts/install.php` | สร้างตาราง เพิ่มคอลัมน์ที่ขาด และสร้างผู้ดูแลคนแรก |
| `php scripts/import.php ไฟล์.xlsx` | นำเข้าข้อมูลจาก Excel หรือ Google Sheets (แทนที่ทั้งตาราง) |
| `php scripts/import.php ไฟล์.json` | นำเข้าข้อมูลจากไฟล์ JSON (แทนที่ทั้งตาราง) |
| `php scripts/reset-admin.php รหัสใหม่` | ตั้งรหัสผ่าน admin ใหม่ เมื่อลืมรหัส |
| `php scripts/reset-admin.php รหัสใหม่ ชื่อผู้ใช้` | ตั้งรหัสผ่านใหม่ให้บัญชีอื่น |
| `php scripts/make-sql.php` | สร้าง `sql/schema.sql` ใหม่ |

## สำรองข้อมูล
```
C:\xampp\mysql\bin\mariadb-dump -u root -p tabhub > backup.sql
```

## โครงสร้างไฟล์
```
public/index.php     จุดรับคำขอเว็บ (เปิดจากเว็บได้เฉพาะโฟลเดอร์ public)
public/assets/       Bootstrap 5 และ jQuery
src/core.php         ค่าตั้งค่า โครงสร้างตาราง การอ่าน/เขียนฐานข้อมูล
src/app.php          เส้นทางเว็บ รายการ API ที่อนุญาต การจำกัดล็อกอิน
src/sched.php        ตรวจการชนและจัดตารางอัตโนมัติ
src/ot.php           กติกาคำนวณค่าสอนเกินภาระงาน
src/api_core.php     ผู้ใช้ สิทธิ์ ข้อมูลพื้นฐาน
src/api_sched.php    API จัดตาราง รายงาน ส่งออก
src/api_ot.php       API ค่าสอนเกิน สอนแทน สอนชดเชย
src/api_extra.php    API รายงานการสอน นิเทศฝึกงาน งบหน้า
src/tab_import.php   นำเข้าจากระบบ TAB
src/xlsx.php         อ่าน/เขียนไฟล์ Excel
views/               หน้าเว็บ (JavaScript, jQuery, Bootstrap 5)
scripts/             คำสั่งติดตั้งและนำเข้าข้อมูล
sql/schema.sql       โครงสร้างตาราง
```

## ข้อควรรู้
- กติกาจัดตารางและค่าสอนเกินมี 2 ชุดที่ต้องตรงกัน: `src/sched.php` กับ `views/SchedCore.js` และ `src/ot.php` กับ `views/OtCore.js` ถ้าแก้ต้องแก้ทั้งคู่
- ทุกคอลัมน์เก็บเป็นข้อความ และทุกตารางมีคอลัมน์ `_seq` ไว้เรียงลำดับแถว
- ทุกคำขอเป็น transaction ถ้าเกิดข้อผิดพลาดกลางคันข้อมูลจะย้อนกลับทั้งหมด และการเขียนใช้ล็อกเดียวกันเพื่อไม่ให้ข้อมูลทับกัน
- รหัสผ่านแบบเดิม (SHA-256) ยังเข้าระบบได้ และจะถูกเปลี่ยนเป็น bcrypt ตอนล็อกอินสำเร็จ
- ล็อกอินผิด 5 ครั้งจาก IP และชื่อผู้ใช้เดียวกัน จะถูกพัก 15 นาที ถ้าใช้ reverse proxy ให้ตั้ง `TRUST_PROXY=true`
- ไฟล์ `.htaccess` ที่รากโปรเจกต์ห้าม Apache เปิดโฟลเดอร์นี้ผ่านพอร์ต 80 **ห้ามลบ** เพราะมีไฟล์ `.env` และข้อมูลสำรองอยู่
- ปุ่ม "นำเข้าจาก TAB" ต้องใส่ `TAB_API_KEY` ในไฟล์ `.env` ก่อน
- ข้อมูลครูและนักศึกษาเป็นข้อมูลส่วนบุคคล ควรตั้งรหัสผ่าน MariaDB และใช้ HTTPS เมื่อเปิดใช้งานผ่านอินเทอร์เน็ต
