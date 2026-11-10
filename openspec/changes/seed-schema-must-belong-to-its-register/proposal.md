## Why

A seed object names its schema by slug. When the slug is not one the import
created itself, `ImportHandler` resolves it with a global lookup and never checks
that the seed's register lists the schema it found. Two apps can ship a schema
under the same slug. stackiq and opencatalogi both used `organization`;
opencatalogi dropped its own but kept a `default-org` seed under register
`publication`. With stackiq installed, the lookup found stackiq's schema and the
seed was written into a `publication` x stackiq-`organization` table, failing
NOT NULL on stackiq's required `type`. Whichever app installed second broke.

## What Changes

- A new guard, `ImportHandler::seedSchemaIsForeign()`, skips a seed (with a
  warning and a `skipped` count) when all three hold: the register lists schemas
  and this one is not among them, the schema names an owning application, and
  that owner is neither the importing app nor the register's own app.
- It runs at the three places a seed's schema is resolved: the
  `components.objects` import loop, the `@ref:` pre-resolution (so a skipped seed
  is never handed an identity), and the `seedData` path.

## Impact

- Kept working: seeds of the app's own schemas (even before this pass linked
  them to the register), seeds of ownerless shared schemas such as
  `nc-organisation`, and cross-app seeds into a register that lists the schema.
- The stale opencatalogi seed itself still needs removing in opencatalogi; this
  makes either install order safe while it ships.
