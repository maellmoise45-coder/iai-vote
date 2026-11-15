/* ============================================================
   Digital-Elect — synchronisation temps réel
   ------------------------------------------------------------
   Interroge régulièrement /api/sync.php qui renvoie uniquement
   une empreinte des données. Le navigateur ne recharge jamais la
   page : il ne redemande les données que si l'empreinte a changé.

   - pause automatique quand l'onglet est masqué ;
   - vérification immédiate au retour sur l'onglet ;
   - temporisation progressive en cas d'erreur réseau ;
   - aucune donnée métier dans la réponse de synchronisation.
   ============================================================ */

const TempsReel = (() => {
    let options = null;
    let minuterie = null;
    let signature = null;
    let enCours = false;
    let echecs = 0;
    let actif = false;

    function surReveil() {
        if (!document.hidden) verifier(true);
    }

    /**
     * Vérifie l'empreinte une fois.
     * @param {boolean} ignorerMasque  vérifier même si l'onglet est masqué
     */
    async function verifier(ignorerMasque = false) {
        if (!actif || !options || enCours) return;
        if (document.hidden && !ignorerMasque) return;

        enCours = true;
        let reponse = null;
        try {
            const chemin = 'sync.php'
                + (options.cle ? '?cle=' + encodeURIComponent(options.cle) : '');
            reponse = await API.get(chemin);
        } catch (e) {
            reponse = null;
        } finally {
            enCours = false;
        }

        if (!reponse || !reponse.ok || !reponse.data || !reponse.data.success) {
            echecs = Math.min(6, echecs + 1);
            return;
        }

        echecs = 0;
        const premiere = signature === null;
        const change = !premiere && reponse.data.sig !== signature;
        signature = reponse.data.sig;

        // Au premier passage on mémorise seulement l'empreinte : la page
        // vient déjà de faire son chargement initial. On ne déclenche le
        // callback au premier passage que si le demandeur le souhaite.
        const declencher = premiere ? options.initial === true : change;
        if (!declencher) return;

        try {
            await options.callback(reponse.data);
        } catch (e) {
            /* une erreur d'affichage ne doit jamais arrêter la boucle */
            console.error('[TempsReel]', e);
        }
    }

    function programmer(delai) {
        if (!actif) return;
        clearTimeout(minuterie);
        minuterie = setTimeout(boucle, delai);
    }

    async function boucle() {
        if (!actif) return;
        await verifier(false);
        if (!actif) return;

        const base = Math.max(1500, Number(options && options.intervalle) || 4000);
        const delai = echecs
            ? Math.min(30000, base * Math.pow(2, Math.min(4, echecs)))
            : base;
        programmer(delai + Math.random() * 300);
    }

    /**
     * Démarre la synchronisation.
     * @param {object} opts
     * @param {number} [opts.intervalle=4000]  délai entre deux vérifications (ms)
     * @param {string} [opts.cle]              'vote' pour l'empreinte allégée
     * @param {boolean} [opts.initial=false]   appeler le callback dès le 1er passage
     * @param {function} opts.callback         appelé quand les données ont changé
     */
    function demarrer(opts = {}) {
        arreter();
        options = Object.assign({ intervalle: 4000, initial: false }, opts);
        actif = true;
        signature = null;
        echecs = 0;
        document.addEventListener('visibilitychange', surReveil);
        window.addEventListener('focus', surReveil);
        boucle();
    }

    function arreter() {
        actif = false;
        clearTimeout(minuterie);
        minuterie = null;
        options = null;
        document.removeEventListener('visibilitychange', surReveil);
        window.removeEventListener('focus', surReveil);
    }

    /** Ajuste la cadence sans relancer la boucle (sans perdre l'empreinte). */
    function changerIntervalle(ms) {
        if (!options) return;
        const valeur = Math.max(1500, Number(ms) || 0);
        if (valeur > 0) options.intervalle = valeur;
    }

    /** Force une vérification immédiate (retour d'onglet, action locale). */
    function rafraichirMaintenant() {
        verifier(true);
    }

    return { demarrer, arreter, changerIntervalle, rafraichirMaintenant };
})();
