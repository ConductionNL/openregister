---
kind: code
---

## Why

Nextcloud is investing heavily in the Assistant / Context Agent surface (Hub
26 Spring shipped agent tools across Files, Mail, Tasks, Forms, Deck), and
"chat with your data" is now a baseline user expectation for any app that
holds structured records. OpenRegister already registers a unified-search
provider, notifier, activity provider, and dashboard widget so register
objects surface across Nextcloud's built-in chrome — but it registers no
`OCP\ContextChat` content provider, so every object in every register is
invisible to the NC Assistant's RAG pipeline. For register-backed apps
(including OpenBuild virtual apps), this is currently the highest-leverage
missing AI surface: users can search for an object but cannot ask the
Assistant a question that requires reasoning over its content.

## What Changes

- Implement `OCP\ContextChat\IContentProvider` (`getId`, `getAppId`,
  `getItemUrl($id)`, `triggerInitialImport`) and register it by listening for
  `ContentProviderRegisterEvent`, calling
  `$event->registerContentProvider('openregister', 'openregister_objects', ContentProvider::class)`.
  Registration is guarded by `isContextChatAvailable()` (soft dependency,
  same `class_exists`-style guard OpenRegister already uses for the optional
  Tables integration) — zero hard dependency on the `context_chat` app.
- Submit object content to `ContentManager::submitContent()` on
  `ObjectCreatedEvent` / `ObjectUpdatedEvent`, and remove it on
  `ObjectDeletedEvent`, via a new listener attached at the same
  `registerEventListeners()` site as the existing object-lifecycle listeners
  (`ObjectChangeListener`, `ObjectMetricsListener`, `SourceRecordChangeListener`).
  No hot-path service is modified.
- Add a per-schema opt-in flag, `configuration['x-openregister-contextchat']`
  (default OFF), following the existing `x-openregister-*` annotation
  convention. Only objects of an opted-in schema are ever submitted.
- Content submission additionally requires the object to satisfy the
  published predicate already used by unified search
  (`@self.published` set and in the past, `@self.depublished` unset or in
  the future) OR be readable via a documented, deliberately coarse
  allow-list — see design.md's access-model section for the full decision
  and its explicit limitation.
- `getItemUrl($id)` resolves via the existing
  `DeepLinkRegistryService::resolveUrl()` (same mechanism `ObjectsProvider`
  already uses for unified-search result URLs), falling back to
  `openregister.objects.show`. No new URL-template config is introduced —
  see design.md for why a bespoke `contextchat_url_template` key was
  rejected in favour of the fleet-wide deep-link registry.
- `triggerInitialImport()` walks opted-in (register, schema) pairs in
  batches and submits each qualifying object.
- New occ command `openregister:contextchat:reindex` (optionally scoped to a
  register/schema) driving the same batch-submission path as
  `triggerInitialImport()`.
- Unit tests: provider registration guard (available / unavailable), object
  → content-item submission payload shape, opt-in schema filtering, and
  published-predicate filtering.

## Capabilities

### New Capabilities
- `context-chat-provider`: Registers OpenRegister as a Nextcloud Context Chat
  (`OCP\ContextChat`) content provider so opted-in, published register
  objects are indexed into the NC Assistant's RAG pipeline, submitted on
  object create/update, removed on delete, with an initial-import walk and
  an occ reindex command.

### Modified Capabilities
(none — this is additive; it reuses `DeepLinkRegistryService` and the
existing `x-openregister-*` schema-configuration allow-list mechanism
without changing their contracts)

## Impact

- `lib/AppInfo/Application.php` — register `ContentProviderRegisterEvent`
  listener in `registerEventListeners()`; register the new submission
  listener on `ObjectCreatedEvent` / `ObjectUpdatedEvent` / `ObjectDeletedEvent`.
- New `lib/ContextChat/ContentProvider.php` — `IContentProvider` implementation.
- New `lib/ContextChat/ContentProviderRegistrationListener.php` — listens for
  `ContentProviderRegisterEvent`, guarded by `isContextChatAvailable()`.
- New `lib/Listener/ContextChatSubmissionListener.php` — submits/removes
  content on the object lifecycle events.
- New `lib/Command/ContextChatReindexCommand.php` — `openregister:contextchat:reindex`.
- `appinfo/info.xml` — register the new occ command (`<command>` entry);
  declare `context_chat` as an optional/soft dependency if the platform
  requires it for autoloading its interfaces (see design.md).
- `lib/Db/Schema.php` — add `x-openregister-contextchat` to the
  `setConfiguration()` annotation allow-list (existing keys are silently
  dropped if the incoming key isn't listed — see or#460/#462-class bug
  history) and add an `isContextChatIndexingEnabled()` accessor.
- No new HTTP controller/route — no user-facing REST surface is added;
  the only new entry point is the occ command.
- Tests: `tests/Unit/ContextChat/ContentProviderTest.php`,
  `tests/Unit/Listener/ContextChatSubmissionListenerTest.php`.
