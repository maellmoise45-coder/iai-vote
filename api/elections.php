<?php
/**
 * ============================================================
 *  Elections - /api/elections.php
 * ============================================================
 *  GET    ?id=..   une election (+ candidats)
 *  GET              liste des elections
 *  POST             creation (admin ou gestionnaire)
 *  PUT              mise a jour partielle (statut, dates, publication)
 *  DELETE ?id=..    suppression (admin)
 *
 *  REGLES :
 *   - une election dont la date_fin est depassee se cloture
 *     AUTOMATIQUEMENT (statut = 'terminee') ;
 *   - la cloture MANUELLE exige le mot de passe de l'admin ;
 *   - les resultats sont publies EXPLICITEMENT (action 'publier'),
 *     jamais automatiquement.
 * ============================================================
 */
require_once 'config.php';

$method = $_SERVER['REQUEST_METHOD'];
$pdo    = getDB();

switch ($method) {
    case 'GET':
        if (isset($_GET['id'])) {
            getElection($pdo, (int)$_GET['id']);
        } else {
            getElections($pdo);
        }
        break;

    case 'POST':
        $user = getAuthUser($pdo);
        exigerRole($user, ['admin', 'gestionnaire']);
        createElection($pdo, $user);
        break;

    case 'PUT':
        $user = getAuthUser($pdo);
        exigerRole($user, ['admin', 'gestionnaire']);
        updateElection($pdo, $user);
        break;

    case 'DELETE':
        $user = getAuthUser($pdo);
        exigerRole($user, ['admin']);
        deleteElection($pdo);
        break;

    default:
        jsonResponse(['error' => 'Methode non autorisee'], 405);
}

function getElections($pdo) {
    cloturerElectionsEchues($pdo); // cloture auto si la date de fin est passee

    $stmt = $pdo->query(
        "SELECT e.*,
                (SELECT COUNT(*) FROM candidats c WHERE c.election_id = e.id) AS nb_candidats,
                (SELECT COUNT(*) FROM votes v WHERE v.election_id = e.id)     AS nb_votes
         FROM elections e
         ORDER BY e.cree_le DESC"
    );
    jsonResponse(['success' => true, 'data' => $stmt->fetchAll()]);
}

function getElection($pdo, $id) {
    cloturerElectionsEchues($pdo);

    $stmt = $pdo->prepare("SELECT * FROM elections WHERE id = ?");
    $stmt->execute([$id]);
    $election = $stmt->fetch();

    if (!$election) {
        jsonResponse(['error' => 'Election non trouvee'], 404);
    }

    $stmt = $pdo->prepare("SELECT * FROM candidats WHERE election_id = ? ORDER BY id");
    $stmt->execute([$id]);
    $election['candidats'] = $stmt->fetchAll();

    jsonResponse(['success' => true, 'data' => $election]);
}

function createElection($pdo, $user) {
    $data = getJsonBody();

    $titre       = trim($data['titre'] ?? '');
    $description = trim($data['description'] ?? '');
    $dateDebut   = $data['date_debut'] ?? null;
    $dateFin     = $data['date_fin'] ?? null;

    if ($titre === '') {
        jsonResponse(['error' => 'Le titre est requis'], 400);
    }

    $stmt = $pdo->prepare(
        "INSERT INTO elections (titre, description, date_debut, date_fin)
         VALUES (?, ?, ?, ?) RETURNING id"
    );
    $stmt->execute([
        $titre,
        $description ?: null,
        $dateDebut ?: null,
        $dateFin ?: null
    ]);
    $electionId = (int)$stmt->fetchColumn();

    if (!empty($data['candidats']) && is_array($data['candidats'])) {
        $ins = $pdo->prepare(
            "INSERT INTO candidats (nom, prenom, programme, photo_url, election_id)
             VALUES (?, ?, ?, ?, ?)"
        );
        foreach ($data['candidats'] as $c) {
            $ins->execute([
                trim($c['nom'] ?? ''),
                trim($c['prenom'] ?? ''),
                trim($c['programme'] ?? '') ?: null,
                validerPhoto($c['photo_url'] ?? ''),
                $electionId
            ]);
        }
    }

    journaliser($pdo, 'user:' . $user['user_id'], 'creation_election', ['id' => $electionId, 'titre' => $titre]);

    jsonResponse(['success' => true, 'message' => 'Election creee avec succes', 'id' => $electionId], 201);
}

