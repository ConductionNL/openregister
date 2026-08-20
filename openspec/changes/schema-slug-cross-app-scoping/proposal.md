---
kind: code
---

## Why

Schema (and register) slugs are unique per **organisation**, not per **application**.
The DB enforces this with a `(organisation, slug)` unique index, and
`SchemaMapper::find()` resolves a slug by `LOWER(slug)` GLOBALLY across every app
on the instance, returning the first row it fetches.

On the shared OpenRegister instance — ~20 Conduction apps living in one database —
that turns a generic slug shipped by app B (`conversation`, `order`, `task`,
`notification`, `contact`, `message`, …) into a hard collision with app A's schema
of the same slug:

- The DB physically refuses app B its own schema, so `ImportHandler::importSchema()`
  silently **binds** app B to app A's schema — or, if B's version is newer,
  **overwrites** A's live schema.
- App B's objects then land in the wrong schema/table, and A's schema can be
  corrupted.
- It is **first-app-wins** and therefore environment-dependent, and **invisible in
  single-app tests** (a clean instance has no competitor for the slug).

Live example: `hermiq` ships bare `conversation`/`message`; `pipelinq` already owns
`conversation` (#701, required `channel` enum) and `message` (#700). hermiq's import
bound to #701/#700, so **every** hermiq agent run — which does
`saveObject(register: 'hermiq', schema: 'conversation')` — resolved to pipelinq's
#701 and failed validation on the missing `channel`. Agent execution was 100% broken.

Convention (namespacing every app's slugs) does not hold across ~20 teams. The
durable fix is to make the slug namespace **per application**, and to resolve slugs
within the **register** context that runtime callers already carry.

## What Changes

- **Widen slug-uniqueness** (migration): replace the `(organisation, slug)` unique
  index on `openregister_schemas` and `openregister_registers` with
  `(organisation, application, slug)`. Strictly more permissive than the old key —
  no data dedup, cannot fail on existing rows. Idempotent + self-guarding.
- **App-scoped import** (`ImportHandler::importSchema()`): when an app context is
  present, resolve the existing schema via
  `SchemaMapper::findByApplicationAndSlug($slug, $appId)`. An app only ever updates
  a schema it **owns**, so importing a colliding slug creates the app's **own**
  schema instead of binding to / overwriting a foreign one. A foreign owner is
  logged. App-less (manual/UI) imports keep the historical global behaviour.
- **Register-scoped runtime resolution** (`ObjectService::setSchema()` and
  `searchObjectsBySlug()`): when a register context is present, resolve a slug among
  the register's own schemas via `SchemaMapper::findBySlugInIds($slug, $ids)` so a
  slug resolves to the schema **that register references** — not whichever same-slug
  row is fetched first. Falls back to the global find for numeric ids, uuids, and
  slugs the register does not carry (register-less callers unchanged).
- **Self-heal polluted registers** (`ImportHandler::autoCreateRegisterIfApplication()`):
  when reconciling an app's auto-register, **prune** any listed schema id that is now
  shadowed by a freshly-imported same-slug schema, so a register that had a foreign
  app's schema bound in before this fix is cleaned up on the next import.

## Impact

- Backward compatible: the new resolution paths are no-ops without an app/register
  context; the wider unique key accepts everything the old one did.
- Behaviour changes **only** in genuine cross-app collision cases (which were bugs):
  the second app now gets its own schema and resolves to it.
- Affected code: `lib/Db/SchemaMapper.php`, `lib/Service/ObjectService.php`,
  `lib/Service/Configuration/ImportHandler.php`,
  `lib/Migration/Version1Date20260723000000.php`.
- Live-verified on the shared 8080 instance: hermiq now imports its own
  `conversation`/`message` schemas, its register is healed of the foreign ids, a
  slug resolves to the app's own schema, and a conversation object persists.
