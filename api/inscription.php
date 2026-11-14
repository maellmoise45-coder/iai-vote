<?php
/**
 * ============================================================
 *  Inscription par lien de classe - /api/inscription.php
 * ============================================================
 *  ENDPOINT PUBLIC (aucune connexion requise).
 *
 *  GET  ?token=..   verifie le lien et renvoie la classe imposee
 *  POST {token, matricule, nom, prenom, email, mot_de_passe}
 *                   cree le compte electeur dans la classe du lien
 *
 *  La zone n'est JAMAIS lue depuis le formulaire : elle vient
 *  exclusivement de l'invitation. Un etudiant ne peut donc pas
 *  s'inscrire dans une autre classe que celle de son delegue.
 *
 *  Les comptes crees par ce chemin sont marques auto_inscrit :
 *  le delegue les retrouve dans sa liste et peut supprimer ceux
 *  qui ne correspondent pas a sa classe.
 * ============================================================
 */
require_once 'config.php';

$method = $_SERVER['REQUEST_METHOD'];
$pdo    = getDB();

switch ($method) {
    case 'GET':
        verifierLien($pdo);
        break;

    case 'POST':
        inscrire($pdo);
        break;

    default:
        jsonResponse(['error' => 'Methode non autorisee'], 405);
}

/* ------------------------------------------------------------
   Verification du lien (avant affichage du formulaire)
   ------------------------------------------------------------ */
function verifierLien($pdo) {
    $token = trim((string)($_GET['token'] ?? ''));

    if (tropDeTokensInvalides($pdo)) {
        header('Retry-After: 900');
        jsonResponse([
            'error' => 'Trop de tentatives. Reessayez dans 15 minutes.'
        ], 429);
    }

    $invitation = invitationValide($pdo, $token);

    if (!$invitation) {
        if ($token !== '') {
            journaliser($pdo, null, 'inscription_token_invalide');
        }
        jsonResponse([
            'success' => false,
            'error'   => 'Ce lien d\'inscription est invalide, revoque ou expire. Demandez un nouveau lien a votre delegue.'
        ], 404);
    }

    jsonResponse([
        'success' => true,
        'data'    => [
            'zone'      => $invitation['zone'],
            'expire_at' => $invitation['expire_at'],
            'delegue'   => trim((string)($invitation['delegue_prenom'] ?? '') . ' '
                               . (string)($invitation['delegue_nom'] ?? '')) ?: null,
        ],
    ]);
}

/* ------------------------------------------------------------
   Inscription
   ------------------------------------------------------------ */
