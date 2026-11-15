/* ============================================================
   Tableau de bord — admin / gestionnaire (délégué de classe)
   ------------------------------------------------------------
   V1.2 :
     - le gestionnaire ne crée et ne modifie plus de profil ;
     - il consulte uniquement les électeurs de SA classe et peut
       retirer un intrus de sa liste ;
     - il génère le lien d'inscription de sa classe ;
     - les résultats s'affichent en diagramme en bandes.
   V1.4 :
     - mise à jour automatique (temps réel) via /api/sync.php ;
     - onglet Sauvegarde réservé à l'administrateur.
   ============================================================ */

const user = protegerPage(['admin', 'gestionnaire']);
const estAdmin = user && user.role === 'admin';

const LIBELLE_ROLE = {
    admin: 'Administrateur',
    gestionnaire: 'Délégué de classe',
};

let vueActive = 'elections';
let etatComptes = { role: 'electeur', q: '', page: 1 };

if (user) {
    document.getElementById('entete-user').innerHTML =
        `<b>${echapper(user.prenom)} ${echapper(user.nom)}</b>` +
        `<small>${LIBELLE_ROLE[user.role] || echapper(user.role)}${
            !estAdmin && user.zone ? ' — ' + echapper(user.zone) : ''}</small>`;

    // Le délégué gère les élections de sa classe, sa liste d'élèves
    // et le lien d'inscription de la classe.
    if (!estAdmin) {
        ['onglet-gestionnaires', 'onglet-controleurs', 'onglet-suivi', 'onglet-journal', 'onglet-sauvegarde']
            .forEach(id => document.getElementById(id)?.remove());

        const ongletElections = document.getElementById('onglet-elections');
        if (ongletElections) ongletElections.textContent = 'Élections de classe';

        const ongletInvitation = document.getElementById('onglet-invitation');
        if (ongletInvitation) ongletInvitation.textContent = 'Ma classe';

        vueActive = 'elections';
        document.querySelectorAll('.onglet').forEach(o =>
            o.classList.toggle('actif', o.dataset.vue === 'elections'));
    }
}

document.getElementById('btn-deconnexion').addEventListener('click', async () => {
    await API.post('auth.php', { action: 'logout', token: API.getToken() });
    API.clearSession();
    location.href = 'index.html';
});

// Chacun peut modifier son propre mot de passe.
document.getElementById('btn-mon-mot-de-passe').addEventListener('click', modaleMonMotDePasse);

/* ---------- Modale générique ---------- */
const modale = document.getElementById('modale');
const modaleForm = document.getElementById('modale-form');
let submitModale = null;

function ouvrirModale(titre, champsHtml, onValider, texteBouton = 'Enregistrer') {
    document.getElementById('modale-titre').textContent = titre;
    modaleForm.innerHTML = champsHtml +
        `<div style="display:flex; gap:10px; justify-content:flex-end; margin-top:8px;">
            <button type="button" class="btn btn-neutre" id="modale-annuler">Annuler</button>
            <button type="submit" class="btn btn-primaire">${texteBouton}</button>
         </div>`;
    masquerMessage('modale-message');
    submitModale = onValider;
    document.getElementById('modale-annuler').addEventListener('click', fermerModale);
    modale.classList.remove('cache');
}
function fermerModale() {
    modale.classList.add('cache');
    submitModale = null;
    modaleForm.innerHTML = '';
}
modaleForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (!submitModale) return;
    const bouton = modaleForm.querySelector('button[type=submit]');
    bouton.disabled = true;
    const erreur = await submitModale(new FormData(modaleForm));
    bouton.disabled = false;
    if (erreur) afficherMessage('modale-message', erreur);
});
document.getElementById('modale').addEventListener('click', (e) => {
    if (e.target === modale) fermerModale();
});

/* ---------- Navigation par onglets ---------- */
document.getElementById('onglets').addEventListener('click', (e) => {
    const bouton = e.target.closest('.onglet');
    if (!bouton) return;
    document.querySelectorAll('.onglet').forEach(o => o.classList.remove('actif'));
    bouton.classList.add('actif');
    vueActive = bouton.dataset.vue;
    afficherVue();
});

function afficherVue(silencieux = false) {
    const vue = document.getElementById('vue');

    // Sécurité côté client : un gestionnaire n'a pas accès aux vues
    // globales (délégués, contrôleurs, suivi, journal, sauvegarde).
    if (!estAdmin && !['elections', 'electeurs', 'invitation'].includes(vueActive)) {
        vueActive = 'elections';
    }

    if (silencieux) {
        // Le suivi live a son propre rafraîchissement compact.
        if (vueActive === 'suivi') {
            Suivi.rafraichir();
            return;
        }
        // Ne jamais interrompre une saisie en cours (recherche, filtre).
        const actif = document.activeElement;
        if (actif && actif.closest && actif.closest('#vue')
            && ['INPUT', 'SELECT', 'TEXTAREA'].includes(actif.tagName)) {
            return;
        }
    } else {
        Suivi.arreter(); // arrête un éventuel rafraîchissement live précédent
        vue.innerHTML = '<div class="carte"><p class="vide">Chargement…</p></div>';
    }

    if (vueActive === 'elections') vueElections(vue, silencieux);
    else if (vueActive === 'electeurs') vueComptes(vue, 'electeur', silencieux);
    else if (vueActive === 'gestionnaires') vueComptes(vue, 'gestionnaire', silencieux);
    else if (vueActive === 'controleurs') vueComptes(vue, 'controleur', silencieux);
    else if (vueActive === 'invitation') vueInvitation(vue, silencieux);
    else if (vueActive === 'suivi') vueSuivi(vue);
    else if (vueActive === 'journal') vueJournal(vue, silencieux);
    else if (vueActive === 'sauvegarde') vueSauvegarde(vue, silencieux);
}

/* ============================================================
   TEMPS RÉEL
   ============================================================ */
async function rafraichirSilencieux() {
    if (!user) return;
    // Une modale ouverte = saisie en cours : on ne touche à rien.
    if (!modale.classList.contains('cache')) return;

    await chargerStats();
    afficherVue(true);
}

/* ============================================================
   STATISTIQUES
   ============================================================ */
async function chargerStats() {
    const { data } = await API.get('statistiques.php');
    if (!data.success) return;
    const s = data.stats;
    const grille = document.getElementById('grille-stats');

    if (s.role === 'gestionnaire') {
        grille.innerHTML = `
            <div class="stat"><div class="valeur">${s.electeurs}</div><div class="libelle">Élèves dans ma classe</div></div>
            <div class="stat"><div class="valeur">${s.auto_inscrits}</div><div class="libelle">Inscrits via le lien</div></div>
            <div class="stat"><div class="valeur">${s.elections}</div><div class="libelle">Élections de classe</div></div>
            <div class="stat"><div class="valeur">${s.en_cours}</div><div class="libelle">Scrutins en cours</div></div>
        `;
        return;
    }

    grille.innerHTML = `
        <div class="stat"><div class="valeur">${s.electeurs}</div><div class="libelle">Électeurs inscrits</div></div>
        <div class="stat"><div class="valeur">${s.auto_inscrits || 0}</div><div class="libelle">Inscrits via un lien</div></div>
        <div class="stat"><div class="valeur">${s.gestionnaires || 0}</div><div class="libelle">Délégués</div></div>
        <div class="stat"><div class="valeur">${s.elections}</div><div class="libelle">Élections</div></div>
        <div class="stat"><div class="valeur">${s.en_cours}</div><div class="libelle">Scrutins en cours</div></div>
        <div class="stat"><div class="valeur">${s.votes}</div><div class="libelle">Votes exprimés</div></div>
    `;
}

