// SPDX-License-Identifier: EUPL-1.2
/**
 * Time Tracker integration registration.
 *
 * Registers the 'time-tracker' integration with the OR integration registry
 * so that CnObjectSidebar, CnDashboardPage, and CnDetailPage can render the
 * tab and widget surfaces automatically (ADR-019).
 *
 * The `referenceType: 'time-tracker'` declaration causes CnFormDialog and
 * CnDetailGrid to render the single-entity hours chip inline next to any
 * schema property that carries `referenceType: 'time-tracker'`.
 */

import CnTimeTab from './CnTimeTab.vue'
import CnTimeCard from './CnTimeCard.vue'

/**
 * Register the time-tracker integration with the OR registry.
 *
 * Called from the app's main entry point once OCA.OpenRegister.integrations
 * is available.
 *
 * @param {object} registry - The OCA.OpenRegister.integrations registry.
 */
export function registerTimeTracker(registry) {
	registry.register({
		id: 'time-tracker',
		label: 'Time',
		icon: 'Clock',
		group: 'workflow',
		referenceType: 'time-tracker',
		tab: CnTimeTab,
		widget: CnTimeCard,
	})
}

export { CnTimeTab, CnTimeCard }
