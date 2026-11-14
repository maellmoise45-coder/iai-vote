-- ============================================================
--  Digital-Elect — Migration V4
-- ============================================================
--  A executer UNE SEULE FOIS sur une base existante (V3) pour
--  ajouter :
--    1) la colonne "auto_inscrit" (comptes crees par un lien
--       d'inscription de classe) ;
--    2) la table "invitations" (un lien par classe, genere par
--       le delegue, permet aux etudiants de s'inscrire seuls
--       dans la zone de la classe, sans pouvoir la choisir).
--
--  En local (pas de psql installe) :
--    php C:\...\migrate_v4.php
--  En production :
--    psql -U <user> -d <base> -f database/migration_v4_invitations.sql
-- ============================================================

BEGIN;

-- 1) Comptes auto-inscrits -----------------------------------
ALTER TABLE utilisateurs
    ADD COLUMN IF NOT EXISTS auto_inscrit BOOLEAN NOT NULL DEFAULT FALSE;

-- 2) Liens d'inscription par zone (classe) -------------------
CREATE TABLE IF NOT EXISTS invitations (
    id         SERIAL PRIMARY KEY,
    token      VARCHAR(64)  NOT NULL UNIQUE,   -- 32 octets en hexa
    zone       VARCHAR(100) NOT NULL,          -- classe / amphi / salle
    cree_par   INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    actif      BOOLEAN      NOT NULL DEFAULT TRUE,
    expire_at  TIMESTAMPTZ,                    -- NULL = pas d'expiration
    cree_le    TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_invitations_zone  ON invitations(zone);
CREATE INDEX IF NOT EXISTS idx_invitations_token ON invitations(token);
CREATE INDEX IF NOT EXISTS idx_invitations_actif ON invitations(actif);

COMMIT;
