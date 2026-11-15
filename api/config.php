<?php
/**
 * ============================================================
 *  Digital-Elect - Configuration de l'API
 * ============================================================
 *  PHP natif + PostgreSQL (PDO).
 *
 *  En local (XAMPP) : les valeurs par defaut ci-dessous sont
 *  utilisees. En production (Render) : les variables
 *  d'environnement prennent le dessus.
 * ============================================================
 */

/* ------------------------------------------------------------
   LECTURE DE LA CONFIGURATION
   ------------------------------------------------------------
   Render fournit une variable DATABASE_URL de la forme
   postgres://user:pass@host:port/db
   En local, on retombe sur les valeurs de XAMPP/PostgreSQL.
   ------------------------------------------------------------ */
$env = function ($cle, $defaut = null) {
    $v = getenv($cle);
    if ($v === false || $v === '') {
        $v = $_ENV[$cle] ?? $_SERVER[$cle] ?? null;
    }
    return ($v === false || $v === '' || $v === null) ? $defaut : $v;
};

/* ------------------------------------------------------------
   SURCHARGE LOCALE (fichier non versionne)
   ------------------------------------------------------------
   Si le fichier api/config.local.php existe, il est charge AVANT
   la lecture des variables. Il peut y definir les identifiants
   de la base via putenv('DB_HOST=...'), etc. Pratique pour un
   hebergement mutualise (alwaysdata) ou l'on ne peut pas creer
   de variables d'environnement.
   ------------------------------------------------------------ */
$fichierLocal = __DIR__ . '/config.local.php';
if (is_file($fichierLocal)) {
    require $fichierLocal;
}

$dbHost    = $env('DB_HOST', '127.0.0.1');
$dbPort    = $env('DB_PORT', '5432');
$dbName    = $env('DB_NAME', 'iai_vote');
$dbUser    = $env('DB_USER', 'postgres');
$dbPass    = $env('DB_PASS', 'postgres');
$dbSslMode = $env('DB_SSLMODE', ''); // vide en local, 'require' en ligne

// Render : DATABASE_URL a la priorite si presente
$databaseUrl = $env('DATABASE_URL');
if ($databaseUrl) {
    $u = parse_url($databaseUrl);
    if ($u && isset($u['host'])) {
        $dbHost = $u['host'];
        $dbPort = $u['port'] ?? 5432;
        $dbName = ltrim($u['path'] ?? '', '/');
        $dbUser = $u['user'] ?? $dbUser;
        $dbPass = $u['pass'] ?? $dbPass;
    }
    // SSL : la base managee Render impose une connexion chiffree.
    // On lit sslmode dans l'URL si present, sinon on force 'require'.
    if ($u && isset($u['query'])) {
        parse_str($u['query'], $query);
        if (!empty($query['sslmode'])) {
            $dbSslMode = $query['sslmode'];
        }
    }
    if ($dbSslMode === '') {
        $dbSslMode = 'require';
    }
}

define('DB_HOST', $dbHost);
define('DB_PORT', $dbPort);
define('DB_NAME', $dbName);
define('DB_USER', $dbUser);
define('DB_PASS', $dbPass);
define('DB_SSLMODE', $dbSslMode);

// Secret servant a signer les jetons ET a calculer le hash anonyme
// des votes. En production, definir la variable d'environnement APP_SECRET.
define('APP_NAME', 'Digital-Elect');
define('APP_VERSION', '1.4');
define('JWT_SECRET', $env('APP_SECRET', 'iai-vote-secret-a-changer-en-production-2025'));

// Jeton exige par api/install.php. Volontairement SANS valeur par defaut :
// si INSTALL_TOKEN n'est pas defini (config.local.php ou variable
// d'environnement), l'installation web est refusee.
define('INSTALL_TOKEN', (string)$env('INSTALL_TOKEN', ''));

// Origine autorisee pour les appels cross-origin (facultatif).
// Par defaut vide = uniquement le meme site (aucun en-tete CORS emis).
define('APP_ORIGIN', (string)$env('APP_ORIGIN', ''));

// Duree de vote (chrono) en secondes. Au-dela, le bulletin est NUL.
define('DUREE_VOTE', 60);

