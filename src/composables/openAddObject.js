/**
 * Open the add-object modal with the register/schema context of the current
 * scope: shared by the object views that offer "Add Object".
 *
 * @spec exclude UI plumbing — store-set + modal dispatch; object creation contract owned by object-lifecycle.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import {
	navigationStore,
	objectStore,
	registerStore,
	schemaStore,
} from '../store/store.js'

/**
 * Clear the current object and open the add-object modal.
 *
 * @spec exclude UI plumbing — store-set + modal dispatch; object creation contract owned by object-lifecycle.
 * @return {void}
 */
export function openAddObjectModal() {
	// Clear any existing object and open the add object modal
	objectStore.setObjectItem(null)
	// Ensure register and schema are set for new object creation
	if (registerStore.registerItem) {
		registerStore.setRegisterItem(registerStore.registerItem)
	}
	if (schemaStore.schemaItem) {
		schemaStore.setSchemaItem(schemaStore.schemaItem)
	}
	navigationStore.setModal('viewObject')
}
