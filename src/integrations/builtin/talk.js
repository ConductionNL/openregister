/**
 * Talk integration registration for the OpenRegister integration registry.
 *
 * Registers the 'talk' integration with id, label, icon, group, and
 * referenceType so CnObjectSidebar and dashboard surfaces can discover it.
 * The tab (CnTalkTab) and widget (CnTalkCard) components live in the
 * @conduction/nextcloud-vue package and are resolved by the registry at
 * runtime.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

/**
 * Register the Talk integration with the OpenRegister integration registry.
 *
 * This function is called by the main integrations index once the registry
 * is available on window.OCA.OpenRegister.integrations.
 *
 * @param {object} registry - The OpenRegister integration registry instance.
 * @returns {void}
 */
export function registerTalkIntegration(registry) {
	registry.register({
		id: 'talk',
		label: 'Chat',
		icon: 'ChatOutline',
		group: 'comms',
		referenceType: 'talk',
		requiredApp: 'spreed',
		storageStrategy: 'link-table',
	})
}
