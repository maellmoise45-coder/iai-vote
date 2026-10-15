<?php
/**
 * ============================================================
 *  Authentification - /api/auth.php
 * ============================================================
 *  POST action=login            matricule + mot_de_passe
 *  POST action=logout           token
 *  POST action=change_password  old_password + new_password
 *  GET  action=me               profil de l'utilisateur connecte
 * ============================================================
 */
require_once 'config.php';

$method = $_SERVER['REQUEST_METHOD'];
$pdo    = getDB();

switch ($method) {
    case 'POST':
        $data   = getJsonBody();
        $action = $data['action'] ?? '';

        switch ($action) {
            case 'login':
                handleLogin($pdo, $data);
                break;
            case 'logout':
                handleLogout($pdo, $data);
                break;
            case 'change_password':
                handleChangePassword($pdo, $data);
                break;
            default:
                jsonResponse(['error' => 'Action non reconnue'], 400);
        }
        break;

    case 'GET':
        if (($_GET['action'] ?? '') === 'me') {
            $user = getAuthUser($pdo);
            $stmt = $pdo->prepare(
                "SELECT id, matricule, nom, prenom, email, role, a_vote FROM utilisateurs WHERE id = ?"
            );
            $stmt->execute([$user['user_id']]);
            jsonResponse(['success' => true, 'user' => $stmt->fetch()]);
        }
        jsonResponse(['error' => 'Methode non autorisee'], 405);
        break;

    default:
        jsonResponse(['error' => 'Methode non autorisee'], 405);
}

/**
 * Connexion par matricule + mot de passe.
 * Une seule session active par compte (connexion unique).
 */
function handleLogin($pdo, $data) {
    $matricule = trim($data['matricule'] ?? '');
    $password  = $data['mot_de_passe'] ?? ($data['password'] ?? '');

    if ($matricule === '' || $password === '') {
        jsonResponse(['error' => 'Matricule et mot de passe requis'], 400);
    }

    $stmt = $pdo->prepare("SELECT * FROM utilisateurs WHERE matricule = ?");
    $stmt->execute([$matricule]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['mot_de_passe'])) {
        // Message volontairement generique (pas d'indication sur le champ fautif)
        jsonResponse(['error' => 'Matricule ou mot de passe incorrect'], 401);
    }

    if (!$user['actif']) {
        jsonResponse(['error' => 'Compte desactive. Contactez l\'administration.'], 403);
    }

    $token = generateToken($user['id'], $user['role']);

    // Purger les sessions expirees
    $pdo->prepare("DELETE FROM sessions WHERE expire_at < NOW()")->execute();

    // === CONNEXION UNIQUE ===
    // On supprime les sessions precedentes : la nouvelle connexion
    // invalide automatiquement l'ancienne (meme sur un autre appareil).
    $pdo->prepare("DELETE FROM sessions WHERE utilisateur_id = ?")->execute([$user['id']]);

    $stmt = $pdo->prepare(
        "INSERT INTO sessions (utilisateur_id, token, expire_at)
         VALUES (?, ?, NOW() + INTERVAL '24 hours')"
    );
    $stmt->execute([$user['id'], $token]);

    journaliser($pdo, $matricule, 'connexion', ['role' => $user['role']]);

    jsonResponse([
        'success' => true,
        'token'   => $token,
        'user' => [
            'id'        => (int)$user['id'],
            'matricule' => $user['matricule'],
            'nom'       => $user['nom'],
            'prenom'    => $user['prenom'],
            'email'     => $user['email'],
            'role'      => $user['role'],
            'a_vote'    => (bool)$user['a_vote'],
        ]
    ]);
}

function handleLogout($pdo, $data) {
    $token = $data['token'] ?? '';

    if ($token === '') {
        jsonResponse(['error' => 'Token requis'], 400);
    }

    $stmt = $pdo->prepare("DELETE FROM sessions WHERE token = ?");
    $stmt->execute([$token]);

    jsonResponse(['success' => true, 'message' => 'Deconnexion reussie']);
}

function handleChangePassword($pdo, $data) {
    $user = getAuthUser($pdo);

    $ancien = $data['old_password'] ?? '';
    $nouveau = $data['new_password'] ?? '';

    if ($ancien === '' || $nouveau === '') {
        jsonResponse(['error' => 'Ancien et nouveau mot de passe requis'], 400);
    }
    if (strlen($nouveau) < 6) {
        jsonResponse(['error' => 'Le nouveau mot de passe doit contenir au moins 6 caracteres'], 400);
    }

    $stmt = $pdo->prepare("SELECT mot_de_passe, matricule FROM utilisateurs WHERE id = ?");
    $stmt->execute([$user['user_id']]);
    $row = $stmt->fetch();

    if (!$row || !password_verify($ancien, $row['mot_de_passe'])) {
        jsonResponse(['error' => 'Ancien mot de passe incorrect'], 400);
    }

    $hash = password_hash($nouveau, PASSWORD_DEFAULT);
    $pdo->prepare("UPDATE utilisateurs SET mot_de_passe = ? WHERE id = ?")
        ->execute([$hash, $user['user_id']]);

    journaliser($pdo, $row['matricule'], 'changement_mot_de_passe');

    jsonResponse(['success' => true, 'message' => 'Mot de passe modifie avec succes']);
}
