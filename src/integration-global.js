/**
 * Global integration-registry bootstrap entry.
 *
 * Loaded on EVERY Nextcloud page via `OCP\Util::addInitScript` (see
 * lib/AppInfo/Application.php) so the shared registry
 * (window.OCA.OpenRegister.integrations) is installed + populated with the
 * built-in integrations and the generic leaves on every page — not just
 * OpenRegister's own SPA. That makes integration tabs/widgets (and any
 * leaf app's Path-2 component queued on the stub) render inside ANY
 * consuming app's object detail page (e.g. an OpenCatalogi publication)
 * with zero per-consumer bootstrap.
 *
 * Kept tiny + idempotent (ensureIntegrationRegistry guards re-entry), so
 * loading it alongside OpenRegister's own main bundle is harmless.
 *
 * @package
 *
 * @license EUPL-1.2
 *
 * @see ADR-019 — Pluggable Integration Registry
 */
import { ensureIntegrationRegistry } from './integrations/bootstrap.js'
import { loadLibraryTranslationsWhenIdle } from './services/libraryTranslations.js'

// Register nextcloud-vue's own catalogue so the library's labels follow the
// reader's language on this page too. Only the reader's language is fetched,
// as a separate chunk, and only once the page has loaded and is idle: this
// script runs on every Nextcloud page, and nothing on it shows a library label
// before an integration is opened.
loadLibraryTranslationsWhenIdle()

ensureIntegrationRegistry()
