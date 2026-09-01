<script setup>
import { translate as t } from '@nextcloud/l10n'
import { applicationStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcAppContent>
		<CnIndexPage
			ref="indexPage"
			:title="t('openregister', 'Applications')"
			:description="t('openregister', 'Manage your applications and modules')"
			:showTitle="true"
			:objects="paginatedApplications"
			:columns="tableColumns"
			:pagination="paginationData"
			:loading="applicationStore.loading"
			:viewMode="applicationStore.viewMode"
			:selectable="true"
			:selectedIds="selectedApplications"
			:showEditAction="false"
			:showCopyAction="false"
			:showDeleteAction="false"
			:showMassImport="false"
			:showMassExport="false"
			:showMassCopy="false"
			:showMassDelete="false"
			showViewToggle
			:addLabel="t('openregister', 'Add Application')"
			rowKey="id"
			:emptyText="emptyContentName"
			:refreshing="isRefreshing"
			@add="
				() => {
					applicationStore.setApplicationItem(null)
					navigationStore.setModal('editApplication')
				}
			"
			@refresh="handleRefresh"
			@pageChanged="onPageChanged"
			@pageSizeChanged="onPageSizeChanged"
			@viewModeChange="applicationStore.setViewMode($event)"
			@select="onSelect">
			<!-- Custom card template -->
			<template #card="{ object }">
				<div class="card">
					<div class="cardHeader">
						<h2 :title="object.description">
							<ApplicationOutline :size="20" />
							{{ object.name }}
						</h2>
						<NcActions
							:primary="true"
							:menuName="t('openregister', 'Actions')">
							<template #icon>
								<DotsHorizontal :size="20" />
							</template>
							<NcActionButton
								closeAfterClick
								@click="
									() => {
										applicationStore.setApplicationItem(object)
										navigationStore.setModal('editApplication')
									}
								">
								<template #icon>
									<Pencil :size="20" />
								</template>
								{{ t('openregister', 'Edit') }}
							</NcActionButton>
							<NcActionButton
								closeAfterClick
								@click="
									() => {
										applicationStore.setApplicationItem(object)
										navigationStore.setDialog(
											'deleteApplication',
										)
									}
								">
								<template #icon>
									<TrashCanOutline :size="20" />
								</template>
								{{ t('openregister', 'Delete') }}
							</NcActionButton>
						</NcActions>
					</div>
					<!-- Application Details -->
					<div class="applicationDetails">
						<p v-if="object.description" class="applicationDescription">
							{{ object.description }}
						</p>
						<div class="applicationInfo">
							<div v-if="object.version" class="applicationInfoItem">
								<strong>{{ t('openregister', 'Version') }}:</strong>
								<span>{{ object.version }}</span>
							</div>
							<div
								v-if="object.active !== undefined"
								class="applicationInfoItem">
								<strong>{{ t('openregister', 'Status') }}:</strong>
								<span
									:class="
										object.active
											? 'status-active'
											: 'status-inactive'
									">
									{{
										object.active
											? t('openregister', 'Active')
											: t('openregister', 'Inactive')
									}}
								</span>
							</div>
							<div
								v-if="object.configurations"
								class="applicationInfoItem">
								<strong
									>{{
										t('openregister', 'Configurations')
									}}:</strong
								>
								<span>{{ object.configurations.length }}</span>
							</div>
							<div v-if="object.registers" class="applicationInfoItem">
								<strong
									>{{ t('openregister', 'Registers') }}:</strong
								>
								<span>{{ object.registers.length }}</span>
							</div>
							<div v-if="object.schemas" class="applicationInfoItem">
								<strong>{{ t('openregister', 'Schemas') }}:</strong>
								<span>{{ object.schemas.length }}</span>
							</div>
						</div>
					</div>
				</div>
			</template>

			<!-- Custom column: name with description -->
			<template #column-name="{ row }">
				<div class="titleContent">
					<strong>{{ row.name }}</strong>
					<span
						v-if="row.description"
						class="textDescription textEllipsis"
						>{{ row.description }}</span
					>
				</div>
			</template>

			<!-- Custom column: version -->
			<template #column-version="{ row }">
				{{ row.version || '-' }}
			</template>

			<!-- Custom column: active status -->
			<template #column-active="{ row }">
				<span :class="row.active ? 'status-active' : 'status-inactive'">
					{{
						row.active
							? t('openregister', 'Active')
							: t('openregister', 'Inactive')
					}}
				</span>
			</template>

			<!-- Custom column: configurations count -->
			<template #column-configurations="{ row }">
				{{ row.configurations?.length || 0 }}
			</template>

			<!-- Custom column: registers count -->
			<template #column-registers="{ row }">
				{{ row.registers?.length || 0 }}
			</template>

			<!-- Custom column: schemas count -->
			<template #column-schemas="{ row }">
				{{ row.schemas?.length || 0 }}
			</template>

			<!-- Custom column: created date -->
			<template #column-created="{ row }">
				{{
					row.created
						? new Date(row.created).toLocaleDateString({
								day: '2-digit',
								month: '2-digit',
								year: 'numeric',
							})
							+ ', '
							+ new Date(row.created).toLocaleTimeString({
								hour: '2-digit',
								minute: '2-digit',
								second: '2-digit',
							})
						: '-'
				}}
			</template>

			<!-- Custom row actions for table view -->
			<template #row-actions="{ row }">
				<NcActions :primary="false">
					<template #icon>
						<DotsHorizontal :size="20" />
					</template>
					<NcActionButton
						closeAfterClick
						@click="
							() => {
								applicationStore.setApplicationItem(row)
								navigationStore.setModal('editApplication')
							}
						">
						<template #icon>
							<Pencil :size="20" />
						</template>
						{{ t('openregister', 'Edit') }}
					</NcActionButton>
					<NcActionButton
						closeAfterClick
						@click="
							() => {
								applicationStore.setApplicationItem(row)
								navigationStore.setDialog('deleteApplication')
							}
						">
						<template #icon>
							<TrashCanOutline :size="20" />
						</template>
						{{ t('openregister', 'Delete') }}
					</NcActionButton>
				</NcActions>
			</template>
		</CnIndexPage>
	</NcAppContent>
</template>

<script>
import { CnIndexPage } from '@conduction/nextcloud-vue'
import { NcActionButton, NcActions, NcAppContent } from '@nextcloud/vue'
import ApplicationOutline from 'vue-material-design-icons/ApplicationOutline.vue'
import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'

export default {
	name: 'ApplicationsIndex',
	components: {
		NcAppContent,
		CnIndexPage,
		NcActions,
		NcActionButton,
		ApplicationOutline,
		DotsHorizontal,
		Pencil,
		TrashCanOutline,
	},

	data() {
		return {
			selectedApplications: [],
			isRefreshing: false,
		}
	},

	computed: {
		/**
		 * Column definitions for the applications table.
		 *
		 * @spec exclude list-view table column definitions (computed)
		 * @return {Array<object>}
		 */
		tableColumns() {
			return [
				{ key: 'name', label: t('openregister', 'Name'), sortable: true },
				{ key: 'version', label: t('openregister', 'Version') },
				{ key: 'active', label: t('openregister', 'Status') },
				{
					key: 'configurations',
					label: t('openregister', 'Configurations'),
				},
				{ key: 'registers', label: t('openregister', 'Registers') },
				{ key: 'schemas', label: t('openregister', 'Schemas') },
				{
					key: 'created',
					label: t('openregister', 'Created'),
					sortable: true,
				},
			]
		},

		/**
		 * Pagination state derived from the application store, for display.
		 *
		 * @spec exclude list-view pagination summary helper (computed)
		 * @return {object}
		 */
		paginationData() {
			const page = applicationStore.pagination.page || 1
			const limit = applicationStore.pagination.limit || 20
			const total = applicationStore.applicationList.length
			const pages = Math.ceil(total / limit)
			return { page, pages, total, limit }
		},

		/**
		 * The applications for the current page. The full list is loaded
		 * client-side, so CnIndexPage (prop mode) does not slice — we slice here
		 * so paging works.
		 *
		 * @spec exclude list-view client-side pagination slice (computed)
		 * @return {Array<object>}
		 */
		paginatedApplications() {
			const { page, limit } = this.paginationData
			const start = (page - 1) * limit
			return applicationStore.applicationList.slice(start, start + limit)
		},

		/**
		 * Empty-content label shown when the application list is empty or loading.
		 *
		 * @spec exclude list-view empty-state title text helper (computed)
		 * @return {string}
		 */
		emptyContentName() {
			if (applicationStore.error) {
				return applicationStore.error
			} else if (!applicationStore.applicationList.length) {
				return t('openregister', 'No applications found')
			}
			return t('openregister', 'Loading applications...')
		},
	},

	/**
	 * @spec exclude list-view lifecycle group preload + soft-refresh of the application list on mount
	 */
	async mounted() {
		// Load Nextcloud groups into store first (needed for edit modal)
		await applicationStore.loadNextcloudGroups()
		// Use soft reload (no loading spinner) since data is hot-loaded at app startup
		applicationStore.refreshApplicationList(null, true)
	},

	methods: {
		/**
		 * Reload the application list from the store.
		 *
		 * @spec exclude list-view manual refresh plumbing
		 * @return {Promise<void>}
		 */
		async handleRefresh() {
			this.isRefreshing = true
			try {
				await applicationStore.refreshApplicationList()
			} finally {
				this.isRefreshing = false
			}
		},

		/**
		 * Update store pagination when the page changes.
		 *
		 * @spec exclude list-view pagination page-change handler
		 * @param {number} page - the new page number
		 * @return {void}
		 */
		onPageChanged(page) {
			applicationStore.setPagination(page, applicationStore.pagination.limit)
		},

		/**
		 * Update store pagination when the page size changes.
		 *
		 * @spec exclude list-view pagination page-size-change handler
		 * @param {number} pageSize - the new page size
		 * @return {void}
		 */
		onPageSizeChanged(pageSize) {
			applicationStore.setPagination(1, pageSize)
		},

		/**
		 * Track the selected application ids for bulk actions.
		 *
		 * @spec exclude list-view row-selection state setter
		 * @param {Array} ids - the selected application ids
		 * @return {void}
		 */
		onSelect(ids) {
			this.selectedApplications = ids
		},
	},
}
</script>

<style scoped>
.applicationDetails {
	margin-top: 1rem;
}

.applicationDescription {
	color: var(--color-text-lighter);
	margin-bottom: 1rem;
}

.applicationInfo {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
}

.applicationInfoItem {
	display: flex;
	gap: 0.5rem;
}

.applicationInfoItem strong {
	min-width: 120px;
}

.status-active {
	color: var(--color-success);
	font-weight: 600;
}

.status-inactive {
	color: var(--color-text-lighter);
}
</style>
