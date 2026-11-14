<?php
/**
 * ============================================================
 *  Gestion des comptes - /api/electeurs.php
 * ============================================================
 *  REGLES D'ACCES (V1.2) :
 *
 *   - admin       : cree, consulte, modifie et supprime tous les
 *                   comptes (sauf les administrateurs).
 *   - gestionnaire: DELEGUE D'UNE CLASSE. Il ne peut PLUS creer
 *                   ni modifier un profil. Il consulte uniquement
 *                   les electeurs de SA zone et peut supprimer un
 *                   profil qui ne correspond pas a sa liste.
 *
 *  GET    ?role=electeur&q=...&page=1   liste (recherche + pagination)
 *  GET    ?id=..                        un compte
 *  POST   {matricule, nom, prenom, email, mot_de_passe, role}   (admin)
 *  PUT    {id, ...}                     mise a jour partielle      (admin)
 *  DELETE ?id=..                        suppression                (admin / delegue)
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
            getUtilisateur($pdo, (int)$_GET['id'], $user);
        } else {
            getUtilisateurs($pdo, $user);
        }
        break;

    case 'POST':
        $user = getAuthUser($pdo);
        exigerRole($user, ['admin']);
        createUtilisateur($pdo, $user);
        break;

    case 'PUT':
        $user = getAuthUser($pdo);
        exigerRole($user, ['admin']);
        updateUtilisateur($pdo, $user);
        break;

    case 'DELETE':
        $user = getAuthUser($pdo);
        exigerRole($user, ['admin', 'gestionnaire']);
        deleteUtilisateur($pdo, $user);
        break;

    default:
        jsonResponse(['error' => 'Methode non autorisee'], 405);
}

function getUtilisateurs($pdo, $user) {
    $estAdmin = (($user['user_role'] ?? '') === 'admin');
    $role     = $_GET['role'] ?? 'electeur';
    $rolesValides = ['electeur', 'gestionnaire', 'controleur', 'admin'];

    if (!in_array($role, $rolesValides, true)) {
        $role = 'electeur';
    }
    // Le delegue ne gere QUE les electeurs de sa classe.
    if (!$estAdmin) {
        $role = 'electeur';
    }

    $q    = trim($_GET['q'] ?? '');
    $page = max(1, (int)($_GET['page'] ?? 1));
    $parPage = 50;
    $offset  = ($page - 1) * $parPage;

    $where  = "WHERE role = ?";
    $params = [$role];

    // Zone : imposee pour un delegue, filtre facultatif pour l'admin.
    $maZone = '';
    if (!$estAdmin) {
        $maZone = zoneUtilisateur($pdo, (int)$user['user_id']);
        if ($maZone === '') {
            jsonResponse([
                'success' => false,
                'error'   => 'Aucune classe n\'est associee a votre compte. Contactez l\'administrateur.'
            ], 403);
        }
        $where .= " AND LOWER(TRIM(COALESCE(zone, ''))) = LOWER(?)";
        $params[] = $maZone;
    } else {
        $filtreZone = normaliserZone($_GET['zone'] ?? '');
        if ($filtreZone !== '') {
            $where .= " AND LOWER(TRIM(COALESCE(zone, ''))) = LOWER(?)";
            $params[] = $filtreZone;
        }
    }

    if ($q !== '') {
        $where .= " AND (matricule ILIKE ? OR nom ILIKE ? OR prenom ILIKE ? OR email ILIKE ?)";
        $like = '%' . $q . '%';
        array_push($params, $like, $like, $like, $like);
    }

    $c = $pdo->prepare("SELECT COUNT(*) FROM utilisateurs $where");
    $c->execute($params);
    $total = (int)$c->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT id, matricule, nom, prenom, email, role, zone, a_vote,
                actif, auto_inscrit, cree_le
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
        'pages'   => (int)ceil($total / $parPage),
        'par_page' => $parPage,
        'ma_zone' => $estAdmin ? null : $maZone,
        'role'    => $role,
    ]);
}

function getUtilisateur($pdo, $id, $user) {
    $estAdmin = (($user['user_role'] ?? '') === 'admin');

    $stmt = $pdo->prepare(
        "SELECT id, matricule, nom, prenom, email, role, zone, a_vote,
                actif, auto_inscrit, cree_le
         FROM utilisateurs WHERE id = ?"
    );
    $stmt->execute([$id]);
    $u = $stmt->fetch();
    if (!$u) {
        jsonResponse(['error' => 'Compte introuvable'], 404);
    }

    if (!$estAdmin) {
        $maZone = zoneUtilisateur($pdo, (int)$user['user_id']);
        if ($u['role'] !== 'electeur'
            || $maZone === ''
            || mb_strtolower(trim((string)$u['zone'])) !== mb_strtolower($maZone)) {
            jsonResponse(['error' => 'Acces non autorise a ce compte'], 403);
        }
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
    $zone      = normaliserZone($data['zone'] ?? '');

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
    if (!in_array($role, ['electeur', 'gestionnaire', 'admin', 'controleur'], true)) {
        jsonResponse(['error' => 'Role invalide'], 400);
    }

    // Email facultatif mais valide s'il est fourni.
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(['error' => 'Adresse email invalide'], 400);
    }

    // Unicite du matricule
    $stmt = $pdo->prepare("SELECT id FROM utilisateurs WHERE matricule = ?");
    $stmt->execute([$matricule]);
    if ($stmt->fetch()) {
        jsonResponse(['error' => 'Ce matricule est deja utilise'], 409);
    }
    if ($email !== '') {
        $stmt = $pdo->prepare("SELECT id FROM utilisateurs WHERE LOWER(email) = LOWER(?)");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            jsonResponse(['error' => 'Cette adresse email est deja utilisee'], 409);
        }
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);

    $stmt = $pdo->prepare(
        "INSERT INTO utilisateurs (matricule, nom, prenom, email, mot_de_passe, role, zone)
         VALUES (?, ?, ?, ?, ?, ?, ?) RETURNING id"
    );
    $stmt->execute([$matricule, $nom, $prenom, $email ?: null, $hash, $role, $zone ?: null]);
    $id = (int)$stmt->fetchColumn();

    journaliser($pdo, 'user:' . $user['user_id'], 'creation_compte', [
        'cible' => $matricule, 'role' => $role, 'zone' => $zone ?: null
    ]);

    jsonResponse(['success' => true, 'message' => 'Compte cree avec succes', 'id' => $id], 201);
}

function updateUtilisateur($pdo, $user) {
    $data = getJsonBody();
    $id   = (int)($data['id'] ?? 0);

    if (!$id) {
        jsonResponse(['error' => 'Identifiant du compte requis'], 400);
    }

    $stmt = $pdo->prepare("SELECT role, matricule FROM utilisateurs WHERE id = ?");
    $stmt->execute([$id]);
    $cible = $stmt->fetch();
    if (!$cible) {
        jsonResponse(['error' => 'Compte introuvable'], 404);
    }

    // Email facultatif mais valide s'il est fourni.
    if (array_key_exists('email', $data) && trim((string)$data['email']) !== ''
        && !filter_var(trim((string)$data['email']), FILTER_VALIDATE_EMAIL)) {
        jsonResponse(['error' => 'Adresse email invalide'], 400);
    }

    // Zone : on normalise avant l'ecriture.
    if (array_key_exists('zone', $data)) {
        $data['zone'] = normaliserZone($data['zone']);
    }

    // Desactivation : on normalise la valeur une seule fois.
    $desactivation = array_key_exists('actif', $data)
        && filter_var($data['actif'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === false;

    // Un administrateur ne doit pas se bloquer lui-meme.
    if ($desactivation && $id === (int)$user['user_id']) {
        jsonResponse(['error' => 'Vous ne pouvez pas desactiver votre propre compte'], 400);
    }

    // Mot de passe : reinitialisation eventuelle
    if (!empty($data['mot_de_passe'])) {
        if (strlen($data['mot_de_passe']) < 6) {
            jsonResponse(['error' => 'Le mot de passe doit contenir au moins 6 caracteres'], 400);
        }
        $pdo->prepare("UPDATE utilisateurs SET mot_de_passe = ? WHERE id = ?")
            ->execute([password_hash($data['mot_de_passe'], PASSWORD_DEFAULT), $id]);

        // Un mot de passe reinitialise invalide les sessions en cours du compte.
        if ($id !== (int)$user['user_id']) {
            $pdo->prepare("DELETE FROM sessions WHERE utilisateur_id = ?")->execute([$id]);
        }
    }

    miseAJourPartielle($pdo, 'utilisateurs', $data, [
        'nom'    => 'nom',
        'prenom' => 'prenom',
        'email'  => 'nullable',
        'zone'   => 'nullable',
        'actif'  => 'bool',
    ], $id);

    // Le compte bloque perd immediatement toutes ses sessions actives.
    if ($desactivation) {
        $pdo->prepare("DELETE FROM sessions WHERE utilisateur_id = ?")->execute([$id]);
        journaliser($pdo, 'user:' . $user['user_id'], 'compte_bloque', [
            'cible' => $cible['matricule']
        ]);
    }

    journaliser($pdo, 'user:' . $user['user_id'], 'modification_compte', [
        'cible' => $cible['matricule']
    ]);

    jsonResponse(['success' => true, 'message' => 'Compte mis a jour']);
}

/**
 * Suppression d'un compte.
 *
 *  - admin       : peut supprimer tout compte sauf un administrateur.
 *  - gestionnaire: uniquement un ELECTEUR de SA zone, et seulement
 *                  s'il n'a pas deja participe a un scrutin en cours
 *                  (pour ne pas fausser les statistiques en direct).
 */
