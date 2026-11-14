<?php
/**
 * ============================================================
 *  Digital-Elect — exemple de configuration locale
 * ============================================================
 *  Copiez ce fichier en « config.local.php » sur le serveur
 *  (même dossier que config.php), puis remplacez les valeurs.
 *
 *  IMPORTANT :
 *   - config.local.php ne doit JAMAIS être versionné ni envoyé
 *     sur GitHub (il est déjà exclu par .gitignore) ;
 *   - ce fichier d'exemple ne contient aucun secret réel ;
 *   - après l'installation, supprimez la ligne INSTALL_TOKEN
 *     (et le fichier api/install.php).
 * ============================================================
 */

// --- Base de données PostgreSQL -------------------------------
putenv('DB_HOST=127.0.0.1');
putenv('DB_PORT=5432');
putenv('DB_NAME=iai_vote');
putenv('DB_USER=postgres');
putenv('DB_PASS=changez-moi');

// --- Secret applicatif ----------------------------------------
// Sert à signer les jetons de session ET à calculer le hash
// anonyme des votes. Générez une valeur longue et aléatoire :
//   php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
// Si ce secret change, les sessions et les hash de vote existants
// deviennent invalides.
putenv('APP_SECRET=changez-moi-par-64-caracteres-aleatoires');

// --- Jeton d'installation (api/install.php) -------------------
// Obligatoire pour lancer install.php. À supprimer une fois
// l'installation terminée.
putenv('INSTALL_TOKEN=changez-moi-jeton-temporaire');

// --- Origine CORS (facultatif) --------------------------------
// À ne renseigner QUE si le frontend et l'API sont sur deux
// domaines différents. Vide = même origine uniquement.
// putenv('APP_ORIGIN=https://vote.exemple.cm');
