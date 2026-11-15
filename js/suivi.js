/* ============================================================
   Digital-Elect — suivi du vote en direct
   ------------------------------------------------------------
   Module partagé par le tableau de bord admin et la page
   contrôleur. Il interroge /api/suivi.php et rafraîchit les
   agrégats (participation par zone et résultats live) dès que
   les données changent, via le moteur TempsReel.

   Aucun choix individuel n'est jamais affiché : uniquement
   des totaux par zone et par candidat.
   ============================================================ */

const Suivi = (() => {
    let electionId = null;
    let conteneur = null;
    let selecteur = null;
    let surChangement = null;

    // Photos/programmes vus lors d'un chargement complet : ils ne
    // changent pas pendant le scrutin et sont reutilises pour les
    // rafraichissements compacts (evite ~300 Ko d'images / 5 s).
    const metaCandidats = new Map();
    let electionMeta = null;

    function arreter() {
        if (selecteur && surChangement) {
            selecteur.removeEventListener('change', surChangement);
        }
        surChangement = null;
        conteneur = null;
        selecteur = null;
    }

    function majSelecteur(elections) {
        if (!selecteur) return;
        const signature = elections.map(e => e.id + ':' + e.statut).join('|');
        if (selecteur.dataset.signature !== signature) {
            selecteur.innerHTML = elections.length
                ? elections.map(e =>
                    `<option value="${e.id}">${echapper(e.titre)}${
                        e.zone ? ' (classe ' + echapper(e.zone) + ')' : ''
                    } — ${LIBELLE_STATUT[e.statut] || echapper(e.statut)}</option>`
                  ).join('')
                : '<option value="">Aucune élection</option>';
            selecteur.dataset.signature = signature;
        }
        if (electionId != null) selecteur.value = String(electionId);
    }

    function tableauZones(zones) {
        if (!zones.length) {
            return '<p class="vide">Aucune zone renseignée pour les électeurs.</p>';
        }
        let html = `<div class="tableau-scroll"><table class="tableau">
            <thead><tr>
                <th>Zone</th><th>Étudiants inscrits</th><th>Ont voté</th><th>Participation</th>
            </tr></thead><tbody>`;
        for (const z of zones) {
            html += `<tr>
                <td><b>${echapper(z.zone)}</b></td>
                <td>${z.inscrits}</td>
                <td>${z.votants}</td>
                <td style="min-width:170px;">
                    <div style="display:flex; align-items:center; gap:10px;">
                        <div class="barre-fond" style="flex:1; height:12px;">
                            <div class="barre-remplissage" style="width:${z.pourcentage}%"></div>
                        </div>
                        <span style="font-size:13px; font-weight:700; color:var(--bleu);">${z.pourcentage}%</span>
                    </div>
                </td>
            </tr>`;
        }
        html += '</tbody></table></div>';
        return html;
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

    function resultatsCandidats(candidats, participation) {
        if (!candidats.length) {
            return '<p class="vide">Aucun candidat déclaré.</p>';
        }
        // Diagramme à bandes : noms + photos sous les colonnes.
        const nuls = Number(participation.votes_nuls || 0);
        return Graphe.rendre(completerCandidats(candidats), {
            votants: participation.votants || 0,
            nuls: nuls > 0 ? nuls : null,
            note: `${participation.votes_valides || 0} bulletin(s) valide(s) sur `
                + `${participation.votants || 0} votant(s) — mise à jour automatique en temps réel.`,
        });
    }

    function rendre(data) {
        if (!data.election) {
            return `<div class="carte"><p class="vide">Aucune élection à suivre pour le moment.</p></div>`;
        }

        const e = data.election;
        const p = data.participation || {};
        const heure = new Date(data.rafraichi_at || Date.now())
            .toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit', second: '2-digit' });

        return `
            <div class="carte">
                <div class="carte-titre">
                    <span>${echapper(e.titre)} ${badgeStatut(e.statut)}${
                        e.zone ? ` <span class="badge badge-bleu">Classe ${echapper(e.zone)}</span>` : ''
                    }</span>
                    <span style="font-size:12px; color:var(--gris); font-weight:500;">↻ ${heure}</span>
                </div>

                <div class="grille-stats" style="margin-bottom:4px;">
                    <div class="stat"><div class="valeur">${p.inscrits || 0}</div><div class="libelle">Étudiants inscrits</div></div>
                    <div class="stat"><div class="valeur">${p.votants || 0}</div><div class="libelle">Ont voté</div></div>
                    <div class="stat"><div class="valeur">${p.pourcentage || 0}%</div><div class="libelle">Participation</div></div>
                    <div class="stat"><div class="valeur">${p.votes_nuls || 0}</div><div class="libelle">Bulletins nuls</div></div>
                </div>
            </div>

            <div class="carte">
                <div class="carte-titre">Participation par zone</div>
                <p style="color:var(--gris); font-size:13px; margin-bottom:14px;">
                    Nombre d'étudiants et nombre ayant voté par zone. Le choix de vote
                    individuel n'est jamais affiché.
                </p>
                ${tableauZones(data.zones || [])}
            </div>

            <div class="carte">
                <div class="carte-titre">Résultats en direct</div>
                ${resultatsCandidats(data.candidats || [], p)}
            </div>`;
    }

    async function rafraichir() {
        // On fige la cible : si l'utilisateur change d'onglet pendant
        // la requête, le conteneur est détaché et on ne le réutilise pas.
        const cible = conteneur;
        if (!cible) return;

        // Après un premier chargement complet, seuls les compteurs
        // changent : on demande la version compacte (sans photos).
        const compact = electionId != null && electionMeta === electionId;
        const params = [];
        if (electionId) params.push('election_id=' + encodeURIComponent(electionId));
        if (compact) params.push('compact=1');
        const chemin = 'suivi.php' + (params.length ? '?' + params.join('&') : '');

        let reponse = await API.get(chemin);

        // Election supprimée / identifiant périmé : on repart sur la plus récente.
        if ((!reponse.ok || !reponse.data.success) && electionId) {
            electionId = null;
            electionMeta = null;
            reponse = await API.get('suivi.php');
        }

        if (!cible.isConnected) return;

        if (!reponse.ok || !reponse.data.success) {
            cible.innerHTML = `<div class="carte"><p class="vide">${echapper(reponse.data.error || 'Suivi indisponible.')}</p></div>`;
            return;
        }

        const data = reponse.data;
        if (data.election) {
            electionId = data.election.id;
            if (!data.compact) {
                for (const c of data.candidats || []) {
                    metaCandidats.set(Number(c.id), c);
                }
                electionMeta = Number(data.election.id);
            }
        }
        majSelecteur(data.elections || []);
        cible.innerHTML = rendre(data);
    }

    /**
     * Démarre le suivi.
     * @param {HTMLSelectElement|null} sel   sélecteur d'élection (facultatif)
     * @param {HTMLElement} zone             conteneur à remplir
     */
    function demarrer(sel, zone) {
        arreter();
        selecteur = sel;
        conteneur = zone;

        if (sel) {
            surChangement = () => {
                electionId = parseInt(sel.value, 10) || null;
                rafraichir();
            };
            sel.addEventListener('change', surChangement);
        }

        rafraichir();
    }

    return { demarrer, arreter, rafraichir };
})();
