@echo off
chcp 65001 >nul
cd /d "%~dp0"
echo ลากไฟล์ .xlsx ที่ดาวน์โหลดจาก Google Sheets มาวางที่ไอคอนไฟล์นี้ หรือพิมพ์ชื่อไฟล์
set "F=%~1"
if "%F%"=="" set /p F=ไฟล์ .xlsx:
set "PHP=C:\xampp\php\php.exe"
if not exist "%PHP%" set "PHP=php"
"%PHP%" scripts\import.php "%F%"
pause
