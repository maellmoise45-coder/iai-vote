<?php
/**
 * ============================================================
 *  Votes - /api/votes.php
 * ============================================================
 *  LE VOTE EST ANONYME :
 *   - aucun matricule n'est stocke dans la table votes ;
 *   - hash_vote = HMAC(election_id | matricule, SECRET) : il
 *     garantit "1 matricule = 1 vote" sans reveler le choix.
 *
 *  POST {election_id, candidat_id}        enregistrer un vote (electeur)
 *  POST {election_id, vote_nul:true}      vote nul VOLONTAIRE (bulletin blanc)
 *  POST {action:'expirer', election_id}   chrono ecoule -> vote NUL + deconnexion
 *  GET  ?action=a_vote&election_id=       a vote ? + temps restant
 *
 *  Aucune liste nominative ou individuelle des bulletins n'est
 *  exposee : seuls les agregats (resultats.php, suivi.php) le sont.
 *  CHRONO : a l'ouverture du bulletin, une ligne 'ouvertures' est
 *  creee (une seule fois). Si l'electeur ne vote pas dans les
 *  DUREE_VOTE secondes, son bulletin est declare NUL.
 * ============================================================
 */
require_once 'config.php';

$method = $_SERVER['REQUEST_METHOD'];
$pdo    = getDB();

switch ($method) {
    case 'GET':
        if (($_GET['action'] ?? '') === 'a_vote') {
            aVote($pdo);
        } else {
            // Pas de listing des bulletins : le secret du vote et la regle
            // "aucun choix individuel expose" l'interdisent.
            jsonResponse(['error' => 'Action non autorisee. Utilisez resultats.php ou suivi.php.'], 400);
        }
        break;

    case 'POST':
        $user = getAuthUser($pdo);
        exigerRole($user, ['electeur']);
        $data = getJsonBody();
        if (($data['action'] ?? '') === 'expirer') {
            expirerVote($pdo, $user, $data);
        } else {
            createVote($pdo, $user, $data);
        }
        break;

    default:
        jsonResponse(['error' => 'Methode non autorisee'], 405);
}

/**
 * Etat de vote de l'electeur pour une election :
 *  - a_vote             : un bulletin (valide ou nul) existe-t-il ?
 *  - nul                : ce bulletin est-il nul ?
 *  - secondes_restantes : temps restant du chrono (l'ouverture est
 *                         creee au premier appel, une seule fois).
 */
function aVote($pdo) {
    $user = getAuthUser($pdo);
    exigerRole($user, ['electeur']);
    $electionId = (int)($_GET['election_id'] ?? 0);

    if (!$electionId) {
        jsonResponse(['error' => 'election_id requis'], 400);
    }

    // Le matricule est lu en base (le jeton ne contient que l'id)
    $stmt = $pdo->prepare("SELECT matricule FROM utilisateurs WHERE id = ?");
    $stmt->execute([$user['user_id']]);
    $matricule = (string)$stmt->fetchColumn();

    $hash = calculerHashVote($electionId, $matricule);

    $stmt = $pdo->prepare("SELECT candidat_id, date_vote FROM votes WHERE election_id = ? AND hash_vote = ?");
    $stmt->execute([$electionId, $hash]);
    $vote = $stmt->fetch();

    $resultat = [
        'success'            => true,
        'a_vote'             => $vote !== false,
        'nul'                => $vote !== false ? ($vote['candidat_id'] === null) : false,
        'date_vote'          => $vote['date_vote'] ?? null,
        'secondes_restantes' => null,
    ];

    if ($vote === false) {
        // Ouvrir le chrono (une seule fois) pour cet electeur / election.
        $pdo->prepare(
            "INSERT INTO ouvertures (utilisateur_id, election_id)
             VALUES (?, ?) ON CONFLICT (utilisateur_id, election_id) DO NOTHING"
        )->execute([$user['user_id'], $electionId]);

        $stmt = $pdo->prepare("SELECT debut_at FROM ouvertures WHERE utilisateur_id = ? AND election_id = ?");
        $stmt->execute([$user['user_id'], $electionId]);
        $debut = $stmt->fetchColumn();

        if ($debut) {
            $ecoule = time() - (int)strtotime((string)$debut);
            $resultat['secondes_restantes'] = max(0, DUREE_VOTE - $ecoule);
        } else {
            $resultat['secondes_restantes'] = DUREE_VOTE;
        }
    }

    jsonResponse($resultat);
}

