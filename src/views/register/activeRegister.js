import { dashboardStore, registerStore } from '../../store/store.js'
import { findRegisterByIdentifier } from '../../services/matchRegister.js'

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
	// By id, uuid or slug, as RegisterMapper::find() resolves it: a link from
	// another app can only name the slug.
	return findRegisterByIdentifier(dashboardStore.registers, registerId)
}
