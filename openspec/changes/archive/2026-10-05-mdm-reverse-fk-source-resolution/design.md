# Design — mdm-reverse-fk-source-resolution

## Context

The survivorship/merge engine (changes `mdm-survivorship-engine`,
`mdm-merge-engine`) resolves a master's competing source records from an
**embedded** `sourceLinkField` on the master payload. `SurvivorshipResolver`
itself is already pure — it consumes a resolved `array $sourceRecords` and reads
each entry's `values`/`mappedAttributes`. The embedded assumption lives entirely
in the two *callers* that build that array:

- `SurvivorshipRecomputeListener::loadSourceRecords($data, $sourceLinkField)`
- `MergeService::loadSourceRecords($data, $sourceLinkField)` (+ `relinkSourceRecords`, reversal `restoreSourceLink`)

The canonical MDM model is reverse-FK: source records are separate objects
pointing up at the master. pipelinq's `sourceRecord` schema carries
`currentMasterEntity` (the master's UUID), `mappedAttributes` (the competing
values), `sourceSystem`, and `lastChange` (freshness anchor). The `masterEntity`
schema has no `sourceRecords` property, so embedded resolution finds nothing and
the golden record projects empty.

## Approach

### A. Annotation: an optional `sourceLink` block

Both `x-openregister-survivorship` and `x-openregister-merge` gain:

```jsonc
"sourceLink": {
  "mode": "reverseFk",              // "embedded" (default) | "reverseFk"
  "sourceSchema": "sourceRecord",   // slug or id of the source schema
  "referenceField": "currentMasterEntity", // field on a source holding the master UUID
  "sourceRegister": "<slug|id>"     // optional; defaults to the master's register
}
```

Absent `sourceLink` ⇒ embedded mode ⇒ byte-for-byte current behaviour. The
existing `sourceLinkField` stays required for embedded mode and is ignored in
reverseFk mode. Both annotation validators accept the block and, on a malformed
reverseFk block (missing `sourceSchema`/`referenceField`), degrade to a logged
warning + embedded fallback — matching the engine's established "malformed
annotation is non-fatal" rule.

### B. One shared resolver, two callers

Introduce `lib/Service/Survivorship/SourceRecordResolver.php` — a small service
with:

```
resolveSources(array $masterData, string $masterUuid, array $survivorshipConfig): array
```

It branches on `sourceLink.mode`:
- **embedded** — the exact current logic (embedded records + `ObjectService::find`
  on uuid strings), moved out of the two callers verbatim.
- **reverseFk** — `ObjectService` query of `sourceSchema`/`sourceRegister` where
  `referenceField === $masterUuid`, returning each hit's `->getObject()`.

`SurvivorshipRecomputeListener` and `MergeService::recomputeSurvivor` both call
`resolveSources(...)` instead of their private `loadSourceRecords`. This removes
the duplicated embedded logic and gives reverseFk one implementation.

**Master identity.** reverseFk needs the master's UUID, which the payload alone
may not carry. Both call sites already hold it: the listener has the saved
`ObjectEntity` (`->getUuid()`); `MergeService` has `$intoObject`/`$fromObject`
(`->getUuid()`). We thread the uuid in explicitly rather than digging it out of
`$data`.

**Query API.** Use `ObjectService::findAll` with a `referenceField => uuid`
filter, scoped to the source register+schema, `_rbac: true`,
`_multitenancy: true` (mirrors the existing `find` call in embedded mode). The
filter field is the raw property name; if `findAll`'s filter surface cannot
express it directly we fall back to `findAll` on the source schema + an in-PHP
`referenceField` equality filter (source-record counts per master are small —
a handful of systems — so this is cheap).

### C. Merge relink/reverse becomes mode-aware

`MergeService`:
- **embedded** (unchanged): `relinkSourceRecords` array-merges the survivor's
  `sourceLinkField`; reversal restores the survivor/loser payloads.
- **reverseFk**: on merge, for each source object currently referencing the
  losing master, persist `referenceField := survivorUuid` (an
  `ObjectService::save` per source). The snapshot records
  `{sourceUuid, priorReference}` per moved source. `recomputeSurvivor` then
  resolves the survivor's sources by query (they now include the moved ones).
  Reversal writes each snapshot's `priorReference` back to its source object and
  recomputes both masters.

### D. Source-change recompute trigger

`SurvivorshipRecomputeListener` fires on master save. In reverseFk mode the
master must also recompute when a *source* changes. Add a
`SourceRecordChangeListener` on `ObjectCreatedEvent`/`ObjectUpdatedEvent`/
`ObjectDeletedEvent`:

1. Look up survivorship annotations across schemas whose
   `sourceLink.mode = reverseFk` and `sourceLink.sourceSchema` matches the saved
   object's schema. (Reverse index built from `SchemaMapper`; the source schema
   itself carries no annotation — the master does.)
