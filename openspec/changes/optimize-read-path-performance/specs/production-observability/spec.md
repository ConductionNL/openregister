## ADDED Requirements

### Requirement: Render and read paths log at debug level per request

The system SHALL emit log statements that fire on every render or read request — batch-preload
progress in the object renderer, per-request RBAC/flow tracing in the objects controller — at
`debug` level, never `info` or higher. `info` level on these paths is reserved for genuine business events
(e.g. a bulk operation skipping an object due to a RESTRICT constraint) and explicit administrative
batch operations; routine per-request progress MUST NOT appear in production logs at the default
log level.

#### Scenario: Extended list render emits no info logs

- **WHEN** a list of objects is rendered with `_extend` set
- **THEN** the batch-preload progress messages are logged at `debug` level only

#### Scenario: Object PATCH emits no per-request info tracing

- **WHEN** an object is updated via PATCH
- **THEN** the request-flow tracing messages (settings dump, save-succeeded, preparing-response)
  are logged at `debug` level only
