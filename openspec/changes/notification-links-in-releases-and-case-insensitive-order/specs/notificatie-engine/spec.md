# notificatie-engine

## MODIFIED Requirements

### Requirement: An object notification MUST link to the object

`AnnotationNotifier` SHALL set the notification link of every object
notification that carries a register, a schema and an object uuid. The link
SHALL be the owning app's detail page from the deep link registry when an app
claimed the schema, else OpenRegister's object view
(`openregister.dashboard.page` + `objects/{registerId}/{schemaId}/{objectUuid}`).
The dispatcher's `object-detail` action target SHALL fall back to the same
object view, never to a hash route on the origin app. The link SHALL be
absolute. The implicit View action SHALL use the same link.

#### Scenario: an unclaimed schema opens the object in OpenRegister

- **GIVEN** no app registered a deep link for the object's schema
- **WHEN** the notifier prepares an object notification, or the dispatcher resolves an `object-detail` action
- **THEN** the link is `/index.php/apps/openregister/objects/{registerId}/{schemaId}/{objectUuid}`
- **AND** it is not the dashboard hash `#/registers/...`
- @e2e exclude {notifier rendering; covered by tests/Unit/Notification/AnnotationNotifierLinkTest.php and tests/Unit/Service/Notification/DispatcherObjectDetailFallbackTest.php}

## ADDED Requirements

### Requirement: A translatable value MUST fill its placeholder

`NotificationTemplating::interpolate()` SHALL render a language map
(`{"nl": "Bel klant", "en": "Call client"}`: an object whose keys are all
language codes and whose values are all scalar) as its value in the
recipient's language, then the register's default language and other
languages, then its first non-empty value. The value SHALL be HTML-escaped
like any other. Any other object or list SHALL stay unanswered, so its
placeholder remains visible.

#### Scenario: a task subject in the recipient's language

- **GIVEN** a crmTask whose translatable `subject` is `{"nl": "Bel klant", "en": "Call client"}`
- **WHEN** an English-speaking recipient gets "Task changed: {{subject}}"
- **THEN** the subject reads "Task changed: Call client"
- @e2e exclude {notification rendering; covered by tests/Unit/Service/Notification/TranslatablePlaceholderTest.php}

#### Scenario: a recipient language the map lacks

- **GIVEN** a register whose default language is `nl`
- **WHEN** a French-speaking recipient gets a subject naming a translatable field that has `en` and `nl`
- **THEN** the field renders in Dutch
- @e2e exclude {notification rendering; covered by tests/Unit/Service/Notification/TranslatablePlaceholderTest.php}
