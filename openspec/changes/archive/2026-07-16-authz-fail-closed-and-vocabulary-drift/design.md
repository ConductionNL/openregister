# Design

## Verification verdicts (all three verified against HEAD before implementing)

| # | Finding | Verdict | Evidence at HEAD |
|---|---------|---------|------------------|
| 1 | `getRegisterAuthorization()` silent fail-open, cached | **CONFIRMED** | `PermissionHandler.php` — `catch (\Throwable $e) { $this->cachedRegisterAuth[$registerId] = null; return null; }`. No logger call. Callers at `evaluatePermission()` / `MagicRbacHandler` treat `empty()` as open. Proven by test: pre-fix, `read` was **granted** on a thrown mapper. |
| 2 | `x-openregister-seed` in vocabulary, zero engines | **CONFIRMED** | `grep x-openregister-seed lib/**/*.php` → only `Schema.php:2105` (the vocabulary entry itself). No reader. Real seed engine is `ImportHandler` reading `components.objects` / top-level `objects`. |
| 3 | `x-openregister-processing` read but not in vocabulary | **CONFIRMED** | `ProcessingLogService::ANNOTATION_KEY = 'x-openregister-processing'`; vocabulary contained only the *different* key `x-openregister-processing-activity`. |

Two findings were **sharpened** during verification (the audit was directionally
right but imprecise):

- **Finding 1 understated the blast radius.** A *fourth* resolver on the same
  path, `getRegisterForSchema()`, had the identical shape. The audit noted "a
  sibling resolver logs on the same shape; this one doesn't" — implying the
  sibling was fine. It was not: it logged a **warning** and still returned `null`
  → open. **Logging a fail-open does not make it fail closed.** Fixed too.
  The fail-open also reached `MagicRbacHandler`, dropping RBAC **SQL** filtering
  (`bypass => true`), not just the PHP-side verdict.
- **Finding 2's MDM claim is exactly right, and now root-caused.** The 6 trust
  rules sit at `components.schemas.trustConfiguration.x-openregister-seed.objects`
  — a schema annotation nothing reads — while the importer only reads
  `components.objects`. `ImportTrustConfigurationRegister` (the repair step) runs
  fine and imports the register; the *objects* were simply declared in a location
  with no engine. The importer existed; the seed location was phantom.

## The root-cause insight: "not dropped" ≠ "consumed"

The vocabulary/spec contract only ever required an annotation key to
**round-trip** through the configuration column. It never required that anything
**reads** it. A persisted key looks supported to every app that declares it —
the declaration survives a save, a reload, an export, and a test — while the
capability is 100% dead.

This produces a matched pair of defects, and this change closes **both**
directions:

- **Phantom** — key in the vocabulary, no engine (`x-openregister-seed`). The
  declaration persists and no-ops forever. This is the ADR-031 / #396
  anti-phantom principle applied to the vocabulary itself.
- **Silently dropped** — engine reads a key the vocabulary omits
  (`x-openregister-processing`). The engine is live but never receives input.

The tests OR already shipped are the clearest proof of the trap:
`SchemaAnnotationVocabularyTest::testSeedAnnotationSurvivesRoundTrip()` asserted
the seed key round-tripped and treated that as evidence the capability worked.
It was green the entire time the 6 MDM trust rules were never planted. **A
round-trip test on an annotation proves storage, never behaviour.** Those tests
are inverted here to assert the phantom is now rejected loudly.

## Seed Data — decision and evidence

**Decision: REMOVE `x-openregister-seed` from the vocabulary** (do not build an
engine for it), and **relocate** OR's own trust rules to the engine-backed
`components.objects`.

Evidence (read-only `git ls-files` scan across `apps-extra`):