2. For each match, read `masterUuid = savedSource[referenceField]` (and the
   *prior* value on update/delete, so a reassigned or removed source recomputes
   **both** the old and new master).
3. Recompute by re-persisting the referenced master through `ObjectService`,
   which re-runs `SurvivorshipRecomputeListener` (reverseFk source resolution +
   materialisation). No new materialisation path; no listener loop (a master
   save does not fire the source-change listener).

Resilience: each master recompute is wrapped so a failure is logged and never
aborts the source object's own save/delete.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| Golden-record survivorship (derived field) | **Declarative surface, imperative engine** | The *what* is declared in the schema annotation (`x-openregister-survivorship` + new `sourceLink`); the recompute is executed by the existing OpenRegister survivorship **engine/listener**, not a per-app service. This change extends that engine — it does not add an app-side `*Service.php`. |
| Source-change → master recompute (reactive) | **Imperative listener (engine)** | This is a cross-object reactive recompute over RBAC/multitenancy-scoped reads — the same class of concern as the existing `SurvivorshipRecomputeListener` and `QualityScoreOnSaveListener`. It is an engine listener inside OpenRegister, keyed off the declarative `sourceLink` annotation, not a schema-declared `x-openregister-*` derived field (a materialised aggregate can't express "requery a foreign schema and rematerialise another object"). This is the ADR-031 lifecycle-guard/engine exception, consistent with how survivorship already ships. |

No new app-side service class, no n8n workflow. The only declarative artefact a
*consumer* touches is the `sourceLink` annotation on its master schema.

## Seed Data

This change modifies engine behaviour and adds **no OpenRegister schema**, so it
contributes no `_registers.json` seed objects. Its behaviour is proven against
the consumer's real schemas (pipelinq `masterEntity`/`sourceRecord`) by the
`mdm-views-route-scoping-e2e` fixture, which — as part of this change — is
extended to create linked `sourceRecord` objects (two competing systems,
different trust tiers) referencing a seeded `masterEntity` via
`currentMasterEntity`, so the merge-execute+reverse and conflict-resolution
specs run against a populated golden record instead of skipping. Representative
source objects mirror general organisation data (e.g. a municipality contact
supplied by both a CRM at `silver` and a KvK feed at `gold`).

## Findings from the true e2e (12/12 green)

Proving this change against a live isolated instance (the `mdm-views-route-scoping-e2e`
suite, extended with real reverse-FK `sourceRecord` objects) took the merge-execute
and conflict-save write-back chains all the way to green, and surfaced three things
worth recording — none of them reverse-FK resolution bugs:

1. **Space-date round-trip (fixed here).** OpenRegister stores `date-time` values
   as `YYYY-MM-DD HH:MM:SS`, a form its OWN schema validation then rejects against
   the `date-time` format on a getObject → mutate → saveObject round-trip. The
   reverse-FK relink / recompute re-persist whole objects, so they must normalise
   the untouched date fields first — see `MergeService::normaliseRoundTripDates()`,
   applied in the relink, the reversal, and the source-change recompute.

2. **Mid-save slug resolution (worked around here).** `ObjectService::setSchema()` /
   `SchemaMapper::find()` resolve a schema/register by *slug* via a DB lookup that
   can miss inside a save transaction, while the *numeric-id* path is robust. The
   reverse-FK query therefore resolves the source schema to a numeric id first
   (`SourceRecordResolver::schemaQueryFilter()`, cached) and the read-surface
   endpoints (sources / merge preview) warm that cache in a clean context.

3. **Follow-ups (OUT OF SCOPE here — tracked for the owning changes):**
   - *OpenRegister should auto-seed the `merge-operation` + `trust-configuration`
     registers* (a repair step, like the virtual-schema seeders). They ship as
     `lib/Settings/{merge_operation,trust_configuration}_register.json` but the
     `mdm-merge-engine` / `mdm-survivorship-engine` changes never seed them, so the
     write-back has nowhere to persist on a fresh instance. The e2e fixture imports
     them as a test prerequisite; production needs the repair step. Belongs to
     `mdm-merge-engine` / `mdm-survivorship-engine`.
   - *Duplicate detection should exclude lifecycle-merged masters* (status =
     `merged-into-other`) so an already-merged object is not re-offered as a
     candidate. Belongs to `mdm-dedup-nested-paths` / the dedup engine.

## Risks / alternatives

- **Re-persisting the master to recompute** (D3) is simple and reuses the
  materialisation path, at the cost of an extra object write per source change.
  Acceptable — source changes are steward/sync-paced, not hot. Alternative
  (call the resolver + write only materialised fields) is a later optimisation.
- **Reverse-FK query cost**: bounded by sources-per-master (a few systems).
  No pagination concerns.
- **Backward compatibility**: gated entirely behind the presence of a
  `sourceLink.reverseFk` block; every existing embedded schema is untouched.
