# Tasks: fields-a-user-adds-and-choices-a-record-narrows

## 1. A property with a scope

- [x] 1.1 A `scope` attribute in the published property vocabulary, naming a unit or a team.
  - 🔴 **DELIBERATELY NOT SHIPPED ON ITS OWN, 2026-09-18.** Publishing `scope`
    without 1.3 would be an inert declaration, and this one is inert in the
    dangerous direction: a schema author writes `scope: team-a`, the key
    validates, the vocabulary publishes it, and the field is readable by
    everybody. They would believe the field is team-scoped precisely because
    the platform accepted the word.
  - That is the same defect as a widget declaring roles nothing reads
    (dossiq#2947) and as a reporter with no caller (openregister#3896). The
    difference is that those were visible as "nothing happened"; this one looks
    like it worked.
  - So 1.1 lands WITH 1.3, not before it. The read filter, the write refusal and
    the published key are one change, and section 2's ceiling and promotion sit
    on top of them.
- [x] 1.2 Adding a scoped property is a declared action gated by a group, not by the admin flag.
  - `ScopedPropertyGovernance::assertMayAddAtScope()`, called from BOTH
    `SchemaMapper::insert()` and `::update()`.
  - THE GATE IS THE SCOPE. Gating on admin would mean either every team waits on
    an administrator, which is the friction this feature exists to remove, or
    administrators are handed out until the flag means nothing. The group that
    OWNS the scope may add to it, which is the same answer the read rule gives,
    so nobody can create a field they would not then be allowed to see.
  - An admin is ADMITTED, which is not the same as the flag being the gate. The
    test that proves the difference is the one where a non-admin member passes.
  - ON UPDATE TOO, not only insert: otherwise a scope could be added to an
    existing schema by anybody, and the ceiling walked past one edit at a time.
    Derived from the mapper's own source, mutation-checked.
- [x] 1.3 A scoped property is returned, validated and writable only within its scope.
  - SHIPPED TOGETHER WITH 1.1, as the note above insisted.
  - 🔑 IT IS A SHORTHAND, NOT A SECOND EVALUATOR. `PropertyRbacHandler` already
    strips unreadable properties from every read, refuses writes to them, and
    keeps them out of exports and the OAS, all driven by a property's
    `authorization` block. So `scope: team-a` COMPILES INTO
    `authorization: {read: ['team-a'], update: ['team-a']}` in
    `Schema::getPropertyAuthorization()`, and every enforcement path that
    already exists applies unchanged. Building a second mechanism beside it
    would mean two answers to "may this person see this field", and the two
    disagree within a week; the wider one is the one that discloses.
  - READ IS IN THE COMPILED BLOCK ON PURPOSE. A scope governing only writes
    would leave the value on screen for everybody, which is the inert failure
    with extra steps. Mutation-checked.
  - 🔴 THE COMPILE ALONE WOULD HAVE BEEN INERT, AND NOTHING WOULD HAVE FAILED.
    `Schema::hasPropertyAuthorization()` is a SHORT-CIRCUIT that five call
    sites on the render, query, export and OAS paths use to skip property
    filtering entirely. On a schema whose only control is a scope it answered
    false, so the compiler would have been correct and never called: the field
    published as scoped and returned to everyone. Both that gate and
    `getPropertiesWithAuthorization()` now ask one shared question that a scope
    answers. This is the single most important line of the change and it is not
    the one the task described.
  - Declaring both `scope` and `authorization` is refused rather than merged,
    and so is a scope that cannot name a group: a name no group carries matches
    nobody, so accepting it would publish a scope that denies everybody just as
    quietly.
  - The existing vocabulary prober caught the key before the tests did: it
    asserts every PUBLISHED key is accepted by the save path, probing with null
    where it has no sample. That is the derived-from-source shape working.
- [x] 1.4 A scoped property is searchable, facetable, groupable and exportable like a schema property.
  - LIKE A SCHEMA PROPERTY IS THE EASY HALF, and it was already true: a scoped
    property IS a schema property, so search, grouping and export reach it
    through the ordinary paths and `PropertyRbacHandler` strips it for anyone
    outside the scope.
  - 🔴 FACETING WAS NOT, AND THE LEAK WAS PRE-EXISTING AND QUIET.
    `MagicFacetHandler::expandFacetConfig()` offered EVERY property marked
    `facetable` to EVERY caller who could see the rows, and never consulted
    `PropertyRbacHandler` at all. A facet over a governed column hands back its
    DISTINCT VALUES with counts, so for a property scoped to one team everybody
    else could read the set of answers without ever being allowed to read one.
  - Nothing on screen suggested it. The response looked like an ordinary facet,
    and the property never appeared in any object body because the render path
    strips it correctly. Only the facet did not ask.
  - This is not confined to `scope`: any property carrying an `authorization`
    block was exposed the same way, which predates this change. Reported as
    such, and fixed here because publishing `scope` without fixing it would
    multiply it.
  - Fails closed: a governed property whose read rule cannot be resolved is
    omitted rather than offered, and an ungoverned schema asks nothing at all so
    ordinary facets are untouched. Mutation-checked with a control.

## 2. Keeping the schema honest

- [x] 2.1 An administered ceiling on scoped properties per scope, refusing the one above it.
  - `scoped_properties_per_scope`, default 25. THE REFUSAL NAMES THE CEILING:
    "refused" alone sends the author to an administrator with nothing to say,
    while the number tells them whether to ask for a higher one or retire a
    field, which is the decision the ceiling exists to force.
  - PER SCOPE, not per schema, or one busy team would exhaust every other team's
    allowance. Editing an existing property does not count it twice, or a scope
    at its ceiling could never edit the fields it already has.
  - A configured ceiling of zero is read as one, because zero would refuse every
    scoped property while reading like "no limit".
- [x] 2.2 A report of scoped properties unused for a declared period.
  - `scoped_property_unused_days`, default 90.
  - 🔑 A PROPERTY WITH NO COUNT IS REPORTED `unknown`, NOT `unused`. Absent
    evidence is not evidence of absence, and retiring a field on it would delete
    data somebody relies on. The counts are passed IN rather than fetched here,
    because a class that both decides the rule and gathers the evidence ends up
    with two versions of the rule.
- [x] 2.3 Promotion of a scoped property to the schema as a recorded act, keeping stored values.
  - 🔴 PROMOTION DROPS THE SCOPE AND CHANGES NOTHING ELSE, WHICH IS WHAT KEEPS
    THE VALUES. They live on the objects keyed by the property NAME and are not
    copied. Renaming the property, or rebuilding it from a template, would leave
    forty objects holding a key nothing reads any more, and the loss would be
    SILENT because the objects would still save. Mutation-checked by doing
    exactly that and watching the assertion redden.
  - Promoting something that is not scoped is refused, because it would record
    an act that did not happen. The record names its actor, since "who promoted
    this" is what anyone reading the trail later is asking.

## 3. A reference that narrows

- [x] 3.1 A filter annotation on a reference property whose operands are properties of the record being edited.
  - `x-openregister-reference-filter` and `ReferenceFilterDeclaration`, checked
    at schema save from `PropertyValidatorHandler::validateProperty()` and
    throwing in the `PropertyVocabularyException` family, so every schema-save
    path answers it as a 422 naming the property.
  - The operator set is deliberately three: `eq`, `neq`, `in`. Every one is a
    comparison the objects API already answers, so a filter cannot declare
    something the options read would have to emulate in PHP over an unbounded
    set.
- [x] 3.2 The options read applies the filter, paged and access-scoped.
  - UNBLOCKED AND BUILT. `GET /api/objects/{register}/{schema}/{id}/reference-options?property=<name>`,
    declared BEFORE `objects#show` because `{id}` matches `[^/]+` and the
    generic route would otherwise swallow it. Verified by PARSING
    `appinfo/routes.php` and checking the index ordering, not by grepping for
    the string.
  - IT CALLS THE SAME `resolve()` THE SAVE PATH CALLS. A picker that offers one
    set while the save path accepts another is two evaluators of one rule.
  - 🔴 NO OPTIONS IS NOT EVERY OPTION. An unresolved operand answers an EMPTY
    list, names the property it waits for, with HTTP 200. Returning the
    unfiltered set would show every contact in the register to somebody who had
    not yet chosen an organisation. Mutation-checked.
  - `_draft[...]` merges over the stored record, because the case a picker
    exists for is a form being filled in and those values are not saved yet.
  - PAGED AND CAPPED. `_limit=0` means the DEFAULT, not `LIMIT 0`, which would
    be an empty page with a 200 and no explanation; and a page is capped so a
    picker cannot become a bulk export of the referenced register.
  - ACCESS-SCOPED by running the ordinary object search with `_rbac` on. A
    picker is not a way to see objects you may not see.
  - An unknown property is REFUSED, not answered as empty: "no options" for a
    typo reads exactly like a filter waiting on an operand.
  - The e2e is WRITTEN AND TAGGED, NOT RUN: no Playwright runner on this host.
    It asserts the STATUS of every call, because an earlier spec in this change
    guessed a URL and a 404 would have been skipped by the suite's own
    old-build guard, reporting green while asserting nothing.
- [x] 3.3 A write of a value outside the filter is refused on the server, naming the filter.
  - `SaveObject::assertReferenceMatchesFilter()`, called from
    `validateReferences()` right after the existence check, throwing the
    existing `ReferenceValidationException` so every 422 handler already routes
    it. No new exception family and no controller change.
  - IT CALLS THE SAME `resolve()` the options read will. One reader, one
    resolver; this method only compares. A picker that offers one set and a
    save path that accepts another is two evaluators of one rule.
  - AN UNRESOLVED OPERAND REFUSES rather than waving through. The picker would
    have offered nothing, so no value can be inside the filter. Waving it
    through would make the server accept precisely the writes the form exists
    to prevent.
  - ANYTHING THE COMPARISON DOES NOT RECOGNISE REFUSES: an unknown operator, a
    missing value on the referenced object, an empty `in` list. Accepting is the
    direction that discloses.
  - Costs nothing for a property declaring no filter: the reader returns null
    before any read is made.
- [x] 3.4 An unresolved operand returns no options and names the property it needs.
  - `ReferenceFilterDeclaration::resolve()` answers a filter OR a `needs`, never
    both and never a partial filter. ONE unresolved condition drops the WHOLE
    filter, because half a filter is wider than the filter and wider is the
    direction that discloses: a picker meant to show one organisation's contacts
    would show every organisation's.
  - Every empty shape is treated as unresolved: null, '' and []. "Not chosen
    yet" arrives as null from one client, as an empty string from a form post
    and as an empty array from a multi-select, and a resolver that only knew
    null would open the picker on the other two.
- [x] 3.5 Schema save refuses a filter naming a property the schema or the far schema does not declare.
  - `assertOperandsExist()`, and the message names WHICH of the two schemas is
    missing the property; without that an author checks the wrong one first
    every time. Called where both schemas are in hand, not from
    `validateProperty()`, which sees one property.

## 4. Tests

- [ ] 4.1 Unit tests for the scope on read and write, the ceiling, the promotion and the retained values.
- [ ] 4.2 Unit tests for the filtered options, the refused out-of-filter write and the unresolved operand.
- [ ] 4.3 An e2e over a reference offering only the contacts of the chosen organisation.
- [ ] 4.4 Deduplication check (ADR-012) recorded in the PR body.
