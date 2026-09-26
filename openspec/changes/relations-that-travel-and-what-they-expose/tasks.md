# Tasks: relations-that-travel-and-what-they-expose

> 🔑 **RE-MEASURED 2026-09-18 against the code rather than the task text.**
> Eight tasks stood open. THE FINDING is not in any of them individually: it is
> that `LinkExposure` had carried its rule, its constants and its own test
> suite since the change opened, and had NO CALLER. That is the worst shape a
> control can take. Every test of it passed, every schema declaring `exposes`
> was accepted, and every field it was written to withhold travelled anyway,
> because nothing ever asked. The two tasks that would have noticed, 3.1b and
> 3.2b, read as wiring chores.
>
> **Closed here: 3.1b, 3.2b, 4.2.** The save path refuses an exposed property
> the far schema does not declare, the read path narrows a far record reached
> through a link, and an e2e reads both over HTTP as a non-admin.
>
> **Still open, and owned elsewhere rather than waiting on nothing:**
> 2.3 and 2.4 belong to pipelinq, which owns the `relationship` schema (2.1 and
> 2.2 were closed against it in #3959); validating a schema another app defines
> here would put the rule and the data in separate repositories. 1.2b waits on
> `party-model` to say which schemas ARE parties, because guessing it here
> would be a second definition of what a party is. 1.4b and 4.1b want the
> live-DB suite and section 2 respectively.

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
- [x] 3.1b Calling it from the schema save path, beside
      `RelationAnnotationValidator`, which needs the far schema resolved at
      validation time. DONE: `SchemaMapper::exposureRefusals()`, in the same
      choke point every create, update and file-upload passes through. The far
      schema is resolved from the property's `$ref` through `find()`.
      🔑 AN UNRESOLVABLE FAR SCHEMA IS NOT A REFUSAL, and that is a decision
      rather than an oversight: schemas arrive in whatever order a
      configuration import walks them, so the schema a `$ref` names may
      genuinely not exist yet when this one is saved, and refusing there would
      fail a valid import on ordering alone. The check is what it can honestly
      be: a refusal when the far schema IS resolvable and does not declare the
      property.
- [x] 3.2a The rule: the visible set is the INTERSECTION of what the link
      declares and what the reader's own property rules allow, so a link can
      carry a reader to a record they could not otherwise open and can never
      show them a field their own rules withhold.
- [x] 3.2b Wiring it into the read path beside `PropertyRbacHandler`, which is
      where the readable set comes from. The rule takes that set as an
      argument precisely so there is no second permission evaluator.
      DONE, in `RenderObject`'s extend path, which is where a far record is
      reached THROUGH a link. The readable set is the far record as
      `renderEntity()` answered it: that call has already run the far schema's
      own property rules through `PropertyRbacHandler`, so the intersection
      takes its answer as an argument and evaluates no permission of its own.
      🔴 `@self` and `id` are the render envelope and are never withheld. They
      say WHICH record the link points at, and a link that withheld the
      identity of the record it exists to name would be unusable.
      The exposure declaration rides the descriptor
      (`RelationTypeResolver::describe()`) rather than being parsed again in
      the render path, so `x-openregister-relation-types` keeps one reader.
      The already-extended branch projects too, or a caller could step around
      the control by asking for the wildcard form of the same extend.
- [x] 3.3 `LinkExposure::WITHHELD`. Empty reads as "there is no besluit" and
      withheld reads as "you may not see it", and the two send a reader to
      different places.

## 4. Tests

- [x] 4.1a 15 tests over the type filter, the prune report, the prune's
      reachability, the truncation passthrough, the intersection, the
      withheld marker, the undeclared-versus-empty exposure and the save-time
      refusal. Two mutation checks.
- [ ] 4.1b The kind validation belongs to section 2.
- [x] 4.2 An e2e over a cross-domain link showing two fields and withholding the rest.
      `tests/e2e/ci/link-exposure.spec.ts`, tagged to the three scenarios it
      covers, probing as an ordinary authenticated user rather than as an
      administrator. Two of those scenarios carried `@e2e exclude {covered by
      unit tests}`; the exclusions are gone, because unit tests could not have
      told anyone whether the rule was ever CALLED, and until this change it
      was not.
      14 wiring tests in `tests/Unit/Service/Relation/LinkExposureWiringTest.php`
      beside it, with two mutation checks: withholding the envelope reddens the
      identity assertion, and carrying an empty `exposes` for an undeclared one
      reddens the undeclared-versus-empty pair.
- [x] 4.3 Recorded in the PR body: one traversal, one permission evaluator,
      one label vocabulary. The affected set filters the existing walk's answer
      and the exposure takes the readable set as an argument.
