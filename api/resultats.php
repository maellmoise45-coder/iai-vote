<?php
/**
 * ============================================================
 *  Resultats - /api/resultats.php
 * ============================================================
 *  GET ?election_id=..
 *    - si l'election a "resultats_publics" = TRUE : acces libre
 *    - sinon : reserve aux roles admin et gestionnaire
 *
 *  Les resultats ne s'affichent jamais automatiquement : c'est
 *  l'admin/gestionnaire qui les publie explicitement.
 *
 *  Les bulletins NULS (chrono ecoule) sont comptes a part.
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

// Controle d'acces
if (!$election['resultats_publics']) {
    $user = getAuthUser($pdo, false);
    if (!$user || !in_array($user['user_role'] ?? '', ['admin', 'gestionnaire'], true)) {
        jsonResponse([
            'success' => false,
            'error'   => 'Les resultats de cette election ne sont pas encore publics'
        ], 403);
    }
}

// Candidats + voix + pourcentage
$stmt = $pdo->prepare(
    "SELECT id, nom, prenom, programme, photo_url, nombre_voix
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
$inscrits   = (int)$pdo->query("SELECT COUNT(*) FROM utilisateurs WHERE role = 'electeur' AND actif = TRUE")->fetchColumn();

jsonResponse([
    'success' => true,
    'election' => [
        'id'                => (int)$election['id'],
        'titre'             => $election['titre'],
        'statut'            => $election['statut'],
        'resultats_publics' => (bool)$election['resultats_publics'],
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
