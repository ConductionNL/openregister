## ADDED Requirements

### Requirement: An example set's writes are caused as an import of that set

Every object write an example set install or refresh makes SHALL be recorded with the write cause `import` and the run `example-set:<set>`. An audit row the set declares itself SHALL keep the cause and run it declares. When the run ends, the cause SHALL be what it was before.

#### Scenario: The daily refresh moves a case type's relative dates
- GIVEN the Zuiddrecht set declares a case type with `validFrom` `{{date:-12}}`
- WHEN the daily refresh rewrites it the next day
- THEN the audit row of that update has cause `import` and run `example-set:zuiddrecht`
- AND the seeded row "Version 4 published" by Lars de Koning keeps its own run
