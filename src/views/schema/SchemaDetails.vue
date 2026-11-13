<script setup>
import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import formatBytes from '../../services/formatBytes.js'
import { dashboardStore, navigationStore, schemaStore } from '../../store/store.js'
</script>

<template>
	<NcAppContent>
		<!-- Loading and error states -->
		<div v-if="dashboardStore.loading" class="error">
			<NcEmptyContent
				name="Loading"
				description="Loading schema statistics...">
				<template #icon>
					<NcLoadingIcon :size="64" />
				</template>
			</NcEmptyContent>
		</div>
		<div v-else-if="dashboardStore.error" class="error">
			<NcEmptyContent name="Error" :description="dashboardStore.error">
				<template #icon>
					<AlertCircle :size="64" />
				</template>
			</NcEmptyContent>
		</div>
		<div v-else>
			<span class="pageHeaderContainer">
				<h2 class="pageHeader">
					{{ schemaStore.schemaItem.title }}
				</h2>
				<div class="headerActionsContainer">
					<NcActions :primary="true" menuName="Actions">
						<template #icon>
							<DotsHorizontal :size="20" />
						</template>
						<NcActionButton closeAfterClick @click="openEditor(false)">
							<template #icon>
								<Pencil :size="20" />
							</template>
							Edit
						</NcActionButton>
						<NcActionButton
							closeAfterClick
							data-testid="edit-schema-as-draft"
							@click="openEditor(true)">
							<template #icon>
								<FileDocumentEditOutline :size="20" />
							</template>
							{{ t('openregister', 'Edit as draft') }}
						</NcActionButton>
						<NcActionButton
							closeAfterClick
							data-testid="add-schema-property"
							@click="
								() => {
									schemaStore.setSchemaPropertyKey(null)
									navigationStore.setModal('editSchemaProperty')
								}
							">
							<template #icon>
								<PlusCircleOutline />
							</template>
							Add Property
						</NcActionButton>
						<NcActionButton
							closeAfterClick
							@click="navigationStore.setModal('uploadSchema')">
							<template #icon>
								<Upload :size="20" />
							</template>
							Upload
						</NcActionButton>
						<NcActionButton
							closeAfterClick
							@click="
								schemaStore.downloadSchema(schemaStore.schemaItem)
							">
							<template #icon>
								<Download :size="20" />
							</template>
							Download
						</NcActionButton>
						<NcActionButton
							closeAfterClick
							@click="navigationStore.setDialog('deleteSchema')">
							<template #icon>
								<TrashCanOutline :size="20" />
							</template>
							Delete
						</NcActionButton>
					</NcActions>
				</div>
			</span>

			<!-- A pending draft: records use the published schema until it is published. -->
			<SchemaDraftBar
				:schema="schemaStore.schemaItem"
				@editDraft="openEditor(true)" />

			<!-- Tab navigation -->
			<div class="schemaTabNav">
				<button
					class="tabButton"
					:class="[{ active: activeTab === 'dashboard' }]"
					@click="activeTab = 'dashboard'">
					<ChartBox :size="16" />
					{{ t('openregister', 'Dashboard') }}
				</button>
				<button
					class="tabButton"
					:class="[{ active: activeTab === 'calendar' }]"
					@click="activeTab = 'calendar'">
					<CalendarMonth :size="16" />
					{{ t('openregister', 'Calendar') }}
				</button>
				<button
					class="tabButton"
					:class="[{ active: activeTab === 'workflows' }]"
					@click="activeTab = 'workflows'">
					<Cog :size="16" />
					{{ t('openregister', 'Workflows') }}
				</button>
				<button
					class="tabButton"
					:class="[{ active: activeTab === 'rules' }]"
					@click="activeTab = 'rules'">
					<Gavel :size="16" />
					{{ t('openregister', 'Rules') }}
				</button>
			</div>

			<!-- Calendar Provider Tab -->
			<CalendarProviderTab
				v-if="activeTab === 'calendar'"
				:schema="schemaStore.schemaItem" />

			<!-- Workflows Tab — execution history, scheduled workflows, approval chains -->
			<SchemaWorkflowTab
				v-if="activeTab === 'workflows'"
				:schema="schemaStore.schemaItem" />

			<!-- Rules Tab: everything that runs on a save, in the order it runs -->
			<SchemaRulesTab
				v-if="activeTab === 'rules'"
				:schema="schemaStore.schemaItem" />

			<!-- Dashboard Tab (original content) -->
			<div v-show="activeTab === 'dashboard'" class="dashboardContent">
				<span>{{ schemaStore.schemaItem.description }}</span>

				<!-- Schema Statistics -->
				<div v-if="schemaStats" class="statsContainer">
					<h3>{{ t('openregister', 'Schema Statistics') }}</h3>
					<table class="statisticsTable schemaStats">
						<thead>
							<tr>
								<th scope="col">
									{{ t('openregister', 'Type') }}
								</th>
								<th scope="col">
									{{ t('openregister', 'Total') }}
								</th>
								<th scope="col">
									{{ t('openregister', 'Size') }}
								</th>
							</tr>
						</thead>
						<tbody>
							<tr>
								<td>{{ t('openregister', 'Objects') }}</td>
								<td>{{ schemaStats.objects?.total || 0 }}</td>
								<td>
									{{ formatBytes(schemaStats.objects?.size || 0) }}
								</td>
							</tr>
							<tr class="subRow">
								<td class="indented">
									{{ t('openregister', 'Invalid') }}
								</td>
								<td>{{ schemaStats.objects?.invalid || 0 }}</td>
								<td>-</td>
							</tr>
							<tr class="subRow">
								<td class="indented">
									{{ t('openregister', 'Deleted') }}
								</td>
								<td>{{ schemaStats.objects?.deleted || 0 }}</td>
								<td>-</td>
							</tr>
							<tr>
								<td>{{ t('openregister', 'Files') }}</td>
								<td>{{ schemaStats.files?.total || 0 }}</td>
								<td>
									{{ formatBytes(schemaStats.files?.size || 0) }}
								</td>
							</tr>
							<tr>
								<td>{{ t('openregister', 'Logs') }}</td>
								<td>{{ schemaStats.logs?.total || 0 }}</td>
								<td>
									{{ formatBytes(schemaStats.logs?.size || 0) }}
								</td>
							</tr>
							<tr>
								<td>{{ t('openregister', 'Registers') }}</td>
								<td>{{ schemaStats.registers || 0 }}</td>
								<td>-</td>
							</tr>
						</tbody>
					</table>
				</div>

				<DetailChartGrid
					:chartData="dashboardStore.chartData"
					distributionKey="objectsByRegister"
					distributionTitle="Objects by Register"
					:auditOptions="auditTrailChartOptions"
					:distributionOptions="registerChartOptions"
					:sizeOptions="sizeChartOptions" />
			</div>
		</div>
	</NcAppContent>
