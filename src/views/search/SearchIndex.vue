<script>
/**
 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-search-across-registers-global-search
 */
import { translate as t } from '@nextcloud/l10n'
import { NcAppContent, NcActions, NcActionButton } from '@nextcloud/vue'
import { CnIndexPage, CnObjectKanban, CnObjectCalendar } from '@conduction/nextcloud-vue'
import { navigationStore, objectStore, registerStore, schemaStore, viewsStore } from '../../store/store.js'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'

/**
 * Normalize list so each row has top-level id for CnIndexPage rowKey.
 * @param {Array} raw - Raw collection from store
 * @return {Array} Rows with row.id set from @self.id
 */
function normalizeObjects(raw) {
	if (!Array.isArray(raw)) return []
	return raw.map((row) => {
		const id = row['@self']?.id ?? row.id
		return { ...row, id }
	})
}

export default {
	name: 'SearchIndex',
	components: {
		NcAppContent,
		NcActions,
		NcActionButton,
		CnIndexPage,
		CnObjectKanban,
		CnObjectCalendar,
		Pencil,
		ContentCopy,
		TrashCanOutline,
	},
	data() {
		return {
			objectStore,
			navigationStore,
			viewsStore,
			isAddingNewObject: false,
			// Kanban board state (REQ-VIEW-KANBAN-02): the pre-built `columns`
			// shape from GET /api/views/{id}/kanban, refetched wholesale on
			// "load more" (see fetchKanbanBoard/handleKanbanLoadMore) since the
			// backend paginates every column with the same _limit/_offset.
			kanbanBoard: null,
			kanbanLoading: false,
			// Column values currently doing a "load more" fetch — drives
			// CnObjectKanban's per-column spinner.
			loadingColumns: [],
			// Calendar objects for the currently visible range
			// (REQ-VIEW-CAL-04), refetched on CnObjectCalendar's `range-change`.
			calendarObjects: [],
			calendarLoading: false,
		}
	},
	computed: {
		/**
		 * Normalized search result objects for table display.
		 *
		 * @spec exclude UI plumbing — derived view state from the store
		 * @return {Array<object>}
		 */
		normalizedObjects() {
			return normalizeObjects(objectStore.searchCollection)
		},
		/**
		 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-search-across-registers-global-search
		 */
		hasSelectedRegisters() {
			return objectStore.searchParams.register != null
		},
		hasSelectedSchemas() {
			return objectStore.searchParams.schema != null
		},
		/**
		 * Page title derived from the selected register and schema.
		 *
		 * @spec exclude UI plumbing — derived header title for display
		 * @return {string}
		 */
		pageTitle() {
			if (!this.hasSelectedRegisters) return 'No register selected'
			const reg = registerStore.registerList.find((r) => r.id === objectStore.searchParams.register)
			const regTitle = reg ? (reg.label || reg.title) : 'Register'
			if (!this.hasSelectedSchemas) return `${regTitle} / No schema selected`
			const schema = schemaStore.schemaList.find((s) => s.id === objectStore.searchParams.schema)
			const schemaTitle = schema ? (schema.label || schema.title) : 'Schema'
			return `${regTitle} / ${schemaTitle}`
		},
		/**
		 * Selected object ids for the current page, as strings.
		 *
		 * @spec exclude UI plumbing — derived selection view state
		 * @return {Array<string>}
		 */
		selectedIdsForPage() {
			const list = objectStore.selectedObjects
			return Array.isArray(list) ? list.map(String) : []
		},
		/**
		 * Object-type slug derived from the selected register and schema.
		 *
		 * @spec exclude UI plumbing — derived identifier for the index page
		 * @return {string}
		 */
		computedObjectType() {
			return objectStore.createObjectTypeSlug(objectStore.searchRegister, objectStore.searchSchema)
		},
		/**
		 * Search schema with inherited (allOf) properties merged in, for columns.
		 *
		 * @spec exclude UI plumbing — derived schema view state for display
		 * @return {object}
		 */
		normalizedSchema() {
			const schema = objectStore.searchSchema
			if (!schema || !schema.properties) return schema
			// Merge inherited properties from allOf parent schemas so extended schemas
			// expose the full property set (own + inherited) for columns and form fields.
			const allOf = schema.allOf || []
			const inheritedProperties = {}
			for (const ref of allOf) {
				const schemaId = typeof ref === 'object' ? ref.id : ref
				const parentSchema = schemaStore.schemaList.find(s =>
					s.id === schemaId || s.uuid === schemaId || String(s.id) === String(schemaId),
				)
				if (parentSchema?.properties) {
					Object.assign(inheritedProperties, parentSchema.properties)
				}
			}
			// Own properties take precedence over inherited; normalize order values
			const rawProperties = { ...inheritedProperties, ...schema.properties }
			const properties = {}
			for (const [key, prop] of Object.entries(rawProperties)) {
				properties[key] = prop.order !== undefined
					? { ...prop, order: Number(prop.order) }
					: prop
			}
			return { ...schema, properties }
		},
		/**
		 * The currently active saved view, if any.
		 *
		 * @spec exclude UI plumbing — proxies the active view from the views store
		 * @return {object|null}
		 */
		activeView() {
			return viewsStore.activeView
		},
		/**
		 * Stable identity for the active view, used to key the kanban/calendar
		 * components (forces a remount — and for the calendar, a fresh
		 * `range-change` — when the user switches saved views) and to trigger
		 * a board/range refetch.
		 *
		 * @spec exclude UI plumbing — derived key for the active view
		 * @return {string|number|null}
		 */
		activeViewId() {
			return this.activeView ? (this.activeView.id || this.activeView.uuid) : null
		},
		/**
		 * The active view's presentation type. A view with no `presentation`
		 * (legacy view, or none selected) renders as `table` — unchanged from
		 * before this dispatch existed (REQ-VIEW-PRES-01 scenario: legacy view
		 * still renders as table).
		 *
		 * @spec openspec/specs/saved-search-views/spec.md#requirement-presentation-components-are-shared-and-wired-not-owned-by-or-req-view-pres-05
		 * @return {string}
		 */
		presentationType() {
			return this.activeView?.presentation?.viewType || 'table'
		},
		/**
		 * The active view's kanban config (`groupByField`/`columnOrder`/`cardFields`).
		 *
		 * @spec exclude UI plumbing — derived view config for CnObjectKanban props
		 * @return {object}
		 */
		kanbanConfig() {
			return this.activeView?.presentation?.kanban || {}
		},
		/**
		 * The active view's calendar config (`dateField`/`endDateField`).
		 *
		 * @spec exclude UI plumbing — derived view config for CnObjectCalendar props
		 * @return {object}
		 */
		calendarConfig() {
			return this.activeView?.presentation?.calendar || {}
		},
		/**
		 * Kanban board columns with each column's cards normalized the same way
		 * as the table's `normalizedObjects` (top-level `id` from `@self.id`),
		 * so `CnObjectKanban`'s default `row-key="id"` resolves cards correctly.
		 *
		 * @spec exclude UI plumbing — derived view state from kanbanBoard
		 * @return {Array<object>}
		 */
		normalizedKanbanColumns() {
			if (!this.kanbanBoard || !Array.isArray(this.kanbanBoard.columns)) return []
			return this.kanbanBoard.columns.map((column) => ({
				...column,
				cards: normalizeObjects(column.cards),
			}))
		},
		/**
		 * Calendar objects normalized the same way as the table's
		 * `normalizedObjects`, for `CnObjectCalendar`'s `row-key="id"`.
		 *
		 * @spec exclude UI plumbing — derived view state from calendarObjects
		 * @return {Array<object>}
		 */
		normalizedCalendarObjects() {
			return normalizeObjects(this.calendarObjects)
		},
	},
	watch: {
		'navigationStore.modal'(newVal, oldVal) {
			if (oldVal === 'viewObject' && !newVal && this.isAddingNewObject) {
				this.isAddingNewObject = false
			}
		},
		/**
		 * Reset kanban/calendar state and (for kanban) fetch the board when the
		 * active saved view changes. Calendar's initial fetch instead comes
		 * from CnObjectCalendar's own `range-change` on (re)mount — the `:key`
		 * binding on the component forces that remount.
		 *
		 * @spec openspec/specs/saved-search-views/spec.md#requirement-presentation-components-are-shared-and-wired-not-owned-by-or-req-view-pres-05
		 */
		activeViewId(newVal) {
			this.kanbanBoard = null
			this.loadingColumns = []
			this.calendarObjects = []
			if (newVal && this.presentationType === 'kanban') {
				this.fetchKanbanBoard()
			}
		},
	},
	/**
	 * @spec exclude UI plumbing — fetch the kanban board on initial mount when a kanban view is already active (e.g. restored from route params)
	 */
	mounted() {
		if (this.activeViewId && this.presentationType === 'kanban') {
			this.fetchKanbanBoard()
		}
	},
	methods: {
		/**
		 * Open the new-object dialog for the selected register and schema.
		 *
		 * @spec exclude UI plumbing — opens the create-object modal
		 * @return {void}
		 */
		handleAddObject() {
			if (!this.hasSelectedRegisters || !this.hasSelectedSchemas) return
			this.isAddingNewObject = true
			objectStore.setObjectItem(null)
			if (registerStore.registerItem) {
				registerStore.setRegisterItem(registerStore.registerItem)
			}
			if (schemaStore.schemaItem) {
				schemaStore.setSchemaItem(schemaStore.schemaItem)
			}
			navigationStore.setModal('viewObject')
		},
		/**
		 * Re-run the current search.
		 *
		 * @spec exclude UI plumbing — refresh delegates to the object store
		 * @return {void}
		 */
		handleRefresh() {
			objectStore.refetchSearchCollection()
		},
		/**
		 * Apply a sort change and re-run the search.
		 *
		 * @param {object} root0 The sort event payload.
		 * @param {string} root0.key The sort column key.
		 * @param {string} root0.order The sort order.
		 * @spec exclude UI plumbing — sort handler delegates to the store
		 * @return {void}
		 */
		handleSort({ key, order }) {
			objectStore.updateSearchParams({ sortKey: key, sortOrder: order })
			objectStore.refetchSearchCollection()
		},
		/**
		 * Apply a page change and re-run the search.
		 *
		 * @param {number} page The new page number.
		 * @spec exclude UI plumbing — pagination handler delegates to the store
		 * @return {void}
		 */
		handlePageChanged(page) {
			objectStore.updateSearchParams({ page })
			objectStore.refetchSearchCollection()
		},
		/**
		 * Apply a page-size change and re-run the search.
		 *
		 * @param {number} limit The new page size.
		 * @spec exclude UI plumbing — pagination handler delegates to the store
		 * @return {void}
		 */
		handlePageSizeChanged(limit) {
			objectStore.updateSearchParams({ page: 1, limit })
			objectStore.refetchSearchCollection()
		},
		/**
		 * Track the selected object ids.
		 *
		 * @param {Array} ids The selected ids.
		 * @spec exclude UI plumbing — selection state delegates to the store
		 * @return {void}
		 */
		handleSelect(ids) {
			objectStore.setSelectedObjects(ids)
		},
		/**
		 * Open the view-object modal for a clicked row.
		 *
		 * @param {object} row The clicked object row.
		 * @spec exclude UI plumbing — opens the view-object modal
		 * @return {void}
		 */
		handleRowClick(row) {
			objectStore.setObjectItem(row)
			navigationStore.setModal('viewObject')
		},
		/**
		 * Open the copy-object dialog for a row.
		 *
		 * @param {object} row The object row to copy.
		 * @spec exclude UI plumbing — opens the copy-object dialog
		 * @return {void}
		 */
		handleCopyRow(row) {
			objectStore.setObjectItem(row)
			navigationStore.setDialog('copyObject')
		},
		/**
		 * Open the delete-object dialog for a row.
		 *
		 * @param {object} row The object row to delete.
		 * @spec exclude UI plumbing — opens the delete-object dialog
		 * @return {void}
		 */
		handleDeleteRow(row) {
			objectStore.setObjectItem(row)
			navigationStore.setDialog('deleteObject')
		},
		/**
		 * Open the mass-delete dialog for the selected ids.
		 *
		 * @param {Array} ids The selected row ids.
		 * @spec exclude UI plumbing — opens the mass-delete dialog
		 * @return {void}
		 */
		handleMassDelete(ids) {
			const rows = this.normalizedObjects.filter((r) => ids.includes(String(r.id)))
			objectStore.setSelectedObjects(rows.map((r) => r['@self']?.id ?? r.id))
			navigationStore.setDialog('massDeleteObject')
		},
		/**
		 * Open the mass-copy dialog for the selected ids.
		 *
		 * @param {object} payload The mass-copy event payload.
		 * @spec exclude UI plumbing — opens the mass-copy dialog
		 * @return {void}
		 */
		handleMassCopy(payload) {
			const ids = payload?.ids || []
			const rows = this.normalizedObjects.filter((r) => ids.includes(String(r.id)))
			objectStore.setSelectedObjects(rows.map((r) => r['@self']?.id ?? r.id))
			navigationStore.setDialog('massCopyObjects')
		},
		/**
		 * Fetch the kanban board for the active view (REQ-VIEW-KANBAN-02).
		 *
		 * @param {object} [params] Optional query params (`_limit`/`_offset`).
		 * @spec openspec/specs/saved-search-views/spec.md#requirement-kanban-columns-and-cards-req-view-kanban-02
		 * @return {Promise<void>}
		 */
		async fetchKanbanBoard(params = {}) {
			if (!this.activeViewId) return
			this.kanbanLoading = true
			try {
				this.kanbanBoard = await viewsStore.fetchKanbanBoard(this.activeViewId, params)
			} catch (e) {
				console.error('[SearchIndex] Error fetching kanban board:', e)
			} finally {
				this.kanbanLoading = false
			}
		},
		/**
		 * Handle a column's "load more" — the backend paginates every column
		 * with the same `_limit`, so a bigger board is refetched wholesale and
		 * only the requesting column's cards are taken from it, leaving the
		 * other (already-loaded) columns' local state untouched.
		 *
		 * @param {object} payload The `load-more` event payload.
		 * @param {string|number} payload.value The column's value.
		 * @param {number} payload.offset The column's current card count.
		 * @spec openspec/specs/saved-search-views/spec.md#requirement-kanban-columns-and-cards-req-view-kanban-02
		 * @return {Promise<void>}
		 */
		async handleKanbanLoadMore({ value, offset }) {
			if (!this.activeViewId) return
			this.loadingColumns = [...this.loadingColumns, value]
			try {
				const currentLimit = this.kanbanBoard?.columns?.find((c) => c.value === value)?.limit || 20
				const nextLimit = Math.max(offset, currentLimit) + currentLimit
				const board = await viewsStore.fetchKanbanBoard(this.activeViewId, { _limit: nextLimit })
				const updatedColumn = board?.columns?.find((c) => c.value === value)
				if (updatedColumn && this.kanbanBoard) {
					this.kanbanBoard = {
						...this.kanbanBoard,
						columns: this.kanbanBoard.columns.map((c) => (c.value === value ? updatedColumn : c)),
					}
				}
			} catch (e) {
				console.error('[SearchIndex] Error loading more kanban cards:', e)
			} finally {
				this.loadingColumns = this.loadingColumns.filter((v) => v !== value)
			}
		},
		/**
		 * Drag-to-move (REQ-VIEW-KANBAN-03): persist the card's `groupByField`
		 * through the existing guarded object PUT path — no bespoke move
		 * endpoint. On success, confirm the optimistic move; on a rejected
		 * write (RBAC or an illegal `x-openregister-lifecycle` transition) roll
		 * the card back with the server's reason.
		 *
		 * @param {object} payload The `move` event payload.
		 * @param {object} payload.object The moved card (full object, `@self` intact).
		 * @param {string} payload.groupByField The property being changed.
		 * @param {*} payload.toValue The destination column's value.
		 * @spec openspec/specs/saved-search-views/spec.md#requirement-kanban-drag-changes-status-via-the-guarded-write-path-req-view-kanban-03
		 * @return {Promise<void>}
		 */
		async handleKanbanMove({ object, groupByField, toValue }) {
			const type = this.computedObjectType
			// Carry every existing field forward — saveObject's PUT is
			// full-replace semantic, so only overriding groupByField (rather
			// than sending a partial patch) is what keeps the rest of the
			// object intact.
			const updated = { ...object, [groupByField]: toValue }
			const kanbanRef = this.$refs.kanban
			const saved = await objectStore.saveObject(type, updated)
			if (saved) {
				kanbanRef?.resolveMove(object.id)
				return
			}
			const reason = objectStore.errors?.[type]?.message || t('openregister', 'The move was rejected by the server.')
			kanbanRef?.rejectMove(object.id, reason)
		},
		/**
		 * Surface a rejected move's reason (e.g. an illegal lifecycle
		 * transition) after `CnObjectKanban` has snapped the card back.
		 *
		 * @param {object} payload The `move-rejected` event payload.
		 * @param {string} [payload.reason] The server's rejection reason.
		 * @spec exclude UI plumbing — logs the rejection reason surfaced by the shared component; the guard itself is owned by object-lifecycle
		 * @return {void}
		 */
		handleKanbanMoveRejected({ reason }) {
			if (reason) {
				console.warn('[SearchIndex] Kanban move rejected:', reason)
			}
		},
		/**
		 * Refetch calendar objects for the newly visible range
		 * (REQ-VIEW-CAL-04), on mount and after every month navigation.
		 *
		 * @param {object} payload The `range-change` event payload.
		 * @param {string} payload.rangeStart Inclusive range start (ISO date).
		 * @param {string} payload.rangeEnd Inclusive range end (ISO date).
		 * @spec openspec/specs/saved-search-views/spec.md#requirement-calendar-plots-objects-by-a-date-field-over-a-range-req-view-cal-04
		 * @return {Promise<void>}
		 */
		async handleCalendarRangeChange({ rangeStart, rangeEnd }) {
			if (!this.activeViewId) return
			this.calendarLoading = true
			try {
				const result = await viewsStore.fetchCalendarObjects(this.activeViewId, rangeStart, rangeEnd)
				this.calendarObjects = result?.objects || []
			} catch (e) {
				console.error('[SearchIndex] Error fetching calendar range:', e)
			} finally {
				this.calendarLoading = false
			}
		},
	},
}
</script>

