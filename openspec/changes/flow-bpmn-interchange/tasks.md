# Tasks: flow-bpmn-interchange

> 🔑 **This PR builds the VOCABULARY and the REPORT, and nothing that touches
> XML.** Those are the two pieces everything else rests on and the two that can
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
- [x] BPMN DI emission from stored canvas positions.
- [x] `GET /api/flows/{id}/bpmn` on `FlowController` — read-guarded, returns
      `application/xml` with a download filename; route registered in
      `appinfo/routes.php` with its auth posture (gate-5/29).
- [~] Exporter unit tests: every mapping row; XSD validation of every
      fixture's output as part of the test, not a separate step.

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

- [ ] `FlowBpmnImporter::import(string $xml, bool $strict): ImportResult`
      producing the flow document plus a `BpmnMappingReport` of
      `mapped`/`approximated`/`refused` entries (element id, kind, verdict,
      action sentence).
- [ ] XSD validation before mapping; a non-validating file refused naming the
      first violation.
- [ ] Reverse mappings incl. the tolerated widenings (userTask →
      await-signal; inclusive gateway with default → route; terminate end →
      end; ISO-8601 timer cycles → cron where expressible).
- [ ] Refusal handling: element dropped + report entry by default; `strict`
      fails the import with no flow created.
- [ ] Extension-element preference: a task carrying `openregister:type`
      imports to that exact node; one without imports typeless and is listed
      in the report.
- [ ] BPMN DI consumption; auto-layout (layered, non-overlapping) when DI is
      absent.
- [ ] `POST /api/flows/import/bpmn` — `flow.create`-guarded, multipart or
      raw-XML body, returns the stored flow plus the report.
- [ ] Importer unit tests over fixture files: every verdict class, the
      strict/lenient pair, the no-DI layout, the invalid file; each refusal
      test with a positive control proving the corrected file imports.

## Round-trip and boundary

- [ ] Round-trip test: export → import on a flow exercising every mapping
      row; assert semantic equality of documents and DEFINITION equality
      after lowering (the "indistinguishable at run time" scenario).
- [x] Dependency-direction check: nothing under `lib/Service/Flow/` outside
      `Bpmn/` imports from `Bpmn\` (enforce with a small architecture test or
      Psalm forbidden-import config).
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

## Status of the export half, 2026-09-18

**The exporter is built, on the vocabulary the sibling lane landed** in #3944.
I had written a second mapping table before that PR appeared on
`parity/round2`; it is deleted. `BpmnVocabulary` owns both directions —
including the fact that the import table is NOT the export table flipped — and
the exporter asks it. A test asserts the exporter holds no mapping rows of its
own, because two tables agree until somebody adds a node type to one of them,
and the disagreement shows up as a file that does not round-trip through its
own product.

- Export is TOTAL: every flow exports. A step the vocabulary has no row for
  becomes the `serviceTask` fallback carrying its real type and configuration
  in `extensionElements`, which a conformant tool must preserve and may ignore.
- The extension is written on EVERY node, including the ones BPMN can name. A
  `switch` and a `route` are both exclusive gateways; without it the file
  cannot say which it was, which is the same collision the vocabulary's import
  table exists to avoid.
- DI comes from stored canvas positions; a node without one is laid out in
  document order rather than stacked on the origin.
- A dangling edge is dropped: a `sequenceFlow` pointing at nothing makes the
  file unopenable in every modeller, turning one broken edge into an export
  nobody can use.
- Ids are made xsd:ID-safe. A uuid starts with a digit as often as not.
- `GET /api/flows/{id}/bpmn`, `flow.read`-guarded, `application/xml`.

**What this export does NOT do, said where the endpoint is:** validate against
the OMG XSD. The schema is not vendored, for the reason the groundwork task
already records, so the output is well-formed XML in the standard's namespaces
and shape and its schema-validity is unverified. The importer needs the schema
first, because "refused because we do not read it" and "refused because the
file is invalid" are different sentences an author acts on differently.

**Still open:** the importer and its report wiring, the round-trip test, the
auto-layout, and the nextcloud-vue surface.
