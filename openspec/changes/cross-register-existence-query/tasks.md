# Tasks: cross-register-existence-query

## 1. The service

- [x] 1.1 `CrossRegisterExistenceService`: probe bound, per-probe read
  authorisation, existence and count, `reveal` narrowed by the schema with the
  refused fields named.
  **files**: `lib/Service/CrossRegisterExistenceService.php`

## 2. The endpoint

- [x] 2.1 `POST /api/objects/exists`, authenticated, answering per probe.
  **files**: `lib/Controller/ObjectsController.php`, `appinfo/routes.php`

## 3. Tests

- [x] 3.1 The projection carries nothing of the row, asserted as EXACT keys and
  again by searching the encoded answer for seeded content.
  **files**: `tests/Unit/Service/CrossRegisterExistenceServiceTest.php`
- [x] 3.2 A refused register reports refused rather than "nothing exists".
  **files**: `tests/Unit/Service/CrossRegisterExistenceServiceTest.php`
- [x] 3.3 A sensitive and an undeclared `reveal` field are both refused by name.
  **files**: `tests/Unit/Service/CrossRegisterExistenceServiceTest.php`
- [x] 3.4 The endpoint refuses an anonymous caller before any register is asked.
  **files**: `tests/Unit/Controller/ObjectsControllerExistsTest.php`

## What was built, and what is honest about it

`lib/Service/CrossRegisterExistenceService.php`,
`ObjectsController::exists()`, `POST /api/objects/exists`,
`tests/Unit/Service/CrossRegisterExistenceServiceTest.php` (9) and
`tests/Unit/Controller/ObjectsControllerExistsTest.php` (3).

🔑 THE SERVER STILL READS, AND THE DOCBLOCK SAYS SO. It has to: it owns the
data and has to count. What it does not do is hand the row over. The property
this service provides is about what crosses the boundary TO THE CALLER, which
is exactly the property a caller cannot provide for itself, and overclaiming it
as "the row is never loaded" would be a sentence the code does not support.

🔑 "SENSITIVE" REUSES THE PLATFORM'S EXISTING VOCABULARY. `writeOnly` and a
property carrying an `authorization` block, both from
`row-field-level-security`. Inventing a second marker here would give one
schema two answers about one property.

Mutation-checked: returning the whole row as `revealed` reddened four
assertions, including the exact-keys one and the three that search the encoded
answer for seeded content. Refusing an anonymous caller is asserted as the
service never being RESOLVED, not merely as a 401: checking the status alone
would pass on an implementation that queried every register first and discarded
the answer.

## Open, and deliberately not built here

- **A `count`-only path.** The probe reads up to `MAX_COUNT` rows to count
  them, which is correct and not cheap. A pushed-down count that never
  materialises rows belongs with the mapper and is its own change.
- **The consuming app's ground.** dossiq requires an authorisation ground
  before it asks and writes it to `sociaalDomeinAuditLog`. That stays there:
  the grounds are a social-domain vocabulary and mean nothing to a register of
  invoices (D-6).
