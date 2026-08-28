# Tasks

## event-driven-architecture (sub-clusters 1, 2, 3)

- [x] task-1: event-driven-architecture#REQ — Object CRUD lifecycle events carry their entity payloads (ObjectCreatedEvent, ObjectDeletedEvent getters) (retroactive annotation; getters previously annotated under retrofit-2026-04-23-annotate-openregister, left as-is)
- [x] task-2: event-driven-architecture — ObjectTransitionedEvent realises the new "State-machine transitions MUST dispatch a typed ObjectTransitionedEvent" requirement (constructor + getObject/getAction/getFrom/getTo/getUserId/getRegister/getSchema)
- [x] task-3: event-driven-architecture — ObjectUnlockedEvent realises the new "Object unlock MUST dispatch a typed ObjectUnlockedEvent" requirement (getObject; previously annotated to 2026-04-23 retrofit, now re-anchored)
- [x] task-4: event-driven-architecture#REQ — Non-object meta-entity mutation events carry their entity payloads — constructor-only events ConfigurationUpdatedEvent + ViewUpdatedEvent brought into coverage (no getters to annotate; all other meta-entity getters annotated under retrofit-2026-04-23-annotate-openregister)

## reference-existence-validation (sub-cluster 4)

- [x] task-5: reference-existence-validation — ReferenceValidatedEvent getters realise the existing "Validation events MUST be dispatched for notification and extensibility" requirement (getPropertyName/getReferencedUuid/getTargetSchemaSlug/getTargetRegister)
- [x] task-6: reference-existence-validation — ReferenceValidationFailedEvent getters realise the same requirement (getPropertyName/getReferencedUuid/getTargetSchemaSlug/getTargetRegister)

## rbac-scopes (sub-cluster 5)

- [x] task-7: rbac-scopes — CustomScopeEvaluatingEvent realises the new "Custom action verbs MUST be resolvable via a voting event pair" requirement (getSchema/getAction/getUserId/getUserGroups/getObject + allow/deny/getVerdict/hasVerdict voting)
- [x] task-8: rbac-scopes — CustomScopeEvaluatedEvent telemetry pair realises the same requirement (getSchema/getAction/getUserId/getVerdict/isFromListener)

## deep-link-registry (sub-cluster 6)

- [x] task-9: deep-link-registry — DeepLinkRegistrationEvent.getRegistry() realises the existing "Apps SHALL register deep link patterns via boot-time events" requirement (register() convenience method previously annotated under retrofit-2026-04-23-annotate-openregister)
