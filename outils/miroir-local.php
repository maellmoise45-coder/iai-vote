<?php
/**
 * ============================================================
 *  Digital-Elect - miroir local de la base de production
 * ============================================================
 *  Recopie la base PostgreSQL de production vers la base locale
 *  (XAMPP) en LECTURE SEULE cote production, puis ecrit un
 *  instantane JSON dans sauvegardes/.
 *
 *  Utilisation (ligne de commande uniquement) :
 *    php outils/miroir-local.php
 *    php outils/miroir-local.php --dry-run
 *    php outils/miroir-local.php --silencieux
 *
 *  SECURITE
 *  --------
 *  - la session PostgreSQL source est forcee en lecture seule :
 *    le script ne peut rien modifier en production ;
 *  - identifiants dans outils/miroir.local.php (ignore par Git) ;
 *  - verrou pour eviter deux synchronisations simultanees ;
 *  - instantanes JSON en droits 0600, rotation automatique ;
 *  - aucune donnee sensible affichee dans la console.
 * ============================================================
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Ce script s'execute en ligne de commande uniquement.\n");
}

$racine = dirname(__DIR__);
$options = $argv ?? [];
$dryRun = in_array('--dry-run', $options, true);
$silencieux = in_array('--silencieux', $options, true);

/** Ecrit une ligne dans outils/miroir.log et sur la sortie standard. */
function journaliser(string $message, bool $silencieux = false): void
{
    $ligne = '[' . gmdate('Y-m-d H:i:s') . ' UTC] ' . $message . PHP_EOL;
    $fichier = __DIR__ . '/miroir.log';

    if (is_file($fichier) && (int)filesize($fichier) > 1048576) {
        @rename($fichier, $fichier . '.old');
    }
    @file_put_contents($fichier, $ligne, FILE_APPEND | LOCK_EX);

    if (!$silencieux) {
        echo $ligne;
    }
}

/** Termine le script avec une erreur explicite. */
function echec(string $message, bool $silencieux = false): void
{
    journaliser('ERREUR : ' . $message, $silencieux);
    fwrite(STDERR, 'ERREUR : ' . $message . PHP_EOL);
    exit(1);
}

$fichierConfig = __DIR__ . '/miroir.local.php';
if (!is_file($fichierConfig)) {
    echec(
        'outils/miroir.local.php introuvable. Copiez outils/miroir.local.example.php '
        . 'puis renseignez les identifiants.',
        $silencieux
    );
}

$config = require $fichierConfig;
if (!is_array($config)
    || !isset($config['source']['dsn'], $config['cible']['dsn'])
) {
    echec('Configuration du miroir invalide.', $silencieux);
}

$dossierSnapshots = $racine . '/' . trim((string)($config['snapshots'] ?? 'sauvegardes'), '/\\');
$retention = max(1, (int)($config['retention'] ?? 14));

// Tables copiees (ordre de restauration respectant les cles etrangeres).
$tables = [
    'utilisateurs',
    'elections',
    'candidats',
    'votes',
    'emargements',
    'ouvertures',
    'invitations',
    'journal',
];

// Tables videes mais non copiees : les sessions actives restent locales
// (aucun jeton de production n'est recopie sur la machine).
$tablesVides = ['sessions'];

/* ---------- Verrou : une seule synchronisation a la fois ---------- */
$fpVerrou = @fopen(__DIR__ . '/miroir.lock', 'c');
if (!$fpVerrou) {
    echec('Impossible de creer le fichier de verrou.', $silencieux);
}
if (!flock($fpVerrou, LOCK_EX | LOCK_NB)) {
    journaliser('Une synchronisation est deja en cours : abandon.', $silencieux);
    fclose($fpVerrou);
    exit(0);
}

$debut = microtime(true);

