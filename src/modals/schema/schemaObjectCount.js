/**
 * The object count of the active schema, for the dialogs that act on all its
 * objects: loaded on mount, and again when the schema changes before any
 * objects were counted.
 *
 * @spec exclude Modal data-load plumbing — fetches schema object-count stats.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { schemaStore } from '../../store/store.js'

/**
 * A mixin holding the object count of the active schema. The dialog using it
 * reports a failed count through its own `logCountFailure(error)` method.
 */
export const schemaObjectCount = {
	data() {
		return {
			countLoading: true,
			countFailed: false,
			objectCount: 0,
			objectStats: null,
		}
	},

	computed: {
		// A <script setup> binding is not on `this`, and a watch path reads `this`.
		schemaStore: () => schemaStore,
	},

	watch: {
		'schemaStore.schemaItem': {
			/**
			 * @param {object|null} newSchemaItem The schema now active
			 * @spec exclude UI watcher — reloads the object count when the schema item changes.
			 */
			handler(newSchemaItem) {
				if (newSchemaItem?.id && this.objectCount === 0) {
					this.loadObjectCount()
				}
			},
		},
	},

	/**
	 * @spec exclude Vue lifecycle hook — loads the object count on mount.
	 */
	async mounted() {
		await this.loadObjectCount()
	},

	methods: {
		/**
		 * Count the objects of the active schema, marking a failed count.
		 *
		 * @spec exclude Modal data-load plumbing — fetches schema object-count stats.
		 * @return {Promise<void>}
		 */
		async loadObjectCount() {
			this.countLoading = true
			this.countFailed = false
			try {
				await this.fetchObjectStats()
			} catch (err) {
				this.logCountFailure(err)
				this.objectCount = 0
				this.countFailed = true
				this.objectStats = null
			} finally {
				this.countLoading = false
			}
		},

		/**
		 * Read the object statistics of the active schema, if there is one.
		 *
		 * @spec exclude Modal data-load plumbing — fetches schema object-count stats.
		 * @return {Promise<void>}
		 */
		async fetchObjectStats() {
			if (!schemaStore.schemaItem?.id) {
				return
			}
			// Use the upgraded stats endpoint to get detailed object counts
			const stats = await schemaStore.getSchemaStats(schemaStore.schemaItem.id)
			this.objectStats = stats.objects
			this.objectCount = stats.objects?.total || 0
		},
	},
}
