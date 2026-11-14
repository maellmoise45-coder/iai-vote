<?php
/**
 * ============================================================
 *  Journal d'audit - /api/journal.php
 * ============================================================
 *  GET ?limite=100   dernieres actions (admin / controleur)
 * ============================================================
 */
require_once 'config.php';

$pdo = getDB();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['error' => 'Methode non autorisee'], 405);
}

$user = getAuthUser($pdo);
exigerRole($user, ['admin', 'controleur']);

$limite = min(500, max(1, (int)($_GET['limite'] ?? 100)));

$stmt = $pdo->query(
    "SELECT id, matricule, action, details, adresse_ip, date_action
     FROM journal ORDER BY date_action DESC LIMIT $limite"
);

jsonResponse(['success' => true, 'data' => $stmt->fetchAll()]);
