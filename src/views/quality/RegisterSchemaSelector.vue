<template>
	<div class="qualitySelector">
		<NcSelect
			v-model="registerModel"
			class="qualitySelectorField"
			data-testid="mdm-register-select"
			:options="registerOptions"
			:input-label="t('openregister', 'Register')"
			:placeholder="t('openregister', 'Select a register')"
			:clearable="false"
			label="label"
			@input="handleRegisterChange" />
		<NcSelect
			v-model="schemaModel"
			class="qualitySelectorField"
			data-testid="mdm-schema-select"
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
import { NcSelect } from '@nextcloud/vue'
import { qualityStore } from '../../store/store.js'

/**
 * Shared register-then-schema selector for the four MDM "Data quality"
 * views. Holds no local selection state of its own — the selected pair is
 * committed to the `quality` Pinia store so switching between MDM views
 * preserves the selection (design.md D3).
 *
 * The selection is also mirrored into the hash-mode route query
 * (`?register=&schema=`): a deep-link such as
 * `#/quality?register=16&schema=1207` pre-selects and loads with no clicks,
 * and every in-UI selection change reflects back into the URL so the view is
 * bookmarkable and shareable (mdm-views-route-scoping). The store remains the
 * source of truth; the route is a mirror + entry point.
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
	 * On mount, a `?register=` (and optional `?schema=`) route query wins over
	 * the persisted store selection so a deep-link auto-selects and loads with
	 * no clicks; otherwise the shared store selection is restored (and mirrored
	 * back into the URL so a reload from that point is itself a deep-link).
	 *
	 * @spec openspec/changes/mdm-views-route-scoping-e2e/specs/mdm-views-route-scoping/spec.md#scenario-deep-link-preselects-register-and-schema
	 * @spec openspec/changes/mdm-views-route-scoping-e2e/specs/mdm-views-route-scoping/spec.md#scenario-route-query-takes-precedence-over-stored-selection
	 * @spec openspec/specs/mdm-frontend/spec.md
	 */
	async mounted() {
		await qualityStore.fetchRegisters()

		const routeRegister = this.$route?.query?.register ?? null
		const routeSchema = this.$route?.query?.schema ?? null

		if (routeRegister) {
			this.registerModel = this.registerOptions.find((o) => String(o.id) === String(routeRegister)) || null
			qualityStore.setSelection(routeRegister, routeSchema || null)
			await qualityStore.fetchSchemasForRegister(routeRegister)
			if (routeSchema) {
				this.schemaModel = this.schemaOptions.find((o) => String(o.id) === String(routeSchema)) || null
			}
			return
		}

		if (qualityStore.selectedRegister) {
			this.registerModel = this.registerOptions.find((o) => String(o.id) === String(qualityStore.selectedRegister)) || null
			await qualityStore.fetchSchemasForRegister(qualityStore.selectedRegister)
			if (qualityStore.selectedSchema) {
				this.schemaModel = this.schemaOptions.find((o) => String(o.id) === String(qualityStore.selectedSchema)) || null
			}
			this.syncRoute(qualityStore.selectedRegister, qualityStore.selectedSchema)
		}
	},

	methods: {
		/**
		 * Handle a register selection change: reset the schema selection
		 * (no data request is issued until both are chosen again), load that
		 * register's schemas, and mirror the (register, no-schema) pair into
		 * the URL.
		 *
		 * @param {object|null} option Selected NcSelect option.
		 * @spec openspec/changes/mdm-views-route-scoping-e2e/specs/mdm-views-route-scoping/spec.md#scenario-changing-the-register-resets-the-schema-in-the-url
		 */
		async handleRegisterChange(option) {
			this.schemaModel = null
			qualityStore.setSelection(option?.id ?? null, null)
			this.syncRoute(option?.id ?? null, null)
			if (option?.id) {
				await qualityStore.fetchSchemasForRegister(option.id)
			} else {
				qualityStore.schemas = []
			}
		},

		/**
		 * Handle a schema selection change — commits the full (register,
		 * schema) pair to the shared store and mirrors it into the URL.
		 *
		 * @param {object|null} option Selected NcSelect option.
		 * @spec openspec/changes/mdm-views-route-scoping-e2e/specs/mdm-views-route-scoping/spec.md#scenario-selecting-a-register-and-schema-updates-the-url
		 */
		handleSchemaChange(option) {
			qualityStore.setSelection(this.registerModel?.id ?? null, option?.id ?? null)
			this.syncRoute(this.registerModel?.id ?? null, option?.id ?? null)
		},

		/**
		 * Mirror the current (register, schema) selection into the hash-mode
		 * route query. A NavigationDuplicated rejection (the query is already
		 * current) is swallowed.
		 *
		 * @param {string|number|null} register Selected register id.
		 * @param {string|number|null} schema   Selected schema id.
		 * @spec openspec/changes/mdm-views-route-scoping-e2e/specs/mdm-views-route-scoping/spec.md#scenario-selecting-a-register-and-schema-updates-the-url
		 */
		syncRoute(register, schema) {
			const query = {}
			if (register) {
				query.register = String(register)
			}
			if (schema) {
				query.schema = String(schema)
			}
			this.$router.replace({ query }).catch(() => {})
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
