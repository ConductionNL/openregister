# Tasks: audit-trail-not-in-filter

- [x] 1.1 Test red first: `tests/Unit/Db/AuditTrailNotInFilterTest.php` (6; an array value was cast to "Array" and matched nothing). The SQLite test bridge renders `notIn` and `func()->lower`.
- [x] 1.2 `AuditTrailMapper::findAll` applies `field[notIn]` and plain lists in the query; unknown operators are refused.
- [ ] 1.3 nextcloud-vue: `CnAuditTrailCard` sends `action[notIn]` / `user[notIn]` for `excludeActions` / `excludeActors` (lands after this).
