<script setup>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { schemaStore, navigationStore, configurationStore } from '../../store/store.js'
</script>

<template>
	<NcAppContent>
		<CnIndexPage
			ref="indexPage"
			:title="t('openregister', 'Schemas')"
			:description="t('openregister', 'Manage your data schemas and their properties')"
			:show-title="true"
			:objects="paginatedSchemas"
			:columns="tableColumns"
			:pagination="paginationData"
			:view-mode="schemaStore.viewMode"
			:selectable="true"
			:selected-ids="selectedSchemas"
			:show-edit-action="false"
			:show-copy-action="false"
			:show-delete-action="false"
			:show-mass-import="false"
			:show-mass-export="false"
			:show-mass-copy="false"
			:show-mass-delete="false"
			show-view-toggle
			:add-label="t('openregister', 'Add Schema')"
			row-key="id"
			:empty-text="emptyContentName"
			:row-class="getRowClass"
			:refreshing="isRefreshing"
			@add="schemaStore.setSchemaItem(null); navigationStore.setModal('editSchema')"
			@refresh="handleRefresh"
			@page-changed="onPageChanged"
			@page-size-changed="onPageSizeChanged"
			@view-mode-change="schemaStore.setViewMode($event)"
			@select="onSelect">
			<!-- TODO: Convert EditSchema.vue to a component in @conduction/nextcloud-vue -->

			<!-- Custom card template -->
			<template #card="{ object }">
				<RegisterSchemaCard :item="object" type="schema" @refresh="handleRefresh" />
			</template>

			<!-- Custom column: title with badges -->
			<template #column-title="{ row }">
				<div class="titleContent">
					<div class="titleWithBadges">
						<strong>{{ row.title }}</strong>
						<span v-if="row.extend" class="statusPill statusPill--alert">
							{{ t('openregister', 'Extended') }}
						</span>
						<span v-if="hasObjects(row)" class="statusPill statusPill--success">
							{{ t('openregister', 'In use') }}
						</span>
						<span v-if="isManagedByExternalConfig(row)" class="managedBadge managedBadge--external">
							<CogOutline :size="16" />
							{{ t('openregister', 'Managed') }}
						</span>
						<span v-else-if="isManagedByLocalConfig(row)" class="managedBadge managedBadge--local">
							<CogOutline :size="16" />
							{{ t('openregister', 'Local') }}
						</span>
					</div>
					<span v-if="row.description" class="textDescription textEllipsis">{{ row.description }}</span>
				</div>
			</template>

			<!-- Custom column: properties count -->
			<template #column-properties="{ row }">
				{{ Object.keys(row.properties || {}).length }}
			</template>

			<!-- Custom column: created date -->
			<template #column-created="{ row }">
				{{ row.created ? new Date(row.created).toLocaleDateString({day: '2-digit', month: '2-digit', year: 'numeric'}) + ', ' + new Date(row.created).toLocaleTimeString({hour: '2-digit', minute: '2-digit', second: '2-digit'}) : '-' }}
			</template>

			<!-- Custom column: updated date -->
			<template #column-updated="{ row }">
				{{ row.updated ? new Date(row.updated).toLocaleDateString({day: '2-digit', month: '2-digit', year: 'numeric'}) + ', ' + new Date(row.updated).toLocaleTimeString({hour: '2-digit', minute: '2-digit', second: '2-digit'}) : '-' }}
			</template>

			<!-- Custom row actions for table view -->
			<template #row-actions="{ row }">
				<NcActions :primary="false">
					<template #icon>
						<DotsHorizontal :size="20" />
					</template>
					<NcActionButton
						:title="isManagedByExternalConfig(row) ? 'Cannot edit: This schema is managed by external configuration ' + (getManagingConfiguration(row)?.title || '') : ''"
						close-after-click
						:disabled="isManagedByExternalConfig(row)"
						@click="schemaStore.setSchemaItem(row); navigationStore.setModal('editSchema')">
						<template #icon>
							<Pencil :size="20" />
						</template>
						Edit
					</NcActionButton>
					<NcActionButton :title="row.stats?.objects?.total > 0 ? 'Cannot delete: objects are still attached' : ''"
						close-after-click
						:disabled="row.stats?.objects?.total > 0"
						@click="schemaStore.setSchemaItem(row); navigationStore.setDialog('deleteSchema')">
						<template #icon>
							<TrashCanOutline :size="20" />
						</template>
						Delete
					</NcActionButton>
				</NcActions>
			</template>
		</CnIndexPage>
	</NcAppContent>
