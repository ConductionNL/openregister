<script setup>
import { translate as t } from '@nextcloud/l10n'
</script>

<template>
	<div class="calendarProviderTab">
		<h3>{{ t('openregister', 'Calendar Provider Configuration') }}</h3>
		<p class="description">
			{{ t('openregister', 'Configure this schema to surface objects as events in the Nextcloud Calendar app.') }}
		</p>

		<!-- Enable toggle -->
		<div class="fieldRow">
			<NcCheckboxRadioSwitch
				:checked="localConfig.enabled"
				type="switch"
				@update:checked="localConfig.enabled = $event">
				{{ t('openregister', 'Enable calendar provider') }}
			</NcCheckboxRadioSwitch>
		</div>

		<template v-if="localConfig.enabled">
			<!-- Display name -->
			<div class="fieldRow">
				<label for="cal-displayName">{{ t('openregister', 'Display Name') }}</label>
				<NcTextField
					id="cal-displayName"
					:value.sync="localConfig.displayName"
					:placeholder="schema?.title || t('openregister', 'Calendar name')"
					:label-outside="true" />
			</div>

			<!-- Color picker -->
			<div class="fieldRow">
				<label for="cal-color">{{ t('openregister', 'Color') }}</label>
				<NcColorPicker v-model="localConfig.color">
					<NcButton>
						<template #icon>
							<CircleIcon :size="20" :fill-color="localConfig.color || '#0082C9'" />
						</template>
						{{ localConfig.color || '#0082C9' }}
					</NcButton>
				</NcColorPicker>
			</div>

			<!-- DTSTART field -->
			<div class="fieldRow">
				<label for="cal-dtstart">{{ t('openregister', 'Start Date Field') }} *</label>
				<NcSelect
					id="cal-dtstart"
					v-model="localConfig.dtstart"
					:options="datePropertyOptions"
					:placeholder="t('openregister', 'Select a date property')" />
			</div>

			<!-- DTEND field -->
			<div class="fieldRow">
				<label for="cal-dtend">{{ t('openregister', 'End Date Field') }}</label>
				<NcSelect
					id="cal-dtend"
					v-model="localConfig.dtend"
					:options="datePropertyOptions"
					:placeholder="t('openregister', 'Optional end date property')" />
			</div>

			<!-- Title template -->
			<div class="fieldRow">
				<label for="cal-title">{{ t('openregister', 'Title Template') }} *</label>
				<NcTextField
					id="cal-title"
					:value.sync="localConfig.titleTemplate"
					:placeholder="t('openregister', '{property} - {other}')" />
				<small class="hint">
					{{ t('openregister', 'Available placeholders:') }}
					<span v-for="prop in propertyNames" :key="prop" class="placeholder">
						{{ '{' + prop + '}' }}
					</span>
				</small>
			</div>

			<!-- Description template -->
			<div class="fieldRow">
				<label for="cal-desc">{{ t('openregister', 'Description Template') }}</label>
				<textarea
					id="cal-desc"
					v-model="localConfig.descriptionTemplate"
					class="ncTextarea"
					rows="3"
					:placeholder="t('openregister', 'Optional event description template')" />
			</div>

			<!-- Location field -->
			<div class="fieldRow">
				<label for="cal-location">{{ t('openregister', 'Location Field') }}</label>
				<NcSelect
					id="cal-location"
					v-model="localConfig.locationField"
					:options="stringPropertyOptions"
					:placeholder="t('openregister', 'Optional location property')" />
			</div>

			<!-- All day toggle -->
			<div class="fieldRow">
				<NcCheckboxRadioSwitch
					:checked="localConfig.allDay"
					:indeterminate="localConfig.allDay === null || localConfig.allDay === undefined"
					type="switch"
					@update:checked="localConfig.allDay = $event">
					{{ t('openregister', 'All-day events') }}
				</NcCheckboxRadioSwitch>
				<small class="hint">
					{{ t('openregister', 'Leave off for auto-detection from property format.') }}
				</small>
			</div>

			<!-- Save button -->
			<div class="fieldRow actions">
				<NcButton
					type="primary"
					:disabled="!isValid || saving"
					@click="save">
					<template #icon>
						<NcLoadingIcon v-if="saving" :size="20" />
						<ContentSave v-else :size="20" />
					</template>
					{{ t('openregister', 'Save') }}
				</NcButton>
			</div>
		</template>
	</div>
