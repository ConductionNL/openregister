/**
 * Bookmarks integration leaf registration.
 *
 * Registers the Bookmarks integration with the OpenRegister integration
 * registry. Uses CnBookmarksTab + CnBookmarksCard when they are exported
 * from @conduction/nextcloud-vue; falls back to the generic
 * CnIntegrationTab / CnIntegrationCard so the integration is always
 * visible in the registry even before the dedicated components ship.
 *
 * Delegates "add URL" scraping to NC Bookmarks (AD-1 of the change
 * design): OR never re-implements title/favicon extraction.
 *
 * @see openspec/changes/integration-bookmarks/design.md
 * @see ADR-019 — Integration Registry Pattern
 */
import { translate as t } from '@nextcloud/l10n'

/**
 * Register the Bookmarks integration with the shared OR registry.
 *
 * Guards against double-registration so it is safe to call from multiple
 * entry bundles (main.js, files-sidebar.js, integration-global.js).
 *
 * @param {object} registry The shared OCA.OpenRegister.integrations registry.
 * @return {void}
 */
export function registerBookmarksIntegration(registry) {
	if (!registry?.register) return
	if (registry.has?.('bookmarks')) return

	import('@conduction/nextcloud-vue')
		.then(({ CnBookmarksTab, CnBookmarksCard, CnIntegrationTab, CnIntegrationCard }) => {
			registry.register({
				id: 'bookmarks',
				label: t('openregister', 'Bookmarks'),
				icon: 'Bookmark',
				group: 'docs',
				requiredApp: 'bookmarks',
				referenceType: 'bookmarks',
				tab: CnBookmarksTab ?? CnIntegrationTab,
				widget: CnBookmarksCard ?? CnIntegrationCard,
				defaultSize: { w: 4, h: 3 },
				order: 20,
			})
		})
		.catch((e) => console.error('[bookmarks] integration registration failed', e))
}
