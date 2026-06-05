/**
 * Register the Collectives integration with the OpenRegister integration registry.
 *
 * This file is auto-loaded by the integration bootstrap in main.js when
 * OCA.OpenRegister.integrations is available.
 *
 * @spec openspec/changes/integration-collectives/tasks.md#task-8
 */

import CnCollectivesTab from '../collectives/CnCollectivesTab.vue'
import CnCollectivesCard from '../collectives/CnCollectivesCard.vue'

export default function registerCollectivesIntegration() {
	if (typeof OCA === 'undefined'
		|| typeof OCA.OpenRegister === 'undefined'
		|| typeof OCA.OpenRegister.integrations === 'undefined') {
		return
	}

	OCA.OpenRegister.integrations.register({
		id: 'collectives',
		label: 'Knowledge',
		icon: 'BookOpenPageVariant',
		group: 'docs',
		requiredApp: 'collectives',
		referenceType: 'collectives',

		/** Sidebar tab component */
		tab: CnCollectivesTab,

		/** Widget card component (handles all four surfaces internally) */
		widget: CnCollectivesCard,
	})
}
