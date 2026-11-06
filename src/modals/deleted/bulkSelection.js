/**
 * The selection a bulk dialog on deleted objects works on: taken from the
 * deleted store when the dialog opens, shrunk as the user removes objects,
 * and cleared when the dialog closes.
 *
 * @spec exclude Client-side bulk selection plumbing shared by the purge and restore dialogs.
 */
import eventBus from '../../eventBus.js'
import { deletedStore, navigationStore } from '../../store/store.js'

/**
 * Take the selection the deleted-items list handed over; close when it is empty.
 *
 * @param {object} dialog The dialog component
 */
export function takeSelection(dialog) {
	const data = deletedStore.selectedForBulkAction || []
	dialog.selectedObjects.splice(0, dialog.selectedObjects.length, ...data)
	if (dialog.selectedObjects.length === 0) {
		dialog.closeDialog()
	}
}

/**
 * Drop one object from the selection; close when none are left.
 *
 * @param {object} dialog The dialog component
 * @param {string} objectId The object's id
 */
export function dropFromSelection(dialog, objectId) {
	dialog.selectedObjects = dialog.selectedObjects.filter(
		(obj) => obj.id !== objectId,
	)
	if (dialog.selectedObjects.length === 0) {
		dialog.closeDialog()
	}
}

/**
 * Close the dialog, clear the handed-over selection and reset the outcome.
 *
 * @param {object} dialog The dialog component
 */
export function closeBulkDialog(dialog) {
	navigationStore.setDialog(false)
	deletedStore.clearSelectedForBulkAction()
	clearTimeout(dialog.closeModalTimeout)
	dialog.success = null
	dialog.successMessage = ''
	dialog.loading = false
	dialog.error = false
}

/**
 * Where the name of a deleted object is looked for, in order.
 */
const TITLE_SOURCES = [
	(object) => object?.title,
	(object) => object?.fileName,
	(object) => object?.name,
	(object) => object?.object?.title,
	(object) => object?.object?.name,
	(object) => object?.id,
]

/**
 * The name a deleted object is shown by.
 *
 * @param {object} object The object
 * @return {string} The title
 */
export function objectTitle(object) {
	return TITLE_SOURCES.map((read) => read(object)).find(Boolean) || 'Unknown'
}

/**
 * The selection and outcome a bulk dialog hands to its dialog body.
 *
 * @param {object} dialog The dialog component
 * @return {object} The body's state props
 */
export function reviewStateOf(dialog) {
	return {
		objects: dialog.selectedObjects,
		success: dialog.success,
		successMessage: dialog.successMessage,
		error: dialog.error,
		loading: dialog.loading,
	}
}

/**
 * The state of a bulk dialog before anything is selected or done.
 *
 * @return {object} The dialog state
 */
export function bulkDialogData() {
	return {
		success: null,
		successMessage: '',
		loading: false,
		error: false,
		closeModalTimeout: null,
		selectedObjects: [],
	}
}

/**
 * The dialog watcher that takes the handed-over selection when the named
 * dialog opens.
 *
 * @param {string} dialogName The dialog's name in the navigation store
 * @return {function(string, string): void} The watcher
 */
export function opensWith(dialogName) {
	return function (newValue, oldValue) {
		if (newValue === dialogName && oldValue !== dialogName) {
			this.initializeSelection()
		}
	}
}

/**
 * The watcher and mounted hook of a bulk dialog: both take the handed-over
 * selection, the watcher when the named dialog opens.
 *
 * @param {string} dialogName The dialog's name in the navigation store
 * @return {object} The watch and mounted options
 */
export function bulkDialogLifecycle(dialogName) {
	return {
		watch: { 'navigationStore.dialog': opensWith(dialogName) },
		mounted() {
			this.initializeSelection()
		},
	}
}

/**
 * The selection handling both bulk dialogs share.
 */
export const bulkDialogMethods = {
	/**
	 * Initialize selection from transfer data
	 *
	 * @return {void}
	 * @spec openspec/specs/entity-management-modals/spec.md
	 */
	initializeSelection() {
		takeSelection(this)
	},

	/**
	 * Remove object from selection
	 *
	 * @param {string} objectId - ID of object to remove
	 * @return {void}
	 * @spec exclude Removes one object from the local bulk-selection list; UI selection plumbing.
	 */
	removeObject(objectId) {
		dropFromSelection(this, objectId)
	},

	/**
	 * Close the dialog and reset state
	 *
	 * @return {void}
	 * @spec exclude Modal close handler resetting navigationStore.dialog and local state; UI plumbing.
	 */
	closeDialog() {
		closeBulkDialog(this)
	},
}

/**
 * Run a bulk action on the selected objects: the store call, then the
 * outcome, the delayed close and the refresh event, or the failure.
 *
 * @param {object} dialog The dialog component
 * @param {{run: function(Array): Promise<object>, done: function(object): string, event: string, closeAfter: number, failure: string}} action
 *     The store call, the success message for its answer, the event to emit,
 *     the close delay in milliseconds and the fallback failure message
 * @return {Promise<void>}
 */
export async function runBulkAction(dialog, action) {
	dialog.loading = true
	try {
		const ids = dialog.selectedObjects.map((obj) => obj.id)
		const result = await action.run(ids)
		bulkActionDone(dialog, { action, ids, result })
	} catch (error) {
		dialog.success = false
		dialog.error = error.message || action.failure
	} finally {
		dialog.loading = false
	}
}

/**
 * Record a finished bulk action, close the dialog after the action's delay,
 * and tell the list to refresh.
 *
 * @param {object} dialog The dialog component
 * @param {{action: object, ids: Array, result: object}} outcome The action, the ids it ran on and the store's answer
 * @return {void}
 */
function bulkActionDone(dialog, outcome) {
	dialog.success = true
	dialog.error = false
	dialog.successMessage = outcome.action.done(outcome.result)
	dialog.closeModalTimeout = setTimeout(
		dialog.closeDialog,
		outcome.action.closeAfter,
	)
	eventBus.emit(outcome.action.event, outcome.ids)
}
