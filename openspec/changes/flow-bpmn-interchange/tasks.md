# Tasks: flow-bpmn-interchange

## Groundwork

- [ ] Vendor the OMG BPMN 2.0 XSD set (version-pinned, licence-checked) under
      `lib/Service/Flow/Bpmn/schema/`; wire `DOMDocument::schemaValidate`
      behind a helper both directions share.
- [x] Declare the `openregister` extension namespace and its two elements
      (`type`, `config`) in one place both exporter and importer read.

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

## Status, 2026-09-18

**Built: the export half, over a subset that is written down.**

`BpmnMapping` holds the subset as data, in one place both directions read. It
carries two lists, and the second is the point: `EXPORT`, the node types the
standard has a word for, and `NOT_SUPPORTED`, the parts of BPMN 2.0 this app
does not read at all — choreographies and conversations, compensation and
transactions, event sub-processes, boundary events, lanes and pools beyond the
first participant, more than one process per file, data objects and item
definitions, multi-instance and loop markers. Claiming "BPMN support" and
quietly dropping those is how an exported diagram comes back from another tool
meaning something else.

- Export is TOTAL: every flow exports. A step the standard has no word for
  becomes a `serviceTask` carrying its real type and configuration in
  `extensionElements`, which a conformant tool must preserve and may ignore.
- The extension is written on EVERY node, including the ones BPMN can name. A
  `switch` and a `route` are both exclusive gateways; without it the file
  cannot say which it was, and our own round-trip would be close rather than
  exact.
- DI comes from stored canvas positions; a node without one is laid out in
  document order rather than stacked on the origin.
- A dangling edge is dropped rather than exported: a `sequenceFlow` pointing at
  nothing makes the file unopenable in every modeller, turning one broken edge
  into an export nobody can use.
- Ids are made xsd:ID-safe. A uuid starts with a digit as often as not.

**What is NOT read, in this change, and matters most:**

- **The OMG XSD is not vendored, so nothing is validated against it.** The
  output is well-formed XML in the standard's namespaces and shape; whether it
  is schema-VALID is unverified. The groundwork task stays open deliberately:
  vendoring the schema set is a licence decision and a supply-chain decision,
  and doing it by hand from an unpinned download is exactly the move the ADR
  warns about elsewhere. Until then the endpoint's docblock says what the file
  is and is not.
- **The importer is not built**, nor the mapping report, nor the round-trip
  test, nor the auto-layout. Import is the half where BPMN is bigger than the
  engine and every approximation has to be reported by element id; it is a
  change's worth of work and it needs the XSD first, because "refused because
  we do not read it" and "refused because the file is invalid" are different
  sentences and a user acts on them differently.
- **The UI follow-up** against nextcloud-vue is unfiled; the endpoint contract
  is here and the surface is not.
