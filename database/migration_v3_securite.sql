-- ============================================================
--  Digital-Elect — Migration V3 (securite)
-- ============================================================
--  A executer UNE SEULE FOIS sur une base existante (V2) pour
--  accelerer la limitation des tentatives de connexion.
--
--  En production :
--    psql -U <user> -d <base> -f database/migration_v3_securite.sql
-- ============================================================

BEGIN;

CREATE INDEX IF NOT EXISTS idx_journal_tentatives
    ON journal(action, adresse_ip, matricule, date_action);

-- Purge des anciennes traces de tentatives (facultatif, sans impact
-- sur les actions d'audit : seules les connexions echouees agees
-- de plus de 30 jours sont supprimees).
DELETE FROM journal
WHERE action = 'connexion_echouee'
  AND date_action < NOW() - INTERVAL '30 days';

COMMIT;
