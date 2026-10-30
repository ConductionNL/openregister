# Design: rbac-matrix-app-rows-and-file-verbs

## D-1. `where` is part of the compiled scope, not a second check

A row `{group, value(s), actions, where}` compiles to

```json
{"group": "<group>", "match": {"<field>": {"$in": ["..."]}, "<where key>": "<where value>", "...": "..."}}
```

The `where` keys are copied into `match` beside the field condition. `buildMatchConditions()` already joins match keys with AND on both the PHP and the SQL path, so no evaluator learns a new word.

Rows sharing a group and actions merge into one scope only when their `where` is equal (compared after key sort). Rows with different `where` stay separate scopes in an OR, which is what they mean.

The validator refuses, at schema save, a `where` key the schema does not declare and a `where` value that is not a scalar, a list of scalars or `{$in: [...]}`. Provider rows go through the same check at compile time; a refused row is dropped and logged with the provider id.

🔴 An empty `$in` inside `where` drops the row whole, for the same reason as the field condition: the SQL builder drops an empty `$in` and the row would become wider than written.

## D-2. `until` on a row

Read with `GrantConstraints::reaches()` against the request's clock. A row past its end compiles to nothing. An `until` nobody can parse is already past, the rule `GrantConstraints` applies everywhere else.

## D-3. Rows from an app, at check time

`matrix.rowsFrom` names a provider. In `compileDepartmentMatrix()` OpenRegister dispatches `MatrixRowsRequestedEvent` with the schema, the register and the provider id. Listeners call `addRows(array $rows, string $appId)`. Only rows added by the app that owns the provider id (the part before the first dot, `dossiq` in `dossiq.case-type-rights`) are kept, and a row granting `public`, `authenticated` or `admin` is dropped. The rows are added to the declared rows before compiling.

- The event fires once per request per (schema, provider). The result is kept in the handler for the rest of the request.
- 🔴 The listener runs inside an authorization decision. It must read without RBAC (as `DepartmentMatrixPersonValues` does) and must not ask the permission handler anything. A listener that throws contributes no rows; the matrix only adds ways in, so the failure direction is closed.
- Why an event and not rows written into the schema: the app's rows change with its own objects (a new case type version, a role whose group changes, an `until` that passes). Writing them into the schema on every change makes the schema a cache that can go stale, and a stale grant is a disclosure.

## D-4. File verbs

When the effective authorization block (after the matrix is compiled) names `readFiles`, `addFiles` or `deleteFiles` under any action key, `ObjectFileAccess` checks per endpoint:

| Endpoint | Today | With file verbs |
|---|---|---|
| index, show, downloadById, preview, listVersions, ZIP export | `read` | `read` and `readFiles` |
| create, save, createMultipart, batch create | `update` | `read` and `addFiles` |
| update, rename, labels, metadata, lock, unlock, restoreVersion | `update` | `read` and `addFiles` |
| delete, batch delete | `update` | `read` and `deleteFiles` |
| copy | `read` source, `update` target | `readFiles` source, `addFiles` target |
| move | `update` both | `deleteFiles` source, `addFiles` target |
| Office open | `read`, edit with `update` | `readFiles`, edit with `addFiles` |

A refused read still answers 404 and a refused change 403 (decision 4 of `object-files-follow-object-access`). The admin rule, the owner rule and system operations are unchanged.

When the block names none of the three, every row reads "Today". A schema opts in by naming one.

## D-5. `{groups: true}` user source

`valuesFromGroups()` returns every group id of the caller when the source says `groups: true`, and the prefixed form keeps working. Both may be declared; the union is used, as with the person source.

## Access tests

PHPUnit with a real `PermissionHandler` and `MagicRbacHandler` (the pattern of `object-files-follow-object-access` 1.1), each case run on the PHP path and the SQL list path and asserted equal:

1. `$self` on `assignedGroup` with `{groups: true}`: a member of group `team-a` lists the objects assigned to `team-a`, not those of `team-b`; a caller in no group sees none through the row.
2. `where: {status: {$in: [s1]}}` on an `update` row: update allowed in `s1`, refused in `s2`; a `read` row without `where` still reads in `s2`.
3. Provider rows: a listener adds one row; the caller is admitted by it; a listener that throws admits nobody; a provider row with an undeclared `where` key is dropped.
4. `until` in the past compiles to nothing.
5. File verbs: a role with `read` + `readFiles` lists files and gets 403 on upload; with `addFiles` uploads and gets 403 on delete; with `deleteFiles` deletes. A schema with no file verb keeps today's table.