</template>

<script>
import {
	NcActionButton,
	NcActions,
	NcAppContent,
	NcEmptyContent,
	NcLoadingIcon,
} from '@nextcloud/vue'
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue'
import CalendarMonth from 'vue-material-design-icons/CalendarMonth.vue'
import ChartBox from 'vue-material-design-icons/ChartBox.vue'
import Cog from 'vue-material-design-icons/Cog.vue'
import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'
import Download from 'vue-material-design-icons/Download.vue'
import FileDocumentEditOutline from 'vue-material-design-icons/FileDocumentEditOutline.vue'
import Gavel from 'vue-material-design-icons/Gavel.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import PlusCircleOutline from 'vue-material-design-icons/PlusCircleOutline.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import Upload from 'vue-material-design-icons/Upload.vue'
import SchemaDraftBar from '../../components/schema/SchemaDraftBar.vue'
import DetailChartGrid from '../register/DetailChartGrid.vue'
import SchemaWorkflowTab from '../schemas/SchemaWorkflowTab.vue'
import CalendarProviderTab from './CalendarProviderTab.vue'
import SchemaRulesTab from './SchemaRulesTab.vue'
import {
	auditTrailChartOptions,
	distributionChartOptions,
	sizeChartOptions,
} from '../register/detailChartOptions.js'

