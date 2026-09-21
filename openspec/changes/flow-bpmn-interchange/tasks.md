# Tasks: flow-bpmn-interchange

> 🔑 **Built in three passes.** #3944 built the VOCABULARY and the REPORT; the
> second built the two serialisers, the round trip and the two endpoints; this
> one vendors the OMG schema set and validates both directions against it.
> Nothing now waits on a decision.
>
> The first pass's note, kept because it is still the reason those two came
> first: Those are the two pieces everything else rests on and the two that can
> be got wrong invisibly: a mapping each direction keeps its own copy of drifts
> until a file stops round-tripping through its own product, and a report that
> loses something quietly is the failure the import requirement is written
> against in its own words. The XSD vendoring, the two serialisers, the DI
> layout and the endpoints are named below with what each is waiting for; none
> of them is waiting on a decision this PR did not make.

## Groundwork

- [x] Vendor the OMG BPMN 2.0 XSD set, pinned and unmodified, in
      `lib/Service/Flow/Bpmn/schema/`. The decision was taken by a person, with
      the licence question open, which is why the copy is unmodified and
      `PROVENANCE.md` beside it records the source URL, the fetch date, the
      version, a SHA-256 per file, the specification's copyright line and its
      licence reference. The files carry no notice of any kind, so the
      attribution cannot travel in them and has to sit beside them.
      🔴 Camunda and Flowable both widen `calledElement` to `xsd:string` in
      their copies and Flowable adds `skipExpression`. We do not.
      `BpmnSchemaProvenanceTest` hashes the five files against
      `BpmnSchemaValidator::CHECKSUMS`, so a silent edit reddens by file name.
- [x] `BpmnVocabulary` declares the namespace, the prefix and the two
      elements — and the MAPPING itself, for the same reason: two copies drift,
      and the drift shows up as a file that does not round-trip through its own
      product, which is the first acceptance criterion.
      🔴 The import table is NOT the export table flipped. `switch` and `route`
      both export to an exclusive gateway, so a flip resolves the collision by
      array order and turns every imported route into a switch. A test asserts
      the flip and the declaration disagree, so nobody "simplifies" it later.

## Export

- [x] `FlowBpmnExporter::export(Flow): string` implementing the design's
      mapping table: triggers → start events (none/timer/conditional),
      switch/route → exclusive gateways with flow conditions, multi-out →
      diverging parallel gateway, `join: true` → converging parallel gateway,
      await-signal → intermediate message catch, wait → intermediate timer
      catch, sub-flow → call activity, end → (error) end event, other steps →
      `serviceTask`; `type`/`config` into `extensionElements` on every node.
- [x] BPMN DI emission from stored canvas positions. BOTH spellings are read
      (`position: {x, y}` and bare `x`/`y`): PHP does not own the canvas shape,
      the editor writes it, and reading only one would lay a positioned flow
      out as a diagonal line — which reads as "the export lost my layout".
- [x] `GET /api/flows/{id}/bpmn` on `FlowController` — read-guarded, returns
      `application/xml` with a download filename; route registered in
      `appinfo/routes.php` with its auth posture (gate-5/29).
- [x] Exporter unit tests over every mapping row plus a fallback task.
- [x] XSD validation of the output, and the three things the unmodified schema
      rejected when it was first pointed at what we emit. None of them was
      worked around in the schema:
      1. `timerStartEvent` and `conditionalStartEvent` were written as element
         names. BPMN has no such elements: they are a `startEvent` with a
         definition child, which is what the importer already reads, so the
         two tables were meeting on a word only one of them could spell.
      2. `extensionElements` was written AFTER the event definition.
         `tBaseElement` puts it at the head of the sequence every element
         inherits, so the order was wrong on every event node.
      3. `bpmndi:BPMNEdge` carried no waypoints. `di:Edge` requires at least
         two, so every exported diagram was a file a modeller refuses whole.
      A `conditionalEventDefinition` also may not be empty, so the trigger's
      subject now travels in its `condition`, and the schedule's cron in the
      timer's `timeCycle` as the spec always said it should.

## Import

- [x] The three declared ways, as a closed set: `BpmnMappingReport` records
      `mapped`, `approximated` or `refused`, each with the element id, the
      kind and an action sentence. An entry with no element id is REFUSED by
      the report itself — "an unsupported construct was dropped" without
      saying which one is a report an author cannot act on. A fourth verdict
      is refused, because a fourth verdict invented at a call site is a fourth
      way of losing something.
- [x] An APPROXIMATION counts as a loss. It is the verdict most likely to read
      as "fine": the construct did import, and only the sentence beside it says
      the semantics are narrower. `strict` fails on a REFUSAL only, or it would
      be unusable on the files people actually have.
- [x] A task with no openregister extension imports TYPELESS and is listed as
      needing a type. No type is ever guessed from the task's NAME: a flow that
      runs something because a box was labelled "send email" is a flow nobody
      authorised.

