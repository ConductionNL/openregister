## ADDED Requirements

### Requirement: Webhook transport internals MUST configure delivery and normalize event identifiers

The webhook delivery layer MUST initialize an HTTP client tolerant of webhook endpoints, resolve dot-notation filter keys against the payload, and normalize event identifiers between the dotted event-type form and the fully qualified event class form. These helpers underpin filtering, the standard payload, and request interception.

#### Scenario: HTTP client is configured for webhook delivery

- **GIVEN** the webhook service is constructed
- **WHEN** `initializeHttpClient()` runs
- **THEN** it MUST build a Guzzle client with `timeout: 30`, `connect_timeout: 10`, `verify: false` (self-signed endpoints allowed), `allow_redirects: true`, and `http_errors: false` so 4xx/5xx responses are handled manually rather than thrown

#### Scenario: Dot-notation filter key resolution

- **GIVEN** a payload and a filter key such as `object.status`
- **WHEN** `getNestedValue()` traverses the payload
- **THEN** it MUST descend each dot-separated segment and return the nested value, or `null` when any segment is absent

#### Scenario: Short event name derivation

- **GIVEN** a fully qualified event class such as `OCA\OpenRegister\Event\ObjectCreatedEvent`
- **WHEN** `getShortEventName()` runs
- **THEN** it MUST return the trailing class segment `ObjectCreatedEvent`, which is the value enriched onto mapping input as `event`

#### Scenario: Event-type to event-class mapping

- **GIVEN** a dotted interception event type such as `object.creating`
- **WHEN** `eventTypeToEventClass()` runs
- **THEN** it MUST return `OCA\OpenRegister\Event\ObjectCreatingEvent`, capitalizing the entity and action segments and defaulting the action to `created` when absent

#### Scenario: CloudEvent datacontenttype derives from the request

- **GIVEN** an intercepted HTTP request being formatted as a CloudEvent
- **WHEN** `CloudEventFormatter::getContentTypeHeader()` runs
- **THEN** it MUST return the request's `Content-Type` header when present, otherwise default to `application/json`
