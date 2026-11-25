// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
/**
 * Bookmarks leaf-integration registration.
 *
 * Registers the `bookmarks` integration in the shared OR integration
 * registry with `referenceType: 'bookmarks'` so CnFormDialog and
 * CnDetailGrid render the single-entity bookmark widget inline next to
 * reference properties.
 *
 * Uses `CnBookmarksTab` + `CnBookmarksCard` from @conduction/nextcloud-vue.
 *
 * Idempotent — a `registry.has('bookmarks')` guard prevents
 * double-registration when `registerLeafIntegrations` from nc-vue
 * already installed a bookmarks descriptor in the same page load.
 *
 * @see ADR-019 — Pluggable Integration Registry
 * @see openspec/changes/integration-bookmarks/specs/integration-bookmarks/spec.md
 */
import { bookmarksIntegration } from '@conduction/nextcloud-vue/dist/esm/integrations/builtin/bookmarks.js'
import { t } from '@nextcloud/l10n'

/**
 * The bookmarks integration descriptor.
 *
 * The tab and card are the library's dedicated bookmarks components (lazy
 * wrappers that load on first render), taken from the library's own
 * bookmarks descriptor module rather than the package barrel: this runs
 * from the script Nextcloud loads on every page, and the barrel import used
 * to pull a second copy of the whole library onto it.
 *
 * @spec openspec/changes/integration-bookmarks/specs/integration-bookmarks/spec.md
 * @param {object} [library] The tab and card to use.
 * @param {object} [library.tab] The tab component.
 * @param {object} [library.widget] The card component.
 * @return {object} The descriptor.
 */
export function bookmarksDescriptor({ tab, widget } = bookmarksIntegration) {
	return {
		id: 'bookmarks',
		label: t('openregister', 'Bookmarks'),
		icon: 'Bookmark',
		group: 'docs',
		requiredApp: 'bookmarks',
		storage: 'link-table',
		referenceType: 'bookmarks',
		tab,
		widget,
		defaultSize: { w: 4, h: 3 },
	}
}

/**
 * Register the bookmarks integration into the supplied registry instance.
 *
 * Call this from ensureIntegrationRegistry() after registerLeafIntegrations()
 * so that the nc-vue built-in leaf-descriptors are already present. When
 * nc-vue already registered a `bookmarks` entry this call is a no-op.
 *
 * @spec openspec/changes/integration-bookmarks/specs/integration-bookmarks/spec.md
 * @param {object} registry — The shared integration registry instance
 *                            returned by getSharedRegistry().
 * @return {void}
 */
export function registerBookmarksIntegration(registry) {
	if (!registry || typeof registry.register !== 'function') {
		return
	}

	if (registry.has?.('bookmarks')) {
		return
	}

	try {
		registry.register(bookmarksDescriptor())
	} catch (e) {
		console.error('[bookmarks] failed to register bookmarks integration', e)
	}
}
