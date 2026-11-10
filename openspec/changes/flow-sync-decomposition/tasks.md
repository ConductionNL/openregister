## 0. Scope of this task list

`proposal.md` and `design.md` cover four sequenced pieces of work. Two are
**already shipped** and are not re-touched here:

- The iteration construct (`openregister.iterate`, and the `openregister.loop`
  → `openregister.batch` rename + migration) — live, confirmed present in the
  node catalogue (`GET /apps/openregister/api/flow/node-catalog`).
- `openregister.map` ("own the Twig engine") — live, same catalogue. Shipped,
  but (see design decision 2 below) not the mapping path this pilot uses.

One is deliberately **out of scope for this task list**: the doriath
credential broker and the `publiccode` worked example (Impact items 2–3 in
`proposal.md`). That is real work but a different proving ground; pulling it
in here would make the decomposition's acceptance test depend on a GitHub PAT
and a schema nobody has authored yet. `credential-broker/tasks.md` already
tracks the descriptor half of that separately.

What remains, and what this task list covers end to end, is Impact item 4: the
four contributed nodes named in `proposal.md`'s "What Changes" —
`openconnector.source-paginate`, `openconnector.change-detect`,
`openconnector.contract-resolve`, `openconnector.contract-write` — sized to
the fleet's heaviest real consumer, spectr, using its `tenderned` connector
bundle (`spectr/connectors/tenderned.json`) as the pilot.

`openconnector.synchronization-run` and `openconnector.source-call` are
**not touched, not deprecated in code, and not disabled** by any task below.
Both stay live in the catalogue throughout. Per `design.md`'s closing
paragraph, `synchronization-run` is retired only once a decomposed flow has
demonstrably replaced it for a real synchronisation — that is task group 7
below, not this change.

### Design decisions this task list makes (not in design.md, needed to start)

`design.md` names the four nodes but not how they address a contract without
running the monolith, or how they reach a Mapping. Two things are resolved
here, both confirmed against the current code rather than assumed:

**1. All four nodes accept the same `synchronization` reference
`SynchronizationRunNode` already accepts** (a uuid/slug/reference into
register `openconnector`, schema `synchronization`), but never call
`SynchronizationService::synchronize()`. The reference is read purely for:

- `sourceConfig` (endpoint, query, pagination) — `source-paginate`
- `sourceHashMapping` / `sourceTargetMapping` — `change-detect` /
  `contract-write`
- `targetId` / `targetType` — `contract-resolve` / `contract-write`
- the contract SCOPE key (`synchronizationId`) that
  `SynchronizationContractService::findBySyncAndOrigin()` already keys on

This is what makes the decomposition genuinely backward-interoperable rather
than merely dialect-compatible: a decomposed flow and the classic monolith
pointed at the **same** `synchronization` object read and write the **same**
`synchronization_contract` rows and the **same** target objects. Re-running
either dialect against the same synchronisation is idempotent with respect to
the other, and `tenderned-to-spectr-tender` needs no changes to run through
either path.

**2. `openregister.map` (`lib/Service/Flow/Nodes/MapNode.php`) is NOT used by
the pilot flow, and no task below tries to make it work here.** Checked
directly: `MapNode` resolves its `mapping` config through
`OCA\OpenRegister\Db\MappingMapper`, OpenRegister's own native
`oc_openregister_mappings` entity table. Spectr's `tenderned-to-tender` and
`tenderned-hash` Mappings are not rows in that table — they are OpenRegister
**objects**, register `openconnector`, schema `mapping`, the same kind of
record a Source or a Synchronization is. `MapNode` has no path to them today,
and widening `MappingMapper`/`MapNode` to read the object store is a
cross-cutting OpenRegister change this task list does not take on.

Nothing needs fixing to hit the requirement, though: OpenConnector already has
its own `OCA\OpenConnector\Service\MappingService::executeMapping()`, which
accepts an `ObjectEntity` (or an id/slug it resolves itself) and is exactly
what `synchronizeContract()` calls today for both `sourceTargetMapping` and
`sourceHashMapping`. `change-detect` (task 2) and `contract-write` (task 4)
call it directly. This keeps mapping resolution entirely on the OpenConnector
side of the boundary, matches what the classic dialect already does field for
field, and needs zero changes to OpenRegister's flow engine or its native
Mapping table.

## 1. `openconnector.source-paginate`

