# Design — optimize-write-path-performance

## D1. Bulk-save classification repair is a prerequisite, not scope creep

The brief for finding 1 assumed `emitChunkSideEffects()` runs per-object audit
INSERTs. Live verification (2-object bulk save against the dev instance)
showed it never runs: `buildChunkResults()` gated on
`isset($firstItem['created'], $firstItem['updated'])`, keys that raw
magic-table rows (metadata columns `_created`/`_updated`) never have. The
response reported `saved_count: 0`, buckets empty, zero audit rows, zero
events. Batching a dead code path would have shipped a lie, so the gate is
fixed to the mapper's real contract (`object_status`, set by
`MagicBulkHandler::executeUpsertChunk()`), and rows are converted through the
canonical `MagicMapper::convertRowToObjectEntity()` (register/schema context
resolved via SaveObjects' existing static caches).

Trade-off: the response buckets now carry `ObjectEntity::jsonSerialize()`
output (parity with the single-object save path) instead of the raw DB rows
they would have carried had the gate ever matched. Conversion failures fall
back to the raw row (minus internal bookkeeping keys) rather than dropping
the object from the response.

## D2. Batched audit inserts must reuse the single-row builder

`AuditTrailMapper::createAuditTrail()`'s body (diff computation, user/session/
request stamping, AVG processing-activity resolution, import-job tagging,
size/expiry) is extracted verbatim into `buildAuditTrail()`;
`createAuditTrail()` is now `insertHashChained(buildAuditTrail(...))` and the
new `insertAuditTrails()` builds every row through the same method — row
content cannot drift between the paths. Value conversion for the raw
multi-row INSERT mirrors `QBMapper::insert()`'s parameter types exactly
(`json` → `json_encode`, `datetime` → `Y-m-d H:i:s`), asserted by unit test.

Chunk size 100 rows/INSERT: audit rows are small (bounded `changed` JSON),
100 stays far under `max_allowed_packet` while collapsing per-row round trips.

## D3. Batched hash-chain sealing (`AuditHashService::sealRows()`)

Per-row sealing costs 3 queries (row SELECT, prev-hash SELECT, UPDATE).
`sealRows()` does one range SELECT over `[min(id), max(id)]`, one
prev-hash SELECT, computes the chain in PHP with the same
`mapRowToEntity()`/`getCanonicalJson()`/`computeHash()` primitives
`verifyChain()` uses, and persists with one CASE-based UPDATE.

Interleaving safety: concurrent writers can insert rows between the batch's
ids. All UNSEALED rows in the range are sealed (their hash is deterministic —
same canonical JSON, same predecessor — so this equals what their own
`sealRow()` would compute), and already-sealed rows are left untouched but
contribute their stored hash as the chain link. `verifyChain()` therefore
verifies the same chain it would have seen under per-row sealing. Sealing
stays fail-soft exactly like `insertHashChained()`: rows are never lost to a
sealing hiccup, they are left unhashed and logged.

## D4. Real pre-update state without extra queries

`MagicBulkHandler::executeUpsertChunk()` already SELECTed the pre-upsert
`_uuid`s for classification; widening that to `SELECT *` (same query count,
same predicate) retains the full pre-update rows. Rows classified `updated`
carry `_pre_update_row`, which `SaveObjects` converts to an `ObjectEntity`
and feeds into both the audit entry (`old`) and
`ObjectUpdatedEvent(oldObject: …)`. The key is stripped before rows reach the
API response. Fallback: when no pre-update row could be reconstructed the
persisted entity is passed as `old` (previous behaviour), so the row still
records an `update` action rather than being misclassified as `create`.

## D5. Listener classification (finding 3) — full table

Registered in `lib/AppInfo/Application.php` `registerEventListeners()`
(post-change lines ~2294–2520). "Session-entangled" means the listener (or a
service it calls) resolves the acting user from `IUserSession` — a background
job runs without a session, so deferral would silently change RBAC scope,
target calendars/addressbooks, or recorded actor identity. Per the brief:
in doubt → inline, do not break behaviour to chase speed.

### Pre-save (`ObjectCreatingEvent` / `ObjectUpdatingEvent`) — MUST stay inline

| Listener | Why inline |
|---|---|
| LifecycleInitialStateListener | Writes `x-openregister-lifecycle` initial state INTO the payload before persistence. |
| LifecycleValidationListener | Validates/blocks the write before persistence. |
| CalculationOnSaveListener | Materialises `x-openregister-calculations` into the payload; the save response returns these fields. |
| QualityScoreOnSaveListener | Materialises the quality score into the payload pre-persistence (brief assumed post-save — it is not). |
| SurvivorshipRecomputeListener | Materialises the golden record into the payload pre-persistence (brief assumed post-save — it is not). |

### Post-save (`ObjectCreated/Updated/DeletedEvent`) — verdict per listener

