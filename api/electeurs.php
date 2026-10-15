<?php
/**
 * ============================================================
 *  Gestion des comptes - /api/electeurs.php
 * ============================================================
 *  Reserve aux roles admin et gestionnaire.
 *
 *  GET    ?role=electeur&q=...&page=1   liste (recherche + pagination)
 *  GET    ?id=..                        un compte
 *  POST   {matricule, nom, prenom, email, mot_de_passe, role}
 *  PUT    {id, ...}                     mise a jour partielle
 *  DELETE ?id=..                        suppression
 * ============================================================
 */
require_once 'config.php';

$method = $_SERVER['REQUEST_METHOD'];
$pdo    = getDB();

switch ($method) {
    case 'GET':
        $user = getAuthUser($pdo);
        exigerRole($user, ['admin', 'gestionnaire']);
        if (isset($_GET['id'])) {
            getUtilisateur($pdo, (int)$_GET['id']);
        } else {
            getUtilisateurs($pdo);
        }
        break;

    case 'POST':
        $user = getAuthUser($pdo);
        exigerRole($user, ['admin', 'gestionnaire']);
        createUtilisateur($pdo, $user);
        break;

    case 'PUT':
        $user = getAuthUser($pdo);
        exigerRole($user, ['admin', 'gestionnaire']);
        updateUtilisateur($pdo, $user);
        break;

    case 'DELETE':
        $user = getAuthUser($pdo);
        exigerRole($user, ['admin']);
        deleteUtilisateur($pdo);
        break;

    default:
        jsonResponse(['error' => 'Methode non autorisee'], 405);
}

function getUtilisateurs($pdo) {
    $role = $_GET['role'] ?? 'electeur';
    $q    = trim($_GET['q'] ?? '');
    $page = max(1, (int)($_GET['page'] ?? 1));
    $parPage = 20;
    $offset  = ($page - 1) * $parPage;

    $where  = "WHERE role = ?";
    $params = [$role];

    if ($q !== '') {
        $where .= " AND (matricule ILIKE ? OR nom ILIKE ? OR prenom ILIKE ? OR email ILIKE ?)";
        $like = '%' . $q . '%';
        array_push($params, $like, $like, $like, $like);
    }

    $c = $pdo->prepare("SELECT COUNT(*) FROM utilisateurs $where");
    $c->execute($params);
    $total = (int)$c->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT id, matricule, nom, prenom, email, role, a_vote, actif, cree_le
         FROM utilisateurs $where
         ORDER BY nom, prenom
         LIMIT $parPage OFFSET $offset"
    );
    $stmt->execute($params);
    $data = $stmt->fetchAll();

    jsonResponse([
        'success' => true,
        'data'    => $data,
        'total'   => $total,
        'page'    => $page,
        'pages'   => (int)ceil($total / $parPage)
    ]);
}

function getUtilisateur($pdo, $id) {
    $stmt = $pdo->prepare(
        "SELECT id, matricule, nom, prenom, email, role, a_vote, actif, cree_le
         FROM utilisateurs WHERE id = ?"
    );
    $stmt->execute([$id]);
    $u = $stmt->fetch();
    if (!$u) {
        jsonResponse(['error' => 'Compte introuvable'], 404);
    }
    jsonResponse(['success' => true, 'data' => $u]);
}

