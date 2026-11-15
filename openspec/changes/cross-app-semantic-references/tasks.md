# Tasks: Cross-app object references by canonical semantic type

## 1. Schema-side semantic type (OpenRegister)

- [x] 1.1 Support `configuration.implements: string[]` on `Schema` — default to
  `[jsonld.type]` when absent; validate each entry is an absolute IRI via
  `JsonLdContextService::isAbsoluteIri()`. Reject non-IRI values on write. (code exists, test missing: lib/Service/SemanticTypeResolver.php (implements derived from jsonld.type; write-time IRI rejection not confirmed, tests show non-IRI entries are dropped))
  > second pass: the requirement "A schema SHALL be resolvable by the canonical semantic type(s) it implements" and its scenario "Non-IRI implements value dropped" ask for a non-IRI entry to be DROPPED, not refused; the spec governs, and `SemanticTypeResolver` does that (`implements` when present, else `jsonld.type` / `x-schema-org`, entries kept only when `JsonLdContextService::isAbsoluteIri()` holds after CURIE expansion). No write-time refusal is added.
- [x] 1.2 Unit-test the default (`implements` derives from `jsonld.type`),
  multi-value advertisement, and IRI validation rejection.
  > second pass: covered by `tests/Unit/Service/SemanticTypeResolverTest.php`: `testImplementedTypesDefaultFromJsonLdType`, `testMultiValueImplements`, `testImplementsDropsNonIri` (the spec's drop, see 1.1), plus the CURIE forms.

## 2. Property keyword `referenceSemanticType`

- [x] 2.1 Add a `PropertySemanticReferenceValidator` (mirror
  `PropertyReferenceTypeValidator`): a property may declare
  `referenceSemanticType` (absolute IRI) and optional `referenceSemanticApp`
  (string hint). Validate on schema write. (code exists, test missing: lib/Service/Integration/PropertySemanticReferenceValidator.php)
  > second pass: the validator was registered but no save path called it. `SemanticReferenceDeclaration` now runs in `PropertyValidatorHandler::assertDeclarations()`, so every schema save refuses a non-IRI `referenceSemanticType` with a 422 naming the property (`SemanticReferenceDeclarationTest`).
- [x] 2.2 Unit-test valid/invalid `referenceSemanticType`.

## 3. Resolver (OpenRegister)

- [x] 3.1 Add `SemanticTypeResolver::resolveSchemaByImplements(string $uri, ?int $consumingRegisterId = null): ?Schema`
  over `SchemaMapper::findAll()`; request-scoped cache; **return null** (never
  throw) when no installed schema matches. (verified: lib/Service/SemanticTypeResolver.php, tests/Unit/Service/SemanticTypeResolverTest.php)
- [x] 3.2 Deterministic tie-break: same register → `referenceSemanticApp` hint →
  first by slug; `WARN` log naming the pick when >1 candidate. (verified: lib/Service/SemanticTypeResolver.php::tieBreak, tests/Unit/Service/SemanticTypeResolverTest.php::testTieBreakPrefersConsumingRegister)
- [x] 3.3 Unit-test: 0 providers → null; 1 provider → it; N providers → tie-break
  order; org/RBAC scoping honoured.

## 4. Discovery surface (OpenRegister)

- [ ] 4.1 Extend the integrations OCS capability payload with `semanticProviders:
  [{ uri, register, schema, appId, available, reason }]` computed from
  resolvable schemas + provider availability.
  > second pass, not built: `IntegrationsCapability::getCapabilities()` runs whenever Nextcloud builds the capabilities (page loads included), and the provider list needs every schema (`SemanticTypeResolver` enumerates `SchemaMapper::findAll()` and walks `allOf`). It needs a cached list invalidated on schema and app changes before it can go there; `GET /api/schemas/resolve-by-implements` (4.2) already serves the on-demand lookup.
- [x] 4.2 Add `@NoAdminRequired` `SchemasController::resolveByImplements(uri)`
  returning the resolved `{register, schema}` or `null`. (verified: lib/Controller/SchemasController.php, appinfo/routes.php, tests/Unit/Controller/SchemasControllerTest.php)
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
- [x] 6.3 Document the pattern in OR schema-config docs; cross-link ADR-048.
  > `docs/Features/json-ld.md`, "Referencing a type another app provides".
