/**
 * Shared behaviour of the bulk object dialogs. `objectStore.selectedObjects`
 * holds id strings, because the table binds to them; these dialogs work on
 * rows, so the selection is resolved here and written back as ids.
 *
 * @spec openspec/specs/entity-management-modals/spec.md
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { objectStore } from '../../store/store.js'

/**
 * The id of a list row.
 *
 * @param {object} row The row
 * @return {string|number|undefined} Its id
 */
function rowId(row) {
	return row?.['@self']?.id ?? row?.id
}

/**
 * The row of one selection entry. An id without a loaded row yields `{ id }`,
 * which is enough to address the object but not to save it.
 *
 * @param {string|number|object} entry An id, or a row
 * @param {Map<string, object>} rowsById Loaded rows by id
 * @return {object} The row with a top-level id
 */
function selectedRow(entry, rowsById) {
	if (entry && typeof entry === 'object') {
		return { ...entry, id: rowId(entry) }
	}
	const row = rowsById.get(String(entry))
	return row ? { ...row, id: rowId(row) } : { id: String(entry) }
}

/**
 * Turn a selection into rows, dropping entries without an id.
 *
 * @param {Array|undefined} selection Ids or rows
 * @param {Array|undefined} rows The loaded rows
 * @return {Array<object>} One row per selected object
 */
export function resolveSelection(selection, rows) {
	const pool = Array.isArray(rows) ? rows : []
	const rowsById = new Map(
		pool.filter(rowId).map((row) => [String(rowId(row)), row]),
	)
	return (selection || [])
		.map((entry) => selectedRow(entry, rowsById))
		.filter((obj) => obj.id)
}

/**
 * Clear the selection and refresh both object lists: the search view renders
 * `searchCollection`, other views the register and schema collection. Views
 * without that register and schema pair reject the second refresh.
 *
 * @return {void}
 */
export function refreshObjectLists() {
	objectStore.selectedObjects = []
	objectStore.refetchSearchCollection()
	objectStore.refreshObjectList().catch(() => {})
}

export default {
	data() {
		return {
			selectedObjects: [],
		}
	},

	methods: {
		/**
		 * @spec openspec/specs/entity-management-modals/spec.md
		 */
		initializeSelection() {
			this.selectedObjects = resolveSelection(
				objectStore.selectedObjects,
				objectStore.searchCollection,
			)
			if (this.selectedObjects.length === 0) {
				this.closeDialog()
			}
		},

		/**
		 * @param {string|number} objectId The object to take out
		 * @spec openspec/specs/entity-management-modals/spec.md
		 */
		removeObject(objectId) {
			this.selectedObjects = this.selectedObjects.filter(
				(obj) => obj.id !== objectId,
			)
			objectStore.selectedObjects = this.selectedObjects.map((obj) => obj.id)
			if (this.selectedObjects.length === 0) {
				this.closeDialog()
			}
		},
	},
}
