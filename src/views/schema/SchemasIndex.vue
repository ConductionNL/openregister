<script setup>
import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import { listDateTime } from '../../services/listDateTime.js'
import {
	configurationStore,
	navigationStore,
	schemaStore,
} from '../../store/store.js'
</script>

<template>
	<NcAppContent>
		<CnIndexPage
			ref="indexPage"
			:title="t('openregister', 'Schemas')"
			:description="
				t('openregister', 'Manage your data schemas and their properties')
			"
			:showTitle="true"
			:objects="paginatedSchemas"
			:columns="tableColumns"
			:sortKey="sortKey"
			:sortOrder="sortOrder"
			:activeFilters="activeFilters"
			:pagination="paginationData"
			:viewMode="schemaStore.viewMode"
			:selectable="true"
			:selectedIds="selectedSchemas"
			v-bind="builtinActionsOff"
			showViewToggle
			:addLabel="t('openregister', 'Add Schema')"
			rowKey="id"
			:emptyText="emptyContentName"
			:rowClass="getRowClass"
			:refreshing="isRefreshing"
			@sort="onSort"
			@filterChange="onFilterChange"
			@add="
				() => {
					schemaStore.setSchemaItem(null)
					navigationStore.setModal('editSchema')
				}
			"
			@refresh="handleRefresh"
			@pageChanged="onPageChanged"
			@pageSizeChanged="onPageSizeChanged"
			@viewModeChange="schemaStore.setViewMode($event)"
			@select="onSelect">
			<!-- TODO: Convert EditSchema.vue to a component in @conduction/nextcloud-vue -->

			<!-- Custom card template -->
			<template #card="{ object }">
				<RegisterSchemaCard
					:item="object"
					type="schema"
					@refresh="handleRefresh" />
			</template>

			<!-- Custom column: title with badges -->
			<template #column-title="{ row }">
				<div class="titleContent">
					<div class="titleWithBadges">
						<strong>{{ row.title }}</strong>
						<span v-if="row.extend" class="statusPill statusPill--alert">
							{{ t('openregister', 'Extended') }}
						</span>
						<span
							v-if="hasObjects(row)"
							class="statusPill statusPill--success">
							{{ t('openregister', 'In use') }}
						</span>
						<ManagedBadge :config="getManagingConfiguration(row)" />
					</div>
					<IndexRowDescription :text="row.description" />
				</div>
			</template>

			<!-- Custom column: properties count -->
			<template #column-properties="{ row }">
				{{ Object.keys(row.properties || {}).length }}
			</template>

			<!-- Custom columns: created and updated dates -->
			<template
				v-for="key in ['created', 'updated']"
				#[`column-${key}`]="{ row }">
				{{ listDateTime(row[key]) }}
			</template>

			<!-- Custom row actions for table view -->
			<template #row-actions="{ row }">
				<ActionMenu :actions="schemaActions(row)" />
			</template>
		</CnIndexPage>
	</NcAppContent>
</template>

<script>
import { CnIndexPage } from '@conduction/nextcloud-vue'
import { NcAppContent } from '@nextcloud/vue'
import RegisterSchemaCard from '../../components/cards/RegisterSchemaCard.vue'
import ActionMenu from '../../components/shared/ActionMenu.vue'
import ManagedBadge from '../../components/shared/ManagedBadge.vue'
import IndexRowDescription from '../IndexRowDescription.vue'
import {
	headerListData,
	headerListMethods,
} from '../../composables/headerListControls.js'
import {
	isExternalConfiguration,
	isLocalConfiguration,
	managedRowClass,
} from '../../composables/managedConfiguration.js'
import { filterRows } from '../../services/listFilter.js'
import { sortSchemas } from '../../services/listSort.js'
import { deleteAction, editAction } from '../../services/menuActions.js'
import { timestampColumns } from '../indexPageOptions.js'

/**
 * The value a schema row is filtered on: the properties column shows a count.
 *
 * @param {object} schema The schema row.
 * @param {string} field The column key.
 * @return {unknown} The value.
 */
function schemaFilterValue(schema, field) {
	if (field === 'properties') {
		return Object.keys(schema?.properties || {}).length
	}
	return schema ? schema[field] : undefined
}

