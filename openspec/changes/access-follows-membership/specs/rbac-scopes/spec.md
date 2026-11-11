# rbac-scopes

## ADDED Requirements

### Requirement: A match can admit by the caller's membership records

A `match` property MAY take an operator object `{ "$memberOf": <operand> }`.
The operand SHALL name a membership schema (`schema`, required), optionally
its register (`register`), the membership field whose values form the set
(`property`, required), the membership field that holds the caller's user id
(`match`, default `userId`), optional static filters (`where`) and an
optional validity window (`current: { from, until }`).

OpenRegister SHALL admit an object for that rule only when the object's value
for the property is in the set of `property` values held by the caller's
membership rows that pass `where` and `current`. The set SHALL be resolved by
`ConditionMatcher::resolveDynamicValue()` into `$in`, so the list query, its
count, the single-object verdict, property-level rules and deny rules read
the same set. The membership rows SHALL be read without the membership
schema's own RBAC and multitenancy, and the user match SHALL be re-checked on
every row read.

#### Scenario: a bookkeeper lists only the rows of their administrations

- **GIVEN** a schema `boeking` with `read: [{ "group": "shillinq_bookkeeper", "match": { "administrationId": { "$memberOf": { "schema": "administration-membership", "property": "administrationId" } } } }]`
- **AND** user `anna` in group `shillinq_bookkeeper` has membership rows for `adm-1` and `adm-2`
- **AND** the `boeking` table holds rows of `adm-1`, `adm-2` and `adm-3`
- **WHEN** `anna` lists `boeking` with `_limit` and `_page`
- **THEN** every returned row has `administrationId` `adm-1` or `adm-2`
- **AND** the total count equals the number of `adm-1` and `adm-2` rows
- **AND** the pages together hold exactly those rows
- @e2e exclude {engine primitive; proven against SQLite in MagicRbacMemberOfListTest, task 2.2}

#### Scenario: a single read of another administration's row is refused

- **GIVEN** the same schema and memberships
- **WHEN** `anna` reads a `boeking` row of `adm-3`
- **THEN** the per-object check refuses it, as the list excludes it
- @e2e exclude {engine primitive; covered by ConditionMatcherMemberOfTest, task 2.1}

#### Scenario: the role filter and validity window narrow the set

- **GIVEN** the operand has `where: { "role": { "$in": ["boekhouder"] } }` and `current: { "from": "validFrom", "until": "validUntil" }`
- **AND** `anna` is `inkijker` in `adm-1`, `boekhouder` in `adm-2` until yesterday, and `boekhouder` in `adm-4` with no end date
- **WHEN** the set is resolved for `anna`
- **THEN** it is `["adm-4"]`
- @e2e exclude {engine primitive; covered by MemberOfResolverTest, task 1.1}

#### Scenario: whatever cannot be resolved denies

- **GIVEN** a `$memberOf` operand without `schema`, or naming a schema that does not exist, or a caller who is not signed in
- **WHEN** the rule is evaluated on a list or a single object
- **THEN** the rule admits no object
- **AND** the list query carries an impossible predicate rather than dropping the condition
- @e2e exclude {engine primitive; covered by MemberOfResolverTest and MagicRbacMemberOfListTest}

#### Scenario: the set is read once per request

- **GIVEN** a list of 50 rows evaluated under a `$memberOf` rule
- **WHEN** the list and the per-object checks of the same request run
- **THEN** the membership rows are read once for that caller and operand
- @e2e exclude {engine primitive; covered by MemberOfResolverTest, task 1.1}
