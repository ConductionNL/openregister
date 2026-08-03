---
kind: code
---

## Why

Three gaps, found while unifying the flow engine. Each is a case where the same
capability exists twice, or exists only outside the engine.

### 1. The mapping service is implemented twice

`openconnector/lib/Service/MappingService.php` (910 lines) and
`openregister/lib/Service/MappingService.php` (721 lines) both evaluate mappings.
They are not two designs for two problems: OpenConnector has **no `lib/Db/` at
all**, and its copy already imports `OCA\OpenRegister\Db\Mapping`, so both operate
on OpenRegister's entity through OpenRegister's mapper.

They are, however, **not near-duplicates**. Comparing method by method:

| Shared method | OC lines | OR lines | Same? |
|---|---|---|---|
| `executeMapping` | 118 | 126 | **drifted** |
| `handleCast` | 166 | 33 | **drifted** |
| `getMappings` | 27 | 4 | **drifted** |
| `getMapping` | 17 | 26 | **drifted** |
| `encodeArrayKeys` | 17 | 20 | **drifted** |
| `__construct` | 21 | 80 | **drifted** |
| `coordinateStringToArray` | 23 | 23 | identical |
| `areAllArrayKeysNull` | 19 | 18 | identical |

Only two of eight shared methods are identical. `handleCast` differs five-fold,
and `executeMapping` — the entry point everything else goes through — differs in
both copies.

On top of that, each copy has methods the other lacks. OpenConnector: `renderTemplateString`
(3 call sites in `SynchronizationService`), `translateVngFilterOperators` and
`expandRelations` (both live in `EndpointService`), `translatePartijIdentificatorFilter`,
plus the private `normaliseMapping`, `findMappingByIdentifier`, `resolveExpandValue`.
OpenRegister: `applyCast`, `getCachedTemplate`, `invalidateMappingCache`.

Two copies of a transformation engine is not merely redundant. A mapping that
behaves differently depending on which app evaluated it is a data-correctness
problem — and with `executeMapping` and `handleCast` both drifted, that is not a
risk but the current state.

This also means consolidation is a genuine merge, not a delete-and-repoint:
six drifted methods must be reconciled behaviour by behaviour, seven methods
ported, and roughly twenty OpenConnector files rewired. Sequencing it first is
therefore right, but it should be costed as its own piece of work rather than as
a preliminary to the mapping node.

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

`EndpointService::processRules()` dispatches **23 rule types** (counted from the
`match ($ruleType)` block, not from documentation). The live node catalogue
returns **18 nodes**: OpenRegister 14, OpenConnector 2, hermiq 2.

Two earlier claims here were wrong and are corrected. OpenConnector **does**
contribute nodes — `openconnector.source-call` and
`openconnector.synchronization-run` — so `synchronization` already has one. And
`mapping` now has `openregister.map`. Both were read off the running
`/api/flow/node-catalog`, which is the only source that reflects what the engine
will actually resolve; the earlier table was assembled by reading code.

Mapping the two vocabularies:

| Rule type | Node today | Gap |
|---|---|---|
| `save_object` | `openregister.object-write` | — |
| `override` | `openregister.set-fields` | partial |
| `error` | `openregister.stop` | partial (stop carries a message; rule returns a shaped response) |
| `mapping` | `openregister.map` | — (shipped) |
| `synchronization` | `openconnector.synchronization-run` | — (already existed) |
| `flow` | `openregister.sub-flow` | — |
| `authentication` | — | covered by the webhook-delivery spec |
| `webhook_signature` | — | covered by the webhook-delivery spec |
| `javascript` | — | **no node** |
| `extend_input` | — | **no node** |
| `extend_external_input` | — | **no node** |
| `download` | — | **no node** |
| `write_file` | — | **no node** |
| `fileparts_create` | — | **no node** |
| `filepart_upload` | — | **no node** |
| `locking` | — | **no node** |
| `audit_trail` | — | **no node** |
| `approval` | — | **no node** |
| `composite_fanout` | — | **no node** |
| `avg_bsn_policy` | — | **no node** |
| `referentienummer` | — | **no node** |
| `selfurl_hal` | — | **no node** |
| `custom` | — | escape hatch, not a gap |

Thirteen capabilities still exist only inside the endpoint pipeline. As long as that
is true, "OpenRegister owns the one flow engine" is only half true — an
integration that needs any of them must be built as an endpoint rule chain
instead, which is a second orchestration model with its own storage, its own
editor and its own semantics.

## What Changes

- **CONSOLIDATE** the mapping service into OpenRegister: reconcile the six
  drifted methods, port the seven OpenConnector-only ones, repoint
  OpenConnector's callers, then delete that copy. One transformation engine, one
  cache, one set of semantics. Every reconciliation is a behavioural choice and
  must be recorded as one — picking silently would replace a visible divergence
  with an invisible one.
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
