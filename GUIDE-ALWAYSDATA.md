# Guide de déploiement — alwaysdata (solution retenue)

> **Application : Digital-Elect V1.2**
> **En ligne :** https://digitalelect.alwaysdata.net

alwaysdata est un hébergeur **français**, avec une offre **gratuite à vie,
sans carte bancaire**, qui fournit du **PHP** et du **PostgreSQL**.

> 💡 Pourquoi pas Render ? Render exige une **carte bancaire** — même pour la
> base PostgreSQL gratuite. alwaysdata ne la demande pas.

---

## 1. Informations du compte

| Élément | Valeur |
|---|---|
| Compte alwaysdata | `digitalelect` |
| Adresse du site | https://digitalelect.alwaysdata.net |
| Panneau d'administration | https://admin.alwaysdata.com |
| Dossier web | `/www` (racine du site) |
| Base PostgreSQL | `digitalelect_vote` |
| Serveur PostgreSQL | `postgresql-digitalelect.alwaysdata.net` (port 5432) |
| Utilisateur PostgreSQL | `digitalelect` |
| Mot de passe PostgreSQL | *celui du compte alwaysdata* |
| PHP | 8.4 (extensions `pdo_pgsql`, `pgsql`, `mbstring`, `openssl`, `json`, `hash` : OK) |
| HTTPS | fourni automatiquement (Let's Encrypt) |

---

## 2. Ce qui a été fait

1. **Compte** alwaysdata créé (offre gratuite).
2. **Base PostgreSQL** `digitalelect_vote` créée + droits accordés à l'utilisateur.
3. **Fichiers** de l'application envoyés par **FTPS** dans `/www`.
4. **`www/api/config.local.php`** créé (fichier **non versionné**) à partir de
   `api/config.local.example.php` : identifiants de la base, `APP_SECRET` et
   `INSTALL_TOKEN`.
5. **`api/install.php`** exécuté **une seule fois** avec l'en-tête
   `X-Install-Token` : tables + données de démonstration.
6. **`api/install.php`** supprimé du serveur (404) et `INSTALL_TOKEN` retiré de
   `config.local.php`.
7. **Migrations** `migration_v2.sql`, `migration_v3_securite.sql` puis
   `migration_v4_invitations.sql` appliquées si la base existait déjà.
8. **Tests** validés en ligne : connexions des 4 rôles, permissions, vote,
   double vote `409`, vote nul, clôture protégée, publication des résultats,
   anonymat, en-têtes de sécurité.

### Nouveautés V1.2 (liens de classe + direct)

- Le **délégué de classe** ne crée et ne modifie plus de profils : il consulte
  les élèves de sa zone et peut retirer un intrus. Il génère le **lien
  d'inscription** de sa classe.
- Les étudiants s'inscrivent eux-mêmes via `inscription.html?token=…` : la
  classe portée par le lien est imposée et non modifiable.
- Les résultats s'affichent en **diagramme à bandes** (axe gradué, grille
  horizontale, barres pleines) et, après avoir voté, l'électeur voit le
  **direct** (rafraîchissement toutes les 5 s).
- Le vote propose un **bouton « Voter nul (bulletin blanc) »** avec confirmation.
  Le bulletin nul apparaît en gris dans le diagramme et dans le suivi live.

---

## 3. Comptes de démonstration

| Rôle | Matricule | Mot de passe |
|---|---|---|
| Administrateur | `admin` | `1234Zeus` |
| Gestionnaire | `gestionnaire` | `gestion123` |
| Contrôleur | `controleur` | `controle123` |
| Électeurs | `ETU22A001`, `ETU22A002`, `ETU22A003` | `electeur123` |

> ⚠️ **À FAIRE :** connectez-vous en `admin`, puis cliquez sur
> **« Mon mot de passe »** (en haut à droite) pour changer le mot de passe.
> Faites de même pour le gestionnaire et le contrôleur : ces mots de passe
> sont publics.

---

## 4. Comment modifier les fichiers plus tard

Connexion **FTPS** (le FTP simple est refusé, il faut chiffrer) :

| Paramètre | Valeur |
|---|---|
| Hôte | `ftp-digitalelect.alwaysdata.net` |
| Port | `990` (implicite TLS) ou `21` (STARTTLS) |
| Utilisateur | `digitalelect` |
| Mot de passe | *mot de passe du compte* |
| Mode | passif |

