/**
 * OpenProject Integration Registration
 *
 * Registers the OpenProject integration with the frontend integration registry.
 * Paired with the backend OpenProjectProvider (lib/Service/Integration/Providers/OpenProjectProvider.php)
 * via the shared id 'openproject'.
 *
 * @spec openspec/changes/integration-openproject/tasks.md#task-7
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

import CnOpenProjectTab from '../CnOpenProjectTab.vue'
import CnOpenProjectCard from '../CnOpenProjectCard.vue'

/**
 * OpenProject integration descriptor.
 *
 * Registered with OCA.OpenRegister.integrations.register() when the registry
 * is available. Backend and frontend are paired by the 'openproject' id.
 *
 * @type {object}
 */
const openProjectIntegration = {
	/**
	 * Unique id — must match OpenProjectProvider::getId() on the backend.
	 */
	id: 'openproject',

	/**
	 * Display label shown in sidebars and admin UI.
	 */
	label: 'Projects',

	/**
	 * Icon name for UI rendering.
	 */
	icon: 'Briefcase',

	/**
	 * Integration group — external services go through OpenConnector.
	 */
	group: 'external',

	/**
	 * Reference type used when a schema property has referenceType: 'openproject'.
	 * Causes CnFormDialog / CnDetailGrid to render CnOpenProjectCard with surface='single-entity'.
	 */
	referenceType: 'openproject',

	/**
	 * Tab component rendered in the object sidebar.
	 *
	 * @type {object}
	 */
	tab: CnOpenProjectTab,

	/**
	 * Widget component rendered on dashboards and detail pages.
	 *
	 * @type {object}
	 */
	widget: CnOpenProjectCard,
}

/**
 * Register with the OpenRegister integration registry if available.
 *
 * Falls back gracefully if the registry hasn't been initialised yet.
 * Consuming apps (Procest, Pipelinq, etc.) call registerAll() on their
 * integration modules to ensure all integrations are registered before
 * rendering.
 */
function register() {
	if (typeof window !== 'undefined'
		&& window.OCA
		&& window.OCA.OpenRegister
		&& window.OCA.OpenRegister.integrations
		&& typeof window.OCA.OpenRegister.integrations.register === 'function'
	) {
		window.OCA.OpenRegister.integrations.register(openProjectIntegration)
	}
}

export { openProjectIntegration, register }
export default openProjectIntegration