// Nombre maxi de connexions echouees par IP sur 15 minutes.
define('MAX_TENTATIVES_CONNEXION', 10);

/* ------------------------------------------------------------
   CORS
   ------------------------------------------------------------
   Le frontend est servi par le meme domaine que l'API : aucun
   en-tete CORS n'est necessaire. Si un deploiement separe est
   utilise, definir APP_ORIGIN (ex. https://vote.exemple.cm).
   ------------------------------------------------------------ */
$origine = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origine !== '' && APP_ORIGIN !== '' && rtrim($origine, '/') === rtrim(APP_ORIGIN, '/')) {
    header('Access-Control-Allow-Origin: ' . $origine);
    header('Vary: Origin');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Access-Control-Max-Age: 600');
}

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, max-age=0');

// En-tetes de securite (defense en profondeur)
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=()');
header('Cross-Origin-Resource-Policy: same-origin');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(APP_ORIGIN !== '' ? 204 : 200);
    exit();
}

/* ==================================================================
   GESTIONNAIRE D'ERREURS GLOBAL
   ------------------------------------------------------------------
   Toute erreur non rattrapee est traduite en JSON lisible plutot
   qu'en page HTML : le frontend affiche ainsi un vrai message.
   ================================================================== */
set_exception_handler(function ($e) {
    $estSql = $e instanceof PDOException;

    $message = $estSql ? messageSqlLisible($e) : $e->getMessage();

    error_log('[DigitalElect] ' . get_class($e) . ' : ' . $e->getMessage()
        . ' | ' . $e->getFile() . ':' . $e->getLine());

    jsonResponse([
        'success' => false,
        'error'   => $message,
        'type'    => $estSql ? 'base_de_donnees' : 'application'
    ], $estSql ? 400 : 500);
});

ini_set('display_errors', '0');
ini_set('log_errors', '1');

/**
 * Traduit un code d'erreur PostgreSQL (SQLSTATE) en message lisible.
 */
function messageSqlLisible($e) {
    $code = $e->getCode();

    switch ($code) {
        case '23503': // foreign_key_violation
            return 'Cet enregistrement fait reference a une donnee introuvable.';
        case '23505': // unique_violation
            return 'Cette valeur existe deja (matricule, email ou vote en double).';
        case '23502': // not_null_violation
            return 'Un champ obligatoire est manquant.';
        case '22001': // string_data_right_truncation
            return 'Une valeur est trop longue pour le champ correspondant.';
        case '42P01': // undefined_table
            return 'Table introuvable : verifiez que la base a bien ete initialisee.';
        case '42703': // undefined_column
            return 'Colonne introuvable : le schema de la base ne correspond pas au code.';
        default:
            return 'Erreur de base de donnees (code ' . $code . ')';
    }
}

/* ------------------------------------------------------------
   CONNEXION A LA BASE
   ------------------------------------------------------------ */
function getDB() {
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    try {
        $dsn = 'pgsql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME;
        if (DB_SSLMODE !== '') {
            $dsn .= ';sslmode=' . DB_SSLMODE;
        }
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        // PostgreSQL renvoie les booleens en 't'/'f' : on les convertit
        // en vrais booleens PHP pour que json_encode produise true/false.
        $pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, false);
        return $pdo;
    } catch (PDOException $e) {
        // Ne JAMAIS renvoyer le detail de la connexion au navigateur :
        // il contient l'hote, l'utilisateur et parfois le mot de passe.
        error_log('[DigitalElect] Connexion PostgreSQL impossible : ' . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'error' => 'Erreur de connexion a la base de donnees. Contactez l\'administrateur.'
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }
}

/* ------------------------------------------------------------
   JETONS (JWT HS256 maison)
   ------------------------------------------------------------ */
function generateToken($userId, $userRole) {
    $header  = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
    $time    = time();
    $payload = json_encode([
        'user_id'   => $userId,
        'user_role' => $userRole,
        'jti'       => bin2hex(random_bytes(16)),
        'iat'       => $time,
        'exp'       => $time + (60 * 60 * 24) // 24 heures
    ]);

    $b64 = function ($v) {
        return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($v));
    };

    $h = $b64($header);
    $p = $b64($payload);
    $s = $b64(hash_hmac('sha256', $h . '.' . $p, JWT_SECRET, true));

    return $h . '.' . $p . '.' . $s;
}

