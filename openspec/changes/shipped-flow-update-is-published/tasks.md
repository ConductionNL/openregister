## 1. Publish on upgrade

- [x] 1.1 `FlowVersionService::publishShippedUpdate()`: the four conditions, then `createDraft()` + `publish(publishedBy: null)`.
- [x] 1.2 `SchemaFlowImportListener::upsert()` calls it on the update path through the container, never raising.

## 2. Verification

- [x] 2.1 `tests/Unit/Service/Flow/FlowShippedUpdatePublishTest.php` (real FlowVersionService, FlowDefinitionPin hashing and FlowSemanticVersion): a changed shipped graph becomes version 2, published, version 1 deprecated; a person's version, an open draft, an unchanged graph and a flow with no published version are left alone.
- [x] 2.2 `SchemaFlowImportListenerTest`: a re-import calls `publishShippedUpdate()` with the updated flow; a failing publish is not fatal (both fail on the old code). A first import publishes version 1 as nobody and leaves the flow disabled, so a clean store install ships a runnable, unadopted flow.
- [x] 2.3 Flow (1,498) and Listener (420) unit suites green.
- [ ] 2.4 Live: re-import opencatalogi with a changed `publiccode-github-harvest` flow and see version 2 published.
