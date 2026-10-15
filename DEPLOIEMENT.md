# Guide de déploiement — Digital-Elect

Ce guide met l'application en ligne sur **Render.com** (gratuit), avec une base
**PostgreSQL** créée automatiquement.

---

## Prérequis

| Élément | Pourquoi | Coût |
|---|---|---|
| Un compte **GitHub** | héberger le code | gratuit |
| Un compte **Render** | héberger l'app + la base | gratuit |

> Remarque : **Git n'est pas installé** sur cette machine. Ce n'est pas
> bloquant : utilisez l'**Option A (GitHub Desktop)** ou l'**Option B (upload
> web)** ci-dessous.

---

## Étape 1 — Mettre le code sur GitHub

### Option A — GitHub Desktop (recommandé pour débuter)

1. Téléchargez **GitHub Desktop** : https://desktop.github.com
2. Installez-le et connectez-vous avec votre compte GitHub.
3. Menu **File → Add local repository…** puis choisissez le dossier
   `iai-vote` (celui de `Documents`, pas celui de XAMPP).
   - S'il propose de créer un dépôt : acceptez (**create a repository**).
4. Cliquez **Publish repository**, décochez « Keep this code private » si vous
   voulez le rendre public, puis **Publish**.

### Option B — Upload web (sans rien installer)

1. Sur https://github.com → **New repository** → nom : `iai-vote` → **Create**.
2. Sur la page du dépôt : **Add file → Upload files**.
3. Glissez **le contenu** du dossier `iai-vote` (et non le dossier lui-même),
   puis **Commit changes**.

> ⚠️ Le fichier `render.yaml` **doit être à la racine** du dépôt.

---

## Étape 2 — Déployer sur Render

1. Sur https://render.com → créez un compte (**Sign up with GitHub**).
2. Tableau de bord → **New +** → **Blueprint**.
3. Sélectionnez votre dépôt `iai-vote` → **Connect**.
4. Render lit `render.yaml` et propose de créer :
   - un **service web** (Docker) ;
   - une **base PostgreSQL** `iai-vote-db`.
   Cliquez **Apply**.

Le build prend ~5–10 minutes. Render génère tout seul :
- `APP_SECRET` (secret des jetons + hash anonyme) ;
- `DATABASE_URL` (connexion à la base).

---

## Étape 3 — Initialiser la base (une seule fois)

Quand le service est « **Live** », ouvrez :

```
https://<votre-service>.onrender.com/api/install.php
```

Vous devez voir : `SUCCES : base initialisee.`

Ensuite, ouvrez l'application :

```
https://<votre-service>.onrender.com/
```

---

## Étape 4 — Sécuriser (OBLIGATOIRE)

1. **Changez le mot de passe admin** (`admin` / `1234Zeus`) :
   connectez-vous au tableau de bord, puis cliquez sur le bouton
   **« Mon mot de passe »** en haut à droite.
2. **Supprimez `api/install.php`** du dépôt GitHub (Add file → Delete) —
   sinon n'importe qui peut relancer l'installation.
3. Créez vos **vrais comptes électeurs** depuis le tableau de bord.

---

## Durée estimée

| Étape | Durée |
|---|---|
| Créer le compte GitHub + envoyer le code | 5–10 min |
| Créer le compte Render + Blueprint | ~5 min |
| Build + déploiement automatique | 5–10 min |
| `install.php` + personnalisation | ~5 min |
| **Total** | **~20–30 min** |

---

## À savoir sur l'offre gratuite Render

- Le service **se met en veille** après ~15 min d'inactivité ; le premier
  chargement suivant prend ~30 s.
- La **base gratuite** est **limitée dans le temps** (Render l'expire après une
  période donnée). Pour un usage durable, prévoyez une offre payante.
- HTTPS est fourni automatiquement.

---

## Notes techniques

- **Photos des candidats** : enregistrées dans la base (colonne `photo_url`,
  type `TEXT`) sous forme d'image compressée. Elles **survivent** aux
  redéploiements et à la mise en veille du service.
- **Chrono de vote** : 1 minute, vérifié **côté serveur** (table `ouvertures`).
  Passé le délai, le bulletin devient **nul** et l'électeur est déconnecté.
  Durée modifiable dans `api/config.php` (`DUREE_VOTE`, en secondes).
- **Résultats** : jamais automatiques. Après la clôture (auto à la date de fin,
  ou manuelle avec mot de passe admin), cliquez **Résultats → Publier**.
- **Sécurité** : `.htaccess` (en-têtes + CSP) est copié dans l'image Docker ;
  le `Dockerfile` active déjà `AllowOverride All` et le module `headers`.
- **Base locale** : PostgreSQL portable dans `C:\xampp\pgsql`, base `iai_vote`
  (voir `demarrer-postgres.bat` / `arreter-postgres.bat`).
- **Ne committez jamais** de fichier `.env` (secrets) — déjà exclu par
  `.gitignore` et `.dockerignore`.

---

## Dépannage

| Problème | Piste |
|---|---|
| `install.php` → erreur de connexion | La base Render n'est pas encore prête ; attendez 1–2 min et rechargez. |
| Page blanche / 500 | Consultez les **Logs** du service sur Render. |
| « Aucune élection ouverte » | Dans le tableau de bord, ouvrez le scrutin (bouton **Ouvrir**). |
| Rôle administrateur perdu | Vérifiez dans la base : `SELECT role FROM utilisateurs WHERE matricule='admin';` |
