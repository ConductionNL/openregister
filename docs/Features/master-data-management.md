---
title: Master Data Management (MDM)
description: How Open Register decides which source wins when systems disagree — trust tiers, freshness decay, and the golden record.
keywords:
  - Open Register
  - Master Data Management
  - MDM
  - Golden Record
  - Survivorship
  - Trust Configuration
  - Data Quality
---

# Master Data Management

When more than one system knows about the same organisation, person, or product, they will eventually disagree. The Chamber of Commerce API and your invoicing system will hold different phone numbers for the same customer. Both are "correct" — they were right when they were written.

Master Data Management is how Open Register answers the question that follows: **which value do we actually show?**

The answer is not "the newest one", and it is not "whichever system wrote last". It is a decision you configure, per attribute, per source — and Open Register resolves it for you into a **golden record**.

:::note Trust here means *data* trust
"Trust" in this feature means *which source is authoritative for a given field*. It is unrelated to security trust or credential brokering (which is Doriath's job). A source can be perfectly trustworthy in the security sense and still be a poor authority on someone's billing address.
:::

## The moving parts

| Concept | What it is |
|---|---|
| **Source record** | What one upstream system claims. One row per system, per entity. Never edited — it is that system's truth. |
| **Master entity** | The entity itself (an organisation, a customer). Holds the golden record. |
| **Golden record** | The resolved, best-known value of each attribute, assembled from the source records. |
| **Trust configuration** | The rules that decide which source wins for which attribute. |
| **Provenance** | For every golden-record field, which source it came from and why it won. |

Source records are kept intact. The golden record is *derived* — so when the rules change, the answer changes, and nothing is lost.

## Trust configuration

A trust rule is a row in the `trust-configuration` register (schema `trustConfiguration`). It maps a **`(entityType, attribute, sourceSystem)`** tuple onto a **trust tier**:

| Field | Required | Meaning |
|---|---|---|
| `entityType` | yes | The master-record type the rule applies to, e.g. `account`. |
| `attribute` | yes | The field it applies to, e.g. `billingAddress`. |
| `sourceSystem` | yes | Slug of the upstream system, e.g. `kvk-api`. |
| `trustTier` | yes | `gold` \| `silver` \| `bronze` \| `discard`. |
| `freshnessDecayDays` | no | After this many days, this source's value steps **one tier down**. Omit for no decay. |
| `effectiveFrom` | no | The date the rule takes effect. Rules are versioned — see below. |

A worked example. Three rules, all about accounts:

| entityType | attribute | sourceSystem | trustTier | freshnessDecayDays |
|---|---|---|---|---|
| account | `billingAddress` | `kvk-api` | gold | 180 |
| account | `phone` | `shillinq-debiteuren` | silver | 90 |
| account | `vatNumber` | `kvk-api` | gold | 365 |

Read the first row as: *"For a company's billing address, the Chamber of Commerce is the authority — but if we haven't re-checked it in six months, trust it less."*

Note that trust is **per attribute, not per system**. The Chamber of Commerce is gold on the legal address and worthless on a mobile number. Nothing forces a source to be uniformly good or bad, which is the point.

### Freshness decay

`freshnessDecayDays` encodes the fact that authority ages. A gold-tier value that has gone stale steps down exactly one tier — gold becomes silver — and can then lose to a fresher silver source. It is a single discrete step, not a sliding scale, so the outcome stays explainable: you can always say *why* a value won.

### Versioning with `effectiveFrom`

Rules are not edited in place. To change a rule, add a new row with a later `effectiveFrom`. When resolving, Open Register picks the most recent rule whose `effectiveFrom` is on or before the as-of date.

This means a golden record can be recomputed **as it would have been resolved last March** — which is what you need when someone asks why a decision was made on a date when the rules were different.

## Wiring it to a schema

The engine is not hardcoded to any entity type. A schema opts in through the `x-openregister-survivorship` annotation, which tells Open Register where to find things:

```json
{
  "x-openregister-survivorship": {
    "sourceLink": {
      "mode": "reverseFk",
      "sourceSchema": "sourceRecord",
      "referenceField": "currentMasterEntity"
    },
    "goldenRecordField": "goldenRecord",
    "provenanceField": "attributeProvenance",
    "trustTierField": "trustTier",
    "tierOrder": ["discard", "bronze", "silver", "gold"],
    "defaultTier": "bronze",
    "discardTier": "discard",
    "freshnessAnchorField": "lastChange",
    "overridesField": "attributeOverrides"
  }
}
```

Two things worth calling out:

- **`tierOrder` is yours.** The four tiers above are a convention, not a constraint — the ranking is whatever you list, lowest first. `defaultTier` and `discardTier` must be members of it.
- **`defaultTier` is the fallback.** A source with no matching trust rule is not rejected; it lands on `defaultTier`. So the system degrades to "everything is bronze, newest wins" rather than to an empty record.

## How a value is chosen

For one attribute, across all source records for that entity:

1. Drop any source whose resolved tier is the `discardTier`.
2. Resolve each remaining source's tier from the trust configuration, honouring `effectiveFrom`.
3. Apply freshness decay against each candidate's `freshnessAnchorField`.
4. Take the highest remaining tier. Ties break on freshness.
5. Record the winner in `goldenRecordField`, and *why* it won in `provenanceField`.

A **manual override** (`overridesField`) beats all of it. A human who has picked up the phone and confirmed the address outranks any rule — but the override is stored as an override, so it is visible, attributable, and reversible rather than being silently written over the source data.

## When sources genuinely conflict

Trust rules resolve most disagreements automatically. What they cannot resolve is a real-world conflict — two sources, both plausible, both current, both gold. Open Register surfaces these rather than guessing: the duplicate-detection and quality stack scores records, flags likely duplicates, and raises conflicts to a human through the MDM conflict-resolution UI. Merging two entities is an explicit, audited act.

## Merging duplicates

A merge folds one record (the **loser**) into another (the **survivor**). The steward starts it from **Duplicate Candidates**, previews it, gives a reason, and confirms. Open Register snapshots both records, relinks everything that pointed at the loser, recomputes the survivor's golden record, and writes a `mergeOperation` row that **Merge Operations** lists and can reverse.

| Step | Endpoint |
|---|---|
| Preview, writes nothing | `POST /api/objects/merge/preview` |
| Execute | `POST /api/objects/merge/execute` |
| Reverse | `POST /api/objects/merge/{id}/reverse` |

### Every reference moves with the merge

Other records point at the loser: a suite lists its applications, a connection names the module it calls, a contract names its supplier. A merge moves **every** such reference onto the survivor, on any schema, without that schema declaring anything. Open Register finds them through its relation index, across the whole instance:

| Reference | What the merge does |
|---|---|
| A single reference (`"module": "<loser>"`) | Points it at the survivor. |
| A list of references (`"applications": ["<loser>", "<survivor>"]`) | Replaces the loser with the survivor and drops the duplicate that leaves, so the list holds the survivor once. |
| A reference nested deeper in the record | Found and replaced where it sits. |

Each change is saved **as the steward who merges**, through the normal save path, so their rights, validation and audit trail all apply. A record the steward may not update is left as it is and listed under `skippedReferences` in the merge result; nothing is forced. The result's `references` lists what moved, by schema and count.

References that a schema's own merge configuration (`x-openregister-merge`) already relinks are moved by that first; the general relink only picks up what is left, so nothing moves twice.

:::note A limit of 5,000 references
One merge moves at most **5,000** references. A record referenced from more places than that is not merged at all: the execute call is refused before anything is written, naming the record and the limit. Merge such a record with a bulk job instead.
:::

### The preview shows what will move

The preview answers with a `references` list beside the post-merge golden record:

```json
{
  "postMergeGoldenRecord": { "name": "Zaaksysteem" },
  "references": [
    { "schema": "42", "count": 1 },
    { "schema": "57", "count": 12 }
  ]
}
```

The merge wizard shows it under **Referenced by**, one row per schema with the number of references that will move, so the steward sees how far a merge reaches before confirming it. When nothing references the loser, the list is left out.

### Reversing a merge

Inside the reversal window (`reversalWindowDays`, 30 days by default) **Merge Operations** offers **Reverse**. A reversal restores both records and puts every moved reference back where it was: the suite lists both applications again, and each connection points where it pointed before.

Every move is kept in the merge snapshot as the field's value before and after the merge. A reversal restores a field only if it **still holds what the merge wrote**. If someone edited that field after the merge, their edit wins: the field is kept as it is, and the reversal reports it in `keptReferenceEdits`:

```json
{
  "keptReferenceEdits": [
    { "object": "9f1c…", "path": "applications" }
  ]
}
```

So a reversal never silently undoes work done since the merge; it tells you which references to look at.

### Opening Duplicate Candidates from a link

**Duplicate Candidates** takes the register and schema from the address, so an app can send a steward straight to the right list:

```
/apps/openregister/duplicates?register=catalogus&schema=module
```

Each of `register` and `schema` may be an **id or a slug**. The page opens with that pair selected and its candidate pairs loaded; the steward picks nothing. A value that matches no register, or no schema of that register, is not guessed at: the page shows a notice naming the value it did not find and leaves the picker for the steward. With an unknown schema, the register stays selected.

The same parameters work on the other data quality pages that share the register and schema picker (**Data Quality**, **Master entities**).

## Related

- **Data quality** — scoring, duplicate detection, and similarity, which feed the same pipeline.
- **Multi-tenancy & access control** — trust rows are read RBAC- and tenant-scoped, so one organisation's rules never leak into another's golden record.
- **Doriath (credential broker)** — unrelated to trust *tiers*, but it is how Open Register holds the credentials for the source systems themselves.
