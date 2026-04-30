# Tasks: Computed Fields

> **Status:** `lib/Service/Object/SaveObject/ComputedFieldHandler.php` is in production with full Twig sandbox + reference resolution. `tests/Service/ComputedFieldsIntegrationTest` (6 tests) verifies the evaluator end-to-end. **Production bug found and fixed**: Twig autoescape was on but the sandbox didn't allow the `escape` filter, so every `{{ var }}` expression silently failed with `Filter "escape" is not allowed`. Fixed by setting `autoescape: false` on the sandboxed environment.
>
> 11 of 18 tasks tickably complete; 7 partial / open with notes.

## Implemented

- [x] **Schema Property Computed Attribute Definition.** `property.computed.expression` (Twig string) + `property.computed.evaluateOn` (`save` or `read`). Detected by `ComputedFieldHandler::hasComputedProperties` / `getComputedPropertyNames`. **Verified live** by `testHasComputedPropertiesDetectsComputedAttribute` and `testGetComputedPropertyNamesReturnsForCorrectMode`.
- [x] **Save-Time Evaluation.** `evaluateComputedFields(data, schema, 'save')` materialises every property with `evaluateOn: save`. **Verified live** by `testEvaluateComputedFieldsRendersTwigExpression` and `testEvaluateComputedFieldsRespectsEvaluateOnSave`.
- [x] **Read-Time Evaluation.** Same handler with `'read'` mode; `evaluateOn: read` properties fire on read instead of save. **Verified live** by `testEvaluateComputedFieldsRespectsEvaluateOnSave`.
- [x] **Cross-Field References Within the Same Object.** Twig context is `data` itself; `{{ otherField }}` resolves against the same object.
- [x] **Cross-Object Reference Lookups.** `buildTwigContext` resolves `$ref`-typed properties up to `MAX_REF_DEPTH`, exposed under `_ref.<propertyName>`.
- [x] **String, Date, and Math Operations.** Sandbox allows date/string/numeric/array filters + ZGW custom filters. **Verified live** by the arithmetic-expression test.
- [x] **Error Handling for Invalid Expressions.** Twig errors caught + logged; property set to `null` rather than failing the save. **Verified live** by `testInvalidExpressionFailsClosedToNull`.
- [x] **Custom Twig Function Registration.** `MappingExtension` + `MappingRuntimeLoader` are added to the Twig environment.
- [x] **Computed Fields as Read-Only in the API.** Computed properties are evaluated AFTER user-supplied data is set; expression always wins.
- [x] **Migration When Formula Changes.** Save-time evaluation re-materialises naturally when objects are resaved.
- [x] **Interaction with Schema Hooks.** Computed evaluation happens inside the standard save pipeline; hooks see post-evaluation data.

## Open / partial

- [ ] **On-Demand Evaluation Mode.** Partial — `evaluateOn: read` covers on-render; an explicit "evaluate now without persisting" endpoint isn't shipped. **Open**.
- [ ] **Aggregation Functions Across Related Objects.** Partial — single-uuid `$ref` lookups only; collection aggregation isn't pre-resolved. **Open**.
- [ ] **Circular Dependency Detection.** Partial — `MAX_REF_DEPTH` guards reference recursion; same-schema cycles between computed fields aren't detected. **Open**.
- [ ] **Performance and Caching.** Partial — Twig templates are cached within a request; cross-request opcode cache isn't wired. **Open**.
- [ ] **Computed Fields in the UI.** Partial — backend metadata exposed; frontend "computed (read-only)" badge isn't shipped. **Open** (frontend).
- [ ] **Audit Trail for Computed Values.** Partial — result captured; expression+input context isn't recorded. **Open**.
- [ ] **Import and Export Behavior.** Partial — values exported; whether to include the `computed` attribute on import/export is undecided. **Open**.

## Test coverage

- [x] `tests/Service/ComputedFieldsIntegrationTest` — 6 integration tests covering detection, save-mode rendering, save/read mode separation, pass-through baseline, mode-aware listing, parse-failure fail-closed.
