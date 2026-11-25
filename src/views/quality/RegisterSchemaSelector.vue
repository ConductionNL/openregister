<template>
	<NcNoteCard
		v-if="unknownRegister"
		type="warning"
		data-testid="mdm-unknown-register"
		:heading="t('openregister', 'Unknown Register')">
		<code>{{ unknownRegister }}</code> — {{ t('openregister', 'Choose a register') }}
	</NcNoteCard>
	<NcNoteCard
		v-if="unknownSchema"
		type="warning"
		data-testid="mdm-unknown-schema"
		:heading="t('openregister', 'Unknown Schema')">
		<code>{{ unknownSchema }}</code> — {{ t('openregister', 'Choose a schema') }}
	</NcNoteCard>
	<div class="qualitySelector">
		<NcSelect
			v-model="registerModel"
			class="qualitySelectorField"
			data-testid="mdm-register-select"
			:options="registerOptions"
			:inputLabel="t('openregister', 'Register')"
			:placeholder="t('openregister', 'Select a register')"
			:clearable="false"
			label="label"
			@update:modelValue="handleRegisterChange" />
		<NcSelect
			v-model="schemaModel"
			class="qualitySelectorField"
			data-testid="mdm-schema-select"
			:options="schemaOptions"
			:inputLabel="t('openregister', 'Schema')"
			:placeholder="t('openregister', 'Select a schema')"
			:disabled="!registerModel"
			:clearable="false"
			label="label"
			@update:modelValue="handleSchemaChange" />
	</div>
</template>

<script>
import { NcNoteCard, NcSelect } from '@nextcloud/vue'
import { qualityStore } from '../../store/store.js'

/**
 * The register or schema a deep-link value names: an id, a uuid or a slug.
 *
 * @param {Array<object>|null} items The loaded registers or schemas.
 * @param {string|number} value The route query value.
 * @return {object|null} The match, or null when nothing matches.
 * @spec openspec/changes/mdm-merge-relinks-every-reference/specs/mdm-merge/spec.md#requirement-the-duplicates-page-opens-on-a-register-and-schema-from-a-link
 */
function findByIdOrSlug(items, value) {
	const wanted = String(value)
	return (
		(items || []).find((item) => String(item.id) === wanted)
		|| (items || []).find((item) => item.uuid === wanted || item.slug === wanted)
		|| null
	)
}

/**
 * Shared register-then-schema selector for the four MDM "Data quality"
 * views. Holds no local selection state of its own — the selected pair is
 * committed to the `quality` Pinia store so switching between MDM views
 * preserves the selection (design.md D3).
 *
 * The selection is also mirrored into the route query
 * (`?register=&schema=`): a deep-link such as
 * `/quality?register=16&schema=1207` pre-selects and loads with no clicks,
 * and so does `/duplicates?register=catalogus&schema=module`: each value may
 * be an id or a slug, and one that matches nothing shows a notice instead of
 * a guess. Every in-UI selection change reflects back into the URL so the view is
 * bookmarkable and shareable (mdm-views-route-scoping). The store remains the
 * source of truth; the route is a mirror + entry point.
 */
