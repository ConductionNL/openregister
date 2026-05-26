## ADDED Requirements

### Requirement: Mapping Transformation Engine Semantics

The system MUST transform an input array into an output array according to a `Mapping`
entity's declarative rules via `MappingService::executeMapping(Mapping $mapping, array
$input, bool $list = false): array`, independent of any caller (webhook delivery, sync,
enrichment). The engine MUST support the following semantics:

- **Dot-notation source lookup**: each mapping rule's value is first looked up against the
  input using dot-notation (`adbario/php-dot-notation`); when the input contains that path,
  the resolved value is copied to the rule's key.
- **Twig rendering**: when the rule value is not a present input path, it MUST be rendered
  as a Twig template against the original input, with HTML entities decoded; a render
  failure MUST throw an `Exception` naming the mapping, key, and value.
- **Pass-through**: when the mapping's `passThrough` flag is true, the full input MUST seed
  the output before rules are applied; otherwise the output starts empty.
- **Key encoding**: input keys MUST have `.` encoded to a safe token before dot-notation
  processing so literal dots in keys are not misread as path separators.
- **List mode**: when `$list` is true, the engine MUST apply the mapping to each element of
  the input, supporting a `listInput` envelope that carries extra shared values merged into
  every element.

#### Scenario: Source path is copied via dot-notation
- **GIVEN** a mapping rule whose value matches a dot-notation path present in the input
- **WHEN** `executeMapping()` runs
- **THEN** the input value at that path MUST be copied to the rule's output key

#### Scenario: Missing source path is rendered as Twig
- **GIVEN** a mapping rule whose value is not a present input path
- **WHEN** `executeMapping()` runs
- **THEN** the value MUST be rendered as a Twig template against the original input
- **AND** a Twig render failure MUST throw an exception naming the mapping, key, and value

#### Scenario: Pass-through seeds the output
- **GIVEN** a mapping with `passThrough` true
- **WHEN** `executeMapping()` runs
- **THEN** the full input MUST be present in the output before the rules overwrite mapped keys

#### Scenario: List mode maps each element
- **GIVEN** `$list` is true and the input is a collection
- **WHEN** `executeMapping()` runs
- **THEN** the mapping MUST be applied to each element and the results returned keyed as the input