function verifyToken($token) {
    $parts = explode('.', $token);
    if (count($parts) !== 3) return false;

    $signature = hash_hmac('sha256', $parts[0] . '.' . $parts[1], JWT_SECRET, true);
    $b64 = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));

    if (!hash_equals($b64, $parts[2])) return false;

    $payload = json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], $parts[1])), true);

    if (!$payload || ($payload['exp'] ?? 0) < time()) return false;

    return $payload;
}

/**
 * Verifie qu'un jeton est bien la session ACTIVE du compte.
 * Connexion unique : toute nouvelle connexion invalide la precedente.
 */
function verifierSessionActive($pdo, $token, $userId) {
    // On verifie aussi que le compte est toujours actif : un compte
    // bloque perd immediatement l'acces, meme si son jeton existe encore.
    $stmt = $pdo->prepare(
        "SELECT s.id
         FROM sessions s
         JOIN utilisateurs u ON u.id = s.utilisateur_id
         WHERE s.utilisateur_id = ?
           AND s.token = ?
           AND s.expire_at > NOW()
           AND u.actif = TRUE"
    );
    $stmt->execute([$userId, $token]);
    return $stmt->fetch() !== false;
}

/**
 * Recupere l'utilisateur authentifie.
 * @param bool $obligatoire si false, renvoie null au lieu de couper.
 */
function getAuthUser($pdo = null, $obligatoire = true) {
    $tokenBrut = null;

    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $key => $value) {
            if (strtolower($key) === 'authorization') { $tokenBrut = $value; break; }
        }
    }
    if (!$tokenBrut && function_exists('apache_request_headers')) {
        foreach (apache_request_headers() as $key => $value) {
            if (strtolower($key) === 'authorization') { $tokenBrut = $value; break; }
        }
    }
    if (!$tokenBrut) {
        foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $key) {
            if (!empty($_SERVER[$key])) { $tokenBrut = $_SERVER[$key]; break; }
        }
    }

    if ($tokenBrut && preg_match('/Bearer\s+(.*)$/i', $tokenBrut, $m)) {
        $token   = trim($m[1]);
        $payload = verifyToken($token);

        if ($payload) {
            if ($pdo instanceof PDO) {
                if (!verifierSessionActive($pdo, $token, $payload['user_id'])) {
                    if (!$obligatoire) return null;
                    http_response_code(401);
                    echo json_encode([
                        'error' => 'Session expiree : une nouvelle connexion a ete effectuee sur ce compte'
                    ]);
                    exit();
                }
            }
            return $payload;
        }
    }

    if (!$obligatoire) return null;

    http_response_code(401);
    echo json_encode(['error' => 'Non autorise : token manquant ou invalide']);
    exit();
}

/** Verifie que l'utilisateur a l'un des roles autorises. */
function exigerRole($user, $roles) {
    $roles = (array)$roles;
    if (!in_array($user['user_role'] ?? '', $roles, true)) {
        jsonResponse(['error' => 'Acces non autorise pour votre role'], 403);
    }
}

/* ------------------------------------------------------------
   ZONES (classes)
   ------------------------------------------------------------
   Une zone est un texte libre mais normalise (espaces reduits).
   Les comparaisons sont insensibles a la casse pour eviter les
   doublons "Amphi A" / "amphi a".
   ------------------------------------------------------------ */
function normaliserZone($zone) {
    $zone = preg_replace('/\s+/u', ' ', trim((string)$zone));
    return $zone === null ? '' : $zone;
}

/** Zone (classe) d'un compte, ou chaine vide. */
function zoneUtilisateur($pdo, $userId) {
    $stmt = $pdo->prepare("SELECT zone FROM utilisateurs WHERE id = ?");
    $stmt->execute([(int)$userId]);
    $zone = $stmt->fetchColumn();
    return ($zone === false || $zone === null) ? '' : trim((string)$zone);
}

