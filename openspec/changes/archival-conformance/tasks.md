# Tasks: archival-conformance

The findings marked DONE or RESOLVED in proposal.md are built. These tasks are what the audit left open.

## 1. MDTO export completeness

- [x] 1.1 D2: the generator reports every MDTO-required element it fills with a default (`MdtoXmlGenerator::collectMdtoRequiredElementGaps()`, through `assertTransferPreconditions()`), and the XSD test holds the output to MDTO-XML 1.0.1.
- [x] 1.2 D1: emit `betrokkene` from a schema-declared party property. Verify: XSD test with a record that names a party.
- [ ] 1.3 D1: a schema declares which relation expresses containment, and the export emits `isOnderdeelVan` and `bevatOnderdeel` from it. Verify: XSD test on a parent record with two children.

## 2. Docs

- [ ] 2.1 `docs/` states which MDTO elements the export carries and which it leaves out.