**Avec `curl` (ligne de commande) :**

```bash
# Lister le dossier web
curl --ssl-reqd --list-only --user "digitalelect:MOTDEPASSE" \
     "ftp://ftp-digitalelect.alwaysdata.net/www/"

# Envoyer un fichier (crée les dossiers si besoin)
curl --ssl-reqd --ftp-create-dirs -T index.html \
     --user "digitalelect:MOTDEPASSE" \
     "ftp://ftp-digitalelect.alwaysdata.net/www/index.html"
```

**Avec FileZilla (interface graphique) :** protocole **FTPS – TLS implicite**,
port **990**.

> 🔒 Ne transférez jamais `api/config.local.php` depuis un dépôt public : créez-le
> directement sur le serveur. Il est exclu de Git (`.gitignore`) et de l'image
> Docker (`.dockerignore`).

---

## 5. Configuration de la base et des secrets

Créez sur le serveur `www/api/config.local.php` à partir de
`www/api/config.local.example.php`, puis renseignez :

```php
<?php
// --- Base de données PostgreSQL ---
putenv('DB_HOST=postgresql-digitalelect.alwaysdata.net');
putenv('DB_PORT=5432');
putenv('DB_NAME=digitalelect_vote');
putenv('DB_USER=digitalelect');
putenv('DB_PASS=********');

// --- Secret applicatif (64 caractères aléatoires) ---
putenv('APP_SECRET=********');

// --- Jeton temporaire pour install.php ---
putenv('INSTALL_TOKEN=********');

// --- Facultatif : uniquement si frontend et API sont séparés ---
// putenv('APP_ORIGIN=https://vote.exemple.cm');
```

Générer un secret solide :

```bash
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

`api/config.php` charge ce fichier **s'il existe**, avant de lire les variables
d'environnement — ce qui permet de configurer la base sans toucher au code.

> 🔒 `api/config.local.php` est listé dans `.gitignore` : il ne part **jamais**
> sur GitHub. Il est aussi interdit en HTTP par `api/.htaccess`.

### Rôle du secret `APP_SECRET`

Il signe les jetons de session **et** calcule le hash anonyme des votes.
**Ne le changez pas après le début d'un scrutin** : les sessions et les hash de
vote existants deviendraient invalides.

---

## 6. Installer la base (une seule fois)

1. Vérifiez que `INSTALL_TOKEN` est défini dans `config.local.php`.
2. Appelez le script **avec le jeton** :

   ```bash
   curl -H "X-Install-Token: VOTRE_JETON" \
        https://digitalelect.alwaysdata.net/api/install.php
   ```

   Réponse attendue : `SUCCES : base initialisee.`

3. **Supprimez immédiatement** `api/install.php` du serveur (FTPS) et retirez
   `INSTALL_TOKEN` de `config.local.php`.

> Sans jeton valide, `install.php` répond `403` et ne crée rien.
> Ne laissez jamais ce fichier en ligne après l'installation.

### Base déjà existante (V1 → V2 → V3 → V4)

N'exécutez **pas** `install.php` : il ne fait rien si la table `utilisateurs`
existe, mais les migrations sont nécessaires.

```bash
psql -h postgresql-digitalelect.alwaysdata.net -U digitalelect -d digitalelect_vote \
     -f database/migration_v2.sql

psql -h postgresql-digitalelect.alwaysdata.net -U digitalelect -d digitalelect_vote \
     -f database/migration_v3_securite.sql

psql -h postgresql-digitalelect.alwaysdata.net -U digitalelect -d digitalelect_vote \
     -f database/migration_v4_invitations.sql
```

Les migrations ajoutent :

- **V2** : rôle `controleur`, colonne `zone`, table `emargements` ;
- **V3** : index `idx_journal_tentatives` pour la limitation des tentatives ;
- **V4** : table `invitations` (liens de classe) et colonne `auto_inscrit`.

> Sans `psql` sur votre poste, exécutez le contenu des fichiers via un petit
> script PHP/PDO (comme pour les précédentes migrations).

---

## 7. Se connecter à la base en direct (dépannage)

```bash
psql -h postgresql-digitalelect.alwaysdata.net -U digitalelect -d digitalelect_vote
```

Requêtes utiles :

```sql
-- Vérifier l'installation
SELECT count(*) FROM utilisateurs;
SELECT count(*) FROM votes;

