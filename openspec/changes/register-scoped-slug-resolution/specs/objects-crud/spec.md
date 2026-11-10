## MODIFIED Requirements

### Requirement: `searchObjectsBySlug` MUST resolve the schema slug within the named register only

`ObjectService::searchObjectsBySlug()` takes a register slug and a schema slug. It
previously resolved the schema slug register-scoped first and fell back to a
multi-tenancy-scoped global `SchemaMapper::find()` when the register did not carry
the slug. That fallback MUST be removed.

The organisation filter the fallback applied is not a sufficient guard: on the
measured instance nine schemas with slug `anonymizationLink` are owned by the same
application, so a same-organisation fallback still selects the wrong schema. The
register is the only context that disambiguates them.

When the named register carries no schema with the requested slug, the method MUST
throw `SchemaNotInRegisterException` naming both slugs. The register-slug
resolution itself is unchanged.

#### Scenario: Searching a slug the register does not carry is refused

- **GIVEN** register slug `document` resolves to register `6` carrying schemas `[9173, 9174, 9177]`
- **AND** schema slug `generatedDocument` exists on the instance but not in register `6`
- **WHEN** `searchObjectsBySlug('document', 'generatedDocument')` is called
- **THEN** the method MUST throw `SchemaNotInRegisterException`
- **AND** the message MUST name register `document` and schema `generatedDocument`
- **AND** the method MUST NOT return results from a schema outside register `6`

#### Scenario: Searching a slug the register carries returns that register's objects

- **GIVEN** register `6` carries schema `9177` with slug `anonymizationLink`
- **AND** `oc_openregister_table_6_9177` holds 4 rows
- **WHEN** `searchObjectsBySlug('document', 'anonymizationLink')` is called
- **THEN** the method MUST search schema `9177`
- **AND** MUST return the 4 rows, rather than the empty set that schema `5084` would yield

#### Scenario: An unknown register slug still throws its own error

- **GIVEN** no register carries slug `nonexistent`
- **WHEN** `searchObjectsBySlug('nonexistent', 'anything')` is called
- **THEN** the method MUST throw the existing register-not-found `DoesNotExistException`
- **AND** MUST NOT throw `SchemaNotInRegisterException`, because the failure is register resolution, not schema scoping