/* ============================================================
   VUE : ÉLECTIONS (admin)
   ============================================================ */
async function vueElections(conteneur, silencieux = false) {
    const { data } = await API.get('elections.php');
    if (!data.success) return;
    const elections = data.data || [];

    // Rien n'a changé : on ne reconstruit pas le tableau (focus conservé).
    const signature = elections.map(e =>
        `${e.id}:${e.statut}:${e.resultats_publics ? 1 : 0}:${e.nb_candidats}:${e.nb_votes ?? ''}:${e.zone || ''}`
    ).join('|');
    if (silencieux && conteneur.dataset.signature === signature) return;
    conteneur.dataset.signature = signature;

    const titre = estAdmin ? 'Élections' : 'Élections de classe';
    const badgeClasse = !estAdmin && user.zone
        ? `<span class="badge badge-bleu">Classe ${echapper(user.zone)}</span>` : '';

    let html = `
        <div class="carte">
            <div class="carte-titre">
                <span>${titre} (${elections.length}) ${badgeClasse}</span>
                <button class="btn btn-primaire btn-petit" id="btn-nouvelle-election">
                    + ${estAdmin ? 'Nouvelle élection' : 'Nouvelle élection de classe'}
                </button>
            </div>`;

    if (elections.length === 0) {
        html += `<p class="vide">${estAdmin
            ? 'Aucune élection. Créez-en une pour commencer.'
            : 'Aucune élection de classe pour le moment. Créez-en une pour élire votre délégué, par exemple.'}</p>`;
    } else {
        html += `<div class="tableau-scroll"><table class="tableau">
            <thead><tr>
                <th>Titre</th>${estAdmin ? '<th>Classe</th>' : ''}<th>Statut</th><th>Dates</th>
                <th>Cand.</th><th>Votes</th><th>Actions</th>
            </tr></thead><tbody>`;
        for (const e of elections) {
            const demarrer = ['a_venir', 'annulee'].includes(e.statut)
                ? `<button class="btn btn-succes btn-petit" data-act="demarrer" data-id="${e.id}">Ouvrir</button>` : '';
            const cloturer = e.statut === 'en_cours'
                ? `<button class="btn btn-danger btn-petit" data-act="cloturer" data-id="${e.id}">Clôturer</button>` : '';
            const publier = e.statut === 'terminee'
                ? `<button class="btn btn-primaire btn-petit" data-act="publier" data-id="${e.id}">Publier</button>` : '';
            // Pour l'admin, la classe a sa propre colonne : inutile de
            // répéter le badge dans la cellule du titre.
            const badgeE = !estAdmin && e.zone
                ? `<span class="badge badge-bleu">Classe ${echapper(e.zone)}</span>` : '';
            const celluleClasse = estAdmin
                ? `<td>${e.zone
                    ? echapper(e.zone)
                    : '<span style="color:var(--gris)">Toute l\'école</span>'}</td>`
                : '';

            html += `<tr>
                <td><b>${echapper(e.titre)}</b> ${badgeE}</td>
                ${celluleClasse}
                <td>${badgeStatut(e.statut)}</td>
                <td style="font-size:13px; color:var(--gris);">${formaterDate(e.date_debut)} → ${formaterDate(e.date_fin)}</td>
                <td>${e.nb_candidats}</td>
                <td>${e.nb_votes != null ? e.nb_votes : '—'}</td>
                <td><div class="actions">
                    <button class="btn btn-neutre btn-petit" data-act="candidats" data-id="${e.id}">Candidats</button>
                    ${demarrer}${cloturer}${publier}
                    <button class="btn btn-neutre btn-petit" data-act="resultats" data-id="${e.id}">Résultats</button>
                    <button class="btn btn-neutre btn-petit" data-act="modifier" data-id="${e.id}">Modifier</button>
                    <button class="btn btn-danger btn-petit" data-act="supprimer" data-id="${e.id}">Suppr.</button>
                </div></td>
            </tr>`;
        }
        html += `</tbody></table></div>`;
    }
    html += `</div>`;
    conteneur.innerHTML = html;

    document.getElementById('btn-nouvelle-election')?.addEventListener('click', () => modaleElection(null));

    conteneur.querySelectorAll('[data-act]').forEach(btn => {
        btn.addEventListener('click', () => {
            const id = parseInt(btn.dataset.id, 10);
            const election = elections.find(e => e.id === id);
            switch (btn.dataset.act) {
                case 'modifier':    modaleElection(election); break;
                case 'candidats':   modaleCandidats(election); break;
                case 'resultats':   modaleResultats(election); break;
                case 'demarrer':    changerStatut(election.id, 'en_cours'); break;
                case 'cloturer':    modaleCloturer(election); break;
                case 'publier':     publierResultats(election); break;
                case 'supprimer':   supprimerElection(election.id); break;
            }
        });
    });
}

function modaleElection(election) {
    const e = election || {};
    const titreModale = election
        ? (estAdmin ? 'Modifier l\'élection' : 'Modifier l\'élection de classe')
        : (estAdmin ? 'Nouvelle élection' : 'Nouvelle élection de classe');

    let champZone;
    if (!estAdmin) {
        champZone = `<div class="champ"><label>Classe</label>
            <input value="${echapper(user.zone || 'non renseignée')}" disabled>
            <small class="aide">Une élection de classe est réservée à votre classe : seuls vos étudiants la voient et votent.</small></div>`;
    } else if (election) {
        champZone = `<div class="champ"><label>Classe</label>
            <input value="${echapper(e.zone || 'Toute l\'école')}" disabled>
            <small class="aide">La portée d'une élection ne peut plus être modifiée.</small></div>`;
    } else {
        champZone = `<div class="champ"><label>Classe (optionnel)</label>
            <input name="zone" placeholder="ex : L1B">
            <small class="aide">Laisser vide pour une élection générale (toute l'école).</small></div>`;
    }

    const champs = `
        <div class="champ"><label>Titre</label>
            <input name="titre" required value="${echapper(e.titre || '')}"></div>
        <div class="champ"><label>Description</label>
            <textarea name="description">${echapper(e.description || '')}</textarea></div>
        ${champZone}
        <div class="champ"><label>Date de début</label>
            <input type="datetime-local" name="date_debut" value="${echapper(e.date_debut ? e.date_debut.slice(0,16) : '')}"></div>
        <div class="champ"><label>Date de fin</label>
            <input type="datetime-local" name="date_fin" value="${echapper(e.date_fin ? e.date_fin.slice(0,16) : '')}"></div>`;

    ouvrirModale(titreModale, champs, async (fd) => {
        const corps = {
            titre: fd.get('titre').trim(),
            description: fd.get('description').trim(),
            date_debut: fd.get('date_debut') || null,
            date_fin: fd.get('date_fin') || null,
        };
        if (!corps.titre) return 'Le titre est obligatoire.';
        // La zone n'est envoyée qu'à la création par l'admin.
        if (estAdmin && !election) {
            corps.zone = (fd.get('zone') || '').trim();
        }

        const rep = election
            ? await API.put('elections.php', { id: election.id, ...corps })
            : await API.post('elections.php', corps);

        if (!rep.ok || !rep.data.success) return rep.data.error || 'Erreur.';
        fermerModale();
        afficherMessage('message', 'Élection enregistrée.', 'succes');
        chargerStats(); afficherVue();
        return null;
    });
}

