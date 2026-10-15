# Design: enforce-organisation-special-rights

Read from `development` at 012e1bc9f (after #4601). Line numbers below are in
this branch unless marked "base".

## Where each right applies

### `agent_use`: running an agent

An agent is run in two places. Both are enforced.

1. **A chat message in a conversation that has an agent.** The agent is loaded
   and handed to the model call in `ChatService::processMessage()`
   (`lib/Service/ChatService.php:218`; agent loaded at 246 to 249, model called
   at 298). Two HTTP entry points reach it:
   - `POST /api/chat/send` → `ChatController::sendMessage()`
     (`lib/Controller/ChatController.php:415`, calls processMessage at 453);
   - `POST /api/chat/stream` → `ChatStreamController::stream()`
     (`lib/Controller/ChatStreamController.php:218`, calls processMessage at 362).
   Enforced in `ChatService::processMessage()` at 253 (through
   `ChatService::missingRight()`, 389), which covers both; and again in
   `ChatStreamController::stream()` at 302, before the first heartbeat, so the
   stream answers with its own `forbidden` frame.
2. **An MCP session that declares an agent** (`agent` initialize parameter or
   `X-OpenRegister-Agent`). Every call on it acts as that agent.
   `McpServerController` (`lib/Controller/McpServerController.php`) calls
   `McpAgentScope::assertUsable()` at 317 (initialize), `filterListing()` at 368
   (tools/list) and `assertMayCall()` at 427 (tools/call); all three load the
   agent through `McpAgentScope::loadAgent()`
   (`lib/Service/Mcp/McpAgentScope.php:235`). Enforced there, at 248 to 258, so
   narrowing the right also narrows open sessions, as narrowing the grant does.

Not an entry point: an agent-type endpoint
(`EndpointService::executeAgentEndpoint()`, `lib/Service/EndpointService.php:285`)
answers 501 and runs nothing (314). A flow's agent principal
(`lib/Service/Flow/Principal/AgentPrincipalResolver.php`) is an agent acting as
its own Nextcloud account, not a user running an agent.

Read from: the agent's organisation (`Agent::getOrganisation()`), as
`grant_manage` is read from the record's.

### `llm_use`: a model called on a user's behalf

Every call to a language model is in `lib/Service/Chat/`:
`ResponseGenerationHandler::generateResponse()` (`new OpenAIChat` at 391,
`new OllamaChat` at 433), and in `ConversationManagementHandler` the title
(238, 260) and the summary (575, 579). All three are reached only from
`ChatService::processMessage()`: `checkAndSummarize()` at 275,
`generateResponse()` at 298, `generateConversationTitle()` at 327. The public
`ChatService::generateConversationTitle()` (423) has no caller in `lib/`.
So the entry points are the same two as above, and the check is the same one,
at `lib/Service/ChatService.php:253` and `lib/Controller/ChatStreamController.php:302`.

Not counted: `LlmSettingsController::testChat()` is an administrator's settings
probe, and administrators pass. Embeddings for vector search
(`lib/Service/Vectorization/Handlers/EmbeddingGeneratorHandler.php`) call an
embedding model, not a language model on a user's behalf; see Q3.

Read from: the conversation's organisation (`Conversation::getOrganisation()`,
set from the user's active organisation when the conversation is created).

### `object_publish`: NOT FOUND, question Q1

Objects no longer have a publish action. Migration
`lib/Migration/Version1Date20260313130000.php` dropped `published` and
`depublished` (110, 119): "The published/depublished metadata system has been
replaced by RBAC conditional rules with the $now dynamic variable." There is no
`objects#publish` route and no `setPublished()` on `ObjectEntity`. The nearest
actions are other things:

- publishing a FILE of an object: `POST /api/objects/{register}/{schema}/{id}/files/batch`
  (`appinfo/routes.php:1514`) with action `publish` / `depublish`,
  `lib/Service/File/FileBatchHandler.php:181` and 184, into
  `FilePublishingHandler::publishFile()` / `unpublishFile()`;
- making an object readable without an account, by a public access link or a
  `public` read grant, which is the sharing and RBAC surface.

Not enforced. Choosing either would be inventing what the right means.

### `dashboard_view`: question Q2

The dashboard is `DashboardController` (`lib/Controller/DashboardController.php`):
`GET /api/dashboard` (`index()`, 322) and the chart and statistics endpoints
under `/api/dashboard/`. Every one of them already refuses a non-administrator
(`isCurrentUserAdmin() === false` → 403, e.g. 323), because the figures are
instance-wide and would leak across organisations. Administrators pass every
special right, so enforcing `dashboard_view` there changes nothing. `page()`
(148) is the app's single-page shell, not the dashboard; refusing it would
refuse the whole app. The Nextcloud dashboard widget
(`lib/Dashboard/IntegrationDashboardWidget.php`) lists integrations, not
organisation analytics.

Not enforced.

## Semantics of a right a caller uses

`OrganisationSpecialRights::mayUse(right, userId, organisationUuid)`
(`lib/Service/Rbac/OrganisationSpecialRights.php:253`):

| Situation | Answer |
|-----------|--------|
| Caller is a Nextcloud administrator | allowed |
| No caller (empty user id) | refused |
| Right is not one of the four (e.g. `grant_manage`) | refused |
| Organisation not named on the agent or conversation | the default organisation is read |
| Not named and no default organisation | allowed: nothing could have narrowed it |
| Named organisation cannot be loaded | refused (fail closed) |
| Organisation does not carry the key | allowed: nobody narrowed it |
| Value is not a list | refused |
| List holds `*` | every signed-in user, including one in no group |
| List of groups | a user in one of them |
| Empty list | administrators only |
| Entry narrowed to a register/schema (the `grant_manage` shape) | admits nobody here |

A user who is in no organisation is not a special case: the right is read
from the organisation of the thing being used, never from the caller's
membership.

## Upgrade

Repair step `OCA\OpenRegister\Repair\OpenSpecialRightsToEveryone`
(`lib/Repair/OpenSpecialRightsToEveryone.php`), listed under post-migration in
`appinfo/info.xml`. It adds `*` to `object_publish`, `agent_use`,
`dashboard_view` and `llm_use` in every organisation, keeping any groups
listed, and never touches `grant_manage`. It runs once: app config
`openregister/special_rights_opened_to_everyone` records it, so a `*` an
administrator removes stays removed. If it fails part-way it does not set the
flag; the next upgrade finishes it and skips organisations that already hold
`*`. It never throws.

A fresh install needs no step: `Organisation::getDefaultAuthorization()`
(`lib/Db/Organisation.php`) gives a new organisation `["*"]` under the four.

All four are opened, not only the two enforced now, so enforcing
`object_publish` or `dashboard_view` later needs no second repair step.

## Who changes special rights

`OrganisationController::update()` (`lib/Controller/OrganisationController.php:718`)
compares each of the five lists in the request with the stored one, through
`changedSpecialRights()` (1098). A key missing on either side counts as its
default (`["*"]` for the four, `[]` for `grant_manage`), because the stored
block is replaced as a whole. Any difference from a caller for whom
`OrganisationService::isOrganisationAdmin()` is false (not an administrator,
not the owner) is a 403 naming the rights. `PATCH` goes through `update()`.

`EditOrganisation.vue` disables the four selects and says why when the signed-in
user is neither an administrator (`getCurrentUser().isAdmin`) nor the owner;
creating a new organisation makes you its owner, so it stays editable.

## Questions for Ruben

- **Q1 `object_publish`.** Objects have no publish action since migration
  Version1Date20260313130000. Should this right guard (a) publishing and
  depublishing an object's files (`files/batch` action `publish`/`depublish`),
  (b) making an object public (a public access link, or a `public` read grant
  on the object), (c) both, or (d) be retired?
- **Q2 `dashboard_view`.** The dashboard API is administrator only because its
  figures are instance-wide. Should this right (a) let a holder see the
  dashboard, which then needs the figures scoped to the holder's organisation
  first, (b) guard some other dashboard (a leaf app's), or (c) be retired?
- **Q3 `llm_use` and embeddings.** Should a semantic or hybrid search, which
  embeds the user's query with an embedding model, also need `llm_use`? Today
  it does not.
