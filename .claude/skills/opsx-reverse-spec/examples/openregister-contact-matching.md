# Example — `/opsx-reverse-spec openregister --cluster contact-matching`

Worked example of a real reverse-spec run against OpenRegister's `ContactMatchingService` — a
whole-service Bucket 2b cluster with no existing spec capability. Uses `--cluster` because
"contact matching" is genuinely new territory, not an extension of something like
`deep-link-registry` or `object-interactions`.

Use this example to calibrate REQ granularity, scenario voice, and the inline annotation
commit structure.

---

## Input context

- **App**: `openregister`
- **Coverage report cluster**: `bucket_2b["contact-matching"]`
- **Methods in cluster** (5 public, 5 private helpers — private methods inherit REQ tags from their caller):

  | File::method | Observed behavior note |
  |---|---|
  | `lib/Service/ContactMatchingService.php::matchByEmail` | Case-insensitive email match, confidence 1.0, cached |
  | `lib/Service/ContactMatchingService.php::matchByName` | Name-part fuzzy match, 0.7 full / 0.4 partial |
  | `lib/Service/ContactMatchingService.php::matchByOrganization` | Organization match, 0.8 confidence |
  | `lib/Service/ContactMatchingService.php::matchContact` | Orchestrator; combines the three matchers + dedupes |
  | `lib/Service/ContactMatchingService.php::invalidateCache` | Per-email cache invalidation |

5 methods, 5 distinct observable behaviors → exactly at the 5-REQ cap. No split needed.

---

## Step 3 — Load the cluster (decision log)

From `coverage-report.json`:

```json
{
  "buckets": {
    "bucket_2b": {
      "contact-matching": {
        "reason": "Cohesive service, no @spec tags, no existing capability spec covers contact matching",
        "methods": [
          "lib/Service/ContactMatchingService.php::matchByEmail",
          "lib/Service/ContactMatchingService.php::matchByName",
          "lib/Service/ContactMatchingService.php::matchByOrganization",
          "lib/Service/ContactMatchingService.php::matchContact",
          "lib/Service/ContactMatchingService.php::invalidateCache"
        ],
        "branch": "development"
      }
    }
  }
}
```

Granularity call: `getRelatedObjectCounts()` and `invalidateCacheForObject()` were in the
cluster but folded into `invalidateCache` behavior (same REQ). `formatMatch()` is a
pure helper — annotated, not its own REQ.

---

## Step 6 — Drafted REQs

File written to
`openregister/openspec/changes/retrofit-contact-matching-2026-04-21/specs/contact-matching/spec.md`:

```yaml
---
retrofit: true
capability: contact-matching
status: retrofit-draft
---
```

### REQ-001 — Match contacts by email (exact, case-insensitive)

**Description**: The system SHALL locate contacts whose email properties match a given
address exactly, ignoring case and surrounding whitespace. Email matching is the highest-
confidence match type and returns confidence 1.0.

**Scenarios**:
- WHEN a caller submits `Alice@Example.com ` THEN the system SHALL search all registered
  schemas for properties in `EMAIL_PROPERTY_PATTERNS` matching `alice@example.com` exactly
- WHEN no matching contact exists THEN the system SHALL return an empty array
- WHEN the email is empty or whitespace-only THEN the system SHALL return an empty array
  without querying storage

**Notes**: Current implementation caches results under a SHA256(email) key for
`CACHE_TTL`; cached results carry a `cached: true` marker. TODO: cache-key collision risk
if `EMAIL_PROPERTY_PATTERNS` changes — not addressed by this retrofit.

### REQ-002 — Match contacts by display name (fuzzy, confidence-scored)

**Description**: The system SHALL locate contacts whose name properties contain all parts
of a given display name. Matches are scored: a full multi-part match returns confidence
0.7; a partial (subset) match returns 0.4.

**Scenarios**:
- WHEN a caller submits `"Jan de Vries"` THEN the system SHALL split the name into parts
  `["Jan", "de", "Vries"]` and return matches where every part appears in a name property,
  confidence 0.7
- WHEN only `"Jan Vries"` matches part of the stored name THEN the system SHALL return
  that match with confidence 0.4
- WHEN the name is null or empty THEN the system SHALL return an empty array

**Notes**: The 0.7 / 0.4 thresholds are magic numbers in `countMatchingNameParts()` —
flagged for future REQ tightening once a tuning study exists.

### REQ-003 — Match contacts by organization (medium-high confidence)

**Description**: The system SHALL locate contacts whose organization properties match a
given organization name. Organization matching returns confidence 0.8 — stronger than
name (ambiguous) but weaker than email (unique).

**Scenarios**:
- WHEN a caller submits `"Gemeente Utrecht"` THEN the system SHALL return contacts whose
  organization property matches, confidence 0.8
- WHEN the organization is null or empty THEN the system SHALL return an empty array

