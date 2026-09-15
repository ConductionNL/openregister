## ADDED Requirements

### Requirement: A node type opts in to direct invocation

A node type MUST implement `IFlowDirectlyInvokable` to be reachable through
the direct-invoke endpoint. A node type that does not implement it MUST be
refused (404), even when the flow and node id otherwise resolve.

#### Scenario: A node that has not opted in is refused

- **GIVEN** a published flow with a node whose type does not implement
  `IFlowDirectlyInvokable`
- **WHEN** `POST /api/flows/{flowId}/nodes/{nodeId}/run` is called
- **THEN** the response is 404

#### Scenario: An opted-in node runs directly

- **GIVEN** a published flow with a node whose type implements
  `IFlowDirectlyInvokable`
- **WHEN** the caller holds the required subject permission (see below) and
  calls `POST /api/flows/{flowId}/nodes/{nodeId}/run` with a `subject` and a
  `config` body
- **THEN** only that node runs, against the given subject, and a `FlowRun`
  record is created scoped to it

### Requirement: Direct invocation is authorized against the subject object

The endpoint MUST NOT authorize solely on the flow named-rights matrix
(`flow.run`, which carries no subject dimension). It MUST additionally
require the caller to hold write/update permission, via OpenRegister's
object-level RBAC, on the `subject` object named in the request.

#### Scenario: A caller without subject permission is refused

- **GIVEN** an opted-in node and a subject object the caller cannot write to
- **WHEN** the caller calls the direct-invoke endpoint against that subject
- **THEN** the response is 403, and the node does not run

#### Scenario: `flow.run` alone is not sufficient

- **GIVEN** a caller holding the `flow.run` right instance-wide (the seeded
  `@authenticated` default) but no permission on the specific subject object
- **WHEN** they call the direct-invoke endpoint against that subject
- **THEN** the response is 403

### Requirement: A direct-invoked node's config form is inspectable

When a node type implements `IFlowNodeConfigForm`, its declared fields
(including any `optionsFrom` reference) MUST be resolvable for a node
addressed by `{flowId}/{nodeId}`, so a calling UI can render a picker sourced
from the node's own declaration rather than inventing new per-field grammar.

#### Scenario: A select field's options are sourced from the node's own declaration

- **GIVEN** a node type declaring a `select` field with `optionsFrom`
- **WHEN** the node's form is resolved for `{flowId}/{nodeId}`
- **THEN** the field's `optionsFrom` reference is returned unchanged, for the
  caller to fetch

@e2e exclude new capability, no UI surface in this repo — the e2e path is
dossiq's `case-documents` suite, once `documents-on-the-case` 3.3 unblocks.
