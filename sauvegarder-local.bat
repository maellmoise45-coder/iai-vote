@echo off
setlocal
rem ============================================================
rem  Digital-Elect - copie la base de production vers la base
rem  PostgreSQL locale (lecture seule cote production).
rem ============================================================
set "PHP=php"
where php >nul 2>nul || set "PHP=C:\xampp\php\php.exe"

"%PHP%" "%~dp0outils\miroir-local.php" %*

if errorlevel 1 (
    echo.
    echo La copie a echoue. Consultez outils\miroir.log.
    pause
)
endlocal
