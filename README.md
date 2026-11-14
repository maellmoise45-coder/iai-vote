# Digital-Elect V1.2

Application de **vote électronique** pour une école : les étudiants votent avec
leur **matricule**, un **seul vote par matricule et par élection**, et les
bulletins sont **anonymes**.

> Version **1.2** : audit sécurité complet (XSS, SQL, permissions), limitation
> des tentatives de connexion, installation protégée par jeton et documentation
> de déploiement mise à jour.
>
> Cette version introduit aussi les **liens d'inscription par classe**
> (auto-inscription des étudiants) et le **diagramme à bandes** avec résultats
> en direct après le vote.

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
- **Délégué de classe (rôle `gestionnaire`)** : il ne crée et ne modifie plus
  aucun profil. Il consulte uniquement les élèves de **sa classe** (colonne
  `zone`) et peut retirer un profil qui ne correspond pas à sa liste. Il génère
  le **lien d'inscription de sa classe**. L'API applique ces règles, pas
  seulement l'interface.
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
  l'admin clique sur **Résultats → Publier**. Avant publication, les compteurs
  (`nb_votes`, `nombre_voix`) sont retirés des réponses publiques.
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
- **Résultats en direct après le vote** : pendant un scrutin `en_cours`, un
  électeur qui a déjà voté voit le diagramme se rafraîchir toutes les 5 secondes
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
  le mot de passe de l'administrateur.
- **Changement de mot de passe** : invalide immédiatement toutes les sessions
  du compte (`reconnecter: true`).
- **Blocage immédiat d'un compte** : la désactivation coupe les sessions en
  cours et empêche toute reconnexion.
- **Journal d'audit** des actions sensibles (connexions, votes, publications…).
- **Suivi live par zone** (admin et contrôleurs) : inscrits, votants et taux de
  participation par amphi/salle, avec le même diagramme à bandes.
- **Interface responsive** : palette bleue, logo Digital-Elect, favicon,
  tableaux maîtrisés sur mobile, mention « Créé par MOISE ».

## Arborescence

```
Digital-Elect.V1.2/
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
│   ├── login.js
│   ├── vote.js                Chrono + vote + direct après vote
│   ├── dashboard.js           Admin / délégué de classe
│   ├── suivi.js               Suivi live partagé
│   ├── controle.js            Espace contrôleur
│   ├── resultats.js           Résultats publics + direct
│   ├── inscription.js         Vérification du lien + auto-inscription
│   └── graphe.js              Diagramme en bandes (CSS/JS pur)
├── api/
│   ├── config.php             Configuration PDO + helpers sécurité
│   ├── config.local.example.php   Modèle de configuration locale
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
│   └── .htaccess              Protection config + transmission Authorization
├── database/
│   ├── schema.sql             Schéma PostgreSQL complet
│   ├── migration_v2.sql       V1 → V2 (contrôleur, zones, émargements)
│   ├── migration_v3_securite.sql  V2 → V3 (index anti-force brute)
│   └── migration_v4_invitations.sql  V3 → V4 (liens de classe)
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
```

Sans `psql` (poste Windows), exécutez le contenu des fichiers via un petit
script PHP/PDO. Les votes déjà enregistrés restent anonymes : ils ne peuvent pas
être rattachés rétroactivement à une zone (les émargements sont comptés à partir
de la V2).

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
  (liste, recherche, suppression d'un profil qui n'a pas voté) et gestion du
  **lien d'inscription de sa classe**. Création et modification refusées (`403`).
- Auto-inscription : `inscription.php` n'accepte la zone que depuis le jeton
  d'invitation ; aucune zone n'est lue dans le corps de la requête.
- Contrôleur : `suivi.php` et `journal.php` en lecture seule.
- Électeur : vote et état de son propre bulletin uniquement.
- Admin : élections, candidats, comptes, publication.

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
- Parcours navigateur : admin, délégué de classe (onglets « Ma classe » et
  « Électeurs »), contrôleur, électeur, auto-inscription, vote, bouton
  « Voter nul » avec confirmation, diagramme à bandes, résultats publics et
  direct, XSS stocké (aucune exécution, aucune erreur console).
- Base réinitialisée par `database/schema.sql` : 6 utilisateurs, 1 élection,
  0 vote, index `idx_journal_tentatives` présent, table `invitations` présente.

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
  liste de sa classe et son lien d'inscription, le contrôleur surveille,
  l'électeur vote. Chaque rôle est limité par l'API.
- **Direct maîtrisé** : pendant le scrutin, seuls les électeurs ayant déjà voté
  voient les résultats en direct ; les autres reçoivent `403`. L'onglet public
  n'affiche que les scrutins publiés.

---

Créé par **MOISE**.
