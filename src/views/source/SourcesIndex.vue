<script setup>
import { translate as t } from '@nextcloud/l10n'
import { sourceStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcAppContent>
		<CnIndexPage
			ref="indexPage"
			:title="t('openregister', 'Sources')"
			:description="
				t(
					'openregister',
					'Manage your data sources and their configurations',
				)
			"
			:show-title="true"
			:objects="paginatedSources"
			:columns="tableColumns"
			:pagination="paginationData"
			:view-mode="viewMode"
			:selectable="true"
			:selected-ids="selectedSources"
			:show-edit-action="false"
			:show-copy-action="false"
			:show-delete-action="false"
			:show-mass-import="false"
			:show-mass-export="false"
			:show-mass-copy="false"
			:show-mass-delete="false"
			show-view-toggle
			:add-label="t('openregister', 'Add Source')"
			row-key="id"
			:empty-text="emptyContentName"
			:refreshing="isRefreshing"
			@add="
				sourceStore.setSourceItem(null)
				navigationStore.setModal('editSource')
			"
			@refresh="handleRefresh"
			@page-changed="onPageChanged"
			@page-size-changed="onPageSizeChanged"
			@view-mode-change="viewMode = $event"
			@select="onSelect">
			<!-- Custom card template -->
			<template #card="{ object }">
				<div class="card">
					<div class="cardHeader">
						<h2 :title="object.description">
							<DatabaseArrowRightOutline :size="20" />
							{{ object.title }}
						</h2>
						<NcActions :primary="true" menu-name="Actions">
							<template #icon>
								<DotsHorizontal :size="20" />
							</template>
							<NcActionButton
								close-after-click
								@click="
									sourceStore.setSourceItem(object)
									navigationStore.setModal('viewSource')
								">
								<template #icon>
									<Eye :size="20" />
								</template>
								{{ t('openregister', 'View') }}
							</NcActionButton>
							<NcActionButton
								close-after-click
								@click="
									sourceStore.setSourceItem(object)
									navigationStore.setModal('editSource')
								">
								<template #icon>
									<Pencil :size="20" />
								</template>
								{{ t('openregister', 'Edit') }}
							</NcActionButton>
							<NcActionButton
								close-after-click
								@click="
									sourceStore.setSourceItem(object)
									navigationStore.setDialog('deleteSource')
								">
								<template #icon>
									<TrashCanOutline :size="20" />
								</template>
								{{ t('openregister', 'Delete') }}
							</NcActionButton>
						</NcActions>
					</div>
					<!-- Source Details -->
					<div class="sourceDetails">
						<p v-if="object.description" class="sourceDescription">
							{{ object.description }}
						</p>
						<div class="sourceInfo">
							<div class="sourceInfoItem">
								<strong>{{ t('openregister', 'Type') }}:</strong>
								<span>{{
									object.type || t('openregister', 'Unknown')
								}}</span>
							</div>
							<div v-if="object.databaseUrl" class="sourceInfoItem">
								<strong
									>{{ t('openregister', 'Database URL') }}:</strong
								>
								<span class="truncatedUrl">{{
									object.databaseUrl
								}}</span>
							</div>
							<div class="sourceInfoItem">
								<strong
									>{{ t('openregister', 'Registers') }}:</strong
								>
								<span>{{ getSourceRegisterCount(object.id) }}</span>
							</div>
						</div>
					</div>
				</div>
			</template>

			<!-- Custom column: title with description -->
			<template #column-title="{ row }">
				<div class="titleContent">
					<strong>{{ row.title }}</strong>
					<span
						v-if="row.description"
						class="textDescription textEllipsis"
						>{{ row.description }}</span
					>
				</div>
			</template>

			<!-- Custom column: type -->
			<template #column-type="{ row }">
				{{ row.type || t('openregister', 'Unknown') }}
			</template>

			<!-- Custom column: database url -->
			<template #column-databaseUrl="{ row }">
				<span v-if="row.databaseUrl" class="truncatedUrl">{{
					row.databaseUrl
				}}</span>
				<span v-else>-</span>
			</template>

			<!-- Custom column: registers count -->
			<template #column-registers="{ row }">
				{{ getSourceRegisterCount(row.id) }}
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

			<!-- Custom column: updated date -->
			<template #column-updated="{ row }">
				{{
					row.updated
						? new Date(row.updated).toLocaleDateString({
								day: '2-digit',
								month: '2-digit',
								year: 'numeric',
							})
							+ ', '
							+ new Date(row.updated).toLocaleTimeString({
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
						close-after-click
						@click="
							sourceStore.setSourceItem(row)
							navigationStore.setModal('viewSource')
						">
						<template #icon>
							<Eye :size="20" />
						</template>
						{{ t('openregister', 'View') }}
					</NcActionButton>
					<NcActionButton
						close-after-click
						@click="
							sourceStore.setSourceItem(row)
							navigationStore.setModal('editSource')
						">
						<template #icon>
							<Pencil :size="20" />
						</template>
						{{ t('openregister', 'Edit') }}
					</NcActionButton>
					<NcActionButton
						close-after-click
						@click="
							sourceStore.setSourceItem(row)
							navigationStore.setDialog('deleteSource')
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
import { NcAppContent, NcActions, NcActionButton } from '@nextcloud/vue'
import { CnIndexPage } from '@conduction/nextcloud-vue'
import DatabaseArrowRightOutline from 'vue-material-design-icons/DatabaseArrowRightOutline.vue'
import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import Eye from 'vue-material-design-icons/Eye.vue'

export default {
	name: 'SourcesIndex',
	components: {
		NcAppContent,
		CnIndexPage,
		NcActions,
		NcActionButton,
		DatabaseArrowRightOutline,
		DotsHorizontal,
		Pencil,
		TrashCanOutline,
		Eye,
	},
	data() {
		return {
			viewMode: 'cards',
			selectedSources: [],
			isRefreshing: false,
			pagination: {
				page: 1,
				limit: 20,
			},
		}
	},
	computed: {
		/**
		 * Column definitions for the sources table.
		 *
		 * @spec exclude UI plumbing — static table column list for display.
		 * @return {Array<object>}
		 */
		tableColumns() {
			return [
				{ key: 'title', label: t('openregister', 'Title'), sortable: true },
				{ key: 'type', label: t('openregister', 'Type') },
				{ key: 'databaseUrl', label: t('openregister', 'Database URL') },
				{ key: 'registers', label: t('openregister', 'Registers') },
				{
					key: 'created',
					label: t('openregister', 'Created'),
					sortable: true,
				},
				{
					key: 'updated',
					label: t('openregister', 'Updated'),
					sortable: true,
				},
			]
		},
		/**
		 * Pagination state for display.
		 *
		 * @spec exclude UI plumbing — derived pagination view state; admin list contract owned by admin-list-views.
		 * @return {object}
		 */
		paginationData() {
			const page = this.pagination.page || 1
			const limit = this.pagination.limit || 20
			const total = sourceStore.sourceList.length
			const pages = Math.ceil(total / limit)
			return { page, pages, total, limit }
		},
		/**
		 * Current page slice of the source list. The full list is loaded
		 * client-side, so CnIndexPage (prop mode) does not slice — we slice here
		 * so paging works.
		 *
		 * @spec exclude UI plumbing — client-side pagination computed; admin list contract owned by admin-list-views.
		 * @return {Array}
		 */
		paginatedSources() {
			const { page, limit } = this.paginationData
			const start = (page - 1) * limit
			return sourceStore.sourceList.slice(start, start + limit)
		},
		/**
		 * Empty-state title reflecting loading/empty.
		 *
		 * @spec exclude UI plumbing — derived empty-state copy, no observable contract.
		 * @return {string}
		 */
		emptyContentName() {
			if (!sourceStore.sourceList.length) {
				return t('openregister', 'No sources found')
			}
			return t('openregister', 'Loading sources...')
		},
	},
	/**
	 * Soft-refresh the source list on mount.
	 *
	 * @spec exclude UI plumbing — lifecycle hook delegating to the store; list contract owned by admin-list-views.
	 * @return {void}
	 */
	mounted() {
		// Use soft reload (no loading spinner) since data is hot-loaded at app startup
		sourceStore.refreshSourceList(null, true)
	},
	methods: {
		/**
		 * Reload the source list from the store.
		 *
		 * @spec exclude UI plumbing — refresh button delegates to the store.
		 * @return {Promise<void>}
		 */
		async handleRefresh() {
			this.isRefreshing = true
			try {
				await sourceStore.refreshSourceList()
			} finally {
				this.isRefreshing = false
			}
		},
		/**
		 * Handle a page change from the paginator.
		 *
		 * @spec exclude UI plumbing — pagination state mutation; admin list contract owned by admin-list-views.
		 * @param {number} page - new page number
		 * @return {void}
		 */
		onPageChanged(page) {
			this.pagination.page = page
		},
		/**
		 * Handle a page-size change from the paginator.
		 *
		 * @spec exclude UI plumbing — pagination state mutation; admin list contract owned by admin-list-views.
		 * @param {number} pageSize - new page size
		 * @return {void}
		 */
		onPageSizeChanged(pageSize) {
			this.pagination.page = 1
			this.pagination.limit = pageSize
		},
		/**
		 * Track the selected source ids for bulk actions.
		 *
		 * @spec exclude UI plumbing — row-selection state mutation; admin list contract owned by admin-list-views.
		 * @param {Array} ids - selected source ids
		 * @return {void}
		 */
		onSelect(ids) {
			this.selectedSources = ids
		},
		/**
		 * Placeholder register-count display for a source.
		 *
		 * @spec exclude UI plumbing — unimplemented display placeholder, no observable contract.
		 * @param {string|number} _sourceId - source id
		 * @return {string}
		 */
		getSourceRegisterCount(_sourceId) {
			// This would need to be implemented based on how registers are linked to sources
			// For now, return a placeholder
			return '-'
		},
	},
}
</script>

<style scoped>
.sourceDetails {
	margin-top: 1rem;
}

.sourceDescription {
	color: var(--color-text-lighter);
	margin-bottom: 1rem;
}

.sourceInfo {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
}

.sourceInfoItem {
	display: flex;
	gap: 0.5rem;
}

.sourceInfoItem strong {
	min-width: 100px;
}

.truncatedUrl {
	max-width: 200px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	display: inline-block;
}
</style>
