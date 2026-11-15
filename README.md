# Digital-Elect V1.4

Application de **vote électronique** pour une école : les étudiants votent avec
leur **matricule**, un **seul vote par matricule et par élection**, et les
bulletins sont **anonymes**.

> Version **1.4** : le site est **dynamique en temps réel** (plus besoin de
> rafraîchir pour voir les résultats et l'activité), avec une **sauvegarde
> automatique** de la base et un **miroir local** sécurisé de la base en ligne
> sur la machine, en lecture seule.
>
> La V1.3 avait apporté les **élections de classe** (le délégué organise pour sa
> classe, résultats visibles uniquement par la classe avec photos des candidats).

## Liens

- Application en ligne : <https://digitalelect.alwaysdata.net>
- Dépôt GitHub : <https://github.com/maellmoise45-coder/iai-vote>

## Stack technique

| Couche    | Technologie                              |
|-----------|------------------------------------------|
| Backend   | PHP natif (aucun framework)              |
| Base      | PostgreSQL (PDO, requêtes préparées)     |
| Frontend  | HTML / CSS / JavaScript vanilla          |
| Serveur   | Apache (XAMPP en local, alwaysdata en ligne) |

## Fonctionnalités

- **Connexion par matricule + mot de passe**.
- **Connexion unique** : une nouvelle connexion invalide les sessions précédentes.
- **Rôles** : `admin`, `gestionnaire`, `controleur`, `electeur`.
- **Comptes créés par l'administration** ou **auto-inscription par lien de classe**.
- **Délégué de classe (rôle `gestionnaire`)** : il ne crée et ne modifie aucun
  profil. Il consulte uniquement les élèves de **sa classe** (colonne `zone`) et
  peut retirer un profil qui ne correspond pas à sa liste. Il génère le **lien
  d'inscription de sa classe** et organise les **élections de classe**. L'API
  applique ces règles, pas seulement l'interface.
- **Élections de classe** : le délégué crée un scrutin **pour sa seule classe**
  (la zone est forcée côté serveur, jamais modifiable). L'élection, ses
  candidats et ses résultats ne sont visibles que par **lui et les étudiants de
  sa classe** : les autres électeurs, les contrôleurs et les visiteurs reçoivent
  `404`/`403`. Les résultats gardent le **diagramme à bandes avec photos** et
  sont publiés explicitement par le délégué. Un admin voit et gère toutes les
  élections, générales comme de classe.
- **Deux portées d'élection** : `elections.zone` vide = élection **générale**
  (toute l'école) ; sinon = élection **de classe**, réservée à cette zone.
- **Auto-inscription par lien de classe** : un lien à jeton (64 caractères
  hexadécimaux) porte la classe. La page `inscription.html?token=…` impose cette
  classe (préremplie, non modifiable) : l'étudiant saisit ses informations et
  son mot de passe, puis est connecté automatiquement. Un seul lien actif par
  classe, valable 90 jours ; générer un nouveau lien désactive l'ancien.
  Les comptes ainsi créés sont marqués `auto_inscrit`.
- **Contrôleurs en consultation seule** : suivi du vote en direct et journal
  d'audit, sans aucune action de gestion.
- **Vote unique par matricule et par élection** — contrainte `UNIQUE` +
  transaction `FOR UPDATE`.
- **Vote anonyme** : aucun matricule dans la table `votes`. Le lien technique
  est `hash_vote = HMAC(election_id | matricule, APP_SECRET)`.
- **Aucun choix individuel exposé** : l'API ne renvoie que des agrégats
  (résultats par candidat, participation par zone). L'ancien listing des
  bulletins a été supprimé.
- **Résultats publiés manuellement** : jamais automatiques. Après clôture,
  l'organisateur clique sur **Résultats → Publier**. Avant publication, les
  compteurs (`nb_votes`, `nombre_voix`) sont retirés des réponses publiques.
  Pour une élection de classe, la publication ne concerne que la classe.
- **Photo du candidat** : redimensionnée dans le navigateur puis stockée en base
  (elle survit à un redéploiement). Seules les images sont acceptées.
- **Résultats en diagramme à bandes** : un axe vertical gradué (pas 1/2/5
  lisibles), une grille horizontale et une barre verticale pleine par candidat,
  avec la valeur au-dessus et le nom + photo sous l'axe. Le candidat en tête est
  mis en avant. Les pourcentages sont calculés sur les **votants** (bulletins
  exprimés, nuls compris). Les **bulletins nuls** apparaissent en gris rayé.
  Affichage 100 % CSS/JS, sans bibliothèque externe.
- **Vote nul volontaire (bulletin blanc)** : un bouton dédié sous les candidats
  ouvre une confirmation. Le bulletin est comptabilisé comme nul (`candidat_id
  NULL`), sans donner de voix à un candidat ; l'électeur reste connecté et voit
  le direct. Le nul **chrono** (temps écoulé) reste inchangé et déconnecte.
- **Temps réel sans rafraîchissement manuel** : les pages (tableau de bord,
  résultats publics/privés, suivi live, espace électeur, journal contrôleur) se
  mettent à jour automatiquement. Un moteur léger (`js/temps-reel.js`) interroge
  `api/sync.php` qui renvoie uniquement une **empreinte** calculée dans le
  périmètre de l'utilisateur ; les données ne sont rechargées que si l'empreinte
  change. L'onglet masqué est mis en pause et se resynchronise au retour,
  avec temporisation progressive en cas d'erreur réseau.
- **Résultats en direct après le vote** : pendant un scrutin `en_cours`, un
  électeur qui a déjà voté voit le diagramme se mettre à jour en temps réel
  (l'API `resultats.php` vérifie le bulletin via `hash_vote`). Avant le vote,
  l'accès reste refusé. Les photos sont téléchargées une seule fois puis
  réutilisées : seuls les compteurs sont redemandés (`compact=1`).
- **Chrono de vote (1 minute) vérifié côté serveur** : l'ouverture du bulletin
  est enregistrée dans la table `ouvertures`. Recharger la page ne remet pas le
  compteur à zéro. À l'expiration, le bulletin est **NUL** et l'électeur est
  déconnecté.
- **Votes nuls** comptés séparément dans les résultats, le direct et le suivi
  live (bulletins blancs volontaires et bulletins expirés).
- **Clôture automatique** à la date de fin ; **clôture manuelle** protégée par
  le mot de passe de l'organisateur connecté (admin ou délégué).
- **Changement de mot de passe** : invalide immédiatement toutes les sessions
  du compte (`reconnecter: true`).
- **Blocage immédiat d'un compte** : la désactivation coupe les sessions en
  cours et empêche toute reconnexion.
- **Journal d'audit** des actions sensibles (connexions, votes, publications…).
- **Suivi live par zone** (admin et contrôleurs ; pour les contrôleurs,
  élections générales uniquement) : inscrits, votants et taux de participation
  par amphi/salle, avec le même diagramme à bandes.
- **Sauvegarde automatique sécurisée** : l'API `api/sauvegarde.php` (admin
  uniquement) produit un instantané JSON complet de la base (hors sessions). En
  production le fichier est écrit **hors de la racine web** ; en local il est
  stocké dans `sauvegardes/` protégé par `.htaccess` (refus d'accès web). Noms
  aléatoires, droits `0600`, rotation (14 fichiers), téléchargement authentifié.
  L'onglet **Sauvegarde** du tableau de bord déclenche aussi une sauvegarde
  automatique si la dernière date de plus de 24 h.
- **Miroir local de la base en ligne** : `outils/miroir-local.php` (CLI) se
  connecte à PostgreSQL de production en **lecture seule** (`default_transaction_read_only`),
  copie les données dans la base locale `iai_vote` en transaction, puis écrit un
  instantané JSON local. La base en ligne n'est **jamais modifiée**. Identifiants
  dans `outils/miroir.local.php` (ignoré par Git). Tâche planifiée fournie
  (toutes les 3 h, reprise si la machine était éteinte) et lancement manuel via
  `sauvegarder-local.bat`.
- **Interface responsive** : palette bleue, logo Digital-Elect, favicon,
  tableaux maîtrisés sur mobile, mention « Créé par MOISE ».

## Arborescence

```
Digital-Elect/
├── index.html                 Page de connexion
├── vote.html                  Espace électeur (vote)
├── dashboard.html             Espace admin / délégué de classe
├── controle.html              Espace contrôleur (suivi + journal)
├── resultats.html             Résultats publics
├── inscription.html           Inscription par lien de classe
├── css/style.css
├── images/
│   ├── logo.svg
│   └── favicon.svg
├── js/
│   ├── api.js                 Socle (appels API, jeton, session)
│   ├── temps-reel.js          Moteur de synchronisation temps réel
│   ├── login.js
│   ├── vote.js                Chrono + vote + direct après vote
│   ├── dashboard.js           Admin / délégué de classe (+ sauvegarde)
│   ├── suivi.js               Suivi live partagé
│   ├── controle.js            Espace contrôleur
│   ├── resultats.js           Résultats publics + direct
│   ├── inscription.js         Vérification du lien + auto-inscription
│   └── graphe.js              Diagramme en bandes (CSS/JS pur)
├── api/
│   ├── config.php             Configuration PDO + helpers sécurité
│   ├── config.local.example.php   Modèle de configuration locale
│   ├── sync.php               Empreinte de synchronisation temps réel
│   ├── sauvegarde.php         Sauvegarde JSON (admin, hors web)
│   ├── auth.php               Connexion / déconnexion / mot de passe
│   ├── electeurs.php          Gestion des comptes (règles par rôle)
│   ├── invitations.php        Liens d'inscription par classe
│   ├── inscription.php        Inscription publique (zone imposée)
│   ├── elections.php          Cycle de vie + publication
│   ├── candidats.php
│   ├── votes.php              Vote anonyme (transaction + hash)
│   ├── resultats.php          Agrégats publics / réservés
│   ├── statistiques.php
│   ├── suivi.php              Participation par zone + live
│   ├── journal.php
│   ├── install.php            Création initiale (protégée par jeton)
│   ├── cache/                 Compteurs de limitation (protégé .htaccess)
│   └── .htaccess              Protection config + transmission Authorization
├── outils/
│   ├── miroir-local.php       Copie production → local (lecture seule, CLI)
│   ├── miroir.local.example.php  Modèle d'identifiants (à copier)
│   ├── planifier-miroir.ps1   Crée la tâche planifiée (toutes les 3 h)
│   └── supprimer-tache-miroir.ps1
├── sauvegardes/               Sauvegardes/instantanés locaux (protégé .htaccess)
├── database/
│   ├── schema.sql             Schéma PostgreSQL complet
│   ├── migration_v2.sql       V1 → V2 (contrôleur, zones, émargements)
│   ├── migration_v3_securite.sql  V2 → V3 (index anti-force brute)
│   ├── migration_v4_invitations.sql  V3 → V4 (liens de classe)
│   └── migration_v5_elections_classe.sql  V4 → V5 (élections de classe)
├── sauvegarder-local.bat      Lancement manuel du miroir local
├── .htaccess                  En-têtes de sécurité + CSP
├── .env.example               Variables d'environnement (modèle)
├── Dockerfile
├── docker-entrypoint.sh
├── render.yaml
├── README.md
├── GUIDE-ALWAYSDATA.md
└── DEPLOIEMENT.md
```

## 1. Lancer en local (XAMPP + PostgreSQL)

### Prérequis

- XAMPP (Apache + PHP) avec les extensions PostgreSQL activées dans `php.ini` :

  ```ini
  extension=pdo_pgsql
  extension=pgsql
  ```

- Un serveur **PostgreSQL** en écoute sur `127.0.0.1:5432`.

### Étapes

1. Copiez le dossier dans `C:\xampp\htdocs\iai-vote` (ou créez une jonction
   Apache vers ce dossier).
2. Créez la base :

   ```bash
   createdb -U postgres iai_vote
   ```

   (ou `CREATE DATABASE iai_vote;` dans psql)
3. **Définissez le jeton d'installation**, soit dans `api/config.local.php`
   (copie de `api/config.local.example.php`), soit dans l'environnement :

   ```php
   putenv('INSTALL_TOKEN=mon-jeton-temporaire');
   ```

4. Lancez l'installation **avec le jeton** :

   ```bash
   curl -H "X-Install-Token: mon-jeton-temporaire" \
        http://localhost/iai-vote/api/install.php
   ```

   Sans jeton valide, `install.php` répond **403** et ne fait rien.

5. Ouvrez l'application :

   ```
   http://localhost/iai-vote/
   ```

6. **Après installation** : supprimez `api/install.php` (ou interdisez-y
   l'accès) et retirez `INSTALL_TOKEN` de `config.local.php`.

### Identifiants par défaut

| Rôle           | Matricule       | Mot de passe  |
|----------------|-----------------|---------------|
| Administrateur | `admin`         | `1234Zeus`    |
| Délégué (gestionnaire) | `gestionnaire`  | `gestion123`  |
| Contrôleur     | `controleur`    | `controle123` |
| Électeurs      | `ETU22A001` …   | `electeur123` |

> ⚠️ **Changez les mots de passe** (surtout l'admin) avant tout usage réel.
> Le compte `gestionnaire` doit avoir une **zone** (classe) renseignée par
> l'admin pour pouvoir générer un lien d'inscription.

### Mettre à jour une base existante

Sur une base déjà initialisée, **ne relancez pas `install.php`** : appliquez les
migrations dans l'ordre.

```bash
# V1 → V2 : rôle contrôleur, zones, émargements par élection
psql -U postgres -d iai_vote -f database/migration_v2.sql

# V2 → V3 : index de limitation des tentatives de connexion
psql -U postgres -d iai_vote -f database/migration_v3_securite.sql

# V3 → V4 : liens d'inscription par classe + colonne auto_inscrit
psql -U postgres -d iai_vote -f database/migration_v4_invitations.sql

# V4 → V5 : élections de classe (colonne elections.zone + index)
psql -U postgres -d iai_vote -f database/migration_v5_elections_classe.sql
```

Sans `psql` (poste Windows), exécutez le contenu des fichiers via un petit
script PHP/PDO. Les votes déjà enregistrés restent anonymes : ils ne peuvent pas
être rattachés rétroactivement à une zone (les émargements sont comptés à partir
de la V2). La migration V5 est **sans effet sur les élections existantes** :
leur `zone` reste vide, elles demeurent générales.

## 2. Déploiement en ligne

### alwaysdata (solution retenue — gratuite, sans carte bancaire)

> Guide complet : **[GUIDE-ALWAYSDATA.md](GUIDE-ALWAYSDATA.md)**

Résumé :

1. Créer la base PostgreSQL et noter les identifiants.
2. Envoyer les fichiers par **FTPS** dans `/www`.
3. Créer `api/config.local.php` (non versionné) à partir de
   `api/config.local.example.php` : base, `APP_SECRET`, `INSTALL_TOKEN`.
4. Lancer l'installation avec le jeton, puis **supprimer `install.php`** :

   ```bash
   curl -H "X-Install-Token: VOTRE_JETON" \
        https://digitalelect.alwaysdata.net/api/install.php
   ```

5. Appliquer les migrations si la base existe déjà.

### Render.com (alternative — exige une carte bancaire)

1. Poussez le projet sur GitHub.
2. Sur Render : **New → Blueprint**, sélectionnez le dépôt. `render.yaml` crée
   le service web et génère `APP_SECRET` ainsi que `INSTALL_TOKEN`.
3. Récupérez la valeur de `INSTALL_TOKEN` dans **Environment**, puis ouvrez :

   ```bash
   curl -H "X-Install-Token: VALEUR" \
        https://<votre-service>.onrender.com/api/install.php
   ```

4. Supprimez `api/install.php` du dépôt après l'installation.

### Docker manuel

```bash
docker build -t digitalelect .
docker run -p 10000:10000 \
  -e DATABASE_URL="postgres://user:pass@host:5432/dbname" \
  -e APP_SECRET="un-secret-long-et-aleatoire" \
  -e INSTALL_TOKEN="jeton-temporaire" \
  digitalelect
```

## 3. Sécurité

### Injection SQL

- **Requêtes préparées PDO** partout, aucune concaténation de valeurs.
- Paramètres numériques **castés en entier** (`(int)`).
- Noms de colonnes de `miseAJourPartielle()` validés par expression régulière
  et limités à une table de correspondance codée en dur.
- Liste blanche des rôles et des colonnes modifiables.

### XSS

- Tout contenu affiché passe par `echapper()` (HTML, attributs, textes).
- Aucun `<script>` inline : scripts dans des fichiers externes.
- **CSP** stricte : `script-src 'self'`, `object-src 'none'`,
  `frame-ancestors 'none'`, `base-uri 'self'`, `form-action 'self'`.
- Dates invalides rendues par `—` (pas d'insertion brute).
- Testé avec des titres, descriptions, noms et programmes contenant
  `<script>` et `<img onerror=…>` : aucun script exécuté.

### Authentification et sessions

- Mots de passe hachés (`password_hash` / `password_verify`).
- Jetons de session en base, purgés à chaque connexion.
- **Limitation** : 10 échecs maximum par couple IP + matricule sur 15 minutes
  (réponse `429` + `Retry-After`), journalisés dans `journal`.
- Changement de mot de passe : toutes les sessions du compte sont supprimées.
- Réinitialisation par un admin : sessions du compte cible supprimées.
- Message de connexion générique (ne révèle pas si le matricule existe).

### Permissions

- Contrôle **côté API** (`exigerRole`) pour chaque endpoint, pas seulement dans
  l'interface.
- Gestionnaire (délégué de classe) : électeurs de **sa zone** uniquement
  (liste, recherche, suppression d'un profil qui n'a pas voté), gestion du
  **lien d'inscription de sa classe** et des **élections de sa classe**
  (création, candidats, clôture, publication, suppression). Toute action sur une
  élection générale ou sur une autre classe est refusée (`403`).
- Auto-inscription : `inscription.php` n'accepte la zone que depuis le jeton
  d'invitation ; aucune zone n'est lue dans le corps de la requête.
- Contrôleur : `suivi.php` et `journal.php` en lecture seule, **élections
  générales uniquement**.
- Électeur : vote et état de son propre bulletin uniquement, élections générales
  et **celles de sa classe**.
- Admin : élections (générales et de classe), candidats, comptes, publication.
- Élection de classe : `zone` forcée côté serveur à la création par un délégué,
  jamais modifiable ensuite ; l'API `elections.php`, `candidats.php`,
  `resultats.php`, `votes.php` et `suivi.php` contrôle l'appartenance à la zone.

### Configuration et fichiers sensibles

- `api/config.local.php` et `.env` **non versionnés** et **non servis** par
  Apache (`api/.htaccess` + `.htaccess` racine).
- Fichiers `.sql`, `.md`, `.log`, `.bak`, `.example` interdits en HTTP.
- `install.php` **fail-closed** : sans `INSTALL_TOKEN` valide → `403`.
- Erreurs PDO : détail écrit dans le journal serveur, jamais renvoyé au client.
- CORS : aucun en-tête sauvage ; `APP_ORIGIN` doit être défini explicitement
  pour un déploiement frontend/API séparé.
- En-têtes : `X-Content-Type-Options`, `X-Frame-Options: DENY`,
  `Referrer-Policy`, `Permissions-Policy`, `Cross-Origin-Resource-Policy`,
  `Cross-Origin-Opener-Policy`, HSTS (en HTTPS), `Cache-Control: no-store`.

### Anonymat et intégrité du vote

- Aucun matricule dans `votes` ; seul un HMAC déterministe lie techniquement
  l'électeur à son bulletin sans révéler son choix.
- Contrainte `UNIQUE` + `SELECT … FOR UPDATE` contre le double vote concurrent.
- Chrono serveur dans `ouvertures` : recharger la page ne remet pas le temps.
- Aucun endpoint ne permet de lister les bulletins ou de retrouver un choix
  individuel.

## 4. Vérifications effectuées

- `php -l` : aucune erreur de syntaxe sur l'ensemble des fichiers PHP.
- Suite de tests API : connexions des 4 rôles, en-têtes de sécurité, fichiers
  sensibles, permissions gestionnaire/contrôleur/électeur, cycle d'élection,
  vote, double vote, résultats non publiés, injection SQL, limitation des
  tentatives, suppression d'élection.
- Tests V1.2 (liens de classe) : création/rotation/révocation d'un lien,
  inscription publique avec zone imposée, `auto_inscrit`, jeton invalide
  (`404`), création et modification refusées au délégué (`403`), suppression
  d'un électeur d'une autre zone (`403`), suppression d'un votant pendant le
  scrutin (`409`), résultats refusés avant le vote (`403`), direct après vote.
- Tests V1.2 (diagramme + vote nul) : vote nul volontaire (`201`,
  `volontaire: true`, ligne `candidat_id NULL`), second vote (`409`), nul chrono
  (`201`, déconnexion), résultats/direct/suivi avec barre grise « Bulletins
  nuls », axe gradué et grille horizontale, aucun débordement horizontal.
- Tests V1.3 (élections de classe) : création par le délégué avec zone forcée,
  élection invisible pour un électeur d'une autre classe (`404`), candidats et
  résultats refusés hors classe (`403`), contrôleur exclu du suivi d'une élection
  de classe (`403`), admin autorisé, vote de l'électeur de la classe accepté et
  vote hors classe refusé, clôture avec le mot de passe du délégué, publication
  limitée à la classe, non-régression des élections générales.
- Parcours navigateur : admin (colonne « Classe »), délégué de classe (onglet
  « Élections de classe », modales, résultats avec photos), contrôleur, électeur,
  auto-inscription, vote, bouton « Voter nul » avec confirmation, diagramme à
  bandes, résultats publics et direct, XSS stocké (aucune exécution, aucune
  erreur console).
- Base réinitialisée par `database/schema.sql` : 6 utilisateurs, 1 élection,
  0 vote, index `idx_journal_tentatives` présent, table `invitations` présente,
  colonne `elections.zone` et index `idx_elections_zone` présents.

## 5. Notes pour la soutenance

- **Anonymat du vote** : `hash_vote = HMAC-SHA256(election_id | matricule, APP_SECRET)`.
  Le hash est déterministe (⇒ un seul vote par matricule via `UNIQUE`) mais non
  réversible sans le secret serveur : on ne peut pas relier un bulletin à un
  étudiant en lisant la base.
- **Anti double vote** : la transaction verrouille la ligne de l'élection
  (`FOR UPDATE`), ce qui sérialise les votes simultanés ; la contrainte `UNIQUE`
  reste le filet de sécurité ultime.
- **Chrono anti-influence** : le compte à rebours d'une minute limite le temps
  de décision. Il est vérifié côté serveur : l'heure d'ouverture du bulletin est
  stockée dans `ouvertures`, donc recharger la page ne réinitialise pas le
  temps. Passé le délai, un vote nul est enregistré et l'électeur est déconnecté.
- **Résultats maîtrisés** : la publication n'est jamais automatique ; elle exige
  une action explicite (« Publier ») après la clôture. Les bulletins nuls sont
  isolés sur une ligne dédiée.
- **Séparation des pouvoirs** : l'admin organise, le délégué de classe tient la
  liste de sa classe, son lien d'inscription et ses élections de classe, le
  contrôleur surveille, l'électeur vote. Chaque rôle est limité par l'API.
- **Direct maîtrisé** : pendant le scrutin, seuls les électeurs ayant déjà voté
  voient les résultats en direct ; les autres reçoivent `403`. L'onglet public
  n'affiche que les scrutins publiés.
- **Élection de classe** : `elections.zone` porte la classe. Le serveur force la
  zone du délégué à la création, puis vérifie cette zone à chaque lecture, vote
  et publication : même en modifiant l'URL ou le corps JSON, un étudiant d'une
  autre classe ne peut ni voir ni voter. Les résultats restent anonymes et
  affichent les photos des candidats.

---

Créé par **MOISE**.
