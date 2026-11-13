---
kind: code
---

# Proposal: extended-field-types-range-and-image

## Summary

Two field types of their own for a schema property: `calendar-range`, a start and an end kept together as one value, and `image-url`, an image address with its width, height, alt text and file format. A schema author picks them like any other type, and the save path refuses a range that ends before it starts or an image address that is not a URL.

Ruben decided on 12 Nov 2026 (decision 402) to give this idea a capability row and a spec, not built yet. It comes from the archived `add-openregister-discovered-capabilities` change, which listed it as not covered elsewhere.

## Why

The archived dump proposed six extended types. Two of them are built: `color` and `recurrence` sit in `PropertyValidatorHandler::TYPES` and are checked by `ExtendedFieldCheck`. The other ideas never landed:

- **A period is two loose dates today.** A booking, a validity window or a term is modelled as `startDate` and `endDate`. Nothing ties them together, so nothing refuses an end before the start, and every app writes that check again.
- **An image is a bare string.** A cover image is a `string` with `format: uri`. Its alt text lives in a second property, if anywhere, so a portal that renders the image has no alt text to give a screen reader.

## What changes

- A `calendar-range` type: an object `{ start, end }`, both required, `end` not before `start`, with `format: date` or `date-time` (default) on the property. Range filters on `start` and `end` work through the existing query parameters.
- An `image-url` type: a URL, or an object `{ url, width?, height?, altText?, format? }`. A bare URL is stored as `{ url }`, so a read always returns the object form.
- Both types are listed in `PropertyValidatorHandler::TYPES`, so the schema editor offers them, and both are rendered in the OpenAPI document as one named component each.

## Out of scope

- `gallery-cover-url`. It differs from `image-url` only in how a consumer may render it, so a portal can say that with its own config.
- A typed `uuid` scalar. `{ "type": "string", "format": "uuid" }` already covers it.
- New GraphQL scalars. GraphQL keeps passing the object form through as JSON until the graphql-api spec asks for typed scalars.

## Rows this closes

| matrix | row | capability | own rating |
|---|---|---|---|
| openregister | mod-field-range-image | Store a date range, or an image with its size and alt text, as one field of its own | no |

## Risks

- A schema that changes a property from two dates to one `calendar-range` needs its existing records migrated. The type is marked `conversion: unsupported`, so the schema editor refuses the change on a schema with records, the same way it refuses a change to `recurrence`.