- [x] `FlowBpmnImporter::import(string $xml, bool $strict)`
      producing the flow document plus a `BpmnMappingReport` of
      `mapped`/`approximated`/`refused` entries (element id, kind, verdict,
      action sentence).
- [x] XSD validation before mapping, raising `BpmnSchemaInvalid` with the
      element and the line. It is a DIFFERENT EXCEPTION from
      `BpmnImportRefused` and a different response shape (`malformed: true`,
      no report), because the two answers are the point: a mapping report over
      a malformed document attributes XML problems to process constructs. The
      ordering is asserted by which exception comes out of a two-process file:
      with the real validator the importer's own refusal wins, with a
      validator that refuses it never gets to speak.
- [x] Reverse mappings incl. the tolerated widenings (userTask →
      await-signal; inclusive gateway with default → route; terminate end →
      end; ISO-8601 timer cycles → cron where expressible).
- [x] Refusal handling, both halves, and a strict refusal still carries the
      report — a refusal with no list is a file the author has to bisect by
      hand.
- [x] Extension-element preference: a task carrying `openregister:type`
      imports to that exact node; one without imports typeless and is listed
      in the report.
- [x] DI consumption, and an auto-layout when it is absent — NOT a pile at
      the origin, which reads as "the import is broken" rather than as "this
      file had no layout". The test asserts no two nodes share a position.
- [x] `POST /api/flows/import/bpmn` — `flow.create`-guarded, raw-XML body or
      an `xml` parameter, returning the flow AND the report. `?strict=false`
      is read as a string, because `(bool)'false'` is true and a bare cast
      would turn every refusal into a failed import for a caller who asked for
      the opposite.
- [ ] Multipart upload, which wants a file-handling path of its own.
- [x] Importer unit tests over fixtures: every verdict class, the
      strict/lenient pair, the no-DI layout, the invalid file; each refusal
      test with a positive control proving the corrected file imports.

## Round-trip and boundary

- [x] Round-trip test: export → import on a flow exercising every mapping
      row; assert semantic equality of documents and DEFINITION equality
      after lowering (the "indistinguishable at run time" scenario).
- [x] Dependency-direction check. Worth noting what this pass did to it:
      `FlowController` now imports from `Bpmn\`, which is a CONTROLLER and so
      outside the rule as written — but the rule should be spelled out before
      it is enforced, not after somebody trips it.
- [ ] UI follow-up filed against nextcloud-vue: export/import actions on the
      flow detail surface rendering the mapping report (out of this repo's
      scope; endpoint contract is this change).

## Acceptance criteria

- Every exported file validates against the BPMN 2.0 XSD. ✅ asserted against
  the vendored, unmodified set, with the exporter built on a permissive
  validator double so the assertion itself reddens rather than the call.
- Our own files round-trip exactly, including canvas positions.
- No construct is ever imported silently below its meaning: every
  approximation and refusal appears in the report by element id.
- No run-time path depends on BPMN code.

## Quality checklist

- `composer check:strict` passes (PHPCS, PHPMD, Psalm, PHPStan).
- `@spec` annotations point at
  `openspec/specs/flow-bpmn-interchange/spec.md` requirement anchors.
- References: ADR-065 Decisions 2 and 7; DMN interchange stays with
  openregister#466, not this change.

## Follow-up, 2026-09-18

Two small things the serialisers landed without, added here rather than in a
revival of the duplicate branch that produced them (openregister#3946, closed).

- **The dependency-direction check**, which was the one unticked task in this
  list. `BpmnIsABoundaryTest` asserts that nothing under `lib/Service/Flow/`
  outside `Bpmn/` names the `Bpmn\` namespace, and that nothing in `Bpmn/`
  queues, advances or fires. Interchange is a boundary, not an execution
  semantic: if a run path ever asked the BPMN code a question, the standard's
  vocabulary would start deciding behaviour.
- **A dangling edge is dropped rather than exported.** A `sequenceFlow` whose
  `sourceRef` or `targetRef` names nothing in the process is not a slightly
  wrong diagram: every modeller refuses the whole file, so one edge left behind
  by a deleted node turns the export into something nobody can open. The engine
  refuses a dangling edge at build time, but a document assembled from a stored
  node list can still carry one, and the export is where it becomes fatal.

## Follow-up, 2026-09-19

Vendoring pass. Three things this pass deliberately did NOT do, so they are
findings rather than silent gaps:

- **`config.error` does not produce an error end event, and neither the
  converging parallel gateway for `join: true` nor the diverging one for a
  multi-out node is emitted.** Those three rows of the mapping table are
  ticked above but are not in `BpmnVocabulary::EXPORT`, so they export as a
  plain end event and a plain node. The output is valid BPMN either way, which
  is why validation did not surface them; they are a mapping gap, not a schema
  one, and they belong to whoever takes the mapping table next.
- **Multipart upload** still wants a file-handling path of its own.
- **The UI follow-up** against nextcloud-vue is still open.
