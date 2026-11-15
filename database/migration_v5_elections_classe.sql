-- ============================================================
--  Digital-Elect — Migration V5
-- ============================================================
--  A executer UNE SEULE FOIS sur une base existante (V4) pour
--  ajouter les ELECTIONS DE CLASSE :
--    - colonne "zone" sur la table elections ;
--    - NULL / vide  = election generale (toute l'ecole) ;
--    - "L1B", "L1G" = election reservee aux electeurs de la
--      classe, organisee par le delegue de cette classe.
--
--  En local (pas de psql installe) :
--    php C:\...\migration_v5.php
--  En production :
--    psql -U <user> -d <base> -f database/migration_v5_elections_classe.sql
-- ============================================================

BEGIN;

ALTER TABLE elections
    ADD COLUMN IF NOT EXISTS zone VARCHAR(100);

CREATE INDEX IF NOT EXISTS idx_elections_zone ON elections(zone);

COMMIT;
