import { dashboardStore, registerStore } from '../../store/store.js'

/**
 * The active register in the dashboard store: the register store's item,
 * falling back to the route's `:id` on a deep link or page refresh.
 *
 * @param {object} route The current route; read only without a register item
 * @return {object|undefined} The register record, if the dashboard holds it
 */
export function findActiveRegister(route) {
	const registerId = registerStore.getRegisterItem?.id || route.params.id
	if (!registerId) {
		return undefined
	}
	// Route params are strings, the API returns numeric ids — compare as strings.
	return dashboardStore.registers.find(
		(r) => String(r.id) === String(registerId),
	)
}
