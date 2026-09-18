# Tasks: flow-bpmn-interchange

> 🔑 **Built in two passes.** #3944 built the VOCABULARY and the REPORT;
> this pass builds the two serialisers, the round trip and the two endpoints.
> Everything that does not need the vendored schema set is done; the one
> requirement that genuinely waits on the licence decision is named below and
> is not worked around.
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

- [ ] Vendor the OMG BPMN 2.0 XSD set. NOT DONE HERE, deliberately: it is a
      licence-checked third-party artefact fetched from omg.org, and vendoring
      one on a build-lane's judgement is the kind of thing that is discovered
      six months later in a licence audit. It wants a person who can say yes to
      the licence.
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
- [ ] 🔴 **XSD validation of the output. THIS IS THE REQUIREMENT THAT WAITS ON
      THE LICENCE DECISION**, and it is not worked around. The acceptance
      criterion "every exported file validates against the BPMN 2.0 XSD" is NOT
      met. What the tests assert instead is strictly weaker and says so: the
      output is well-formed XML, carries the declared namespaces, and
      round-trips through our own importer.

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
- [ ] 🔴 **XSD validation before mapping — the same licence wait.** The
      importer checks that the document PARSES and that it holds exactly one
      `bpmn:process`, and refuses otherwise. That is weaker, it is stated in
      the class docblock, and the mapping report is NOT a substitute: a
      malformed document would have its XML problems attributed to process
      constructs, which is what the XSD step exists to prevent.
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
- [ ] Dependency-direction check. Worth noting what this pass did to it:
      `FlowController` now imports from `Bpmn\`, which is a CONTROLLER and so
      outside the rule as written — but the rule should be spelled out before
      it is enforced, not after somebody trips it.
- [ ] UI follow-up filed against nextcloud-vue: export/import actions on the
      flow detail surface rendering the mapping report (out of this repo's
      scope; endpoint contract is this change).

## Acceptance criteria

- Every exported file validates against the BPMN 2.0 XSD.
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
