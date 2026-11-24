# Expired object sweep

Let an object end on a date. A schema says what its objects' expiry means,
delete them or strip them down to a few named fields, and a daily job does it
when the date has passed.

A resident's unfinished form, a failed submission, a draft nobody came back
to: none of these is a record that needs a destruction list, but none of them
should stay forever either. The app that owns them (portaliq, for submissions)
sets each object's expiry; OpenRegister does the removal.

## Opting a schema in

A schema opts in with an `expiry` block under `x-openregister` in its
configuration:

```json
"x-openregister": {
  "expiry": {
    "action": "anonymise",
    "keep": ["reference", "binding", "state", "submittedAt"],
    "actionField": "retentionMethod"
  }
}
```

- **`action`** is `delete` or `anonymise`, the default for every object.
- **`keep`** names the properties an anonymisation leaves as they are.
- **`actionField`** (optional) names a property whose value, `delete` or
  `anonymise`, overrides the action for that object. Any other value falls
  back to `action`. Portaliq uses it so each form binding can choose.

A schema save is refused when the action is not `delete` or `anonymise`, or
when `keep` or `actionField` names a property the schema does not have. The
message names what is wrong and, for the action, the allowed values.

A schema without the block has not opted in: `expires` on its objects means
what it meant before, and the sweep never touches them.

## Setting the expiry

For an opted-in schema, `@self.expires` on a create or update is stored as the
object's expiry. The save runs behind the usual update check, so only a caller
who may update the object can set it. An empty value clears it. A value that
is not an ISO 8601 date (`2026-12-31` or `2026-12-31T00:00:00+00:00`) is
refused rather than dropped. For other schemas `@self.expires` is ignored on
save, as before.

## The daily sweep

`ExpiredObjectSweepJob` runs once a day. For every opted-in schema, in every
register that uses it, it takes the objects whose expiry is in the past,
oldest first, up to the batch size, and for each one:

1. **Legal hold:** an object under an active legal hold is left alone and
   counted as held.
2. **Delete:** the object is deleted as the system, and the audit trail gets
   an `expiry.deleted` entry.
3. **Anonymise:** every property outside `keep` is emptied (a nested object or
   list becomes empty, anything else `null`; the object's id stays), its files
   are removed, its expiry is cleared, and the audit trail gets an
   `expiry.anonymised` entry naming the kept properties. Validation does not
   run for this write, because required answers become empty.

Each run logs one line per schema: how many objects were deleted, anonymised,
held, failed, and left for the next run.

| App config key | Default | Meaning |
| --- | --- | --- |
| `expiry_sweep_enabled` | on | Switch the daily sweep off without removing it. |
| `expiry_sweep_batch` | 500 | The most objects one run handles per schema. |

## Dry run

Before switching the sweep on, see what it would do:

```bash
occ openregister:expiry:sweep --dry-run
```

It prints, per opted-in schema, how many objects the next run would delete,
anonymise and keep for a legal hold, and changes nothing. Without
`--dry-run` the command runs the sweep once.

## How this differs from archival destruction

Archival destruction (`retention-management`) is the Archiefwet path for
records: it runs on the archiefactiedatum, through selection lists, destruction
lists and approval. The expiry sweep is for objects that are not records, such
as an unfinished draft or a failed submission, and needs no list or approval.
A record schema stays on the archival path; the two do not replace each other.
