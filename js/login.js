/* ============================================================
   Page de connexion — Digital-Elect
   ============================================================ */

// Déjà connecté ? On va directement au bon espace.
if (API.getUser() && API.getToken()) {
    redirigerSelonRole();
}

// Message si l'électeur a été expulsé (chrono de vote écoulé).
const motifExpulsion = sessionStorage.getItem('iai_expire');
if (motifExpulsion) {
    sessionStorage.removeItem('iai_expire');
    afficherMessage(
        'message',
        '⏱️ Temps de vote écoulé : votre bulletin a été déclaré NUL et vous avez été déconnecté. ' +
        'Vous pouvez vous reconnecter, mais votre vote pour cette élection est déjà enregistré.',
        'erreur'
    );
}

const form = document.getElementById('form-connexion');
const btn  = document.getElementById('btn-connexion');

form.addEventListener('submit', async (e) => {
    e.preventDefault();
    masquerMessage('message');

    const matricule = document.getElementById('matricule').value.trim();
    const motDePasse = document.getElementById('mot_de_passe').value;

    if (!matricule || !motDePasse) {
        afficherMessage('message', 'Veuillez remplir tous les champs.');
        return;
    }

    btn.disabled = true;
    btn.textContent = 'Connexion…';

    const { ok, data } = await API.post('auth.php', {
        action: 'login',
        matricule,
        mot_de_passe: motDePasse,
    });

    btn.disabled = false;
    btn.textContent = 'Se connecter';

    if (!ok || !data.success) {
        afficherMessage('message', data.error || 'Échec de la connexion.');
        return;
    }

    API.setSession(data.token, data.user);

    afficherMessage('message', 'Connexion réussie ! Redirection…', 'succes');
    setTimeout(() => {
        location.href = data.user.role === 'electeur' ? 'vote.html' : 'dashboard.html';
    }, 500);
});