/** Renvoie l'orthographe existante de la zone, ou null si inconnue. */
function zoneCanonique($pdo, $zone) {
    $zone = normaliserZone($zone);
    if ($zone === '') {
        return null;
    }
    $stmt = $pdo->prepare(
        "SELECT zone FROM utilisateurs
         WHERE zone IS NOT NULL AND TRIM(zone) <> ''
           AND LOWER(TRIM(zone)) = LOWER(?)
         LIMIT 1"
    );
    $stmt->execute([$zone]);
    $valeur = $stmt->fetchColumn();
    return $valeur === false ? null : trim((string)$valeur);
}

/* ------------------------------------------------------------
   ELECTIONS DE CLASSE
   ------------------------------------------------------------
   Une election dont la zone est vide est une election generale
   (toute l'ecole). Sinon elle est reservee a la classe indiquee
   et organisee par le delegue de cette classe.
   ------------------------------------------------------------ */

/** Zone (classe) d'une election ; chaine vide = election generale. */
function zoneElection($election) {
    $zone = is_array($election) ? ($election['zone'] ?? '') : $election;
    return normaliserZone($zone);
}

/** Une election est-elle reservee a une classe ? */
function electionEstClasse($election) {
    return zoneElection($election) !== '';
}

/**
 * Droits d'acces a une election.
 *
 *   admin        : tout ;
 *   gestionnaire : uniquement les elections de SA classe ;
 *   controleur   : elections generales seulement ;
 *   electeur     : elections generales + celles de sa classe ;
 *   visiteur     : elections generales.
 *
 * $gestion = true exige le droit d'ORGANISER l'election
 * (creation, cloture, publication, candidats, suppression) :
 * seul l'admin et le delegue de la classe concerne y ont droit.
 */
function accesElection($pdo, $user, $election, $gestion = false) {
    $role  = $user['user_role'] ?? '';
    $zoneE = zoneElection($election);

    if ($role === 'admin') {
        return true;
    }

    // Pour organiser : uniquement le delegue de la classe concernee.
    if ($gestion) {
        if ($role !== 'gestionnaire' || $zoneE === '') {
            return false;
        }
        $maZone = zoneUtilisateur($pdo, (int)$user['user_id']);
        return $maZone !== '' && strcasecmp($zoneE, $maZone) === 0;
    }

    if ($role === 'gestionnaire') {
        if ($zoneE === '') {
            return false;
        }
        $maZone = zoneUtilisateur($pdo, (int)$user['user_id']);
        return $maZone !== '' && strcasecmp($zoneE, $maZone) === 0;
    }

    if ($role === 'electeur') {
        if ($zoneE === '') {
            return true;
        }
        $maZone = zoneUtilisateur($pdo, (int)$user['user_id']);
        return $maZone !== '' && strcasecmp($zoneE, $maZone) === 0;
    }

    // Controleur et visiteur anonyme : elections generales uniquement.
    return $zoneE === '';
}

/* ------------------------------------------------------------
   REPONSES / ENTREES JSON
   ------------------------------------------------------------ */
function jsonResponse($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit();
}

function getJsonBody() {
    $raw = file_get_contents('php://input');

    if ($raw === false || trim($raw) === '') {
        jsonResponse(['error' => 'Corps de requete vide. Un JSON est attendu.'], 400);
    }

    if (!mb_check_encoding($raw, 'UTF-8')) {
        $raw = mb_convert_encoding($raw, 'UTF-8', 'ISO-8859-1');
    }

    $data = json_decode($raw, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        jsonResponse([
            'error' => 'JSON invalide : ' . json_last_error_msg()
        ], 400);
    }

    if (!is_array($data)) {
        jsonResponse(['error' => 'Le corps de la requete doit etre un objet JSON'], 400);
    }

    return $data;
}

/* ------------------------------------------------------------
   MISE A JOUR PARTIELLE
   ------------------------------------------------------------
   Les boutons de l'interface n'envoient QUE le champ modifie.
   Cette fonction ne construit la clause SET qu'avec les champs
   reellement presents dans la requete (colonnes 'nullable' et
   'nullable_int' acceptent NULL).
   ------------------------------------------------------------ */