### REQ-004 — Combined contact match (orchestrated, deduplicated)

**Description**: The system SHALL accept any combination of email, name, and organization
inputs, run the available single-field matchers, and return deduplicated results sorted
by confidence. Callers SHALL NOT need to invoke the single-field matchers separately to
get a ranked result.

**Scenarios**:
- WHEN a caller submits all three fields THEN the system SHALL run all three matchers
  and merge results, deduplicating by object ID, keeping the highest confidence score per
  contact
- WHEN a caller submits only email + name THEN the system SHALL skip the organization
  matcher and merge the other two
- WHEN no fields are submitted THEN the system SHALL return an empty array

### REQ-005 — Invalidate match cache on contact mutation

**Description**: The system SHALL invalidate cached match results for a contact when that
contact's identifying properties change. Callers that mutate contact data SHALL be able
to trigger invalidation by email or by object payload.

**Scenarios**:
- WHEN a caller invokes invalidation with an email THEN the system SHALL clear cached
  results for that email and leave other cache entries untouched
- WHEN a caller invokes invalidation with a full contact object THEN the system SHALL
  derive the cache keys for every email property the object holds and clear each

**Notes**: `invalidateCacheForObject()` currently reads `EMAIL_PROPERTY_PATTERNS` but does
not invalidate name/organization caches — observed, not a bug to fix here. Noted for a
future REQ.

---

## Step 9 — tasks.md

```markdown
# Tasks

- [x] task-1: contact-matching#REQ-001 — Match contacts by email (retroactive annotation)
- [x] task-2: contact-matching#REQ-002 — Match contacts by display name (retroactive annotation)
- [x] task-3: contact-matching#REQ-003 — Match contacts by organization (retroactive annotation)
- [x] task-4: contact-matching#REQ-004 — Combined contact match (retroactive annotation)
- [x] task-5: contact-matching#REQ-005 — Invalidate match cache (retroactive annotation)
```

REQ → task → method map:

| REQ | task | Methods tagged |
|---|---|---|
| REQ-001 | task-1 | `matchByEmail`, helpers: `searchAndFilter`, `hasMatchingProperty`, `formatMatch` |
| REQ-002 | task-2 | `matchByName`, helpers: `searchAndFilterByName`, `countMatchingNameParts` |
| REQ-003 | task-3 | `matchByOrganization` |
| REQ-004 | task-4 | `matchContact`, `getRelatedObjectCounts` |
| REQ-005 | task-5 | `invalidateCache`, `invalidateCacheForObject` |

Private helpers inherit the REQ tag of the public method they support — no separate REQs.

---

## Step 12 — Annotation example

Inline single-pass edit on `ContactMatchingService::matchByEmail`:

```php
    /**
     * Match a contact by email address (high confidence).
     *
     * @spec openspec/changes/retrofit-contact-matching-2026-04-21/tasks.md#task-1
     *
     * @param string $email The email address to match
     *
     * @return array The match results with confidence 1.0
     */
    public function matchByEmail(string $email): array
```

Same pattern for the other four public methods. Private helpers get `@spec` tags pointing
at the task of their public caller.

---

## Step 15 — Commit structure

```
retrofit: draft contact-matching spec + annotate 10 methods

- New capability: contact-matching (5 REQs)
- Annotated 5 public + 5 private methods in ContactMatchingService
- Ghost change: retrofit-contact-matching-2026-04-21
```

```
retrofit: archive contact-matching change

Merges specs/contact-matching/spec.md with retrofit: true into main.
Specter sync ran clean.
```

Second commit SHA appended to `.git-blame-ignore-revs`.

---

## Step 17 — Summary block

```
## Reverse-Spec Complete — openregister / contact-matching

Ghost change:   retrofit-contact-matching-2026-04-21 (archived)
REQs drafted:   5
Methods tagged: 10 (5 public + 5 private)
Specter:        registered (retrofit cohort via sync_spec_content.py)
Branch: retrofit/reverse-spec-openregister-contact-matching-2026-04-21
PR: https://github.com/ConductionNL/openregister/pull/####

Remaining Bucket 2 clusters (from current report): 12

Next:
- /opsx-reverse-spec openregister --cluster deep-link-registry (smaller, 3 methods)
- Or /opsx-coverage-scan openregister to refresh the report after this merges
```

---

## Calibration takeaways (feed into learnings.md after the run)

- **5-REQ cap was exactly right here.** 10 methods reduced cleanly to 5 distinct behaviors
  because 5 of the 10 are private helpers supporting public callers.
- **Cache invalidation is one REQ, not two**: the email-level and object-level variants are
  different entry points to the same observable behavior. Splitting them would have created
  REQ inflation.
- **Confidence numbers made it into REQ language** (1.0 / 0.8 / 0.7 / 0.4). These are
  implementation details that *are* observable via the return value, so pinning them in the
  spec is correct — if the implementation re-tunes them, the spec delta follows.
