@echo off
chcp 65001 >nul
cd /d "%~dp0"
echo === ติดตั้งระบบจัดตารางเรียน ===
set "PHP=C:\xampp\php\php.exe"
if not exist "%PHP%" set "PHP=php"
if not exist .env copy .env.example .env >nul
echo ตรวจค่าฐานข้อมูลในไฟล์ .env (กำลังเปิดให้) แล้วบันทึกและปิดไฟล์
notepad .env
"%PHP%" scripts\install.php
echo.
echo เสร็จแล้ว - ตั้งค่า Apache พอร์ต 8080 ตามคู่มือ README.md แล้วเปิด http://localhost:8080
pause