try {
    /* ---------- 1. Lecture de la production (lecture seule) ---------- */
    $source = new PDO(
        (string)$config['source']['dsn'],
        (string)($config['source']['user'] ?? ''),
        (string)($config['source']['pass'] ?? ''),
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT            => 25,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    // Defense en profondeur : toute ecriture est refusee par PostgreSQL.
    $source->exec('SET default_transaction_read_only = on');
    $source->exec("SET statement_timeout = '60s'");
    $source->exec('BEGIN TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');

    $colonnes = [];
    $types = [];
    $stmtColonnes = $source->prepare(
        "SELECT column_name, data_type
         FROM information_schema.columns
         WHERE table_schema = 'public'
           AND table_name = ?
           AND is_generated <> 'ALWAYS'
         ORDER BY ordinal_position"
    );

    foreach (array_merge($tables, $tablesVides) as $table) {
        $stmtColonnes->execute([$table]);
        $infos = $stmtColonnes->fetchAll(PDO::FETCH_ASSOC);
        if (!$infos) {
            throw new RuntimeException("Table \"$table\" introuvable en production.");
        }
        $colonnes[$table] = array_column($infos, 'column_name');
        $types[$table] = array_column($infos, 'data_type', 'column_name');
    }

    $donnees = [];
    foreach ($tables as $table) {
        // Nom de table issu d'une liste blanche codee en dur.
        $donnees[$table] = $source->query("SELECT * FROM $table ORDER BY id")->fetchAll();
    }

    $source->exec('COMMIT');
    $source = null; // ferme la connexion de production

    $resume = [];
    foreach ($tables as $table) {
        $resume[] = $table . '=' . count($donnees[$table]);
    }

    if ($dryRun) {
        journaliser('Simulation OK : ' . implode(' ', $resume), $silencieux);
        exit(0);
    }

    /* ---------- 2. Ecriture dans la base locale ---------- */
    $cible = new PDO(
        (string)$config['cible']['dsn'],
        (string)($config['cible']['user'] ?? ''),
        (string)($config['cible']['pass'] ?? ''),
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    // Verifie que la base locale est a jour avant de la vider.
    $stmtLocal = $cible->prepare(
        "SELECT column_name
         FROM information_schema.columns
         WHERE table_schema = 'public' AND table_name = ?
         ORDER BY ordinal_position"
    );
    foreach (array_merge($tables, $tablesVides) as $table) {
        $stmtLocal->execute([$table]);
        $local = $stmtLocal->fetchAll(PDO::FETCH_COLUMN);
        if (!$local) {
            throw new RuntimeException(
                "Table \"$table\" absente de la base locale. "
                . 'Lancez database/schema.sql puis les migrations.'
            );
        }
        $manquantes = array_diff($colonnes[$table], $local);
        if ($manquantes) {
            throw new RuntimeException(
                "Colonnes manquantes en local (table $table) : "
                . implode(', ', $manquantes) . '. Appliquez les migrations.'
            );
        }
    }

    $cible->beginTransaction();
    try {
        $listeTruncate = implode(', ', array_merge($tables, $tablesVides));
        $cible->exec("TRUNCATE TABLE $listeTruncate RESTART IDENTITY CASCADE");

        foreach ($tables as $table) {
            $lignes = $donnees[$table];
            if (!$lignes) {
                continue;
            }

            $cols = $colonnes[$table];
            $noms = implode(', ', array_map(static fn($c) => '"' . $c . '"', $cols));
            $marques = implode(', ', array_fill(0, count($cols), '?'));
            $insert = $cible->prepare("INSERT INTO $table ($noms) VALUES ($marques)");

            foreach ($lignes as $ligne) {
                $valeurs = [];
                foreach ($cols as $colonne) {
                    $valeur = $ligne[$colonne] ?? null;

                    // PDO envoie false sous forme de chaine vide, que
                    // PostgreSQL refuse pour un booleen : on convertit.
                    if ($valeur !== null && ($types[$table][$colonne] ?? '') === 'boolean') {
                        $valeur = in_array($valeur, [true, 1, '1', 't', 'true'], true) ? 't' : 'f';
                    }
                    $valeurs[] = $valeur;
                }
                $insert->execute($valeurs);
            }
        }

        // Recalage des sequences sur le maximum des identifiants copies.
        foreach (array_merge($tables, $tablesVides) as $table) {
            $cible->exec(
                "SELECT setval(
                    pg_get_serial_sequence('$table', 'id'),
                    COALESCE((SELECT MAX(id) FROM $table), 1),
                    (SELECT COUNT(*) FROM $table) > 0
                )"
            );
        }

        $cible->commit();
    } catch (Throwable $e) {
        if ($cible->inTransaction()) {
            $cible->rollBack();
        }
        throw $e;
    }
    $cible = null;

    /* ---------- 3. Instantane JSON local ---------- */
    if (!is_dir($dossierSnapshots)) {
        @mkdir($dossierSnapshots, 0700, true);
    }
    if (!is_dir($dossierSnapshots) || !is_writable($dossierSnapshots)) {
        throw new RuntimeException('Dossier de snapshots inaccessible : ' . $dossierSnapshots);
    }

    $document = [
        'application' => 'Digital-Elect',
        'version'     => '1.4',
        'type'        => 'miroir-local',
        'cree_le'     => gmdate('c'),
        'empreinte'   => hash('sha256', json_encode($donnees, JSON_UNESCAPED_UNICODE)),
        'tables'      => $donnees,
    ];

    $json = json_encode(
        $document,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE
    );
    if ($json === false) {
        throw new RuntimeException('Encodage JSON impossible.');
    }

    $nom = 'miroir-local-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.json';
    $chemin = $dossierSnapshots . '/' . $nom;

    if (@file_put_contents($chemin, $json, LOCK_EX) !== strlen($json)) {
        @unlink($chemin);
        throw new RuntimeException('Ecriture du snapshot incomplete.');
    }
    @chmod($chemin, 0600);

    // Rotation : on garde les N instantanes les plus recents.
    $liste = glob($dossierSnapshots . '/miroir-local-*.json') ?: [];
    usort($liste, static fn($a, $b) => filemtime($b) <=> filemtime($a));
    foreach (array_slice($liste, $retention) as $ancien) {
        @unlink($ancien);
    }

    journaliser(
        'OK ' . implode(' ', $resume)
        . sprintf(' (%.1fs, snapshot %s)', microtime(true) - $debut, $nom),
        $silencieux
    );
} catch (Throwable $e) {
    echec($e->getMessage(), $silencieux);
} finally {
    flock($fpVerrou, LOCK_UN);
    fclose($fpVerrou);
}