export default {
	name: 'RegisterSchemaSelector',

	components: {
		NcNoteCard,
		NcSelect,
	},

	data() {
		return {
			registerModel: null,
			schemaModel: null,
			// The `?register=` / `?schema=` value of a deep link that matched
			// nothing, shown as a notice above the picker; null otherwise.
			unknownRegister: null,
			unknownSchema: null,
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
	 * @spec openspec/specs/mdm-views-route-scoping/spec.md#scenario-deep-link-preselects-register-and-schema
	 * @spec openspec/specs/mdm-views-route-scoping/spec.md#scenario-route-query-takes-precedence-over-stored-selection
	 * @spec openspec/specs/mdm-frontend/spec.md
	 * @spec openspec/changes/mdm-merge-relinks-every-reference/specs/mdm-merge/spec.md#scenario-an-app-sends-the-steward-to-the-duplicates-of-one-schema
	 */
	async mounted() {
		await qualityStore.fetchRegisters()

		const routeRegister = this.$route?.query?.register ?? null
		const routeSchema = this.$route?.query?.schema ?? null

		if (routeRegister) {
			await this.selectFromRoute(routeRegister, routeSchema)
			return
		}

		if (qualityStore.selectedRegister) {
			this.registerModel =
				this.registerOptions.find(
					(o) => String(o.id) === String(qualityStore.selectedRegister),
				) || null
			await qualityStore.fetchSchemasForRegister(qualityStore.selectedRegister)
			if (qualityStore.selectedSchema) {
				this.schemaModel =
					this.schemaOptions.find(
						(o) => String(o.id) === String(qualityStore.selectedSchema),
					) || null
			}
			this.syncRoute(
				qualityStore.selectedRegister,
				qualityStore.selectedSchema,
			)
		}
	},

	methods: {
		/**
		 * Select the register and schema a deep link names, by id or slug.
		 *
		 * Each value is resolved against the loaded registers, then that
		 * register's schemas, and the selection is committed with their ids.
		 * A value that matches nothing is never guessed at: an unknown
		 * register clears the selection, an unknown schema leaves only the
		 * register selected, and either shows a notice naming the value so
		 * the steward can choose from the picker.
		 *
		 * @param {string} routeRegister The `?register=` value: an id or slug.
		 * @param {string|null} routeSchema The `?schema=` value: an id or slug.
		 * @return {Promise<void>}
		 * @spec openspec/changes/mdm-merge-relinks-every-reference/specs/mdm-merge/spec.md#requirement-the-duplicates-page-opens-on-a-register-and-schema-from-a-link
		 */
		async selectFromRoute(routeRegister, routeSchema) {
			const register = findByIdOrSlug(qualityStore.registers, routeRegister)
			if (!register) {
				this.unknownRegister = String(routeRegister)
				qualityStore.setSelection(null, null)
				return
			}
			this.registerModel =
				this.registerOptions.find((o) => String(o.id) === String(register.id)) || null
			qualityStore.setSelection(register.id, null)
			await qualityStore.fetchSchemasForRegister(register.id)
			if (!routeSchema) {
				return
			}
			const schema = findByIdOrSlug(qualityStore.schemas, routeSchema)
			if (!schema) {
				this.unknownSchema = String(routeSchema)
				return
			}
			this.schemaModel =
				this.schemaOptions.find((o) => String(o.id) === String(schema.id)) || null
			qualityStore.setSelection(register.id, schema.id)
		},

		/**
		 * Handle a register selection change: reset the schema selection
		 * (no data request is issued until both are chosen again), load that
		 * register's schemas, and mirror the (register, no-schema) pair into
		 * the URL.
		 *
		 * @param {object|null} option Selected NcSelect option.
		 * @spec openspec/specs/mdm-views-route-scoping/spec.md#scenario-changing-the-register-resets-the-schema-in-the-url
		 */
		async handleRegisterChange(option) {
			this.schemaModel = null
			this.unknownRegister = null
			this.unknownSchema = null
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
		 * @spec openspec/specs/mdm-views-route-scoping/spec.md#scenario-selecting-a-register-and-schema-updates-the-url
		 */
		handleSchemaChange(option) {
			this.unknownSchema = null
			qualityStore.setSelection(
				this.registerModel?.id ?? null,
				option?.id ?? null,
			)
			this.syncRoute(this.registerModel?.id ?? null, option?.id ?? null)
		},

		/**
		 * Mirror the current (register, schema) selection into the hash-mode
		 * route query. A NavigationDuplicated rejection (the query is already
		 * current) is swallowed.
		 *
		 * @param {string|number|null} register Selected register id.
		 * @param {string|number|null} schema   Selected schema id.
		 * @spec openspec/specs/mdm-views-route-scoping/spec.md#scenario-selecting-a-register-and-schema-updates-the-url
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
