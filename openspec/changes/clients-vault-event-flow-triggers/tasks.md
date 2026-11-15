# Tasks: other apps add flow triggers to the event catalog

- [x] 1.1 Add `lib/Event/RegisterFlowTriggersEvent.php` with `addTrigger(id, label, group)` and `getTriggers()`. Verify: unit test that triggers come back in order.
- [x] 1.2 `EventCatalogService` dispatches it once on the first read; `getCatalog()` and `knownTriggerIds()` include the contributed entries; empty fields, built-in ids and aliases, and repeats are refused; a throwing listener is logged. Verify: unit tests for each, and that a listener reading the catalog does not dispatch again.
- [ ] 1.3 With Keepiq enabled, `GET /api/flow/event-catalog` lists the `Vault` group. Verify: live check on the dev stack.
