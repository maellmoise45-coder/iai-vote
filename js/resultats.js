/* ============================================================
   Page des résultats publics — Digital-Elect
   ============================================================ */
let elections = [];

async function chargerElections() {
    const { data } = await API.get('elections.php');
    const select = document.getElementById('select-election');
    elections = (data.data || []).filter(e => e.resultats_publics || e.statut === 'publiee');

    if (elections.length === 0) {
        select.innerHTML = '<option>Aucun résultat publié</option>';
        document.getElementById('zone-resultats').innerHTML =
            `<div class="carte"><p class="vide">Aucun résultat n'est encore publié.</p></div>`;
        return;
    }

    select.innerHTML = elections.map(e =>
        `<option value="${e.id}">${echapper(e.titre)}</option>`).join('');
    select.addEventListener('change', () => chargerResultats(select.value));
    chargerResultats(elections[0].id);
}

async function chargerResultats(electionId) {
    const zone = document.getElementById('zone-resultats');
    zone.innerHTML = '<div class="carte"><p class="vide">Chargement…</p></div>';

    const { ok, data } = await API.get('resultats.php?election_id=' + electionId);
    if (!ok || !data.success) {
        zone.innerHTML = `<div class="carte"><p class="vide">${echapper(data.error || 'Résultats indisponibles.')}</p></div>`;
        return;
    }

    let html = `<div class="carte">
        <div class="carte-titre">${echapper(data.election.titre)} ${badgeStatut(data.election.statut)}</div>
        <p style="color:var(--gris); margin-bottom:16px; font-size:14px;">
            ${data.total_votes} vote(s) exprimé(s) — participation ${data.participation}% (${data.inscrits} inscrits)
        </p>`;

    if (data.candidats.length === 0) {
        html += '<p class="vide">Aucun candidat.</p>';
    } else {
        for (const c of data.candidats) {
            const photo = c.photo_url
                ? `<img class="vignette" src="${echapper(c.photo_url)}" alt="">`
                : `<div class="vignette vignette-vide">${echapper(((c.prenom || '')[0] || '') + ((c.nom || '')[0] || ''))}</div>`;
            html += `<div class="barre-resultat">
                <div class="barre-tete">
                    <span style="display:flex; align-items:center; gap:10px;">${photo} ${echapper(c.prenom)} ${echapper(c.nom)}</span>
                    <span>${c.nombre_voix} voix (${c.pourcentage}%)</span></div>
                <div class="barre-fond"><div class="barre-remplissage" style="width:${c.pourcentage}%"></div></div>
            </div>`;
        }
    }

    // Bulletins nuls (chrono écoulé) : comptés à part des candidats.
    const nuls = Number(data.votes_nuls || 0);
    if (nuls > 0) {
        const total = Number(data.total_votes || 0);
        const pct = total > 0 ? Math.round(nuls * 100 / total) : 0;
        html += `<div class="barre-resultat">
            <div class="barre-tete">
                <span style="display:flex; align-items:center; gap:10px;">
                    <span class="vignette vignette-nulle">⊘</span> Votes nuls (temps de vote écoulé)
                </span>
                <span>${nuls} voix</span></div>
            <div class="barre-fond"><div class="barre-remplissage barre-nulle" style="width:${pct}%"></div></div>
        </div>`;
    }

    html += '</div>';
    zone.innerHTML = html;
}

chargerElections();
