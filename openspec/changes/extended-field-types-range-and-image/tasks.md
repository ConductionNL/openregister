# Tasks: extended-field-types-range-and-image

## 1. calendar-range

- [x] 1.1 Add `calendar-range` to `PropertyValidatorHandler::TYPES` (category `temporal`, conversion `unsupported`). Verify: unit test that a schema declaring it imports, and one declaring an unknown type is refused naming the property.
- [x] 1.2 Check the value in `ExtendedFieldCheck`: an object with `start` and `end`, both present, `end` not before `start`, and the time component following the property's `format`. Verify: unit tests for each refusal message in the spec.
- [x] 1.3 Pass the object through `SchemaTypeConverter::convertValue()` unchanged. Verify: round-trip unit test.
- [ ] 1.4 Make `period.start` and `period.end` filterable with the existing range operators. Verify: API test with rows inside and outside the window.

## 2. image-url

- [x] 2.1 Add `image-url` to `PropertyValidatorHandler::TYPES` (category `presentation`, conversion `conditional`). Verify: unit test.
- [x] 2.2 Check the value in `ExtendedFieldCheck`: a valid URL, optional `width` and `height` as positive integers, `format` one of jpeg, png, webp, avif or svg. Store a bare URL as `{ url }`. Verify: unit tests for the normalisation and each refusal.

## 3. OpenAPI and docs

- [x] 3.1 Emit `CalendarRange` and `ImageUrl` once as named components in the OpenAPI document, and reference them from every property of that type. Verify: unit test on the generator with two schemas sharing a type.
- [x] 3.2 Add both types to the field types section in `docs/`.
