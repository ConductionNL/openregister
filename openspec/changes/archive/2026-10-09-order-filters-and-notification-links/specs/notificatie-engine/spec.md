# notificatie-engine

## ADDED Requirements

### Requirement: An object notification MUST link to the object

`AnnotationNotifier` SHALL set the notification link of every object
notification that carries a register, a schema and an object uuid. The link
SHALL be the owning app's detail page from the deep link registry when an app
claimed the schema, else OpenRegister's object view
(`openregister.dashboard.page` + `#/registers/{registerId}/schemas/{schemaId}/objects/{objectUuid}`).
The link SHALL be absolute. The implicit View action SHALL use the same link.
Declared actions keep their own targets; one whose resolved url is a path
SHALL be made absolute, because Nextcloud refuses a relative action link.
Actions SHALL be added as parsed actions (`setParsedLabel()` +
`addParsedAction()`), because Nextcloud's notification API returns parsed
actions only: an action added with `addAction()` never reached the client.

#### Scenario: a pipelinq client notification links to pipelinq

- **GIVEN** pipelinq registered `/apps/pipelinq/clients/{uuid}` for its client schema
- **WHEN** the notifier prepares a "Client changed" notification for client `c-1`
- **THEN** the notification link is the absolute URL of `/apps/pipelinq/clients/c-1`
- **AND** the View action links there too and is returned by the notifications API
- @e2e exclude {notifier rendering; covered by tests/Unit/Notification/AnnotationNotifierLinkTest.php}

#### Scenario: an unclaimed schema links to OpenRegister

- **GIVEN** no app registered a deep link for the object's schema
- **WHEN** the notifier prepares an object notification
- **THEN** the link is OpenRegister's object view for that register, schema and uuid
- @e2e exclude {notifier rendering; covered by tests/Unit/Notification/AnnotationNotifierLinkTest.php}
