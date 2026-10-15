@echo off
REM ============================================================
REM  Arreter PostgreSQL - Digital-Elect
REM ============================================================
echo Arret de PostgreSQL...
"C:\xampp\pgsql\bin\pg_ctl.exe" -D "C:\xampp\pgsql\data" stop
pause
