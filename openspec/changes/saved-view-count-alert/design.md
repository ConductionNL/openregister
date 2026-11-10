# Design: saved-view-count-alert

## D-1: the alert is on the view, not a rule on a schema

A schema rule watches objects. A view alert watches a number. Putting it on
the View keeps the query and the threshold together, owned by the same
person, and deletes with the view.

## D-2: count as the owner

The sweep has no user session. It counts with the view owner's RBAC through
`runAs` (ADR-099), so a shared view alerts on what its owner may see and
never leaks a count the owner could not read.

## D-3: fire on the crossing, not on the state

The state machine is `armed → fired → armed`. A count above the threshold
on a `gte` alert fires once and stays `fired` until a sweep sees it below,
then re-arms. That is what stops a standing backlog from paging a team lead
every quarter hour.

## D-4: bounded

Each sweep evaluates only the views whose `every` has elapsed, in one pass
ordered by last evaluated, with a hard cap per pass and a watermark, the
same shape the scheduled notification sweep uses.

## D-5: kind

Code, in OpenRegister. The control's field is nextcloud-vue's.
