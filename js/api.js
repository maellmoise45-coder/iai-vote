/* ============================================================
   Digital-Elect — socle JavaScript partagé
   ------------------------------------------------------------
   Gère : appel API, jeton, session, helpers d'affichage.
   ============================================================ */

const API = (() => {
    // URL de l'API, relative : fonctionne en local comme en ligne
    const BASE = 'api/';

    function getToken() {
        return localStorage.getItem('iai_token');
    }

    function setSession(token, user) {
        localStorage.setItem('iai_token', token);
        localStorage.setItem('iai_user', JSON.stringify(user));
    }

    function getUser() {
        try { return JSON.parse(localStorage.getItem('iai_user') || 'null'); }
        catch { return null; }
    }

    function clearSession() {
        localStorage.removeItem('iai_token');
        localStorage.removeItem('iai_user');
    }

    /**
     * Appel API générique.
     * @returns {Promise<{ok:boolean,status:number,data:any}>}
     */
    async function requete(chemin, options = {}) {
        const entetes = { 'Content-Type': 'application/json' };
        const token = getToken();
        if (token) entetes['Authorization'] = 'Bearer ' + token;

        let reponse;
        try {
            reponse = await fetch(BASE + chemin, {
                method: options.method || 'GET',
                headers: entetes,
                body: options.body ? JSON.stringify(options.body) : undefined,
            });
        } catch (e) {
            return { ok: false, status: 0, data: { error: 'Serveur injoignable. Verifiez qu\'Apache et PostgreSQL sont demarres.' } };
        }

        let data = {};
        const texte = await reponse.text();
        if (texte) {
            try { data = JSON.parse(texte); }
            catch { data = { error: 'Reponse invalide du serveur', brut: texte.slice(0, 200) }; }
        }

        // Session expirée / jeton invalide : on renvoie vers la connexion
        if (reponse.status === 401) {
            clearSession();
            if (!location.pathname.endsWith('index.html') && location.pathname !== '/') {
                setTimeout(() => location.href = 'index.html', 800);
            }
        }

        return { ok: reponse.ok, status: reponse.status, data };
    }

    const get  = (chemin)       => requete(chemin, { method: 'GET' });
    const post = (chemin, body) => requete(chemin, { method: 'POST', body });
    const put  = (chemin, body) => requete(chemin, { method: 'PUT', body });
    const del  = (chemin)       => requete(chemin, { method: 'DELETE' });

    return { requete, get, post, put, del, getToken, setSession, getUser, clearSession };
})();

/* ---------- Utilitaires d'affichage ---------- */

function afficherMessage(zone, texte, type = 'erreur') {
    const el = typeof zone === 'string' ? document.getElementById(zone) : zone;
    if (!el) return;
    el.className = 'alerte alerte-' + type;
    el.textContent = texte;
    el.classList.remove('cache');
}

function masquerMessage(zone) {
    const el = typeof zone === 'string' ? document.getElementById(zone) : zone;
    if (el) el.classList.add('cache');
}

function echapper(texte) {
    const d = document.createElement('div');
    d.textContent = texte == null ? '' : String(texte);
    return d.innerHTML;
}

function formaterDate(valeur) {
    if (!valeur) return '—';
    const d = new Date(valeur);
    if (isNaN(d.getTime())) return '—';
    return d.toLocaleDateString('fr-FR', { day: '2-digit', month: 'short', year: 'numeric' });
}

function formaterDateHeure(valeur) {
    if (!valeur) return '—';
    const d = new Date(valeur);
    if (isNaN(d.getTime())) return '—';
    return d.toLocaleString('fr-FR', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

const LIBELLE_STATUT = {
    a_venir:  'À venir',
    en_cours: 'En cours',
    terminee: 'Terminée',
    publiee:  'Publiée',
    annulee:  'Annulée',
};

function badgeStatut(statut) {
    const classes = {
        a_venir:  'badge-gris',
        en_cours: 'badge-vert',
        terminee: 'badge-orange',
        publiee:  'badge-vert',
        annulee:  'badge-rouge',
    };
    return `<span class="badge ${classes[statut] || 'badge-gris'}">${LIBELLE_STATUT[statut] || echapper(statut)}</span>`;
}

/** Redirige vers la page correspondant au rôle connecté. */
function redirigerSelonRole() {
    const user = API.getUser();
    if (!user) { location.href = 'index.html'; return; }
    if (user.role === 'electeur') location.href = 'vote.html';
    else if (user.role === 'controleur') location.href = 'controle.html';
    else location.href = 'dashboard.html';
}

/** Protège une page : redirige si non connecté ou rôle non autorisé. */
function protegerPage(rolesAutorises) {
    const user = API.getUser();
    if (!user || !API.getToken()) {
        location.href = 'index.html';
        return null;
    }
    if (rolesAutorises && !rolesAutorises.includes(user.role)) {
        redirigerSelonRole();
        return null;
    }
    return user;
}
