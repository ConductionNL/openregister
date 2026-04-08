<template>
	<div class="section">
		<h2>{{ t('openregister', 'Activity') }}</h2>
		<div class="activity-section__filters">
			<NcSelect v-model="typeFilter"
				:options="typeOptions"
				:placeholder="t('openregister', 'Filter by type')"
				@input="loadActivity" />
		</div>
		<div v-if="loading && activities.length === 0" class="section__loading">
			{{ t('openregister', 'Loading activity...') }}
		</div>
		<ul v-else class="activity-section__list">
			<li v-for="activity in activities" :key="activity.id" class="activity-section__item">
				<span class="activity-section__type">{{ activity.type }}</span>
				<span class="activity-section__summary">{{ activity.summary }}</span>
				<span class="activity-section__time">{{ formatTime(activity.timestamp) }}</span>
			</li>
		</ul>
		<p v-if="activities.length === 0 && !loading">
			{{ t('openregister', 'No activity found.') }}
		</p>
		<NcButton v-if="hasMore"
			:disabled="loading"
			@click="loadMore">
			{{ t('openregister', 'Load more') }}
		</NcButton>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcSelect from '@nextcloud/vue/dist/Components/NcSelect.js'

export default {
	name: 'ActivitySection',
	components: { NcButton, NcSelect },
	data() {
		return {
			activities: [],
			total: 0,
			offset: 0,
			limit: 25,
			loading: false,
			typeFilter: null,
			typeOptions: ['create', 'update', 'delete'],
		}
	},
	computed: {
		hasMore() {
			return this.activities.length < this.total
		},
	},
	mounted() {
		this.loadActivity()
	},
	methods: {
		t,
		async loadActivity() {
			this.loading = true
			this.offset = 0
			this.activities = []
			await this.fetchActivity()
		},
		async loadMore() {
			this.offset += this.limit
			await this.fetchActivity()
		},
		async fetchActivity() {
			this.loading = true
			try {
				const params = { _limit: this.limit, _offset: this.offset }
				if (this.typeFilter) params.type = this.typeFilter
				const { data } = await axios.get(
					generateUrl('/apps/openregister/api/user/me/activity'),
					{ params },
				)
				this.activities = [...this.activities, ...(data.results || [])]
				this.total = data.total || 0
			} catch (e) {
				// Silently handle.
			} finally {
				this.loading = false
			}
		},
		formatTime(timestamp) {
			if (!timestamp) return ''
			const date = new Date(timestamp)
			return date.toLocaleString()
		},
	},
}
</script>

<style scoped>
.section { margin-bottom: 32px; padding: 16px; border-bottom: 1px solid var(--color-border); }
.section__loading { color: var(--color-text-maxcontrast); }
.activity-section__filters { margin-bottom: 16px; max-width: 200px; }
.activity-section__list { list-style: none; padding: 0; }
.activity-section__item { display: flex; gap: 12px; padding: 8px 0; border-bottom: 1px solid var(--color-border-dark); align-items: center; }
.activity-section__type { font-weight: bold; min-width: 60px; text-transform: capitalize; }
.activity-section__summary { flex: 1; }
.activity-section__time { color: var(--color-text-maxcontrast); font-size: 0.9em; }
</style>
