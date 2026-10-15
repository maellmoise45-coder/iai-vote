<?php
/**
 * ============================================================
 *  Statistiques - /api/statistiques.php
 * ============================================================
 *  GET   tableau de bord (admin/gestionnaire)
 * ============================================================
 */
require_once 'config.php';

$pdo = getDB();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['error' => 'Methode non autorisee'], 405);
}

$user = getAuthUser($pdo);
exigerRole($user, ['admin', 'gestionnaire']);

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
        'electeurs'     => $nbElecteurs,
        'gestionnaires' => $nbGestionnaires,
        'elections'     => $nbElections,
        'en_cours'      => $nbEnCours,
        'votes'         => $nbVotes,
    ],
    'dernieres_elections' => $dernieres,
]);
