<!-- SPDX-License-Identifier: EUPL-1.2 -->
<template>
	<div class="cn-time-card" :class="`cn-time-card--${surface}`">
		<!-- single-entity: compact hours chip -->
		<span v-if="surface === 'single-entity'" class="cn-time-card__chip">
			<ClockIcon :size="14" />
			{{ formattedTotal }}
		</span>

		<!-- user-dashboard: user's hours today across objects -->
		<div v-else-if="surface === 'user-dashboard'" class="cn-time-card__dashboard">
			<h3 class="cn-time-card__title">
				{{ t('openregister', 'My time today') }}
			</h3>
			<div class="cn-time-card__total">
				{{ formattedTotal }}
			</div>
		</div>

		<!-- app-dashboard: scoped total for the current app context -->
		<div v-else-if="surface === 'app-dashboard'" class="cn-time-card__dashboard">
			<h3 class="cn-time-card__title">
				{{ t('openregister', 'Time logged') }}
			</h3>
			<div class="cn-time-card__total">
				{{ formattedTotal }}
			</div>
		</div>

		<!-- detail-page: object total + per-user/week breakdown -->
		<div v-else class="cn-time-card__detail">
			<div class="cn-time-card__total-row">
				<ClockIcon :size="20" />
				<span class="cn-time-card__total">{{ formattedTotal }}</span>
				<span class="cn-time-card__label">{{ t('openregister', 'total') }}</span>
			</div>
			<div v-if="breakdown.length > 0" class="cn-time-card__breakdown">
				<div
					v-for="row in breakdown"
					:key="row.key"
					class="cn-time-card__breakdown-row">
					<span class="cn-time-card__breakdown-user">{{ row.userId }}</span>
					<span class="cn-time-card__breakdown-week">{{ row.week }}</span>
					<span class="cn-time-card__breakdown-duration">{{ formatMinutes(row.minutes) }}</span>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import ClockIcon from 'vue-material-design-icons/Clock.vue'

export default {
	name: 'CnTimeCard',

	components: { ClockIcon },

	props: {
		/**
		 * Rendering surface: 'user-dashboard' | 'app-dashboard' | 'detail-page' | 'single-entity'.
		 */
		surface: {
			type: String,
			default: 'detail-page',
			validator: (v) => ['user-dashboard', 'app-dashboard', 'detail-page', 'single-entity'].includes(v),
		},
		/** Object UUID (required for detail-page and single-entity). */
		objectUuid: {
			type: String,
			default: null,
		},
		/** Register slug (required for detail-page and single-entity). */
		register: {
			type: String,
			default: null,
		},
		/** Schema slug (required for detail-page and single-entity). */
		schema: {
			type: String,
			default: null,
		},
		/** Object ID (alias for objectUuid for single-entity). */
		objectId: {
			type: String,
			default: null,
		},
	},

	data() {
		return {
			totalMinutes: 0,
			entries: [],
			loading: false,
		}
	},

	computed: {
		formattedTotal() {
			return this.formatMinutes(this.totalMinutes)
		},

		breakdown() {
			const map = {}
			for (const entry of this.entries) {
				const date = entry.entryDate ? new Date(entry.entryDate) : new Date()
				const year = date.getFullYear()
				const week = this.getISOWeek(date)
				const key = `${entry.userId}__${year}-W${String(week).padStart(2, '0')}`
				if (!map[key]) {
					map[key] = {
						key,
						userId: entry.userId,
						week: `${year}-W${String(week).padStart(2, '0')}`,
						minutes: 0,
					}
				}

				map[key].minutes += entry.durationMinutes || 0
			}

			return Object.values(map).sort((a, b) => b.week.localeCompare(a.week))
		},
	},

	mounted() {
		if (this.objectUuid || this.objectId) {
			this.fetchData()
		}
	},

	methods: {
		t,

		async fetchData() {
			const uuid = this.objectUuid || this.objectId
			if (!uuid || !this.register || !this.schema) {
				return
			}

			this.loading = true
			try {
				const url = generateUrl(
					`/apps/openregister/api/objects/${encodeURIComponent(this.register)}/${encodeURIComponent(this.schema)}/${encodeURIComponent(uuid)}/time`
				)
				const { data } = await axios.get(url)
				this.totalMinutes = data.totalMinutes || 0
				this.entries = data.results || []
			} catch (e) {
				console.error('[CnTimeCard] fetchData failed', e)
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

		/** ISO week number from a Date object. */
		getISOWeek(date) {
			const d = new Date(Date.UTC(date.getFullYear(), date.getMonth(), date.getDate()))
			const dayNum = d.getUTCDay() || 7
			d.setUTCDate(d.getUTCDate() + 4 - dayNum)
			const yearStart = new Date(Date.UTC(d.getUTCFullYear(), 0, 1))
			return Math.ceil((((d - yearStart) / 86400000) + 1) / 7)
		},
	},
}
</script>

<style scoped>
.cn-time-card {
	display: flex;
	flex-direction: column;
}

.cn-time-card__chip {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	background: var(--color-background-dark);
	border-radius: var(--border-radius-pill, 20px);
	padding: 2px 8px;
	font-size: 0.85em;
	font-weight: 600;
}

.cn-time-card__dashboard {
	padding: var(--spacer-2, 8px);
}

.cn-time-card__title {
	font-size: 1em;
	margin: 0 0 var(--spacer-1, 4px);
}

.cn-time-card__total {
	font-size: 2em;
	font-weight: 700;
	color: var(--color-primary-element);
}

.cn-time-card__detail {
	padding: var(--spacer-2, 8px);
}

.cn-time-card__total-row {
	display: flex;
	align-items: center;
	gap: var(--spacer-2, 8px);
	margin-bottom: var(--spacer-2, 8px);
}

.cn-time-card__label {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.cn-time-card__breakdown {
	border-top: 1px solid var(--color-border);
	padding-top: var(--spacer-2, 8px);
}

.cn-time-card__breakdown-row {
	display: flex;
	gap: var(--spacer-2, 8px);
	padding: 2px 0;
	font-size: 0.9em;
}

.cn-time-card__breakdown-user {
	flex: 1;
	font-weight: 500;
}

.cn-time-card__breakdown-week {
	color: var(--color-text-maxcontrast);
	min-width: 8em;
}

.cn-time-card__breakdown-duration {
	font-weight: 600;
	min-width: 4em;
	text-align: right;
}
</style>
