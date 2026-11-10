## ADDED Requirements

### Requirement: Validator accepts actions, originApp, and the web-push channel

`NotificationAnnotationValidator` SHALL accept the dialect additions from the foundation contract: `web-push` added to the channel enum (`VALID_CHANNELS`), an optional `actions[]` array with a hard cap of 2, and an optional `originApp` string. Each action SHALL declare an i18n `label`, an optional `primary` boolean, and a `target`. A third action SHALL be rejected with `notification-too-many-actions`; a bad label with `notification-action-bad-label`; an unrecognised target kind with `notification-action-bad-target`; a non-string/empty `originApp` with `notification-bad-origin-app`.

#### Scenario: web-push accepted as a channel

- **WHEN** a rule declares `channels: ["web-push"]`
- **THEN** schema-save validation accepts `web-push` (now in `VALID_CHANNELS`)

#### Scenario: Two actions accepted, three rejected

- **WHEN** a rule declares two actions, then another rule declares three
- **THEN** the two-action rule validates and the three-action rule is rejected with `notification-too-many-actions` referencing the Web Notification API limit of 2

#### Scenario: Bad action label rejected

- **WHEN** an action's `label` is not a per-locale map with at least one non-empty locale
- **THEN** validation rejects the rule with `notification-action-bad-label`

#### Scenario: Unrecognised target kind rejected

- **WHEN** an action declares a `target.kind` outside `[object-detail, route, url]`
- **THEN** validation rejects the rule with `notification-action-bad-target`

#### Scenario: Bad originApp rejected

- **WHEN** a rule declares `originApp` as a non-string or empty string
- **THEN** validation rejects the rule with `notification-bad-origin-app`

### Requirement: Dispatcher stamps originApp and routes web-push

`AnnotationNotificationDispatcher::emitNotification` SHALL stamp the resolved `originApp` (declared value, or default = the app owning the schema's register) onto the emitted notification, and SHALL route a rule carrying the `web-push` channel through the Web Push send path (the `web-push-delivery` capability) in addition to any other declared channels.

#### Scenario: originApp stamped from declaration

- **WHEN** a rule declares `originApp: "pipelinq"`
- **THEN** the dispatched notification carries the `pipelinq` origin (driving icon and deeplink base) instead of `openregister`

#### Scenario: originApp defaults to register owner

- **WHEN** a rule omits `originApp`
- **THEN** the dispatcher resolves the owning app from the schema's register and uses that as the origin

#### Scenario: web-push channel routed to the send path

- **WHEN** a rule declares `channels: ["nc-notification", "web-push"]`
- **THEN** the dispatcher emits the nc-notification AND hands the payload to the web-push send path (background job)

### Requirement: Dispatcher resolves action targets to deeplinks

For each declared action, `AnnotationNotificationDispatcher` SHALL resolve the `target` to a concrete deeplink server-side at dispatch time: `object-detail` → the triggering object's detail route; `object-detail` with `{ object: { kind: "relation", field } }` → the related object's register/schema/uuid resolved on the triggering object (the "Open client" mechanism), through OR RBAC so the deeplink is only built for objects the recipient may read; `route` → the originApp frontend route with `{{prop}}` interpolation (HTML-escaped) from object fields; `url` → the absolute URL verbatim.

#### Scenario: object-detail deeplinks to the triggering object

- **WHEN** an action declares `target: { "kind": "object-detail" }`
- **THEN** the dispatcher builds the deeplink from the triggering object's registerId + schemaId + objectUuid against the originApp route base

#### Scenario: relation target deeplinks to the related object

- **WHEN** an action declares `target: { "kind": "object-detail", "object": { "kind": "relation", "field": "client" } }` and the triggering Contactmoment holds a relation in `client`
- **THEN** the dispatcher resolves the related Client's register/schema/uuid server-side and builds the deeplink to that Client (the "Open client" case), only if the recipient may read it

#### Scenario: route target interpolates fields

- **WHEN** an action declares `target: { "kind": "route", "app": "pipelinq", "route": "/clients/{{clientId}}" }`
- **THEN** the dispatcher interpolates `{{clientId}}` from the object's fields (HTML-escaped) and builds the named app route link

#### Scenario: url target passthrough

- **WHEN** an action declares `target: { "kind": "url", "href": "https://EXAMPLE_HOST/path" }`
- **THEN** the dispatcher uses the absolute URL verbatim

### Requirement: Notifier renders declared actions with the originApp icon

`AnnotationNotifier` SHALL render the declared `actions[]` via `addAction()` (keeping the implicit "View" action as the default only when no actions are declared, for back-compat), labelling each in the recipient's locale (nl/en per ADR-007) and marking the `primary` one. It SHALL set the notification icon to the originApp hex composite (served by the hex-icon endpoint) instead of the static openregister image path.

#### Scenario: Declared actions replace the implicit View

- **WHEN** a rule declares one primary action
- **THEN** the notifier renders that action button (localised, primary) and does not add the implicit "View" action

#### Scenario: No actions keeps the implicit View

- **WHEN** a rule declares no `actions`
- **THEN** the notifier keeps adding the implicit "View" action deeplinking to the triggering object, exactly as before

#### Scenario: Icon uses the originApp hex composite

- **WHEN** a notification is dispatched with `originApp: "pipelinq"`
- **THEN** the notifier sets the icon to the pipelinq hex-composite raster URL rather than `IURLGenerator::imagePath('openregister', ...)`