export default {
	name: 'SchemaDetails',
	components: {
		NcActions,
		NcActionButton,
		NcAppContent,
		NcEmptyContent,
		NcLoadingIcon,
		DetailChartGrid,
		DotsHorizontal,
		Pencil,
		TrashCanOutline,
		PlusCircleOutline,
		Download,
		FileDocumentEditOutline,
		Upload,
		AlertCircle,
		CalendarMonth,
		ChartBox,
		Cog,
		Gavel,
		CalendarProviderTab,
		SchemaWorkflowTab,
		SchemaRulesTab,
		SchemaDraftBar,
	},

	data() {
		return {
			activeTab: 'dashboard',
			schemaStats: null,
			statsLoading: false,
			statsError: null,
		}
	},

	computed: {
		/**
		 * Chart options for the Audit Trail Actions chart
		 *
		 * @spec exclude UI plumbing — static chart configuration for display
		 * @return {object}
		 */
		auditTrailChartOptions() {
			return auditTrailChartOptions(
				dashboardStore.chartData?.auditTrailActions?.labels || [],
				'light',
			)
		},

		/**
		 * Chart options for the Objects by Register chart
		 *
		 * @spec exclude UI plumbing — static chart configuration for display
		 * @return {object}
		 */
		registerChartOptions() {
			return distributionChartOptions(
				dashboardStore.chartData?.objectsByRegister?.labels || [],
			)
		},

		/**
		 * Chart options for the Objects by Size Distribution chart
		 *
		 * @spec exclude UI plumbing — static chart configuration for display
		 * @return {object}
		 */
		sizeChartOptions() {
			return sizeChartOptions(
				dashboardStore.chartData?.objectsBySize?.labels || [],
			)
		},
	},

	/**
	 * Lifecycle hook: load chart data and schema stats on mount.
	 *
	 * @spec exclude UI plumbing — view-mount data fetch for display only
	 * @return {Promise<void>}
	 */
	async mounted() {
		// Fetch dashboard data if not already loaded
		if (
			!dashboardStore.chartData
			|| Object.keys(dashboardStore.chartData).length === 0
		) {
			await dashboardStore.fetchAllChartData()
		}

		// Fetch schema stats if schema is available
		if (schemaStore.schemaItem?.id) {
			await this.loadSchemaStats()
		}
	},

	methods: {
		/**
		 * Open the schema editor, publishing directly or saving to the draft.
		 *
		 * @param {boolean} asDraft - True to save the edit as the schema's draft
		 * @spec openspec/specs/runtime-schema-api/spec.md#requirement-req-sdraft-001-a-schema-edit-can-be-held-as-a-draft-until-it-is-published
		 */
		openEditor(asDraft) {
			schemaStore.setDraftMode(asDraft)
			navigationStore.setModal('editSchema')
		},

		/**
		 * Load schema statistics from the dedicated stats endpoint
		 *
		 * @spec exclude UI plumbing — delegates to the schema store stats fetch
		 * @return {Promise<void>}
		 */
		async loadSchemaStats() {
			if (!schemaStore.schemaItem?.id) {
				return
			}

			this.statsLoading = true
			this.statsError = null

			try {
				this.schemaStats = await schemaStore.getSchemaStats(
					schemaStore.schemaItem.id,
				)
			} catch (error) {
				console.error('Error loading schema stats:', error)
				this.statsError = error.message
			} finally {
				this.statsLoading = false
			}
		},

		/**
		 * Set the active property for editing
		 *
		 * @param {string|null} key - The key to process
		 * @spec exclude UI plumbing — toggles active-property selection state
		 * @return {void}
		 */
		setActiveProperty(key) {
			if (
				JSON.stringify(schemaStore.schemaPropertyKey) === JSON.stringify(key)
			) {
				schemaStore.setSchemaPropertyKey(null)
			} else {
				schemaStore.setSchemaPropertyKey(key)
			}
		},
	},
}
</script>

<style lang="scss" scoped>
@use '../../styles/views.scss' as *;

.pageHeaderContainer {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 0;
}

.dashboardContent {
	margin-inline: auto;
	max-width: 1200px;
	padding-block: 20px;
	padding-inline: 20px;
}

.chartGrid {
	display: grid;
	grid-template-columns: repeat(2, 1fr);
	gap: 20px;
	padding: 20px;
}

@include media-screen-and-max-width-1024px;

.statsContainer {
	margin-bottom: 30px;

	h3 {
		margin-bottom: 15px;
		color: var(--color-main-text);
	}
}

.schemaTabNav {
	display: flex;
	gap: 0;
	border-bottom: 2px solid var(--color-border);
	margin-inline: 20px;
	margin-bottom: 0;

	.tabButton {
		display: flex;
		align-items: center;
		gap: 6px;
		padding: 10px 16px;
		background: none;
		border: none;
		border-bottom: 2px solid transparent;
		margin-bottom: -2px;
		cursor: pointer;
		color: var(--color-text-maxcontrast);
		font-size: 14px;
		font-weight: 500;
		transition:
			color 0.15s,
			border-color 0.15s;

		&:hover {
			color: var(--color-main-text);
		}

		&.active {
			color: var(--color-primary);
			border-bottom-color: var(--color-primary);
		}
	}
}

@media (prefers-reduced-motion: reduce) {
	.schemaTabNav .tabButton {
		transition: none;
	}
}
</style>