function createUtilisateur($pdo, $user) {
    $data = getJsonBody();

    $matricule = strtoupper(trim($data['matricule'] ?? ''));
    $nom       = trim($data['nom'] ?? '');
    $prenom    = trim($data['prenom'] ?? '');
    $email     = trim($data['email'] ?? '');
    $password  = $data['mot_de_passe'] ?? '';
    $role      = $data['role'] ?? 'electeur';

    if ($matricule === '' || $nom === '' || $prenom === '' || $password === '') {
        jsonResponse(['error' => 'Matricule, nom, prenom et mot de passe sont obligatoires'], 400);
    }
    if (strlen($password) < 6) {
        jsonResponse(['error' => 'Le mot de passe doit contenir au moins 6 caracteres'], 400);
    }
    if (!in_array($role, ['electeur', 'gestionnaire', 'admin'], true)) {
        jsonResponse(['error' => 'Role invalide'], 400);
    }
    // Seul un admin peut creer un gestionnaire ou un admin
    if (in_array($role, ['admin', 'gestionnaire'], true) && $user['user_role'] !== 'admin') {
        jsonResponse(['error' => 'Seul un administrateur peut creer ce type de compte'], 403);
    }

    // Unicite du matricule
    $stmt = $pdo->prepare("SELECT id FROM utilisateurs WHERE matricule = ?");
    $stmt->execute([$matricule]);
    if ($stmt->fetch()) {
        jsonResponse(['error' => 'Ce matricule est deja utilise'], 409);
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);

    $stmt = $pdo->prepare(
        "INSERT INTO utilisateurs (matricule, nom, prenom, email, mot_de_passe, role)
         VALUES (?, ?, ?, ?, ?, ?) RETURNING id"
    );
    $stmt->execute([$matricule, $nom, $prenom, $email ?: null, $hash, $role]);
    $id = (int)$stmt->fetchColumn();

    journaliser($pdo, $user['user_role'] . ':' . ($user['user_id'] ?? ''), 'creation_compte', [
        'cible' => $matricule, 'role' => $role
    ]);

    jsonResponse(['success' => true, 'message' => 'Compte cree avec succes', 'id' => $id], 201);
}

function updateUtilisateur($pdo, $user) {
    $data = getJsonBody();
    $id   = (int)($data['id'] ?? 0);

    if (!$id) {
        jsonResponse(['error' => 'Identifiant du compte requis'], 400);
    }

    // Un gestionnaire ne peut pas modifier un admin
    $stmt = $pdo->prepare("SELECT role, matricule FROM utilisateurs WHERE id = ?");
    $stmt->execute([$id]);
    $cible = $stmt->fetch();
    if (!$cible) {
        jsonResponse(['error' => 'Compte introuvable'], 404);
    }
    if ($cible['role'] === 'admin' && $user['user_role'] !== 'admin') {
        jsonResponse(['error' => 'Modification d\'un administrateur interdite'], 403);
    }

    // Mot de passe : reinitialisation eventuelle
    if (!empty($data['mot_de_passe'])) {
        if (strlen($data['mot_de_passe']) < 6) {
            jsonResponse(['error' => 'Le mot de passe doit contenir au moins 6 caracteres'], 400);
        }
        $pdo->prepare("UPDATE utilisateurs SET mot_de_passe = ? WHERE id = ?")
            ->execute([password_hash($data['mot_de_passe'], PASSWORD_DEFAULT), $id]);
    }

    miseAJourPartielle($pdo, 'utilisateurs', $data, [
        'nom'    => 'nom',
        'prenom' => 'prenom',
        'email'  => 'nullable',
        'actif'  => 'bool',
    ], $id);

    journaliser($pdo, $user['user_role'] . ':' . ($user['user_id'] ?? ''), 'modification_compte', [
        'cible' => $cible['matricule']
    ]);

    jsonResponse(['success' => true, 'message' => 'Compte mis a jour']);
}

function deleteUtilisateur($pdo) {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) {
        jsonResponse(['error' => 'Identifiant requis'], 400);
    }

    $stmt = $pdo->prepare("SELECT matricule, role FROM utilisateurs WHERE id = ?");
    $stmt->execute([$id]);
    $cible = $stmt->fetch();
    if (!$cible) {
        jsonResponse(['error' => 'Compte introuvable'], 404);
    }
    if ($cible['role'] === 'admin') {
        jsonResponse(['error' => 'Impossible de supprimer un administrateur'], 403);
    }

    $pdo->prepare("DELETE FROM utilisateurs WHERE id = ?")->execute([$id]);

    jsonResponse(['success' => true, 'message' => 'Compte supprime']);
}
