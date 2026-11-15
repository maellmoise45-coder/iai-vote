# Guide de déploiement — Digital-Elect V1.4 (alternative Render)

> ⚠️ **Note (2026) :** Render **exige une carte bancaire**, même pour la base
> PostgreSQL gratuite. L'application est donc déployée sur **alwaysdata**
> (gratuit, sans carte) :
> 👉 **https://digitalelect.alwaysdata.net** — voir **[GUIDE-ALWAYSDATA.md](GUIDE-ALWAYSDATA.md)**.
>
> Ce document décrit la **méthode Render** (conservée comme alternative).

Ce guide met l'application en ligne sur **Render.com**, avec une base
**PostgreSQL** externe (Neon ou équivalent).

---

## Prérequis

| Élément | Pourquoi | Coût |
|---|---|---|
| Un compte **GitHub** | héberger le code | gratuit |
| Un compte **Render** | héberger l'app | gratuit (carte requise) |
| Une base **PostgreSQL** | stocker les données | selon le fournisseur |

> Remarque : si **Git n'est pas installé** sur votre machine, utilisez
> **GitHub Desktop** ou l'upload web (Option B ci-dessous).

---

## Étape 1 — Mettre le code sur GitHub

### Option A — GitHub Desktop (recommandé pour débuter)

1. Téléchargez **GitHub Desktop** : https://desktop.github.com
2. Installez-le et connectez-vous avec votre compte GitHub.
3. Menu **File → Add local repository…** puis choisissez le dossier
   `Digital-Elect.V1.3` (celui de `Documents`, pas celui de XAMPP).
   - S'il propose de créer un dépôt : acceptez (**create a repository**).
4. Cliquez **Publish repository**, choisissez la visibilité, puis **Publish**.

### Option B — Upload web (sans rien installer)

