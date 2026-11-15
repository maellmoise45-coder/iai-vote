<?php
/**
 * ============================================================
 *  Elections - /api/elections.php
 * ============================================================
 *  GET    ?id=..   une election (+ candidats)
 *  GET              liste des elections visibles
 *  POST             creation (admin ou delegue de classe)
 *  PUT              mise a jour partielle (statut, dates, publication)
 *  DELETE ?id=..    suppression (admin ou delegue de la classe)
 *
 *  ELECTIONS DE CLASSE
 *  -------------------
 *  Une election dont la colonne "zone" est vide est une election
 *  generale (toute l'ecole). Sinon, elle est reservee a la classe
 *  indiquee : le delegue de cette classe peut l'organiser, et
 *  seuls les etudiants de la classe la voient et votent.
 *
 *  REGLES :
 *   - une election dont la date_fin est depassee se cloture
 *     AUTOMATIQUEMENT (statut = 'terminee') ;
 *   - la cloture MANUELLE exige le mot de passe de l'organisateur ;
 *   - les resultats sont publies EXPLICITEMENT (action 'publier')
 *     jamais automatiquement. Pour une election de classe, ils ne
 *     sont visibles que par le delegue et les etudiants de la classe.
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
        exigerRole($user, ['admin', 'gestionnaire']);
        deleteElection($pdo, $user);
        break;

    default:
        jsonResponse(['error' => 'Methode non autorisee'], 405);
}

/**
 * Filtre SQL des elections visibles par l'utilisateur connecte.
 * @return array{0:string,1:array}
 */
function filtreElections($pdo, $user) {
    if (!$user) {
        return ["WHERE TRIM(COALESCE(e.zone, '')) = ''", []];
    }

    $role = $user['user_role'] ?? '';

    if ($role === 'admin') {
        return ['', []];
    }

    if ($role === 'gestionnaire' || $role === 'electeur') {
        $zone = zoneUtilisateur($pdo, (int)$user['user_id']);
        if ($role === 'gestionnaire') {
            // Le delegue ne voit que les elections de SA classe.
            return [
                "WHERE TRIM(COALESCE(e.zone, '')) <> ''
                   AND LOWER(TRIM(e.zone)) = LOWER(?)",
                [$zone],
            ];
        }
        // L'electeur voit les elections generales et celles de sa classe.
        return [
            "WHERE (TRIM(COALESCE(e.zone, '')) = ''
                    OR LOWER(TRIM(e.zone)) = LOWER(?))",
            [$zone],
        ];
    }

    // Controleur et visiteur anonyme : elections generales seulement.
    return ["WHERE TRIM(COALESCE(e.zone, '')) = ''", []];
}

function getElections($pdo) {
    cloturerElectionsEchues($pdo); // cloture auto si la date de fin est passee

    $user = getAuthUser($pdo, false);
    [$where, $params] = filtreElections($pdo, $user);

    $stmt = $pdo->prepare(
        "SELECT e.*,
                (SELECT COUNT(*) FROM candidats c WHERE c.election_id = e.id) AS nb_candidats,
                (SELECT COUNT(*) FROM votes v WHERE v.election_id = e.id)     AS nb_votes
         FROM elections e
         $where
         ORDER BY e.cree_le DESC"
    );
    $stmt->execute($params);
    $elections = $stmt->fetchAll();

    // Le nombre de bulletins n'est pas une donnee publique avant
    // publication : on le retire pour les visiteurs et les electeurs.
    $peutVoirVotes = $user
        && in_array($user['user_role'] ?? '', ['admin', 'gestionnaire', 'controleur'], true);

    if (!$peutVoirVotes) {
        foreach ($elections as &$e) {
            unset($e['nb_votes']);
        }
        unset($e);
    }

    jsonResponse(['success' => true, 'data' => $elections]);
}

