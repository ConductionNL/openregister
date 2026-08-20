# or-flow-object-write-skip-when

## Why

The decomposed synchronization (openconnector
`openspec/changes/flow-native-synchronization/`) can now decide `skip` for an
unchanged object and commits **zero contracts** on a re-run. It still rewrites
every target **object**, because a skipped item must keep flowing into
`object-write`: `openregister.filter` would DROP it, and
`openconnector.contract-sweep` decides what to delete from the target ids its
items carry — so dropping an unchanged item is exactly what makes the next
sweep delete the object that was fine.

`SaveObject::updateObject()` stamps `updated` unconditionally, so reaching the
write at all moves `@self.updated`.

## What changes

`openregister.object-write` gains an optional `skipWhen` — a dot-path on the
item. When it resolves to `true` or the string `skip`, that item is emitted
**unchanged and unwritten**: no write, no cap consumption, position and
identity preserved. Both the per-item and the `bulk` paths honour it.

Absent, behaviour is byte-identical to today.

## Impact

- `lib/Service/Flow/Nodes/ObjectWriteNode.php` — one config key, one helper.
- No API, schema or migration impact.
- Follow-up in openconnector: `SynchronizationFlowGenerator` emits
  `skipWhen: "contract.outcome"`, which completes task 2.3's zero-write
  re-run.
