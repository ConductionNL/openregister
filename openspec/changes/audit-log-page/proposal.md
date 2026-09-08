# Audit log page: instance-wide, with filters and export

## Why

Round 2 of the dossiq competitor analysis (row B14 in
`concurrentie-analyse/procest/_round2/compare/tier-b-and-sibling.md`, decision
D10): every competitor has one page where an administrator or auditor reads
the whole instance's log. OpenCase has a Transaction log under admin settings
(`opencase/round2/pages/AdminSettings.md`), GZAC an Admin Logs page
(`valtimo/round2/pages/Admin-Logs.md`), Zaaksysteem a Logboek
(`xxllnc-zaken/round2/pages/Logboek.md`) with filters on user, period and
event type and an export.

dossiq shows the audit per case in the sidebar; its StufAuditLog.vue and
AiAuditExportController cover one integration each. OpenRegister owns the
hash-chained audit trail and already exports it for compliance
(audit-trail-immutable, Audit-Trail Access, Export, and Administrative
Deletion Surface). What is missing is one page over the whole trail, and a
leaf surface so a fleet app can place it as a card on its Reports page
without writing a list.

## What changes

- An `audit` index surface on the audit leaf: a paginated list over the
  whole instance's audit trail, with filters on actor, period, action,
  register, schema and object, and full-text on the change summary.
- The list is scoped by RBAC: a user sees the entries of objects they may
  read; an admin sees all.
- Export of the filtered result as CSV or JSON, through the existing export
  path, so the hash chain fields travel with it.
- A consuming app places the surface with one manifest entry; dossiq links it
  as a card on Reports.

## Who benefits

dossiq, zaakafhandelapp, integriq (sync audit), humaniq (payroll audit),
every app with a compliance duty.

## Impact

- Affected specs: audit-trail-immutable (delta).
- Affected code: `lib/Controller/AuditTrailController.php` (filters),
  `lib/Db/AuditTrailMapper.php` (index-backed filter query, see
  enhanced-audit-trail), the audit leaf's Vue index surface.
- Backwards compatible: per-object audit endpoints are unchanged.
