/* ============================================================
   Page des résultats — Digital-Elect
   ------------------------------------------------------------
   Diagramme en bandes (Graphe). Les résultats se mettent à jour
   tout seuls : la page interroge une empreinte légère
   (/api/sync.php) et ne recharge les données que si quelque
   chose a changé. Aucun rafraîchissement manuel n'est nécessaire.
   Les électeurs doivent avoir voté pour accéder au direct
   (contrôle côté serveur).
   ============================================================ */

let elections = [];
let metaCandidats = new Map();
let metaElectionId = null;
let signatureListe = '';
let intervalleActuel = 0;

/* ---------- Liste des élections visibles ---------- */
function electionsVisibles(liste) {
    const connecte = !!API.getUser();
    return (liste || []).filter(e =>
        e.resultats_publics
        || e.statut === 'publiee'
        || (connecte && e.statut === 'en_cours')
    );
}

async function chargerElections(silencieux = false) {
    const { ok, data } = await API.get('elections.php');
    if (!ok || !data.success) {
        if (!silencieux) {
            document.getElementById('zone-resultats').innerHTML =
                `<div class="carte"><p class="vide">${echapper(data.error || 'Liste indisponible.')}</p></div>`;
        }
        return;
    }

    elections = electionsVisibles(data.data);
    const select = document.getElementById('select-election');

    if (elections.length === 0) {
        signatureListe = '';
        select.innerHTML = '<option>Aucun résultat disponible</option>';
        document.getElementById('zone-resultats').innerHTML =
            `<div class="carte"><p class="vide">Aucun résultat n'est encore publié.
             Les résultats en direct apparaissent après le vote, pendant le scrutin.</p></div>`;
        return;
    }

    const signature = elections
        .map(e => `${e.id}:${e.statut}:${e.resultats_publics ? 1 : 0}`)
        .join('|');

    if (signature !== signatureListe) {
        signatureListe = signature;
        const choixActuel = select.value;
        select.innerHTML = elections.map(e =>
            `<option value="${e.id}">${echapper(e.titre)}${
                e.zone ? ' — classe ' + echapper(e.zone) : ''
            }${e.statut === 'en_cours' ? ' — en direct' : ''}</option>`
        ).join('');
        if (elections.some(e => String(e.id) === String(choixActuel))) {
            select.value = choixActuel;
        }
    }

    const cible = select.value || elections[0].id;
    await chargerResultats(cible, silencieux);
    ajusterCadence();
}

/* ---------- Détail d'une élection ---------- */
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
            <span>${echapper(data.election.titre)} ${badgeStatut(data.election.statut)}${
                data.election.zone
                    ? ` <span class="badge badge-bleu">Élection de classe · ${echapper(data.election.zone)}</span>`
                    : ''
            }</span>
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
        html += `<p class="graphe-note">Mise à jour automatique en temps réel.</p>`;
    }

    html += `</div>`;
    return html;
}

async function chargerResultats(electionId, silencieux = false) {
    const zone = document.getElementById('zone-resultats');
    const compact = silencieux && String(metaElectionId) === String(electionId);

    if (!silencieux) {
        zone.innerHTML = '<div class="carte"><p class="vide">Chargement…</p></div>';
    }

    const { ok, data } = await API.get(
        'resultats.php?election_id=' + encodeURIComponent(electionId)
        + (compact ? '&compact=1' : '')
    );

    if (!ok || !data.success) {
        if (silencieux) return; // on conserve le dernier affichage
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

    if (!compact) {
        metaCandidats = new Map((data.candidats || []).map(c => [Number(c.id), c]));
        metaElectionId = Number(electionId);
    }

    zone.innerHTML = rendreResultats(data);
}

/* ---------- Temps réel ---------- */
function ajusterCadence() {
    const enDirect = elections.some(e => e.statut === 'en_cours');
    const souhaite = enDirect ? 4000 : 15000;
    if (souhaite === intervalleActuel) return;
    intervalleActuel = souhaite;
    TempsReel.changerIntervalle(souhaite);
}

async function rafraichirPage() {
    if (document.hidden) return;
    await chargerElections(true);
}

/* ---------- Démarrage ---------- */
document.getElementById('select-election').addEventListener('change', (e) => {
    if (e.target.value) chargerResultats(e.target.value, false);
});

(async () => {
    await chargerElections(false);
    TempsReel.demarrer({ intervalle: intervalleActuel || 4000, callback: rafraichirPage });
})();