function inscrire($pdo) {
    $data = getJsonBody();
    $token = trim((string)($data['token'] ?? ''));

    if (tropDeTokensInvalides($pdo)) {
        header('Retry-After: 900');
        jsonResponse([
            'error' => 'Trop de tentatives. Reessayez dans 15 minutes.'
        ], 429);
    }

    $invitation = invitationValide($pdo, $token);
    if (!$invitation) {
        journaliser($pdo, null, 'inscription_token_invalide');
        jsonResponse([
            'error' => 'Ce lien d\'inscription est invalide, revoque ou expire.'
        ], 404);
    }

    $matricule = strtoupper(trim((string)($data['matricule'] ?? '')));
    $nom       = trim((string)($data['nom'] ?? ''));
    $prenom    = trim((string)($data['prenom'] ?? ''));
    $email     = trim((string)($data['email'] ?? ''));
    $password  = (string)($data['mot_de_passe'] ?? '');

    if ($matricule === '' || $nom === '' || $prenom === '' || $password === '') {
        jsonResponse(['error' => 'Matricule, nom, prenom et mot de passe sont obligatoires'], 400);
    }
    if (!preg_match('/^[A-Z0-9][A-Z0-9._-]{2,49}$/', $matricule)) {
        jsonResponse(['error' => 'Matricule invalide (3 a 50 caracteres : lettres, chiffres, . _ -)'], 400);
    }
    if (mb_strlen($nom) > 100 || mb_strlen($prenom) > 100) {
        jsonResponse(['error' => 'Nom ou prenom trop long (100 caracteres maximum)'], 400);
    }
    if (strlen($password) < 6) {
        jsonResponse(['error' => 'Le mot de passe doit contenir au moins 6 caracteres'], 400);
    }
    if (strlen($password) > 200) {
        jsonResponse(['error' => 'Mot de passe trop long (200 caracteres maximum)'], 400);
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(['error' => 'Adresse email invalide'], 400);
    }
    if (mb_strlen($email) > 150) {
        jsonResponse(['error' => 'Adresse email trop longue (150 caracteres maximum)'], 400);
    }

    // Unicite du matricule
    $stmt = $pdo->prepare("SELECT id FROM utilisateurs WHERE matricule = ?");
    $stmt->execute([$matricule]);
    if ($stmt->fetch()) {
        jsonResponse(['error' => 'Ce matricule est deja inscrit. Connectez-vous ou contactez votre delegue.'], 409);
    }

    // Unicite de l'email (insensible a la casse)
    if ($email !== '') {
        $stmt = $pdo->prepare("SELECT id FROM utilisateurs WHERE LOWER(email) = LOWER(?)");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            jsonResponse(['error' => 'Cette adresse email est deja utilisee'], 409);
        }
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);

    try {
        $stmt = $pdo->prepare(
            "INSERT INTO utilisateurs
                (matricule, nom, prenom, email, mot_de_passe, role, zone, auto_inscrit, actif)
             VALUES (?, ?, ?, ?, ?, 'electeur', ?, TRUE, TRUE)
             RETURNING id"
        );
        $stmt->execute([
            $matricule, $nom, $prenom, $email ?: null, $hash, $invitation['zone']
        ]);
        $id = (int)$stmt->fetchColumn();
    } catch (PDOException $e) {
        if ($e->getCode() === '23505') {
            jsonResponse(['error' => 'Ce matricule ou cette adresse email est deja utilise'], 409);
        }
        throw $e;
    }

    journaliser($pdo, $matricule, 'inscription_lien', [
        'zone'  => $invitation['zone'],
        'lien'  => substr($invitation['token'], 0, 8) . '...',
    ]);

    jsonResponse([
        'success'   => true,
        'message'   => 'Inscription enregistree. Vous pouvez maintenant vous connecter.',
        'id'        => $id,
        'matricule' => $matricule,
        'zone'      => $invitation['zone'],
    ], 201);
}

/* ------------------------------------------------------------
   Helpers
   ------------------------------------------------------------ */
function invitationValide($pdo, $token) {
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    $stmt = $pdo->prepare(
        "SELECT i.id, i.token, i.zone, i.expire_at,
                u.prenom AS delegue_prenom, u.nom AS delegue_nom
         FROM invitations i
         LEFT JOIN utilisateurs u ON u.id = i.cree_par
         WHERE i.token = ?
           AND i.actif = TRUE
           AND (i.expire_at IS NULL OR i.expire_at > NOW())
         LIMIT 1"
    );
    $stmt->execute([$token]);
    $invitation = $stmt->fetch();
    return $invitation ?: null;
}

/**
 * Limite les tentatives avec un token invalide : 20 par IP et par
 * quart d'heure. Les inscriptions reussies ne sont pas limitees.
 */
function tropDeTokensInvalides($pdo) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if ($ip === '') {
        return false;
    }
    try {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM journal
             WHERE action = 'inscription_token_invalide'
               AND adresse_ip = ?
               AND date_action > NOW() - INTERVAL '15 minutes'"
        );
        $stmt->execute([$ip]);
        return ((int)$stmt->fetchColumn()) >= 20;
    } catch (Exception $e) {
        error_log('[DigitalElect] throttle inscription: ' . $e->getMessage());
        return false;
    }
}
