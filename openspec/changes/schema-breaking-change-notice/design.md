# Design: schema-breaking-change-notice

Read at openregister development c53dd0685c.

## D-1: who builds on a schema

Two groups, each with a reason to be told and a way to find them:

- **Callers.** Principals in `openregister_api_calls`
  (`lib/Db/ApiCallRecordMapper.php:59`) whose `route` starts with one of the
  schema's object route prefixes and whose `last_seen` falls in the last 90
  days. The recorder collapses only identifiers (`ApiCallRecorder::routeOf()`),
  so a route keeps its register and schema segments; the finder matches both
  spellings a caller can use, slug and id, for register and schema:
  `/apps/openregister/api/objects/{register}/{schema}`. A new
  `ApiCallRecordMapper::findCallersOfRoutes(array $prefixes, DateTime $from, int $limit = 500)`
  returns distinct principals with summed counts, filtered on the
  `idx_or_apicall_seen` index (`lib/Migration/Version1Date20260916070000.php:100`).
  The table holds one row per principal, route, method and version, so the
  scan is over callers, not calls. The anonymous principal (empty string) is
  counted and never notified.
- **Followers.** A new table `openregister_schema_followers` (`schema_id`,
  `uid`, `created`), unique on the pair. `POST` and `DELETE`
  `/api/schemas/{id}/change-followers` add and remove the caller. Following
  requires read on the schema through the same check the schema read endpoint
  uses; a caller who cannot read it gets 404.

## D-2: the administrator sees the reach before acknowledging

`SchemaVersioningService::enforceGate()` (`lib/Service/Schema/SchemaVersioningService.php:112`)
throws `BreakingSchemaChangeException`; its `toResponse()`
(`lib/Exception/BreakingSchemaChangeException.php:70-82`) gains
`affectedCallers: {count, top: [{principal, calls, lastSeen}]}` (ten busiest)
and `followers: n`, filled by the controller before it answers 409
(`lib/Controller/SchemasController.php:1146-1149`). Only administrators and
schema managers reach this gate, and `/api/callers` already shows them the same
record.

## D-3: the notice goes out after the change is applied

After `recordChangelog()` succeeds for a breaking, acknowledged change
(`SchemasController.php:1182-1188`), the controller queues
`SchemaChangeNoticeJob` with the changelog id and the optional `changeNotice`
(at most 500 characters, plain text). The job resolves callers and followers
(D-1), removes users who can no longer read the schema, deduplicates, caps at
500 recipients per change (the rest counted, not told) and sends one
Nextcloud notification per recipient with subject `schema_breaking_change`,
rendered by a new case in `Notifier::prepare()` (`lib/Notification/Notifier.php:218-231`)
in the recipient's language. The notification links to the changelog. The job
writes `noticeSentTo` (count) and `noticeSentAt` onto the changelog entry.

A user who called the schema and also follows it is told once.

## D-4: the machine signal

A response listener on the object read and list routes adds, for 30 days after
the latest breaking changelog entry of the schema answered:

- `OpenRegister-Schema-Changed: version="<v>", at="<ISO date>", breaking`
- `Link: </index.php/apps/openregister/api/schemas/<id>/changelog>; rel="describedby"`

It reads the latest breaking entry per schema from a per-request memo, so a
list of 500 objects does one lookup, not 500 (openregister ADR-009 Rule 1).

## Declarative-vs-imperative decision

Imperative. ADR-031's notification dialect is declared on a schema and fires on
object events; this notice is about the schema itself, triggered by an
administrator's act, and its recipients come from the caller record, which no
schema declaration can name. The notice reuses the Nextcloud notification
channel through the existing `Notifier`, not a parallel sender.

## Risks

- Disclosure: callers learn nothing about each other. The top-ten list is in the
  409 only, which only an administrator or schema manager can receive. A
  notified user sees the schema they already call.
- Noise: one notice per change per recipient, 500 recipients at most, callers
  limited to 90 days.
- Performance (hydra ADR-058): the caller finder is an indexed, capped read on
  a small table; the header costs one memoised lookup per request.
- The caller record can be switched off (`ApiCallRecorder::ENABLED_KEY`); then
  only followers are told, and the 409 says the record is off rather than
  reporting zero callers.