-- Vérifier les index de sécurité
SELECT indexname FROM pg_indexes
WHERE schemaname = 'public'
ORDER BY indexname;

-- Remettre la démo à zéro
DELETE FROM votes;
DELETE FROM ouvertures;
DELETE FROM emargements;
UPDATE utilisateurs SET a_vote = FALSE;
UPDATE candidats SET nombre_voix = 0;
UPDATE elections
SET statut = 'en_cours', resultats_publics = FALSE,
    date_fin = NOW() + INTERVAL '7 days'
WHERE id = 1;
```

---

## 8. Réinstaller la base (si nécessaire)

1. Remettez `install.php` en ligne (`curl` ci-dessus).
2. Vérifiez que `INSTALL_TOKEN` est de nouveau défini dans `config.local.php`.
3. Appelez le script **avec l'en-tête** :

   ```bash
   curl -H "X-Install-Token: VOTRE_JETON" \
        https://digitalelect.alwaysdata.net/api/install.php
   ```

4. **Supprimez** de nouveau `install.php` et le jeton.

> ⚠️ `schema.sql` commence par `DROP TABLE` : une réinstallation **efface toutes
> les données** (votes compris). Sauvegardez avant.

---

## 9. Vérifications après déploiement

| Vérification | Résultat attendu |
|---|---|
| `https://…/api/config.php` | `403` (fichier non servi) |
| `https://…/database/schema.sql` | `403` ou `404` |
| `https://…/README.md` | `403` ou `404` |
| `https://…/api/install.php` (sans jeton) | `403` |
| Connexion admin | tableau de bord avec onglets Élections, Électeurs, Liens de classe, Suivi, Journal |
| Connexion gestionnaire | onglets « Électeurs » (sa classe) et « Ma classe » uniquement |
| Connexion contrôleur | Suivi live + Journal, aucune action de gestion |
| Lien de classe délégué | bouton **Copier** → `inscription.html?token=…` s'ouvre sur la bonne classe |
| Auto-inscription | la classe affichée est verrouillée, l'inscription connecte directement l'étudiant |
| Direct après vote | diagramme en bandes visible, actualisé toutes les 5 s |
| Résultats avant publication | `403` pour un visiteur |
| En-têtes | `X-Frame-Options: DENY`, CSP, `X-Content-Type-Options: nosniff` |

---

## 10. Limites de l'offre gratuite

- 1 Go de disque, 256 Mo de RAM (largement suffisant ici).
- Le service n'est **pas mis en veille** (contrairement à Render) : le site
  répond immédiatement.
- Interdit d'héberger **uniquement** une base de données sur l'offre gratuite —
  ce qui n'est pas notre cas (on héberge aussi le site).

---

## 11. Dépannage

| Problème | Piste |
|---|---|
| `install.php` → `403 Acces refuse` | `INSTALL_TOKEN` absent de `config.local.php` ou en-tête `X-Install-Token` incorrect. |
| Erreur de connexion à la base | Vérifiez `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` dans `config.local.php`. |
| Lien de classe introuvable | Le token a été révoqué ou remplacé : générez un nouveau lien depuis **Ma classe**. |
| « Aucune classe associée » (délégué) | L'admin doit renseigner la **zone** du compte gestionnaire. |
| Table `invitations` absente | Appliquez `database/migration_v4_invitations.sql`. |
| `config.php` renvoie `403` | Normal : le fichier n'est jamais servi directement. |
| Page blanche / 500 | Consultez les logs PHP dans le panneau alwaysdata. |
| « Aucune élection ouverte » | Dans le tableau de bord admin : **Ouvrir** le scrutin. |
| Mot de passe admin perdu | `SELECT role FROM utilisateurs WHERE matricule='admin';` puis réinitialisation par un autre admin, ou via la base. |
| Résultats invisibles | Ils doivent être publiés : **Résultats → Publier** après la clôture. |
| Session invalidée | Une nouvelle connexion au même compte invalide l'ancienne ; un changement de mot de passe aussi. |

---

Créé par **MOISE**.
