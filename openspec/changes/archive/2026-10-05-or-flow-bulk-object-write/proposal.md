# or-flow-bulk-object-write

## Why

The flow-native synchronization programme (openconnector
`openspec/changes/flow-native-synchronization/`) decomposes a synchronization
into page-level flow steps. Its write stage needs one bulk save per page —
`sync → object-write` pipelines carry hundreds of items per page, and the
per-item `saveObject()` round-trip is the dominant cost. `ObjectService`
already has the seam (`saveObjects()`); the flow node cannot reach it.

## What changes

- `openregister.object-write` gains a `bulk: true` configuration key that
  routes the whole page of items through ONE `ObjectService::saveObjects()`
  call, inside the same `runAs($owner)` wrapper the per-item path uses.
- Bulk is refused at save time for every semantic the bulk path does not
  have: `update`/`delete` operations, `onConflict: fail`, upserts without
  `replace: true`, and upserts matching on anything but the row uuid.
- Row uuids are decided client-side (generated for creates, resolved from the
  uuid match for upserts) so output items name their rows without correlating
  a categorised bulk result, and so a downstream contract-commit step can
  read the target id.
- A bulk result carrying rejected rows fails the step loudly, naming the
  count and first reason; the write cap is enforced against the page size
  before anything is written.
- The node implements `IFlowNodeConfigForm` so the editor renders register /
  schema pickers and labelled fields instead of a bare JSON pane.

## Impact

- `lib/Service/Flow/Nodes/ObjectWriteNode.php` — bulk branch + configForm.
- No API, schema, or migration impact. The per-item path is byte-for-byte
  unchanged for every existing flow (no flow can carry `bulk` today; preflight
  would have refused the unknown key).
