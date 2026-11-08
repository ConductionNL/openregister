<script setup>
import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import { configurationStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcAppContent>
		<div class="viewContainer">
			<!-- Header -->
			<div class="viewHeader">
				<h1 class="viewHeaderTitleIndented">
					{{ t('openregister', 'Configurations') }}
				</h1>
				<p>
					{{
						t(
							'openregister',
							'Manage your system configurations and settings',
						)
					}}
				</p>
			</div>

			<!-- Actions Bar -->
			<ListActionsBar
				:summary="
					t(
						'openregister',
						'Showing {showing} of {total} configurations',
						{
							showing: paginatedConfigurations.length,
							total: configurationStore.configurationList.length,
						},
					)
				"
				:selectedCount="selectedConfigurations.length"
				:actions="headerActions">
				<ViewModeSwitch
					v-model="viewMode"
					cardsTitle="See configurations as cards"
					tableTitle="See configurations as a table" />
			</ListActionsBar>

			<!-- Loading, Error, and Empty States -->
			<NcEmptyContent
				v-if="
					configurationStore.loading
					|| configurationStore.error
					|| !configurationStore.configurationList.length
				"
				:name="emptyContentName"
				:description="emptyContentDescription">
				<template #icon>
					<NcLoadingIcon v-if="configurationStore.loading" :size="64" />
					<CogOutline v-else :size="64" />
				</template>
			</NcEmptyContent>

			<!-- Content -->
			<div v-else>
				<template v-if="viewMode === 'cards'">
					<div class="cardGrid">
						<ConfigurationCard
							v-for="configuration in paginatedConfigurations"
							:key="configuration.id"
							:configuration="configuration"
							@view="handleView(configuration)"
							@edit="handleEdit(configuration)"
							@export="handleExport(configuration)"
							@delete="handleDelete(configuration)"
							@checkVersion="checkVersion(configuration)"
							@previewUpdate="previewUpdate(configuration)" />
					</div>
				</template>
				<template v-else>
					<CnDataTable
						:columns="columns"
						:rows="paginatedConfigurations"
						selectable
						rowClickToView
						:selectedIds="selectedConfigurations"
						:selectAllLabel="t('openregister', 'Select All')"
						@select="selectedConfigurations = $event"
						@selectAll="toggleSelectAll">
						<template #column-title="{ row: configuration }">
							<div class="titleContent">
								<strong>{{ configuration.title }}</strong>
								<span
									v-if="configuration.description"
									class="textDescription textEllipsis"
									>{{ configuration.description }}</span
								>
							</div>
						</template>
						<template #column-sourceType="{ value }">
							{{ getSourceTypeLabel(value) }}
						</template>
						<template #column-localVersion="{ value }">
							{{ value || '-' }}
						</template>
						<template #column-remoteVersion="{ value }">
							{{ value || '-' }}
						</template>
						<template #column-status="{ row: configuration }">
							<div class="statusPillsContainer">
								<template v-if="configuration.isLocal">
									<span
										class="configBadge configBadge--local">
										<CheckCircle :size="16" />
										Local
									</span>
									<span
										v-if="configuration.app"
										class="configBadge configBadge--app">
										<ApplicationCog :size="16" />
										{{ configuration.app }}
									</span>
								</template>
								<span
									v-else
									class="configBadge configBadge--external">
									<Cloud :size="16" />
									External
								</span>
								<span
									v-if="
										!configuration.isLocal
										&& configuration.syncEnabled
									"
									class="configBadge"
									:class="
										'configBadge--sync-'
										+ configuration.syncStatus
									">
									<Sync
										v-if="
											configuration.syncStatus
											=== 'success'
										"
										:size="16" />
									<AlertCircle
										v-else-if="
											configuration.syncStatus
											=== 'failed'
										"
										:size="16" />
									<ClockOutline v-else :size="16" />
									{{
										getSyncStatusText(configuration)
									}}
								</span>
								<span
									v-if="
										hasUpdateAvailable(configuration)
									"
									class="configBadge configBadge--update">
									<Update :size="16" />
									Update Available
								</span>
							</div>
						</template>
						<template #column-updated="{ row: configuration }">
							{{
								configuration.updated
									? new Date(
											configuration.updated,
										).toLocaleDateString({
											day: '2-digit',
											month: '2-digit',
											year: 'numeric',
										})
										+ ', '
										+ new Date(
											configuration.updated,
										).toLocaleTimeString({
											hour: '2-digit',
											minute: '2-digit',
											second: '2-digit',
										})
									: '-'
							}}
						</template>
						<template #actions-header>
							{{ t('openregister', 'Actions') }}
						</template>
						<template #row-actions="{ row: configuration }">
							<ActionMenu :actions="configurationActions(configuration)" />
						</template>
					</CnDataTable>
				</template>
			</div>

			<!-- Pagination -->
			<PaginationComponent
				v-if="configurationStore.configurationList.length > 0"
				:currentPage="pagination.page || 1"
				:totalPages="
					Math.ceil(
						configurationStore.configurationList.length
							/ (pagination.limit || 20),
					)
				"
				:totalItems="configurationStore.configurationList.length"
				:currentPageSize="pagination.limit || 20"
				:minItemsToShow="10"
				@pageChanged="onPageChanged"
				@pageSizeChanged="onPageSizeChanged" />
		</div>
	</NcAppContent>
</template>

<script>
import { CnDataTable } from '@conduction/nextcloud-vue'
import {
	NcAppContent,
	NcEmptyContent,
	NcLoadingIcon,
} from '@nextcloud/vue'
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue'
import ApplicationCog from 'vue-material-design-icons/ApplicationCog.vue'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import ClockOutline from 'vue-material-design-icons/ClockOutline.vue'
import Cloud from 'vue-material-design-icons/Cloud.vue'
import CloudUpload from 'vue-material-design-icons/CloudUpload.vue'
import CogOutline from 'vue-material-design-icons/CogOutline.vue'
import Download from 'vue-material-design-icons/Download.vue'
import EyeOutline from 'vue-material-design-icons/EyeOutline.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Sync from 'vue-material-design-icons/Sync.vue'
import Update from 'vue-material-design-icons/Update.vue'
import ConfigurationCard from '../../components/cards/ConfigurationCard.vue'
import PaginationComponent from '../../components/PaginationComponent.vue'
import ActionMenu from '../../components/shared/ActionMenu.vue'
import ListActionsBar from '../../components/shared/ListActionsBar.vue'
import ViewModeSwitch from '../../components/shared/ViewModeSwitch.vue'
import {
	checkConfigurationVersion,
	sourceTypeLabel,
	syncStatusText,
} from '../../services/configurationDisplay.js'
import {
	deleteAction,
	editAction,
	refreshAction,
	viewAction,
} from '../../services/menuActions.js'
import { localPagination } from '../localPagination.js'

export default {
	name: 'ConfigurationsIndex',
	components: {
		CnDataTable,
		ViewModeSwitch,
		ActionMenu,
		ListActionsBar,
		NcAppContent,
		NcEmptyContent,
		NcLoadingIcon,
		ConfigurationCard,
		CogOutline,
		Update,
		Sync,
		CheckCircle,
		Cloud,
		AlertCircle,
		ClockOutline,
		ApplicationCog,
		PaginationComponent,
	},

	mixins: [localPagination],

	data() {
		return {
			viewMode: 'cards',
			selectedConfigurations: [],
		}
	},

	computed: {
		/**
		 * The entries of the bar above the list.
		 *
		 * @spec exclude Client-side menu composition.
		 * @return {Array<object>} The ActionMenu entries
		 */
		headerActions() {
			return [
				{
					label: 'Create Configuration',
					icon: Plus,
					run: () => {
						configurationStore.setConfigurationItem(null)
						navigationStore.setModal('editConfiguration')
					},
				},
				{
					label: 'Import Configuration',
					icon: CloudUpload,
					run: () => navigationStore.setModal('importConfiguration'),
				},
				refreshAction(() => configurationStore.refreshConfigurationList()),
			]
		},

		/**
		 * The table view's columns; the row menu adds the "Actions" column.
		 *
		 * @spec exclude UI plumbing — table layout; admin list contract owned by admin-list-views.
		 * @return {Array<object>} The CnDataTable columns
		 */
		columns() {
			return [
				{ key: 'title', label: t('openregister', 'Title'), cellClass: 'cn-cell--wrap' },
				{ key: 'sourceType', label: t('openregister', 'Source') },
				{ key: 'localVersion', label: t('openregister', 'Local Version') },
				{ key: 'remoteVersion', label: t('openregister', 'Remote Version') },
				{ key: 'status', label: t('openregister', 'Status'), cellClass: 'cn-cell--wrap' },
				{ key: 'updated', label: t('openregister', 'Updated') },
			]
		},

		/**
		 * Current page slice of the configuration list.
		 *
		 * @spec exclude UI plumbing — client-side pagination computed; admin list contract owned by admin-list-views.
		 * @return {Array} configurations on the active page
		 */
		paginatedConfigurations() {
			const start =
				((this.pagination.page || 1) - 1) * (this.pagination.limit || 20)
			const end = start + (this.pagination.limit || 20)
			return configurationStore.configurationList.slice(start, end)
		},

		/**
		 * Empty-state title reflecting loading/error/empty.
		 *
		 * @spec exclude UI plumbing — derived empty-state copy, no observable contract.
		 * @return {string}
		 */
		emptyContentName() {
			if (configurationStore.loading) {
				return t('openregister', 'Loading configurations...')
			} else if (configurationStore.error) {
				return configurationStore.error
			} else if (!configurationStore.configurationList.length) {
				return t('openregister', 'No configurations found')
			}
			return ''
		},

		/**
		 * Empty-state description reflecting loading/error/empty.
		 *
		 * @spec exclude UI plumbing — derived empty-state copy, no observable contract.
		 * @return {string}
		 */
		emptyContentDescription() {
			if (configurationStore.loading) {
				return t(
					'openregister',
					'Please wait while we fetch your configurations.',
				)
			} else if (configurationStore.error) {
				return t('openregister', 'Please try again later.')
			} else if (!configurationStore.configurationList.length) {
				return t('openregister', 'No configurations are available.')
			}
			return ''
		},
	},

	/**
	 * Soft-refresh the configuration list on mount.
	 *
	 * @spec exclude UI plumbing — lifecycle hook delegating to the store; list contract owned by admin-list-views.
	 * @return {void}
	 */
	mounted() {
		// Use soft reload (no loading spinner) since data is hot-loaded at app startup
		configurationStore.refreshConfigurationList(null, true)
	},

	methods: {
		checkVersion: checkConfigurationVersion,
		getSourceTypeLabel: sourceTypeLabel,
		getSyncStatusText: syncStatusText,

		/**
		 * The row menu of a configuration.
		 *
		 * @spec exclude Client-side menu composition.
		 * @param {object} configuration The configuration
		 * @return {Array<object>} The ActionMenu entries
		 */
		configurationActions(configuration) {
			return [
				viewAction(() => this.handleView(configuration)),
				editAction(() => this.handleEdit(configuration)),
				{
					label: 'Check Version',
					icon: Sync,
					run: () => this.checkVersion(configuration),
					hidden: !this.isRemoteConfiguration(configuration),
				},
				{
					label: 'Preview Update',
					icon: EyeOutline,
					run: () => this.previewUpdate(configuration),
					hidden: !this.hasUpdateAvailable(configuration),
				},
				{
					label: 'Export',
					icon: Download,
					run: () => this.handleExport(configuration),
				},
				deleteAction(() => this.handleDelete(configuration)),
			]
		},

		/**
		 * Toggle selection state for every configuration in the current list.
		 *
		 * @spec openspec/specs/admin-list-views/spec.md
		 * @param {boolean} checked - true selects all, false clears the selection
		 * @return {void}
		 */
		toggleSelectAll(checked) {
			if (checked) {
				this.selectedConfigurations =
					configurationStore.configurationList.map(
						(configuration) => configuration.id,
					)
			} else {
				this.selectedConfigurations = []
			}
		},

		/**
		 * Whether a configuration has a remote update available.
		 *
		 * @spec exclude UI plumbing — display badge predicate comparing local vs remote version.
		 * @param {object} configuration - configuration row
		 * @return {boolean}
		 */
		hasUpdateAvailable(configuration) {
			if (!configuration.localVersion || !configuration.remoteVersion) {
				return false
			}
			// Simple version comparison - remote version different from local
			return configuration.remoteVersion !== configuration.localVersion
		},

		isRemoteConfiguration(configuration) {
			return configuration.sourceType && configuration.sourceType !== 'local'
		},

		isManualConfiguration(configuration) {
			return !configuration.sourceType || configuration.sourceType === 'local'
		},

		/**
		 * Open the view-configuration modal for a row.
		 *
		 * @spec exclude UI plumbing — store-set + modal dispatch.
		 * @param {object} configuration - configuration row
		 * @return {void}
		 */
		handleView(configuration) {
			configurationStore.setConfigurationItem(configuration)
			navigationStore.setModal('viewConfiguration')
		},

		/**
		 * Open the edit-configuration modal for a row.
		 *
		 * @spec exclude UI plumbing — store-set + modal dispatch.
		 * @param {object} configuration - configuration row
		 * @return {void}
		 */
		handleEdit(configuration) {
			configurationStore.setConfigurationItem(configuration)
			navigationStore.setModal('editConfiguration')
		},

		/**
		 * Open the export-configuration modal for a row.
		 *
		 * @spec exclude UI plumbing — store-set + modal dispatch.
		 * @param {object} configuration - configuration row
		 * @return {void}
		 */
		handleExport(configuration) {
			configurationStore.setConfigurationItem(configuration)
			navigationStore.setModal('exportConfiguration')
		},

		/**
		 * Open the delete-configuration dialog for a row.
		 *
		 * @spec exclude UI plumbing — store-set + dialog dispatch.
		 * @param {object} configuration - configuration row
		 * @return {void}
		 */
		handleDelete(configuration) {
			configurationStore.setConfigurationItem(configuration)
			navigationStore.setDialog('deleteConfiguration')
		},

		/**
		 * Open the preview-update modal for a row.
		 *
		 * @spec exclude UI plumbing — store-set + modal dispatch.
		 * @param {object} configuration - configuration row
		 * @return {void}
		 */
		previewUpdate(configuration) {
			// Set the configuration and open preview modal
			configurationStore.setConfigurationItem(configuration)
			navigationStore.setModal('previewConfiguration')
		},
	},
}
</script>

<style scoped lang="scss">
@use '../../styles/common.scss' as *;

/* Badge styles for table view only (card view uses ConfigurationCard styles) */
.configBadge {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	padding: 2px 8px;
	border-radius: 12px;
	font-size: 0.85em;
	font-weight: 600;
	vertical-align: middle;
}

/* Local configuration badge (green/success) */
.configBadge--local {
	background-color: var(--color-success-light);
	color: var(--color-success-dark);
}

/* External configuration badge (blue/primary) */
.configBadge--external {
	background-color: var(--color-primary-element-light);
	color: var(--color-primary-element-text);
}

/* App source badge (gray/secondary) */
.configBadge--app {
	background-color: var(--color-background-dark);
	color: var(--color-text-lighter);
}

/* Sync status badges */
@include config-badge-sync-success-rules;

.configBadge--sync-pending,
.configBadge--sync-never {
	background-color: var(--color-background-dark);
	color: var(--color-text-lighter);
}

/* Update available badge */
@include config-badge-update;

/* Badge container for table view */
.statusPillsContainer {
	display: flex;
	gap: 6px;
	flex-wrap: wrap;
	align-items: center;
}
</style>
