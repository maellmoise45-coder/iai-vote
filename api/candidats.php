<?php
/**
 * ============================================================
 *  Candidats - /api/candidats.php
 * ============================================================
 *  POST   {election_id, nom, prenom, programme, photo_url}
 *  PUT    {id, ...}
 *  DELETE ?id=..
 *  GET    ?election_id=..   liste des candidats d'une election
 * ============================================================
 */
require_once 'config.php';

$method = $_SERVER['REQUEST_METHOD'];
$pdo    = getDB();

switch ($method) {
    case 'GET':
        getCandidats($pdo);
        break;

    case 'POST':
        $user = getAuthUser($pdo);
        exigerRole($user, ['admin']);
        createCandidat($pdo, $user);
        break;

    case 'PUT':
        $user = getAuthUser($pdo);
        exigerRole($user, ['admin']);
        updateCandidat($pdo, $user);
        break;

    case 'DELETE':
        $user = getAuthUser($pdo);
        exigerRole($user, ['admin']);
        deleteCandidat($pdo, $user);
        break;

    default:
        jsonResponse(['error' => 'Methode non autorisee'], 405);
}

function getCandidats($pdo) {
    $electionId = (int)($_GET['election_id'] ?? 0);
    if (!$electionId) {
        jsonResponse(['error' => 'election_id requis'], 400);
    }
    $stmt = $pdo->prepare(
        "SELECT id, election_id, nom, prenom, programme, photo_url, cree_le
         FROM candidats WHERE election_id = ? ORDER BY id"
    );
    $stmt->execute([$electionId]);
    jsonResponse(['success' => true, 'data' => $stmt->fetchAll()]);
}

function createCandidat($pdo, $user) {
    $data = getJsonBody();

    $electionId = (int)($data['election_id'] ?? 0);
    $nom        = trim($data['nom'] ?? '');
    $prenom     = trim($data['prenom'] ?? '');

    if (!$electionId || $nom === '' || $prenom === '') {
        jsonResponse(['error' => 'election_id, nom et prenom sont obligatoires'], 400);
    }

    // L'election doit exister
    $stmt = $pdo->prepare("SELECT id FROM elections WHERE id = ?");
    $stmt->execute([$electionId]);
    if (!$stmt->fetch()) {
        jsonResponse(['error' => 'Election introuvable'], 404);
    }

    // Photo : data URL d'image ou URL http(s) uniquement.
    $photo = validerPhoto($data['photo_url'] ?? '');
    if ($photo === null && trim((string)($data['photo_url'] ?? '')) !== '') {
        jsonResponse(['error' => 'Photo invalide : une image est attendue'], 400);
    }

    $stmt = $pdo->prepare(
        "INSERT INTO candidats (election_id, nom, prenom, programme, photo_url)
         VALUES (?, ?, ?, ?, ?) RETURNING id"
    );
    $stmt->execute([
        $electionId,
        $nom,
        $prenom,
        trim($data['programme'] ?? '') ?: null,
        $photo,
    ]);

    jsonResponse(['success' => true, 'message' => 'Candidat ajoute', 'id' => (int)$stmt->fetchColumn()], 201);
}

function updateCandidat($pdo, $user) {
    $data = getJsonBody();
    $id   = (int)($data['id'] ?? 0);

    if (!$id) {
        jsonResponse(['error' => 'ID du candidat requis'], 400);
    }

    miseAJourPartielle($pdo, 'candidats', $data, [
        'nom'       => 'nom',
        'prenom'    => 'prenom',
        'programme' => 'nullable',
        'photo_url' => 'nullable',
    ], $id, [
        'photo_url' => function ($valeur) {
            $brut = trim((string)$valeur);
            if ($brut === '') {
                return ['erreur' => null, 'valeur' => null];
            }
            $photo = validerPhoto($brut);
            if ($photo === null) {
                return ['erreur' => 'Photo invalide : une image est attendue', 'valeur' => null];
            }
            return ['erreur' => null, 'valeur' => $photo];
        },
    ]);

    jsonResponse(['success' => true, 'message' => 'Candidat mis a jour']);
}

function deleteCandidat($pdo, $user) {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) {
        jsonResponse(['error' => 'ID du candidat requis'], 400);
    }
    $pdo->prepare("DELETE FROM candidats WHERE id = ?")->execute([$id]);
    jsonResponse(['success' => true, 'message' => 'Candidat supprime']);
}
