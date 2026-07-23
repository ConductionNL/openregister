---
status: in-progress
---
# Context Chat Provider

## Purpose

@e2e exclude backend Nextcloud Context Chat integration — covered by PHPUnit

Registers OpenRegister as a Nextcloud Context Chat (`OCP\ContextChat`) content provider so opted-in, published register objects become searchable/reasonable-over by the NC Assistant's RAG pipeline — the same way OpenRegister already surfaces objects to unified search, notifications, and activity. Registration is soft-gated on `isContextChatAvailable()` (zero hard dependency on the `context_chat` app). Content is submitted on object create/update and removed on delete via the existing object-lifecycle events, is scoped by an explicit, default-OFF per-schema opt-in (`configuration['x-openregister-contextchat']`), and is further restricted to objects satisfying the same published predicate unified search already enforces. `getItemUrl()` reuses the existing `DeepLinkRegistryService::resolveUrl()` fleet-wide deep-link mechanism (no bespoke URL-template config). An `occ openregister:contextchat:reindex` command and `triggerInitialImport()` provide batched backfill.

**Status**: in-progress

**OpenSpec changes**
- `context-chat-provider` (in-progress) — implements `IContentProvider` (`getId`/`getAppId`/`getItemUrl`/`triggerInitialImport`) registered via `ContentProviderRegisterEvent`; submits/removes content on the existing `ObjectCreatedEvent`/`ObjectUpdatedEvent`/`ObjectDeletedEvent` listeners; adds the `x-openregister-contextchat` per-schema opt-in to the `Schema` configuration allow-list; adds the `openregister:contextchat:reindex` occ command.

## Requirements

### Requirement: OpenRegister registers a Context Chat content provider only when the platform is available

OpenRegister SHALL listen for `ContentProviderRegisterEvent` and register exactly one content provider (id `openregister_objects`, app id `openregister`) only when `isContextChatAvailable()` is true, mirroring the existing soft-dependency guard pattern used for the optional Tables integration. Instances without `context_chat` installed MUST be entirely unaffected. The full normative behaviour is defined by the `context-chat-provider` change delta.

#### Scenario: Provider registration is gated on platform availability
- **GIVEN** an OpenRegister instance with or without the `context_chat` app enabled
- **WHEN** `ContentProviderRegisterEvent` is dispatched (or never dispatched, on an instance without the app)
- **THEN** OpenRegister registers `openregister_objects` only when `context_chat` is available, and never errors either way
- @e2e exclude backend event listener registration — asserted by PHPUnit

### Requirement: Only opted-in, published objects are submitted to Context Chat

Content submission to Context Chat SHALL be scoped by two independent gates: (1) the object's schema carries `configuration['x-openregister-contextchat']` set to a truthy value (default OFF), and (2) the object satisfies the published predicate already used by unified search (`@self.published` set and in the past, `@self.depublished` unset or in the future). Deleted objects, or objects that become unpublished, SHALL have their content removed from Context Chat rather than left stale. The full normative behaviour is defined by the `context-chat-provider` change delta.

#### Scenario: Object submission respects opt-in and publish state
- **GIVEN** objects across opted-in and non-opted-in schemas, in both published and unpublished states
- **WHEN** each object is created, updated, or deleted
- **THEN** Context Chat only ever holds content for currently-published objects on opted-in schemas, and content is removed on delete or on the object leaving the published state
- @e2e exclude backend submission/removal listener — asserted by PHPUnit

### Requirement: getItemUrl and initial import reuse existing OpenRegister infrastructure

`getItemUrl($id)` SHALL resolve via the existing `DeepLinkRegistryService::resolveUrl()`, falling back to the `openregister.objects.show` route — no new URL-template configuration surface is introduced. `triggerInitialImport()` and the `openregister:contextchat:reindex` occ command SHALL walk opted-in (register, schema) pairs in bounded batches, submitting every qualifying published object, with the occ command additionally supporting optional register/schema scoping. The full normative behaviour is defined by the `context-chat-provider` change delta.

#### Scenario: Item URL and backfill reuse fleet-wide mechanisms
- **GIVEN** an opted-in schema with a mix of published objects, some claimed by a registered deep link and some not
- **WHEN** `getItemUrl()` is resolved for those objects, and `triggerInitialImport()` or the reindex occ command is run
- **THEN** each URL matches the deep-link registry result (or the `openregister.objects.show` fallback), and every published object of every opted-in schema is submitted
- @e2e exclude backend initial-import/reindex batch job — asserted by PHPUnit