function updateElection($pdo, $user) {
    $data = getJsonBody();
    $id   = (int)($data['id'] ?? 0);

    if (!$id) {
        jsonResponse(['error' => 'ID de l\'election requis'], 400);
    }

    /* ---- Action : PUBLIER les resultats ----------------------
       Les resultats n'apparaissent jamais tout seuls : l'admin
       (ou le gestionnaire) doit cliquer sur "Publier". */
    if (!empty($data['publier'])) {
        $stmt = $pdo->prepare("SELECT statut FROM elections WHERE id = ?");
        $stmt->execute([$id]);
        $statut = $stmt->fetchColumn();
        if ($statut === false) {
            jsonResponse(['error' => 'Election introuvable'], 404);
        }
        if (!in_array($statut, ['terminee', 'publiee'], true)) {
            jsonResponse(['error' => 'Cloturez l\'election avant de publier les resultats'], 400);
        }

        // On fige d'abord les bulletins nuls (chrono depasse),
        // puis on publie.
        sweepVotesNuls($pdo, $id);
        $pdo->prepare("UPDATE elections SET resultats_publics = TRUE, statut = 'publiee' WHERE id = ?")
            ->execute([$id]);

        journaliser($pdo, 'user:' . $user['user_id'], 'publication_resultats', ['id' => $id]);

        jsonResponse(['success' => true, 'message' => 'Resultats publies : ils sont desormais visibles par tous']);
    }

    /* ---- CLOTURE MANUELLE : mot de passe de l'admin requis --- */
    if (($data['statut'] ?? null) === 'terminee') {
        $mdp = (string)($data['mot_de_passe'] ?? '');
        if ($mdp === '') {
            jsonResponse(['error' => 'Mot de passe requis pour cloturer l\'election'], 400);
        }

        $stmt = $pdo->prepare("SELECT mot_de_passe FROM utilisateurs WHERE id = ?");
        $stmt->execute([$user['user_id']]);
        $hashMdp = (string)$stmt->fetchColumn();

        if ($hashMdp === '' || !password_verify($mdp, $hashMdp)) {
            journaliser($pdo, 'user:' . $user['user_id'], 'cloture_refusee', ['id' => $id]);
            jsonResponse(['error' => 'Mot de passe incorrect'], 403);
        }
        unset($data['mot_de_passe']);
    }

    miseAJourPartielle($pdo, 'elections', $data, [
        'titre'             => 'titre',
        'description'       => 'nullable',
        'statut'            => 'statut',
        'date_debut'        => 'nullable',
        'date_fin'          => 'nullable',
        'resultats_publics' => 'bool',
    ], $id, [
        'statut' => function ($valeur) {
            $autorises = ['a_venir', 'en_cours', 'terminee', 'publiee', 'annulee'];
            if (!in_array($valeur, $autorises, true)) {
                return ['erreur' => 'Statut invalide. Valeurs acceptees : ' . implode(', ', $autorises), 'valeur' => null];
            }
            return ['erreur' => null, 'valeur' => $valeur];
        },
        'date_fin' => function ($valeur, $donnees) {
            if ($valeur === '' || $valeur === null) {
                return ['erreur' => null, 'valeur' => null];
            }
            if (!empty($donnees['date_debut']) && strtotime($valeur) <= strtotime($donnees['date_debut'])) {
                return ['erreur' => 'La date de fin doit etre posterieure a la date de debut', 'valeur' => null];
            }
            return ['erreur' => null, 'valeur' => $valeur];
        },
    ]);

    // Apres une cloture, figer les bulletins nuls (chrono depasse).
    if (($data['statut'] ?? null) === 'terminee') {
        sweepVotesNuls($pdo, $id);
    }

    journaliser($pdo, 'user:' . $user['user_id'], 'modification_election', ['id' => $id]);

    jsonResponse(['success' => true, 'message' => 'Election mise a jour avec succes']);
}

function deleteElection($pdo) {
    $data = getJsonBody();
    $id   = (int)($data['id'] ?? ($_GET['id'] ?? 0));

    if (!$id) {
        jsonResponse(['error' => 'ID de l\'election requis'], 400);
    }

    $pdo->prepare("DELETE FROM elections WHERE id = ?")->execute([$id]);

    jsonResponse(['success' => true, 'message' => 'Election supprimee avec succes']);
}