async function changerStatut(id, statut) {
    const { ok, data } = await API.put('elections.php', { id, statut });
    if (!ok || !data.success) { afficherMessage('message', data.error || 'Erreur.', 'erreur'); return; }
    afficherMessage('message', 'Statut mis à jour.', 'succes');
    chargerStats(); afficherVue();
}

// Clôture MANUELLE : le mot de passe de l'admin est exigé.
function modaleCloturer(election) {
    const champs = `
        <p style="color:var(--gris); font-size:14px; margin-bottom:10px;">
            La clôture manuelle de « ${echapper(election.titre)} » est définitive :
            les électeurs ne pourront plus voter.
            Saisissez votre mot de passe pour confirmer.
        </p>
        <div class="champ"><label>Mot de passe</label>
            <input type="password" name="mot_de_passe" required autocomplete="current-password"></div>`;

    ouvrirModale('Clôturer l\'élection', champs, async (fd) => {
        const rep = await API.put('elections.php', {
            id: election.id,
            statut: 'terminee',
            mot_de_passe: fd.get('mot_de_passe'),
        });
        if (!rep.ok || !rep.data.success) return rep.data.error || 'Erreur.';
        fermerModale();
        afficherMessage('message', 'Élection clôturée.', 'succes');
        chargerStats(); afficherVue();
        return null;
    }, 'Clôturer');
}

// PUBLIER les résultats : pour une élection générale ils deviennent
// visibles par tout le monde ; pour une élection de classe, seulement
// par le délégué et les étudiants de la classe.
async function publierResultats(election) {
    const portee = election.zone
        ? `les étudiants de la classe ${election.zone}`
        : 'tout le monde';
    if (!confirm(`Publier les résultats de « ${election.titre} » ? Ils seront visibles par ${portee}.`)) return;
    const { ok, data } = await API.put('elections.php', { id: election.id, publier: true });
    if (!ok || !data.success) { afficherMessage('message', data.error || 'Erreur.', 'erreur'); return; }
    afficherMessage('message', data.message || 'Résultats publiés.', 'succes');
    chargerStats(); afficherVue();
}

// Changement du mot de passe de l'utilisateur connecté.
function modaleMonMotDePasse() {
    const champs = `
        <div class="champ"><label>Mot de passe actuel</label>
            <input type="password" name="old_password" required autocomplete="current-password"></div>
        <div class="champ"><label>Nouveau mot de passe</label>
            <input type="password" name="new_password" required minlength="8" autocomplete="new-password"></div>
        <div class="champ"><label>Confirmer le nouveau mot de passe</label>
            <input type="password" name="confirmation" required minlength="8" autocomplete="new-password"></div>`;

    ouvrirModale('Changer mon mot de passe', champs, async (fd) => {
        const ancien = fd.get('old_password');
        const nouveau = fd.get('new_password');
        const confirmation = fd.get('confirmation');

        if (nouveau.length < 8) return 'Le nouveau mot de passe doit contenir au moins 8 caractères.';
        if (nouveau !== confirmation) return 'Les deux mots de passe ne correspondent pas.';

        const rep = await API.post('auth.php', {
            action: 'change_password',
            old_password: ancien,
            new_password: nouveau,
        });
        if (!rep.ok || !rep.data.success) return rep.data.error || 'Erreur.';

        fermerModale();
        if (rep.data.reconnecter) {
            API.clearSession();
            afficherMessage('message', rep.data.message || 'Mot de passe modifié. Reconnectez-vous.', 'succes');
            setTimeout(() => location.href = 'index.html', 1800);
            return null;
        }
        afficherMessage('message', 'Mot de passe modifié avec succès.', 'succes');
        return null;
    }, 'Changer');
}

async function supprimerElection(id) {
    if (!confirm('Supprimer cette élection et tous ses votes ?')) return;
    const { ok, data } = await API.del('elections.php?id=' + id);
    if (!ok || !data.success) { afficherMessage('message', data.error || 'Erreur.', 'erreur'); return; }
    afficherMessage('message', 'Élection supprimée.', 'succes');
    chargerStats(); afficherVue();
}

/* ---------- Candidats (modale) ---------- */
const PHOTO_TAILLE_MAX = 500; // cote le plus long, en pixels

// Lit un fichier image, le redimensionne et renvoie une data URL (JPEG compact).
function lirePhoto(file) {
    return new Promise((resolve, reject) => {
        if (!file) return resolve(null);
        if (!file.type.startsWith('image/')) return reject(new Error('Veuillez choisir un fichier image.'));
        const lecteur = new FileReader();
        lecteur.onload = () => {
            const img = new Image();
            img.onload = () => {
                let { width, height } = img;
                const max = PHOTO_TAILLE_MAX;
                if (width > max || height > max) {
                    const r = Math.min(max / width, max / height);
                    width = Math.round(width * r);
                    height = Math.round(height * r);
                }
                const canvas = document.createElement('canvas');
                canvas.width = width; canvas.height = height;
                const ctx = canvas.getContext('2d');
                ctx.fillStyle = '#ffffff'; // fond blanc (utile pour les PNG transparents)
                ctx.fillRect(0, 0, width, height);
                ctx.drawImage(img, 0, 0, width, height);
                resolve(canvas.toDataURL('image/jpeg', 0.82));
            };
            img.onerror = () => reject(new Error('Image illisible.'));
            img.src = lecteur.result;
        };
        lecteur.onerror = () => reject(new Error('Lecture du fichier impossible.'));
        lecteur.readAsDataURL(file);
    });
}

// Branche l'apercu live sur le champ photo de la modale courante.
function brancherChampPhoto() {
    const input = modaleForm.querySelector('input[name=photo]');
    if (!input) return;
    const apercu = modaleForm.querySelector('#apercu-photo');
    input.addEventListener('change', async () => {
        const f = input.files && input.files[0];
        if (!f) return;
        try {
            const url = await lirePhoto(f);
            if (apercu) { apercu.src = url; apercu.style.display = 'block'; }
        } catch (e) {
            afficherMessage('modale-message', e.message);
        }
    });
}

// Petite vignette ronde (photo si dispo, sinon initiales).
function vignetteCandidat(c) {
    if (c.photo_url) return `<img class="vignette" src="${echapper(c.photo_url)}" alt="">`;
    return `<div class="vignette vignette-vide">${echapper(((c.prenom || '')[0] || '') + ((c.nom || '')[0] || ''))}</div>`;
}

