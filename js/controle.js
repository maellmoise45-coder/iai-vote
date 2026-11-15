/* ============================================================
   Espace contrôleur — Digital-Elect
   ------------------------------------------------------------
   Accès limité : consultation du vote en direct (participation
   globale, détail par zone) et du journal d'audit.
   Aucune gestion d'élection, de candidat ou de compte.
   ============================================================ */

const user = protegerPage(['controleur']);

if (user) {
    document.getElementById('entete-user').innerHTML =
        `<b>${echapper(user.prenom)} ${echapper(user.nom)}</b><small>Contrôleur</small>`;
}

document.getElementById('btn-deconnexion').addEventListener('click', async () => {
    await API.post('auth.php', { action: 'logout', token: API.getToken() });
    API.clearSession();
    location.href = 'index.html';
});

/* ---------- Navigation par onglets ---------- */
let vueActive = 'suivi';

document.getElementById('onglets').addEventListener('click', (e) => {
    const bouton = e.target.closest('.onglet');
    if (!bouton) return;
    document.querySelectorAll('.onglet').forEach(o => o.classList.remove('actif'));
    bouton.classList.add('actif');
    vueActive = bouton.dataset.vue;
    afficherVue();
});

function afficherVue() {
    // Le rafraîchissement live ne tourne que sur l'onglet Suivi.
    Suivi.arreter();

    document.getElementById('vue-suivi').classList.toggle('cache', vueActive !== 'suivi');
    document.getElementById('vue-journal').classList.toggle('cache', vueActive !== 'journal');

    if (vueActive === 'suivi') {
        Suivi.demarrer(
            document.getElementById('select-suivi-election'),
            document.getElementById('zone-suivi')
        );
    } else if (vueActive === 'journal') {
        chargerJournal();
    }
}

/* ---------- Journal d'audit ---------- */
async function chargerJournal(silencieux = false) {
    const conteneur = document.getElementById('vue-journal');
    if (!silencieux) {
        conteneur.innerHTML = '<div class="carte"><p class="vide">Chargement du journal…</p></div>';
    }

    const { ok, data } = await API.get('journal.php?limite=200');
    if (!ok || !data.success) {
        if (silencieux) return;
        conteneur.innerHTML = `<div class="carte"><p class="vide">${echapper(data.error || 'Journal indisponible.')}</p></div>`;
        return;
    }

    const lignes = data.data || [];

    // Rien n'a changé : on ne reconstruit pas le tableau.
    const signature = lignes.map(l => l.id).join(',');
    if (silencieux && conteneur.dataset.signature === signature) return;
    conteneur.dataset.signature = signature;

    let html = `<div class="carte"><div class="carte-titre">Journal des actions</div>`;
    if (lignes.length === 0) {
        html += `<p class="vide">Aucune action enregistrée.</p>`;
    } else {
        html += `<div class="tableau-scroll"><table class="tableau">
            <thead><tr><th>Date</th><th>Acteur</th><th>Action</th><th>Détails</th><th>IP</th></tr></thead><tbody>`;
        for (const l of lignes) {
            html += `<tr>
                <td style="font-size:13px">${formaterDateHeure(l.date_action)}</td>
                <td>${echapper(l.matricule || '—')}</td>
                <td><span class="badge badge-gris">${echapper(l.action)}</span></td>
                <td style="font-size:12px; color:var(--gris)">${echapper(l.details || '')}</td>
                <td style="font-size:12px; color:var(--gris)">${echapper(l.adresse_ip || '')}</td>
            </tr>`;
        }
        html += `</tbody></table></div>`;
    }
    html += `</div>`;
    conteneur.innerHTML = html;
}

/* ---------- Temps réel ---------- */
async function rafraichirSilencieux() {
    if (vueActive === 'suivi') {
        Suivi.rafraichir();
        return;
    }
    if (vueActive === 'journal') {
        await chargerJournal(true);
    }
}

/* ---------- Démarrage ---------- */
if (user) {
    afficherVue();
    TempsReel.demarrer({ intervalle: 5000, callback: rafraichirSilencieux });
}
