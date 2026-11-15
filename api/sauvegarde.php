<?php
/**
 * ============================================================
 *  Sauvegardes - /api/sauvegarde.php
 * ============================================================
 *  Reserve a l'administrateur connecte.
 *
 *  GET  ?action=etat         etat de la base + derniere sauvegarde
 *                            (cree automatiquement une sauvegarde
 *                            si la derniere date de plus de 24 h)
 *  POST ?action=creer        cree une sauvegarde immediate
 *  GET  ?action=telecharger  telecharge la derniere sauvegarde
 *
 *  SECURITE
 *  --------
 *  - role admin obligatoire (jeton de session verifie) ;
 *  - fichiers ecrits HORS de la racine web quand c'est possible
 *    (dossier personnel alwaysdata), sinon dossier protege par
 *    un .htaccess "Require all denied" ;
 *  - noms de fichiers aleatoires, droits 0600, retention limitee ;
 *  - aucun chemin absolu renvoye au navigateur ;
 *  - limitation de requetes (api/cache) ;
 *  - reponse non mise en cache.
 *
 *  Le fichier contient des donnees sensibles (empreintes de mots
 *  de passe, bulletins anonymes). Il ne doit jamais etre commite
 *  ni depose dans un dossier public. Les sessions actives sont
 *  volontairement exclues.
 * ============================================================
 */
require_once 'config.php';

$method = $_SERVER['REQUEST_METHOD'];
if (!in_array($method, ['GET', 'POST'], true)) {
    jsonResponse(['error' => 'Methode non autorisee'], 405);
}

$pdo  = getDB();
$user = getAuthUser($pdo);
exigerRole($user, ['admin']);

$action = $_GET['action'] ?? ($method === 'POST' ? 'creer' : 'etat');

if ($method === 'POST' && $action === 'creer') {
    limiterRequetes('sauvegarde_creer', 10, 60, 'u' . (int)$user['user_id']);
    $info = creerSauvegarde($pdo);
    journaliser($pdo, 'user:' . $user['user_id'], 'sauvegarde_creee', [
        'fichier' => $info['nom'],
        'octets'  => $info['taille'],
    ]);
    jsonResponse([
        'success' => true,
        'message' => 'Sauvegarde creee.',
        'sauvegarde' => $info,
    ], 201);
}

if ($method === 'GET' && $action === 'telecharger') {
    limiterRequetes('sauvegarde_telecharger', 10, 60, 'u' . (int)$user['user_id']);
    telechargerSauvegarde($pdo, $user);
}

if ($method === 'GET' && $action === 'etat') {
    limiterRequetes('sauvegarde_etat', 30, 60, 'u' . (int)$user['user_id']);

    // Sauvegarde automatique : au plus une par 24 h, creee a la
    // premiere consultation de la page par un administrateur.
    autoSauvegarde($pdo);

    jsonResponse([
        'success'    => true,
        'version'    => APP_VERSION,
        'sauvegarde' => etatSauvegarde(),
        'tables'     => compterTables($pdo),
        'hors_web'   => dossierHorsWeb(),
    ]);
}

jsonResponse(['error' => 'Action inconnue'], 400);

/* ============================================================
   FONCTIONS
   ============================================================ */

/** Tables sauvegardees, dans l'ordre de restauration (FK). */
function tablesSauvegardees() {
    return [
        'utilisateurs',
        'elections',
        'candidats',
        'votes',
        'emargements',
        'ouvertures',
        'invitations',
        'journal',
    ];
}

/** Dossier de stockage, hors racine web si possible. */
function dossierSauvegardes() {
    static $dossier = null;
    if ($dossier !== null) {
        return $dossier;
    }

    $env = getenv('SAUVEGARDE_DIR');
    if ($env !== false && trim($env) !== '') {
        $base = rtrim(trim($env), '/\\');
    } else {
        // En production alwaysdata, l'application est dans /home/<compte>/www :
        // on remonte d'un niveau pour sortir de la racine web.
        $prod = strpos(__DIR__, DIRECTORY_SEPARATOR . 'www' . DIRECTORY_SEPARATOR) !== false;
        $base = $prod
            ? dirname(__DIR__, 2) . '/sauvegardes'
            : dirname(__DIR__) . '/sauvegardes';
    }

    if (!is_dir($base)) {
        @mkdir($base, 0700, true);
    }

    // Defense en profondeur : si le dossier est sous la racine web,
    // on y interdit tout acces HTTP et on masque le listage.
    if (is_dir($base)) {
        $ht = $base . '/.htaccess';
        if (!is_file($ht)) {
            @file_put_contents(
                $ht,
                "# Sauvegardes privees : aucun acces web\nRequire all denied\nOptions -Indexes\n"
            );
            @chmod($ht, 0600);
        }
        if (!is_file($base . '/index.html')) {
            @file_put_contents($base . '/index.html', '');
        }
    }

    $dossier = $base;
    return $dossier;
}

/** Le dossier de sauvegarde est-il hors de la racine web ? */
function dossierHorsWeb() {
    $base = dossierSauvegardes();
    $racine = dirname(__DIR__); // .../www
    $baseR = str_replace('\\', '/', $base);
    $racineR = str_replace('\\', '/', $racine);
    return strpos($baseR . '/', $racineR . '/') !== 0;
}

