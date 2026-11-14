<?php
/**
 * ============================================================
 *  Statistiques - /api/statistiques.php
 * ============================================================
 *  GET   tableau de bord (admin / gestionnaire)
 *
 *  L'admin voit les chiffres globaux. Le gestionnaire (delegue)
 *  ne voit que les effectifs de SA classe : le nombre de votes
 *  global ne lui est pas expose.
 * ============================================================
 */
require_once 'config.php';

$pdo = getDB();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['error' => 'Methode non autorisee'], 405);
}

$user = getAuthUser($pdo);
exigerRole($user, ['admin', 'gestionnaire']);

$estAdmin = (($user['user_role'] ?? '') === 'admin');

if (!$estAdmin) {
    $zone = zoneUtilisateur($pdo, (int)$user['user_id']);

    $nbElecteurs = 0;
    $nbAuto      = 0;
    if ($zone !== '') {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM utilisateurs
             WHERE role = 'electeur' AND actif = TRUE
               AND LOWER(TRIM(COALESCE(zone, ''))) = LOWER(?)"
        );
        $stmt->execute([$zone]);
        $nbElecteurs = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM utilisateurs
             WHERE role = 'electeur' AND auto_inscrit = TRUE
               AND LOWER(TRIM(COALESCE(zone, ''))) = LOWER(?)"
        );
        $stmt->execute([$zone]);
        $nbAuto = (int)$stmt->fetchColumn();
    }

    jsonResponse([
        'success' => true,
        'stats' => [
            'role'          => 'gestionnaire',
            'zone'          => $zone,
            'electeurs'     => $nbElecteurs,
            'auto_inscrits' => $nbAuto,
            'elections'     => (int)$pdo->query("SELECT COUNT(*) FROM elections")->fetchColumn(),
            'en_cours'      => (int)$pdo->query("SELECT COUNT(*) FROM elections WHERE statut = 'en_cours'")->fetchColumn(),
            'votes'         => null, // non expose au delegue
        ],
        'dernieres_elections' => [],
    ]);
}

$nbElecteurs     = (int)$pdo->query("SELECT COUNT(*) FROM utilisateurs WHERE role = 'electeur'")->fetchColumn();
$nbGestionnaires = (int)$pdo->query("SELECT COUNT(*) FROM utilisateurs WHERE role = 'gestionnaire'")->fetchColumn();
$nbElections     = (int)$pdo->query("SELECT COUNT(*) FROM elections")->fetchColumn();
$nbEnCours       = (int)$pdo->query("SELECT COUNT(*) FROM elections WHERE statut = 'en_cours'")->fetchColumn();
$nbVotes         = (int)$pdo->query("SELECT COUNT(*) FROM votes")->fetchColumn();

$dernieres = $pdo->query(
    "SELECT id, titre, statut,
            (SELECT COUNT(*) FROM votes v WHERE v.election_id = e.id) AS nb_votes
     FROM elections e ORDER BY cree_le DESC LIMIT 5"
)->fetchAll();

jsonResponse([
    'success' => true,
    'stats' => [
        'role'          => 'admin',
        'zone'          => null,
        'electeurs'     => $nbElecteurs,
        'auto_inscrits' => (int)$pdo->query("SELECT COUNT(*) FROM utilisateurs WHERE auto_inscrit = TRUE")->fetchColumn(),
        'gestionnaires' => $nbGestionnaires,
        'elections'     => $nbElections,
        'en_cours'      => $nbEnCours,
        'votes'         => $nbVotes,
    ],
    'dernieres_elections' => $dernieres,
]);
