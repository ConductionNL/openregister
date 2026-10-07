## Why

An app that ships a flow in `x-openregister-flows` gets version 1 published on
its first import. A later app upgrade that changes the declaration rewrote the
flow's head and stopped there. Runs walk the published version, so the fix the
upgrade shipped never ran until someone opened a draft and published it by hand.
Measured on the Rotterdam stack: version 1 had 32 nodes, the head 147, and every
run of opencatalogi's publiccode harvest still walked the 32. Every app that
ships a flow inherits this, and a clean install followed by an upgrade from the
app store hits it with nobody there to publish.

## What Changes

- `FlowVersionService::publishShippedUpdate(Flow)` publishes the head as the next
  version when the shipped declaration still owns the flow: a version is
  published, nobody published it (`publishedBy` is null, which is how an import
  publishes), the head is not an open draft, and the head's graph differs from
  the published one.
- `SchemaFlowImportListener` calls it after updating an existing flow. A failure
  is logged and never aborts the import; the previous version keeps serving.

## Impact

- A version an administrator published is never replaced by an upgrade, and an
  open draft is never published under its author. Both keep today's behaviour.
- `enabled` and `owner` stay untouched, as before.
