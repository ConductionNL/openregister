// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
/**
 * External register leaf-integration registration
 * (external-register-view-leaf, task 2.2).
 *
 * Registers `external-register` in the shared integration registry with a
 * widget and a tab that show one record of a basisregistratie next to the
 * object that names it. The library's `CnExternalRegisterCard` is used when it
 * ships one; until then OpenRegister's own `ExternalRegisterCard` serves both
 * surfaces. Idempotent like the bookmarks registration.
 *
 * @see ADR-019 — Pluggable Integration Registry
 * @spec openspec/changes/external-register-view-leaf/tasks.md#2-leaf
 */
import { lazyComponent } from '@conduction/nextcloud-vue/dist/esm/utils/lazyComponent.js'
import { t } from '@nextcloud/l10n'

// The card loads on its first render, like the library's own integration
// cards: this module is part of the script Nextcloud loads on every page, and
// a static import put the card's whole tree (NcButton, vue-router and Vue's
// devtools layer among it) in front of every page.
const ExternalRegisterCard = lazyComponent(() => import(/* webpackChunkName: "external-register-card" */ './ExternalRegisterCard.vue'), 'ExternalRegisterCard')

/**
 * The descriptor.
 *
 * @param {object} library The @conduction/nextcloud-vue exports.
 * @param {object} [library.CnExternalRegisterCard] The library card, when it ships one.
 * @return {object} The descriptor.
 */
export function externalRegisterDescriptor({ CnExternalRegisterCard } = {}) {
	const card = CnExternalRegisterCard ?? ExternalRegisterCard
	return {
		id: 'external-register',
		label: t('openregister', 'External register'),
		icon: 'DatabaseOutline',
		group: 'data',
		tab: card,
		widget: card,
		defaultSize: { w: 4, h: 3 },
	}
}

/**
 * Register the external register integration into the registry.
 *
 * Registers at once with OpenRegister's own card. It used to import the
 * @conduction/nextcloud-vue barrel first to look for a library card, and that
 * dynamic import fetched a second ~8.6 MB copy of the whole library on every
 * Nextcloud page (this runs from the global script). No library release ships
 * `CnExternalRegisterCard`; when one does, pass it to
 * {@link externalRegisterDescriptor} from a bundle that already holds the library.
 *
 * @spec openspec/changes/external-register-view-leaf/tasks.md#2-leaf
 * @param {object} registry The shared integration registry.
 * @return {void}
 */
export function registerExternalRegisterIntegration(registry) {
	if (!registry || typeof registry.register !== 'function') {
		return
	}

	if (registry.has?.('external-register')) {
		return
	}

	registry.register(externalRegisterDescriptor())
}
