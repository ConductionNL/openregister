// SPDX-License-Identifier: EUPL-1.2
/**
 * Bookmarks leaf-integration registration.
 *
 * Registers the `bookmarks` integration in the shared OR integration
 * registry with `referenceType: 'bookmarks'` so CnFormDialog and
 * CnDetailGrid render the single-entity bookmark widget inline next to
 * reference properties.
 *
 * Uses `CnBookmarksTab` + `CnBookmarksCard` from @conduction/nextcloud-vue
 * when available; falls back to the generic `CnIntegrationTab` /
 * `CnIntegrationCard` so OR works even when the dedicated components
 * have not shipped yet.
 *
 * Idempotent — a `registry.has('bookmarks')` guard prevents
 * double-registration when `registerLeafIntegrations` from nc-vue
 * already installed a bookmarks descriptor in the same page load.
 *
 * @see ADR-019 — Pluggable Integration Registry
 * @see openspec/changes/integration-bookmarks/specs/integration-bookmarks/spec.md
 */
import { t } from '@nextcloud/l10n'

/**
 * Register the bookmarks integration into the supplied registry instance.
 *
 * Call this from ensureIntegrationRegistry() after registerLeafIntegrations()
 * so that the nc-vue built-in leaf-descriptors are already present. When
 * nc-vue already registered a `bookmarks` entry this call is a no-op.
 *
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

	import('@conduction/nextcloud-vue')
		.then(
			({
				CnBookmarksTab,
				CnBookmarksCard,
				CnIntegrationTab,
				CnIntegrationCard,
			}) => {
				const tab = CnBookmarksTab ?? CnIntegrationTab
				const widget = CnBookmarksCard ?? CnIntegrationCard

				registry.register({
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
				})
			},
		)
		.catch((e) => {
			console.error('[bookmarks] failed to register bookmarks integration', e)
		})
}
