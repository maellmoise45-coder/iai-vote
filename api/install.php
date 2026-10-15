<?php
/**
 * ============================================================
 *  Installation de la base - /api/install.php
 * ============================================================
 *  A executer UNE SEULE FOIS, apres avoir configure la
 *  connexion PostgreSQL (variables d'environnement ou valeurs
 *  par defaut de config.php).
 *
 *  Cree les tables et les donnees de demonstration, uniquement
 *  si la base n'est pas deja initialisee.
 *
 *  Exemple : http://localhost/iai-vote/api/install.php
 * ============================================================
 */
require_once 'config.php';

header('Content-Type: text/plain; charset=UTF-8');

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
        echo "ERREUR : impossible de lire database/schema.sql\n";
        exit(1);
    }

    $pdo->exec($schema);

    echo "SUCCES : base initialisee.\n\n";
    echo "Comptes disponibles :\n";
    echo "  administrateur : admin / 1234Zeus\n";
    echo "  gestionnaire   : gestionnaire / gestion123\n";
    echo "  electeurs      : ETU22A001, ETU22A002, ETU22A003 / electeur123\n\n";
    echo "IMPORTANT : supprimez ou protegez ce fichier (install.php) apres installation.\n";

} catch (Exception $e) {
    http_response_code(500);
    echo "ERREUR : " . $e->getMessage() . "\n";
}