function miseAJourPartielle($pdo, $table, $donnees, $autorises, $id, $transformer = []) {
    $affectations = [];
    $params = [];

    foreach ($autorises as $cleJson => $colonneSql) {

        if (!array_key_exists($cleJson, $donnees)) {
            continue;
        }

        // Le nom de colonne vient d'une table de correspondance codee en
        // dur, mais on valide quand meme le format : aucune chaine
        // arbitraire ne doit pouvoir atteindre la clause SQL.
        $colonne = in_array($colonneSql, ['nullable', 'nullable_int', 'bool'], true)
            ? $cleJson
            : $colonneSql;
        if (!preg_match('/^[a-z_][a-z0-9_]*$/', $colonne)) {
            jsonResponse(['error' => 'Champ non autorise'], 400);
        }

        $valeur = $donnees[$cleJson];

        if (isset($transformer[$cleJson])) {
            $controle = call_user_func($transformer[$cleJson], $valeur, $donnees);
            if (!empty($controle['erreur'])) {
                jsonResponse(['error' => $controle['erreur']], 400);
            }
            $valeur = $controle['valeur'];
        }

        if ($colonneSql === 'nullable') {
            $valeur = is_string($valeur) ? trim($valeur) : $valeur;
            if ($valeur === '' || $valeur === null) {
                $affectations[] = $colonne . ' = NULL';
            } else {
                $affectations[] = $colonne . ' = ?';
                $params[] = $valeur;
            }
            continue;
        }

        if ($colonneSql === 'nullable_int') {
            if ($valeur === '' || $valeur === null || (int)$valeur <= 0) {
                $affectations[] = $colonne . ' = NULL';
            } else {
                $affectations[] = $colonne . ' = ?';
                $params[] = (int)$valeur;
            }
            continue;
        }

        // Booleen explicite
        if ($colonneSql === 'bool') {
            // PDO/pgsql en prepares natifs transforme le booleen PHP FALSE
            // en chaine vide, que PostgreSQL refuse. On envoie donc
            // explicitement 'true' ou 'false'.
            $booleen = filter_var($valeur, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($booleen === null) {
                jsonResponse(['error' => "Valeur booleenne invalide pour le champ '$cleJson'"], 400);
            }
            $affectations[] = $colonne . ' = ?';
            $params[] = $booleen ? 'true' : 'false';
            continue;
        }

        // Colonne ordinaire : une chaine vide est ignoree
        if (is_string($valeur) && trim($valeur) === '') {
            continue;
        }

        $affectations[] = $colonne . ' = ?';
        $params[] = $valeur;
    }

    if (!$affectations) {
        jsonResponse(['success' => true, 'message' => 'Aucune modification a apporter'], 200);
    }

    $params[] = $id;
    $sql = "UPDATE $table SET " . implode(', ', $affectations) . " WHERE id = ?";

    $pdo->prepare($sql)->execute($params);
}

/* ------------------------------------------------------------
   JOURNAL D'AUDIT
   ------------------------------------------------------------ */
function journaliser($pdo, $matricule, $action, $details = null) {
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO journal (matricule, action, details, adresse_ip) VALUES (?, ?, ?, ?)"
        );
        $stmt->execute([
            $matricule,
            $action,
            is_array($details) ? json_encode($details, JSON_UNESCAPED_UNICODE) : $details,
            $_SERVER['REMOTE_ADDR'] ?? null
        ]);
    } catch (Exception $e) {
        // Le journal ne doit jamais faire echouer l'action principale
        error_log('[DigitalElect] journal: ' . $e->getMessage());
    }
}

/* ------------------------------------------------------------
   LIMITATION DES TENTATIVES DE CONNEXION
   ------------------------------------------------------------
   On s'appuie sur le journal d'audit (deja present dans toutes
   les installations) : 10 echecs maxi par couple IP + matricule
   sur 15 minutes. Aucune table supplementaire n'est necessaire.
   ------------------------------------------------------------ */
