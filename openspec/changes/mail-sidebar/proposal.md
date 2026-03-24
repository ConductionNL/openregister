## Why

Email is a primary communication channel for government workers. When they receive an email, they often need to look up the sender in their case management system (Procest), CRM (Pipelinq), or document system (DocuDesk). Currently this requires switching apps and manually searching. Showing linked entities, related objects, and available actions directly in the Nextcloud Mail sidebar saves time and reduces context switching.

## What Changes

- **Mail app integration**: Research and implement extension points in the Nextcloud Mail app to add sidebar tabs (via `LoadAdditionalScriptsEvent`, sidebar tab API, or DOM injection fallback)
- **Entity matching engine**: New `MailIntegrationService` that matches email sender/recipients against OpenRegister entities by email address, display name, and domain
- **Entities tab**: Vue component showing matched entities (persons, organizations) grouped by sender/to/cc, with type icons, match badges, linked object counts, and DeepLink click-through
- **Objects tab**: Vue component showing register objects linked to matched entities via EntityRelation lookups
- **Actions tab**: Vue component showing context-filtered actions from the action registry (`context: "mail"`), passing mail-specific context (emailId, senderEmail, senderName, subject, messageId)
- **New API endpoints**: Three authenticated endpoints for mail entity matching, object lookup, and mail-context action retrieval
- **Caching layer**: APCu caching for email-to-entity mappings (TTL 60s) and per-email object lookups, with client-side debounce (300ms) for rapid email navigation

## Capabilities

### New Capabilities
- `mail-sidebar-integration`: Mail app extension point research, tab registration, script injection, and sidebar UI framework for the Entities/Objects/Actions tabs
- `mail-entity-matching`: Backend service for matching email metadata (address, name, domain) against OpenRegister entities, with APCu caching and new API endpoints

### Modified Capabilities
- `deep-link-registry`: Entity and object cards in the mail sidebar need DeepLink URLs for click-through navigation to consuming apps

## Impact

- **New files**: `MailIntegrationService.php`, `MailIntegrationController.php`, `src/components/MailSidebar/EntitiesTab.vue`, `ObjectsTab.vue`, `ActionsTab.vue`
- **Routes**: Three new API routes under `/api/mail/`
- **Dependencies**: Requires Nextcloud Mail app installed and enabled; depends on `action-registry` change for Actions tab; soft dependency on `files-sidebar-tabs` for shared component patterns
- **Existing code**: Leverages `EntityMapper`, `EntityRelationMapper`, and the DeepLink registry
- **Performance**: APCu caching prevents repeated DB queries for same-sender emails within a session
- **Consuming apps**: Procest, Pipelinq, DocuDesk register mail-context actions (e.g., "Create Case from Email", "Create Lead from Email", "Archive Email")
