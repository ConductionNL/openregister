## ADDED Requirements

### Requirement: OpenRegister MUST register a Context Chat content provider only when the platform is available

OpenRegister MUST listen for `OCP\ContextChat\Event\ContentProviderRegisterEvent`
and, only when `isContextChatAvailable()` returns true, call
`$event->registerContentProvider('openregister', 'openregister_objects',
ContentProvider::class)`. When `context_chat` is not installed or not
enabled, OpenRegister MUST NOT register a content provider and MUST NOT
error or log above debug level.

#### Scenario: context_chat app is enabled
- **WHEN** `ContentProviderRegisterEvent` is dispatched and `context_chat` is enabled
- **THEN** OpenRegister registers a content provider with id `openregister_objects` and app id `openregister`

#### Scenario: context_chat app is absent
- **WHEN** OpenRegister boots on an instance without the `context_chat` app installed
- **THEN** no content provider is registered and app boot completes without error

### Requirement: Only opted-in schemas MUST have their objects submitted to Context Chat

A schema's objects MUST only be submitted to Context Chat when its
configuration contains `x-openregister-contextchat` set to a truthy value.
The default, for any schema lacking this key, MUST be treated as opted out.

#### Scenario: Opted-in schema submits content
- **GIVEN** a schema with `configuration['x-openregister-contextchat'] = true`
- **WHEN** an object of that schema is created
- **THEN** the object's content is submitted via `ContentManager::submitContent()`

#### Scenario: Schema without the flag is skipped by default
- **GIVEN** a schema whose configuration does not contain `x-openregister-contextchat`
- **WHEN** an object of that schema is created
- **THEN** no content is submitted for that object

### Requirement: Only published objects MUST be submitted to Context Chat

Regardless of schema opt-in, an object MUST only be submitted to Context
Chat when it satisfies the published predicate: `@self.published` is set
and in the past, and `@self.depublished` is either unset or in the future.
Soft-deleted objects MUST never be submitted.

#### Scenario: Published object on opted-in schema is submitted
- **GIVEN** an opted-in schema and an object with `@self.published` set in the past and no `@self.depublished`
- **WHEN** the object is created or updated
- **THEN** the object's content is submitted to Context Chat

#### Scenario: Unpublished object on opted-in schema is not submitted
- **GIVEN** an opted-in schema and an object with no `@self.published` value
- **WHEN** the object is created or updated
- **THEN** no content is submitted for that object

#### Scenario: Object that becomes depublished is removed
- **GIVEN** a previously published, submitted object whose `@self.depublished` moves into the past
- **WHEN** the object is updated
- **THEN** OpenRegister removes the object's content from Context Chat rather than resubmitting it

### Requirement: Object deletion MUST remove submitted content from Context Chat

OpenRegister MUST remove an object's content from Context Chat when the
object is deleted, regardless of its published state at delete time,
provided it was ever eligible for submission (i.e. its schema is opted in).

#### Scenario: Deleting a submitted object removes its content
- **GIVEN** a published object on an opted-in schema previously submitted to Context Chat
- **WHEN** the object is deleted
- **THEN** OpenRegister issues a content-removal call for that object's id to Context Chat

### Requirement: getItemUrl MUST resolve through the existing deep-link registry

`ContentProvider::getItemUrl($id)` MUST resolve the target object's URL via
`DeepLinkRegistryService::resolveUrl()` using the object's register and
schema, and MUST fall back to the `openregister.objects.show` route when no
app has claimed a deep link for that (register, schema) pair. No
provider-specific URL-template configuration is introduced.

#### Scenario: Deep link registered by a consuming app
- **GIVEN** an app has registered a deep-link URL template for the object's (register, schema)
- **WHEN** `getItemUrl($id)` is called for an object of that (register, schema)
- **THEN** the resolved URL matches the registered app's template

#### Scenario: No deep link registered
- **GIVEN** no app has registered a deep-link URL template for the object's (register, schema)
- **WHEN** `getItemUrl($id)` is called
- **THEN** the resolved URL points to OpenRegister's own `openregister.objects.show` route

### Requirement: Initial import MUST walk opted-in schemas in batches and MUST be re-runnable via occ

`ContentProvider::triggerInitialImport()` MUST enumerate every opted-in
(register, schema) pair and submit each qualifying (published) object in
bounded batches. The same batch-submission path MUST also be reachable via
the `openregister:contextchat:reindex` occ command, which MUST accept
optional register/schema scoping.

#### Scenario: Initial import covers all opted-in schemas
- **GIVEN** two opted-in schemas, each with published objects
- **WHEN** `triggerInitialImport()` runs
- **THEN** every published object of both schemas is submitted to Context Chat

#### Scenario: occ reindex command scoped to one schema
- **GIVEN** two opted-in schemas
- **WHEN** an operator runs `occ openregister:contextchat:reindex --schema=<id>`
- **THEN** only published objects of that schema are (re)submitted
