# Tasks: object-quota-per-organisation

## 1. Declaration

- [x] 1.1 `x-openregister-quota` in `Schema::ANNOTATION_VOCABULARY`, so the annotation survives `setConfiguration()` (D-1).

## 2. Service

- [x] 2.1 `ObjectQuotaService::limitFor()`, `count()` and `status()` (D-1, D-2).

## 3. Enforcement

- [x] 3.1 `ObjectQuotaListener` on `ObjectCreatingEvent`, registered in `Application`; refuses with `object-quota-exceeded`, count and limit; updates and objects without an organisation are not counted; a failing count allows the create and warns (D-3, D-4).

## 4. Follow-ups

- [ ] 4.1 Enforce the cap on bulk creates (`saveObjects`), which do not dispatch `ObjectCreatingEvent` per object.
- [ ] 4.2 hermiq moves its schedule quota onto the annotation and `status()` (hermiq lane, after this lands).

## 5. Tests

- [x] 5.1 `ObjectQuotaListenerTest` through the real listener and service: refused at the cap, allowed below, the count query is unrestricted and scoped to register, schema and organisation, no count without a quota, invalid limits are no quota, no organisation and updates are not counted, a failing count allows and warns, `status()` with and without a cap, the annotation survives the schema allow-list, the listener is registered.
- [x] 5.2 `openspec validate object-quota-per-organisation --strict`.
