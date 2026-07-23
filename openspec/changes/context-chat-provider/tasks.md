## 1. Schema configuration

- [ ] 1.1 Add `x-openregister-contextchat` to `Schema::setConfiguration()`'s annotation allow-list, add an `isContextChatIndexingEnabled()` accessor, and assert round-trip (write config, re-read schema, key survives) in a test

## 2. Content provider + registration

- [ ] 2.1 Implement `lib/ContextChat/ContentProvider.php` (`IContentProvider`: `getId`, `getAppId`, `getItemUrl($id)`, `triggerInitialImport`)
- [ ] 2.2 Implement `lib/ContextChat/ContentProviderRegistrationListener.php` (listens on `ContentProviderRegisterEvent`, guarded by `isContextChatAvailable()`)
- [ ] 2.3 Register the listener in `lib/AppInfo/Application.php::registerEventListeners()`

## 3. Submission listener

- [ ] 3.1 Implement `lib/Listener/ContextChatSubmissionListener.php`: submit on `ObjectCreatedEvent`/`ObjectUpdatedEvent` when schema opted-in AND object satisfies the published predicate; remove on `ObjectDeletedEvent` or when an update makes the object no longer published; fail-soft (catch `Throwable`, log, never block the write)
- [ ] 3.2 Register the listener on `ObjectCreatedEvent`, `ObjectUpdatedEvent`, `ObjectDeletedEvent` in `registerEventListeners()`

## 4. Deep-link URL resolution

- [ ] 4.1 Wire `ContentProvider::getItemUrl()` to `DeepLinkRegistryService::resolveUrl()` with the `openregister.objects.show` route fallback (no new URL-template config)

## 5. Initial import + occ command

- [ ] 5.1 Implement `triggerInitialImport()`: batched walk of every opted-in (register, schema) pair, submitting each published object
- [ ] 5.2 Implement `lib/Command/ContextChatReindexCommand.php` (`openregister:contextchat:reindex`, optional `--register`/`--schema` scoping), reusing the batch-submission path
- [ ] 5.3 Register the command in `appinfo/info.xml`

## 6. Tests

- [ ] 6.1 Unit test: provider registration fires only when `isContextChatAvailable()` is true
- [ ] 6.2 Unit test: submission payload shape on object create/update
- [ ] 6.3 Unit test: opt-in filtering (schema with flag vs. without)
- [ ] 6.4 Unit test: published-predicate filtering (published/unpublished/depublished objects)
- [ ] 6.5 Unit test: object deletion issues a content-removal call
- [ ] 6.6 Unit test: `getItemUrl` deep-link resolution and fallback

## 7. Live verification

- [ ] 7.1 Live-verify on the dev instance (8080): opt in a test schema, create/publish/update/delete objects, confirm submit/remove calls fire, and run `occ openregister:contextchat:reindex` end-to-end

## Acceptance Criteria

- A standalone OpenRegister instance without the `context_chat` app boots and behaves exactly as before this change.
- Objects on schemas without `x-openregister-contextchat: true` are never submitted to Context Chat.
- Only published, non-deleted objects on opted-in schemas are submitted; depublished or deleted objects are removed from Context Chat.
- `getItemUrl()` returns the same URL a user would reach via unified search or the deep-link registry for that object.
- `occ openregister:contextchat:reindex` backfills all opted-in, published objects and can be scoped to a single register/schema.
- No object create/update/delete request fails or slows materially due to a Context Chat submission error.