1. Sur https://github.com → **New repository** → nom : `iai-vote` → **Create**.
   (Le dépôt existant est <https://github.com/maellmoise45-coder/iai-vote>.)
2. Sur la page du dépôt : **Add file → Upload files**.
3. Glissez **le contenu** du dossier (et non le dossier lui-même), puis
   **Commit changes**.

> ⚠️ `render.yaml` **doit être à la racine** du dépôt.
> ⚠️ Ne committez **jamais** `api/config.local.php` ni `.env`.

---

## Étape 2 — Déployer sur Render

1. Sur https://render.com → créez un compte (**Sign up with GitHub**).
2. Tableau de bord → **New +** → **Blueprint**.
3. Sélectionnez votre dépôt → **Connect**.
4. Render lit `render.yaml` et crée un **service web** (Docker).
   Il génère automatiquement :
   - `APP_SECRET` (jetons + hash anonyme des votes) ;
   - `INSTALL_TOKEN` (protection de `install.php`).
5. Renseignez `DATABASE_URL` avec l'adresse de votre base PostgreSQL externe
   (ex. Neon), puis **Apply**.

Le build prend ~5–10 minutes.

---

## Étape 3 — Initialiser la base (une seule fois)

1. Dans Render → **Environment**, copiez la valeur de `INSTALL_TOKEN`.
2. Appelez le script avec le jeton :

   ```bash
   curl -H "X-Install-Token: VALEUR_DU_JETON" \
        https://<votre-service>.onrender.com/api/install.php
   ```

   Réponse attendue : `SUCCES : base initialisee.`

3. Ouvrez l'application :

   ```
   https://<votre-service>.onrender.com/
   ```

### Base existante (V1 → V2 → V3 → V4 → V5)

N'utilisez pas `install.php` sur une base déjà peuplée. Appliquez les migrations :

```bash
psql "$DATABASE_URL" -f database/migration_v2.sql
psql "$DATABASE_URL" -f database/migration_v3_securite.sql
psql "$DATABASE_URL" -f database/migration_v4_invitations.sql
psql "$DATABASE_URL" -f database/migration_v5_elections_classe.sql
```

- **V2** : rôle `controleur`, colonne `zone`, table `emargements` ;
- **V3** : index `idx_journal_tentatives` (limitation des tentatives) ;
- **V4** : table `invitations` (liens d'inscription par classe) et colonne
  `auto_inscrit` ;
- **V5** : colonne `elections.zone` + index `idx_elections_zone` (élections de
  classe ; zone vide = élection générale).

---

## Étape 4 — Sécuriser (OBLIGATOIRE)

1. **Changez les mots de passe** (`admin` / `1234Zeus`, `gestionnaire`,
   `controleur`) via **« Mon mot de passe »**.
2. **Supprimez `api/install.php`** du dépôt GitHub (Add file → Delete) —
   sinon l'installation reste exposée.
3. Retirez `INSTALL_TOKEN` de l'environnement Render.
4. Créez vos **vrais comptes électeurs** depuis le tableau de bord.
5. Vérifiez que `https://…/api/config.php` renvoie bien `403`.

---

## Durée estimée

| Étape | Durée |
|---|---|
| Créer le compte GitHub + envoyer le code | 5–10 min |
| Créer le compte Render + Blueprint | ~5 min |
| Build + déploiement automatique | 5–10 min |
| `install.php` protégé + personnalisation | ~5 min |
| **Total** | **~20–30 min** |

---

## À savoir sur l'offre gratuite Render

- Le service **se met en veille** après ~15 min d'inactivité ; le premier
  chargement suivant prend ~30 s.
- La **base gratuite** Render n'existe plus : utilisez une base externe
  (Neon, Supabase…) et renseignez `DATABASE_URL`.
- HTTPS est fourni automatiquement.

---

## Notes techniques

- **Photos des candidats** : enregistrées dans la base (colonne `photo_url`,
  type `TEXT`) sous forme d'image compressée. Elles **survivent** aux
  redéploiements et à la mise en veille.
- **Chrono de vote** : 1 minute, vérifié **côté serveur** (table `ouvertures`).
  Passé le délai, le bulletin devient **nul** et l'électeur est déconnecté.
  Durée modifiable dans `api/config.php` (`DUREE_VOTE`, en secondes).
- **Résultats** : jamais automatiques. Après la clôture (auto à la date de fin,
  ou manuelle avec le mot de passe de l'organisateur connecté), cliquez
  **Résultats → Publier**. Pour une élection de classe, la publication est
  limitée au délégué et aux étudiants de la classe.
- **Élections de classe** : le délégué crée un scrutin pour **sa** classe
  (colonne `elections.zone`, forcée côté serveur). Élection, candidats et
  résultats sont invisibles pour les autres classes, les contrôleurs et les
  visiteurs. L'admin voit et gère tout.
- **Liens de classe** : le délégué génère un lien depuis l'onglet **Ma classe**.
  Il impose la zone de la classe ; un seul lien actif à la fois (validité
  90 jours). Les comptes créés sont marqués `auto_inscrit`.
- **Temps réel** : `js/temps-reel.js` interroge `api/sync.php` (empreinte
  scopée, pas de données métier) et ne recharge que si quelque chose a changé.
  `api/cache/` doit être inscriptible par PHP et est protégé par `.htaccess`.
- **Sauvegarde** : `api/sauvegarde.php` (admin) écrit hors de la racine web quand
  possible, sinon dans `sauvegardes/` protégé par `.htaccess`. Noms aléatoires,
  droits `0600`, rotation 14 fichiers, auto toutes les 24 h à l'ouverture de
  l'onglet Sauvegarde.
- **Miroir local** (poste de travail, pas sur le serveur) : `outils/miroir-local.php`
  lit la production en **lecture seule** et synchronise PostgreSQL local + un
  instantané JSON ; `outils/miroir.local.php` contient les secrets et n'est
  jamais versionné.
- **Diagramme en bandes** : affichage CSS/JS pur (`js/graphe.js`), pourcentages
  calculés sur les votants.
- **Sécurité** : `.htaccess` (en-têtes + CSP) est copié dans l'image Docker ;
  le `Dockerfile` active déjà `AllowOverride All` et le module `headers`.
- **Secrets** : `api/config.local.php` et `outils/miroir.local.php` sont exclus
  de Git/de l'image Docker ; en production Docker/Render, utilisez les variables
  d'environnement.
- **Ne committez jamais** de fichier `.env` ni `api/config.local.php`.

---

## Dépannage

| Problème | Piste |
|---|---|
| `install.php` → `403 Acces refuse` | `INSTALL_TOKEN` absent de l'environnement, ou en-tête `X-Install-Token` incorrect. |
| `install.php` → erreur de connexion | La base n'est pas encore prête ou `DATABASE_URL` est incorrecte ; attendez 1–2 min et rechargez. |
| Page blanche / 500 | Consultez les **Logs** du service sur Render. |
| « Aucune élection ouverte » | Dans le tableau de bord, ouvrez le scrutin (bouton **Ouvrir**). |
| Rôle administrateur perdu | Vérifiez : `SELECT role FROM utilisateurs WHERE matricule='admin';` |
| Résultats invisibles | Ils doivent être publiés : **Résultats → Publier** après la clôture. Pour une élection de classe, seuls le délégué et les étudiants de la classe les voient. |
| Colonne `elections.zone` absente | Appliquez `database/migration_v5_elections_classe.sql`. |

---

Créé par **MOISE**.
