---
kind: code
depends_on: [rbac-department-role-matrix, object-files-follow-object-access]
---

# Proposal: rbac-matrix-app-rows-and-file-verbs

## Why

Ruben decided four rights questions for dossiq's case types on 23 October 2026. Three of them are access rules, and access rules belong in OpenRegister's RBAC, not in the app:

1. A rights row can name "own team": the cases of the caller's own team, resolved when the check runs.
2. A rights row can be limited to statuses (phases). Outside them the role cannot edit.
3. Documents get their own rights per role: read, add and delete a case's files, enforced on the files.

The department matrix (`rbac-department-role-matrix`) already compiles rows into ordinary conditional scopes and already resolves `$self` at check time. It cannot yet say any of the three:

- A row has one condition, on the matrix field. It cannot add "and the status is one of these" or "and the case type is this one".
- `$self` resolves from a group prefix or a person schema. A team is a plain Nextcloud group with no prefix.
- The rows live in the schema. dossiq declares rights per case type, on case type objects that change with every published version, and `until` must stop a grant without anything running.
- A file action asks `read` or `update` on the object. There is no separate verb for files.

## What changes

Each item is generic. Nothing in it names a case, a case type or dossiq.

- **A matrix row may carry `where`**: extra conditions in the scope vocabulary (`{field: value}`, `{field: {$in: [...]}}`), joined with AND to the row's field condition. The compiled scope is still one ordinary conditional scope, so the PHP and SQL paths stay identical.
- **A matrix row may carry `until`**, read through `GrantConstraints` the way a role grant reads it. A row past its end compiles to nothing.
- **A matrix user source may be `{groups: true}`**: the caller's own values are their Nextcloud group ids, unprefixed. `$self` against a group-id field then means "a group I am in".
- **An app may contribute matrix rows at check time.** A schema declares `matrix.rowsFrom: "<provider id>"`. OpenRegister dispatches `MatrixRowsRequestedEvent` (schema, register, provider id) once per request and schema, and compiles the rows the listeners add beside the declared ones. Rows are input to the same compiler and the same validator; a row a listener adds that fails validation is dropped and logged, never widened.
- **File verbs.** `readFiles`, `addFiles` and `deleteFiles` join the matrix action set and the permission catalogue. When a schema's authorization declares any of them, `ObjectFileAccess` asks the file verb instead of the object verb it asks today (read files: `readFiles`; add or change a file: `addFiles`; delete: `deleteFiles`), always on top of `read` on the object. The full table per endpoint is design D-4. When a schema declares none of them, nothing changes.

## What does not change

- A matrix only adds ways in. Rows from a provider widen within the schema's ceiling, exactly as declared rows do.
- Empty `$in` still drops the row whole (the leak the compiler already guards).
- Schemas without `where`, `rowsFrom` or file verbs behave as today.

## Impact

- `DepartmentMatrixCompiler`, `DepartmentMatrixValidator`, `PermissionHandler::compileDepartmentMatrix()`, `ObjectFileAccess`, `PermissionCatalogue`, a new event class.
- First consumer: dossiq change `case-type-rights-enforced`.
