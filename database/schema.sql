-- ============================================================
--  Digital-Elect — Base de donnees PostgreSQL
--  Systeme de vote electronique (V1)
-- ============================================================
--  Lancement :
--    psql -U postgres -f database/schema.sql
-- ============================================================

-- Nettoyage (ordre inverse des dependances) -------------------
DROP TABLE IF EXISTS journal CASCADE;
DROP TABLE IF EXISTS ouvertures CASCADE;
DROP TABLE IF EXISTS sessions CASCADE;
DROP TABLE IF EXISTS votes CASCADE;
DROP TABLE IF EXISTS candidats CASCADE;
DROP TABLE IF EXISTS elections CASCADE;
DROP TABLE IF EXISTS utilisateurs CASCADE;

-- ============================================================
--  UTILISATEURS (admin, gestionnaire, electeur)
-- ============================================================
--  Le matricule est l'identifiant de connexion (UNIQUE).
--  Les trois roles vivent dans la meme table : c'est plus
--  simple qu'une table par role et le controle d'acces se fait
--  sur la colonne "role".
-- ============================================================
CREATE TABLE utilisateurs (
    id             SERIAL PRIMARY KEY,
    matricule      VARCHAR(50)  NOT NULL UNIQUE,
    nom            VARCHAR(100) NOT NULL,
    prenom         VARCHAR(100) NOT NULL,
    email          VARCHAR(150) UNIQUE,
    mot_de_passe   VARCHAR(255) NOT NULL,               -- password_hash()
    role           VARCHAR(20)  NOT NULL DEFAULT 'electeur'
                   CHECK (role IN ('admin', 'gestionnaire', 'electeur')),
    a_vote         BOOLEAN      NOT NULL DEFAULT FALSE, -- drapeau d'emargement (1 vote)
    actif          BOOLEAN      NOT NULL DEFAULT TRUE,
    cree_le        TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_utilisateurs_role ON utilisateurs(role);

-- ============================================================
--  ELECTIONS
-- ============================================================
CREATE TABLE elections (
    id                 SERIAL PRIMARY KEY,
    titre              VARCHAR(200) NOT NULL,
    description        TEXT,
    date_debut         TIMESTAMPTZ,
    date_fin           TIMESTAMPTZ,
    statut             VARCHAR(20) NOT NULL DEFAULT 'a_venir'
                       CHECK (statut IN ('a_venir', 'en_cours', 'terminee', 'publiee', 'annulee')),
    resultats_publics  BOOLEAN NOT NULL DEFAULT FALSE,
    cree_le            TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- ============================================================
--  CANDIDATS
-- ============================================================
CREATE TABLE candidats (
    id            SERIAL PRIMARY KEY,
    election_id   INTEGER NOT NULL REFERENCES elections(id) ON DELETE CASCADE,
    nom           VARCHAR(100) NOT NULL,
    prenom        VARCHAR(100) NOT NULL,
    programme     TEXT,
    photo_url     TEXT,                                 -- URL externe OU photo encodee (data URL)
    nombre_voix   INTEGER NOT NULL DEFAULT 0,
    cree_le       TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_candidats_election ON candidats(election_id);

-- ============================================================
--  VOTES  (urne ANONYME)
-- ============================================================
--  Aucun matricule n'est stocke ici : le lien entre l'electeur
--  et son bulletin est impossible a lire directement.
--
--  hash_vote = HMAC-SHA256(election_id | matricule, SECRET)
--    -> deterministe : la contrainte UNIQUE garantit
--       "un matricule = un seul vote par election"
--    -> sans le secret serveur, personne ne peut recalculer
--       le hash pour retrouver le bulletin d'un etudiant.
-- ============================================================
CREATE TABLE votes (
    id           SERIAL PRIMARY KEY,
    election_id  INTEGER NOT NULL REFERENCES elections(id) ON DELETE CASCADE,
    candidat_id  INTEGER REFERENCES candidats(id) ON DELETE CASCADE, -- NULL = vote nul
    hash_vote    VARCHAR(64) NOT NULL UNIQUE,
    date_vote    TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_votes_election ON votes(election_id);
CREATE INDEX idx_votes_candidat ON votes(candidat_id);

-- ============================================================
--  OUVERTURES  (chrono de vote : heure d'ouverture du bulletin)
-- ============================================================
--  Enregistre l'instant ou un electeur ouvre pour la premiere
--  fois le bulletin d'une election. Permet de faire respecter
--  la duree de vote (1 minute) cote serveur, meme si le
--  navigateur est recharge, ferme ou reouvert.
-- ============================================================
CREATE TABLE ouvertures (
    id             SERIAL PRIMARY KEY,
    utilisateur_id INTEGER NOT NULL REFERENCES utilisateurs(id) ON DELETE CASCADE,
    election_id    INTEGER NOT NULL REFERENCES elections(id) ON DELETE CASCADE,
    debut_at       TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (utilisateur_id, election_id)
);

CREATE INDEX idx_ouvertures_election ON ouvertures(election_id);

-- ============================================================
--  SESSIONS  (connexion unique)
-- ============================================================
CREATE TABLE sessions (
    id              SERIAL PRIMARY KEY,
    utilisateur_id  INTEGER NOT NULL REFERENCES utilisateurs(id) ON DELETE CASCADE,
    token           VARCHAR(255) NOT NULL UNIQUE,
    expire_at       TIMESTAMPTZ NOT NULL,
    cree_le         TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_sessions_token ON sessions(token);
CREATE INDEX idx_sessions_user  ON sessions(utilisateur_id);

-- ============================================================
--  JOURNAL  (audit / tracabilite des actions sensibles)
-- ============================================================
CREATE TABLE journal (
    id           SERIAL PRIMARY KEY,
    matricule    VARCHAR(50),
    action       VARCHAR(50) NOT NULL,
    details      TEXT,
    adresse_ip   VARCHAR(64),
    date_action  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_journal_date ON journal(date_action);

-- ============================================================
--  DONNEES INITIALES
-- ============================================================

-- Compte administrateur par defaut
--   matricule : admin
--   mot de passe : 1234Zeus
INSERT INTO utilisateurs (matricule, nom, prenom, email, mot_de_passe, role)
VALUES (
    'admin',
    'Administrateur',
    'Systeme',
    'admin@digital-elect.cm',
    '$2y$10$dWzP9w7zpgukEiPHn8bKRuTe90PpIarMUrArXCP1DqA5vXt2YEEmS',
    'admin'
);

-- Compte gestionnaire par defaut
--   matricule : gestionnaire
--   mot de passe : gestion123
INSERT INTO utilisateurs (matricule, nom, prenom, email, mot_de_passe, role)
VALUES (
    'gestionnaire',
    'Gestionnaire',
    'General',
    'gestionnaire@digital-elect.cm',
    '$2y$10$1fyRuo1YE0NCkOXRFMJBJuODx14TbEjEHTRfYLysUq/Rz6pPD5o3a',
    'gestionnaire'
);

-- Quelques electeurs de demonstration
--   mot de passe pour tous : electeur123
INSERT INTO utilisateurs (matricule, nom, prenom, email, mot_de_passe, role) VALUES
('ETU22A001', 'NGONO',  'Alice',   'alice@digital-elect.cm',  '$2y$10$QWCI1FOaU3Xs2mk9pTz.2.T9W2OOEL6.Rdbblf7jX.HgG/DlYmyvO', 'electeur'),
('ETU22A002', 'MBALLA', 'Bernard', 'bernard@digital-elect.cm','$2y$10$QWCI1FOaU3Xs2mk9pTz.2.T9W2OOEL6.Rdbblf7jX.HgG/DlYmyvO', 'electeur'),
('ETU22A003', 'FOTSO',  'Carine',  'carine@digital-elect.cm', '$2y$10$QWCI1FOaU3Xs2mk9pTz.2.T9W2OOEL6.Rdbblf7jX.HgG/DlYmyvO', 'electeur');

-- Election de demonstration
INSERT INTO elections (titre, description, statut, date_debut, date_fin, resultats_publics)
VALUES (
    'Election du delegue de classe 2025',
    'Scrutin de demonstration',
    'a_venir',
    NOW(),
    NOW() + INTERVAL '7 days',
    FALSE
);

-- Candidats de demonstration (election 1)
INSERT INTO candidats (election_id, nom, prenom, programme) VALUES
(1, 'TAMBA',  'Jean',   'Programme A : plus de tutorat et de Wi-Fi au campus.'),
(1, 'NDONGO', 'Marie',  'Programme B : sorties pedagogiques et clubs etudiants.'),
(1, 'ESSOMBA','Paul',   'Programme C : bourses et accompagnement des nouveaux.');
