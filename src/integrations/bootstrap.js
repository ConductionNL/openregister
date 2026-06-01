/**
 * Integration Registry Bootstrap
 *
 * Provides ensureIntegrationRegistry(), which resolves the shared
 * window.OCA.OpenRegister.integrations instance via getSharedRegistry().
 *
 * getSharedRegistry() implements "converge-not-clobber": if a leaf app
 * installed a stub ({_queue, register}) before this script ran, the stub's
 * queued descriptors are drained into the real registry instance.
 *
 * Idempotent: calling ensureIntegrationRegistry() multiple times is safe.
 *
 * @license EUPL-1.2
 */

import { getSharedRegistry } from '@conduction/nextcloud-vue'

/**
 * Ensure the shared integration registry is installed on the window object.
 *
 * Resolves (or creates) window.OCA.OpenRegister.integrations using the
 * converge-not-clobber primitive from @conduction/nextcloud-vue. Any leaf
 * app that registered against a stub queue before this script ran will have
 * its descriptors automatically drained into the real registry.
 *
 * @return {object} The shared integration registry instance.
 */
export function ensureIntegrationRegistry() {
	return getSharedRegistry(window)
}
