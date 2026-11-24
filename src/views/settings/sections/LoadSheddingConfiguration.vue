<!--
  Load shedding: the dependency breaker thresholds, the database-pressure
  thresholds, and the switch that turns database-pressure shedding off.
  Dependency breakers cannot be switched off, only tuned (design D-8).

  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
-->
<template>
	<div class="load-shedding-settings">
		<h3>{{ t('openregister', 'Load shedding') }}</h3>
		<p class="load-shedding-settings__intro">
			{{
				t(
					'openregister',
					'A dependency that keeps failing is refused at once until a single trial call succeeds. Under database pressure, heavy requests such as exports are refused first.',
				)
			}}
		</p>

		<p v-if="unreadable" class="load-shedding-settings__error" role="alert">
			{{ t('openregister', 'The load shedding settings could not be read.') }}
		</p>

		<template v-else>
			<NcCheckboxRadioSwitch
				:modelValue="values.pressureEnabled !== false"
				type="switch"
				:disabled="loading || saving"
				@update:modelValue="values.pressureEnabled = $event">
				{{ t('openregister', 'Refuse heavy requests under database pressure') }}
			</NcCheckboxRadioSwitch>

			<fieldset
				v-for="group in groups"
				:key="group.id"
				class="load-shedding-settings__group">
				<legend>{{ group.label }}</legend>
				<NcTextField
					v-for="field in group.fields"
					:key="field.name"
					:modelValue="String(values[field.name] ?? '')"
					type="number"
					:min="boundsOf(field.name).min"
					:max="boundsOf(field.name).max"
					:label="field.label"
					:helperText="helperOf(field.name)"
					:error="invalid.includes(field.name)"
					:disabled="loading || saving"
					@update:modelValue="values[field.name] = $event" />
			</fieldset>

			<NcButton variant="primary" :disabled="loading || saving" @click="save">
				{{
					saving
						? t('openregister', 'Saving…')
						: t('openregister', 'Save')
				}}
			</NcButton>
		</template>
	</div>
</template>

<script>
import { t } from '@nextcloud/l10n'
import { NcButton, NcCheckboxRadioSwitch, NcTextField } from '@nextcloud/vue'
import { useSettingsStore } from '../../../store/settings.js'

export default {
	name: 'LoadSheddingConfiguration',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcTextField,
	},

	setup() {
		return { settingsStore: useSettingsStore() }
	},

	data() {
		return {
			loading: true,
			unreadable: false,
			values: {},
			defaults: {},
			bounds: {},
			// The fields the reader must correct before anything is sent.
			invalid: [],
		}
	},

	computed: {
		/**
		 * @spec exclude UI plumbing — saving flag passthrough
		 * @return {boolean} Whether a save is in flight.
		 */
		saving() {
			return this.settingsStore.saving
		},

		/**
		 * The numeric fields, in two groups, with the words a reader sees.
		 *
		 * @spec exclude Client-side form layout.
		 * @return {Array<object>} `{ id, label, fields: [{ name, label }] }` per group.
		 */
		groups() {
			return [
				{
					id: 'breaker',
					label: t('openregister', 'Dependency breakers'),
					fields: [
						{ name: 'breakerMinCalls', label: t('openregister', 'Calls a breaker needs before it can open') },
						{ name: 'breakerMinFailures', label: t('openregister', 'Failures a breaker needs before it can open') },
						{ name: 'breakerFailurePercent', label: t('openregister', 'Share of failed calls that opens a breaker (%)') },
						{ name: 'breakerCoolDown', label: t('openregister', 'First wait before a trial call (seconds)') },
						{ name: 'breakerMaxCoolDown', label: t('openregister', 'Longest wait before a trial call (seconds)') },
					],
				},
				{
					id: 'pressure',
					label: t('openregister', 'Database pressure'),
					fields: [
						{ name: 'pressureMinRequests', label: t('openregister', 'Requests needed before pressure is judged') },
						{ name: 'pressureSlowMs', label: t('openregister', 'A request counts as slow after (milliseconds)') },
						{ name: 'pressureSlowPercent', label: t('openregister', 'Share of slow requests that means pressure (%)') },
						{ name: 'pressureErrorPercent', label: t('openregister', 'Share of database errors that means pressure (%)') },
					],
				},
			]
		},
	},

	async mounted() {
		const loaded = await this.settingsStore.loadLoadSheddingSettings()
		if (loaded === undefined) {
			this.unreadable = true
		} else {
			this.values = { ...loaded.values }
			this.defaults = loaded.defaults
			this.bounds = loaded.bounds
		}
		this.loading = false
	},

	methods: {
		t,

		/**
		 * A field's bounds, open-ended when the server sent none.
		 *
		 * @spec exclude UI plumbing, reads the bounds the server answered
		 * @param {string} name The field name.
		 * @return {{min: number, max: number}} The bounds.
		 */
		boundsOf(name) {
			return this.bounds[name] || { min: 1, max: Number.MAX_SAFE_INTEGER }
		},

		/**
		 * The line under a field: its default, or what to enter when it is wrong.
		 *
		 * @spec exclude UI plumbing, words a field's hint
		 * @param {string} name The field name.
		 * @return {string} The hint.
		 */
		helperOf(name) {
			if (this.invalid.includes(name)) {
				const { min, max } = this.boundsOf(name)
				return t('openregister', 'Must be a whole number from {min} to {max}.', { min, max })
			}

			return this.defaults[name] === undefined
				? ''
				: t('openregister', 'Default: {value}', { value: this.defaults[name] })
		},

		/**
		 * The fields that are not whole numbers within their bounds.
		 *
		 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
		 * @return {Array<string>} The field names.
		 */
		invalidFields() {
			return this.groups
				.flatMap((group) => group.fields.map((field) => field.name))
				.filter((name) => {
					const value = Number(this.values[name])
					const { min, max } = this.boundsOf(name)
					return String(this.values[name] ?? '').trim() === ''
						|| Number.isInteger(value) === false
						|| value < min
						|| value > max
				})
		},

		/**
		 * Check the form, then store every field as a number.
		 *
		 * Nothing is sent while a field is wrong, so the server never stores
		 * half a form.
		 *
		 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
		 * @return {Promise<void>}
		 */
		async save() {
			this.invalid = this.invalidFields()
			if (this.invalid.length > 0) {
				return
			}

			const payload = { pressureEnabled: this.values.pressureEnabled !== false }
			for (const group of this.groups) {
				for (const field of group.fields) {
					payload[field.name] = Number(this.values[field.name])
				}
			}

			try {
				const stored = await this.settingsStore.updateLoadSheddingSettings(payload)
				if (stored && stored.values) {
					this.values = { ...stored.values }
				}
			} catch {
				// The store has already shown the server's refusal; the form keeps what was typed.
			}
		},
	},
}
</script>

<style scoped>
.load-shedding-settings__intro {
	color: var(--color-text-maxcontrast);
	margin-block: 4px 12px;
}

.load-shedding-settings__error {
	color: var(--color-error-text);
}

.load-shedding-settings__group {
	display: flex;
	flex-direction: column;
	gap: 8px;
	max-width: 480px;
	margin-block: 12px;
	border: none;
	padding: 0;
}

.load-shedding-settings__group legend {
	font-weight: bold;
	margin-block-end: 4px;
}

.load-shedding-settings > * {
	margin-block-end: 12px;
}
</style>
