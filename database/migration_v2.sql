-- ============================================================
--  Digital-Elect — Migration V2
-- ============================================================
--  A executer UNE SEULE FOIS sur une base existante (V1) pour
--  ajouter :
--    1) le role "controleur" ;
--    2) la colonne "zone" des utilisateurs ;
--    3) la table "emargements" (participation par election,
--       sans le choix de vote, pour les stats par zone).
--
--  En local (pas de psql installe) :
--    php C:\...\migrate.php
--  En production :
--    psql -U <user> -d <base> -f database/migration_v2.sql
-- ============================================================

BEGIN;

-- 1) Role controleur -----------------------------------------
ALTER TABLE utilisateurs DROP CONSTRAINT IF EXISTS utilisateurs_role_check;
ALTER TABLE utilisateurs ADD CONSTRAINT utilisateurs_role_check
    CHECK (role IN ('admin', 'gestionnaire', 'controleur', 'electeur'));

-- 2) Zone / amphi / salle ------------------------------------
ALTER TABLE utilisateurs ADD COLUMN IF NOT EXISTS zone VARCHAR(100);

-- 3) Emargements par election --------------------------------
CREATE TABLE IF NOT EXISTS emargements (
    id               SERIAL PRIMARY KEY,
    utilisateur_id   INTEGER NOT NULL REFERENCES utilisateurs(id) ON DELETE CASCADE,
    election_id      INTEGER NOT NULL REFERENCES elections(id) ON DELETE CASCADE,
    date_emargement  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (utilisateur_id, election_id)
);

CREATE INDEX IF NOT EXISTS idx_emargements_election ON emargements(election_id);
CREATE INDEX IF NOT EXISTS idx_emargements_user     ON emargements(utilisateur_id);

COMMIT;

-- NOTE : les votes deja enregistres avant cette migration ne
-- peuvent pas etre rattaches a une zone (l'urne est anonyme).
-- Les emargements sont donc comptes a partir de la V2. La
-- participation globale reste, elle, exacte (table votes).
