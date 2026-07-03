<template>
	<div class="qualitySelector">
		<NcSelect
			v-model="registerModel"
			class="qualitySelectorField"
			:options="registerOptions"
			:input-label="t('openregister', 'Register')"
			:placeholder="t('openregister', 'Select a register')"
			:clearable="false"
			label="label"
			@input="handleRegisterChange" />
		<NcSelect
			v-model="schemaModel"
			class="qualitySelectorField"
			:options="schemaOptions"
			:input-label="t('openregister', 'Schema')"
			:placeholder="t('openregister', 'Select a schema')"
			:disabled="!registerModel"
			:clearable="false"
			label="label"
			@input="handleSchemaChange" />
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcSelect } from '@nextcloud/vue'
import { qualityStore } from '../../store/store.js'

/**
 * Shared register-then-schema selector for the four MDM "Data quality"
 * views. Holds no local selection state of its own — the selected pair is
 * committed to the `quality` Pinia store so switching between MDM views
 * preserves the selection (design.md D3).
 */
export default {
	name: 'RegisterSchemaSelector',

	components: {
		NcSelect,
	},

	data() {
		return {
			registerModel: null,
			schemaModel: null,
		}
	},

	computed: {
		/**
		 * Expose the l10n translate helper to the template.
		 *
		 * @spec exclude UI plumbing — template translation helper
		 * @return {Function}
		 */
		t() {
			return t
		},

		/**
		 * @spec exclude UI plumbing — maps store registers to NcSelect options
		 * @return {Array<object>}
		 */
		registerOptions() {
			return (qualityStore.registers || []).map((register) => ({
				id: register.id,
				label: register.title || register.name || String(register.id),
			}))
		},

		/**
		 * @spec exclude UI plumbing — maps store schemas to NcSelect options
		 * @return {Array<object>}
		 */
		schemaOptions() {
			return (qualityStore.schemas || []).map((schema) => ({
				id: schema.id,
				label: schema.title || schema.name || String(schema.id),
			}))
		},
	},

	/**
	 * @spec openspec/changes/mdm-frontend/specs/mdm-frontend/spec.md#selection-persists-across-mdm-views
	 */
	async mounted() {
		await qualityStore.fetchRegisters()
		if (qualityStore.selectedRegister) {
			this.registerModel = this.registerOptions.find((o) => String(o.id) === String(qualityStore.selectedRegister)) || null
			await qualityStore.fetchSchemasForRegister(qualityStore.selectedRegister)
			if (qualityStore.selectedSchema) {
				this.schemaModel = this.schemaOptions.find((o) => String(o.id) === String(qualityStore.selectedSchema)) || null
			}
		}
	},

	methods: {
		/**
		 * Handle a register selection change: reset the schema selection
		 * (no data request is issued until both are chosen again) and load
		 * that register's schemas.
		 *
		 * @param {object|null} option Selected NcSelect option.
		 * @spec exclude UI event handler — drives selector state, no backend contract of its own
		 */
		async handleRegisterChange(option) {
			this.schemaModel = null
			qualityStore.setSelection(option?.id ?? null, null)
			if (option?.id) {
				await qualityStore.fetchSchemasForRegister(option.id)
			} else {
				qualityStore.schemas = []
			}
		},

		/**
		 * Handle a schema selection change — commits the full
		 * (register, schema) pair to the shared store.
		 *
		 * @param {object|null} option Selected NcSelect option.
		 * @spec exclude UI event handler — drives selector state, no backend contract of its own
		 */
		handleSchemaChange(option) {
			qualityStore.setSelection(this.registerModel?.id ?? null, option?.id ?? null)
		},
	},
}
</script>

<style scoped>
.qualitySelector {
	display: flex;
	gap: 12px;
	align-items: flex-end;
	flex-wrap: wrap;
	margin-bottom: 16px;
}

.qualitySelectorField {
	min-width: 240px;
}
</style>
