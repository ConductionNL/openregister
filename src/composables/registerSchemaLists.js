/**
 * Make sure the register and schema lists are loaded before a picker or a
 * table reads them: each list is fetched only when it is still empty.
 *
 * @spec exclude UI plumbing — loads store lists a view needs.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { registerStore, schemaStore } from '../store/store.js'

/**
 * Load the register list and then the schema list, each only when empty.
 *
 * @return {Promise<void>} Resolves once both lists are loaded; rejects with the first failure.
 */
export async function ensureRegisterAndSchemaLists() {
	if (!registerStore.registerList.length) {
		await registerStore.refreshRegisterList()
	}
	if (!schemaStore.schemaList.length) {
		await schemaStore.refreshSchemaList()
	}
}
