/**
 * The options a bulk selection dialog shares, as one mixin.
 *
 * @spec exclude Client-side dialog plumbing shared by the bulk dialogs.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import BulkSelectionDialog from './BulkSelectionDialog.vue'
import {
	bulkDialogData,
	bulkDialogLifecycle,
	bulkDialogMethods,
	reviewStateOf,
	runBulkAction,
} from './bulkSelection.js'

export { BulkSelectionDialog, reviewStateOf, runBulkAction }

/**
 * A bulk dialog's state, its selection handling and the hooks that take the
 * handed-over selection when the named dialog opens.
 *
 * @param {string} dialogName The dialog's name in the navigation store
 * @return {object} The mixin
 */
export function bulkDialog(dialogName) {
	return {
		data: bulkDialogData,
		...bulkDialogLifecycle(dialogName),
		methods: { ...bulkDialogMethods },
	}
}
