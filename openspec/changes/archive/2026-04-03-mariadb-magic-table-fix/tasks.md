## 1. Bug Fix

- [x] 1.1 In `lib/Db/MagicMapper.php`, update `getExistingTableColumns()` to detect the database platform via `$this->db->getDatabasePlatform()` and use `table_schema = DATABASE()` for MySQL/MariaDB and `table_schema = 'public'` for PostgreSQL, following the same pattern used in `tableExists()` (line ~1599)