- [ ] 1.1 Create `lib/Flow/SourcePaginateNode.php` implementing `IFlowNode`
      (id `openconnector.source-paginate`), styled after `SourceCallNode.php`:
      SPDX header, `@spec` tag, class docblock explaining it is an
      **iterate-compatible source step** — its contract is dictated by
      `IterateNode::seedFor()`/`execute()` in openregister (return `[]` to
      signal "no more pages"; read the pass index from
      `item.json.iteration.index`, never from `$context`, because a step's
      templates render against the item).
- [ ] 1.2 `validateConfig()`: require `source` (Source reference) and
      `synchronization` (reference, per task 0's decision); accept
      `endpoint`, `query`, `headers` as literal overrides for when an author
      wants a page fetch with no `synchronization` object at all, but default
      every one of them from the referenced synchronization's `sourceConfig`
      when absent, so `tenderned-to-spectr-tender`'s existing `sourceConfig`
      (`endpoint: /publicaties`, `query: {size, sort}`, `resultsPosition:
      content`, `idPosition: publicatieId`, `maxPages: 10`) drives the node
      unchanged. Reject a `synchronization` that does not resolve.
- [ ] 1.3 Implement the single-page fetch by delegating to `CallService`
      through the resolved `Source` — mirror `SourceCallNode::outcomeOf()`'s
      transport/status handling (no re-implemented retry, no re-implemented
      auth) rather than reopening `SynchronizationService::fetchAllPages()`'s
      private internals.
- [ ] 1.4 Implement `resultsPosition` / `idPosition` extraction identically to
      `SynchronizationService::getAllObjectsFromApi()`'s existing dot-path
      read, so a raw TenderNed page (`content: [...]`) and a raw TED page
      (`notices: [...]`) both resolve the same way they do today.
- [ ] 1.5 Implement body-based pagination (`usesPagination`, `paginationIn:
      "body"`, `paginationQuery: "page"`) reusing
      `CallService::applyBodyPagination()` — this is what TED's connector
      needs (openconnector#105) and must not regress even though `tenderned`
      itself only needs query-string pagination.
- [ ] 1.6 Fix the known TenderNed page-index defect
      (`tenderned.json`'s own `$comment`: OpenConnector's 1-based
      `currentPage` counter does not line up with TenderNed's 0-based `page`
      query param past page one) by adding an explicit `pageOffset` config
      key (default `0`) that is added to the computed page number before it
      is rendered into the request — `tenderned`'s connector sets
      `pageOffset: -1` so its first request omits `page` and its second sends
      `page=1` (TenderNed's true second page), matching the behaviour
      documented as a KNOWN LIMITATION that "must be fixed... before this
      ships a genuine multi-page crawl".
- [ ] 1.7 Implement cross-RUN cursor persistence via `$context['flowState']`
      (`FlowStateHandle`, already shipped — see `FlowStateNode.php`): on
      entry, read the starting page for THIS run from
      `flowState.get('sourcePaginate.<stepId>.page')`, defaulting to `1`;
      compute `page = startPage + iteration.index`; on exit (whether the
      source ran dry or `maxPages` was reached this run), write the next
      starting page back so a weekly-scheduled flow resumes rather than
      re-walking pages it already covered — the direct equivalent of the
      classic engine's `synchronization.currentPage` field, without a second
      register write per page.
- [ ] 1.8 Enforce `maxPages` as a per-RUN ceiling (return `[]` once reached,
      same semantics as today's `sourceConfig.maxPages`), and return `[]`
      immediately when a fetched page's `resultsPosition` array is empty —
      the two are the same termination signal `IterateNode` already expects.
- [ ] 1.9 Emit one item per source record, `json` = the raw record, with
      `iteration.index`/`iteration.first` preserved and `sourceId` /
      `originId` (via `idPosition`) written under a fixed, documented key
      (e.g. `__source.originId`) so `change-detect` never has to re-derive it.

## 2. `openconnector.change-detect`

- [ ] 2.1 Create `lib/Flow/ChangeDetectNode.php` implementing `IFlowNode`
      (id `openconnector.change-detect`). Class docblock states its ONE job:
      a cheap read-and-compare that lets an unchanged record leave the flow
      **before** `contract-resolve` or `contract-write` ever run — directly
      answering the perf note already written into `SynchronizationRunNode`'s
      own docblock (374 unchanged records, 17.2s spent deciding there was
      nothing to do, because the monolith pays the full write-side cost on
      the skip path too).
- [ ] 2.2 `validateConfig()`: require `synchronization`; resolve its
      `sourceHashMapping` (may be absent — no hash mapping means "hash the
      raw record verbatim", matching `mapHashObject()`'s existing fallback).
- [ ] 2.3 Compute the hash by delegating to
      `OCA\OpenConnector\Service\MappingService::executeMapping()` (the same
      call `SynchronizationService::mapHashObject()` makes) followed by the
      same `hashObject()`/`md5(serialize(...))` step — inject `MappingService`
      directly rather than re-implementing Twig-mapped hashing a second time;
      this is what makes `tenderned-hash`'s existing Mapping object (which
      already drops the daily-recomputing `aantalDagenTotSluitingsDatum`
      field) usable unmodified, per design decision 2 above.
- [ ] 2.4 Look up the existing contract for this origin via
      `SynchronizationContractService::findBySyncAndOrigin()`, keyed by the
      `synchronization` reference and the `originId` `source-paginate` wrote
      in task 1.9.
- [ ] 2.5 Tag every item `__contract.changed: true|false` plus
      `__contract.priorTargetId` / `__contract.priorOriginHash` when a
      contract already exists — mirroring `synchronizeContract()`'s existing
      skip predicate (hash match AND synchronization/mapping not updated
      since last check AND target id/hash both present) so the decomposed
      skip decision agrees with the monolith's, not just with a bare hash
      compare.
- [ ] 2.6 Do NOT filter inside this node — leave that to the existing
      `openregister.filter` step (`condition: "{{ __contract.changed }}"`)
      placed immediately after it in the pilot flow (task 6). Keeping the
      filter external is what makes an author able to override the skip
      decision (e.g. force a full re-map) without touching this node's code.

## 3. `openconnector.contract-resolve`

- [ ] 3.1 Create `lib/Flow/ContractResolveNode.php` implementing `IFlowNode`
      (id `openconnector.contract-resolve`). Runs only on items that survived
      the `changed` filter (task 2.6). Its job is exactly the table's third
      row and nothing else: resolve which target object (if any) this origin
      already maps to. It does not map, write, or touch the contract log.
- [ ] 3.2 `validateConfig()`: require `synchronization`.
- [ ] 3.3 Reuse `__contract.priorTargetId` from `change-detect` when present
      (no second contract read — `change-detect` already did the
      `findBySyncAndOrigin()` lookup in task 2.4); when absent (a miss —
      first time this origin has been seen), write `__contract.priorTargetId:
      null`, which `contract-write` reads as "insert".
- [ ] 3.4 Resolve and validate `__write.register` / `__write.schema` from the
      synchronization's own `targetId` (`"spectr/tender"` splits to register
      `spectr`, schema `tender`) and `targetType` (must be `register/schema`
      for this pilot — throw naming any other `targetType` as unsupported by
      the decomposed path today, same restriction the classic dialect's
      `updateTarget()` dispatch implies for this pilot's purposes even though
      it itself also supports table targets).

## 4. `openconnector.contract-write`

- [ ] 4.1 Create `lib/Flow/ContractWriteNode.php` implementing `IFlowNode`
      (id `openconnector.contract-write`). This is the table's fourth row
      taken WHOLE — "write to target + update the contract" — because that
      is how `synchronizeContract()` itself does it: map, write via
      `updateTarget()`, then persist the contract, in one method. Splitting
      "apply mapping" and "write" into two more node types was considered and
      rejected for this pilot: `openregister.object-write` cannot reach
      `updateTarget()`'s table-target dispatch or its contract-scoped
      identity semantics without config surface `ObjectWriteNode` does not
      have today, and inventing a fifth node type is exactly the "generic
      rebuild" this task list is scoped away from.
- [ ] 4.2 `validateConfig()`: require `synchronization`.
- [ ] 4.3 Apply `sourceTargetMapping` via
      `OCA\OpenConnector\Service\MappingService::executeMapping()` against
      the item's raw source `json` — the same call
      `synchronizeContract()` makes — producing the target payload.
- [ ] 4.4 Delegate the actual write to
      `SynchronizationService::updateTarget()` (public), passing the resolved
      `synchronization`, the mapped payload, and `__contract.priorTargetId`
      from `contract-resolve` as the existing target id (or none, for an
      insert) — no reimplemented create/update dispatch, no direct
      `ObjectService` call from this node.
- [ ] 4.5 Persist contract bookkeeping via
      `SynchronizationContractService::persist()` /
      `createFromArray()`/`updateFromArray()` — `originHash` (from
      `change-detect`'s task 2.3 output), `targetId` (from the object
      `updateTarget()` just wrote), `targetHash`, `sourceLastChecked`,
      `sourceLastChanged` — the same fields `synchronizeContract()` writes
      today, so a contract row created by the classic dialect and later
      updated by the decomposed flow (or vice versa) has no field the other
      dialect doesn't understand.
- [ ] 4.6 Batch the contract persist with `persistBulk()` when the upstream
      batch has more than one item, rather than one `persist()` call per item
      — the monolith's own docblock already measured per-object writes as the
      dominant cost; the decomposition should not reintroduce that on the
      write-side after removing it on the skip-side (task 2).
- [ ] 4.7 Emit the item unchanged plus `__contract.written: true`, so a
      downstream step (or the run log) can distinguish "wrote a target
      object and its contract" from "skipped" without re-reading state.

## 5. Shared node plumbing

- [ ] 5.1 Reuse `FlowNodeSupport::onErrorPolicy()` / `stepId()` /
      `ON_ERROR_POLICIES` / `ERROR_KEY` in all four new nodes — no new
      onError vocabulary.
- [ ] 5.2 Reuse `FlowOwner::resolve()` / `runAs()` in all four for
      fail-closed run-owner attribution, exactly as `SourceCallNode` and
      `SynchronizationRunNode` already do — a decomposed sync must not be
      able to write objects with no attributable owner just because it is
      four small steps instead of one big one.
- [ ] 5.3 Add a shared `SynchronizationReferenceGuard` (or extend
      `FlowConfigGuard`) that resolves and validates a `synchronization`
      reference once, since all four nodes take the same field — avoid four
      copies of `resolveSynchronization()`.
- [ ] 5.4 Add `flow-source-paginate.svg`, `flow-change-detect.svg`,
      `flow-contract-resolve.svg`, `flow-contract-write.svg` to
      `img/` (or reuse an existing icon per node where a distinct one adds no
      information — confirm with whoever owns the palette's visual language
      before drawing four new ones).

## 6. Registration and the pilot flow

- [ ] 6.1 Register all four nodes in `FlowNodeListener::handle()` alongside
      the existing two — one listener, six nodes, no new event wiring.
- [ ] 6.2 Author the pilot flow as a seed/example flow definition (JSON, same
      shape as an existing seeded flow) wiring:
      `openregister.trigger-schedule` → `openregister.iterate` (`source:
      openconnector.source-paginate`, `body: [openconnector.change-detect,
      openregister.filter, openconnector.contract-resolve,
      openconnector.contract-write]`, `maxIterations: 10`, `onLimit: stop`)
      → `openregister.end`. All four new steps' config point at the SAME
      `tenderned-to-spectr-tender` synchronization object spectr already
      ships — no new Mapping, Source or Synchronization is authored, and
      `openregister.map` does not appear in the chain (design decision 2).

## 7. Coexistence, not migration

- [ ] 7.1 Confirm (do not change) that `openconnector.synchronization-run`
      and `openconnector.source-call` remain registered, enabled, and
      undeprecated in the node catalogue after this change ships — a live
      `GET /apps/openregister/api/flow/node-catalog` check, not a code read.
- [ ] 7.2 Leave `tenderned-to-spectr-tender`'s existing weekly `job` object
      (`jobClass: SynchronizationAction`) running unchanged throughout. The
      pilot flow (task 6.2) is a SEPARATE, initially-disabled trigger against
      the same synchronization — never both firing against the same
      `sourceConfig` on the same schedule, which would double-fetch
      TenderNed's feed.
- [ ] 7.3 Document in this change's `design.md` (one short addendum, not a
      rewrite) the exact condition under which `synchronization-run` may
      later be deprecated: the pilot flow has produced row-for-row parity
      (task 8) on at least one real spectr synchronization for at least one
      full scheduled cycle, and no other synchronization in the fleet has
      been decomposed yet requires PHP the four nodes cannot express.

## 8. Tests

- [ ] 8.1 Unit-test `SourcePaginateNode`: page-index math with and without
      `pageOffset`, empty-page termination, `maxPages` termination,
      flow-state cursor read/write across two simulated runs, body-pagination
      delegation to `applyBodyPagination()`.
- [ ] 8.2 Unit-test `ChangeDetectNode`: hash-mapping applied via
      `MappingService::executeMapping()` before hashing, no-hash-mapping
      fallback, skip-predicate parity with `synchronizeContract()`'s
      five-condition check (same hash, config/mapping not updated since,
      target id+hash both present), first-seen origin (`changed: true`, no
      `priorTargetId`).
- [ ] 8.3 Unit-test `ContractResolveNode`: known origin resolves
      `priorTargetId`; unknown origin resolves `null` (insert path);
      `targetId` splitting (`"spectr/tender"` → register/schema); rejection
      of an unsupported `targetType`.
- [ ] 8.4 Unit-test `ContractWriteNode`: `MappingService::executeMapping()`
      called with the resolved `sourceTargetMapping`, `updateTarget()` called
      with the mapped payload and the correct existing/absent target id,
      single-item `persist()` vs batched `persistBulk()`, the persisted
      fields match `synchronizeContract()`'s field names exactly,
      `__contract.written` always set.
- [ ] 8.5 Integration test: run the pilot flow (task 6.2) against a
      stubbed/recorded TenderNed response fixture (reuse the fixture, if any
      already exists from the live-verification transcript referenced in
      `tenderned.json`'s `$comment`; otherwise capture one page's worth,
      trimmed) and assert the resulting `synchronization_contract` and
      `spectr/tender` objects are byte-for-byte what
      `SynchronizationService::synchronize()` would have produced from the
      same fixture.

## 9. Live verification — the acceptance test

- [ ] 9.1 On a spectr-spike-style isolated instance (not shared `:8080`),
      import `spectr/connectors/tenderned.json` unmodified.
- [ ] 9.2 Run `tenderned-to-spectr-tender` once through the classic
      `synchronization-run` path (as `tenderned.json`'s own live-test
      transcript already did on 2026-07-05) and record: object count,
      contract count, every contract's `originHash`/`targetId`.
- [ ] 9.3 Reset the target register (or point the pilot flow at a second,
      empty target register/schema) and run the SAME single page
      (`maxPages` patched to 1, matching the precedent already set for the
      classic path's own live test) through the decomposed pilot flow
      (task 6.2) instead.
- [ ] 9.4 Assert row-for-row parity: same count of created `spectr/tender`
      objects, same field values per record (allowing for the one known,
      pre-existing mapping defect already fixed in `tenderned.json` —
      `procedure`/`typeOpdracht`/`publicatiestatus` `default('')` wrapping —
      which both paths now share since they consume the same Mapping
      object), same count of `synchronization_contract` rows, same
      `originHash` per contract.
- [ ] 9.5 Re-run the decomposed flow a second time with no source change and
      confirm: zero new target objects (contract-keyed upsert prevents
      duplication, same property `tenderned.json`'s transcript already
      verified for the classic path), and `change-detect` reports every item
      `changed: false` — i.e. the skip path this decomposition exists to make
      cheap actually triggers, closing the "did not conclusively isolate
      why the hash-based skip did not trigger" open question the classic
      path's own live-test transcript left unresolved.
- [ ] 9.6 Run `composer check:strict` in openregister and openconnector.

## Acceptance criteria

- `openconnector.source-paginate`, `openconnector.change-detect`,
  `openconnector.contract-resolve` and `openconnector.contract-write` appear
  in the flow node catalogue alongside (not instead of)
  `openconnector.synchronization-run` and `openconnector.source-call`.
- A flow built entirely from existing + these four new nodes reproduces
  `tenderned-to-spectr-tender`'s classic single-page run row for row: same
  target objects, same contract rows, same hashes.
- The decomposed flow consumes spectr's existing `tenderned-hash` and
  `tenderned-to-tender` Mapping objects (register `openconnector`, schema
  `mapping`) completely unmodified — zero of spectr's 25+ mappings are
  re-authored.
- A second run with no source change writes zero new target objects and
  `change-detect` reports every item unchanged.
- `openconnector.synchronization-run` is unmodified, still registered, still
  enabled, and `tenderned-to-spectr-tender`'s existing weekly `job` keeps
  running against it throughout — nothing about the classic dialect is
  disabled, deprecated in code, or deleted by this change.
- Pagination state (the `currentPage` equivalent) survives across separate
  scheduled flow runs via `flowState`, not just across iterations within one
  run.
- The TenderNed 1-based/0-based page-index defect documented in
  `tenderned.json`'s `$comment` is fixed for the decomposed path via
  `pageOffset`, and does not need to be fixed in the classic path for this
  change to ship (that path is untouched).
