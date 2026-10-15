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

$dbHost = $env('DB_HOST', '127.0.0.1');
$dbPort = $env('DB_PORT', '5432');
$dbName = $env('DB_NAME', 'iai_vote');
$dbUser = $env('DB_USER', 'postgres');
$dbPass = $env('DB_PASS', 'postgres');

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
}

define('DB_HOST', $dbHost);
define('DB_PORT', $dbPort);
define('DB_NAME', $dbName);
define('DB_USER', $dbUser);
define('DB_PASS', $dbPass);

// Secret servant a signer les jetons ET a calculer le hash anonyme
// des votes. En production, definir la variable d'environnement APP_SECRET.
define('APP_NAME', 'Digital-Elect');
define('JWT_SECRET', $env('APP_SECRET', 'iai-vote-secret-a-changer-en-production-2025'));

// Duree de vote (chrono) en secondes. Au-dela, le bulletin est NUL.
define('DUREE_VOTE', 60);

// Headers CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=UTF-8');

// En-tetes de securite (defense en profondeur)
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
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
        http_response_code(500);
        echo json_encode([
            'error' => 'Erreur de connexion a la base de donnees',
            'detail' => $e->getMessage()
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
    $stmt = $pdo->prepare(
        "SELECT id FROM sessions WHERE utilisateur_id = ? AND token = ? AND expire_at > NOW()"
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
                $affectations[] = $cleJson . ' = NULL';
            } else {
                $affectations[] = $cleJson . ' = ?';
                $params[] = $valeur;
            }
            continue;
        }

        if ($colonneSql === 'nullable_int') {
            if ($valeur === '' || $valeur === null || (int)$valeur <= 0) {
                $affectations[] = $cleJson . ' = NULL';
            } else {
                $affectations[] = $cleJson . ' = ?';
                $params[] = (int)$valeur;
            }
            continue;
        }

        // Booleen explicite
        if ($colonneSql === 'bool') {
            $affectations[] = $cleJson . ' = ?';
            $params[] = (bool)$valeur;
            continue;
        }

        // Colonne ordinaire : une chaine vide est ignoree
        if (is_string($valeur) && trim($valeur) === '') {
            continue;
        }

        $affectations[] = $colonneSql . ' = ?';
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
