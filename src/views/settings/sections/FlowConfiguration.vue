<!--
  Flow-engine instance policy.

  ADMINISTRATOR settings, deliberately — not personal preferences. How long run
  history is kept, whether every hop is audit-trailed, and whether the oversight
  gate runs are properties of the instance, and a per-user version of them would
  mean the same flow behaved differently depending on who happened to look at it.

  Every value here is a DEFAULT. A flow may override retention, auditing and
  oversight individually; a flow that overrides none of them tracks whatever is
  set here, including later changes to it.

  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<div class="flow-settings">
		<h3>{{ t('openregister', 'Flows') }}</h3>
		<p class="flow-settings__intro">
			{{
				t(
					'openregister',
					'Defaults for every flow on this instance. A flow can override each of these for itself; a flow that overrides none of them follows the values set here, including later changes.',
				)
			}}
		</p>

		<NcTextField
			v-model="form.retentionDays"
			type="number"
			:label="t('openregister', 'Keep run history for (days)')"
			:helperText="
				t(
					'openregister',
					'Runs and their per-step history are deleted once they exceed this age. A flow may keep less than this, or more.',
				)
			" />

		<NcCheckboxRadioSwitch v-model="form.auditEnabled" type="switch">
			{{ t('openregister', 'Write an audit-trail entry for every step') }}
		</NcCheckboxRadioSwitch>
		<p class="flow-settings__hint">
			{{
				t(
					'openregister',
					'Off by default: one entry per step per run is a lot of writes, and the run history already records what each step did. Turn this on where a compliance record of every step is required.',
				)
			}}
		</p>

		<NcCheckboxRadioSwitch v-model="form.oversightEnabled" type="switch">
			{{ t('openregister', 'Run oversight checks before each step') }}
		</NcCheckboxRadioSwitch>
		<p class="flow-settings__hint">
			{{
				t(
					'openregister',
					'On by default. Oversight checks — contributed by apps — can stop a running flow. Turning this off applies to every flow that has not chosen for itself.',
				)
			}}
		</p>

		<NcNoteCard v-if="form.killSwitch" type="warning">
			{{
				t(
					'openregister',
					'The kill switch is on. No flow step will run on this instance until it is turned off.',
				)
			}}
		</NcNoteCard>

		<NcCheckboxRadioSwitch v-model="form.killSwitch" type="switch">
			{{ t('openregister', 'Kill switch — stop all flow execution now') }}
		</NcCheckboxRadioSwitch>
		<p class="flow-settings__hint">
			{{
				t(
					'openregister',
					'Stops every flow mid-run, at its next step. Use this when flows are misbehaving and disabling them one at a time is too slow.',
				)
			}}
		</p>

		<NcButton type="primary" :disabled="saving" @click="save">
			{{
				saving
					? t('openregister', 'Saving...')
					: t('openregister', 'Save flow settings')
			}}
		</NcButton>
	</div>
</template>

<script>
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcNoteCard,
	NcTextField,
} from '@nextcloud/vue'
import { useSettingsStore } from '../../../store/settings.js'

export default {
	name: 'FlowConfiguration',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcNoteCard,
		NcTextField,
	},

	setup() {
		return { settingsStore: useSettingsStore() }
	},

	data() {
		return {
			// A local copy, so an unsaved edit does not change engine behaviour
			// the moment it is typed. The store is only written on save.
			form: {
				retentionDays: 31,
				auditEnabled: false,
				oversightEnabled: true,
				killSwitch: false,
			},
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
	},

	async mounted() {
		await this.settingsStore.loadFlowSettings()
		this.form = { ...this.form, ...this.settingsStore.flowOptions }
	},

	methods: {
		/**
		 * @spec openspec/changes/flow-engine-unification/specs/flow-execution-history/spec.md
		 * @return {Promise<void>}
		 */
		async save() {
			await this.settingsStore.updateFlowSettings({
				...this.form,
				retentionDays: Number(this.form.retentionDays),
			})
		},
	},
}
</script>

<style scoped>
.flow-settings__intro,
.flow-settings__hint {
	color: var(--color-text-maxcontrast);
	margin-block: 4px 12px;
}

.flow-settings__hint {
	font-size: 0.9em;
	margin-block-start: 0;
}

.flow-settings > * {
	margin-block-end: 12px;
}
</style>
