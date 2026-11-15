# ============================================================
#  Digital-Elect - tache planifiee du miroir local
# ============================================================
#  Cree une tache "Digital-Elect miroir local" qui copie la base
#  de production vers PostgreSQL local toutes les 3 heures.
#
#  Lancement (PowerShell, sans accents) :
#    powershell -ExecutionPolicy Bypass -File outils\planifier-miroir.ps1
# ============================================================

$ErrorActionPreference = 'Stop'

$racine = Split-Path -Parent $PSScriptRoot
$php = 'C:\xampp\php\php.exe'
$script = Join-Path $PSScriptRoot 'miroir-local.php'
$nomTache = 'Digital-Elect miroir local'

if (-not (Test-Path $php)) { throw "PHP introuvable : $php" }
if (-not (Test-Path $script)) { throw "Script introuvable : $script" }

$action = New-ScheduledTaskAction `
    -Execute $php `
    -Argument ('"' + $script + '" --silencieux') `
    -WorkingDirectory $racine

$declencheur = New-ScheduledTaskTrigger `
    -Once `
    -At (Get-Date).AddMinutes(2) `
    -RepetitionInterval (New-TimeSpan -Hours 3) `
    -RepetitionDuration (New-TimeSpan -Days 3650)

$reglages = New-ScheduledTaskSettingsSet `
    -StartWhenAvailable `
    -AllowStartIfOnBatteries `
    -DontStopIfGoingOnBatteries `
    -MultipleInstances IgnoreNew `
    -ExecutionTimeLimit (New-TimeSpan -Minutes 30)

$principal = New-ScheduledTaskPrincipal `
    -UserId $env:USERNAME `
    -LogonType Interactive `
    -RunLevel Limited

Register-ScheduledTask `
    -TaskName $nomTache `
    -Action $action `
    -Trigger $declencheur `
    -Settings $reglages `
    -Principal $principal `
    -Description 'Copie la base Digital-Elect de production vers PostgreSQL local (lecture seule).' `
    -Force | Out-Null

Write-Host "Tache planifiee creee : $nomTache"
Write-Host "Frequence : toutes les 3 heures, des que vous etes connecte."
Write-Host "Test immediat : Start-ScheduledTask -TaskName '$nomTache'"
