/* ============================================================
   Tableau de bord — admin / gestionnaire
   ============================================================ */

const user = protegerPage(['admin', 'gestionnaire']);
const estAdmin = user && user.role === 'admin';

if (user) {
    document.getElementById('entete-user').innerHTML =
        `<b>${echapper(user.prenom)} ${echapper(user.nom)}</b><small>${estAdmin ? 'Administrateur' : 'Gestionnaire'}</small>`;
    // Les onglets réservés à l'admin
    if (!estAdmin) {
        document.getElementById('onglet-gestionnaires').remove();
        document.getElementById('onglet-journal').remove();
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
let vueActive = 'elections';
document.getElementById('onglets').addEventListener('click', (e) => {
    const bouton = e.target.closest('.onglet');
    if (!bouton) return;
    document.querySelectorAll('.onglet').forEach(o => o.classList.remove('actif'));
    bouton.classList.add('actif');
    vueActive = bouton.dataset.vue;
    afficherVue();
});

function afficherVue() {
    const vue = document.getElementById('vue');
    vue.innerHTML = '<div class="carte"><p class="vide">Chargement…</p></div>';
    if (vueActive === 'elections') vueElections(vue);
    else if (vueActive === 'electeurs') vueComptes(vue, 'electeur');
    else if (vueActive === 'gestionnaires') vueComptes(vue, 'gestionnaire');
    else if (vueActive === 'journal') vueJournal(vue);
}

/* ============================================================
   STATISTIQUES
   ============================================================ */
async function chargerStats() {
    const { data } = await API.get('statistiques.php');
    if (!data.success) return;
    const s = data.stats;
    document.getElementById('grille-stats').innerHTML = `
        <div class="stat"><div class="valeur">${s.electeurs}</div><div class="libelle">Électeurs inscrits</div></div>
        <div class="stat"><div class="valeur">${s.elections}</div><div class="libelle">Élections</div></div>
        <div class="stat"><div class="valeur">${s.en_cours}</div><div class="libelle">Scrutins en cours</div></div>
        <div class="stat"><div class="valeur">${s.votes}</div><div class="libelle">Votes exprimés</div></div>
    `;
}

/* ============================================================
   VUE : ÉLECTIONS
   ============================================================ */
async function vueElections(conteneur) {
    const { data } = await API.get('elections.php');
    const elections = data.data || [];

    let html = `
        <div class="carte">
            <div class="carte-titre">
                <span>Élections (${elections.length})</span>
                <button class="btn btn-primaire btn-petit" id="btn-nouvelle-election">+ Nouvelle élection</button>
            </div>`;

    if (elections.length === 0) {
        html += `<p class="vide">Aucune élection. Créez-en une pour commencer.</p>`;
    } else {
        html += `<table class="tableau">
            <thead><tr>
                <th>Titre</th><th>Statut</th><th>Dates</th>
                <th>Cand.</th><th>Votes</th><th>Actions</th>
            </tr></thead><tbody>`;
        for (const e of elections) {
            const demarrer = ['a_venir', 'annulee'].includes(e.statut)
                ? `<button class="btn btn-succes btn-petit" data-act="demarrer" data-id="${e.id}">Ouvrir</button>` : '';
            const cloturer = e.statut === 'en_cours'
                ? `<button class="btn btn-danger btn-petit" data-act="cloturer" data-id="${e.id}">Clôturer</button>` : '';
            const publier = e.statut === 'terminee'
                ? `<button class="btn btn-primaire btn-petit" data-act="publier" data-id="${e.id}">Publier</button>` : '';

            html += `<tr>
                <td><b>${echapper(e.titre)}</b></td>
                <td>${badgeStatut(e.statut)}</td>
                <td style="font-size:13px; color:var(--gris);">${formaterDate(e.date_debut)} → ${formaterDate(e.date_fin)}</td>
                <td>${e.nb_candidats}</td>
                <td>${e.nb_votes}</td>
                <td><div class="actions">
                    <button class="btn btn-neutre btn-petit" data-act="candidats" data-id="${e.id}">Candidats</button>
                    ${demarrer}${cloturer}${publier}
                    <button class="btn btn-neutre btn-petit" data-act="resultats" data-id="${e.id}">Résultats</button>
                    <button class="btn btn-neutre btn-petit" data-act="modifier" data-id="${e.id}">Modifier</button>
                    ${estAdmin ? `<button class="btn btn-danger btn-petit" data-act="supprimer" data-id="${e.id}">Suppr.</button>` : ''}
                </div></td>
            </tr>`;
        }
        html += `</tbody></table>`;
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
    const champs = `
        <div class="champ"><label>Titre</label>
            <input name="titre" required value="${echapper(e.titre || '')}"></div>
        <div class="champ"><label>Description</label>
            <textarea name="description">${echapper(e.description || '')}</textarea></div>
        <div class="champ"><label>Date de début</label>
            <input type="datetime-local" name="date_debut" value="${e.date_debut ? e.date_debut.slice(0,16) : ''}"></div>
        <div class="champ"><label>Date de fin</label>
            <input type="datetime-local" name="date_fin" value="${e.date_fin ? e.date_fin.slice(0,16) : ''}"></div>`;

    ouvrirModale(election ? 'Modifier l\'élection' : 'Nouvelle élection', champs, async (fd) => {
        const corps = {
            titre: fd.get('titre').trim(),
            description: fd.get('description').trim(),
            date_debut: fd.get('date_debut') || null,
            date_fin: fd.get('date_fin') || null,
        };
        if (!corps.titre) return 'Le titre est obligatoire.';

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

// PUBLIER les résultats : ils deviennent visibles par tout le monde.
async function publierResultats(election) {
    if (!confirm(`Publier les résultats de « ${election.titre} » ? Ils seront visibles par tous.`)) return;
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
            <input type="password" name="new_password" required minlength="6" autocomplete="new-password"></div>
        <div class="champ"><label>Confirmer le nouveau mot de passe</label>
            <input type="password" name="confirmation" required autocomplete="new-password"></div>`;

    ouvrirModale('Changer mon mot de passe', champs, async (fd) => {
        const ancien = fd.get('old_password');
        const nouveau = fd.get('new_password');
        const confirmation = fd.get('confirmation');

        if (nouveau.length < 6) return 'Le nouveau mot de passe doit contenir au moins 6 caractères.';
        if (nouveau !== confirmation) return 'Les deux mots de passe ne correspondent pas.';

        const rep = await API.post('auth.php', {
            action: 'change_password',
            old_password: ancien,
            new_password: nouveau,
        });
        if (!rep.ok || !rep.data.success) return rep.data.error || 'Erreur.';

        fermerModale();
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
        ? '<table class="tableau"><tbody>' + candidats.map(c => `
            <tr>
                <td style="width:52px">${vignetteCandidat(c)}</td>
                <td><b>${echapper(c.prenom)} ${echapper(c.nom)}</b><br>
                    <small style="color:var(--gris)">${echapper(c.programme || '')}</small></td>
                <td style="text-align:right; white-space:nowrap">
                    <button class="btn btn-neutre btn-petit" data-edit="${c.id}">Modifier</button>
                    <button class="btn btn-danger btn-petit" data-del="${c.id}">Suppr.</button>
                </td>
            </tr>
          `).join('') + '</tbody></table>'
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

/* ---------- Résultats (modale) ---------- */
async function modaleResultats(election) {
    const { ok, data } = await API.get('resultats.php?election_id=' + election.id);
    if (!ok || !data.success) {
        ouvrirModale('Résultats', `<p class="vide">${echapper(data.error || 'Indisponible.')}</p>`, async () => null, 'Fermer');
        return;
    }

    let html = `<p style="color:var(--gris); font-size:14px; margin-bottom:6px;">
        ${data.total_votes} vote(s) — participation ${data.participation}% (${data.inscrits} inscrits)${
            Number(data.votes_nuls || 0) > 0 ? ` — dont <b>${data.votes_nuls}</b> nul(s)` : ''}</p>`;

    if (data.candidats.length === 0) {
        html += '<p class="vide">Aucun candidat.</p>';
    } else {
        for (const c of data.candidats) {
            html += `<div class="barre-resultat">
                <div class="barre-tete">
                    <span style="display:flex; align-items:center; gap:10px;">${vignetteCandidat(c)} ${echapper(c.prenom)} ${echapper(c.nom)}</span>
                    <span>${c.nombre_voix} voix (${c.pourcentage}%)</span></div>
                <div class="barre-fond"><div class="barre-remplissage" style="width:${c.pourcentage}%"></div></div>
            </div>`;
        }
    }

    // Bulletins nuls (chrono écoulé) : comptés à part.
    const nuls = Number(data.votes_nuls || 0);
    if (nuls > 0) {
        const total = Number(data.total_votes || 0);
        const pct = total > 0 ? Math.round(nuls * 100 / total) : 0;
        html += `<div class="barre-resultat">
            <div class="barre-tete">
                <span style="display:flex; align-items:center; gap:10px;">
                    <span class="vignette vignette-nulle">⊘</span> Votes nuls (temps de vote écoulé)</span>
                <span>${nuls} voix</span></div>
            <div class="barre-fond"><div class="barre-remplissage barre-nulle" style="width:${pct}%"></div></div>
        </div>`;
    }

    // Publication explicite (uniquement après clôture, si pas déjà publiée).
    const peutPublier = !data.election.resultats_publics &&
        ['terminee', 'publiee'].includes(data.election.statut);
    if (peutPublier) {
        html += `<div style="margin-top:18px;">
            <button type="button" class="btn btn-primaire" id="btn-publier-resultats">Publier les résultats</button>
            <span style="color:var(--gris); font-size:13px; margin-left:8px;">Ils deviendront visibles par tous.</span>
        </div>`;
    }

    ouvrirModale('Résultats — ' + election.titre, html, async () => { fermerModale(); return null; }, 'Fermer');

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
   VUE : COMPTES (électeurs / gestionnaires)
   ============================================================ */
async function vueComptes(conteneur, role) {
    const titreRole = role === 'electeur' ? 'Électeurs' : 'Gestionnaires';
    const { data } = await API.get('electeurs.php?role=' + role);
    const comptes = data.data || [];

    let html = `
        <div class="carte">
            <div class="carte-titre">
                <span>${titreRole} (${data.total || comptes.length})</span>
                <button class="btn btn-primaire btn-petit" id="btn-nouveau-compte">+ Nouveau</button>
            </div>`;

    if (comptes.length === 0) {
        html += `<p class="vide">Aucun compte.</p>`;
    } else {
        html += `<table class="tableau">
            <thead><tr><th>Matricule</th><th>Nom</th><th>Email</th><th>Statut</th><th>Actions</th></tr></thead><tbody>`;
        for (const u of comptes) {
            html += `<tr>
                <td><b>${echapper(u.matricule)}</b></td>
                <td>${echapper(u.prenom)} ${echapper(u.nom)}</td>
                <td style="font-size:13px">${echapper(u.email || '—')}</td>
                <td>${u.actif ? '<span class="badge badge-vert">Actif</span>' : '<span class="badge badge-rouge">Inactif</span>'}</td>
                <td><div class="actions">
                    <button class="btn btn-neutre btn-petit" data-act="edit" data-id="${u.id}">Modifier</button>
                    <button class="btn btn-neutre btn-petit" data-act="pwd" data-id="${u.id}">Mot de passe</button>
                    ${estAdmin ? `<button class="btn btn-danger btn-petit" data-act="del" data-id="${u.id}">Suppr.</button>` : ''}
                </div></td>
            </tr>`;
        }
        html += `</tbody></table>`;
    }
    html += `</div>`;
    conteneur.innerHTML = html;

    document.getElementById('btn-nouveau-compte').addEventListener('click', () => modaleCompte(null, role));

    conteneur.querySelectorAll('[data-act]').forEach(btn => {
        const id = parseInt(btn.dataset.id, 10);
        const compte = comptes.find(c => c.id === id);
        btn.addEventListener('click', () => {
            if (btn.dataset.act === 'edit') modaleCompte(compte, role);
            else if (btn.dataset.act === 'pwd') modaleMotDePasse(compte);
            else if (btn.dataset.act === 'del') supprimerCompte(compte);
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
        ${compte ? '' : `<div class="champ"><label>Mot de passe</label>
            <input type="text" name="mot_de_passe" required placeholder="min. 6 caractères"></div>`}
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
                actif: fd.get('actif') === 'true',
            });
            if (!rep.ok || !rep.data.success) return rep.data.error || 'Erreur.';
        } else {
            const rep = await API.post('electeurs.php', {
                matricule: fd.get('matricule').trim(),
                prenom: fd.get('prenom').trim(),
                nom: fd.get('nom').trim(),
                email: fd.get('email').trim(),
                mot_de_passe: fd.get('mot_de_passe'),
                role,
            });
            if (!rep.ok || !rep.data.success) return rep.data.error || 'Erreur.';
        }
        fermerModale();
        afficherMessage('message', 'Compte enregistré.', 'succes');
        chargerStats(); afficherVue();
        return null;
    });
}

function modaleMotDePasse(compte) {
    const champs = `<div class="champ"><label>Nouveau mot de passe pour ${echapper(compte.matricule)}</label>
        <input type="text" name="mot_de_passe" required placeholder="min. 6 caractères"></div>`;
    ouvrirModale('Réinitialiser le mot de passe', champs, async (fd) => {
        const rep = await API.put('electeurs.php', { id: compte.id, mot_de_passe: fd.get('mot_de_passe') });
        if (!rep.ok || !rep.data.success) return rep.data.error || 'Erreur.';
        fermerModale();
        afficherMessage('message', 'Mot de passe réinitialisé.', 'succes');
        return null;
    });
}

async function supprimerCompte(compte) {
    if (!confirm(`Supprimer le compte ${compte.matricule} ?`)) return;
    const { ok, data } = await API.del('electeurs.php?id=' + compte.id);
    if (!ok || !data.success) { afficherMessage('message', data.error || 'Erreur.', 'erreur'); return; }
    afficherMessage('message', 'Compte supprimé.', 'succes');
    chargerStats(); afficherVue();
}

/* ============================================================
   VUE : JOURNAL
   ============================================================ */
async function vueJournal(conteneur) {
    const { data } = await API.get('journal.php?limite=100');
    const lignes = data.data || [];

    let html = `<div class="carte"><div class="carte-titre">Journal des actions</div>`;
    if (lignes.length === 0) {
        html += `<p class="vide">Aucune action enregistrée.</p>`;
    } else {
        html += `<table class="tableau">
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
        html += `</tbody></table>`;
    }
    html += `</div>`;
    conteneur.innerHTML = html;
}

/* ============================================================
   DÉMARRAGE
   ============================================================ */
if (user) {
    chargerStats();
    afficherVue();
}