export default {
	name: 'SchemasIndex',
	components: {
		IndexRowDescription,
		ActionMenu,
		NcAppContent,
		CnIndexPage,
		ManagedBadge,
		RegisterSchemaCard,
	},

	data() {
		return {
			...headerListData(),
			selectedSchemas: [],
		}
	},

	computed: {
		/**
		 * Column definitions for the schemas table. The `type` hints tell the
		 * header filter how each column filters: dates by range, the property
		 * count by number, everything else on contains.
		 *
		 * @spec openspec/specs/admin-list-views/spec.md#requirement-openregister-s-schemas-and-registers-lists-must-filter-from-their-column-headers
		 * @return {Array<object>}
		 */
		tableColumns() {
			return [
				{ key: 'title', label: t('openregister', 'Title'), sortable: true },
				{
					key: 'properties',
					label: t('openregister', 'Properties'),
					type: 'integer',
				},
				...timestampColumns({ type: 'date-time' }),
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
			const total = this.orderedSchemas.length
			const pages = Math.ceil(total / limit)
			return { page, pages, total, limit }
		},

		/**
		 * The full schema list in a DETERMINISTIC order, newest first.
		 *
		 * The list is sliced client-side to one page, so whatever order the API
		 * happened to return decides what page 1 shows. Left as-is, a
		 * just-created schema sorts LAST and is therefore invisible on any
		 * instance holding more schemas than fit on a page — the plain
		 * "I just made this, where is it?" failure. It is not an error and not
		 * an empty list: the page renders perfectly, simply without the row the
		 * user is looking for.
		 *
		 * `schema-crud.spec.ts` asserts exactly that ("schema lists as a real
		 * row") and had been passing only because the seeded instance held fewer
		 * schemas than one page. It began failing the moment a 21st schema
		 * existed, which is the honest report of a defect that was already there.
		 *
		 * Highest id first, because id is the only monotonic field every schema
		 * carries. A sortable header the user clicks takes over (`onSort`); the
		 * headers were declared sortable but nothing handled the click, so the
		 * order never moved (live audit G2).
		 *
		 * @spec openspec/specs/audit-trail-immutable/spec.md
		 * @return {Array<object>}
		 */
		orderedSchemas() {
			return sortSchemas(
				filterRows(
					schemaStore.schemaList,
					this.activeFilters,
					schemaFilterValue,
				),
				this.sortKey,
				this.sortOrder,
			)
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
			return this.orderedSchemas.slice(start, start + limit)
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
			if (!this.orderedSchemas.length) {
				return t('openregister', 'No matches')
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
		...headerListMethods(() => schemaStore),

		/**
		 * The row menu of a schema.
		 *
		 * @spec exclude Client-side menu composition.
		 * @param {object} row The schema
		 * @return {Array<object>} The ActionMenu entries
		 */
		schemaActions(row) {
			return [this.schemaEditAction(row), this.schemaDeleteAction(row)]
		},

		/**
		 * The edit entry, locked while an external configuration manages the schema.
		 *
		 * @spec exclude Client-side menu composition.
		 * @param {object} row The schema
		 * @return {object} The ActionMenu entry
		 */
		schemaEditAction(row) {
			const openEditor = () => {
				schemaStore.setSchemaItem(row)
				navigationStore.setModal('editSchema')
			}
			return editAction(openEditor, {
				disabled: this.isManagedByExternalConfig(row),
				title: this.editLockReason(row),
			})
		},

		/**
		 * Why the edit entry is locked: '' unless an external configuration
		 * manages the schema.
		 *
		 * @spec exclude Client-side menu composition.
		 * @param {object} row The schema
		 * @return {string} The reason
		 */
		editLockReason(row) {
			if (!this.isManagedByExternalConfig(row)) {
				return ''
			}
			return (
				'Cannot edit: This schema is managed by external configuration '
				+ (this.getManagingConfiguration(row)?.title || '')
			)
		},

		/**
		 * The delete entry, locked while objects are attached.
		 *
		 * @spec exclude Client-side menu composition.
		 * @param {object} row The schema
		 * @return {object} The ActionMenu entry
		 */
		schemaDeleteAction(row) {
			const attached = this.hasObjects(row)
			const confirmDelete = () => {
				schemaStore.setSchemaItem(row)
				navigationStore.setDialog('deleteSchema')
			}
			return deleteAction(confirmDelete, {
				disabled: attached,
				title: attached ? 'Cannot delete: objects are still attached' : '',
			})
		},

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
			return (
				managedRowClass(this.getManagingConfiguration(schema))
				|| (this.hasObjects(schema) ? 'viewTableRow--in-use' : '')
			)
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
			return (
				configurationStore.configurationList.find(
					(config) =>
						config.schemas
						&& config.schemas.some((s) => s.id === schema.id),
				) || null
			)
		},

		/**
		 * Whether a schema is managed by an external (git/url) configuration.
		 *
		 * @param {object} schema The schema row.
		 * @spec exclude UI plumbing — display predicate helper
		 * @return {boolean}
		 */
		isManagedByExternalConfig(schema) {
			return isExternalConfiguration(this.getManagingConfiguration(schema))
		},

		/**
		 * Whether a schema is managed by a local/manual configuration.
		 *
		 * @param {object} schema The schema row.
		 * @spec exclude UI plumbing — display predicate helper
		 * @return {boolean}
		 */
		isManagedByLocalConfig(schema) {
			return isLocalConfiguration(this.getManagingConfiguration(schema))
		},
	},
}
</script>

<style lang="scss" scoped>
@use '../../styles/common.scss' as *;
@use '../../styles/views.scss' as *;
@use '../managedRowAccents.scss' as *;

@include managed-row-accents;

/* In-use schemas */
:deep(.viewTableRow--in-use:not(.cn-table-row--selected)) {
	box-shadow: inset 3px 0 0 0 var(--color-success);
}

/* Status Pills */
@include status-pill-rules;

/* Title with badges layout */
@include title-with-badges;

.textDescription {
	display: block;
	overflow: hidden;
}

.textEllipsis {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}
</style>
