# Tasks: or-flow-share-tag-triggers

- [x] `NativeFlowTriggerListener` handles Share (created/deleted) and Tag
      (assigned/unassigned) events with defensively-read payloads.
- [x] Event catalog gains Share and Tag groups.
- [x] Register the four events in Application.php.
- [x] Test: share.created payload (unit); tag path live-verified (the OCP tag
      event is absent from the composer test stubs). 7 listener tests. phpcs clean.
- [x] Live-verified on 8080: catalog lists share.*/tag.*; tag.assigned maps
      objectType/objectIds/tags (id+name).
- [ ] Calendar / scheduled triggers — follow-ups.
