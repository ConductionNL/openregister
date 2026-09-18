# Tasks: relations-that-travel-and-what-they-expose

## 1. The affected set

- [x] 1.1 `AffectedSet::derive()` over `RelationGraphService::graph()`'s
      answer — NOT a second walk (D-1). `types: null` means "not filtered by
      type"; `types: []` is REFUSED, because a caller who named no types either
      meant everything or meant nothing and those are opposite answers.
- [x] 1.2a Projected beside the objects, by schema, each with its path.
- [ ] 1.2b Which schemas ARE parties is handed in rather than looked up. The
      party model's own answer to that belongs to `party-model`, and guessing
      it here would be a second definition of what a party is.
- [x] 1.3 Applied AND reported, with the type and the node it was cut at.
      🔴 And the prune RECOMPUTES REACHABILITY: a node reachable only through
      the cut disappears with it, while one reached another way stays. Dropping
      the edges and keeping the nodes would be a prune that reports a cut and
      changes no result.
- [x] 1.4a Truncation is PASSED THROUGH from the walk, never recomputed: the
      walk is the only thing that knows it stopped early, and a complete-looking
      answer to an incomplete question is the failure here.
- [ ] 1.4b "Evaluated for the caller" is inherited rather than added: the walk
      loads objects through the object stack, so a node the caller may not read
      arrives unresolved. Asserting that end to end wants the live-DB suite.

## 2. Party relationships

> 🔑 **NOT STARTED, and named rather than half-built.** A party relationship is
> a RECORD, not two fields (D-3) — a schema, its validation, and a read path
> that returns the label for the reading direction. That is a change of its own
> size, and it depends on `party-roles-beyond-the-requester` for what a party
> IS. Building the schema without the validation, or the validation without the
> read path, would leave a half-stated fact in the register, which is worse
> than the convention in prose it replaces.


- [x] 2.1 A `partyRelationship` schema: two party references, a type, a period, a provenance. NOT BUILT HERE — pipelinq's `relationship` schema carries all four, provenance added in pipelinq#1981.
  - 🔴 DO NOT BUILD IT HERE. PIPELINQ ALREADY SHIPS ONE. Measured 2026-09-18 on
    the development instance: `pipelinq/lib/Settings/pipelinq_register.json`
    carries `components.schemas.relationship`, titled "Relationship", with
    `fromContact`, `toContact`, `fromType`, `toType`, `type`, `inverseType`,
    `category`, `notes`, `startDate`, `endDate`, `strength`.
  - That is 2.1 almost exactly: two party references, a type, and a period.
    Building a second `partyRelationship` schema in openregister would be a
    SECOND DEFINITION OF ONE CONCEPT, at the data-model level, which is the
    costliest place to have two: two schemas mean two sets of stored rows, and
    nothing reconciles them afterwards.
  - Provenance was the one part pipelinq's schema did not carry. ADDED THERE,
    pipelinq#1981, as an enum (`declared`, `imported`, `derived`,
    `authoritative-source`) defaulting to the WEAKEST value, because every
    relationship written before it has none and reading that silence as anything
    stronger would credit old rows with an authority nobody gave them. It is
    `visible`, and that is asserted: `notes` and `startDate` on the same schema
    are `visible: false`, so a provenance added the same way would be stored,
    facetable, validated and never once shown to the person deciding whether to
    trust the relationship.
  - ✅ SO 2.1 AND 2.2 ARE SATISFIED BY PIPELINQ'S SCHEMA AND ARE CLOSED HERE.
    Nothing further is owed in openregister for either. If a later reader is
    tempted to add `partyRelationship`, the answer is in pipelinq's
    `components.schemas.relationship`, and the reason not to is that two schemas
    for one concept mean two sets of stored rows with nothing reconciling
    them.
- [x] 2.2 A relationship type declares the party kind at each end, its label and its reciprocal label. NOT BUILT HERE — `fromType`/`toType` and `type`/`inverseType` on pipelinq's schema.
  - ALSO ALREADY THERE, in the same schema: `fromType` and `toType` are the
    party kind at each end, and `type` with `inverseType` are the label and its
    reciprocal. `category` groups them.
- [ ] 2.3 Validation refuses a relationship whose ends do not match the declared kinds, and a self-relationship.
  - GENUINELY UNBUILT, AND IT BELONGS TO PIPELINQ, which owns the schema.
    Measured: nothing under `pipelinq/lib/` reads `fromContact` or
    `inverseType`; the only `relationship` hits are social connections and
    settings, which are a different concept. Openregister validating a schema
    another app defines would put the rule and the data in separate repositories
    and let them drift.
- [ ] 2.4 A party read returns its relationships with the label for the reading direction.
  - SAME OWNER, same reason. The reading direction is decided by which end the
    reader came from, which is knowledge about parties, and parties are
    pipelinq's.

## 3. What a link exposes

- [x] 3.1a `LinkExposure::refusalFor()` refuses an exposed property the far
      schema does not declare — a typo would otherwise be silently absent from
      every projection while its author read a 200.
- [ ] 3.1b Calling it from the schema save path, beside
      `RelationAnnotationValidator`, which needs the far schema resolved at
      validation time.
- [x] 3.2a The rule: the visible set is the INTERSECTION of what the link
      declares and what the reader's own property rules allow, so a link can
      carry a reader to a record they could not otherwise open and can never
      show them a field their own rules withhold.
- [ ] 3.2b Wiring it into the read path beside `PropertyRbacHandler`, which is
      where the readable set comes from. The rule takes that set as an
      argument precisely so there is no second permission evaluator.
- [x] 3.3 `LinkExposure::WITHHELD`. Empty reads as "there is no besluit" and
      withheld reads as "you may not see it", and the two send a reader to
      different places.

## 4. Tests

- [x] 4.1a 15 tests over the type filter, the prune report, the prune's
      reachability, the truncation passthrough, the intersection, the
      withheld marker, the undeclared-versus-empty exposure and the save-time
      refusal. Two mutation checks.
- [ ] 4.1b The kind validation belongs to section 2.
- [ ] 4.2 An e2e over a cross-domain link showing two fields and withholding the rest.
- [x] 4.3 Recorded in the PR body: one traversal, one permission evaluator,
      one label vocabulary. The affected set filters the existing walk's answer
      and the exposure takes the readable set as an argument.
