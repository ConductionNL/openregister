import { registerStore, schemaStore } from '../store/store.js'
import { registerSelect, schemaSelect, scopeOption } from './scopeSelect.js'

/**
 * A mixin with the register and schema pickers of a list sidebar, read from
 * the stores.
 *
 * @spec openspec/changes/retrofit-2026-05-25-fe-sidebars/tasks.md#task-11
 */
export const scopePickers = {
	computed: {
		/**
		 * Build the register dropdown options.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-fe-sidebars/tasks.md#task-11
		 * @return {object} NcSelect options bag for registers
		 */
		registerOptions() {
			return registerSelect(registerStore.registerList)
		},

		/**
		 * Build the schema dropdown options scoped to the selected register.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-fe-sidebars/tasks.md#task-11
		 * @return {object} NcSelect options bag for schemas
		 */
		schemaOptions() {
			return schemaSelect(registerStore.registerItem, schemaStore.schemaList)
		},

		/**
		 * Resolve the currently-selected register into NcSelect value shape.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-fe-sidebars/tasks.md#task-11
		 * @return {object|null} Selected register option, or null
		 */
		selectedRegisterValue() {
			return scopeOption(registerStore.registerItem, 'register')
		},

		/**
		 * Resolve the currently-selected schema into NcSelect value shape.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-fe-sidebars/tasks.md#task-11
		 * @return {object|null} Selected schema option, or null
		 */
		selectedSchemaValue() {
			return scopeOption(schemaStore.schemaItem, 'schema')
		},

		/**
		 * Whether a register is picked, so the schema picker can be used.
		 *
		 * @spec exclude whether a register is picked, UI plumbing
		 * @return {boolean} True when a register is picked
		 */
		hasRegister() {
			return !!registerStore.registerItem
		},
	},
}
