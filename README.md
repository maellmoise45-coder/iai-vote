# Digital-Elect

Application de **vote électronique** (V1) pour une école : les étudiants votent
avec leur **matricule**, un **seul vote par matricule et par élection**, les
bulletins sont **anonymes**.

## Stack technique

| Couche    | Technologie                        |
|-----------|------------------------------------|
| Backend   | PHP natif (aucun framework)        |
| Base      | PostgreSQL (PDO)                   |
| Frontend  | HTML / CSS / JavaScript vanilla    |
| Serveur   | Apache (XAMPP en local, Docker en ligne) |

## Fonctionnalités

- **Connexion par matricule + mot de passe** (plus de reconnaissance faciale).
- **Connexion unique** : une nouvelle connexion invalide la précédente.
- **Rôles** : `admin`, `gestionnaire`, `electeur`.
- **Comptes créés par l'administration** (pas d'inscription libre).
- **Vote unique par matricule et par élection** — garanti par une contrainte
  `UNIQUE` + une transaction `FOR UPDATE`.
- **Vote anonyme** : le bulletin n'est pas lié nominativement à l'électeur
  (`hash_vote = HMAC(election_id | matricule, SECRET)`).
- **Résultats publiés manuellement** : ils n'apparaissent jamais tout seuls.
  Après la clôture, l'admin clique sur **Résultats** puis **Publier**.
- **Photo du candidat** : affichée pendant le vote et dans les résultats.
  Ajoutée par l'admin/gestionnaire (redimensionnée côté navigateur puis stockée
  dans la base — elle survit donc à un redéploiement).
- **Chrono de vote (1 minute) vérifié côté serveur** : à l'ouverture du bulletin,
  l'heure de départ est enregistrée (table `ouvertures`). Recharger la page ne
  remet pas le compteur à zéro. À l'expiration, le bulletin est déclaré **NUL**
  et l'électeur est **déconnecté** (expulsé).
- **Votes nuls** : comptés et affichés sur une ligne dédiée dans les résultats.
- **Clôture automatique** à la date de fin ; **clôture manuelle protégée par le
  mot de passe** de l'administrateur.
- **Chacun peut changer son mot de passe** (bouton « Mon mot de passe »).
- **Journal d'audit** des actions sensibles.
- **Sécurité** : requêtes préparées partout, échappement HTML systématique,
  en-têtes de sécurité + CSP (`.htaccess`), validation des photos.

## Arborescence

```
iai-vote/
├── index.html            Page de connexion
├── vote.html             Espace électeur (vote)
├── dashboard.html        Espace admin / gestionnaire
├── resultats.html        Résultats publics
├── css/style.css
├── js/
│   ├── api.js            Socle (appels API, jeton, session)
│   ├── login.js          Page de connexion
│   ├── vote.js           Espace électeur (chrono + vote)
│   ├── dashboard.js      Espace admin / gestionnaire
│   └── resultats.js      Résultats publics
├── api/
│   ├── config.php        Connexion PDO PostgreSQL + helpers
│   ├── auth.php          Connexion / déconnexion / mot de passe
│   ├── electeurs.php     Gestion des comptes
│   ├── elections.php
│   ├── candidats.php
│   ├── votes.php         Vote anonyme (transaction + hash)
│   ├── resultats.php
│   ├── statistiques.php
│   ├── journal.php
│   └── install.php       Création initiale de la base
├── database/schema.sql   Schéma PostgreSQL
├── .htaccess             En-têtes de sécurité + CSP
├── .dockerignore
├── Dockerfile
├── docker-entrypoint.sh
├── render.yaml
└── .env.example
```

## 1. Lancer en local (XAMPP + PostgreSQL)

### Prérequis
- XAMPP (Apache + PHP) avec l'extension **`pdo_pgsql`** activée dans `php.ini` :
  ```ini
  extension=pdo_pgsql
  extension=pgsql
  ```
- Un serveur **PostgreSQL** en écoute sur `127.0.0.1:5432`.

### Étapes

1. Copiez le dossier dans `C:\xampp\htdocs\iai-vote`.
2. Créez la base :
   ```bash
   createdb -U postgres iai_vote
   ```
   (ou `CREATE DATABASE iai_vote;` dans psql)
