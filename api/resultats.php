<?php
/**
 * ============================================================
 *  Resultats - /api/resultats.php
 * ============================================================
 *  GET ?election_id=..[&compact=1]
 *
 *  ACCES :
 *   - resultats_publics = TRUE  -> acces libre ;
 *   - admin / gestionnaire / controleur -> acces libre ;
 *   - electeur -> uniquement pour une election EN COURS et
 *     SEULEMENT s'il a deja vote (bulletin valide ou nul).
 *     C'est ce qui permet le suivi du depouillement en direct
 *     apres le vote, sans jamais reveler un choix individuel.
 *
 *  Les resultats ne sont donc jamais visibles par un electeur
 *  AVANT son vote. Les bulletins nuls (chrono ecoule) sont
 *  comptes a part.
 *
 *  compact=1 : omet les photos et programmes (utilise pour le
 *  rafraichissement automatique toutes les 5 s). Le client
 *  complete les metadonnees via candidats.php.
 * ============================================================
 */
require_once 'config.php';

$pdo = getDB();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['error' => 'Methode non autorisee'], 405);
}

$electionId = (int)($_GET['election_id'] ?? 0);
if (!$electionId) {
    jsonResponse(['error' => 'election_id requis'], 400);
}

// Cloture automatique des elections dont la date de fin est passee,
// puis figement en NUL des bulletins dont le chrono est depasse.
cloturerElectionsEchues($pdo);
sweepVotesNuls($pdo, $electionId);

// Election
$stmt = $pdo->prepare("SELECT * FROM elections WHERE id = ?");
$stmt->execute([$electionId]);
$election = $stmt->fetch();
if (!$election) {
    jsonResponse(['error' => 'Election introuvable'], 404);
}

$compact = !empty($_GET['compact']);
$user    = getAuthUser($pdo, false);
$role    = $user['user_role'] ?? '';
$zoneE   = zoneElection($election);

/* ---------- Controle d'acces ---------- */
// Une election de classe n'existe que pour le delegue et les
// etudiants de la classe : personne d'autre ne peut voir ses resultats.
if (!accesElection($pdo, $user, $election, false)) {
    jsonResponse([
        'success' => false,
        'error'   => 'Ces resultats ne vous sont pas accessibles'
    ], 403);
}

$autorise = !empty($election['resultats_publics'])
    || in_array($role, ['admin', 'gestionnaire', 'controleur'], true);

if (!$autorise && $role === 'electeur' && $election['statut'] === 'en_cours') {
    // Verifie que cet electeur a bien depose un bulletin (valide ou nul).
    // On interroge la table votes par le hash : cela couvre aussi les
    // bulletins anterieurs a la table emargements. Le hash ne revele
    // jamais le choix du candidat.
    $stmt = $pdo->prepare("SELECT matricule FROM utilisateurs WHERE id = ?");
    $stmt->execute([(int)$user['user_id']]);
    $matricule = (string)$stmt->fetchColumn();

    if ($matricule !== '') {
        $hash = calculerHashVote($electionId, $matricule);
        $stmt = $pdo->prepare(
            "SELECT 1 FROM votes WHERE election_id = ? AND hash_vote = ? LIMIT 1"
        );
        $stmt->execute([$electionId, $hash]);
        $autorise = $stmt->fetch() !== false;
    }

    // Compatibilite : emargement present mais bulletin purge.
    if (!$autorise) {
        $stmt = $pdo->prepare(
            "SELECT 1 FROM emargements WHERE utilisateur_id = ? AND election_id = ? LIMIT 1"
        );
        $stmt->execute([(int)$user['user_id'], $electionId]);
        $autorise = $stmt->fetch() !== false;
    }
}

if (!$autorise) {
    if ($role === 'electeur') {
        jsonResponse([
            'success' => false,
            'error'   => 'Votez d\'abord pour suivre les resultats en direct de ce scrutin.'
        ], 403);
    }
    jsonResponse([
        'success' => false,
        'error'   => 'Les resultats de cette election ne sont pas encore publics'
    ], 403);
}

/* ---------- Candidats + voix + pourcentage ---------- */
$colonnes = $compact
    ? 'id, nom, prenom, nombre_voix'
    : 'id, nom, prenom, programme, photo_url, nombre_voix';

$stmt = $pdo->prepare(
    "SELECT $colonnes
     FROM candidats WHERE election_id = ? ORDER BY nombre_voix DESC, nom"
);
$stmt->execute([$electionId]);
$candidats = $stmt->fetchAll();

$totalValides = 0;
foreach ($candidats as $c) {
    $totalValides += (int)$c['nombre_voix'];
}

// Les pourcentages se calculent sur les bulletins VALIDES.
foreach ($candidats as &$c) {
    $c['nombre_voix'] = (int)$c['nombre_voix'];
    $c['pourcentage'] = $totalValides > 0 ? round($c['nombre_voix'] * 100 / $totalValides, 2) : 0.0;
}
unset($c);

// Le total de participation inclut les bulletins nuls (bulletins blancs).
$votesNuls  = (int)$pdo->query("SELECT COUNT(*) FROM votes WHERE election_id = " . (int)$electionId . " AND candidat_id IS NULL")->fetchColumn();
$totalVotes = $totalValides + $votesNuls;

// Pour une election de classe, la participation se calcule sur les
// etudiants de la classe ; pour une election generale, sur toute l'ecole.
if ($zoneE !== '') {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM utilisateurs
         WHERE role = 'electeur' AND actif = TRUE
           AND LOWER(TRIM(COALESCE(zone, ''))) = LOWER(?)"
    );
    $stmt->execute([$zoneE]);
    $inscrits = (int)$stmt->fetchColumn();
} else {
    $inscrits = (int)$pdo->query(
        "SELECT COUNT(*) FROM utilisateurs WHERE role = 'electeur' AND actif = TRUE"
    )->fetchColumn();
}

jsonResponse([
    'success' => true,
    'compact' => $compact,
    'election' => [
        'id'                => (int)$election['id'],
        'titre'             => $election['titre'],
        'statut'            => $election['statut'],
        'resultats_publics' => (bool)$election['resultats_publics'],
        'zone'              => $zoneE !== '' ? $zoneE : null,
    ],
    'total_votes'   => $totalVotes,
    'votes_valides' => $totalValides,
    'votes_nuls'    => $votesNuls,
    'inscrits'      => $inscrits,
    'participation' => $inscrits > 0 ? round($totalVotes * 100 / $inscrits, 2) : 0.0,
    'candidats'     => $candidats,
    'gagnant'       => !empty($candidats[0]['nom']) ? [
        'nom'    => $candidats[0]['nom'],
        'prenom' => $candidats[0]['prenom'],
        'voix'   => $candidats[0]['nombre_voix'],
    ] : null,
]);
