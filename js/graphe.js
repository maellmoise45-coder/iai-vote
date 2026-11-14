/* ============================================================
   Digital-Elect — diagramme en barres des résultats
   ------------------------------------------------------------
   Affichage 100 % CSS/JS (aucune bibliothèque externe) :
     - un axe vertical gradué et une grille horizontale ;
     - une barre pleine par candidat, couleur distincte ;
     - la valeur au-dessus de chaque barre ;
     - nom + photo SOUS les barres ;
     - les bulletins nuls apparaissent en gris ;
     - le candidat en tête est mis en avant (★).

   Le module ne fait aucun appel réseau : il reçoit les données
   de resultats.php / suivi.php et retourne du HTML échappé.
   ============================================================ */

const Graphe = (() => {
    const PALETTE = [
        '#1e4fd8', '#0ea5e9', '#16a34a', '#f59e0b', '#8b5cf6',
        '#ec4899', '#14b8a6', '#f97316', '#6366f1', '#84cc16',
    ];
    const COULEUR_NUL = '#94a3b8';

    function couleur(index) {
        return PALETTE[index % PALETTE.length];
    }

    function initiales(c) {
        const p = (c.prenom || '').trim();
        const n = (c.nom || '').trim();
        return ((p[0] || '') + (n[0] || '')).toUpperCase() || '?';
    }

    function pct(valeur, total) {
        if (!(total > 0)) return '0';
        const v = Math.round(valeur * 1000 / total) / 10;
        return String(v).replace('.', ',');
    }

    /**
     * Échelle « ronde » : renvoie le haut de l'axe et le pas des
     * graduations, pour 4 à 6 intervalles lisibles (1, 2, 5, 10…).
     */
    function echelle(max) {
        if (!(max > 0)) return { haut: 4, pas: 1 };
        // Petits effectifs : axe 0..4 pour garder des graduations lisibles.
        if (max <= 4) return { haut: 4, pas: 1 };
        const pasBrut = max / 6;
        const magnitude = Math.pow(10, Math.floor(Math.log10(pasBrut)));
        const normalise = pasBrut / magnitude;
        let pas = normalise <= 1 ? 1 : normalise <= 2 ? 2 : normalise <= 5 ? 5 : 10;
        pas = Math.max(1, Math.round(pas * magnitude));
        return { haut: Math.ceil(max / pas) * pas, pas };
    }

    function vignette(c, i) {
        if (c.photo_url) {
            return `<img class="graphe-photo" src="${echapper(c.photo_url)}"
                         alt="${echapper((c.prenom + ' ' + c.nom).trim())}" loading="lazy">`;
        }
        if (c.nul) {
            return `<div class="graphe-photo graphe-photo-vide" style="--couleur:${COULEUR_NUL}"
                         aria-hidden="true">⊘</div>`;
        }
        return `<div class="graphe-photo graphe-photo-vide"
                     style="--couleur:${couleur(i)}" aria-hidden="true">${echapper(initiales(c))}</div>`;
    }

    function resume(reels, votants, max) {
        if (!reels.length) return 'Aucun résultat.';
        if (max <= 0) return 'Aucun vote exprimé pour le moment.';
        const devant = reels.filter(c => c.voix === max)
            .map(c => `${c.prenom} ${c.nom}`.trim())
            .join(', ');
        return `Résultats en barres : ${devant} en tête avec ${max} voix `
             + `sur ${votants} votant(s).`;
    }

    /**
     * @param {Array} candidats  [{id, nom, prenom, photo_url, nombre_voix}]
     * @param {Object} options   {titre, note, votants, nuls}
     * @returns {string} HTML
     */
    function rendre(candidats, options = {}) {
        const reels = (Array.isArray(candidats) ? candidats : []).map((c, i) => ({
            id: c.id,
            nom: c.nom || '',
            prenom: c.prenom || '',
            photo_url: c.photo_url || null,
            voix: Math.max(0, parseInt(c.nombre_voix, 10) || 0),
            couleur: couleur(i),
            nul: false,
        }));

        if (reels.length === 0) {
            return '<p class="vide">Aucun candidat.</p>';
        }

        const liste = reels.slice();
        const nuls = Math.max(0, parseInt(options.nuls, 10) || 0);
        if (options.nuls !== undefined && options.nuls !== null) {
            liste.push({
                id: 'nul',
                nom: 'Bulletins nuls',
                prenom: '',
                photo_url: null,
                voix: nuls,
                couleur: COULEUR_NUL,
                nul: true,
            });
        }

        // Les pourcentages sont calculés sur les VOTANTS (bulletins
        // exprimés, nuls compris), comme demandé.
        const totalVoix = reels.reduce((s, c) => s + c.voix, 0);
        const votants = Math.max(0, parseInt(options.votants, 10) || 0) || totalVoix;

        const maxReel = Math.max(...reels.map(c => c.voix), 0);
        const maxTotal = Math.max(...liste.map(c => c.voix), 0);
        const { haut, pas } = echelle(maxTotal);
        const divisions = Math.max(1, Math.round(haut / pas));
        const n = liste.length;

        const colonnes = liste.map(c => {
            const hauteur = haut > 0
                ? Math.max(c.voix > 0 ? 1.5 : 0, c.voix * 100 / haut)
                : 0;
            const leader = !c.nul && maxReel > 0 && c.voix === maxReel;
            return `
                <div class="graphe-colonne${leader ? ' leader' : ''}${c.nul ? ' nul' : ''}"
                     style="--couleur:${c.couleur};--h:${hauteur.toFixed(2)}%">
                    <div class="graphe-barre"
                         title="${echapper((c.prenom + ' ' + c.nom).trim() + ' : ' + c.voix + ' voix')}"></div>
                    <div class="graphe-valeur">${c.voix}</div>
                </div>`;
        }).join('');

        const graduations = [];
        for (let v = haut; v >= 0; v -= pas) graduations.push(v);

        const y = graduations.map(v =>
            `<span style="bottom:${(v * 100 / haut).toFixed(2)}%">${v}</span>`
        ).join('');

        const legende = liste.map(c => {
            const leader = !c.nul && maxReel > 0 && c.voix === maxReel;
            const p = pct(c.voix, votants);
            return `
                <div class="graphe-item${leader ? ' leader' : ''}${c.nul ? ' nul' : ''}"
                     style="--couleur:${c.couleur}">
                    ${vignette(c, liste.indexOf(c))}
                    <b class="graphe-nom">${leader ? '★ ' : ''}${echapper((c.prenom + ' ' + c.nom).trim())}</b>
                    <span class="graphe-voix">${c.voix} voix · ${p} %</span>
                </div>`;
        }).join('');

        const titre = options.titre
            ? `<div class="graphe-titre">${echapper(options.titre)}</div>`
            : '';

        return `<div class="graphe-bloc">
            ${titre}
            <div class="graphe-scroll">
                <div class="graphe" style="--nb:${n};--divisions:${divisions}"
                     role="img" aria-label="${echapper(resume(reels, votants, maxReel))}">
                    <div class="graphe-y" aria-hidden="true">
                        <div class="graphe-y-echelle">${y}</div>
                    </div>
                    <div class="graphe-plot" aria-hidden="true">
                        <div class="graphe-colonnes">${colonnes}</div>
                    </div>
                    <div class="graphe-legende">${legende}</div>
                </div>
            </div>
            ${options.note ? `<p class="graphe-note">${echapper(options.note)}</p>` : ''}
        </div>`;
    }

    return { rendre, couleur };
})();
