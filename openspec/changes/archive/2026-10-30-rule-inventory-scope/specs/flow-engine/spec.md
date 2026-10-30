# flow-engine

## ADDED Requirements

### Requirement: The rule inventory can be asked for the rules of one scope

A rule declaration MAY carry `scope: { property, value }`. The inventory SHALL return each descriptor's scope, and
SHALL accept `scope[<property>]=<value>`, returning the rules with exactly that scope and the rules without a scope.

#### Scenario: Two kinds of object in one schema

- **GIVEN** a schema with a calculation without a scope, a state field block scoped to `caseType` `a` and a flow whose
  object trigger node is scoped to `caseType` `b`
- **WHEN** the inventory is asked with `scope[caseType]=a`
- **THEN** it lists the calculation and the state field block, and not the flow
- @e2e exclude {API filter, covered by unit tests}

#### Scenario: No filter

- **GIVEN** the same schema
- **WHEN** the inventory is asked without a scope
- **THEN** it lists all three rules, each with its scope or `null`
- @e2e exclude {API filter, covered by unit tests}
