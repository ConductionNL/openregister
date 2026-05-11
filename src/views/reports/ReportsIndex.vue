<template>
	<NcAppContent>
		<div class="viewContainer">
			<div class="viewHeader">
				<div class="viewHeaderTitle">
					<h1 class="viewHeaderTitleIndented">
						{{ t('openregister', 'Reports') }}
					</h1>
				</div>
				<p>
					{{ t('openregister', 'Operator-defined dashboards and scheduled reports. Each dashboard is a first-class object in the `reports` register; widgets are declared in the dashboard\'s `widgets` array and rendered live from aggregations / GraphQL.') }}
				</p>
			</div>

			<div class="viewActionsBar">
				<div class="viewInfo">
					<span v-if="dashboards.length" class="viewTotalCount">
						{{ t('openregister', 'Showing {count} dashboard(s)', { count: dashboards.length }) }}
					</span>
				</div>
				<div class="viewActions">
					<NcButton type="primary" :disabled="loading" @click="refresh">
						<template #icon>
							<NcLoadingIcon v-if="loading" :size="20" />
							<Refresh v-else :size="20" />
						</template>
						{{ t('openregister', 'Refresh') }}
					</NcButton>
				</div>
			</div>

			<div class="reportsGrid">
				<NcEmptyContent
					v-if="!dashboards.length && !loading"
					:name="t('openregister', 'No dashboards yet')"
					:description="t('openregister', 'Import the report-bundle.json template to get the `reports` register, then create your first dashboard via the standard object UI. Dashboards declare their widgets in JSON and the renderer feeds each widget live aggregation data.')">
					<template #icon>
						<ChartLine :size="64" />
					</template>
				</NcEmptyContent>

				<article
					v-for="dashboard in dashboards"
					:key="dashboard.id || dashboard.uuid"
					class="reportCard"
					@click="openDashboard(dashboard)">
					<div class="reportCardHeader">
						<ChartBoxOutline :size="32" class="reportCardIcon" />
						<div>
							<h3>{{ dashboard.titel || dashboard['@self']?.name || t('openregister', 'Untitled') }}</h3>
							<span class="badge">{{ dashboard.category || 'operational' }}</span>
						</div>
					</div>
					<p v-if="dashboard.beschrijving" class="reportCardBody">
						{{ dashboard.beschrijving }}
					</p>
					<footer class="reportCardFooter">
						<span class="reportCardWidgetCount">
							{{ t('openregister', '{count} widget(s)', { count: (dashboard.widgets || []).length }) }}
						</span>
						<span v-if="dashboard.schedule?.active" class="badge badge-status-published">
							<ClockOutline :size="14" />
							{{ t('openregister', 'Scheduled') }}
						</span>
					</footer>
				</article>
			</div>
		</div>
	</NcAppContent>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcAppContent, NcButton, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import ChartLine from 'vue-material-design-icons/ChartLine.vue'
import ChartBoxOutline from 'vue-material-design-icons/ChartBoxOutline.vue'
import ClockOutline from 'vue-material-design-icons/ClockOutline.vue'
import { reportsStore } from '../../store/store.js'

export default {
	name: 'ReportsIndex',

	components: {
		NcAppContent,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		Refresh,
		ChartLine,
		ChartBoxOutline,
		ClockOutline,
	},

	computed: {
		t() {
			return t
		},
		dashboards() {
			return reportsStore.getDashboards ?? []
		},
		loading() {
			return reportsStore.isLoading
		},
	},

	mounted() {
		this.refresh()
	},

	methods: {
		async refresh() {
			try {
				await reportsStore.fetchDashboards()
			} catch (e) {
				// surfaced via store error
			}
		},

		openDashboard(dashboard) {
			const id = dashboard['@self']?.uuid || dashboard.uuid || dashboard.id
			if (!id) return
			this.$router.push(`/reports/${id}`)
		},
	},
}
</script>

<style scoped>
.viewContainer {
	padding: 20px;
	display: flex;
	flex-direction: column;
	gap: 16px;
}
.viewHeader {
	border-bottom: 1px solid var(--color-border);
	padding-bottom: 12px;
}
.viewHeaderTitleIndented {
	margin: 0;
}
.viewActionsBar {
	display: flex;
	align-items: center;
	justify-content: space-between;
}
.viewInfo {
	color: var(--color-text-maxcontrast);
}
.reportsGrid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
	gap: 16px;
}
.reportCard {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 16px;
	cursor: pointer;
	transition: box-shadow 0.15s ease, border-color 0.15s ease;
}
.reportCard:hover {
	border-color: var(--color-primary);
	box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
}
.reportCardHeader {
	display: flex;
	align-items: flex-start;
	gap: 12px;
	margin-bottom: 8px;
}
.reportCardIcon {
	color: var(--color-primary);
	flex-shrink: 0;
}
.reportCardHeader h3 {
	margin: 0 0 4px 0;
	font-size: 16px;
}
.reportCardBody {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	margin: 0 0 12px 0;
	line-height: 1.4;
}
.reportCardFooter {
	display: flex;
	justify-content: space-between;
	align-items: center;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}
.badge {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	padding: 2px 8px;
	border-radius: 12px;
	background: var(--color-background-darker);
	font-size: 11px;
	font-weight: 600;
	text-transform: uppercase;
}
.badge-status-published {
	background: var(--color-success);
	color: var(--color-primary-text);
}
</style>
