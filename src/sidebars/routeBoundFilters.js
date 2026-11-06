/**
 * What the list sidebars bound to a route share: the URL query is the single
 * source of truth for their filters.
 *
 * @spec exclude Client-side route sync shared by the list sidebars.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { queriesEqual } from '../services/routeQuery.js'
import { registerStore, schemaStore } from '../store/store.js'

/**
 * A mixin for a sidebar on one route. The sidebar supplies `applyFilters()`,
 * `buildQueryFromState()` and `applyQueryParamsFromRoute()`.
 *
 * @param {string} path The route of the sidebar
 * @return {object} The mixin
 */
export function routeBoundFilters(path) {
	return {
		computed: {
			// A <script setup> binding is not on `this`, and a watch path reads `this`.
			registerStore: () => registerStore,
			schemaStore: () => schemaStore,
		},

		watch: {
			'$route.query': {
				/**
				 * @spec exclude follows the URL query, UI plumbing
				 */
				handler() {
					if (this.$route.path !== path) return
					this.applyQueryParamsFromRoute()
				},

				deep: true,
			},

			'registerStore.registerItem': function() {
				this.applyFilters()
			},

			'schemaStore.schemaItem': function() {
				this.applyFilters()
			},
		},

		methods: {
			/**
			 * @param {object|null} register The picked register
			 * @spec exclude filter handler, clears the schema of another register
			 */
			handleRegisterChange(register) {
				registerStore.setRegisterItem(register)
				schemaStore.setSchemaItem(null)
				this.applyFilters()
			},

			/**
			 * @param {object|null} schema The picked schema
			 * @spec exclude filter handler, UI plumbing
			 */
			handleSchemaChange(schema) {
				schemaStore.setSchemaItem(schema)
				this.applyFilters()
			},

			/**
			 * @spec exclude writes the filters to the URL query, UI plumbing
			 */
			updateRouteQueryFromState() {
				if (this.$route.path !== path) return
				const nextQuery = this.buildQueryFromState()
				if (queriesEqual(nextQuery, this.$route.query || {})) return
				this.$router.replace({ path: this.$route.path, query: nextQuery })
			},
		},
	}
}
