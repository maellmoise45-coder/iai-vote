/* ============================================================
   Page des résultats — Digital-Elect
   ------------------------------------------------------------
   Diagramme en bandes (Graphe). Si le scrutin est en cours et
   que l'utilisateur est connecté, les résultats se rafraîchissent
   toutes les 5 secondes. Les électeurs doivent avoir voté pour
   accéder au direct (contrôle côté serveur).
   ============================================================ */

let elections = [];
let liveMinuteur = null;
let metaCandidats = new Map();

function arreterLive() {
    if (liveMinuteur) {
        clearInterval(liveMinuteur);
        liveMinuteur = null;
    }
}

async function chargerElections() {
    const { data } = await API.get('elections.php');
    const select = document.getElementById('select-election');
    const utilisateur = API.getUser();
    const peutVoirDirect = !!utilisateur;

    elections = (data.data || []).filter(e =>
        e.resultats_publics
        || e.statut === 'publiee'
        || (peutVoirDirect && e.statut === 'en_cours')
    );

    if (elections.length === 0) {
        select.innerHTML = '<option>Aucun résultat disponible</option>';
        document.getElementById('zone-resultats').innerHTML =
            `<div class="carte"><p class="vide">Aucun résultat n'est encore publié.
             Les résultats en direct apparaissent après le vote, pendant le scrutin.</p></div>`;
        return;
    }

    select.innerHTML = elections.map(e =>
        `<option value="${e.id}">${echapper(e.titre)}${e.statut === 'en_cours' ? ' — en direct' : ''}</option>`
    ).join('');

    select.addEventListener('change', () => chargerResultats(select.value));
    chargerResultats(elections[0].id);
}

function completerCandidats(candidats) {
    return (candidats || []).map(c => {
        const m = metaCandidats.get(Number(c.id)) || {};
        return {
            ...c,
            photo_url: c.photo_url || m.photo_url || null,
            programme: c.programme || m.programme || '',
        };
    });
}

function rendreResultats(data) {
    const nuls = Number(data.votes_nuls || 0);
    const enDirect = data.election.statut === 'en_cours';
    const heure = new Date().toLocaleTimeString('fr-FR', {
        hour: '2-digit', minute: '2-digit', second: '2-digit',
    });

    let html = `<div class="carte">
        <div class="carte-titre">
            <span>${echapper(data.election.titre)} ${badgeStatut(data.election.statut)}</span>
            ${enDirect ? `<span class="badge badge-vert">● En direct — ${heure}</span>` : ''}
        </div>
        <p class="resume-resultats">
            <b>${data.total_votes}</b> vote(s) exprimé(s) — participation
            <b>${data.participation}%</b> (${data.inscrits} inscrits)
            ${nuls > 0 ? ` — dont <b>${nuls}</b> nul(s)` : ''}
        </p>`;

    html += Graphe.rendre(completerCandidats(data.candidats), {
        votants: data.total_votes || 0,
        nuls: nuls > 0 ? nuls : null,
        note: `${data.votes_valides || 0} bulletin(s) valide(s) sur ${data.total_votes || 0} vote(s) exprimé(s) — pourcentages calculés sur les votants.`,
    });

    if (nuls > 0) {
        html += `<div class="nuls-bloc">⊘ ${nuls} bulletin(s) nul(s) — bulletins blancs et temps de vote écoulé.</div>`;
    }

    if (enDirect) {
        html += `<p class="graphe-note">Mise à jour automatique toutes les 5 secondes.</p>`;
    }

    html += `</div>`;
    return html;
}

async function chargerResultats(electionId) {
    const zone = document.getElementById('zone-resultats');
    const estDirect = elections.some(e => String(e.id) === String(electionId) && e.statut === 'en_cours');

    arreterLive();
    zone.innerHTML = '<div class="carte"><p class="vide">Chargement…</p></div>';

    const { ok, data } = await API.get('resultats.php?election_id=' + encodeURIComponent(electionId));
    if (!ok || !data.success) {
        const utilisateur = API.getUser();
        const lienVote = utilisateur && utilisateur.role === 'electeur'
            ? `<p style="text-align:center; margin-top:12px;">
                   <a class="btn btn-primaire btn-petit" href="vote.html">Aller voter</a>
               </p>`
            : '';
        zone.innerHTML = `<div class="carte">
            <p class="vide">${echapper(data.error || 'Résultats indisponibles.')}</p>
            ${lienVote}
        </div>`;
        return;
    }

    // Mémorise photos/programmes pour les rafraîchissements compacts.
    metaCandidats = new Map(
        (data.candidats || []).map(c => [Number(c.id), c])
    );

    zone.innerHTML = rendreResultats(data);

    if (estDirect && API.getUser()) {
        liveMinuteur = setInterval(() => rafraichirDirect(electionId), 5000);
    }
}

async function rafraichirDirect(electionId) {
    const zone = document.getElementById('zone-resultats');
    if (!zone || !document.body.contains(zone)) {
        arreterLive();
        return;
    }

    const { ok, data } = await API.get(
        `resultats.php?election_id=${encodeURIComponent(electionId)}&compact=1`
    );
    if (!ok || !data.success) return; // on conserve le dernier affichage

    zone.innerHTML = rendreResultats(data);

    // Le scrutin vient de se terminer : on arrête le direct.
    if (data.election.statut !== 'en_cours') {
        arreterLive();
    }
}

// Recharger les métadonnées (photos) si le direct dure longtemps :
// elles ne changent pas, inutile de les redemander à chaque cycle.

window.addEventListener('beforeunload', arreterLive);

chargerElections();
