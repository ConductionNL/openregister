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
// Imported from its own module, not the package barrel (see bookmarks.js).
import { lazyComponent } from '@conduction/nextcloud-vue/dist/esm/utils/lazyComponent.js'
import { t } from '@nextcloud/l10n'

/**
 * OpenRegister's own card, loaded on first render. This file runs from the
 * script Nextcloud loads on every page; a static import of the card put the
 * card and the @nextcloud/vue components it uses (NcButton -> vue-router ->
 * Vue devtools, buffer) into that script.
 */
const ExternalRegisterCard = lazyComponent(
	() => import(/* webpackChunkName: "external-register-card" */ './ExternalRegisterCard.vue'),
	'ExternalRegisterCard',
)

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
