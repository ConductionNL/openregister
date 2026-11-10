# Tasks

- [x] task-1: audit-trail-immutable#REQ-IMMU-001 — File-attachment audit events (bulk download, file action) MUST persist via the same hash-chained AuditTrail entity (retroactive annotation)
- [x] task-2: audit-trail-immutable#REQ-IMMU-002 — Admin-only `DELETE /api/audit-trails/clear-all` exists and wipes the entire audit table (retroactive annotation; flagged as drift from existing "MUST NOT be deletable" requirement)
- [x] task-3: Annotate `ClearAuditTrails.vue::closeDialog` and `ClearAuditTrails.vue::clearAuditTrails` under task-2 (admin clear-all UI surface)
- [x] task-4: Annotate `FileAuditHandler.php::logBulkDownload` and `FileAuditHandler.php::logFileAction` under task-1 (file-event audit producers)
