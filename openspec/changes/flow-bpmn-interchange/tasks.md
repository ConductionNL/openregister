# Tasks: flow-bpmn-interchange

## Groundwork

- [ ] Vendor the OMG BPMN 2.0 XSD set (version-pinned, licence-checked) under
      `lib/Service/Flow/Bpmn/schema/`; wire `DOMDocument::schemaValidate`
      behind a helper both directions share.
- [ ] Declare the `openregister` extension namespace and its two elements
      (`type`, `config`) in one place both exporter and importer read.

## Export

- [ ] `FlowBpmnExporter::export(Flow): string` implementing the design's
      mapping table: triggers → start events (none/timer/conditional),
      switch/route → exclusive gateways with flow conditions, multi-out →
      diverging parallel gateway, `join: true` → converging parallel gateway,
      await-signal → intermediate message catch, wait → intermediate timer
      catch, sub-flow → call activity, end → (error) end event, other steps →
      `serviceTask`; `type`/`config` into `extensionElements` on every node.
- [ ] BPMN DI emission from stored canvas positions.
- [ ] `GET /api/flows/{id}/bpmn` on `FlowController` — read-guarded, returns
      `application/xml` with a download filename; route registered in
      `appinfo/routes.php` with its auth posture (gate-5/29).
- [ ] Exporter unit tests: every mapping row; XSD validation of every
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
- [ ] Dependency-direction check: nothing under `lib/Service/Flow/` outside
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