function connexionTropDeTentatives($pdo, $matricule) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if ($ip === '') {
        return false;
    }
    try {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM journal
             WHERE action = 'connexion_echouee'
               AND adresse_ip = ?
               AND matricule = ?
               AND date_action > NOW() - INTERVAL '15 minutes'"
        );
        $stmt->execute([$ip, $matricule]);
        return ((int)$stmt->fetchColumn()) >= MAX_TENTATIVES_CONNEXION;
    } catch (Exception $e) {
        error_log('[DigitalElect] throttle: ' . $e->getMessage());
        return false;
    }
}

function reinitialiserTentatives($pdo, $matricule) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if ($ip === '') {
        return;
    }
    try {
        $pdo->prepare(
            "DELETE FROM journal
             WHERE action = 'connexion_echouee'
               AND adresse_ip = ?
               AND matricule = ?"
        )->execute([$ip, $matricule]);
    } catch (Exception $e) {
        error_log('[DigitalElect] throttle reset: ' . $e->getMessage());
    }
}

/* ------------------------------------------------------------
   LIMITATION GENERIQUE DE REQUETES
   ------------------------------------------------------------
   Compteur par fenetre glissante, stocke dans des petits
   fichiers verrouilles (api/cache, interdit au web). Sert a
   proteger les endpoints appeles automatiquement (synchro
   temps reel, sauvegardes) sans ecrire dans la base a chaque
   requete. Si le dossier n'est pas accessible en ecriture, la
   limitation est simplement ignoree : l'application continue
   de fonctionner.

   $sujet permet de compter par utilisateur (jeton) plutot que
   par adresse IP, utile derriere un meme routeur.
   ------------------------------------------------------------ */
function limiterRequetes($nom, $max, $fenetre, $sujet = null) {
    static $verifie = [];

    $ip    = $_SERVER['REMOTE_ADDR'] ?? 'inconnu';
    $sujet = $sujet !== null ? (string)$sujet : $ip;
    $cle   = hash('sha256', $nom . '|' . $sujet);
    $dossier = __DIR__ . '/cache';

    if (!is_dir($dossier)) {
        @mkdir($dossier, 0700, true);
    }
    if (!is_dir($dossier) || !is_writable($dossier)) {
        return; // pas de stockage : on ne bloque pas le service
    }

    $fichier = $dossier . '/rl_' . $cle . '.json';
    $fp = @fopen($fichier, 'c+');
    if (!$fp) {
        return;
    }

    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        return;
    }

    $contenu = stream_get_contents($fp);
    $etat = json_decode((string)$contenu, true);
    if (!is_array($etat) || !isset($etat['debut'], $etat['n'])) {
        $etat = ['debut' => time(), 'n' => 0];
    }

    $maintenant = time();
    if (($maintenant - (int)$etat['debut']) >= $fenetre) {
        $etat = ['debut' => $maintenant, 'n' => 0];
    }

    $etat['n'] = (int)$etat['n'] + 1;

    if ($etat['n'] > $max) {
        $attente = max(1, $fenetre - ($maintenant - (int)$etat['debut']));
        flock($fp, LOCK_UN);
        fclose($fp);
        header('Retry-After: ' . $attente);
        jsonResponse([
            'error' => 'Trop de requetes. Reessayez dans quelques instants.'
        ], 429);
    }

    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($etat));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    // Menage occasionnel des compteurs inactifs (1 chance sur 200).
    if (!isset($verifie[$nom]) && random_int(1, 200) === 1) {
        $verifie[$nom] = true;
        foreach (glob($dossier . '/rl_*.json') ?: [] as $ancien) {
            if (@filemtime($ancien) < time() - 3600) {
                @unlink($ancien);
            }
        }
    }
}

/* ------------------------------------------------------------
   HASH ANONYME DU VOTE
   ------------------------------------------------------------
   hash_vote = HMAC-SHA256(election_id | matricule, SECRET)
   -> deterministe : UNIQUE garantit 1 matricule = 1 vote / election
   -> sans le secret, impossible de relier un bulletin a un etudiant.
   ------------------------------------------------------------ */
function calculerHashVote($electionId, $matricule) {
    return hash_hmac('sha256', $electionId . '|' . $matricule, JWT_SECRET);
}

