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

## D-C29-1. Frozen and archived are two states, not one flag

Archived leaves the working views; frozen stays in them. A zaak in bezwaar
must be findable and unchangeable at the same time, and collapsing the two
into one state forces every leaf app to choose the wrong half.

## D-C29-2. A freeze can be declared by the lifecycle

A freeze that depends on somebody clicking is a freeze that happens late.
A state declares that entering it freezes the object, so the registration
data stops changing when the phase closes, not when somebody notices.

## D-C29-3. Immutable is a property rule, not a state

A vastgesteld besluit's date must not change even while the object is
otherwise open. Putting immutability on the property means it holds
regardless of the object's state, which is what the Archiefwet argument
actually asks for.

## D-C29-4. Withdrawn is not deleted

A wrongly filed stuk must go without the record of its arrival going. The
entry leaves the working timeline and stays in the record, with the
withdrawal, its actor and its reason readable by anyone authorised.