function deleteUtilisateur($pdo, $user) {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) {
        jsonResponse(['error' => 'Identifiant requis'], 400);
    }

    $stmt = $pdo->prepare("SELECT id, matricule, role, zone, a_vote FROM utilisateurs WHERE id = ?");
    $stmt->execute([$id]);
    $cible = $stmt->fetch();
    if (!$cible) {
        jsonResponse(['error' => 'Compte introuvable'], 404);
    }

    $estAdmin = (($user['user_role'] ?? '') === 'admin');

    if ($cible['role'] === 'admin') {
        jsonResponse(['error' => 'Impossible de supprimer un administrateur'], 403);
    }

    if (!$estAdmin) {
        $maZone = zoneUtilisateur($pdo, (int)$user['user_id']);
        if ($maZone === '') {
            jsonResponse([
                'error' => 'Aucune classe n\'est associee a votre compte. Contactez l\'administrateur.'
            ], 403);
        }
        if ($cible['role'] !== 'electeur') {
            jsonResponse(['error' => 'Vous ne pouvez supprimer que des electeurs'], 403);
        }
        if (mb_strtolower(trim((string)$cible['zone'])) !== mb_strtolower($maZone)) {
            jsonResponse(['error' => 'Ce compte n\'appartient pas a votre classe'], 403);
        }

        // Un electeur qui participe a un scrutin en cours ne peut plus
        // etre retire : cela fausserait la participation en direct.
        // On croise les emargements ET les bulletins (hash anonyme), car
        // d'anciens votes peuvent ne pas avoir d'emargement.
        $stmt = $pdo->prepare(
            "SELECT 1
             FROM emargements em
             JOIN elections e ON e.id = em.election_id
             WHERE em.utilisateur_id = ? AND e.statut = 'en_cours'
             LIMIT 1"
        );
        $stmt->execute([$id]);
        $aVote = $stmt->fetch() !== false;

        if (!$aVote && $cible['matricule'] !== '') {
            $scrutins = $pdo->query(
                "SELECT id FROM elections WHERE statut = 'en_cours'"
            )->fetchAll(PDO::FETCH_COLUMN);

            $verif = $pdo->prepare(
                "SELECT 1 FROM votes WHERE election_id = ? AND hash_vote = ? LIMIT 1"
            );
            foreach ($scrutins as $electionId) {
                $hash = calculerHashVote((int)$electionId, $cible['matricule']);
                $verif->execute([(int)$electionId, $hash]);
                if ($verif->fetch() !== false) {
                    $aVote = true;
                    break;
                }
            }
        }

        if ($aVote) {
            jsonResponse([
                'error' => 'Cet electeur a deja vote a un scrutin en cours : suppression impossible.'
            ], 409);
        }
    }

    $pdo->prepare("DELETE FROM utilisateurs WHERE id = ?")->execute([$id]);

    journaliser($pdo, 'user:' . $user['user_id'], 'suppression_compte', [
        'cible'   => $cible['matricule'],
        'role'    => $cible['role'],
        'zone'    => $cible['zone'],
        'delegue' => !$estAdmin,
    ]);

    jsonResponse(['success' => true, 'message' => 'Compte supprime']);
}
