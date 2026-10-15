/* ============================================================
   Page de vote (électeur)
   ------------------------------------------------------------
   Chrono de 1 minute. Le temps restant est calculé par le
   SERVEUR (table ouvertures) : recharger la page ne remet pas
   le compteur à zéro. À l'expiration, le bulletin est déclaré
   NUL et l'électeur est déconnecté (expulsé).
   ============================================================ */

const user = protegerPage(['electeur']);
let electionCourante = null;
let candidatChoisi = null;

const DUREE_VOTE = 60;      // valeur par défaut si le serveur ne répond pas
const chronoExpire = {};    // electionId -> true quand le temps est écoulé
let minuteries = [];        // intervalles du chrono en cours
let expulsionEnCours = false;

if (user) {
    document.getElementById('entete-user').innerHTML =
        `<b>${echapper(user.prenom)} ${echapper(user.nom)}</b><small>Matricule ${echapper(user.matricule)}</small>`;
}

document.getElementById('btn-deconnexion').addEventListener('click', async () => {
    await API.post('auth.php', { action: 'logout', token: API.getToken() });
    API.clearSession();
    location.href = 'index.html';
});

/* ---------- Expulsion : bulletin nul + déconnexion ---------- */
async function expulser(electionId) {
    if (expulsionEnCours) return;
    expulsionEnCours = true;

    minuteries.forEach(clearInterval);
    minuteries = [];

    try {
        await API.post('votes.php', { action: 'expirer', election_id: electionId });
    } catch (e) { /* on déconnecte quand même */ }

    API.clearSession();
    sessionStorage.setItem('iai_expire', '1');
    location.href = 'index.html';
}

/* ---------- Chargement des élections ouvertes ---------- */
async function chargerElections() {
    const zone = document.getElementById('zone-contenu');
    const { ok, data } = await API.get('elections.php');

    if (!ok || !data.success) {
        zone.innerHTML = `<p class="vide">${echapper(data.error || 'Impossible de charger les élections.')}</p>`;
        return;
    }

    // Ne garder que les scrutins ouverts
    const ouvertes = (data.data || []).filter(e => e.statut === 'en_cours');

    if (ouvertes.length === 0) {
        zone.innerHTML = `
            <div class="carte">
                <div class="vide">
                    <div style="font-size:40px;">🗳️</div>
                    <p style="margin-top:10px;">Aucune élection n'est ouverte pour le moment.</p>
                    <p style="margin-top:6px; font-size:13px;">Revenez quand le scrutin sera lancé.</p>
                </div>
            </div>`;
        return;
    }

    minuteries.forEach(clearInterval);
    minuteries = [];
    zone.innerHTML = '';
    for (const election of ouvertes) {
        // Si une expulsion est déclenchée, on arrête le rendu.
        if (expulsionEnCours) return;
        await afficherElection(zone, election);
    }
}

async function afficherElection(zone, election) {
    const carte = document.createElement('div');
    carte.className = 'carte';
    carte.innerHTML = `<div class="carte-titre">${echapper(election.titre)} ${badgeStatut(election.statut)}</div>`;

    // L'électeur a-t-il déjà voté ? (le serveur renvoie aussi le temps restant)
    const { data: etat } = await API.get(`votes.php?action=a_vote&election_id=${election.id}`);

    if (etat.a_vote) {
        if (etat.nul) {
            carte.innerHTML += `
                <div class="alerte alerte-erreur">
                    ⊘ Votre bulletin pour cette élection a été déclaré <b>NUL</b> (temps de vote écoulé).
                    Votre vote est déjà enregistré et ne peut pas être repris.
                </div>`;
        } else {
            carte.innerHTML += `
                <div class="alerte alerte-succes">
                    ✅ Vous avez déjà voté pour cette élection${etat.date_vote ? ' le ' + formaterDateHeure(etat.date_vote) : ''}.
                    Votre bulletin est enregistré de façon anonyme.
                </div>`;
        }
        zone.appendChild(carte);
        return;
    }

    // Candidats
    const { data: cand } = await API.get(`candidats.php?election_id=${election.id}`);
    const candidats = cand.data || [];

    if (candidats.length === 0) {
        carte.innerHTML += `<p class="vide">Aucun candidat n'est encore déclaré.</p>`;
        zone.appendChild(carte);
        return;
    }

    const grille = document.createElement('div');
    grille.className = 'grille-candidats';

    candidats.forEach(c => {
        const el = document.createElement('div');
        el.className = 'candidat';
        const visuel = c.photo_url
            ? `<img class="photo-candidat" src="${echapper(c.photo_url)}" alt="${echapper(c.prenom + ' ' + c.nom)}">`
            : `<div class="avatar">${echapper((c.prenom[0] || '') + (c.nom[0] || ''))}</div>`;
        el.innerHTML = `
            ${visuel}
            <h3>${echapper(c.prenom)} ${echapper(c.nom)}</h3>
            <div class="programme">${echapper(c.programme || 'Aucun programme renseigné.')}</div>`;
        el.addEventListener('click', () => ouvrirModale(election, c, el, grille));
        grille.appendChild(el);
    });

    carte.appendChild(grille);
    demarrerChrono(election, carte, grille, etat.secondes_restantes);
    zone.appendChild(carte);
}