function getElection($pdo, $id) {
    cloturerElectionsEchues($pdo);

    $stmt = $pdo->prepare("SELECT * FROM elections WHERE id = ?");
    $stmt->execute([$id]);
    $election = $stmt->fetch();

    if (!$election) {
        jsonResponse(['error' => 'Election non trouvee'], 404);
    }

    $user = getAuthUser($pdo, false);

    // Une election de classe ne doit pas reveler son existence aux
    // personnes qui n'y ont pas acces.
    if (!accesElection($pdo, $user, $election, false)) {
        jsonResponse(['error' => 'Election non trouvee'], 404);
    }

    $stmt = $pdo->prepare(
        "SELECT id, election_id, nom, prenom, programme, photo_url, nombre_voix, cree_le
         FROM candidats WHERE election_id = ? ORDER BY id"
    );
    $stmt->execute([$id]);
    $candidats = $stmt->fetchAll();

    // Compteurs de voix : visibles seulement si les resultats sont
    // publics, ou pour les roles autorises.
    $role = $user['user_role'] ?? '';
    $peutVoirVotes = !empty($election['resultats_publics'])
        || in_array($role, ['admin', 'gestionnaire', 'controleur'], true);

    if (!$peutVoirVotes) {
        foreach ($candidats as &$c) {
            unset($c['nombre_voix']);
        }
        unset($c);
    }

    $election['candidats'] = $candidats;

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

    // Un delegue cree obligatoirement pour SA classe : la zone ne
    // peut pas etre choisie ni contournee depuis le client.
    if (($user['user_role'] ?? '') === 'gestionnaire') {
        $zone = zoneUtilisateur($pdo, (int)$user['user_id']);
        if ($zone === '') {
            jsonResponse([
                'error' => 'Renseignez votre classe avant de creer une election de classe.'
            ], 400);
        }
    } else {
        $zone = normaliserZone($data['zone'] ?? '');
        if ($zone !== '') {
            $zone = zoneCanonique($pdo, $zone) ?: $zone;
        }
    }

    $stmt = $pdo->prepare(
        "INSERT INTO elections (titre, description, date_debut, date_fin, zone)
         VALUES (?, ?, ?, ?, ?) RETURNING id"
    );
    $stmt->execute([
        $titre,
        $description ?: null,
        $dateDebut ?: null,
        $dateFin ?: null,
        $zone !== '' ? $zone : null,
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

    journaliser(
        $pdo,
        'user:' . $user['user_id'],
        $zone !== '' ? 'creation_election_classe' : 'creation_election',
        ['id' => $electionId, 'titre' => $titre, 'zone' => $zone ?: null]
    );

    jsonResponse([
        'success' => true,
        'message' => $zone !== ''
            ? 'Election de classe creee avec succes'
            : 'Election creee avec succes',
        'id'    => $electionId,
        'zone'  => $zone ?: null,
    ], 201);
}

function updateElection($pdo, $user) {
    $data = getJsonBody();
    $id   = (int)($data['id'] ?? 0);

    if (!$id) {
        jsonResponse(['error' => 'ID de l\'election requis'], 400);
    }

    $stmt = $pdo->prepare("SELECT * FROM elections WHERE id = ?");
    $stmt->execute([$id]);
    $election = $stmt->fetch();
    if (!$election) {
        jsonResponse(['error' => 'Election introuvable'], 404);
    }

    // Organisation : admin, ou delegue de la classe concernee.
    if (!accesElection($pdo, $user, $election, true)) {
        jsonResponse(['error' => 'Vous ne pouvez pas modifier cette election'], 403);
    }

    $estClasse = electionEstClasse($election);

    /* ---- Action : PUBLIER les resultats ----------------------
       Les resultats n'apparaissent jamais tout seuls : l'admin
       (ou le delegue) doit cliquer sur "Publier". Pour une
       election de classe, la publication ne concerne que la classe. */
    if (!empty($data['publier'])) {
        $statut = $election['statut'];
        if (!in_array($statut, ['terminee', 'publiee'], true)) {
            jsonResponse(['error' => 'Cloturez l\'election avant de publier les resultats'], 400);
        }

        // On fige d'abord les bulletins nuls (chrono depasse),
        // puis on publie.
        sweepVotesNuls($pdo, $id);
        $pdo->prepare("UPDATE elections SET resultats_publics = TRUE, statut = 'publiee' WHERE id = ?")
            ->execute([$id]);

        journaliser($pdo, 'user:' . $user['user_id'], 'publication_resultats', [
            'id' => $id, 'zone' => $estClasse ? zoneElection($election) : null,
        ]);

        jsonResponse([
            'success' => true,
            'message' => $estClasse
                ? 'Resultats publies : ils sont visibles par la classe ' . zoneElection($election)
                : 'Resultats publies : ils sont desormais visibles par tous',
        ]);
    }

    /* ---- CLOTURE MANUELLE : mot de passe de l'organisateur --- */
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

    // La zone d'une election n'est jamais modifiable (elle determine
    // qui peut voter et voir les resultats).
    unset($data['zone']);

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

    journaliser($pdo, 'user:' . $user['user_id'], 'modification_election', [
        'id' => $id, 'zone' => $estClasse ? zoneElection($election) : null,
    ]);

    jsonResponse(['success' => true, 'message' => 'Election mise a jour avec succes']);
}

function deleteElection($pdo, $user) {
    // L'interface appelle DELETE elections.php?id=.. (sans corps JSON).
    // On accepte aussi un corps JSON {id} pour les clients API.
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) {
        $raw = file_get_contents('php://input');
        if (is_string($raw) && trim($raw) !== '') {
            $data = json_decode($raw, true);
            if (is_array($data)) {
                $id = (int)($data['id'] ?? 0);
            }
        }
    }

    if (!$id) {
        jsonResponse(['error' => 'ID de l\'election requis'], 400);
    }

    $stmt = $pdo->prepare("SELECT * FROM elections WHERE id = ?");
    $stmt->execute([$id]);
    $election = $stmt->fetch();
    if (!$election) {
        jsonResponse(['error' => 'Election introuvable'], 404);
    }

    if (!accesElection($pdo, $user, $election, true)) {
        jsonResponse(['error' => 'Vous ne pouvez pas supprimer cette election'], 403);
    }

    $pdo->prepare("DELETE FROM elections WHERE id = ?")->execute([$id]);

    journaliser($pdo, 'user:' . $user['user_id'], 'suppression_election', [
        'id' => $id, 'zone' => electionEstClasse($election) ? zoneElection($election) : null,
    ]);

    jsonResponse(['success' => true, 'message' => 'Election supprimee avec succes']);
}
