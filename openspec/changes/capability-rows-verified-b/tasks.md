# Tasks: capability-rows-verified-b

## 1. Flow canvas

- [ ] 1.1 Pin the canvas scenario: add an object trigger and a send-email step on the flow page, save, create a matching record and read the run. Verify: a Playwright test on the flow detail page (`src/manifest.json` page `flowDetail`).

## 2. Publish to GitLab

- [ ] 2.1 Add `configuration#publishToGitLab` beside `configuration#publishToGitHub` (`appinfo/routes.php`, `lib/Controller/ConfigurationController.php`), using the GitLab handler that import already uses, and offer GitLab as a target in `src/modals/configuration/PublishConfiguration.vue`.
- [ ] 2.2 Pin both scenarios. Verify: a PHPUnit test with a mocked GitLab client.

## 3. Language model provider

- [ ] 3.1 Pin the provider choice: save Ollama with a local address in the LLM settings and read the settings back. Verify: `tests/Unit/Controller/LlmSettingsControllerTest.php` or a Newman call on `/api/settings/llm`.
