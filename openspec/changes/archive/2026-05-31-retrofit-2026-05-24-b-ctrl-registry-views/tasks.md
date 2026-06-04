# Tasks

## registry-resource-crud (extend openapi-generation)
- [x] task-1: openapi-generation#REQ — Shared resource-CRUD HTTP contract for the registry meta-entity controllers (RegistersController, SchemasController, SourcesController, MappingsController, ApplicationsController, AgentsController, EndpointsController, ConsumersController) — index/show/create/update/patch/destroy (retroactive annotation)

## views-faceting-rest (extend faceting-configuration)
- [x] task-2: faceting-configuration#REQ — Persisted-views CRUD REST contract (ViewsController index/show/create/update/patch/destroy, owner-scoped, 401/404/201/204 semantics) (retroactive annotation)
- [x] task-3: faceting-configuration#REQ — View query/configuration body normalization on create/update/patch (ViewsController) (retroactive annotation)

## webhooks-delivery-api (extend webhook-payload-mapping)
- [x] task-4: webhook-payload-mapping#REQ — Webhook CRUD + test + events + retry HTTP surface (WebhooksController index/show/create/update/destroy/test/events/retry) — already covered by existing webhook-payload-mapping requirements (retroactive annotation, no new REQ)
- [x] task-5: webhook-payload-mapping#REQ — Webhook delivery-log listing API (WebhooksController logs, allLogs, logStats) (retroactive annotation)

## linked-entity-api (extend linked-entity-types)
- [x] task-6: linked-entity-types#REQ — Generic ad-hoc linking + reverse lookup API (LinkedEntityController addObjectLink/removeObjectLink/addRegisterLink/addSchemaLink/reverseLookup) — already covered by existing linked-entity-types requirements (retroactive annotation, no new REQ)

## urn-resolver-api (extend urn-resource-addressing)
- [x] task-7: urn-resource-addressing#REQ — Observed lean URN resolver HTTP contract (UrnController resolve/lookup/bulk) (retroactive annotation)