/* ---------- Chrono de vote (temps restant fourni par le serveur) ---------- */
function demarrerChrono(election, carte, grille, secondesRestantes) {
    let restant = parseInt(secondesRestantes, 10);
    if (isNaN(restant)) restant = DUREE_VOTE;

    // Chrono déjà écoulé côté serveur : expulsion immédiate.
    if (restant <= 0) {
        chronoExpire[election.id] = true;
        expulser(election.id);
        return;
    }

    const chrono = document.createElement('div');
    chrono.className = 'chrono';
    const afficherRestant = () => {
        chrono.innerHTML = `⏱️ Temps restant : <b>${restant}</b> s`;
    };
    afficherRestant();
    carte.insertBefore(chrono, grille);

    const minuterie = setInterval(() => {
        restant--;
        if (restant <= 0) {
            clearInterval(minuterie);
            chronoExpire[election.id] = true;
            chrono.classList.add('chrono-expire');
            chrono.innerHTML = '⏱️ Temps écoulé — bulletin nul, déconnexion…';
            grille.querySelectorAll('.candidat').forEach(el => el.classList.add('desactive'));
            fermerModale();
            expulser(election.id);
        } else {
            afficherRestant();
            if (restant <= 10) chrono.classList.add('chrono-alerte');
        }
    }, 1000);

    minuteries.push(minuterie);
}

/* ---------- Modale de confirmation ---------- */
function ouvrirModale(election, candidat, element, grille) {
    if (chronoExpire[election.id]) {
        afficherMessage('message', '⏱️ Le temps de vote est écoulé pour cette élection.', 'erreur');
        return;
    }
    // Un seul candidat sélectionné à la fois
    grille.querySelectorAll('.candidat').forEach(el => el.classList.remove('choisi'));
    element.classList.add('choisi');

    electionCourante = election;
    candidatChoisi = candidat;

    document.getElementById('modale-texte').textContent =
        `Vous allez voter pour ${candidat.prenom} ${candidat.nom} à l'élection « ${election.titre} ». Cette action est définitive.`;
    masquerMessage('modale-message');
    document.getElementById('modale').classList.remove('cache');
}

function fermerModale() {
    document.getElementById('modale').classList.add('cache');
    candidatChoisi = null;
}

document.getElementById('btn-annuler').addEventListener('click', fermerModale);

document.getElementById('btn-confirmer').addEventListener('click', async () => {
    if (!candidatChoisi || !electionCourante) return;

    if (chronoExpire[electionCourante.id]) {
        afficherMessage('modale-message', '⏱️ Le temps de vote est écoulé.');
        fermerModale();
        return;
    }

    const btn = document.getElementById('btn-confirmer');
    btn.disabled = true;
    btn.textContent = 'Enregistrement…';

    const { ok, data } = await API.post('votes.php', {
        election_id: electionCourante.id,
        candidat_id: candidatChoisi.id,
    });

    btn.disabled = false;
    btn.textContent = 'Confirmer le vote';

    if (!ok || !data.success) {
        afficherMessage('modale-message', data.error || 'Erreur lors du vote.');
        return;
    }

    // Le serveur a jugé le chrono écoulé : le bulletin est NUL.
    if (data.nul) {
        fermerModale();
        afficherMessage('message', '⏱️ ' + (data.message || 'Temps écoulé : bulletin nul.'), 'erreur');
        API.clearSession();
        sessionStorage.setItem('iai_expire', '1');
        setTimeout(() => location.href = 'index.html', 1500);
        return;
    }

    fermerModale();
    afficherMessage('message', '✅ Votre vote a bien été enregistré.', 'succes');
    chargerElections();
});

// Démarrage
if (user) chargerElections();
