## ADDED Requirements

### Requirement: A calendar-range property holds a start and an end as one value

The system SHALL accept `calendar-range` as a property type. Its value SHALL be an object `{ "start": <date or date-time>, "end": <date or date-time> }`. Both members SHALL be present, and `end` SHALL NOT be before `start`. An optional `format` on the property, `date` or `date-time` (the default), SHALL decide whether the members carry a time. The value SHALL be stored as that object and returned unchanged. The existing range query operators SHALL work on `<property>.start` and `<property>.end`. A save that breaks any of these rules SHALL be refused with HTTP 422, a message naming the property, and nothing stored.

#### Scenario: A valid range is saved and read back unchanged
- **GIVEN** schema `booking` has `period: { "type": "calendar-range" }`
- **WHEN** a booking is saved with `period: { "start": "2026-05-01T09:00:00Z", "end": "2026-05-01T17:00:00Z" }`
- **THEN** the save succeeds
- **AND** a read of the booking returns the same `period` object
- @e2e exclude backend validation with no page of its own; covered by ExtendedFieldCheck unit tests and an API test

#### Scenario: A range that ends before it starts is refused
- **GIVEN** the same schema
- **WHEN** a booking is saved with `period: { "start": "2026-05-01T17:00:00Z", "end": "2026-05-01T09:00:00Z" }`
- **THEN** the save fails with HTTP 422
- **AND** the message is `Property 'period': calendar-range 'end' (2026-05-01T09:00:00Z) must be >= 'start' (2026-05-01T17:00:00Z)`
- @e2e exclude backend validation with no page of its own; covered by ExtendedFieldCheck unit tests

#### Scenario: A date range refuses a time
- **GIVEN** schema `booking` has `period: { "type": "calendar-range", "format": "date" }`
- **WHEN** a booking is saved with `period: { "start": "2026-05-01T09:00:00Z", "end": "2026-05-03" }`
- **THEN** the save fails with HTTP 422
- **AND** the message is `Property 'period' declared format 'date'; 'start' must match YYYY-MM-DD`
- @e2e exclude backend validation with no page of its own; covered by ExtendedFieldCheck unit tests

#### Scenario: A range missing one end is refused
- **GIVEN** schema `booking` has `period: { "type": "calendar-range" }`
- **WHEN** a booking is saved with `period: { "start": "2026-05-01T09:00:00Z" }`
- **THEN** the save fails with HTTP 422 naming `period` and the missing `end`
- @e2e exclude backend validation with no page of its own; covered by ExtendedFieldCheck unit tests

#### Scenario: Records are found by their range
- **GIVEN** bookings whose `period` falls in April, May and June 2026
- **WHEN** a client calls `GET /api/objects/{register}/booking?period.start.gte=2026-05-01&period.end.lte=2026-05-31`
- **THEN** only the May bookings are returned
- @e2e exclude query behaviour with no page of its own; covered by an API test

### Requirement: An image-url property holds an image address with its size and alt text

The system SHALL accept `image-url` as a property type. Its value SHALL be either a URL string or an object `{ "url": <URL>, "width"?: <positive integer>, "height"?: <positive integer>, "altText"?: <string>, "format"?: "jpeg" | "png" | "webp" | "avif" | "svg" }`. A bare URL SHALL be stored as `{ "url": <URL> }`, so every read returns the object form. `url` SHALL pass `filter_var(..., FILTER_VALIDATE_URL)`. A save that breaks any of these rules SHALL be refused with HTTP 422, a message naming the property, and nothing stored.

#### Scenario: A bare URL is stored as the object form
- **GIVEN** schema `article` has `cover: { "type": "image-url" }`
- **WHEN** an article is saved with `cover: "https://example.org/img.png"`
- **THEN** a read of the article returns `cover: { "url": "https://example.org/img.png" }`
- @e2e exclude backend normalisation with no page of its own; covered by ExtendedFieldCheck unit tests

#### Scenario: The full object round-trips
- **GIVEN** the same schema
- **WHEN** an article is saved with `cover: { "url": "https://example.org/img.png", "width": 1200, "height": 630, "altText": "Town hall at dusk", "format": "png" }`
- **THEN** a read returns every member unchanged
- @e2e exclude backend round-trip with no page of its own; covered by an API test

#### Scenario: An address that is not a URL is refused
- **GIVEN** the same schema
- **WHEN** an article is saved with `cover: "not a url"`
- **THEN** the save fails with HTTP 422
- **AND** the message is `Property 'cover': 'not a url' is not a valid URL`
- @e2e exclude backend validation with no page of its own; covered by ExtendedFieldCheck unit tests

#### Scenario: An unknown image format is refused
- **GIVEN** the same schema
- **WHEN** an article is saved with `cover: { "url": "https://example.org/x.bmp", "format": "bmp" }`
- **THEN** the save fails with HTTP 422
- **AND** the message is `Property 'cover': format 'bmp' is not one of jpeg|png|webp|avif|svg`
- @e2e exclude backend validation with no page of its own; covered by ExtendedFieldCheck unit tests

### Requirement: The schema editor and the OpenAPI document know both types

`calendar-range` and `image-url` SHALL be listed in `PropertyValidatorHandler::TYPES`, so the schema editor's type list offers them and a schema declaring them imports. The OpenAPI document SHALL define `CalendarRange` and `ImageUrl` once each under `#/components/schemas`, and every property of either type SHALL reference that component instead of repeating the shape.

#### Scenario: The schema editor offers the new types
- **GIVEN** an administrator edits a property in the schema editor
- **WHEN** they open the type list
- **THEN** `calendar-range` and `image-url` are listed with their descriptions
- @e2e exclude type list is served from PropertyValidatorHandler::TYPES; covered by a unit test on the list until the editor e2e suite covers the picker

#### Scenario: Two properties of one type share a component
- **GIVEN** schemas `booking.period` and `reservation.window` are both `calendar-range`
- **WHEN** the OpenAPI document is rendered
- **THEN** both reference `#/components/schemas/CalendarRange`
- **AND** `CalendarRange` is defined once, requiring `start` and `end`
- @e2e exclude generated document with no page; covered by a unit test on the OpenAPI generator
