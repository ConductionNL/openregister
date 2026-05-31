# Retrofit — controller bundle: registry / views (5 sub-clusters)

## Why
The OpenRegister REST controller surface for the registry meta-entities, persisted views, webhook
delivery API, linked-entity API, and the URN resolver already ships but is only partially covered
by capability specs. This change reverse-specs the OBSERVED HTTP contracts so the spec corpus
matches the shipped code, and annotates the controller methods with `@spec` task references.

## What Changes
Reverse-spec of the OpenRegister REST controller surface for the registry meta-entities,
persisted views, webhook delivery API, linked-entity API, and the URN resolver. Code already
exists — this change retroactively specifies the observed HTTP contracts and annotates the
controller methods. All five sub-clusters EXTEND an existing capability spec; no new capability
is minted.

## Sub-clusters

| Sub-cluster | Mode | Target capability | New REQs |
|---|---|---|---|
| registry-resource-crud | extend | openapi-generation | 1 (shared CRUD contract) |
| views-faceting-rest | extend | faceting-configuration | 2 |
| webhooks-delivery-api | extend | webhook-payload-mapping | 1 (delivery-log listing) |
| linked-entity-api | extend | linked-entity-types | 0 (annotate-only) |
| urn-resolver-api | extend | urn-resource-addressing | 1 (observed lean contract) |

Total: 5 new REQs.

## Affected code units

### registry-resource-crud (8 controllers, uniform five-verb CRUD)
- lib/Controller/RegistersController.php (index/show/create/update/patch/destroy)
- lib/Controller/SchemasController.php (index/show/create/update/patch/destroy)
- lib/Controller/SourcesController.php (index/show/create/update/patch/destroy)
- lib/Controller/MappingsController.php (index/show/create/update/patch/destroy)
- lib/Controller/ApplicationsController.php (index/show/create/update/patch/destroy)
- lib/Controller/AgentsController.php (index/show/create/update/patch/destroy)
- lib/Controller/EndpointsController.php (index/show/create/update/patch/destroy)
- lib/Controller/ConsumersController.php (index/show/create/update/patch/destroy)

The eight controllers share one resource-CRUD shape: `index` returns `{results, total?}`,
`show` returns the entity or 404, `create` returns the persisted entity (201 where set),
`update`/`patch` return the entity or 404 (`patch` delegates to `update`), `destroy` returns an
empty body. ONE shared REQ describes the contract — eight near-identical REQs would add no
information. The non-CRUD methods on these controllers (`export`, `import`, `publish`, `stats`,
`schemas`, `objects`, `test`, etc.) belong to OTHER capabilities (data-import-export,
deprecate-published-metadata, production-observability, mapping-execution) and are NOT annotated
here — they are out of scope for the resource-CRUD contract.

### views-faceting-rest
- lib/Controller/ViewsController.php (index/show/create/update/patch/destroy)

### webhooks-delivery-api
- lib/Controller/WebhooksController.php (index/show/create/update/destroy/test/events/logs/logStats/allLogs/retry)

### linked-entity-api
- lib/Controller/LinkedEntityController.php (addObjectLink/removeObjectLink/addRegisterLink/addSchemaLink/reverseLookup)

### urn-resolver-api
- lib/Controller/UrnController.php (resolve/lookup/bulk)

## Cross-capability flags (NOT annotated in this bundle)
- `EndpointsController::test/logs/logStats/allLogs` → production-observability (endpoint call logs)
- `WebhooksController::logStats` → already covered by webhook-payload-mapping#REQ "Webhook health monitoring"
- `MappingsController::test` → mapping execution (separate concern)
- `Registers/SchemasController` export/import/publish/stats/explore → data-import-export, deprecate-published-metadata

## Approach & scanner false-positives dropped
- The scanner bundled the registry CRUD controllers under `openapi-generation`. That spec
  documents OAS *generation*, not the CRUD HTTP surface itself. Extending it with one shared
  resource-CRUD-contract REQ keeps the API documentation capability contiguous without
  minting a near-duplicate capability.
- `faceting-configuration` documents facet *computation within* views, not the persisted-views
  REST surface. The ViewsController CRUD contract (owner-scoped, distinct `{view}`/`{results,total}`
  envelopes, 401 when unauthenticated) was genuinely uncovered → 2 new REQs.
- `webhook-payload-mapping` already documents the WebhooksController CRUD/test/events/logStats/retry
  surface in detail (and the controller already carries `@spec` annotations from the 2026-04-30
  retrofit). Only the delivery-log listing endpoints (`logs`, `allLogs`) were uncovered → 1 new REQ.
- `linked-entity-types` already documents the generic ad-hoc linking API and reverse lookup;
  the LinkedEntityController is already annotated from the 2026-04-23 / 2026-04-30 retrofits.
  Annotate-only against the existing REQs, no new REQs.
- `urn-resource-addressing` spec status is "Not implemented" and describes a far richer design
  (UrnMapping table, federation, OIN/RSIN, versioning, export) than the actual shipped controller.
  The real `UrnController` exposes a lean three-operation contract
  (`urn:nl-or:<instance>:<register>:<schema>:<uuid>`, resolve / lookup / bulk, 1000-URN cap).
  Reverse-specced as ONE REQ documenting OBSERVED behavior; the unimplemented spec aspirations are
  left untouched (this change does not claim them implemented).

Source: /tmp/or-scan/bundle-ctrl-registry-views.json. See retrofit playbook.
