# Guide de déploiement — alwaysdata (solution retenue)

> **L'application est EN LIGNE sur :**
> ## 🔗 https://digitalelect.alwaysdata.net

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
4. **`www/api/config.local.php`** créé (fichier **non versionné**, contient les
   identifiants de la base et le `APP_SECRET`).
5. **`api/install.php`** exécuté **une seule fois** : tables + données de démo.
6. **`api/install.php`** et le fichier de test **supprimés** du serveur (404).
7. **21 tests d'API** validés en ligne (vote, double vote 409, vote nul, clôture
   protégée, publication des résultats, anonymat…).

---

## 3. Comptes de démonstration

| Rôle | Matricule | Mot de passe |
|---|---|---|
| Administrateur | `admin` | `1234Zeus` |
| Gestionnaire | `gestionnaire` | `gestion123` |
| Électeurs | `ETU22A001`, `ETU22A002`, `ETU22A003` | `electeur123` |

> ⚠️ **À FAIRE :** connectez-vous en `admin`, puis cliquez sur
> **« Mon mot de passe »** (en haut à droite) pour changer le mot de passe.
> Le mot de passe par défaut est public.

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

---

## 5. Configuration de la base

Le fichier `api/config.local.php` (créé sur le serveur, **jamais commité**)
contient :

```php
<?php
putenv('DB_HOST=postgresql-digitalelect.alwaysdata.net');
putenv('DB_PORT=5432');
putenv('DB_NAME=digitalelect_vote');
putenv('DB_USER=digitalelect');
putenv('DB_PASS=********');
putenv('APP_SECRET=********');   // secret de 64 caractères
```

`api/config.php` charge ce fichier **s'il existe**, avant de lire les variables
d'environnement — ce qui permet de configurer la base sans toucher au code.

> 🔒 `api/config.local.php` est listé dans `.gitignore` : il ne part **jamais**
> sur GitHub.

---

## 6. Se connecter à la base en direct (dépannage)

```bash
psql -h postgresql-digitalelect.alwaysdata.net -U digitalelect -d digitalelect_vote
```

Requêtes utiles :

```sql
-- Vérifier l'installation
SELECT count(*) FROM utilisateurs;
SELECT count(*) FROM votes;

-- Remettre la démo à zéro
DELETE FROM votes;
DELETE FROM ouvertures;
UPDATE utilisateurs SET a_vote = FALSE;
UPDATE candidats SET nombre_voix = 0;
UPDATE elections SET statut='en_cours', resultats_publics=FALSE,
       date_fin = NOW() + INTERVAL '7 days' WHERE id=1;
```

---

## 7. Réinstaller la base (si nécessaire)

1. Remettre `install.php` en ligne (`curl` ci-dessus).
2. Ouvrir `https://digitalelect.alwaysdata.net/api/install.php`.
3. **Supprimer** de nouveau `install.php`.

---

## 8. Limites de l'offre gratuite

- 1 Go de disque, 256 Mo de RAM (largement suffisant ici).
- Le service n'est **pas mis en veille** (contrairement à Render) : le site
  répond immédiatement.
- Interdit d'héberger **uniquement** une base de données sur l'offre gratuite —
  ce qui n'est pas notre cas (on héberge aussi le site).
