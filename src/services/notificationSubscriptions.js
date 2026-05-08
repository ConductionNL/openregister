/**
 * Notification subscription API client helpers.
 *
 * Thin wrappers over the OpenRegister notification-subscriptions endpoints:
 *
 *   GET    /api/notification-subscriptions
 *   POST   /api/notification-subscriptions
 *   DELETE /api/notification-subscriptions?registerId=X&schemaId=Y
 *
 * Used by the SubscriptionToggle component. Extracted into a standalone
 * module so the API-call shape can be unit-tested without mounting a
 * Vue component.
 *
 * Closes notificatie-engine task: "Users MUST be able to manage their
 * notification preferences".
 */

const BASE = '/index.php/apps/openregister/api/notification-subscriptions'

/**
 * Fetch the current user's subscriptions.
 *
 * @return {Promise<Array<{id, userId, registerId, schemaId, created}>>}
 * @throws {Error} On HTTP error.
 */
export async function listSubscriptions() {
	const response = await fetch(BASE, {
		method: 'GET',
		headers: { 'Content-Type': 'application/json' },
	})
	if (!response.ok) {
		throw new Error(`Failed to list subscriptions: HTTP ${response.status}`)
	}
	const data = await response.json()
	return data.results || []
}

/**
 * Subscribe to a (register, schema) tuple. At least one must be set.
 *
 * @param {object} params Subscription target
 * @param {?number} [params.registerId] Register id.
 * @param {?number} [params.schemaId] Schema id.
 * @return {Promise<object>} The created subscription.
 * @throws {Error} On HTTP error.
 */
export async function subscribe({ registerId = null, schemaId = null } = {}) {
	const response = await fetch(BASE, {
		method: 'POST',
		headers: { 'Content-Type': 'application/json' },
		body: JSON.stringify({ registerId, schemaId }),
	})
	if (!response.ok) {
		throw new Error(`Failed to subscribe: HTTP ${response.status}`)
	}
	return response.json()
}

/**
 * Unsubscribe from a (register, schema) tuple.
 *
 * @param {object} params Unsubscribe target.
 * @param {?number} [params.registerId] Register id.
 * @param {?number} [params.schemaId] Schema id.
 * @return {Promise<{deleted: boolean}>} Result envelope.
 * @throws {Error} On HTTP error.
 */
export async function unsubscribe({ registerId = null, schemaId = null } = {}) {
	const qs = new URLSearchParams()
	if (registerId !== null) qs.set('registerId', String(registerId))
	if (schemaId !== null) qs.set('schemaId', String(schemaId))

	const response = await fetch(`${BASE}?${qs.toString()}`, {
		method: 'DELETE',
		headers: { 'Content-Type': 'application/json' },
	})
	if (!response.ok) {
		throw new Error(`Failed to unsubscribe: HTTP ${response.status}`)
	}
	return response.json()
}

/**
 * Test whether the current user has an active subscription matching
 * a (register, schema) tuple. Either id may be null, in which case
 * only rows with that column NULL match.
 *
 * @param {Array} subscriptions The list returned by listSubscriptions().
 * @param {object} target Target tuple.
 * @param {?number} [target.registerId] Register id.
 * @param {?number} [target.schemaId] Schema id.
 * @return {boolean} True when a matching subscription exists.
 */
export function hasSubscription(subscriptions, { registerId = null, schemaId = null } = {}) {
	if (!Array.isArray(subscriptions)) return false
	return subscriptions.some(sub =>
		sub.registerId === registerId && sub.schemaId === schemaId,
	)
}