| Listener | Events | What it does | Verdict | Rationale |
|---|---|---|---|---|
| ObjectChangeListener | C,U | Text-extraction trigger | inline (already deferred) | Enqueues `ObjectTextExtractionJob`; inline cost is one job insert. |
| WebhookEventListener | C,U,D | Webhook delivery | inline (already deferred) | `WebhookService::dispatchEvent` enqueues `WebhookDeliveryJob` per ADR-009 Rule 5. |
| ActionListener | C,U,D | Action-registry triggers | inline (already deferred) | Action executor schedules `ActionRetryJob`/queued execution; matching is a cheap lookup. |
| AggregationCacheInvalidationListener | C,U,D | Drops aggregation cache keys | inline | Must precede any same/next-request read; cache ops are O(1). Deferring reintroduces stale-read windows. |
| RealtimeEventListener | C,U,D | 1 INSERT into realtime feed | inline | Latency-sensitive (drives live UI polling) and records the session actor. |
| NotifyPushListener | C,U,D | notify_push nudge | inline | Cheap push; has its own request-static dedupe/batch mode for bulk imports. |
| GraphQLSubscriptionListener | C,U,D | Buffers event for SSE subscriptions | inline | One cache/buffer write; deferral adds up-to-cron latency to a "realtime" surface. |
| NotificationDedupePruneListener | D | Prunes dedupe rows | inline | Single bounded DELETE. |
| ActivityEventListener | C,U,D | NC activity entry | inline | Activity author = session user; deferral would misattribute. Cost ~1 publish. |
| HookListener | C,U,D | Schema-declared hooks | inline | Hook contract allows observing the fresh write synchronously; failure/retry semantics live in `HookRetryJob` already. In doubt → inline. |
| TranslationProjectionListener | C,U,D | Upserts translation projections | inline (candidate, blocked) | Write-amplifier, BUT the projection service records the translator from `IUserSession`; a job would stamp null/System. Deferral needs an actor-forwarding contract first — noted, not done. |
| AnnotationNotificationListener | C,U + Transitioned | `x-openregister-notifications` dispatch (can do sync HTTP/mail) | inline (candidate, blocked) | Dispatcher resolves the acting user from the session (actor exclusion / attribution). Same actor-forwarding blocker. |
| AggregationThresholdListener | C,U,D | Re-evaluates threshold notifications (aggregation queries + cache edge state) | inline | Early-outs unless the schema declares threshold triggers; dispatches through the session-reading notification dispatcher (same blocker as above). |
| SourceRecordChangeListener | C,U,D | Recomputes reverse-FK masters (nested full saves) | inline + **index cached** | Master lookup runs `ObjectService::find(_rbac: true, _multitenancy: true)` — session-scoped authorization; a job would evaluate RBAC as no-user. The unconditional cost (all-schemas `findAll()` per request for the reverse index) IS fixed: distributed cache, TTL 1h, eagerly invalidated on Schema created/updated/deleted events. |
| ObjectCleanupListener | D | Cleans notes/tasks/emails/calendar/contacts/deck links | inline | `CalendarEventService::getEventsForObject()` → `findUserCalendar()` and `ContactService` resolve the CURRENT user's calendar/addressbook; a job without a session would silently miss the cleanup targets. |

**Net decision: zero listeners moved to background jobs.** The safe deferrals
already exist (webhooks, text extraction, actions); the remaining heavy ones
are blocked on session/actor context that NC background jobs do not have. A
follow-up "actor-forwarding deferral contract" (capture uid at enqueue,
re-run listener work under an explicit actor parameter instead of ambient
session) is the prerequisite for deferring TranslationProjection /
AnnotationNotification / SourceRecordChange and is deliberately out of scope
here.

## D6. Validator memoization (`ValidateObject`)

Request-scoped (service-instance) caches only — no cross-request state:

- `getValidator()`: one Opis `Validator` with `setMaxErrors(100)`, the three
  custom formats (bsn, semver, ISO-8601 date-time) and the `http` `$ref`
  resolver protocol registered once. The shared loader also memoizes resolved
  `$ref` schema documents (each of which costs a `SchemaMapper::find()`
  through `resolveSchema()`).
- `preparedSchemaCache` keyed `"{schemaId}:{schemaVersion}"` — mirrors
  `SchemaMapper::findCache`'s invalidation-by-version approach: a schema
  update bumps the version, producing a new key; stale entries are never
  served. Cached artifact = fully prepared schema object (transform → clean →
  computed strip from `required` → null-type widening) + derived
  computed-property and required-field lists. The pipeline output depends
  only on the schema, never on the object being validated (verified:
  `transformSchemaForValidation()` returns the object untouched).
- Caller-supplied custom `$schemaObject`s bypass the cache (no stable key).
- Object-side steps (unique-field check, extended-type check, computed-field
  removal from the input, empty-value filtering) still run per object.

Fixed while extracting: computed-property stripping was dead code — the
cleaning step removes the per-property `computed` marker, and the old strip
loop looked for it AFTER cleaning, so it never found anything. Computed names
are now collected before cleaning, restoring the documented behaviour
(computed fields are system-generated and excluded from user-input
validation). `schema: int|string` arguments are now resolved to the entity
once up front (previously an int reaching `validateUniqueFields(Schema $…)`
would have fatal'd), and a null schema skips the unique-field check instead
of a TypeError.

## D7. Reverse-FK index cache invalidation

The index derives exclusively from schema configurations
(`x-openregister-survivorship` / `x-openregister-merge` `sourceLink`
declarations), so Schema created/updated/deleted events are the exact
invalidation surface; the listener itself now subscribes to them and drops
the cache key. TTL (1h) is only a safety net for out-of-band schema changes
(raw SQL, instances without a distributed cache). Failure mode of staleness
is bounded: a newly declared reverse-FK link's recomputes lag at most until
invalidation/TTL; no data is lost (the master recomputes on its next source
change or own save).
