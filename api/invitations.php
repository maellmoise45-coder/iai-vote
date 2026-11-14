<?php
/**
 * ============================================================
 *  Invitations de classe - /api/invitations.php
 * ============================================================
 *  Chaque delegue (gestionnaire) genere UN lien pour SA classe.
 *  L'etudiant qui ouvre le lien s'inscrit lui-meme : la zone est
 *  imposee par le lien et ne peut pas etre choisie.
 *
 *  GET              liste des invitations (+ zones existantes)
 *  POST   {zone?}   creer un lien (le nouveau remplace l'ancien)
 *  PUT    {id}      regenerer le token d'une invitation
 *  DELETE ?id=..    revoquer une invitation
 *
 *  Un gestionnaire ne voit et ne gere que la zone de son compte.
 *  Un admin peut gerer toutes les zones existantes.
 * ============================================================
 */
require_once 'config.php';

$method = $_SERVER['REQUEST_METHOD'];
$pdo    = getDB();

$user = getAuthUser($pdo);
exigerRole($user, ['admin', 'gestionnaire']);

$estAdmin = (($user['user_role'] ?? '') === 'admin');
$maZone   = $estAdmin ? '' : zoneUtilisateur($pdo, (int)$user['user_id']);

switch ($method) {
    case 'GET':
        listerInvitations($pdo, $estAdmin, $maZone);
        break;

    case 'POST':
        creerInvitation($pdo, $user, $estAdmin, $maZone);
        break;

    case 'PUT':
        regenererInvitation($pdo, $user, $estAdmin, $maZone);
        break;

    case 'DELETE':
        revoquerInvitation($pdo, $user, $estAdmin, $maZone);
        break;

    default:
        jsonResponse(['error' => 'Methode non autorisee'], 405);
}

/* ------------------------------------------------------------
   Lecture
   ------------------------------------------------------------ */
function listerInvitations($pdo, $estAdmin, $maZone) {
    $params = [];
    $where  = '';

    if (!$estAdmin) {
        if ($maZone === '') {
            // Aucune classe associee : liste vide, l'interface explique
            // quoi faire. Ce n'est pas une erreur technique.
            jsonResponse([
                'success'     => true,
                'ma_zone'     => '',
                'zones'       => [],
                'invitations' => [],
            ]);
        }
        $where  = "WHERE LOWER(TRIM(i.zone)) = LOWER(?)";
        $params = [$maZone];
    }

    $stmt = $pdo->prepare(
        "SELECT i.id, i.token, i.zone, i.actif, i.expire_at, i.cree_le,
                u.matricule AS cree_par_matricule,
                u.prenom || ' ' || u.nom AS cree_par_nom
         FROM invitations i
         LEFT JOIN utilisateurs u ON u.id = i.cree_par
         $where
         ORDER BY i.cree_le DESC"
    );
    $stmt->execute($params);
    $invitations = $stmt->fetchAll();

    // Effectifs par zone (inscrits + comptes crees via un lien).
    $effectifs = [];
    $rows = $pdo->query(
        "SELECT LOWER(TRIM(zone)) AS z,
                COUNT(*) AS inscrits,
                COUNT(*) FILTER (WHERE auto_inscrit) AS auto_inscrits
         FROM utilisateurs
         WHERE role = 'electeur' AND zone IS NOT NULL AND TRIM(zone) <> ''
         GROUP BY 1"
    )->fetchAll();
    foreach ($rows as $r) {
        $effectifs[$r['z']] = [
            'inscrits'      => (int)$r['inscrits'],
            'auto_inscrits' => (int)$r['auto_inscrits'],
        ];
    }

    $data = [];
    foreach ($invitations as $i) {
        $cle = mb_strtolower(trim((string)$i['zone']));
        $data[] = [
            'id'                => (int)$i['id'],
            'token'             => $i['token'],
            'lien'              => 'inscription.html?token=' . $i['token'],
            'zone'              => $i['zone'],
            'actif'             => (bool)$i['actif'],
            'expire_at'         => $i['expire_at'],
            'cree_le'           => $i['cree_le'],
            'cree_par_matricule' => $i['cree_par_matricule'],
            'cree_par_nom'      => $i['cree_par_nom'],
            'inscrits'          => $effectifs[$cle]['inscrits'] ?? 0,
            'auto_inscrits'     => $effectifs[$cle]['auto_inscrits'] ?? 0,
        ];
    }

    // Zones existantes (pour le selecteur de l'admin).
    if ($estAdmin) {
        $zones = $pdo->query(
            "SELECT DISTINCT zone FROM utilisateurs
             WHERE zone IS NOT NULL AND TRIM(zone) <> ''
             ORDER BY zone"
        )->fetchAll(PDO::FETCH_COLUMN);
    } else {
        $zones = [$maZone];
    }

    jsonResponse([
        'success'     => true,
        'ma_zone'     => $estAdmin ? null : $maZone,
        'zones'       => $zones,
        'invitations' => $data,
    ]);
}

/* ------------------------------------------------------------
   Creation (un nouveau lien remplace l'ancien de la meme zone)
   ------------------------------------------------------------ */
