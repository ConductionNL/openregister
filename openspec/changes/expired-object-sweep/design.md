# Design: expired-object-sweep

No board of OpenRegister's own. Portaliq's PtFormulierInstellingen board (canvas `5NkFW28vZUUij43xzxHg5a`), tab "Bewaartermijn" ("Voltooid 30 dagen, onvolledig 30 dagen, mislukt 90 dagen, daarna anonimiseren"), sets the dates this change acts on.

## D1. The schema key

```json
"x-openregister": { "expiry": { "action": "anonymise", "keep": ["reference", "binding", "state", "submittedAt"], "actionField": "retentionMethod" } }
```

`action` is the default. `actionField` (optional) names a property on the object whose value, `delete` or `anonymise`, overrides the default per object. `keep` names the properties an anonymisation leaves. A schema save refuses an unknown action or a `keep` entry that is not a property.

## D2. Why an action per object

Portaliq sets the method per form binding, and all bindings write the same `portalIntakeSubmission` schema. `actionField` lets the binding's choice travel on the object without a schema per binding.

## D3. The sweep

`ExpiredObjectSweepJob` runs daily (app config `expiry_sweep_enabled`, default on; `expiry_sweep_batch`, default 500 per schema per run). Per opted-in schema it queries objects with `_expires < now`, ordered by `_expires`, and per object:

1. `RetentionService::hasActiveLegalHold()` true: skip, count as held.
2. `delete`: delete through ObjectService as the system, with the audit entry `expiry.deleted`.
3. `anonymise`: write every property outside `keep` as null (or `{}` for objects, `[]` for arrays), delete the object's files, clear `expires`, with the audit entry `expiry.anonymised`. Validation is skipped for this write only, because required answers become empty.

The run writes one log line per schema: deleted, anonymised, held, left for the next run.

## D4. Writing `expires`

For a schema with `expiry`, `@self.expires` on a create or update is stored in the `expires` column. A caller without update rights on the object cannot set it. For other schemas `@self.expires` stays ignored on save, as today.
