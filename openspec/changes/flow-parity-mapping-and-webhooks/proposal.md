---
kind: code
---

## Why

Three gaps, found while unifying the flow engine. Each is a case where the same
capability exists twice, or exists only outside the engine.

### 1. The mapping service is implemented twice

`openconnector/lib/Service/MappingService.php` (712 lines) and
`openregister/lib/Service/MappingService.php` (721 lines) are near-duplicates.
They are not two designs for two problems: OpenConnector has **no `lib/Db/` at
all**, and its copy already imports `OCA\OpenRegister\Db\Mapping`. So both
operate on OpenRegister's entity, through OpenRegister's mapper, with almost the
same public surface:

| | OpenConnector | OpenRegister |
|---|---|---|
| `encodeArrayKeys` | ✓ | ✓ |
| `executeMapping` | ✓ | ✓ |
| `coordinateStringToArray` | ✓ | ✓ |
| `getMapping` / `getMappings` | ✓ | ✓ |
| `renderTemplateString` | ✓ | — |
| `invalidateMappingCache` | — | ✓ |

Two copies of a transformation engine is not merely redundant. A mapping that
behaves differently depending on which app evaluated it is a data-correctness
problem, and the divergence above is already real: only one copy invalidates its
cache, and only one can render a bare template string.

Worse, **a flow cannot map at all.** The engine has 15 node types and none of
them transforms data. Every flow that needs to reshape a payload has to route it
out to an endpoint rule and back.

### 2. A webhook can start a flow but cannot answer

Flows already accept a webhook as a trigger. What a webhook trigger cannot do is
everything that makes an endpoint useful as a *delivery point*:

- read data out of the incoming call (an id to look up, a filter, a body to map)
- authenticate the caller
- return a result to the caller

Without those three, a webhook trigger is fire-and-forget only. A CloudEvents
producer that needs an acknowledgement, or a caller that needs the object back,
has to be pointed at an OpenConnector endpoint instead — so the same integration
is expressed as a flow *or* as an endpoint depending on whether anyone needs an
answer.

### 3. The rule pipeline has no node equivalent

`EndpointService::processRules()` dispatches **18 rule types**. The flow engine
has **15 nodes**, contributed by OpenRegister (13) and hermiq (2).
**OpenConnector contributes none** — `openconnector.jti` and
`openconnector.ratelimit` are distributed cache keys, not nodes.

Mapping the two vocabularies:

| Rule type | Node today | Gap |
|---|---|---|
| `save_object` | `openregister.object-write` | — |
| `override` | `openregister.set-fields` | partial |
| `error` | `openregister.stop` | partial (stop carries a message; rule returns a shaped response) |
| `synchronization` | — | **no node** |
| `mapping` | — | **no node** (see 1) |
| `authentication` | — | **no node** (see 2) |
| `webhook_signature` | — | **no node** (see 2) |
| `javascript` | — | **no node** |
| `extend_input` | — | **no node** |
| `extend_external_input` | — | **no node** |
| `download` | — | **no node** |
| `write_file` | — | **no node** |
| `fileparts_create` | — | **no node** |
| `filepart_upload` | — | **no node** |
| `locking` | — | **no node** |
| `audit_trail` | — | **no node** |
| `custom` | — | escape hatch, not a gap |

Thirteen capabilities exist only inside the endpoint pipeline. As long as that
is true, "OpenRegister owns the one flow engine" is only half true — an
integration that needs any of them must be built as an endpoint rule chain
instead, which is a second orchestration model with its own storage, its own
editor and its own semantics.

## What Changes

- **CONSOLIDATE** the mapping service into OpenRegister. Port
  `renderTemplateString` from OpenConnector's copy, delete that copy, and point
  OpenConnector's callers at OpenRegister's. One transformation engine, one
  cache, one set of semantics.
- **ADD** `openregister.map` — a flow node that runs a stored mapping over the
  item list. This is what makes a flow able to reshape data mid-walk instead of
  routing out to an endpoint and back.
- **EXTEND** the webhook trigger into a delivery point: bind request data
  (path, query, headers, body) into the run context so a flow can look up by an
  incoming id; declare an authentication method on the trigger; and let a flow
  return a result, so a CloudEvents producer gets a real acknowledgement.
- **CLOSE** the rule/node gap in priority order, each as a contributed node
  rather than engine-special-cased: `synchronization`, `javascript`,
  `extend_input`, `extend_external_input`, `download`, `write_file`,
  `locking`, `audit_trail`, and the file-part pair. Contributed by the app that
  owns the capability — OpenConnector for the integration ones — through the
  existing `RegisterFlowNodesEvent`, so the engine gains no app knowledge.

## Impact

Sequenced deliberately: mapping first, because the mapping node is the single
most-requested transformation and consolidation is a prerequisite for it;
webhooks second, because they turn flows into a delivery surface; rule parity
last and incrementally, because each node is independently useful and the list
is long.

This does NOT propose deleting the rule pipeline. Endpoints remain the HTTP
surface; the goal is that anything a rule can do, a flow can also do, so an
integration is not forced into the endpoint model just to reach one capability.
