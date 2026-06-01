/**
 * Integration Registry Global Bootstrap Entry Point
 *
 * Loaded on every Nextcloud page via IntegrationGlobalScriptListener
 * (BeforeTemplateRenderedEvent → Util::addInitScript).
 *
 * Installs window.OCA.OpenRegister.integrations so that leaf apps
 * (e.g. OpenConnector's sync-contract) find a real registry on any
 * consuming-app page — not only on pages served by OpenRegister itself.
 *
 * @license EUPL-1.2
 */

import { ensureIntegrationRegistry } from './integrations/bootstrap.js'

ensureIntegrationRegistry()
