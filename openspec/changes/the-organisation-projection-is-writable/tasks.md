# Tasks

## 1. The provider

- [x] 1.1 `OrganisationObjectSourceProvider` implements
      `WritableObjectSourceProvider`.
- [x] 1.2 `insert()` delegates to `OrganisationService::createOrganisation()`,
      then applies the remaining projected identity fields.
- [x] 1.3 `insert()` refuses without an acting user, and refuses an empty name
      rather than defaulting one.
- [x] 1.4 `update()` writes only the projected properties; anything else is
      ignored, matching what the store does with it anyway.
- [x] 1.5 `update()` requires `isOrganisationAdmin()`, and answers a denied write
      the way it answers an absent one so the projection is not an enumeration
      oracle. The difference is logged.
- [x] 1.6 `update()` does NOT follow a merge chain, and refuses a merged-away
      organisation.
- [x] 1.7 `remove()` refuses, naming merging as the operation that retires an
      organisation.

## 2. The annotation

- [x] 2.1 `NcEntitySemanticMap` carries an optional `writable` flag; only
      `nc-organisation` sets it.
- [x] 2.2 The seed writes `readOnly: false` for a writable row on create.
- [x] 2.3 The seed RECONCILES an existing schema whose annotation disagrees.
      Without this the flag reaches fresh installs only, and the whole change is
      inert on every instance that already seeded the schema.
- [x] 2.4 A failed reconcile logs at ERROR with the consequence, rather than
      degrading into `run()`'s generic warning.

## 3. Wiring

- [x] 3.1 `Application.php` injects `OrganisationService` into the provider.

## 4. Verification

- [x] 4.1 Unit tests for each of 1.2 through 1.7, including the negative cases.
- [x] 4.2 A test that the seed flips an ALREADY-SEEDED schema, which is the
      failure mode 2.3 exists for and the one a create-path test cannot see.
- [x] 4.3 Run the repair step against a live instance and read the schema's
      annotation back, because a passing seed test proves the code and not the
      migration. Then create and update an organisation over HTTP, and confirm a
      delete leaves it standing.

## 5. Not in this change

- [ ] 5.1 Each app's own migration off its `organization` schema. This unblocks
      them; it performs none of them.
- [ ] 5.2 `ObjectsController::destroy()` resolves the uuid through `MagicMapper`
      before the object-source dispatch, so a delete on ANY virtual schema
      answers 404 rather than the read-only rejection, and `remove()` is never
      reached. Pre-existing, shared with every read-only projection, and the
      reason the delete refusal is tested at the provider rather than over HTTP.
