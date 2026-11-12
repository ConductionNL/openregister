---
kind: code
---

# Proposal: party-lookup-reads-the-party-tables

## Why

`PartyService::find` looked a party up by id alone, so the object layer searched every magic table on the instance
(`MagicMapper::findAcrossAllMagicTables`). On a full instance `GET /api/parties/{uuid}` took 5 to 9 seconds, and
dossiq's Rollen card, which reads each party through it, filled in only after about 8 seconds.

## What changes

- `PartyService::find` first reads the party tables: every register that carries a schema declaring
  `x-openregister-party`, one table per register and schema (`RegisterMapper::getAllRegisterIdsWithSchema`).
- Only an id none of those tables holds falls back to the instance-wide lookup, as before.
- The list of party tables is resolved once per request.
