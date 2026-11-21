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
import { t } from '@nextcloud/l10n'
import ExternalRegisterCard from './ExternalRegisterCard.vue'

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

	import('@conduction/nextcloud-vue')
		.then((library) => {
			registry.register(externalRegisterDescriptor(library))
		})
		.catch(() => {
			// The library failed to load: the page renders without this leaf, as without any other.
		})
}
