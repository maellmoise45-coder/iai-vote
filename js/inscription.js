/* ============================================================
   Inscription par lien de classe — Digital-Elect
   ------------------------------------------------------------
   Le token du lien impose la classe. Le formulaire ne contient
   AUCUN champ de zone : la valeur vient exclusivement du serveur.
   Après l'inscription, l'étudiant est connecté automatiquement.
   ============================================================ */

const token = (new URLSearchParams(location.search).get('token') || '').trim();

const form = document.getElementById('form-inscription');
const chargement = document.getElementById('chargement');
const zoneInvalide = document.getElementById('zone-invalide');
const zoneClasse = document.getElementById('zone-classe');
const infoDelegue = document.getElementById('info-delegue');

let zoneImposee = '';
let envoiEnCours = false;

function montrerLienInvalide(message) {
    chargement.classList.add('cache');
    form.classList.add('cache');
    zoneInvalide.classList.remove('cache');
    document.getElementById('erreur-lien').textContent = message;
}

async function verifierLien() {
    if (!token) {
        montrerLienInvalide(
            "Aucun lien d'inscription fourni. Utilisez le lien transmis par votre délégué de classe."
        );
        return;
    }

    if (!/^[a-f0-9]{64}$/.test(token)) {
        montrerLienInvalide(
            "Ce lien d'inscription est invalide. Demandez un nouveau lien à votre délégué de classe."
        );
        return;
    }

    const { ok, data } = await API.get('inscription.php?token=' + encodeURIComponent(token));

    if (!ok || !data.success) {
        montrerLienInvalide(
            data.error
            || "Ce lien d'inscription est invalide, révoqué ou expiré. Demandez un nouveau lien à votre délégué."
        );
        return;
    }

    zoneImposee = data.data.zone;
    zoneClasse.textContent = zoneImposee;

    if (data.data.delegue) {
        infoDelegue.textContent = `Lien généré par ${data.data.delegue}, délégué de cette classe.`;
    } else {
        infoDelegue.textContent = '';
    }

    chargement.classList.add('cache');
    zoneInvalide.classList.add('cache');
    form.classList.remove('cache');
}

form.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (envoiEnCours) return;

    masquerMessage('message');

    const donnees = new FormData(form);
    const matricule = String(donnees.get('matricule') || '').trim().toUpperCase();
    const prenom = String(donnees.get('prenom') || '').trim();
    const nom = String(donnees.get('nom') || '').trim();
    const email = String(donnees.get('email') || '').trim();
    const motDePasse = String(donnees.get('mot_de_passe') || '');
    const confirmation = String(donnees.get('confirmation') || '');

    if (!matricule || !prenom || !nom) {
        afficherMessage('message', 'Matricule, prénom et nom sont obligatoires.', 'erreur');
        return;
    }
    if (motDePasse.length < 8) {
        afficherMessage('message', 'Le mot de passe doit contenir au moins 8 caractères.', 'erreur');
        return;
    }
    if (motDePasse !== confirmation) {
        afficherMessage('message', 'Les deux mots de passe ne correspondent pas.', 'erreur');
        return;
    }

    const bouton = document.getElementById('btn-inscription');
    envoiEnCours = true;
    bouton.disabled = true;
    bouton.textContent = 'Inscription en cours…';

    const { ok, data } = await API.post('inscription.php', {
        token,
        matricule,
        prenom,
        nom,
        email,
        mot_de_passe: motDePasse,
    });

    if (!ok || !data.success) {
        envoiEnCours = false;
        bouton.disabled = false;
        bouton.textContent = "M'inscrire dans cette classe";
        afficherMessage('message', data.error || 'Inscription impossible.', 'erreur');
        return;
    }

    // Inscription réussie : connexion automatique.
    form.classList.add('cache');
    afficherMessage(
        'message',
        `Inscription réussie dans la classe « ${zoneImposee} ». Connexion en cours…`,
        'succes'
    );
    bouton.textContent = 'Inscription réussie';

    const connexion = await API.post('auth.php', {
        action: 'login',
        matricule,
        mot_de_passe: motDePasse,
    });

    if (connexion.ok && connexion.data.success) {
        API.setSession(connexion.data.token, connexion.data.user);
        setTimeout(() => { location.href = 'vote.html'; }, 1300);
        return;
    }

    // La connexion automatique a échoué (rare) : on renvoie vers la
    // page de connexion classique.
    afficherMessage(
        'message',
        'Inscription réussie. Connectez-vous avec votre matricule et votre mot de passe.',
        'succes'
    );
    setTimeout(() => { location.href = 'index.html'; }, 2200);
});

verifierLien();