| App | Key | Count | Content |
|-----|-----|-------|---------|
| scholiq | `x-openregister-seed` (singular) | 22 | **All empty arrays** (`[]`) — zero seed data at risk |
| openregister | `x-openregister-seed` (singular) | 1 | The 6 MDM trust rules — **relocated** by this change |
| decidesk | `x-openregister-seed**s**` (plural) | 21 | A *different* key, never in the vocabulary — already dead; not OR's to fix |

Why remove rather than implement:

1. **OR already has a seed engine.** `ImportHandler` plants `components.objects`
   / top-level `objects`, de-duped by `@self` identity, with per-entity
   resilience. Building a second engine for the annotation would create two
   dialects for one capability — the drift this change exists to end.
2. **Nothing real breaks.** The only non-empty declaration is OR's own, and it
   is migrated to the working path in this change. scholiq's 22 are empty.
3. **Removal makes it loud.** An unknown `x-openregister-*` key is recorded in
   `droppedKeys` and warned by `SchemaMapper` — so a future declaration fails
   visibly instead of silently no-oping. That is precisely the #396 anti-phantom
   principle: a declaration that cannot work must fail at declaration time.
4. decidesk's plural `x-openregister-seeds` is likewise inert and should be
   migrated to `components.objects` — filed as follow-up, out of scope here.

## Fail-closed design

`null` and "unresolvable" were the same value, and that conflation *is* the
vulnerability. They are now distinct:

- `null` / `[]` — "no authorization configured". A real answer. Means open.
- `AuthorizationUnresolvableException` — "I could not determine permissions".
  Callers **MUST** deny.

Per call site:

| Caller | Behaviour on unresolvable |
|--------|---------------------------|
| `evaluatePermission()` | log `error`, `return false` (deny) |
| `getReadableByUsers()` | log `error`, `return []` (no broadcast) |
| `resolveRegisterInheritFromPublic()` | `return false` — most-restrictive-wins |
| `getRegisterConfiguration()` | `return null` — config is not an authz verdict |
| `MagicRbacHandler::applyRbacFilters()` | clamp query to `1 = 0` |
| `MagicRbacHandler::buildRbacConditionsSql()` | `['bypass' => false, 'conditions' => []]` |

**The failure is never cached.** The original `$this->cachedRegisterAuth[$id] = null`
in the catch turned a transient blip into a permanent per-request verdict — and
because the poisoned value was `null`, that permanent verdict was *open*.

## Why gate `unsafe-auth-resolver` did not fire

Not a logic gap — a **path gap**. The gate iterates a **non-recursive glob**:

```
for f in lib/Service/*.php lib/Controller/*.php
```

`PermissionHandler.php` lives at `lib/Service/**Object/**PermissionHandler.php`,
one directory deeper, so the gate never opened the file. Measured on this repo:
the glob scans **227 of 1264** `lib/` PHP files — **82% of the tree is unscanned**,
including `lib/Service/Object/`, `lib/Db/`, and `lib/Db/MagicMapper/`.

The gate's *detection* logic would have caught this verbatim: the method name
`getRegisterAuthorization` matches its `[Aa]uthori[sz]ation` regex, and the catch
block contained a bare `return null`. It found nothing because OR's **central
permission resolver** — the one every app inherits — is in a subdirectory.

**Reported as a hydra blind spot: the glob must become recursive** (`find lib -name '*.php'`).
Until then, every gate using this non-recursive-glob idiom under-scans the fleet,
and the deeper a security-critical class sits, the less likely it is to be checked
— exactly backwards.

## ADR-031 alignment

ADR-031 establishes the canonical-dialect principle: one declarative dialect per
capability, and a declaration that cannot be honoured must fail loudly rather
than persist inertly. This change applies it to the annotation vocabulary itself
— the vocabulary is the registry of dialects, so a key in it is a *promise* that
an engine consumes it. Removing `x-openregister-seed` retires a competing dead
dialect in favour of the canonical `components.objects`; adding
`x-openregister-processing` makes the vocabulary honour a dialect the engine
already implements.
