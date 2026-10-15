@echo off
REM ============================================================
REM  Demarrer PostgreSQL - Digital-Elect
REM ============================================================
echo Demarrage de PostgreSQL...
"C:\xampp\pgsql\bin\pg_ctl.exe" -D "C:\xampp\pgsql\data" -l "C:\xampp\pgsql\data\logfile.log" start
echo.
echo PostgreSQL est demarre sur 127.0.0.1:5432 (base : iai_vote)
pause
