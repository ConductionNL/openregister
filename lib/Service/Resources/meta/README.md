# Vendored meta-schemas

Reference JSON Schema documents used at OAS validation time. Vendored
under git so the validator runs offline (no network round-trip during
`OasService::validateOas()`).

## openapi-3.1.0.json

Canonical OpenAPI 3.1.0 meta-schema, downloaded from:

    https://spec.openapis.org/oas/3.1/schema/2022-10-07

`$id`: `https://spec.openapis.org/oas/3.1/schema/2022-10-07`
`$schema`: `https://json-schema.org/draft/2020-12/schema`

Used by `OasRequestValidator` to assert the generated OAS document
conforms to the OpenAPI 3.1 specification. Used by `ImportHandler` (via
the same primitive) to validate imported OR schemas at the structural
level.

When the upstream meta-schema gains a new revision, refresh by running:

    curl -sL https://spec.openapis.org/oas/3.1/schema/2022-10-07 \
      -o lib/Service/Resources/meta/openapi-3.1.0.json

Closes openregister#1378.