async function modaleCandidats(election) {
    const { data } = await API.get('candidats.php?election_id=' + election.id);
    const candidats = data.data || [];

    let liste = candidats.length
        ? '<div class="tableau-scroll"><table class="tableau tableau-compact"><tbody>' + candidats.map(c => `
            <tr>
                <td style="width:52px">${vignetteCandidat(c)}</td>
                <td><b>${echapper(c.prenom)} ${echapper(c.nom)}</b><br>
                    <small style="color:var(--gris)">${echapper(c.programme || '')}</small></td>
                <td style="text-align:right; white-space:nowrap">
                    <button class="btn btn-neutre btn-petit" data-edit="${c.id}">Modifier</button>
                    <button class="btn btn-danger btn-petit" data-del="${c.id}">Suppr.</button>
                </td>
            </tr>
          `).join('') + '</tbody></table></div>'
        : '<p class="vide">Aucun candidat.</p>';

    const champs = `
        <p style="margin-bottom:10px; color:var(--gris); font-size:14px;">Candidats de « ${echapper(election.titre)} »</p>
        ${liste}
        <hr style="margin:18px 0; border:none; border-top:1px solid var(--gris-clair)">
        <div class="champ"><label>Prénom</label><input name="prenom"></div>
        <div class="champ"><label>Nom</label><input name="nom"></div>
        <div class="champ"><label>Programme</label><textarea name="programme"></textarea></div>
        <div class="champ"><label>Photo (optionnel — JPG/PNG)</label>
            <input type="file" name="photo" accept="image/*">
            <img id="apercu-photo" class="apercu-photo" alt="" style="display:none"></div>`;

    ouvrirModale('Gestion des candidats', champs, async (fd) => {
        const nom = fd.get('nom').trim();
        const prenom = fd.get('prenom').trim();
        if (!nom || !prenom) return 'Prénom et nom obligatoires.';

        let photo_url = null;
        const fichier = fd.get('photo');
        if (fichier && fichier.size) {
            try { photo_url = await lirePhoto(fichier); }
            catch (e) { return e.message; }
        }

        const rep = await API.post('candidats.php', {
            election_id: election.id, nom, prenom,
            programme: fd.get('programme').trim(),
            photo_url,
        });
        if (!rep.ok || !rep.data.success) return rep.data.error || 'Erreur.';
        fermerModale();
        modaleCandidats(election);
        return null;
    }, 'Ajouter le candidat');

    brancherChampPhoto();

    // Boutons de suppression
    modaleForm.querySelectorAll('[data-del]').forEach(b => {
        b.addEventListener('click', async () => {
            await API.del('candidats.php?id=' + b.dataset.del);
            fermerModale(); modaleCandidats(election);
        });
    });

    // Boutons de modification
    modaleForm.querySelectorAll('[data-edit]').forEach(b => {
        b.addEventListener('click', () => {
            const c = candidats.find(x => x.id === parseInt(b.dataset.edit, 10));
            modaleEditerCandidat(election, c);
        });
    });
}

function modaleEditerCandidat(election, c) {
    const champs = `
        <div class="champ"><label>Prénom</label><input name="prenom" value="${echapper(c.prenom)}"></div>
        <div class="champ"><label>Nom</label><input name="nom" value="${echapper(c.nom)}"></div>
        <div class="champ"><label>Programme</label><textarea name="programme">${echapper(c.programme || '')}</textarea></div>
        <div class="champ"><label>Photo (laisser vide pour conserver l'actuelle)</label>
            <input type="file" name="photo" accept="image/*">
            <img id="apercu-photo" class="apercu-photo" alt=""
                 src="${c.photo_url ? echapper(c.photo_url) : ''}"
                 style="display:${c.photo_url ? 'block' : 'none'}"></div>`;

    ouvrirModale('Modifier le candidat', champs, async (fd) => {
        const nom = fd.get('nom').trim();
        const prenom = fd.get('prenom').trim();
        if (!nom || !prenom) return 'Prénom et nom obligatoires.';

        const corps = { id: c.id, nom, prenom, programme: fd.get('programme').trim() };
        const fichier = fd.get('photo');
        if (fichier && fichier.size) {
            try { corps.photo_url = await lirePhoto(fichier); }
            catch (e) { return e.message; }
        }

        const rep = await API.put('candidats.php', corps);
        if (!rep.ok || !rep.data.success) return rep.data.error || 'Erreur.';
        fermerModale();
        modaleCandidats(election);
        return null;
    }, 'Enregistrer');

    brancherChampPhoto();
}

/* ---------- Résultats (modale) — diagramme en bandes ---------- */
async function modaleResultats(election) {
    const { ok, data } = await API.get('resultats.php?election_id=' + election.id);
    if (!ok || !data.success) {
        ouvrirModale('Résultats', `<p class="vide">${echapper(data.error || 'Indisponible.')}</p>`, async () => null, 'Fermer');
        return;
    }

    const nuls = Number(data.votes_nuls || 0);
    let html = `<p class="resume-resultats">
        <b>${data.total_votes}</b> vote(s) exprimé(s) — participation <b>${data.participation}%</b>
        (${data.inscrits} inscrits)${nuls > 0 ? ` — dont <b>${nuls}</b> nul(s)` : ''}
    </p>`;

    html += Graphe.rendre(data.candidats || [], {
        votants: data.total_votes || 0,
        nuls: nuls > 0 ? nuls : null,
        note: `${data.votes_valides || 0} bulletin(s) valide(s) sur ${data.total_votes || 0} vote(s) exprimé(s) — pourcentages calculés sur les votants.`,
    });

    if (nuls > 0) {
        html += `<div class="nuls-bloc">⊘ ${nuls} bulletin(s) nul(s) — bulletins blancs et temps de vote écoulé.</div>`;
    }

    // Publication explicite (uniquement après clôture, si pas déjà publiée).
    const estClasse = !!data.election.zone;
    const peutPublier = !data.election.resultats_publics &&
        ['terminee', 'publiee'].includes(data.election.statut);
    if (peutPublier) {
        html += `<div style="margin-top:18px;">
            <button type="button" class="btn btn-primaire" id="btn-publier-resultats">${
                estClasse ? 'Publier auprès de la classe' : 'Publier les résultats'
            }</button>
            <span style="color:var(--gris); font-size:13px; margin-left:8px;">${
                estClasse
                    ? 'Visibles uniquement par vous et les étudiants de ' + echapper(data.election.zone) + '.'
                    : 'Ils deviendront visibles par tous.'
            }</span>
        </div>`;
    }

    const titreModale = 'Résultats — ' + election.titre +
        (data.election.zone ? ' (classe ' + data.election.zone + ')' : '');
    ouvrirModale(titreModale, html, async () => { fermerModale(); return null; }, 'Fermer');

    if (peutPublier) {
        document.getElementById('btn-publier-resultats').addEventListener('click', async () => {
            const rep = await API.put('elections.php', { id: election.id, publier: true });
            if (!rep.ok || !rep.data.success) { afficherMessage('modale-message', rep.data.error || 'Erreur.'); return; }
            fermerModale();
            afficherMessage('message', rep.data.message || 'Résultats publiés.', 'succes');
            chargerStats(); afficherVue();
        });
    }
}

/* ============================================================
   VUE : COMPTES
   ------------------------------------------------------------
   admin        : tous les comptes, création + modification.
   gestionnaire : électeurs de SA classe, suppression seule.
   ============================================================ */