<template>
	<NcAppContent>
		<!--
			Dispatch on the active view's presentation.viewType
			(REQ-VIEW-PRES-05): `table` (or no active view / no presentation,
			i.e. a legacy view) keeps the existing CnIndexPage list unchanged;
			`kanban`/`calendar` render the shared nextcloud-vue components —
			OpenRegister only wires props/events, it does not re-implement
			rendering locally.
		-->
		<CnIndexPage
			v-if="presentationType === 'table'"
			ref="indexPage"
			:class="{ 'add-button-disabled': !hasSelectedRegisters || !hasSelectedSchemas }"
			:title="pageTitle"
			:schema="normalizedSchema"
			:register="objectStore.searchRegister"
			:objects="normalizedObjects"
			:store="objectStore"
			:object-type="computedObjectType"
			:loading="objectStore.searchLoading"
			:pagination="objectStore.searchPagination"
			row-key="id"
			:include-columns="objectStore.searchVisibleColumns && objectStore.searchVisibleColumns.length ? objectStore.searchVisibleColumns : null"
			:selectable="hasSelectedRegisters && hasSelectedSchemas"
			:selected-ids="selectedIdsForPage"
			:sort-key="objectStore.searchParams.sortKey"
			:sort-order="objectStore.searchParams.sortOrder"
			:show-title="false"
			:show-mass-import="false"
			:show-mass-export="false"
			use-advanced-form-dialog
			:show-edit-action="false"
			:show-copy-action="false"
			:show-delete-action="false"
			show-mass-copy
			show-mass-delete
			mass-action-name-field="title"
			empty-text="No objects found. Select registers and schemas in the sidebar, then search."
			@add="handleAddObject"
			@refresh="handleRefresh"
			@mass-delete="handleMassDelete"
			@mass-copy="handleMassCopy"
			@row-click="handleRowClick"
			@sort="handleSort"
			@page-changed="handlePageChanged"
			@page-size-changed="handlePageSizeChanged"
			@select="handleSelect">
			<template #row-actions="{ row }">
				<NcActions>
					<NcActionButton close-after-click @click="handleRowClick(row)">
						<template #icon>
							<Pencil :size="20" />
						</template>
						Edit
					</NcActionButton>
					<NcActionButton close-after-click @click="handleCopyRow(row)">
						<template #icon>
							<ContentCopy :size="20" />
						</template>
						Copy
					</NcActionButton>
					<NcActionButton close-after-click @click="handleDeleteRow(row)">
						<template #icon>
							<TrashCanOutline :size="20" />
						</template>
						Delete
					</NcActionButton>
				</NcActions>
			</template>
		</CnIndexPage>

		<CnObjectKanban
			v-else-if="presentationType === 'kanban'"
			:key="`kanban-${activeViewId}`"
			ref="kanban"
			:columns="normalizedKanbanColumns"
			:group-by-field="kanbanConfig.groupByField"
			:column-order="kanbanConfig.columnOrder || null"
			:card-fields="kanbanConfig.cardFields || []"
			:schema="normalizedSchema"
			:loading="kanbanLoading"
			:loading-columns="loadingColumns"
			row-key="id"
			@move="handleKanbanMove"
			@move-rejected="handleKanbanMoveRejected"
			@load-more="handleKanbanLoadMore"
			@card-click="handleRowClick" />

		<CnObjectCalendar
			v-else-if="presentationType === 'calendar'"
			:key="`calendar-${activeViewId}`"
			ref="calendar"
			:objects="normalizedCalendarObjects"
			:date-field="calendarConfig.dateField"
			:end-date-field="calendarConfig.endDateField || null"
			:loading="calendarLoading"
			row-key="id"
			@range-change="handleCalendarRangeChange"
			@object-click="handleRowClick" />
	</NcAppContent>
</template>

<style scoped>
.add-button-disabled :deep(.cn-actions-bar .button-vue--vue-primary) {
	opacity: 0.5;
	cursor: not-allowed;
	pointer-events: none;
}
</style>
