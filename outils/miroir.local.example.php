<?php
/**
 * ============================================================
 *  Digital-Elect - identifiants du miroir local (EXEMPLE)
 * ============================================================
 *  Copiez ce fichier en outils/miroir.local.php puis adaptez
 *  les valeurs. Le fichier reel est ignore par Git : il ne doit
 *  JAMAIS etre versionne ni partage.
 *
 *  La connexion "source" est utilisee en LECTURE SEULE : le
 *  script refuse d'ecrire dans la base de production.
 * ============================================================
 */
return [
    // --- Production (alwaysdata) : lecture seule -----------------
    'source' => [
        'dsn'  => 'pgsql:host=postgresql-digitalelect.alwaysdata.net;port=5432;'
                . 'dbname=digitalelect_vote;sslmode=require',
        'user' => 'digitalelect',
        'pass' => 'MOT_DE_PASSE_PRODUCTION',
    ],

    // --- Base locale (XAMPP) : destination -----------------------
    'cible' => [
        'dsn'  => 'pgsql:host=127.0.0.1;port=5432;dbname=iai_vote',
        'user' => 'postgres',
        'pass' => 'postgres',
    ],

    // Dossier des instantanes JSON (relatif a la racine du projet).
    'snapshots' => 'sauvegardes',

    // Nombre d'instantanes conserves.
    'retention' => 14,
];
