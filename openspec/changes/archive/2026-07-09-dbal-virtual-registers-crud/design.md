# Design: dbal-virtual-registers-crud

## Context

v1 (merged) serves external databases live and rejects every write at two dispatch points: `SaveObject.php` ~2796 (`$persist === true && $objectSource !== null` → RuntimeException, surfaces as 403) and `DeleteObject.php` ~507. Validation (`ValidateObject`, opis/json-schema) runs BEFORE the SaveObject guard — live-observed on ocdemo: a bad-typed POST returns 400 before the read-only 403 — so external writes inherit schema validation with no extra work. The schema annotation dialect already reserves `readOnly?: bool` (`Schema::getObjectSource()` shape, Schema.php ~1587).

## Decisions

### D1 — Seam: `WritableObjectSourceProvider extends ObjectSourceProvider`
New interface in `lib/Service/ObjectSource/`:
- `insert(Register $register, Schema $schema, array $data, array $config): ObjectEntity`
- `update(Register $register, Schema $schema, string $id, array $data, array $config): ObjectEntity`
- `remove(Register $register, Schema $schema, string $id, array $config): bool`

Only `DbalObjectSourceProvider` implements it. The dispatch checks `instanceof WritableObjectSourceProvider`; the eight native providers keep today's unconditional rejection with zero code change. **Rationale:** interface segregation — read-only is the contract default; writability is an explicit capability, never inferred.

### D2 — Opt-in: Source flag, stamped into the annotation, re-checked live
- Admin sets `authConfig.writable = true` on the `type: database` Source (UI toggle + API; the flag is non-secret and survives the custody sanitizer).
- Introspection/re-introspection stamps `x-openregister-object-source.readOnly = !writable` on every produced schema (views always `readOnly: true`).
- **Write dispatch requires BOTH**: annotation `readOnly === false` AND the backing Source's `authConfig.writable === true` resolved at write time. Flipping the flag off re-locks writes immediately — no re-introspection needed; a stale annotation alone can never authorize a write (fail closed, ADR-005).
- The real outer boundary remains the external DB user's grants; docs recommend a least-privilege user (SELECT-only until writes are wanted).

### D3 — SQL write mapping
- Column allowlist = the introspected scalar columns (same `scalarColumns()` set the read path uses). Properties outside it → typed 400 (`unknown property`), never silently dropped — consistent with validation strictness.
- Relation properties (`$ref` + `related-object`) write their raw FK value.
- INSERT: absent columns are omitted (external defaults apply); generated single-column PK returned via `RETURNING` on PostgreSQL, `lastInsertId()` on MySQL/SQLite; the created `ObjectEntity` is re-read through `find()` so the response reflects DB-applied defaults.
- UPDATE/DELETE predicate: single-column PK `WHERE idColumn = :id`; composite PK reconstructed by splitting the joined object id (`COMPOSITE_ID_SEPARATOR`) across `idColumns` — part-count mismatch → 400. Affected-rows 0 → DoesNotExistException (404 parity).
- **No-PK tables**: `insert` allowed (append-only), `update`/`remove` rejected (no addressable predicate). **Views**: never writable.
- All statements through the DBAL query builder — bound parameters, platform-quoted identifiers (ADR-005), single-statement writes.

### D4 — Error mapping (sanitized)
New `DbalWriteException` (message safe for clients, carries HTTP status), mapped by the existing `ObjectSourceErrorMiddleware` (which also logs the wrapped DBAL exception for diagnosis):

| SQLSTATE class | Meaning | HTTP |
|---|---|---|
| 23505 | unique violation | 409 |
| 23503 | foreign-key violation | 409 |
| 23502 | not-null violation | 422 |
| 23514 | check violation | 422 |
| 22xxx | data/type errors (e.g. 22P02) | 422 |
| connection refused/lost | unreachable | 503 |
| other DBAL errors | upstream error | 502 |

Client messages name the constraint CLASS only ("a unique constraint on the external table was violated") — never SQL, table internals beyond the schema the caller already sees, or credentials.

### D5 — RBAC ordering (no oracle)
Schema-level `checkPermission('create'|'update'|'delete')` runs in the dispatch BEFORE the provider — and therefore the external database — is consulted, mirroring the v1 read path. Denied write → same NotAuthorizedException semantics as native schemas.

### D6 — Audit
External writes record audit-trail rows through the existing audit mechanism where the save/delete paths already emit them (uuid = external key, register/schema of the virtual schema). If the audit handler proves hard-coupled to native rows at implementation time, external writes MUST still log a structured (secret-free) log line and the gap gets a follow-up issue — silent no-audit is not acceptable.

### D7 — Non-goals (explicit)
- No optimistic locking / versioning: external rows have no OR lock; concurrent writes are last-write-wins.
- No multi-object transactions; each write is a single statement.
- No cascade semantics: relation integrity on write is whatever the external FK constraints enforce (mapped per D4).
- Native NC providers stay read-only; `TablesSchemaSyncService` annotations (`readOnly: true`) unaffected.
- Files/locks/folder features of native objects do not apply to virtual objects.

## Declarative-vs-imperative decision (ADR-031)
Write-through to an external database over Doctrine DBAL is an external integration — the canonical ADR-031 exception. No lifecycle/aggregation/notification behaviour is added; the only declarative artefact remains the `x-openregister-object-source` annotation (gains no new keys; `readOnly` already exists in the dialect).

## Seed Data (ADR-001)
- Unit/integration fixture: the existing SQLite permits fixture (`tests/fixtures/dbal/build-permits-sqlite.php`) reused with a WRITABLE source config (`authConfig.writable: true`); write tests insert/update/delete `permits` rows and assert directly against the SQLite file.
- Live demo: the ocdemo `permits_external` PostgreSQL database (applicants / permit_types / permits + `active_permits` view) — flip the demo source writable and CRUD a permit end-to-end (API + UI).

## Security (ADR-004 / ADR-005)
- Credential custody unchanged: same vault-resolved connection; the writable flag is metadata, never a secret.
- Default-off: fresh and existing database sources are read-only until an admin opts in; the live Source check (D2) fail-closes on resolution errors.
- Injection: bound parameters + platform identifier quoting on every write; column allowlist from introspection (user input never names a column that isn't allowlisted).
- No oracle: RBAC precedes external contact (D5); constraint errors are sanitized (D4).
- Recommendation (docs): grant the external DB user INSERT/UPDATE/DELETE only on the intended tables.

## Mixed-spec rationale
Single `kind: code` change: PHP + one Vue toggle, no register JSON. The hydra ADR-064 amendment ships as a separate docs PR in the hydra repo.
