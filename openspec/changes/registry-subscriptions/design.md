# Design: registry subscriptions

## D-1: the annotation names what the registry owns

`x-openregister-registry: { registry: 'brp', identity: 'bsn', owned:
['givenNames', 'familyName', 'birthDate', 'address'] }`. Owned properties
are the ones an inbound update may write; a user edit of an owned property
on a subscribed object is warned in the UI and audited as a local override.
Property names are English (decision D13).

## D-2: state lives beside the object

Subscription state, last update time and source live in a small table keyed
by object uuid, surfaced as `@self.registry`. Requesting a subscription
writes `requested` and dispatches `RegistrySubscriptionRequestedEvent`;
the connector confirms with `active` through the inbound endpoint, or reports
a refusal, which is stored with its reason.

## D-3: the inbound endpoint is narrow

`POST /api/registry/{registry}/updates` takes the identity value, the
changed owned properties and the registry's own event reference. It resolves
the object by identity, applies the properties through the ordinary save
path with the registry as actor, and refuses (422) a property outside
`owned`. Authentication is the connector's app password or the
credential-broker grant; the endpoint is not public.

## D-4: freshness is queryable

`_registry[state]=active` and `_registry[updatedBefore]=<date>` are lenses on
the object query, so a data steward lists stale subscribed persons.

## D-5: kind

Code, in OpenRegister. Consuming apps add the annotation to their schema
JSON, which is config; integriq ships the connector.

## Implementation addendum, 2026-09-11

Three decisions this proposal left to implementation time, recorded here
rather than left implicit:

- **D-2's "resolve the object by identity" mechanism.** The state table
  (`openregister_registry_subs`) stores `identity_value` per row, captured
  at request time from the object's own identity property. The inbound
  endpoint looks rows up by `(registry, identity_value)`, filtered to
  `active`. This is deliberately NOT unique on `(registry, identity_value)`:
  two different objects — in different registers, e.g. two apps each
  keeping their own `person` schema — may legitimately track the same
  real-world BSN or KvK number, and a registry push updates every matching
  active row, not just one. `applyInboundUpdate()` therefore returns
  `{matched, applied, rejected}` rather than a single result, so a caller
  (and the endpoint) can tell "no such subscription" (`matched === 0`, a
  404) apart from "matched, but this payload changed nothing" (`matched`
  > 0, both `applied` and `rejected` empty, REQ 3's "an unchanged payload
  announces nothing" — a 200 no-op, not a 404).
- **D-3's "audited with the registry as actor."** `ObjectService::saveObject()`
  (the ordinary save path) audits with whichever Nextcloud account the
  connector's app password belongs to — it has no actor-override parameter,
  and widening that signature was out of scope for this change. Instead,
  `applyInboundUpdate()` writes a SECOND, explicit audit row via
  `AuditTrailMapper::createAuditTrailEntry()`, extended with optional
  `$actorId`/`$actorName` parameters (both default `null`, so every
  existing caller is unaffected) naming `registry:{id}` as the actor. Two
  rows for one write is an acceptable and legible cost: one row is "who
  authenticated the call", the other is "on whose authority the data
  changed" — the second is what REQ 3's scenario actually asks a reviewer
  to find.
- **D-4's query lenses are DEFERRED**, not shipped. Wiring `_registry[state]`
  / `_registry[updatedBefore]` means adding a new `EXISTS` join against
  `openregister_registry_subs` inside `MagicSearchHandler::applyAccessControlFilters()`
  — the same hot, per-request query-assembly path `MagicRbacHandler::applyRbacFilters()`
  already extends for RBAC. That is not a change to make without a live
  database to verify the generated SQL against, and it is not what
  dossiq's `contacts-domain` task 4.3 is blocked on (that needs the
  annotation + state + inbound endpoint, not a list-query filter). See
  `tasks.md` 3.1.