/** Liste des sauvegardes, de la plus recente a la plus ancienne. */
function listeSauvegardes() {
    $fichiers = glob(dossierSauvegardes() . '/sauvegarde-*.json') ?: [];
    usort($fichiers, function ($a, $b) {
        return filemtime($b) <=> filemtime($a);
    });
    return $fichiers;
}

/** Informations sur la derniere sauvegarde (ou null). */
function etatSauvegarde() {
    $liste = listeSauvegardes();
    if (!$liste) {
        return null;
    }
    $fichier = $liste[0];
    return [
        'nom'     => basename($fichier),
        'taille'  => (int)filesize($fichier),
        'cree_le' => gmdate('c', (int)filemtime($fichier)),
        'nombre'  => count($liste),
    ];
}

/** Cree une sauvegarde JSON complete. */
function creerSauvegarde($pdo) {
    $dossier = dossierSauvegardes();
    if (!is_dir($dossier) || !is_writable($dossier)) {
        throw new RuntimeException(
            'Dossier de sauvegarde inaccessible. Verifiez les droits du dossier.'
        );
    }

    $tables = [];
    try {
        $pdo->exec('BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY');
        foreach (tablesSauvegardees() as $table) {
            // Nom de table issu d'une liste blanche codee en dur.
            $tables[$table] = $pdo->query("SELECT * FROM $table ORDER BY id")->fetchAll();
        }
        $pdo->exec('COMMIT');
    } catch (Throwable $e) {
        try { $pdo->exec('ROLLBACK'); } catch (Throwable $ignore) {}
        throw $e;
    }

    $options = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;
    $empreinte = hash('sha256', json_encode($tables, $options));

    $document = [
        'application' => APP_NAME,
        'version'     => APP_VERSION,
        'cree_le'     => gmdate('c'),
        'empreinte'   => $empreinte,
        'tables'      => $tables,
    ];

    $json = json_encode($document, $options | JSON_PRETTY_PRINT);
    if ($json === false) {
        throw new RuntimeException('Encodage de la sauvegarde impossible.');
    }

    $nom = 'sauvegarde-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.json';
    $chemin = $dossier . '/' . $nom;

    $fp = @fopen($chemin, 'xb');
    if (!$fp) {
        throw new RuntimeException('Ecriture de la sauvegarde impossible.');
    }
    $ecrit = fwrite($fp, $json);
    fflush($fp);
    fclose($fp);

    if ($ecrit !== strlen($json)) {
        @unlink($chemin);
        throw new RuntimeException('Sauvegarde incomplete : reessayez.');
    }
    @chmod($chemin, 0600);

    // Retention : on garde les 14 sauvegardes les plus recentes.
    $liste = listeSauvegardes();
    foreach (array_slice($liste, 14) as $ancien) {
        @unlink($ancien);
    }

    return [
        'nom'     => $nom,
        'taille'  => strlen($json),
        'cree_le' => gmdate('c'),
    ];
}

/** Cree une sauvegarde si la derniere date de plus de 24 h. */
function autoSauvegarde($pdo) {
    $liste = listeSauvegardes();
    if ($liste && (time() - (int)filemtime($liste[0])) < 86400) {
        return;
    }

    // Verrou : evite deux sauvegardes simultanees (deux onglets admin).
    $verrou = dossierSauvegardes() . '/.auto.lock';
    $fp = @fopen($verrou, 'c');
    if (!$fp) {
        return;
    }
    if (!flock($fp, LOCK_EX | LOCK_NB)) {
        fclose($fp);
        return;
    }

    try {
        $liste = listeSauvegardes();
        if (!$liste || (time() - (int)filemtime($liste[0])) >= 86400) {
            creerSauvegarde($pdo);
        }
    } catch (Throwable $e) {
        // Une sauvegarde automatique ne doit jamais casser le tableau de bord.
        error_log('[DigitalElect] sauvegarde auto: ' . $e->getMessage());
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

/** Compte les lignes de chaque table (affichage "base de donnees"). */
function compterTables($pdo) {
    $comptes = [];
    foreach (tablesSauvegardees() as $table) {
        $comptes[$table] = (int)$pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn();
    }
    return $comptes;
}

/** Envoie la derniere sauvegarde en telechargement. */
function telechargerSauvegarde($pdo, $user) {
    $liste = listeSauvegardes();
    if (!$liste) {
        jsonResponse(['error' => 'Aucune sauvegarde disponible.'], 404);
    }

    $fichier = $liste[0];
    $nom = basename($fichier);

    if (!is_file($fichier) || !is_readable($fichier)) {
        jsonResponse(['error' => 'Sauvegarde illisible.'], 500);
    }

    journaliser($pdo, 'user:' . $user['user_id'], 'sauvegarde_telechargee', [
        'fichier' => $nom,
    ]);

    header('Content-Type: application/json; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $nom . '"');
    header('Content-Length: ' . filesize($fichier));
    header('Cache-Control: no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    readfile($fichier);
    exit();
}