/* ------------------------------------------------------------
   CHRONO DE VOTE / VOTES NULS
   ------------------------------------------------------------
   Regle metier : un electeur qui ouvre le bulletin mais ne vote
   pas dans les DUREE_VOTE secondes voit son bulletin declare
   NUL (bulletin blanc). Le delai est verifie cote SERVEUR a
   partir de la table ouvertures : recharger la page ne remet
   pas le chrono a zero.
   ------------------------------------------------------------ */

/**
 * Enregistre un vote NUL (bulletin blanc) pour un electeur, si
 * aucun vote n'existe deja pour cette election.
 * @return bool true si un bulletin nul a ete insere.
 */
function enregistrerVoteNul($pdo, $electionId, $userId, $matricule, $journaliser = true) {
    $hash = calculerHashVote($electionId, $matricule);

    $stmt = $pdo->prepare("SELECT id FROM votes WHERE election_id = ? AND hash_vote = ?");
    $stmt->execute([$electionId, $hash]);
    if ($stmt->fetch()) {
        return false; // deja vote (valide ou nul)
    }

    $pdo->prepare("INSERT INTO votes (election_id, candidat_id, hash_vote) VALUES (?, NULL, ?)")
        ->execute([$electionId, $hash]);
    $pdo->prepare("UPDATE utilisateurs SET a_vote = TRUE WHERE id = ?")->execute([$userId]);

    // Emargement par election : permet les statistiques par zone sans
    // reveler le choix de l'electeur (aucun lien avec le candidat).
    $pdo->prepare(
        "INSERT INTO emargements (utilisateur_id, election_id) VALUES (?, ?)
         ON CONFLICT (utilisateur_id, election_id) DO NOTHING"
    )->execute([$userId, $electionId]);

    if ($journaliser) {
        journaliser($pdo, $matricule, 'vote_nul', ['election_id' => $electionId]);
    }
    return true;
}

/**
 * Balaye une election : tout electeur dont le delai est depasse
 * et qui n'a pas vote recoit un vote NUL.
 * @return int nombre de bulletins nuls crees.
 */
function sweepVotesNuls($pdo, $electionId) {
    $duree = (int)DUREE_VOTE; // constante interne : interpolation sure
    $stmt = $pdo->prepare(
        "SELECT o.utilisateur_id, u.matricule
         FROM ouvertures o
         JOIN utilisateurs u ON u.id = o.utilisateur_id
         WHERE o.election_id = ?
           AND o.debut_at < NOW() - INTERVAL '$duree seconds'"
    );
    $stmt->execute([$electionId]);

    $crees = 0;
    foreach ($stmt->fetchAll() as $row) {
        if (enregistrerVoteNul($pdo, $electionId, (int)$row['utilisateur_id'], (string)$row['matricule'], false)) {
            $crees++;
        }
    }
    return $crees;
}

/**
 * Ferme automatiquement les elections dont la date de fin est
 * depassee, puis enregistre les votes nuls des bulletins expires.
 */
function cloturerElectionsEchues($pdo) {
    $echecs = $pdo->query(
        "SELECT id FROM elections
         WHERE statut = 'en_cours'
           AND date_fin IS NOT NULL
           AND date_fin < NOW()"
    )->fetchAll();

    if (!$echecs) {
        return;
    }
    $upd = $pdo->prepare("UPDATE elections SET statut = 'terminee' WHERE id = ?");
    foreach ($echecs as $row) {
        $upd->execute([(int)$row['id']]);
        sweepVotesNuls($pdo, (int)$row['id']);
    }
}

/**
 * Valide une photo de candidat : data URL d'image, ou URL http(s).
 * Evite l'injection de schemas dangereux (javascript:, data:text/html).
 * @return string|null valeur nettoyee, ou null si vide/invalide.
 */
function validerPhoto($valeur) {
    $v = trim((string)$valeur);
    if ($v === '') {
        return null;
    }
    if (preg_match('#^data:image/(png|jpe?g|gif|webp);base64,[A-Za-z0-9+/=\s]+$#i', $v)) {
        return $v;
    }
    if (preg_match('#^https?://#i', $v) && mb_strlen($v) <= 1000) {
        return $v;
    }
    return null;
}