</template>

<script>
import { NcAppContent, NcActions, NcActionButton } from '@nextcloud/vue'
import { CnIndexPage } from '@conduction/nextcloud-vue'
import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import CogOutline from 'vue-material-design-icons/CogOutline.vue'
import RegisterSchemaCard from '../../components/cards/RegisterSchemaCard.vue'

export default {
	name: 'SchemasIndex',
	components: {
		NcAppContent,
		CnIndexPage,
		NcActions,
		NcActionButton,
		DotsHorizontal,
		Pencil,
		TrashCanOutline,
		CogOutline,
		RegisterSchemaCard,
	},
	data() {
		return {
			selectedSchemas: [],
			isRefreshing: false,
		}
	},
	computed: {
		/**
		 * Column definitions for the schemas table.
		 *
		 * @spec exclude UI plumbing — static table column list for display
		 * @return {Array<object>}
		 */
		tableColumns() {
			return [
				{ key: 'title', label: t('openregister', 'Title'), sortable: true },
				{ key: 'properties', label: t('openregister', 'Properties') },
				{ key: 'created', label: t('openregister', 'Created'), sortable: true },
				{ key: 'updated', label: t('openregister', 'Updated'), sortable: true },
			]
		},
		/**
		 * Pagination state derived from the schema store, for display.
		 *
		 * @spec exclude UI plumbing — derived pagination view state
		 * @return {object}
		 */
		paginationData() {
			const page = schemaStore.pagination.page || 1
			const limit = schemaStore.pagination.limit || 20
			const total = schemaStore.schemaList.length
			const pages = Math.ceil(total / limit)
			return { page, pages, total, limit }
		},
		/**
		 * The schemas for the current page. The full list is loaded client-side,
		 * so CnIndexPage (prop mode) does not slice — we slice here so paging works.
		 *
		 * @spec exclude UI plumbing — derived per-page slice for display
		 * @return {Array<object>}
		 */
		paginatedSchemas() {
			const { page, limit } = this.paginationData
			const start = (page - 1) * limit
			return schemaStore.schemaList.slice(start, start + limit)
		},
		/**
		 * Empty-content label shown when the schema list is empty or loading.
		 *
		 * @spec exclude UI plumbing — derived empty-state label
		 * @return {string}
		 */
		emptyContentName() {
			if (!schemaStore.schemaList.length) {
				return t('openregister', 'No schemas found')
			}
			return t('openregister', 'Loading schemas...')
		},
	},
	/**
	 * Lifecycle hook: load schemas and configurations on mount.
	 *
	 * @spec exclude UI plumbing — view-mount data fetch for display only
	 * @return {Promise<void>}
	 */
	async mounted() {
		try {
			await Promise.all([
				schemaStore.refreshSchemaList(),
				configurationStore.refreshConfigurationList(),
			])
		} catch (error) {
			console.error('Failed to load data:', error)
		}
	},
	methods: {
		/**
		 * Reload the schema list from the store.
		 *
		 * @spec exclude UI plumbing — refresh button delegates to the store
		 * @return {Promise<void>}
		 */
		async handleRefresh() {
			this.isRefreshing = true
			try {
				await schemaStore.refreshSchemaList()
			} finally {
				this.isRefreshing = false
			}
		},

		/**
		 * Update store pagination when the page changes.
		 *
		 * @param {number} page The new page number.
		 * @spec exclude UI plumbing — pagination handler delegates to the store
		 * @return {void}
		 */
		onPageChanged(page) {
			schemaStore.setPagination(page, schemaStore.pagination.limit)
		},

		/**
		 * Update store pagination when the page size changes.
		 *
		 * @param {number} pageSize The new page size.
		 * @spec exclude UI plumbing — pagination handler delegates to the store
		 * @return {void}
		 */
		onPageSizeChanged(pageSize) {
			schemaStore.setPagination(1, pageSize)
		},

		/**
		 * Track the selected schema ids for bulk actions.
		 *
		 * @param {Array} ids The selected schema ids.
		 * @spec exclude UI plumbing — local selection state update
		 * @return {void}
		 */
		onSelect(ids) {
			this.selectedSchemas = ids
		},

		/**
		 * Compute the CSS row class reflecting a schema's managed/in-use state.
		 *
		 * @param {object} schema The schema row.
		 * @spec exclude UI plumbing — derived row-styling helper
		 * @return {string}
		 */
		getRowClass(schema) {
			if (this.isManagedByExternalConfig(schema)) return 'viewTableRow--managed'
			if (this.isManagedByLocalConfig(schema)) return 'viewTableRow--local'
			if (this.hasObjects(schema)) return 'viewTableRow--in-use'
			return ''
		},

		/**
		 * Whether a schema has any objects.
		 *
		 * @param {object} schema The schema row.
		 * @spec exclude UI plumbing — display predicate helper
		 * @return {boolean}
		 */
		hasObjects(schema) {
			return schema.stats?.objects?.total > 0
		},

		/**
		 * Find the configuration that manages a given schema, if any.
		 *
		 * @param {object} schema The schema row.
		 * @spec exclude UI plumbing — display lookup helper
		 * @return {(object|null)}
		 */
		getManagingConfiguration(schema) {
			if (!schema || !schema.id) return null
			return configurationStore.configurationList.find(
				config => config.schemas && config.schemas.some(s => s.id === schema.id),
			) || null
		},

		/**
		 * Whether a schema is managed by an external (git/url) configuration.
		 *
		 * @param {object} schema The schema row.
		 * @spec exclude UI plumbing — display predicate helper
		 * @return {boolean}
		 */
		isManagedByExternalConfig(schema) {
			const config = this.getManagingConfiguration(schema)
			if (!config) return false
			return (config.sourceType && ['github', 'gitlab', 'url'].includes(config.sourceType)) || config.isLocal === false
		},

		/**
		 * Whether a schema is managed by a local/manual configuration.
		 *
		 * @param {object} schema The schema row.
		 * @spec exclude UI plumbing — display predicate helper
		 * @return {boolean}
		 */
		isManagedByLocalConfig(schema) {
			const config = this.getManagingConfiguration(schema)
			if (!config) return false
			return config.sourceType === 'local' || config.sourceType === 'manual' || config.isLocal === true
		},

	},
}
</script>