function creerInvitation($pdo, $user, $estAdmin, $maZone) {
    $data = getJsonBody();
    $zoneDemandee = normaliserZone($data['zone'] ?? '');

    if (!$estAdmin) {
        if ($maZone === '') {
            jsonResponse([
                'error' => 'Aucune classe n\'est associee a votre compte. Contactez l\'administrateur.'
            ], 403);
        }
        if ($zoneDemandee !== ''
            && mb_strtolower($zoneDemandee) !== mb_strtolower($maZone)) {
            jsonResponse(['error' => 'Vous ne pouvez creer un lien que pour votre classe'], 403);
        }
        $zone = $maZone;
    } else {
        if ($zoneDemandee === '') {
            jsonResponse(['error' => 'Choisissez une classe pour ce lien'], 400);
        }
        $zone = zoneCanonique($pdo, $zoneDemandee);
        if ($zone === null) {
            jsonResponse([
                'error' => 'Cette classe n\'existe pas encore. Creez d\'abord un compte (delegue ou electeur) dans cette zone.'
            ], 400);
        }
    }

    $token = bin2hex(random_bytes(32));

    $pdo->beginTransaction();
    try {
        // Un seul lien actif par classe : le nouveau remplace l'ancien.
        $pdo->prepare(
            "UPDATE invitations
             SET actif = FALSE
             WHERE actif = TRUE AND LOWER(TRIM(zone)) = LOWER(?)"
        )->execute([$zone]);

        $stmt = $pdo->prepare(
            "INSERT INTO invitations (token, zone, cree_par, expire_at)
             VALUES (?, ?, ?, NOW() + INTERVAL '90 days')
             RETURNING id, token, zone, actif, expire_at, cree_le"
        );
        $stmt->execute([$token, $zone, (int)$user['user_id']]);
        $invitation = $stmt->fetch();
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    journaliser($pdo, 'user:' . $user['user_id'], 'creation_invitation', [
        'zone' => $zone,
    ]);

    jsonResponse([
        'success'    => true,
        'message'    => 'Lien d\'inscription cree. L\'ancien lien de cette classe est desactive.',
        'invitation' => [
            'id'                => (int)$invitation['id'],
            'token'             => $invitation['token'],
            'lien'              => 'inscription.html?token=' . $invitation['token'],
            'zone'              => $invitation['zone'],
            'actif'             => (bool)$invitation['actif'],
            'expire_at'         => $invitation['expire_at'],
            'cree_le'           => $invitation['cree_le'],
            'cree_par_matricule' => null,
            'cree_par_nom'      => null,
            'inscrits'          => 0,
            'auto_inscrits'     => 0,
        ],
    ], 201);
}

/* ------------------------------------------------------------
   Regeneration du token
   ------------------------------------------------------------ */
function regenererInvitation($pdo, $user, $estAdmin, $maZone) {
    $data = getJsonBody();
    $id   = (int)($data['id'] ?? 0);
    if (!$id) {
        jsonResponse(['error' => 'Identifiant de l\'invitation requis'], 400);
    }

    $invitation = chargerInvitationAutorisee($pdo, $id, $estAdmin, $maZone);
    $token = bin2hex(random_bytes(32));

    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            "UPDATE invitations
             SET actif = FALSE
             WHERE actif = TRUE
               AND id <> ?
               AND LOWER(TRIM(zone)) = LOWER(?)"
        )->execute([$id, $invitation['zone']]);

        $stmt = $pdo->prepare(
            "UPDATE invitations
             SET token = ?, actif = TRUE, expire_at = NOW() + INTERVAL '90 days', cree_le = NOW()
             WHERE id = ?
             RETURNING id, token, zone, actif, expire_at, cree_le"
        );
        $stmt->execute([$token, $id]);
        $nouvelle = $stmt->fetch();
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    journaliser($pdo, 'user:' . $user['user_id'], 'regeneration_invitation', [
        'zone' => $invitation['zone'],
    ]);

    jsonResponse([
        'success'    => true,
        'message'    => 'Nouveau lien genere. L\'ancien lien ne fonctionne plus.',
        'invitation' => [
            'id'        => (int)$nouvelle['id'],
            'token'     => $nouvelle['token'],
            'lien'      => 'inscription.html?token=' . $nouvelle['token'],
            'zone'      => $nouvelle['zone'],
            'actif'     => (bool)$nouvelle['actif'],
            'expire_at' => $nouvelle['expire_at'],
            'cree_le'   => $nouvelle['cree_le'],
        ],
    ]);
}

/* ------------------------------------------------------------
   Revocation (le lien ne fonctionne plus)
   ------------------------------------------------------------ */
function revoquerInvitation($pdo, $user, $estAdmin, $maZone) {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) {
        jsonResponse(['error' => 'Identifiant de l\'invitation requis'], 400);
    }

    $invitation = chargerInvitationAutorisee($pdo, $id, $estAdmin, $maZone);

    $pdo->prepare("UPDATE invitations SET actif = FALSE WHERE id = ?")->execute([$id]);

    journaliser($pdo, 'user:' . $user['user_id'], 'revocation_invitation', [
        'zone' => $invitation['zone'],
    ]);

    jsonResponse(['success' => true, 'message' => 'Lien d\'inscription revoque']);
}

/* ------------------------------------------------------------
   Controle de portee
   ------------------------------------------------------------ */
function chargerInvitationAutorisee($pdo, $id, $estAdmin, $maZone) {
    $stmt = $pdo->prepare("SELECT id, zone, actif FROM invitations WHERE id = ?");
    $stmt->execute([$id]);
    $invitation = $stmt->fetch();
    if (!$invitation) {
        jsonResponse(['error' => 'Invitation introuvable'], 404);
    }
    if (!$estAdmin) {
        if ($maZone === '') {
            jsonResponse([
                'error' => 'Aucune classe n\'est associee a votre compte. Contactez l\'administrateur.'
            ], 403);
        }
        if (mb_strtolower(trim((string)$invitation['zone'])) !== mb_strtolower($maZone)) {
            jsonResponse(['error' => 'Cette invitation n\'appartient pas a votre classe'], 403);
        }
    }
    return $invitation;
}
