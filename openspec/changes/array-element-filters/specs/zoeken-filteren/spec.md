# zoeken-filteren Delta: array-element-filters

## ADDED Requirements

### Requirement: A filter can match one element of an array property

A list query MAY carry `_element[<property>][<field>]=<value>` for an array-of-objects property. The object SHALL match
when one element of the array carries every field of the block; numbered blocks (`_element[<property>][<n>][…]`) SHALL
each be matched by an element of their own. A field SHALL take a value, a list or an operator bag as a nested-path
filter does. A block on a property that is not an array, or that the caller may not read, SHALL match nothing; an
encrypted property SHALL refuse; a query across several schemas SHALL refuse `_element`.

#### Scenario: A case-type field on the case list

- **GIVEN** a case carries `properties: [{ "propertyDefinition": "pd-7", "name": "Oppervlakte", "value": "120" }]`
- **WHEN** the list asks `_element[properties][propertyDefinition]=pd-7&_element[properties][value][gte]=100`
- **THEN** the case is listed, and a case whose pd-7 answer is 80 is not

#### Scenario: Both fields on the same element

- **GIVEN** a case answers pd-7 with "80" and pd-8 with "120"
- **WHEN** the list asks `_element[properties][propertyDefinition]=pd-7&_element[properties][value][gte]=100`
- **THEN** the case is not listed
