/**
 * Selecting the register and schema a route query names, once the store
 * lists they come from have loaded.
 *
 * @spec exclude Sidebar route-query to store plumbing; no backend contract.
 */
import { registerStore, schemaStore } from '../store/store.js'

const SCOPES = {
	register: {
		store: () => registerStore,
		list: 'registerList',
		select: 'setRegisterItem',
	},
	schema: {
		store: () => schemaStore,
		list: 'schemaList',
		select: 'setSchemaItem',
	},
}

const RETRIES = 10
const RETRY_DELAY = 200

/**
 * Select the item a route names, when its list has loaded.
 *
 * @param {string} kind 'register' or 'schema'
 * @param {string|undefined} id The id from the route, if any
 * @return {boolean} Whether this part of the scope is settled
 */
function selectFromRoute(kind, id) {
	if (!id) {
		return true
	}
	const scope = SCOPES[kind]
	const store = scope.store()
	if (!store[scope.list].length) {
		return false
	}
	const item = store[scope.list].find((entry) => String(entry.id) === String(id))
	if (item) {
		store[scope.select](item)
	}
	return true
}

/**
 * Select the route's register and schema, then call `onReady`. While a list
 * has not loaded yet it retries every 200 ms, at most ten times.
 *
 * @param {{register: string|undefined, schema: string|undefined}} scope The ids from the route
 * @param {Function} onReady Called once both are settled
 */
export function applyRouteScope(scope, onReady) {
	const tryApply = (attempt) => {
		const registerReady = selectFromRoute('register', scope.register)
		const schemaReady = selectFromRoute('schema', scope.schema)
		if (registerReady && schemaReady) {
			onReady()
			return
		}
		if (attempt < RETRIES) {
			setTimeout(() => tryApply(attempt + 1), RETRY_DELAY)
		}
	}
	tryApply(0)
}
