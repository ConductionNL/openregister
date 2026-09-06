# Tasks

## 1. The seam
- [x] 1.1 `FlowService::adopt()`: caller becomes owner; idempotent for the owner; refuses a takeover; audited
- [x] 1.2 `POST /api/flows/{id}/adopt` behind `flow.update`, organisation-scoped through `find()`
- [x] 1.3 `FlowAdoptionRefused` with machine-readable reasons

## 2. Tests
- [x] 2.1 Adopt sets the CALLER as owner, ignoring any uid in the body
- [x] 2.2 Anonymous and unauthorized callers are refused (401/403); a foreign flow is a 404
- [x] 2.3 A flow owned by someone else answers 409 `already-owned`
- [x] 2.4 An adopted and enabled flow becomes dispatchable
