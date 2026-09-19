# Design: department by role matrix

## D-1: the matrix compiles to conditional scopes

Each matrix row `{ value: 'VTH', group: 'handlers', actions: ['read', 'handle'] }`
compiles to a conditional scope on the schema: group `handlers`, actions
`read` and `handle`, condition `object.<field> == 'VTH'`. Rows sharing a group
merge into one scope with an `in` condition. Nothing new at enforcement time,
so PHP and SQL stay identical (rbac-scopes, PHP-side and SQL-side enforcement
MUST be identical).

## D-2: a user's own departments come from one declared source

`matrix.userSource` is either `{ groupPrefix: 'dept:' }` (the user's
Nextcloud groups starting with the prefix, prefix stripped) or
`{ schema: 'person', property: 'department', match: 'userId' }` (the person
object whose `userId` is the current user). A row may use the wildcard value
`$self`, which resolves to the user's own departments at query time through
the existing dynamic-variable mechanism.

## D-3: `handle` is a custom verb

`handle` is not canonical. It is declared through the existing custom-verb
voting pair, so a consuming app maps it (dossiq: may run transitions and
claim). Without a voter it resolves to `update`.

## D-4: admin grid

The schema page gains a Rights tab rendering the matrix as a grid of
departments by role groups with action checkboxes, plus a preview field:
choose a user, see the scopes the compiler produces. The grid writes the
`matrix` block; it never writes compiled scopes.

## D-5: kind

Code, in OpenRegister. Consuming apps declare the matrix in their schema
JSON, so for them it is config.
