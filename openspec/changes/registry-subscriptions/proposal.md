# Registry subscriptions on stored persons and companies

## Why

Round 2 of the dossiq competitor analysis (row B22 in
`concurrentie-analyse/procest/_round2/compare/tier-b-and-sibling.md`, decision
D10): Zaaksysteem keeps a Gegevensmagazijn of persons and organisations with
an afnemerindicatie per record, so the BRP and KvK push changes and the local
copy stays current (`xxllnc-zaken/round2/pages/Gegevensmagazijn.md`);
OpenCase does the same for Danish CPR events (`opencase/round2/code-census.md`).

dossiq's HaalCentraalBrpAdapter and KvkApiAdapter look a person or company up
and store the answer; nothing subscribes, so a stored person is stale the day
after (M1 5.11). The row is split: the connector that registers and receives
the subscription is integriq's; the store that marks an object as subscribed,
receives an update and shows its freshness is OpenRegister's. This change is
the OpenRegister half. No existing capability owns a subscription on an
object, so this is a new capability, `registry-subscriptions`.

## What changes

- A schema may declare `x-openregister-registry`: the registry (`brp`,
  `kvk`, or another id), the identity property (`bsn`, `kvkNumber`) and the
  properties the registry owns.
- An object of such a schema carries `@self.registry`: subscription state
  (`none`, `requested`, `active`, `ended`), the last update received and its
  source. A user with `update` may request or end a subscription; the request
  is an event a connector (integriq) acts on.
- An inbound update endpoint applies a registry change to the object's
  registry-owned properties only, audited with the registry as actor, and
  refuses a change to a property the registry does not own.
- The object list exposes freshness so an index page can show "updated by
  BRP on ..." and filter on subscription state.

## Who benefits

dossiq (parties on the case), zaakafhandelapp, humaniq (employee identity
from BRP), pipelinq (company data from KvK), integriq as the connector.

## Impact

- Affected specs: registry-subscriptions (new capability).
- Affected code: schema annotation validation, `lib/Service/Registry/`,
  two endpoints (subscription request and end, inbound update), the
  `@self.registry` marker, events for the connector.
- Backwards compatible: a schema without the annotation is unchanged.
