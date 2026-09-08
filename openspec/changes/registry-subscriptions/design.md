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
