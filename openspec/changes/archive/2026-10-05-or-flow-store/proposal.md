# Proposal: or-flow-store

## Summary

Ship the OpenRegister-native flow store — a `flows` register and a `flow` schema
— so a flow can be authored in OpenRegister out of the box, not only in a
consuming app. A repair step imports the descriptor idempotently on install and
upgrade, the same way OpenRegister ships its other core registers.

## Why

`OpenRegisterFlowResolver` (or-flow-native-store) resolves flows stored as OR
objects in a configurable register/schema, defaulting to `flows` / `flow`. But
nothing created that store, so the default resolved nothing until an admin built
a register and schema by hand. Shipping the store closes that gap: the resolver's
default now points at a store that exists, so triggers, sub-flows and the `/test`
endpoint work with an OpenRegister-authored flow immediately.

## What Changes

- **`lib/Settings/flow_register.json`** — the descriptor: a `flows` register and a
  `flow` schema (name, description, enabled, trigger, triggerRegister,
  triggerSchema, nodes, edges).
- **`ImportFlowRegister`** repair step imports it via
  `ConfigurationService::importFromApp()` — idempotent, slug-matched,
  version-gated, never throwing — registered in `info.xml` under both `install`
  and `post-migration`, mirroring `ImportCredentialBrokerRegister`.

## Out of scope

- A bespoke flow-authoring canvas. A flow is an object, so the existing object
  editor already edits it; the descriptor gives that editor the right fields.
- Seeding example flows. The store ships empty; authors add flows.
