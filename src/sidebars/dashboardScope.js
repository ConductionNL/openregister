/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What the dashboard and registers sidebars share about the dashboard's
 * registers and the register and schema pickers above their statistics.
 *
 * @spec exclude Client-side statistics scope shared by two sidebars.
 */
import {
	dashboardStore,
	objectStore,
	registerStore,
	schemaStore,
} from '../store/store.js'

/**
 * A mixin for a sidebar that shows the dashboard's register statistics.
 */
export const dashboardScope = {
	computed: {
		/**
		 * Registers excluding the synthetic System Totals / Orphaned Items rows.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-fe-sidebars/tasks.md#task-11
		 * @return {Array}
		 */
		filteredRegisters() {
			return dashboardStore.registers.filter(
				(register) =>
					register.title !== 'System Totals'
					&& register.title !== 'Orphaned Items',
			)
		},

		/**
		 * Total schema count across the filtered registers.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-fe-sidebars/tasks.md#task-11
		 * @return {number}
		 */
		totalSchemas() {
			return this.filteredRegisters.reduce((total, register) => {
				return total + (register.schemas?.length || 0)
			}, 0)
		},
	},

	methods: {
		/**
		 * Apply a register selection and reset dependent schema state (cascade).
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-fe-sidebars/tasks.md#task-5
		 * @param {object|null} option - Selected register (or null to clear)
		 * @return {void}
		 */
		handleRegisterChange(option) {
			registerStore.setRegisterItem(option)
			schemaStore.setSchemaItem(null)
		},

		/**
		 * Apply a schema selection, initialise properties, and refresh the object list.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-fe-sidebars/tasks.md#task-5
		 * @param {object|null} option - Selected schema (or null to clear)
		 * @return {void}
		 */
		handleSchemaChange(option) {
			schemaStore.setSchemaItem(option)
			if (option) {
				objectStore.initializeProperties(option)
				objectStore.refreshObjectList()
			}
		},
	},
}
