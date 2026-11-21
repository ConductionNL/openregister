/**
 * The guard of a delete dialog that is blocked while objects still use the
 * thing being deleted: `canDelete` from the component's `objects`, and a
 * one-time `initDialog()` call the first time the dialog renders open.
 *
 * The component keeps `objects` in its own data and defines `initDialog()`.
 *
 * @spec exclude UI plumbing — delete-enablement guard and one-time dialog init.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { objectStore, schemaStore } from '../store/store.js'

/**
 * Build the guard mixin.
 *
 * @spec exclude UI plumbing — builds the delete-enablement guard mixin.
 * @param {function(): boolean} isOpen Whether the dialog is open
 * @return {object} The mixin
 */
export function objectUsageGuard(isOpen) {
	return {
		/**
		 * @spec exclude UI state — whether the one-time dialog init has run.
		 * @return {object} The mixin's data
		 */
		data() {
			return { isUpdated: false }
		},

		computed: {
			/**
			 * @spec exclude UI state helper — enables deletion only when no objects use it.
			 */
			canDelete() {
				return this.objects.length === 0
			},
		},

		/**
		 * @spec exclude Vue lifecycle hook — initializes the dialog once when it opens.
		 */
		updated() {
			if (!this.isUpdated && isOpen()) {
				this.isUpdated = true
				this.initDialog()
			}
		},
	}
}

/**
 * Load the current schema's objects in one register into the object store's
 * current collection.
 *
 * @spec exclude Modal data-load plumbing — lists objects referencing the schema before delete.
 * @param {object} reg The register
 * @return {Promise<void>}
 */
export async function refreshSchemaObjects(reg) {
	await objectStore.refreshObjectList({
		register: reg.id,
		schema: schemaStore.schemaItem.id,
		search: '',
	})
}
