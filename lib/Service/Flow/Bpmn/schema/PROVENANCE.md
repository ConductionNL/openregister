# Vendored OMG BPMN 2.0 schema set

These five files are third-party artefacts, copied here unmodified. They are
not ours, they are not maintained here, and nothing in this directory may be
edited to make our own output pass.

## What they are

The normative machine-readable documents of BPMN 2.0.2 (OMG document
dtc/10-05-04), listed on the specification page as the XML schemas for the
standard. `BPMN20.xsd` is the root; the other four are reached from it by
relative `schemaLocation`, which is why all five have to sit in one directory.

## Where they came from, and when

- Source: `https://www.omg.org/spec/BPMN/20100501/<file>`
- Specification page: `https://www.omg.org/spec/BPMN/2.0.2/`
- BPMN version: 2.0.2
- OMG document number: dtc/10-05-04 (schemas), formal/13-12-09 (specification)
- Fetched: 2026-09-19

## The files, pinned

| File | Bytes | SHA-256 |
| --- | --- | --- |
| `BPMN20.xsd` | 1941 | `a07c159cb0594573dd7c97b1370dd116112378f377e43c89a8bf512ac5030705` |
| `BPMNDI.xsd` | 4010 | `f0dff1cd559d1514d8ebfc8c646f58402bcaced27ec22e2aa6456c2dcc80b038` |
| `DC.xsd` | 1324 | `a2f90e5ad9bb48c6915e4e034b4e27ac838264a1d4f27bfc70dbdfc69351312d` |
| `DI.xsd` | 3470 | `8220b179c175572df74e08a51bffabe957867962035cee7b5fee0b6acb4c4498` |
| `Semantic.xsd` | 62507 | `c4318842f7d2bbc262d7954c9452c501db16f0868eac0b8732ec5d7fb384d9a7` |

The same sums live in `BpmnSchemaValidator::CHECKSUMS` and are asserted by
`tests/Unit/Service/Flow/Bpmn/BpmnSchemaProvenanceTest.php`. A silent edit to a
vendored schema reddens that test by file name. Verify by hand with:

```bash
sha256sum lib/Service/Flow/Bpmn/schema/*.xsd
```

## Copyright and licence

The files carry no notice of any kind. That was checked byte by byte on
2026-09-19: no XML comment, no header, no `LICENSE` beside them, and no
occurrence of the words "copyright" or "licence" in any of the five. So the
attribution the licence asks for cannot travel in the files themselves, and is
reproduced here instead.

The copyright line is the specification's own (formal/13-12-09, page 2):

> Copyright © 2010 Axway Software; Copyright © 2010 BizAgi; Copyright © 2010
> Bruce Silver Associates; Copyright © 2010 IDS Scheer AG; Copyright © 2010
> International Business Machines Corporation; Copyright © 2010 MEGA
> International; Copyright © 2010 Model Driven Solutions; Copyright © 2010
> Object Management Group, Inc.; Copyright © 2010 Oracle, Inc.; Copyright ©
> 2010 PNA Group; Copyright © 2010 SAP AG; Copyright © 2010 Software AG;
> Copyright © 2010 TIBCO Software, Inc.; Copyright © 2010 Unisys

The licence is the specification's LICENSES section, which grants a
"fully-paid up, non-exclusive, nontransferable, perpetual, worldwide license
(without the right to sublicense), to use this specification to create and
distribute software", on three conditions: that the copyright notice and the
permission notice appear on any copies, that the use is informational and the
copies are not resold, and that no modifications are made. Full text:
`https://www.omg.org/spec/BPMN/2.0.2/PDF`, page 2.

## Line endings are part of the bytes

The files are served with CRLF endings and are stored that way. The repository
sets `* text=auto eol=lf`, which would rewrite every line of them on checkout
and leave a working copy that no longer hashes to the sums above, so
`.gitattributes` exempts this directory with `-text`. That is not a cosmetic
setting: a normalised copy is a modified copy of a specification we said we had
not modified, and it would arrive with no diff to look at.

## What we may not do to them

- **No modifications.** Camunda and Flowable both widen `calledElement` from
  `xsd:QName` to `xsd:string` in their vendored copies, and Flowable adds
  `skipExpression`. We do not. When our export produces something the
  unmodified schema rejects, the export is what changes.
- **No selective vendoring.** All five, or the relative imports break.
- **No silent refresh.** A new BPMN version is a deliberate change with new
  sums in this file and in `BpmnSchemaValidator::CHECKSUMS`.

## The open question, recorded rather than resolved

The licence grant speaks of "this specification" throughout and never states
whether the separately published machine-readable files fall under it, under
other terms, or under none. OMG publishes them as normative and attaches no
notice to them. No OMG statement resolving that was found on 2026-09-19.

Vendoring them here was a decision taken with that question open, which is why
the copy is unmodified and the provenance is written down. Two major engines
vendor the same files into public Apache-2.0 repositories, both with
modifications and neither with an OMG notice; that is practice, not permission,
and it is not the basis for this copy.
