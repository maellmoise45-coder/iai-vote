<?php
/**
 * ============================================================
 *  Synchronisation temps reel - /api/sync.php
 * ============================================================
 *  GET   empreinte des donnees visibles par l'appelant
 *
 *  Le frontend interroge cet endpoint a intervalle court (toutes
 *  les 3 a 5 secondes). Il ne renvoie AUCUNE donnee metier :
 *  uniquement une empreinte (hash) qui change des qu'une election,
 *  un candidat, un vote, un compte ou un lien est modifie.
 *
 *  Le navigateur ne recharge donc plus la page : il compare
 *  l'empreinte et ne redemande les donnees que si quelque chose
 *  a reellement change. C'est le "temps reel" sans surcharge.
 *
 *  ?cle=vote : empreinte allegee (structure des elections et
 *  candidats uniquement, sans les votes) pour la page de vote :
 *  un vote exprime ne doit pas faire clignoter le bulletin d'un
 *  autre electeur en train de choisir.
 *
 *  SECURITE
 *  --------
 *  - l'empreinte est calculee dans le perimetre de l'utilisateur
 *    (admin : tout, delegue : sa classe, electeur : generales +
 *    sa classe, controleur/visiteur : generales) ;
 *  - aucun compteur, identifiant ou nom n'est renvoye : impossible
 *    de deviner l'existence d'une election de classe ;
 *  - limitation de requetes par utilisateur/IP (api/cache) ;
 *  - reponse non mise en cache.
 * ============================================================
 */
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['error' => 'Methode non autorisee'], 405);
}

$pdo  = getDB();
$user = getAuthUser($pdo, false);
$role = $user['user_role'] ?? '';

limiterRequetes(
    'sync',
    600,
    60,
    $user ? ('u' . (int)$user['user_id']) : null
);

$cle = ($_GET['cle'] ?? '') === 'vote' ? 'vote' : 'complet';

/* ------------------------------------------------------------
   Perimetre des elections visibles (memes regles que elections.php)
   ------------------------------------------------------------ */
$zone = '';
if ($user && in_array($role, ['gestionnaire', 'electeur'], true)) {
    $zone = zoneUtilisateur($pdo, (int)$user['user_id']);
}

if ($role === 'admin') {
    $whereE  = '';
    $paramsE = [];
} elseif ($role === 'gestionnaire') {
    $whereE  = "WHERE TRIM(COALESCE(e.zone, '')) <> ''
                  AND LOWER(TRIM(e.zone)) = LOWER(?)";
    $paramsE = [$zone];
} elseif ($role === 'electeur') {
    $whereE  = "WHERE (TRIM(COALESCE(e.zone, '')) = ''
                     OR LOWER(TRIM(e.zone)) = LOWER(?))";
    $paramsE = [$zone];
} else {
    // Controleur et visiteur anonyme : elections generales.
    $whereE  = "WHERE TRIM(COALESCE(e.zone, '')) = ''";
    $paramsE = [];
}

$empreinte = [];

/* --- Elections ------------------------------------------------- */
$stmt = $pdo->prepare(
    "SELECT COUNT(*) AS n,
            COALESCE(SUM(CASE WHEN e.statut = 'en_cours' THEN 1 ELSE 0 END), 0) AS encours,
            COALESCE(SUM(CASE WHEN e.resultats_publics THEN 1 ELSE 0 END), 0) AS publiees,
            COALESCE(MAX(e.id), 0) AS maxid,
            COALESCE(MAX(e.xmin::text::bigint), 0) AS version
     FROM elections e
     $whereE"
);
$stmt->execute($paramsE);
$empreinte['e'] = array_map('intval', $stmt->fetch());

/* --- Candidats des elections visibles ------------------------- */
$stmt = $pdo->prepare(
    "SELECT COUNT(*) AS n,
            COALESCE(MAX(c.id), 0) AS maxid,
            COALESCE(MAX(c.xmin::text::bigint), 0) AS version
     FROM candidats c
     JOIN elections e ON e.id = c.election_id
     $whereE"
);
$stmt->execute($paramsE);
$empreinte['c'] = array_map('intval', $stmt->fetch());

/* --- Votes (uniquement en mode complet) ----------------------- */
if ($cle === 'complet') {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) AS n,
                COALESCE(MAX(v.id), 0) AS maxid
         FROM votes v
         JOIN elections e ON e.id = v.election_id
         $whereE"
    );
    $stmt->execute($paramsE);
    $empreinte['v'] = array_map('intval', $stmt->fetch());
}

/* --- Comptes (admin / delegue) -------------------------------- */
if ($role === 'admin') {
    $empreinte['u'] = array_map('intval', $pdo->query(
        "SELECT COUNT(*) AS n,
                COALESCE(MAX(id), 0) AS maxid,
                COALESCE(MAX(xmin::text::bigint), 0) AS version
         FROM utilisateurs"
    )->fetch());
} elseif ($role === 'gestionnaire' && $zone !== '') {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) AS n,
                COALESCE(MAX(id), 0) AS maxid,
                COALESCE(MAX(xmin::text::bigint), 0) AS version
         FROM utilisateurs
         WHERE role = 'electeur'
           AND LOWER(TRIM(COALESCE(zone, ''))) = LOWER(?)"
    );
    $stmt->execute([$zone]);
    $empreinte['u'] = array_map('intval', $stmt->fetch());
}

/* --- Liens de classe (admin / delegue) ------------------------ */
if ($role === 'admin' || $role === 'gestionnaire') {
    if ($role === 'admin') {
        $empreinte['i'] = array_map('intval', $pdo->query(
            "SELECT COUNT(*) AS n,
                    COALESCE(MAX(id), 0) AS maxid,
                    COALESCE(MAX(xmin::text::bigint), 0) AS version
             FROM invitations"
        )->fetch());
    } else {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) AS n,
                    COALESCE(MAX(id), 0) AS maxid,
                    COALESCE(MAX(xmin::text::bigint), 0) AS version
             FROM invitations
             WHERE LOWER(TRIM(zone)) = LOWER(?)"
        );
        $stmt->execute([$zone]);
        $empreinte['i'] = array_map('intval', $stmt->fetch());
    }
}

/* --- Journal (admin / controleur) ----------------------------- */
if ($role === 'admin' || $role === 'controleur') {
    $empreinte['j'] = array_map('intval', $pdo->query(
        "SELECT COUNT(*) AS n, COALESCE(MAX(id), 0) AS maxid FROM journal"
    )->fetch());
}

$signature = substr(
    hash('sha256', json_encode($empreinte, JSON_UNESCAPED_UNICODE)),
    0,
    32
);

jsonResponse([
    'success' => true,
    'sig'     => $signature,
    'ts'      => time(),
]);
