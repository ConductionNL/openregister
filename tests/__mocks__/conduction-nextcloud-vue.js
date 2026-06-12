/**
 * Test-only stub of @conduction/nextcloud-vue.
 *
 * The real package transitively pulls in @nextcloud/vue + @nextcloud/auth +
 * @nextcloud/browser-storage which is a chain of CJS+ESM that jest cannot
 * parse. The unit tests only exercise the *factory wiring* of our store
 * modules (setObjectItem, refreshObjectList, etc.), so we hand back a
 * minimal pinia-style store factory that merges plugin actions + state.
 */

const { defineStore } = require('pinia')

function createObjectStore(id, options = {}) {
	const plugins = options.plugins || []

	// Merge state / actions / getters from each plugin into one pinia store
	// definition. Plugins are objects produced by filesPlugin(), etc.
	const merged = {
		state: () => {
			const state = {
				objectTypes: [],
				pagination: {},
			}
			for (const p of plugins) {
				if (typeof p?.state === 'function') {
					Object.assign(state, p.state())
				}
			}
			return state
		},
		getters: {},
		actions: {
			registerObjectType(type) {
				if (!this.objectTypes.includes(type)) this.objectTypes.push(type)
			},
			fetchCollection() { return [] },
			getPagination() { return { total: 0, page: 1, pages: 0, limit: 25 } },
		},
	}
	for (const p of plugins) {
		Object.assign(merged.getters, p?.getters || {})
		Object.assign(merged.actions, p?.actions || {})
	}

	return defineStore(id, merged)
}

const noopPlugin = name => () => ({ name, state: () => ({}), getters: {}, actions: {} })

module.exports = {
	createObjectStore,
	filesPlugin: noopPlugin('files'),
	auditTrailsPlugin: noopPlugin('auditTrails'),
	relationsPlugin: noopPlugin('relations'),
	registerMappingPlugin: noopPlugin('registerMapping'),
	lifecyclePlugin: noopPlugin('lifecycle'),
	searchPlugin: noopPlugin('search'),
	selectionPlugin: noopPlugin('selection'),
}
