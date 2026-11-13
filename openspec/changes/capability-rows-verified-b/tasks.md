# Tasks: capability-rows-verified-b

## 1. Flow canvas

- [ ] 1.1 Pin the canvas scenario: add an object trigger and a send-email step on the flow page, save, create a matching record and read the run. Verify: a Playwright test on the flow detail page (`src/manifest.json` page `flowDetail`).

## 2. Publish to GitLab

- [ ] 2.1 Add `configuration#publishToGitLab` beside `configuration#publishToGitHub` (`appinfo/routes.php`, `lib/Controller/ConfigurationController.php`), using the GitLab handler that import already uses, and offer GitLab as a target in `src/modals/configuration/PublishConfiguration.vue`.
      Backend DONE: `POST /api/configurations/{id}/publish/gitlab` (admin only, local configurations
      only) writes through `GitLabHandler::publishFile()` and records the GitLab file as the source URL
      in the form a GitLab import and the sync job read. OPEN: the GitLab target in
      `PublishConfiguration.vue` — it needs new strings, each translated in all 37 locales.
- [x] 2.2 Pin both scenarios. Verify: a PHPUnit test with a mocked GitLab client.
      `tests/Unit/Controller/ConfigurationControllerGitLabPublishTest.php` (publish, second publish
      reported as an update, defaults, admin gate, local-only, required namespace and project, failure)
      and `tests/Unit/Service/Configuration/GitLabHandlerPublishTest.php` (POST creates, PUT updates
      the same file, a non-404 lookup failure stops the publish).

## 3. Language model provider

- [x] 3.1 Pin the provider choice: save Ollama with a local address in the LLM settings and read the settings back. Verify: `tests/Unit/Controller/LlmSettingsControllerTest.php` or a Newman call on `/api/settings/llm`.
      Pinned in `tests/Unit/Service/Settings/LlmProviderChoiceTest.php`: switch from OpenAI to Ollama
      at a local address and read it back, the next embedding is requested from that address, and
      `cohere` is refused with "Unsupported embedding provider: cohere".
