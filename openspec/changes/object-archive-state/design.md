# Design: object-archive-state

## D-1. Archive is not the trash

`deletion-audit-trail` already soft-deletes: `@self.deleted`, excluded
from queries, restorable from a trash API. Reusing that flag for archiving
would merge two different intentions into one, and the first report would
be an archived case sitting in somebody's recycle bin. They get separate
markers and separate verbs. The rule that keeps them apart: the trash is
reversible regret, the archive is a finished result.

## D-2. The marker sits in `@self`, not in the data

Archiving must not change the object's own data, because the object's data
is what the audit trail and the versions are about. `@self.archived`
carries `by`, `at` and `reason`, beside `@self.deleted` and the favourite
marker. Writing it produces one audit entry and no new version of the
data.

## D-3. Read-only means the data, not the metadata

An archived object refuses writes to its data. It still accepts
unarchiving, still accepts the retention machinery setting a destruction
date on it, and still accepts a legal hold. Otherwise archiving would
quietly disable `retention-management`, which is the opposite of what an
archive is for.

## D-4. Exclusion is a default, not a filter the caller must remember

The query excludes archived objects unless asked. A caller who forgets a
parameter gets the working set, which is the safe answer. `_archived=true`
and `_archived=any` are the two ways to see them, and the search providers
take the same default, because a search that still finds them has not
hidden anything.

## D-5. The schema decides whether the action exists

`x-openregister-archive: {"enabled": true}` on the schema. A schema
without it never offers archive and the endpoint refuses with 422. This is
ADR-031: the rule is declared once on the schema, not decided by each app
that renders a button.

## D-6. Kind

Code, in OpenRegister. A consuming app adds one action, one lens and no
backend.

## Risks

- **A reference into an archived object.** Resolution is unchanged on
  purpose: a case that points at an archived contact must still render its
  name. Hiding it from a `$ref` would turn archiving into a silent data
  loss.
- **A count that now disagrees with a list.** Aggregations take the same
  default as the list, or a dashboard tile and the page under it report
  different numbers. The aggregation endpoint is named in the tasks for
  exactly this reason.
- **An archived object in a flow.** A running flow that writes to an
  object archived under it gets the refusal like any other writer. Naming
  the archive in the refusal is what makes that debuggable.
