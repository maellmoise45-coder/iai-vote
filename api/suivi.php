<?php
/**
 * ============================================================
 *  Suivi du vote en direct - /api/suivi.php
 * ============================================================
 *  GET ?election_id=..[&compact=1]
 *
 *  Reserve aux roles admin et controleur.
 *
 *  compact=1 : omet les photos et programmes (le front les garde en
 *  cache et ne redemande que les compteurs toutes les 5 s).
 *
 *  Renvoie :
 *   - la liste des elections (pour le selecteur) ;
 *   - la participation globale (inscrits / votants / %) ;
 *   - le detail PAR ZONE (inscrits, votants, %) sans jamais
 *     reveler le choix d'un electeur ;
 *   - les resultats en direct par candidat (voix + %).
 *
 *  Les bulletins nuls (chrono ecoule) sont comptes a part.
 * ============================================================
 */
require_once 'config.php';

$pdo = getDB();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['error' => 'Methode non autorisee'], 405);
}

$user = getAuthUser($pdo);
exigerRole($user, ['admin', 'controleur']);

// compact=1 : omet les photos et programmes. Le front garde ces
// metadonnees en cache et ne redemande que les compteurs a chaque
// rafraichissement (5 s), ce qui evite de retelecharger plusieurs
// centaines de Ko d'images a chaque cycle.
$compact = !empty($_GET['compact']);

// Cloture automatique des scrutins echus.
cloturerElectionsEchues($pdo);

// Liste des elections pour le selecteur (en cours en premier).
$elections = $pdo->query(
    "SELECT id, titre, statut, date_debut, date_fin
     FROM elections
     ORDER BY (statut = 'en_cours') DESC, cree_le DESC"
)->fetchAll();

$electionId = (int)($_GET['election_id'] ?? 0);
if (!$electionId && $elections) {
    $electionId = (int)$elections[0]['id'];
}

if (!$electionId) {
    jsonResponse([
        'success'      => true,
        'election'     => null,
        'elections'    => [],
        'participation' => null,
        'zones'        => [],
        'candidats'    => [],
        'rafraichi_at' => date('c'),
    ]);
}

// Fige les bulletins dont le chrono est depasse.
sweepVotesNuls($pdo, $electionId);

$stmt = $pdo->prepare(
    "SELECT id, titre, description, statut, date_debut, date_fin, resultats_publics
     FROM elections WHERE id = ?"
);
$stmt->execute([$electionId]);
$election = $stmt->fetch();
if (!$election) {
    jsonResponse(['error' => 'Election introuvable'], 404);
}

/* ---------- Participation globale ---------- */
$inscrits = (int)$pdo->query(
    "SELECT COUNT(*) FROM utilisateurs WHERE role = 'electeur' AND actif = TRUE"
)->fetchColumn();

// Un bulletin = un votant (1 matricule = 1 vote par election).
$stmt = $pdo->prepare("SELECT COUNT(*) FROM votes WHERE election_id = ?");
$stmt->execute([$electionId]);
$votants = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM votes WHERE election_id = ? AND candidat_id IS NULL");
$stmt->execute([$electionId]);
$votesNuls = (int)$stmt->fetchColumn();

$votesValides = max(0, $votants - $votesNuls);

/* ---------- Detail par zone (sans le choix de vote) ---------- */
$stmt = $pdo->prepare(
    "SELECT COALESCE(NULLIF(TRIM(u.zone), ''), 'Non renseignee') AS zone,
            COUNT(*)      AS inscrits,
            COUNT(em.id)  AS votants
     FROM utilisateurs u
     LEFT JOIN emargements em
            ON em.utilisateur_id = u.id AND em.election_id = ?
     WHERE u.role = 'electeur' AND u.actif = TRUE
     GROUP BY 1
     ORDER BY 1"
);
$stmt->execute([$electionId]);
$zones = [];
foreach ($stmt->fetchAll() as $row) {
    $nbInscrits = (int)$row['inscrits'];
    $nbVotants  = (int)$row['votants'];
    $zones[] = [
        'zone'        => $row['zone'],
        'inscrits'    => $nbInscrits,
        'votants'     => $nbVotants,
        'pourcentage' => $nbInscrits > 0 ? round($nbVotants * 100 / $nbInscrits, 2) : 0.0,
    ];
}

/* ---------- Resultats en direct par candidat ---------- */
$colonnes = $compact
    ? 'id, nom, prenom, nombre_voix'
    : 'id, nom, prenom, programme, photo_url, nombre_voix';

$stmt = $pdo->prepare(
    "SELECT $colonnes
     FROM candidats WHERE election_id = ?
     ORDER BY nombre_voix DESC, nom"
);
$stmt->execute([$electionId]);
$candidats = $stmt->fetchAll();

$totalValides = 0;
foreach ($candidats as $c) {
    $totalValides += (int)$c['nombre_voix'];
}
foreach ($candidats as &$c) {
    $c['nombre_voix'] = (int)$c['nombre_voix'];
    $c['pourcentage'] = $totalValides > 0
        ? round($c['nombre_voix'] * 100 / $totalValides, 2)
        : 0.0;
}
unset($c);

jsonResponse([
    'success'  => true,
    'compact'  => $compact,
    'election' => [
        'id'                => (int)$election['id'],
        'titre'             => $election['titre'],
        'description'       => $election['description'],
        'statut'            => $election['statut'],
        'date_debut'        => $election['date_debut'],
        'date_fin'          => $election['date_fin'],
        'resultats_publics' => (bool)$election['resultats_publics'],
    ],
    'elections' => array_map(function ($e) {
        return [
            'id'         => (int)$e['id'],
            'titre'      => $e['titre'],
            'statut'     => $e['statut'],
            'date_debut' => $e['date_debut'],
            'date_fin'   => $e['date_fin'],
        ];
    }, $elections),
    'participation' => [
        'inscrits'     => $inscrits,
        'votants'      => $votants,
        'votes_valides' => $votesValides,
        'votes_nuls'   => $votesNuls,
        'pourcentage'  => $inscrits > 0 ? round($votants * 100 / $inscrits, 2) : 0.0,
    ],
    'zones'        => $zones,
    'candidats'    => $candidats,
    'rafraichi_at' => date('c'),
]);
