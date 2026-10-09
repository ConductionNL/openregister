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
