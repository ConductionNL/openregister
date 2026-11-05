/**
 * The selection a bulk dialog on deleted objects works on: taken from the
 * deleted store when the dialog opens, shrunk as the user removes objects,
 * and cleared when the dialog closes.
 *
 * @spec exclude Client-side bulk selection plumbing shared by the purge and restore dialogs.
 */
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
 * The name a deleted object is shown by.
 *
 * @param {object} object The object
 * @return {string} The title
 */
export function objectTitle(object) {
	return (
		object?.title
		|| object?.fileName
		|| object?.name
		|| object?.object?.title
		|| object?.object?.name
		|| object?.id
		|| 'Unknown'
	)
}
