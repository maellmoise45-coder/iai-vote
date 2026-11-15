# ============================================================
#  Digital-Elect - supprime la tache planifiee du miroir local
# ============================================================
$ErrorActionPreference = 'Stop'
$nomTache = 'Digital-Elect miroir local'

$tache = Get-ScheduledTask -TaskName $nomTache -ErrorAction SilentlyContinue
if (-not $tache) {
    Write-Host "Aucune tache nommee '$nomTache'."
    exit 0
}

Unregister-ScheduledTask -TaskName $nomTache -Confirm:$false
Write-Host "Tache supprimee : $nomTache"
