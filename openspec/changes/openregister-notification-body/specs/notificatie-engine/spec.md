## ADDED Requirements

### Requirement: Notification title is the subject and the body is the optional message

The engine MUST render the notification TITLE from a rule's `subject` and the notification BODY from a rule's optional `message`. The `message` MUST accept the same shape, locale-resolution, and `{{prop}}` interpolation as `subject` (a single template string OR a per-locale map with an optional `defaultLocale`). A malformed `message` MUST be rejected at schema-save with a `notification-bad-message` error. The resolved body MUST be threaded into the in-app notification (set via `setParsedMessage`) and into the web-push payload body, while the title remains the resolved `subject`.

#### Scenario: Explicit per-locale message becomes the localised body

- GIVEN a rule with `subject: { "nl": "Titel {{title}}", "en": "Title {{title}}" }` and `message: { "nl": "Body voor {{title}}", "en": "Body for {{title}}" }`
- AND a recipient whose locale is `nl` and an object with `title` = `demo`
- WHEN the engine dispatches the `nc-notification`
- THEN the notification title MUST be `Titel demo` and the notification body (`_message`, set via `setParsedMessage`) MUST be `Body voor demo`

#### Scenario: Malformed message is rejected at schema-save

- GIVEN a rule whose `message` is neither a non-empty string nor a per-locale map with at least one non-empty locale
- WHEN the schema is validated on save
- THEN validation MUST return a `notification-bad-message` error

#### Scenario: Web-push title and body are distinct

- GIVEN a rule with a `subject` and a distinct `message` delivered over the `web-push` channel
- WHEN the engine enqueues the web-push dispatch job
- THEN the job argument MUST carry `title` = the resolved subject and `body` = the resolved message, and the Service-Worker payload body MUST be the message

### Requirement: The body is auto-derived for actions-bearing rules and empty otherwise

When a rule declares NO `message` but DOES declare `actions[]`, the engine MUST compose a default body `"Open in {AppName}."`, where `{AppName}` is the `originApp`'s human display name resolved via `IAppManager` (falling back to the capitalised app id when the app manager or app info is unavailable). When a rule declares neither `message` nor `actions`, the engine MUST leave the body empty and MUST NOT call `setParsedMessage` — preserving the behaviour before this change.

#### Scenario: Actions but no message yields the auto-derived body

- GIVEN a rule with `originApp: "opentalk"` (display name `OpenTalk`), a declared resolvable `actions[]`, and no `message`
- WHEN the engine dispatches the notification
- THEN the notification body (`_message`) MUST be `Open in OpenTalk.`

#### Scenario: Neither message nor actions leaves the body empty (back-compat)

- GIVEN a rule with neither `message` nor `actions`
- WHEN the engine dispatches the notification
- THEN the body (`_message`) MUST be the empty string and the notifier MUST NOT call `setParsedMessage`
