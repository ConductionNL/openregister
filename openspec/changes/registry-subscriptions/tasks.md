# Tasks: registry-subscriptions

## 1. Declaration and state

- [ ] 1.1 Validate `x-openregister-registry` at schema save.
- [ ] 1.2 Subscription state table and `@self.registry` marker.

## 2. Endpoints and events

- [ ] 2.1 Request and end subscription endpoints, dispatching the events.
- [ ] 2.2 Inbound update endpoint applying owned properties only, audited
      with the registry as actor.

## 3. Query

- [ ] 3.1 `_registry[state]` and `_registry[updatedBefore]` lenses.

## 4. Tests

- [ ] 4.1 Unit tests for validation, the owned-property guard and lenses.
- [ ] 4.2 `tests/e2e/api-direct/registry-subscriptions.spec.ts`: request a
      subscription, post an inbound update, read the changed property and
      the audit actor.
