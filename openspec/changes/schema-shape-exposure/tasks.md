# Tasks: schema-shape-exposure

## 1. The decision, enforced

- [x] 1.1 `OasService` describes only the properties the caller may read.
  - THE CONTRACT OBJECTION IS WHAT SETTLED IT, AND IT SETTLED THE OTHER WAY.
    The API never returns a property this caller may not read, so describing it
    promises a field that will never arrive. Leaving it out makes the document
    MORE truthful: it describes the API this caller actually has.
  - Its `example` and `enum` leave with it. An example is a sample answer and an
    enum is the set of permitted answers; both are values outright, and "we hid
    the property, the example was elsewhere" is the sort of gap that ships.
  - The core API properties (`id`, `_self`) survive whatever the rule says: they
    are not schema properties and are not governed by one.
- [x] 1.2 `required` drops names the document can no longer mention.
  - A required list naming an absent property is not a contract anyone can
    satisfy: a generated client would fail validation on a field it cannot even
    see, and the list would name the property just withheld.
- [x] 1.3 The GraphQL type mapper does the same.
  - BOTH of its property loops, the filter type and the input type. Guarding one
    would leave the names introspectable through the other.
- [x] 1.4 The omission is disclosed as a count, never as names.
  - `x-openregister-withheld-properties: <n>`. Naming them would be the leak
    with an audit trail attached. Saying nothing would be worse in its own way:
    an integrator reading four properties cannot tell whether that is the whole
    schema or the part they are allowed to see, and would build as though it
    were complete. Mutation-checked by turning the count into a list.

## 2. The three principals

- [x] 2.1 Anonymous, signed-in colleague and administrator get different
      documents, and the difference is asserted for each.
  - The three principals are answered by ONE evaluator rather than by three
    branches here: `PropertyRbacHandler::canReadProperty()` already admits an
    administrator, matches a signed-in caller's groups, and admits an anonymous
    caller only where the rule does. Writing the three cases out here would be a
    second answer to a question it already answers.
  - THE ADMINISTRATOR ROW IS DELIBERATE. Making the generated description the
    one place an administrator cannot see the schema would be that second
    answer, and the two would drift.

## 3. Keeping it true

- [x] 3.1 A derived test that no shape-describing path prints a governed
      property.
  - Both describers are now inside the derived sweep from
    `aggregate-paths-ask-permission` rather than allowlisted out of it, so the
    same test that polices facets and aggregations now polices them. Their
    allowlist entries were REMOVED rather than reworded: an entry that excuses a
    path which now asks is a stale exception waiting to excuse a regression.
- [ ] 3.2 An e2e over the three principals.
  - NOT WRITTEN. It needs three real sessions against a live instance, and there
    is no Playwright runner on this build host, so it would be written and never
    run. Named rather than half-done.