3. Ouvrez dans le navigateur :
   ```
   http://localhost/iai-vote/api/install.php
   ```
   Cela crée les tables et les données de démonstration.
4. Ouvrez l'application :
   ```
   http://localhost/iai-vote/
   ```

### Identifiants par défaut

| Rôle          | Matricule       | Mot de passe  |
|---------------|-----------------|---------------|
| Administrateur| `admin`         | `1234Zeus`    |
| Gestionnaire  | `gestionnaire`  | `gestion123`  |
| Électeurs     | `ETU22A001` …   | `electeur123` |

> ⚠️ **Changez le mot de passe administrateur** avant tout usage réel.

## 2. Déploiement en ligne

### alwaysdata (solution retenue — gratuite, sans carte bancaire)

> **Le site est en ligne :** https://digitalelect.alwaysdata.net
>
> Guide complet : **[GUIDE-ALWAYSDATA.md](GUIDE-ALWAYSDATA.md)**
> (compte, base, envoi des fichiers par FTPS, dépannage).

Résumé : compte alwaysdata `digitalelect` → base PostgreSQL
`digitalelect_vote` → fichiers envoyés par **FTPS** dans `/www` → identifiants
de la base placés dans `api/config.local.php` (non versionné) →
`api/install.php` lancé **une fois** puis supprimé.

### Render.com (alternative — exige une carte bancaire)

1. Poussez le projet sur GitHub.
2. Sur Render : **New → Blueprint**, sélectionnez le dépôt.
   Le fichier `render.yaml` crée automatiquement le service web **et** la base
   PostgreSQL.
3. Une fois déployé, ouvrez :
   ```
   https://<votre-service>.onrender.com/api/install.php
   ```
   pour initialiser la base (une seule fois).
4. Supprimez `api/install.php` du dépôt (ou protégez-le) après l'installation.

### Déploiement Docker manuel
```bash
docker build -t iai-vote .
docker run -p 10000:10000 \
  -e DATABASE_URL="postgres://user:pass@host:5432/dbname" \
  -e APP_SECRET="un-secret-long-et-aleatoire" \
  iai-vote
```

## 3. Sécurité

- Requêtes **préparées PDO** partout (aucune concaténation SQL) : testé contre
  l'injection SQL (guillemets, `OR 1=1`, `DROP TABLE`…).
- Les paramètres d'URL numériques sont **castés en entier** (`(int)`).
- Mots de passe **hachés** (`password_hash` / `password_verify`).
- **Connexion unique** : jetons de session en base, purgés à chaque connexion.
- **Vote unique** : contrainte `UNIQUE` + transaction `SELECT … FOR UPDATE`.
- **Anonymat** : aucun matricule dans la table `votes`.
- **Anti-XSS** : tout contenu affiché passe par `echapper()` ; aucun `<script>`
  inline (scripts dans des fichiers externes) ; **CSP** + en-têtes
  `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy` via `.htaccess`
  et l'API.
- **Photos validées** : seules les images (`data:image/...` ou `https://`) sont
  acceptées (pas de `javascript:` ni `data:text/html`).
- **Journalisation** des connexions, créations de comptes et votes.

## 4. Notes pour la soutenance

- **Anonymat du vote** : `hash_vote = HMAC-SHA256(election_id | matricule, SECRET)`.
  Le hash est déterministe (⇒ un seul vote par matricule via `UNIQUE`) mais
  non réversible sans le secret serveur : on ne peut pas relier un bulletin à
  un étudiant en lisant la base.
- **Anti double vote** : la transaction verrouille la ligne de l'élection
  (`FOR UPDATE`), ce qui sérialise les votes simultanés ; la contrainte
  `UNIQUE` reste le filet de sécurité ultime.
- **Chrono anti-influence** : le compte à rebours d'1 minute limite le temps de
  décision. Il est vérifié **côté serveur** : l'heure d'ouverture du bulletin est
  stockée dans la table `ouvertures`, donc recharger la page ne réinitialise pas
  le temps. Passé le délai, un **vote nul** est enregistré et l'électeur est
  déconnecté.
- **Résultats maîtrisés** : la publication n'est jamais automatique ; elle exige
  une action explicite (« Publier ») après la clôture. Les bulletins nuls sont
  isolés sur une ligne dédiée.
