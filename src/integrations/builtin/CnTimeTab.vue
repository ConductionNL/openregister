<!-- SPDX-License-Identifier: EUPL-1.2 -->
<template>
	<div class="cn-time-tab">
		<!-- Quick-log form -->
		<form class="cn-time-tab__log-form" @submit.prevent="submitEntry">
			<div class="cn-time-tab__row">
				<NcTextField
					id="time-duration"
					v-model="form.duration"
					type="number"
					:label="t('openregister', 'Duration (minutes)')"
					:placeholder="t('openregister', 'e.g. 30')"
					:min="1"
					required />
			</div>
			<div class="cn-time-tab__row">
				<NcTextField
					id="time-description"
					v-model="form.description"
					:label="t('openregister', 'Description')"
					:placeholder="t('openregister', 'What did you work on?')" />
			</div>
			<div class="cn-time-tab__row">
				<NcButton
					type="submit"
					:disabled="loading || !form.duration"
					native-type="submit">
					{{ t('openregister', 'Log time') }}
				</NcButton>
			</div>
		</form>

		<!-- Object total -->
		<div v-if="totalMinutes > 0" class="cn-time-tab__total">
			<strong>{{ t('openregister', 'Total:') }}</strong>
			{{ formatMinutes(totalMinutes) }}
		</div>

		<!-- Entry list grouped by user/date -->
		<div v-if="entries.length > 0" class="cn-time-tab__entries">
			<template v-for="(group, key) in grouped" :key="key">
				<div class="cn-time-tab__group-header">
					{{ group.label }}
				</div>
				<div
					v-for="entry in group.entries"
					:key="entry.id"
					class="cn-time-tab__entry">
					<span class="cn-time-tab__entry-duration">
						{{ formatMinutes(entry.durationMinutes) }}
					</span>
					<span v-if="entry.description" class="cn-time-tab__entry-desc">
						{{ entry.description }}
					</span>
					<NcButton
						type="tertiary-no-background"
						:aria-label="t('openregister', 'Delete entry')"
						@click="deleteEntry(entry.id)">
						<template #icon>
							<DeleteIcon :size="16" />
						</template>
					</NcButton>
				</div>
			</template>
		</div>

		<div v-else-if="!loading" class="cn-time-tab__empty">
			{{ t('openregister', 'No time entries yet.') }}
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcTextField from '@nextcloud/vue/dist/Components/NcTextField.js'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'

export default {
	name: 'CnTimeTab',

	components: { NcButton, NcTextField, DeleteIcon },

	props: {
		/** The OpenRegister object (must have uuid, register, schema). */
		object: {
			type: Object,
			required: true,
		},
		/** Register slug. */
		register: {
			type: String,
			required: true,
		},
		/** Schema slug. */
		schema: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			loading: false,
			entries: [],
			totalMinutes: 0,
			form: {
				duration: '',
				description: '',
			},
		}
	},

	computed: {
		/** Group entries by userId + date for the sidebar display. */
		grouped() {
			const groups = {}
			for (const entry of this.entries) {
				const date = entry.entryDate ? entry.entryDate.substring(0, 10) : 'unknown'
				const key = `${entry.userId}__${date}`
				if (!groups[key]) {
					groups[key] = {
						label: `${entry.userId} — ${date}`,
						entries: [],
					}
				}

				groups[key].entries.push(entry)
			}

			return groups
		},
	},

	mounted() {
		this.fetchEntries()
	},

	methods: {
		t,

		async fetchEntries() {
			this.loading = true
			try {
				const url = generateUrl(
					`/apps/openregister/api/objects/${encodeURIComponent(this.register)}/${encodeURIComponent(this.schema)}/${encodeURIComponent(this.object.uuid)}/time`
				)
				const { data } = await axios.get(url)
				this.entries = data.results || []
				this.totalMinutes = data.totalMinutes || 0
			} catch (e) {
				console.error('[CnTimeTab] fetchEntries failed', e)
			} finally {
				this.loading = false
			}
		},

		async submitEntry() {
			if (!this.form.duration) {
				return
			}

			this.loading = true
			try {
				const url = generateUrl(
					`/apps/openregister/api/objects/${encodeURIComponent(this.register)}/${encodeURIComponent(this.schema)}/${encodeURIComponent(this.object.uuid)}/time`
				)
				await axios.post(url, {
					durationMinutes: parseInt(this.form.duration, 10),
					description: this.form.description || null,
				})
				this.form.duration = ''
				this.form.description = ''
				await this.fetchEntries()
			} catch (e) {
				console.error('[CnTimeTab] submitEntry failed', e)
			} finally {
				this.loading = false
			}
		},

		async deleteEntry(entryId) {
			this.loading = true
			try {
				const url = generateUrl(
					`/apps/openregister/api/objects/${encodeURIComponent(this.register)}/${encodeURIComponent(this.schema)}/${encodeURIComponent(this.object.uuid)}/time/${encodeURIComponent(entryId)}`
				)
				await axios.delete(url)
				await this.fetchEntries()
			} catch (e) {
				console.error('[CnTimeTab] deleteEntry failed', e)
			} finally {
				this.loading = false
			}
		},

		formatMinutes(minutes) {
			if (!minutes || minutes <= 0) return '0m'
			const h = Math.floor(minutes / 60)
			const m = minutes % 60
			if (h > 0 && m > 0) return `${h}h ${m}m`
			if (h > 0) return `${h}h`
			return `${m}m`
		},
	},
}
</script>

<style scoped>
.cn-time-tab {
	padding: var(--spacer-2, 8px);
}

.cn-time-tab__log-form {
	margin-bottom: var(--spacer-3, 12px);
}

.cn-time-tab__row {
	margin-bottom: var(--spacer-2, 8px);
}

.cn-time-tab__total {
	padding: var(--spacer-2, 8px) 0;
	font-size: 0.9em;
	color: var(--color-text-maxcontrast);
}

.cn-time-tab__group-header {
	font-weight: bold;
	font-size: 0.85em;
	text-transform: uppercase;
	color: var(--color-text-maxcontrast);
	margin: var(--spacer-2, 8px) 0 var(--spacer-1, 4px);
}

.cn-time-tab__entry {
	display: flex;
	align-items: center;
	gap: var(--spacer-2, 8px);
	padding: var(--spacer-1, 4px) 0;
}

.cn-time-tab__entry-duration {
	font-weight: 600;
	min-width: 4em;
}

.cn-time-tab__entry-desc {
	flex: 1;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.cn-time-tab__empty {
	color: var(--color-text-maxcontrast);
	font-style: italic;
}
</style>
