# Platform Capabilities Catalog

Status reference for OpenRegister platform-level capabilities.
Updated when canonical specs graduate new requirements from change directories.

| Capability | Spec | Status | Notes |
|---|---|---|---|
| Event-driven architecture (CRUD events) | `specs/event-driven-architecture/spec.md` | implemented | 39+ typed event classes, 8+ listeners, CloudEvents v1.0 delivery |
| `x-openregister-lifecycle` annotation | `specs/event-driven-architecture/spec.md` | implemented | Graduated from lifecycle-notifications-amendments (#24). TransitionEngine, TransitionController, LifecycleGuardInterface, ObjectTransitionedEvent all shipped. |
| Lifecycle transition endpoint (`POST /api/objects/{id}/transition`) | `specs/event-driven-architecture/spec.md` | implemented | Graduated from lifecycle-notifications-amendments (#24). Auth: `#[NoAdminRequired]`, no `#[NoCSRFRequired]`. |
| Available-actions endpoint (`GET /api/objects/{id}/available-actions`) | `specs/event-driven-architecture/spec.md` | implemented | Graduated from lifecycle-notifications-amendments (#24). |
| `LifecycleGuardInterface` registration | `specs/event-driven-architecture/spec.md` | implemented | Graduated from lifecycle-notifications-amendments (#24). |
| `ObjectTransitionedEvent` | `specs/event-driven-architecture/spec.md` | implemented | Graduated from lifecycle-notifications-amendments (#24). Deterministic action resolution via unique (from, to) constraint. |
| Notificatie engine (configurable rules, batching, history) | `specs/notificatie-engine/spec.md` | partial | In-app + webhook channels partially implemented; notification rules, batching, history not yet built. |
| `x-openregister-notifications` annotation | `specs/notificatie-engine/spec.md` | implemented | Graduated from lifecycle-notifications-amendments (#24). Channel block format, throttle window grammar, trigger types `created`/`updated` are now normative. |
| Webhook delivery with CloudEvents | `specs/event-driven-architecture/spec.md` | implemented | WebhookService, CloudEventFormatter, retry policies. |
| Webhook payload mapping | `specs/webhook-payload-mapping/spec.md` | implemented | MappingService Twig-based transformation. |
| GraphQL API + SSE subscriptions | `specs/graphql-api/spec.md` | implemented | GraphQLSubscriptionListener, SSE buffer. |
| Schema hooks (n8n / Windmill) | `specs/schema-hooks/spec.md` | implemented | HookListener, HookExecutor, HookRetryJob. |
| MCP discovery | `specs/mcp-discovery/spec.md` | implemented | ToolRegistrationEvent, IMcpToolProvider. |
