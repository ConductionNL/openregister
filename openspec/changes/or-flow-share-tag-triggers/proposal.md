# Proposal: or-flow-share-tag-triggers

## Summary

Two more native trigger families on the payload mechanism from or-flow-native-
triggers: shares (`share.created` / `share.deleted`) and tags (`tag.assigned` /
`tag.unassigned`). Each is a few lines in the existing `NativeFlowTriggerListener`
— the run-seeding-from-payload work is already done.

## What Changes

- `NativeFlowTriggerListener` also handles `ShareCreated`/`ShareDeleted` (payload:
  share id, node id, share type, who it is shared with, path) and
  `TagAssigned`/`TagUnassigned` (payload: object type, object ids, the tags'
  ids and names). Every field is read defensively, as the file payload is.
- The event catalog gains the Share and Tag trigger groups.

## Out of scope

Calendar and scheduled triggers. Calendar is another event family (same
mechanism); a scheduled trigger fires on time rather than an event and is a
different shape — its own change.
