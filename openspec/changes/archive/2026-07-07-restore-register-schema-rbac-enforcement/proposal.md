---
kind: fix
depends_on: []
adr: openspec/architecture/adr-007-single-builtin-search-backend.md
---

## Why

Role-based permission checks on the register and schema mutation surface are
**commented out** at HEAD. `verifyRbacPermission()` is a live, working method
(`lib/Db/MultiTenancyTrait.php:870`) that throws 403 when the caller lacks the
role for an action, but every create/update/delete call site disables it:

- `lib/Db/RegisterMapper.php:558` (`// verifyRbacPermission('create', 'register')`),
  `:647` (update), `:740` (delete)
- `lib/Db/SchemaMapper.php:599` (create), `:1509` (update), `:1589` (delete)

Only `verifyOrganisationAccess()` (tenant scoping) remains. Net effect: **any
authenticated user who is a member of an organisation can create, update, or
delete that organisation's registers and schemas regardless of their RBAC
role.** For a data-governance platform, register/schema definitions are the most
privileged objects in the system — this is a privilege-escalation gap.

The read paths are also disabled, but with an explicit reason:
`RegisterMapper.php:246,500` and `SchemaMapper.php:261,540` carry
`// @todo: remove this hotfix for solr - uncomment when ready`. Per ADR-007 the
Solr backend has been **removed**, so the justification for that hotfix no
longer exists — yet the bypass remains with no tracking issue.

The mutation call sites (create/update/delete) have **no** explanatory comment
at all — they read as silently disabled, not documented as temporary, which is
how such a gap survives review.

## What Changes

- Re-enable `verifyRbacPermission()` on the six create/update/delete call sites
  for Register and Schema mappers.
- Resolve the read-path "solr hotfix": since the Solr backend is gone (ADR-007),
  re-enable read RBAC; if any current caller genuinely needs unrestricted reads,
  make that an explicit, named internal path (`_rbac: false` passed by that
  caller) rather than a globally-disabled check.
- If RBAC-by-role was *deliberately* superseded by org-scoping for some actions,
  document that decision in an ADR and delete the misleading commented calls
  instead of leaving dead code that implies a check exists.

## Impact

- Affected: `lib/Db/RegisterMapper.php`, `lib/Db/SchemaMapper.php`,
  `lib/Db/MultiTenancyTrait.php` (no change, reused).
- Behavioural change: non-privileged org members lose the ability to mutate
  registers/schemas — verify the intended role matrix with a real multi-role
  fixture before shipping, and confirm opencatalogi/softwarecatalog admin flows
  still pass (they run as admin/owner and should be unaffected).
- Risk: over-tightening could break a legitimate internal caller that relied on
  the disabled check; audit callers first (task 1.1).