async function vueComptes(conteneur, role, silencieux = false) {
    const titres = {
        electeur: 'Électeurs',
        gestionnaire: 'Délégués',
        controleur: 'Contrôleurs',
    };
    const titreRole = titres[role] || 'Comptes';

    if (etatComptes.role !== role) {
        etatComptes = { role, q: '', page: 1 };
    }

    const params = new URLSearchParams({ role });
    if (etatComptes.q) params.set('q', etatComptes.q);
    params.set('page', String(etatComptes.page || 1));

    const { ok, data } = await API.get('electeurs.php?' + params.toString());
    if (!ok || !data.success) {
        if (silencieux) return;
        conteneur.innerHTML = `<div class="carte">
            <div class="carte-titre"><span>${titreRole}</span></div>
            <p class="vide">${echapper(data.error || 'Liste indisponible.')}</p>
        </div>`;
        return;
    }

    const comptes = data.data || [];
    const total = Number(data.total || comptes.length);
    const page = Number(data.page || 1);
    const pages = Number(data.pages || 1);

    // Rien n'a changé : on ne reconstruit pas le tableau (focus conservé).
    const signature = `${role}|${page}|${total}|` + comptes.map(u =>
        `${u.id}:${u.actif ? 1 : 0}:${u.zone || ''}:${u.matricule}:${u.prenom}:${u.nom}:${u.email || ''}`
    ).join(',');
    if (silencieux && conteneur.dataset.signature === signature) return;
    conteneur.dataset.signature = signature;

    let html = `
        <div class="carte">
            <div class="carte-titre">
                <span>${titreRole}${!estAdmin && data.ma_zone ? ' — ' + echapper(data.ma_zone) : ''} (${total})</span>
                ${estAdmin
                    ? `<button class="btn btn-primaire btn-petit" id="btn-nouveau-compte">+ Nouveau</button>`
                    : `<span class="badge badge-bleu">Ma classe</span>`}
            </div>`;

    if (!estAdmin) {
        html += `<div class="zone-bandeau">
            <span class="cadenas" aria-hidden="true">🏫</span>
            <span>Classe : <span class="zone-nom">${echapper(data.ma_zone || 'non renseignée')}</span></span>
        </div>
        <p class="aide">
            Vous consultez uniquement les élèves de votre classe. Vous pouvez retirer un profil
            qui ne correspond pas à votre liste. La création et la modification des profils
            sont réservées à l'administration.
        </p>`;
    }

    html += `<form class="barre-outils" id="form-recherche">
        <input type="search" name="q" value="${echapper(etatComptes.q)}"
               placeholder="Rechercher un matricule, un nom, un email…"
               aria-label="Rechercher un compte">
        <button class="btn btn-neutre btn-petit" type="submit">Rechercher</button>
        ${etatComptes.q ? `<button class="btn btn-neutre btn-petit" type="button" id="btn-effacer-recherche">Effacer</button>` : ''}
    </form>`;

    if (comptes.length === 0) {
        html += `<p class="vide">${etatComptes.q
            ? 'Aucun compte ne correspond à cette recherche.'
            : 'Aucun compte pour le moment.'}</p>`;
    } else {
        html += `<div class="tableau-scroll"><table class="tableau">
            <thead><tr>
                <th>Matricule</th><th>Nom</th><th>Email</th><th>Zone</th>
                <th>Inscription</th><th>Statut</th><th>Actions</th>
            </tr></thead><tbody>`;
        for (const u of comptes) {
            html += `<tr>
                <td><b>${echapper(u.matricule)}</b></td>
                <td>${echapper(u.prenom)} ${echapper(u.nom)}</td>
                <td style="font-size:13px">${echapper(u.email || '—')}</td>
                <td>${u.zone ? echapper(u.zone) : '<span style="color:var(--gris)">—</span>'}</td>
                <td>${u.auto_inscrit
                    ? '<span class="badge badge-auto">Lien de classe</span>'
                    : '<span style="color:var(--gris); font-size:12.5px;">Administration</span>'}</td>
                <td>${u.actif ? '<span class="badge badge-vert">Actif</span>' : '<span class="badge badge-rouge">Inactif</span>'}</td>
                <td><div class="actions">
                    ${estAdmin ? `
                        <button class="btn btn-neutre btn-petit" data-act="edit" data-id="${u.id}">Modifier</button>
                        <button class="btn btn-neutre btn-petit" data-act="pwd" data-id="${u.id}">Mot de passe</button>` : ''}
                    <button class="btn btn-danger btn-petit" data-act="del" data-id="${u.id}">Suppr.</button>
                </div></td>
            </tr>`;
        }
        html += `</tbody></table></div>`;

        if (pages > 1) {
            html += `<div class="pagination">
                <button class="btn btn-neutre btn-petit" type="button" data-page="${page - 1}" ${page <= 1 ? 'disabled' : ''}>← Précédent</button>
                <span>Page <b>${page}</b> / ${pages}</span>
                <button class="btn btn-neutre btn-petit" type="button" data-page="${page + 1}" ${page >= pages ? 'disabled' : ''}>Suivant →</button>
            </div>`;
        }
    }

    html += `</div>`;
    conteneur.innerHTML = html;

    document.getElementById('btn-nouveau-compte')?.addEventListener('click', () => modaleCompte(null, role));

    const formRecherche = document.getElementById('form-recherche');
    formRecherche.addEventListener('submit', (e) => {
        e.preventDefault();
        etatComptes.q = formRecherche.querySelector('input[name=q]').value.trim();
        etatComptes.page = 1;
        vueComptes(conteneur, role);
    });

    document.getElementById('btn-effacer-recherche')?.addEventListener('click', () => {
        etatComptes.q = '';
        etatComptes.page = 1;
        vueComptes(conteneur, role);
    });

    conteneur.querySelectorAll('[data-page]').forEach(btn => {
        btn.addEventListener('click', () => {
            etatComptes.page = parseInt(btn.dataset.page, 10) || 1;
            vueComptes(conteneur, role);
        });
    });

    conteneur.querySelectorAll('[data-act]').forEach(btn => {
        const id = parseInt(btn.dataset.id, 10);
        const compte = comptes.find(c => c.id === id);
        btn.addEventListener('click', () => {
            if (btn.dataset.act === 'edit') modaleCompte(compte, role);
            else if (btn.dataset.act === 'pwd') modaleMotDePasse(compte);
            else if (btn.dataset.act === 'del') supprimerCompte(compte, role);
        });
    });
}

