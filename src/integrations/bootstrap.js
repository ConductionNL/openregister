/**
 * Shared integration-registry bootstrap.
 *
 * OpenRegister ships four webpack entry bundles (main, adminSettings,
 * filesSidebar, mailSidebar). Each one runs in its own JS scope when the
 * containing page loads, so each must install + populate the
 * window.OCA.OpenRegister.integrations registry. Without that, sub-surfaces
 * like the /dashboard widgets, the files-sidebar, or the mail-sidebar end
 * up with an empty registry and the integration tabs / widgets disappear.
 *
 * Bootstrap is idempotent: a module-scope guard prevents double registration
 * when an entry bundle's setup runs after main.js (e.g. inside a Files
 * sidebar mount on the same page).
 *
 * @see ADR-019 — Pluggable Integration Registry
 */
import {
	getSharedRegistry,
	registerBuiltinIntegrations,
	registerLeafIntegrations,
} from '@conduction/nextcloud-vue'
import { registerBookmarksIntegration } from './builtin/bookmarks.js'

let bootstrapped = false

/**
 * Idempotent — safe to call from every entry bundle. Subsequent calls
 * after the first are no-ops, so consumers don't need to coordinate.
 *
 * Resolves the SHARED registry (window-global) via getSharedRegistry and
 * registers builtins + leaves into THAT instance, so every consuming
 * app's useIntegrationRegistry (which reads the same shared instance)
 * sees them — including when this bootstrap runs from the global
 * init-script on a foreign app's page (e.g. an OpenCatalogi publication).
 *
 * Leaf integrations that require `referenceType` for property auto-rendering
 * (e.g. bookmarks) are registered via their own builtin module AFTER
 * registerLeafIntegrations so the idempotent guard can skip duplicates.
 */
export function ensureIntegrationRegistry() {
	if (bootstrapped) {
		return
	}
	const registry = getSharedRegistry(window)
	registerBuiltinIntegrations(registry)
	registerLeafIntegrations(registry)
	registerBookmarksIntegration(registry)
	bootstrapped = true
}
