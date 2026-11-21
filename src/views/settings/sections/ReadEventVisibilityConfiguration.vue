<!--
  Who sees who read a record.

  A read entry on the audit trail says who opened a record and when; on a
  family or health case that is itself personal data. Administrators always
  see read entries; anyone else only as a member of a group named here. No
  group named means only administrators see them.

  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  @spec openspec/changes/audit-read-events-visible-to/specs/audit-trail-immutable/spec.md#requirement-read-entries-are-shown-only-to-the-groups-named-for-them
-->
<template>
	<div class="read-event-settings">
		<h3>{{ t('openregister', 'Who sees who read a record') }}</h3>
		<p class="read-event-settings__intro">
			{{
				t(
					'openregister',
					'A record\'s history and the audit feed show who opened a record only to administrators and to members of the groups chosen here. With no group chosen, only administrators see it.',
				)
			}}
		</p>

		<NcSelect
			v-model="selected"
			:options="groups"
			:multiple="true"
			label="label"
			:inputLabel="t('openregister', 'Groups')"
			:disabled="loading || saving" />

		<NcButton variant="primary" :disabled="loading || saving" @click="save">
			{{
				saving
					? t('openregister', 'Saving…')
					: t('openregister', 'Save')
			}}
		</NcButton>
	</div>
</template>

<script>
import { NcButton, NcSelect } from '@nextcloud/vue'
import { useSettingsStore } from '../../../store/settings.js'

export default {
	name: 'ReadEventVisibilityConfiguration',

	components: {
		NcButton,
		NcSelect,
	},

	setup() {
		return { settingsStore: useSettingsStore() }
	},

	data() {
		return {
			loading: true,
			groups: [],
			// The chosen groups as options; a stored group that no longer
			// exists is kept by its id, so saving does not drop it unseen.
			selected: [],
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
		const loaded = await this.settingsStore.loadAuditSettings()
		this.groups = loaded?.groups || []
		this.selected = (loaded?.readEventsVisibleTo || []).map(
			(id) => this.groups.find((group) => group.id === id) || { id, label: id },
		)
		this.loading = false
	},

	methods: {
		/**
		 * Store the chosen group ids.
		 *
		 * @spec openspec/changes/audit-read-events-visible-to/specs/audit-trail-immutable/spec.md#requirement-read-entries-are-shown-only-to-the-groups-named-for-them
		 * @return {Promise<void>}
		 */
		async save() {
			await this.settingsStore.updateAuditSettings({
				readEventsVisibleTo: this.selected.map((group) => group.id),
			})
		},
	},
}
</script>

<style scoped>
.read-event-settings__intro {
	color: var(--color-text-maxcontrast);
	margin-block: 4px 12px;
}

.read-event-settings > * {
	margin-block-end: 12px;
}
</style>