</template>

<script>
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcColorPicker,
	NcLoadingIcon,
	NcSelect,
	NcTextField,
} from '@nextcloud/vue'
import CircleIcon from 'vue-material-design-icons/Circle.vue'
import ContentSave from 'vue-material-design-icons/ContentSave.vue'
import { schemaStore } from '../../store/store.js'

export default {
	name: 'CalendarProviderTab',
	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcColorPicker,
		NcLoadingIcon,
		NcSelect,
		NcTextField,
		CircleIcon,
		ContentSave,
	},
	props: {
		schema: {
			type: Object,
			required: true,
		},
	},
	data() {
		return {
			saving: false,
			localConfig: {
				enabled: false,
				displayName: '',
				color: '#0082C9',
				dtstart: null,
				dtend: null,
				titleTemplate: '',
				descriptionTemplate: '',
				locationField: null,
				allDay: null,
			},
		}
	},
	computed: {
		/**
		 * Property names available for placeholders
		 * @return {string[]}
		 */
		propertyNames() {
			if (!this.schema?.properties) {
				return []
			}
			return Object.keys(this.schema.properties)
		},
		/**
		 * Date/datetime properties for dtstart/dtend selectors
		 * @return {string[]}
		 */
		datePropertyOptions() {
			if (!this.schema?.properties) {
				return []
			}
			return Object.entries(this.schema.properties)
				.filter(([, def]) => {
					const format = def?.format || ''
					const type = def?.type || ''
					return format === 'date' || format === 'date-time' || type === 'date'
				})
				.map(([key]) => key)
		},
		/**
		 * String properties for location selector
		 * @return {string[]}
		 */
		stringPropertyOptions() {
			if (!this.schema?.properties) {
				return []
			}
			return Object.entries(this.schema.properties)
				.filter(([, def]) => def?.type === 'string')
				.map(([key]) => key)
		},
		/**
		 * Validation: dtstart and titleTemplate required when enabled
		 * @return {boolean}
		 */
		isValid() {
			if (!this.localConfig.enabled) {
				return true
			}
			return !!this.localConfig.dtstart && !!this.localConfig.titleTemplate
		},
	},
	watch: {
		schema: {
			handler(newSchema) {
				if (newSchema) {
					this.loadConfig(newSchema)
				}
			},
			immediate: true,
		},
	},
	methods: {
		/**
		 * Load calendar provider config from schema configuration
		 * @param {object} schema The schema object
		 */
		loadConfig(schema) {
			const config = schema?.configuration?.calendarProvider || {}
			this.localConfig = {
				enabled: config.enabled || false,
				displayName: config.displayName || '',
				color: config.color || '#0082C9',
				dtstart: config.dtstart || null,
				dtend: config.dtend || null,
				titleTemplate: config.titleTemplate || '',
				descriptionTemplate: config.descriptionTemplate || '',
				locationField: config.locationField || null,
				allDay: config.allDay ?? null,
			}
		},
		/**
		 * Save the calendar provider configuration via schema update
		 */
		async save() {
			this.saving = true
			try {
				const updatedSchema = {
					...this.schema,
					configuration: {
						...(this.schema.configuration || {}),
						calendarProvider: { ...this.localConfig },
					},
				}
				await schemaStore.saveSchema(updatedSchema)
			} catch (error) {
				console.error('Failed to save calendar provider config:', error)
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style lang="scss" scoped>
.calendarProviderTab {
	padding: 20px;
	max-width: 700px;

	.description {
		color: var(--color-text-maxcontrast);
		margin-bottom: 20px;
	}

	.fieldRow {
		margin-bottom: 16px;

		label {
			display: block;
			font-weight: bold;
			margin-bottom: 4px;
		}

		&.actions {
			margin-top: 24px;
		}
	}

	.hint {
		display: block;
		margin-top: 4px;
		color: var(--color-text-maxcontrast);
		font-size: 0.9em;
	}

	.placeholder {
		display: inline-block;
		background: var(--color-background-dark);
		border-radius: 3px;
		padding: 1px 4px;
		margin: 2px;
		font-family: monospace;
		font-size: 0.85em;
	}

	.ncTextarea {
		width: 100%;
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius);
		padding: 8px;
		font-family: inherit;
		resize: vertical;
	}
}
</style>