<style lang="scss" scoped>
/* Table row accents. Drawn with an inset box-shadow, never border-left: a
   border adds layout width and shifts the row's cell content sideways, while
   box-shadow paints inside the box. Matches .cn-table-row--selected.

   Skipped on a selected row so the library's .cn-table-row--selected accent
   wins. Scoping adds a [data-v-*] attribute, which would otherwise outweigh
   the library's single-class rule and leave a selected row showing its
   managed/local colour instead of the selection colour. */

/* Managed schemas (external - green) */
:deep(.viewTableRow--managed:not(.cn-table-row--selected)) {
	box-shadow: inset 3px 0 0 0 var(--color-success);
}

/* Local configurations (orange) */
:deep(.viewTableRow--local:not(.cn-table-row--selected)) {
	box-shadow: inset 3px 0 0 0 var(--color-warning);
}

/* In-use schemas */
:deep(.viewTableRow--in-use:not(.cn-table-row--selected)) {
	box-shadow: inset 3px 0 0 0 var(--color-success);
}

/* Status Pills */
.statusPill {
	display: inline-block;
	padding: 2px 8px;
	border-radius: 12px;
	font-size: 0.75em;
	font-weight: 600;
	text-transform: uppercase;
	margin-left: 8px;
	white-space: nowrap;
}

.statusPill--alert {
	background-color: var(--color-warning);
	color: var(--color-main-background);
}

.statusPill--success {
	background-color: var(--color-success);
	color: white;
}

/* Title with badges layout */
.titleWithBadges {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
	margin-bottom: 4px;
}

.textDescription {
	display: block;
	overflow: hidden;
}

.textEllipsis {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

/* Managed by Configuration badge */
.managedBadge {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	padding: 4px 8px;
	border-radius: 12px;
	font-size: 0.75rem;
	font-weight: 600;
	margin-left: 8px;
	vertical-align: middle;
}

/* External (managed) badge - green */
.managedBadge--external {
	background: var(--color-success);
	color: white;
}

/* Local configuration badge - orange */
.managedBadge--local {
	background: var(--color-warning);
	color: var(--color-main-text);
}
</style>
