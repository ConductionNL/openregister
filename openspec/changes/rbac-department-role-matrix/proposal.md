# RBAC: a department by role matrix per schema, keyed on an object field

## Why

Round 2 of the dossiq competitor analysis (row B13 in
`concurrentie-analyse/procest/_round2/compare/tier-b-and-sibling.md`, decision
D10): every competitor grants rights per case type as a matrix. Zaaksysteem's
case type editor has a Rechten tab of Afdeling by Rol with four checkboxes
(search, read, handle, manage) (`xxllnc-zaken/round2/case-type-editor-anatomy.md`);
OpenCase scopes access by organisation, KLE and sensitivity
(`opencase/round2/pages/CaseDetail-Access.md`); GZAC stores a permission JSON
per role (`valtimo/round2/pages/Admin-AccessControl.md`).

dossiq has `roleType.ncGroupId` and OpenRegister RBAC per schema, so a group
may read every case of a schema or none; it cannot read the cases of its own
department only. rbac-scopes already has conditional scopes with dynamic
variables, so the engine can evaluate "object.department equals one of the
user's departments". What is missing is the matrix as a first-class
declaration and an admin surface to edit it.

## What changes

- A schema's `authorization` block accepts a `matrix` declaration: a field of
  the object (`department`), the source of the user's own values (a Nextcloud
  group prefix, or a property of the user's person object), and rows of
  (department value, role group, actions). The engine compiles a row into a
  conditional scope, so enforcement is the existing PHP and SQL path.
- The declared action set is the canonical verbs plus `handle`, resolved by
  the existing custom-verb voting so a consuming app can map `handle` to its
  own transitions.
- An admin surface on the schema page edits the matrix as a grid and previews
  the effective scope for a chosen user.

## Who benefits

dossiq (rights per case type), zaakafhandelapp, humaniq (per team), dossiq's
role-routing-via-or-rbac spec gains the per-department dimension it lacks.

## Impact

- Affected specs: rbac-scopes (delta).
- Affected code: `lib/Service/Authorization/` (matrix compiler),
  schema authorization validation, the schema admin Vue page.
- Backwards compatible: a schema without `matrix` keeps its scopes.
