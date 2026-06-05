-- XWiki LTS calls SHOW PROCESSLIST during a number of admin queries.
-- Without the PROCESS privilege the migration loop in
-- HibernateDataMigrationManager throws SQLGrammarException and leaves
-- the schema in a half-migrated state ('Unknown column XWD_ARCHIVE'
-- and friends), which corrupts the install permanently.
-- Granting PROCESS on first DB boot prevents the corruption.
GRANT PROCESS ON *.* TO 'xwiki'@'%';
FLUSH PRIVILEGES;