function modaleCompte(compte, role) {
    const c = compte || {};
    const champs = `
        <div class="champ"><label>Matricule</label>
            <input name="matricule" required value="${echapper(c.matricule || '')}" ${compte ? 'readonly' : ''}></div>
        <div class="champ"><label>Prénom</label><input name="prenom" required value="${echapper(c.prenom || '')}"></div>
        <div class="champ"><label>Nom</label><input name="nom" required value="${echapper(c.nom || '')}"></div>
        <div class="champ"><label>Email</label><input type="email" name="email" value="${echapper(c.email || '')}"></div>
        <div class="champ"><label>Zone / classe (ex : L3 Informatique)</label>
            <input name="zone" value="${echapper(c.zone || '')}" placeholder="ex : Amphi A, L3 Info…"></div>
        ${compte ? '' : `<div class="champ"><label>Mot de passe</label>
            <input type="text" name="mot_de_passe" required placeholder="min. 8 caractères"></div>`}
        <div class="champ"><label>Statut</label>
            <select name="actif"><option value="true" ${c.actif !== false ? 'selected' : ''}>Actif</option>
            <option value="false" ${c.actif === false ? 'selected' : ''}>Inactif</option></select></div>`;

    ouvrirModale(compte ? 'Modifier le compte' : 'Nouveau compte', champs, async (fd) => {
        if (compte) {
            const rep = await API.put('electeurs.php', {
                id: compte.id,
                prenom: fd.get('prenom').trim(),
                nom: fd.get('nom').trim(),
                email: fd.get('email').trim(),
                zone: fd.get('zone').trim(),
                actif: fd.get('actif') === 'true',
            });
            if (!rep.ok || !rep.data.success) return rep.data.error || 'Erreur.';
        } else {
            const rep = await API.post('electeurs.php', {
                matricule: fd.get('matricule').trim(),
                prenom: fd.get('prenom').trim(),
                nom: fd.get('nom').trim(),
                email: fd.get('email').trim(),
                zone: fd.get('zone').trim(),
                mot_de_passe: fd.get('mot_de_passe'),
                role,
            });
            if (!rep.ok || !rep.data.success) return rep.data.error || 'Erreur.';
            etatComptes = { role, q: '', page: 1 };
        }
        fermerModale();
        afficherMessage('message', 'Compte enregistré.', 'succes');
        chargerStats(); afficherVue();
        return null;
    });
}

function modaleMotDePasse(compte) {
    const champs = `<div class="champ"><label>Nouveau mot de passe pour ${echapper(compte.matricule)}</label>
        <input type="text" name="mot_de_passe" required placeholder="min. 8 caractères"></div>`;
    ouvrirModale('Réinitialiser le mot de passe', champs, async (fd) => {
        const rep = await API.put('electeurs.php', { id: compte.id, mot_de_passe: fd.get('mot_de_passe') });
        if (!rep.ok || !rep.data.success) return rep.data.error || 'Erreur.';
        fermerModale();
        afficherMessage('message', 'Mot de passe réinitialisé.', 'succes');
        return null;
    });
}

async function supprimerCompte(compte, role) {
    const message = estAdmin
        ? `Supprimer le compte ${compte.matricule} ?`
        : `Retirer ${compte.matricule} (${compte.prenom} ${compte.nom}) de votre classe ?`;
    if (!confirm(message)) return;

    const { ok, data } = await API.del('electeurs.php?id=' + compte.id);
    if (!ok || !data.success) { afficherMessage('message', data.error || 'Erreur.', 'erreur'); return; }
    afficherMessage('message', data.message || 'Compte supprimé.', 'succes');
    chargerStats();
    vueComptes(document.getElementById('vue'), role);
}

/* ============================================================
   VUE : LIENS D'INSCRIPTION (classe)
   ============================================================ */
function estInvitationActive(inv) {
    if (!inv || !inv.actif) return false;
    if (inv.expire_at && new Date(inv.expire_at).getTime() <= Date.now()) return false;
    return true;
}

