# Tasks: Cross-app object references by canonical semantic type

## 1. Schema-side semantic type (OpenRegister)

- [ ] 1.1 Support `configuration.implements: string[]` on `Schema` — default to
  `[jsonld.type]` when absent; validate each entry is an absolute IRI via
  `JsonLdContextService::isAbsoluteIri()`. Reject non-IRI values on write.
- [ ] 1.2 Unit-test the default (`implements` derives from `jsonld.type`),
  multi-value advertisement, and IRI validation rejection.

## 2. Property keyword `referenceSemanticType`

- [ ] 2.1 Add a `PropertySemanticReferenceValidator` (mirror
  `PropertyReferenceTypeValidator`): a property may declare
  `referenceSemanticType` (absolute IRI) and optional `referenceSemanticApp`
  (string hint). Validate on schema write.
- [ ] 2.2 Unit-test valid/invalid `referenceSemanticType`.

## 3. Resolver (OpenRegister)

- [ ] 3.1 Add `SemanticTypeResolver::resolveSchemaByImplements(string $uri, ?int $consumingRegisterId = null): ?Schema`
  over `SchemaMapper::findAll()`; request-scoped cache; **return null** (never
  throw) when no installed schema matches.
- [ ] 3.2 Deterministic tie-break: same register → `referenceSemanticApp` hint →
  first by slug; `WARN` log naming the pick when >1 candidate.
- [ ] 3.3 Unit-test: 0 providers → null; 1 provider → it; N providers → tie-break
  order; org/RBAC scoping honoured.

## 4. Discovery surface (OpenRegister)

- [ ] 4.1 Extend the integrations OCS capability payload with `semanticProviders:
  [{ uri, register, schema, appId, available, reason }]` computed from
  resolvable schemas + provider availability.
- [ ] 4.2 Add `@NoAdminRequired` `SchemasController::resolveByImplements(uri)`
  returning the resolved `{register, schema}` or `null`.
- [ ] 4.3 API test both surfaces (present provider, absent provider → null,
  RBAC-filtered).

## 5. Form degradation (nextcloud-vue)

- [ ] 5.1 Surface `referenceSemanticType` + `referenceSemanticApp` onto the field
  descriptor in `fieldsFromSchema` (next to `referenceType`).
- [ ] 5.2 Add the `CnFormDialog` branch: resolved → `$ref`-style searchable
  object dropdown bound to the provider `{register, schema}`, stores UUID;
  unresolved → **disabled** field + tooltip from the availability reason-codes.
- [ ] 5.3 Component tests: resolved renders a working picker; unresolved renders
  disabled + tooltip; absent keyword renders the normal field (backward compat).

## 6. First adopter + docs

- [ ] 6.1 shillinq `Payee`: set `configuration.jsonld.type =
  https://schema.org/Organization` (confirm/adjust).
- [ ] 6.2 pipelinq `product`: add `buyCost` (number) and `vendor`
  (`referenceSemanticType: https://schema.org/Organization`,
  `referenceSemanticApp: shillinq`). Verify: with shillinq → vendor picker
  lists Payees; without shillinq → vendor disabled + tooltip, product still
  editable.
- [ ] 6.3 Document the pattern in OR schema-config docs; cross-link ADR-048.
