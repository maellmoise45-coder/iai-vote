<?php
/**
 * ============================================================
 *  Installation de la base - /api/install.php
 * ============================================================
 *  A executer UNE SEULE FOIS, apres avoir configure la
 *  connexion PostgreSQL (config.local.php ou variables
 *  d'environnement).
 *
 *  SECURITE : ce script exige un jeton secret defini par
 *  INSTALL_TOKEN (config.local.php ou variable d'environnement).
 *  Sans jeton valide, il repond 403 et ne fait rien.
 *
 *  Exemple :
 *    curl -H "X-Install-Token: mon-jeton" \
 *         http://localhost/iai-vote/api/install.php
 *
 *  IMPORTANT : apres installation, supprimez ce fichier ou
 *  interdisez-y l'acces dans Apache.
 * ============================================================
 */
require_once 'config.php';

header('Content-Type: text/plain; charset=UTF-8');
header('Cache-Control: no-store');

/* ---- Controle du jeton d'installation ---------------------- */
$jeton = $_SERVER['HTTP_X_INSTALL_TOKEN'] ?? ($_GET['token'] ?? '');
if (INSTALL_TOKEN === '' || $jeton === '' || !hash_equals(INSTALL_TOKEN, (string)$jeton)) {
    http_response_code(403);
    echo "Acces refuse.\n";
    echo "Definissez INSTALL_TOKEN puis fournissez-le via l'en-tete X-Install-Token.\n";
    exit();
}

try {
    $pdo = getDB();

    // La base est-elle deja installee ?
    $existe = $pdo->query("SELECT to_regclass('public.utilisateurs')")->fetchColumn();

    if ($existe) {
        echo "OK : la base est deja installee (table 'utilisateurs' presente).\n";
        echo "Aucune action necessaire.\n";
        exit();
    }

    $schema = file_get_contents(__DIR__ . '/../database/schema.sql');
    if ($schema === false) {
        http_response_code(500);
        echo "ERREUR : impossible de lire database/schema.sql\n";
        exit();
    }

    $pdo->exec($schema);

    echo "SUCCES : base initialisee.\n\n";
    echo "Les comptes de demonstration de database/schema.sql sont actifs.\n";
    echo "Connectez-vous, puis changez IMMEDIATEMENT les mots de passe\n";
    echo "de l'administrateur, du gestionnaire et du controleur.\n\n";
    echo "IMPORTANT : supprimez ou protegez ce fichier (install.php) apres installation.\n";

} catch (Exception $e) {
    // Detail dans le journal serveur uniquement, jamais dans la reponse.
    error_log('[DigitalElect] install: ' . $e->getMessage());
    http_response_code(500);
    echo "ERREUR : l'installation a echoue. Consultez le journal du serveur.\n";
}