function lienAbsolu(lien) {
    const base = location.href.split(/[?#]/)[0].replace(/[^/]*$/, '');
    return base + lien;
}

async function copierTexte(texte, bouton) {
    try {
        if (navigator.clipboard && window.isSecureContext) {
            await navigator.clipboard.writeText(texte);
        } else {
            const zone = document.createElement('textarea');
            zone.value = texte;
            zone.setAttribute('readonly', '');
            zone.style.position = 'fixed';
            zone.style.opacity = '0';
            document.body.appendChild(zone);
            zone.select();
            document.execCommand('copy');
            document.body.removeChild(zone);
        }
        if (bouton) {
            const initial = bouton.textContent;
            bouton.textContent = 'Copié ✓';
            setTimeout(() => { bouton.textContent = initial; }, 1500);
        }
        afficherMessage('message', 'Lien d\'inscription copié.', 'succes');
    } catch (e) {
        afficherMessage('message', 'Copie impossible. Sélectionnez le lien et copiez-le à la main.', 'erreur');
    }
}

function blocLienInvitation(inv) {
    const url = lienAbsolu(inv.lien);
    return `<div class="lien-boite">
        <input type="text" readonly value="${echapper(url)}" aria-label="Lien d'inscription de la classe">
        <button type="button" class="btn btn-primaire btn-petit"
                data-inv-act="copier" data-lien="${echapper(url)}">Copier</button>
        <a class="btn btn-neutre btn-petit" href="${echapper(inv.lien)}"
           target="_blank" rel="noopener">Ouvrir</a>
    </div>`;
}

function metaInvitation(inv) {
    return `<div class="invitation-meta">
        <span>Inscrits : <b>${inv.inscrits || 0}</b></span>
        <span>Via le lien : <b>${inv.auto_inscrits || 0}</b></span>
        <span>Créé le ${formaterDateHeure(inv.cree_le)}</span>
        ${inv.expire_at ? `<span>Expire le ${formaterDateHeure(inv.expire_at)}</span>` : ''}
        ${inv.cree_par_matricule ? `<span>Par <b>${echapper(inv.cree_par_matricule)}</b></span>` : ''}
    </div>`;
}

function itemInvitation(inv, avecZone) {
    const active = estInvitationActive(inv);
    return `<div class="invitation-item${active ? '' : ' inactive'}">
        <div class="invitation-entete">
            <span class="zone">${avecZone ? echapper(inv.zone) : 'Lien de ma classe'}</span>
            ${active
                ? '<span class="badge badge-vert">Actif</span>'
                : (inv.actif
                    ? '<span class="badge badge-orange">Expiré</span>'
                    : '<span class="badge badge-gris">Révoqué</span>')}
        </div>
        ${active ? blocLienInvitation(inv) : ''}
        ${metaInvitation(inv)}
        <div class="invitation-actions">
            <button type="button" class="btn ${active ? 'btn-neutre' : 'btn-primaire'} btn-petit"
                    data-inv-act="regenerer" data-id="${inv.id}">
                ${active ? 'Générer un nouveau lien' : 'Réactiver avec un nouveau lien'}
            </button>
            ${active ? `<button type="button" class="btn btn-danger btn-petit"
                    data-inv-act="revoquer" data-id="${inv.id}">Révoquer</button>` : ''}
        </div>
    </div>`;
}

async function vueInvitation(conteneur, silencieux = false) {
    const { ok, data } = await API.get('invitations.php');
    if (!ok || !data.success) {
        if (silencieux) return;
        conteneur.innerHTML = `<div class="carte">
            <div class="carte-titre"><span>Liens d'inscription</span></div>
            <p class="vide">${echapper(data.error || 'Indisponible.')}</p>
        </div>`;
        return;
    }

    const invitations = data.invitations || [];
    const zones = data.zones || [];
    const maZone = data.ma_zone;

    // Rien n'a changé : on ne reconstruit pas la vue (focus conservé).
    const signature = `${maZone || ''}|${zones.join(',')}|` + invitations.map(i =>
        `${i.id}:${i.actif ? 1 : 0}:${i.expire_at || ''}:${i.inscrits}:${i.auto_inscrits}:${i.token}`
    ).join(',');
    if (silencieux && conteneur.dataset.signature === signature) return;
    conteneur.dataset.signature = signature;

    let html = '';

    if (!estAdmin) {
        if (!maZone) {
            html = `<div class="carte">
                <div class="carte-titre"><span>Ma classe</span></div>
                <div class="alerte alerte-info">
                    Aucune classe n'est associée à votre compte. Demandez à l'administrateur
                    de renseigner votre zone (classe) avant de pouvoir générer un lien.
                </div>
            </div>`;
            conteneur.innerHTML = html;
            return;
        }

        const active = invitations.find(estInvitationActive);
        html = `<div class="carte">
            <div class="carte-titre">
                <span>Lien d'inscription — ${echapper(maZone)}</span>
                <span class="badge badge-bleu">Ma classe</span>
            </div>
            <p class="aide">
                Partagez ce lien avec les étudiants de votre classe. Ils s'inscriront eux-mêmes :
                la classe est imposée par le lien et ne peut pas être modifiée.
                Un seul lien est actif à la fois ; en générer un nouveau désactive l'ancien.
            </p>`;

        if (active) {
            html += `<div class="invitation-item">
                <div class="invitation-entete">
                    <span class="zone">${echapper(maZone)}</span>
                    <span class="badge badge-vert">Lien actif</span>
                </div>
                ${blocLienInvitation(active)}
                ${metaInvitation(active)}
            </div>`;
        } else {
            html += `<div class="alerte alerte-info" style="margin-bottom:14px;">
                Aucun lien actif pour votre classe. Générez-en un pour permettre aux étudiants
                de s'inscrire.
            </div>`;
        }

        html += `<div class="invitation-actions">
            <button type="button" class="btn ${active ? 'btn-neutre' : 'btn-primaire'}"
                    data-inv-act="creer">${active ? 'Générer un nouveau lien' : 'Générer le lien de ma classe'}</button>
            ${active ? `<button type="button" class="btn btn-danger"
                    data-inv-act="revoquer" data-id="${active.id}">Révoquer le lien</button>` : ''}
        </div></div>`;

        const anciens = invitations.filter(i => !estInvitationActive(i));
        if (anciens.length) {
            html += `<div class="carte">
                <div class="carte-titre"><span>Anciens liens</span></div>
                ${anciens.map(i => itemInvitation(i, false)).join('')}
            </div>`;
        }
    } else {
        html = `<div class="carte">
            <div class="carte-titre"><span>Liens d'inscription par classe</span></div>
            <p class="aide">
                Choisissez une classe existante puis générez son lien. Les étudiants qui ouvrent
                le lien s'inscrivent dans cette classe, sans pouvoir la choisir.
            </p>`;

        if (zones.length === 0) {
            html += `<div class="alerte alerte-info">
                Aucune classe n'existe encore. Créez d'abord un compte (délégué ou électeur)
                en renseignant sa zone pour pouvoir générer un lien.
            </div>`;
        } else {
            html += `<div class="barre-outils">
                <select id="select-zone-invitation" aria-label="Choisir une classe">
                    ${zones.map(z => `<option value="${echapper(z)}">${echapper(z)}</option>`).join('')}
                </select>
                <button type="button" class="btn btn-primaire" data-inv-act="creer">Générer le lien</button>
            </div>`;
        }

        if (invitations.length === 0) {
            html += `<p class="vide">Aucun lien généré pour le moment.</p>`;
        } else {
            html += invitations.map(i => itemInvitation(i, true)).join('');
        }
        html += `</div>`;
    }

    conteneur.innerHTML = html;
    brancherInvitations(conteneur);
}

function brancherInvitations(conteneur) {
    conteneur.querySelectorAll('[data-inv-act]').forEach(btn => {
        btn.addEventListener('click', async () => {
            const action = btn.dataset.invAct;

            if (action === 'copier') {
                await copierTexte(btn.dataset.lien, btn);
                return;
            }

            if (action === 'creer') {
                const choix = document.getElementById('select-zone-invitation');
                btn.disabled = true;
                await creerInvitation(choix ? choix.value : '');
                return;
            }

            if (action === 'regenerer') {
                if (!confirm('Générer un nouveau lien ? L\'ancien lien ne fonctionnera plus.')) return;
                btn.disabled = true;
                const rep = await API.put('invitations.php', { id: parseInt(btn.dataset.id, 10) });
                if (!rep.ok || !rep.data.success) {
                    btn.disabled = false;
                    afficherMessage('message', rep.data.error || 'Erreur.', 'erreur');
                    return;
                }
                afficherMessage('message', rep.data.message || 'Nouveau lien généré.', 'succes');
                changerVueInvitation();
                return;
            }

            if (action === 'revoquer') {
                if (!confirm('Révoquer ce lien ? Les étudiants qui ne sont pas encore inscrits ne pourront plus l\'utiliser.')) return;
                btn.disabled = true;
                const rep = await API.del('invitations.php?id=' + parseInt(btn.dataset.id, 10));
                if (!rep.ok || !rep.data.success) {
                    btn.disabled = false;
                    afficherMessage('message', rep.data.error || 'Erreur.', 'erreur');
                    return;
                }
                afficherMessage('message', rep.data.message || 'Lien révoqué.', 'succes');
                changerVueInvitation();
            }
        });
    });
}

function changerVueInvitation() {
    chargerStats();
    vueInvitation(document.getElementById('vue'));
}

async function creerInvitation(zone) {
    const corps = zone ? { zone } : {};
    const rep = await API.post('invitations.php', corps);
    if (!rep.ok || !rep.data.success) {
        afficherMessage('message', rep.data.error || 'Erreur.', 'erreur');
        return;
    }
    afficherMessage('message', rep.data.message || 'Lien d\'inscription généré.', 'succes');
    changerVueInvitation();
}

/* ============================================================
   VUE : SUIVI LIVE (admin)
   ============================================================ */
function vueSuivi(conteneur) {
    conteneur.innerHTML = `
        <div class="carte carte-filtre">
            <div>
                <div class="titre-section">Suivi du vote en direct</div>
                <div class="sous-titre-section">Participation par zone et résultats live — rafraîchissement automatique toutes les 5 s.</div>
            </div>
            <select id="select-suivi-election" aria-label="Choisir une élection à suivre"></select>
        </div>
        <div id="zone-suivi">
            <div class="carte"><p class="vide">Chargement du suivi…</p></div>
        </div>`;

    Suivi.demarrer(
        document.getElementById('select-suivi-election'),
        document.getElementById('zone-suivi')
    );
}

/* ============================================================
   VUE : JOURNAL
   ============================================================ */
async function vueJournal(conteneur, silencieux = false) {
    const { data } = await API.get('journal.php?limite=100');
    if (!data.success) return;
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

/* ============================================================
   VUE : SAUVEGARDE (admin)
   ============================================================ */
const LIBELLE_TABLE = {
    utilisateurs: 'Comptes',
    elections: 'Élections',
    candidats: 'Candidats',
    votes: 'Bulletins (anonymes)',
    emargements: 'Émargements',
    ouvertures: 'Ouvertures de bulletin',
    invitations: 'Liens de classe',
    journal: 'Journal d\'audit',
};

function formaterOctets(n) {
    const v = Number(n) || 0;
    if (v < 1024) return v + ' o';
    if (v < 1024 * 1024) return (v / 1024).toFixed(1) + ' Ko';
    return (v / (1024 * 1024)).toFixed(2) + ' Mo';
}

async function vueSauvegarde(conteneur, silencieux = false) {
    const { ok, data } = await API.get('sauvegarde.php?action=etat');
    if (!ok || !data.success) {
        if (silencieux) return;
        conteneur.innerHTML = `<div class="carte">
            <div class="carte-titre"><span>Sauvegarde</span></div>
            <p class="vide">${echapper(data.error || 'État indisponible.')}</p>
        </div>`;
        return;
    }

    const s = data.sauvegarde;
    const tables = data.tables || {};
    const total = Object.values(tables).reduce((somme, n) => somme + Number(n || 0), 0);

    // Rien n'a changé : on ne reconstruit pas la vue.
    const signature = JSON.stringify([s, tables, data.hors_web]);
    if (silencieux && conteneur.dataset.signature === signature) return;
    conteneur.dataset.signature = signature;

    let html = `<div class="carte">
        <div class="carte-titre">
            <span>Sauvegarde de la base
                <span class="badge badge-bleu">V${echapper(data.version || '')}</span></span>
            <span class="badge ${s ? 'badge-vert' : 'badge-orange'}">${
                s ? 'Sauvegarde disponible' : 'Aucune sauvegarde'
            }</span>
        </div>
        <p class="aide">
            Une copie complète de la base est créée automatiquement au plus une fois
            par 24 heures, dès qu'un administrateur ouvre cet onglet. Les mots de passe
            ne sont jamais lisibles : seules leurs empreintes sont enregistrées.
        </p>
        <div class="grille-stats">
            <div class="stat"><div class="valeur">${total}</div><div class="libelle">Enregistrements</div></div>
            <div class="stat"><div class="valeur">${Object.keys(tables).length}</div><div class="libelle">Tables</div></div>
            <div class="stat"><div class="valeur">${s ? formaterDateHeure(s.cree_le) : '—'}</div><div class="libelle">Dernière sauvegarde</div></div>
            <div class="stat"><div class="valeur">${s ? formaterOctets(s.taille) : '—'}</div><div class="libelle">Taille du fichier</div></div>
        </div>
        <div class="actions" style="margin-top:16px;">
            <button class="btn btn-primaire" id="btn-creer-sauvegarde">Créer une sauvegarde maintenant</button>
            <button class="btn btn-neutre" id="btn-telecharger-sauvegarde" ${s ? '' : 'disabled'}>
                Télécharger la dernière
            </button>
        </div>
        <div id="message-sauvegarde"></div>
        <p class="aide" style="margin-top:14px;">
            Stockage : ${data.hors_web
                ? 'hors de la racine web, inaccessible depuis Internet.'
                : 'dossier protégé par un refus d\'accès web (.htaccess).'}
            ${s ? ` ${s.nombre} fichier(s) conservé(s), rotation automatique.` : ''}
        </p>
    </div>`;

    html += `<div class="carte">
        <div class="carte-titre">Contenu de la base</div>
        <div class="tableau-scroll"><table class="tableau">
            <thead><tr><th>Table</th><th>Enregistrements</th></tr></thead><tbody>`;
    for (const [table, nombre] of Object.entries(tables)) {
        html += `<tr>
            <td>${echapper(LIBELLE_TABLE[table] || table)}</td>
            <td>${Number(nombre) || 0}</td>
        </tr>`;
    }
    html += `</tbody></table></div></div>`;

    html += `<div class="carte">
        <div class="carte-titre">Copie locale automatique</div>
        <p class="aide">
            Le script <code>outils/miroir-local.php</code> recopie cette base en
            <b>lecture seule</b> vers le PostgreSQL de votre machine. Il est prévu pour
            une tâche planifiée (toutes les 3 heures) et conserve aussi un instantané
            JSON dans <code>sauvegardes/</code>. Lancement manuel :
            <code>sauvegarder-local.bat</code>.
        </p>
        <div class="alerte alerte-info">
            La base en ligne n'est jamais modifiée par la copie locale : la connexion
            de synchronisation est en lecture seule et ne peut rien écrire en production.
        </div>
    </div>`;

    conteneur.innerHTML = html;

    document.getElementById('btn-creer-sauvegarde')?.addEventListener('click', async (e) => {
        const bouton = e.currentTarget;
        bouton.disabled = true;
        bouton.textContent = 'Sauvegarde en cours…';

        const rep = await API.post('sauvegarde.php?action=creer', {});

        bouton.disabled = false;
        bouton.textContent = 'Créer une sauvegarde maintenant';

        if (!rep.ok || !rep.data.success) {
            afficherMessage('message-sauvegarde', rep.data.error || 'Sauvegarde impossible.', 'erreur');
            return;
        }

        const confirmation = rep.data.message || 'Sauvegarde créée.';
        chargerStats();
        // La vue est reconstruite avec les nouveaux compteurs : on
        // affiche le message APRÈS, sinon il serait effacé.
        await vueSauvegarde(conteneur, false);
        afficherMessage('message-sauvegarde', confirmation, 'succes');
    });

    document.getElementById('btn-telecharger-sauvegarde')
        ?.addEventListener('click', telechargerSauvegarde);
}

async function telechargerSauvegarde() {
    const bouton = document.getElementById('btn-telecharger-sauvegarde');
    if (bouton) bouton.disabled = true;

    try {
        const reponse = await fetch('api/sauvegarde.php?action=telecharger', {
            headers: { Authorization: 'Bearer ' + API.getToken() },
        });

        if (!reponse.ok) {
            let message = 'Téléchargement impossible.';
            try {
                const err = await reponse.json();
                if (err && err.error) message = err.error;
            } catch (e) { /* réponse non JSON */ }
            afficherMessage('message-sauvegarde', message, 'erreur');
            return;
        }

        const blob = await reponse.blob();
        const entete = reponse.headers.get('Content-Disposition') || '';
        const trouve = /filename="([^"]+)"/.exec(entete);
        const nom = trouve ? trouve[1] : 'sauvegarde.json';

        const url = URL.createObjectURL(blob);
        const lien = document.createElement('a');
        lien.href = url;
        lien.download = nom;
        document.body.appendChild(lien);
        lien.click();
        lien.remove();
        setTimeout(() => URL.revokeObjectURL(url), 4000);

        afficherMessage('message-sauvegarde', 'Sauvegarde téléchargée.', 'succes');
    } catch (e) {
        afficherMessage('message-sauvegarde', 'Téléchargement impossible.', 'erreur');
    } finally {
        if (bouton) bouton.disabled = false;
    }
}

/* ============================================================
   DÉMARRAGE
   ============================================================ */
if (user) {
    chargerStats();
    afficherVue();
    // Temps réel : rafraîchit les données dès qu'elles changent,
    // sans recharger la page.
    TempsReel.demarrer({ intervalle: 5000, callback: rafraichirSilencieux });
}