/**
 * ENREGISTREMENT DU VOTE.
 * Transaction + verrou FOR UPDATE sur la ligne de l'election :
 * deux requetes simultanees sont serialisees, ce qui empeche
 * tout double vote en cas de clic rapide ou d'onglet double.
 * Si le delai de vote est depasse, un bulletin NUL est enregistre.
 */
function createVote($pdo, $user, $data) {
    $electionId = (int)($data['election_id'] ?? 0);
    $candidatId = (int)($data['candidat_id'] ?? 0);
    $voteNul    = !empty($data['vote_nul']);

    if (!$electionId || (!$candidatId && !$voteNul)) {
        jsonResponse(['error' => 'Election et candidat requis (ou vote_nul)'], 400);
    }

    // Le matricule est lu en base : le jeton ne contient que l'identifiant.
    $stmt = $pdo->prepare("SELECT matricule FROM utilisateurs WHERE id = ?");
    $stmt->execute([$user['user_id']]);
    $matricule = (string)$stmt->fetchColumn();
    if ($matricule === '') {
        jsonResponse(['error' => 'Compte sans matricule : vote impossible'], 400);
    }

    $hash = calculerHashVote($electionId, $matricule);

    $pdo->beginTransaction();
    try {
        // 1) Verrouiller l'election (serialise les votes concurrents)
        $stmt = $pdo->prepare("SELECT id, statut FROM elections WHERE id = ? FOR UPDATE");
        $stmt->execute([$electionId]);
        $election = $stmt->fetch();

        if (!$election) {
            $pdo->rollBack();
            jsonResponse(['error' => 'Election introuvable'], 404);
        }
        if ($election['statut'] !== 'en_cours') {
            $pdo->rollBack();
            jsonResponse(['error' => 'Cette election n\'est pas en cours'], 400);
        }

        // 2) Un matricule = un seul vote par election
        $stmt = $pdo->prepare("SELECT id FROM votes WHERE election_id = ? AND hash_vote = ?");
        $stmt->execute([$electionId, $hash]);
        if ($stmt->fetch()) {
            $pdo->rollBack();
            jsonResponse(['error' => 'Vous avez deja vote pour cette election'], 409);
        }

        // 3) Chrono depasse ? -> bulletin NUL (le choix est ignore)
        $stmt = $pdo->prepare("SELECT debut_at FROM ouvertures WHERE utilisateur_id = ? AND election_id = ?");
        $stmt->execute([$user['user_id'], $electionId]);
        $debut = $stmt->fetchColumn();
        $expire = $debut && (time() - (int)strtotime((string)$debut)) > DUREE_VOTE;

        if ($expire) {
            $pdo->prepare("INSERT INTO votes (election_id, candidat_id, hash_vote) VALUES (?, NULL, ?)")
                ->execute([$electionId, $hash]);
            $pdo->prepare("UPDATE utilisateurs SET a_vote = TRUE WHERE id = ?")
                ->execute([$user['user_id']]);
            $pdo->prepare(
                "INSERT INTO emargements (utilisateur_id, election_id) VALUES (?, ?)
                 ON CONFLICT (utilisateur_id, election_id) DO NOTHING"
            )->execute([$user['user_id'], $electionId]);
            $pdo->commit();

            journaliser($pdo, $matricule, 'vote_nul', ['election_id' => $electionId, 'raison' => 'chrono']);

            jsonResponse([
                'success' => true,
                'nul'     => true,
                'message' => 'Temps de vote ecoule : votre bulletin a ete declare NUL.'
            ], 201);
        }

        // 4) Vote nul VOLONTAIRE (bulletin blanc) : aucun candidat choisi.
        if ($voteNul) {
            $pdo->prepare("INSERT INTO votes (election_id, candidat_id, hash_vote) VALUES (?, NULL, ?)")
                ->execute([$electionId, $hash]);
            $pdo->prepare("UPDATE utilisateurs SET a_vote = TRUE WHERE id = ?")
                ->execute([$user['user_id']]);
            $pdo->prepare(
                "INSERT INTO emargements (utilisateur_id, election_id) VALUES (?, ?)
                 ON CONFLICT (utilisateur_id, election_id) DO NOTHING"
            )->execute([$user['user_id'], $electionId]);
            $pdo->commit();

            journaliser($pdo, $matricule, 'vote_nul', ['election_id' => $electionId, 'raison' => 'choix']);

            jsonResponse([
                'success'    => true,
                'nul'        => true,
                'volontaire' => true,
                'message'    => 'Votre vote nul (bulletin blanc) a ete enregistre.'
            ], 201);
        }

        // 4b) Le candidat doit appartenir a l'election
        $stmt = $pdo->prepare("SELECT id FROM candidats WHERE id = ? AND election_id = ?");
        $stmt->execute([$candidatId, $electionId]);
        if (!$stmt->fetch()) {
            $pdo->rollBack();
            jsonResponse(['error' => 'Candidat invalide pour cette election'], 400);
        }

        // 5) Inserer le bulletin anonyme
        $stmt = $pdo->prepare(
            "INSERT INTO votes (election_id, candidat_id, hash_vote) VALUES (?, ?, ?)"
        );
        $stmt->execute([$electionId, $candidatId, $hash]);

        // 6) Incrementer le compteur du candidat
        $pdo->prepare("UPDATE candidats SET nombre_voix = nombre_voix + 1 WHERE id = ?")
            ->execute([$candidatId]);

        // 7) Marquer l'electeur comme ayant vote (emargement)
        $pdo->prepare("UPDATE utilisateurs SET a_vote = TRUE WHERE id = ?")
            ->execute([$user['user_id']]);

        // 7b) Emargement par election (statistiques par zone, sans le choix)
        $pdo->prepare(
            "INSERT INTO emargements (utilisateur_id, election_id) VALUES (?, ?)
             ON CONFLICT (utilisateur_id, election_id) DO NOTHING"
        )->execute([$user['user_id'], $electionId]);

        $pdo->commit();

        journaliser($pdo, $matricule, 'vote', ['election_id' => $electionId]);

        jsonResponse(['success' => true, 'nul' => false, 'message' => 'Vote enregistre avec succes'], 201);

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        // Violation de la contrainte UNIQUE (course entre deux requetes)
        if ($e->getCode() === '23505') {
            jsonResponse(['error' => 'Vous avez deja vote pour cette election'], 409);
        }
        throw $e;
    }
}

/**
 * Appele par le navigateur quand le chrono atteint 0 :
 * enregistre un bulletin NUL puis met fin a la session (expulsion).
 */
function expirerVote($pdo, $user, $data) {
    $electionId = (int)($data['election_id'] ?? 0);
    if (!$electionId) {
        jsonResponse(['error' => 'election_id requis'], 400);
    }

    $stmt = $pdo->prepare("SELECT matricule FROM utilisateurs WHERE id = ?");
    $stmt->execute([$user['user_id']]);
    $matricule = (string)$stmt->fetchColumn();

    $cree = enregistrerVoteNul($pdo, $electionId, (int)$user['user_id'], $matricule);

    // Expulsion : on supprime la session de l'electeur.
    $pdo->prepare("DELETE FROM sessions WHERE utilisateur_id = ?")->execute([$user['user_id']]);

    jsonResponse([
        'success' => true,
        'nul'     => true,
        'cree'    => $cree,
        'message' => $cree
            ? 'Temps de vote ecoule : votre bulletin a ete declare NUL. Vous avez ete deconnecte.'
            : 'Vous aviez deja vote pour cette election.'
    ]);
}
