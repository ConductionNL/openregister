/**
 * App-local event bus, replacing the Vue 2 `this.$root.$on/$off/$emit` pattern.
 *
 * WHY THIS EXISTS
 * ---------------
 * Vue 3 removed the events API from component instances: `$on`, `$off` and
 * `$once` are gone, and `$emit` only emits a component's OWN declared events.
 * `this.$root.$on(...)` therefore throws `TypeError: this.$root.$on is not a
 * function` — inside `mounted()`, which means the whole component fails to
 * finish mounting.
 *
 * OpenRegister used `$root` as a bus on ten components across four
 * sidebar↔view channels (deleted, entities, audit-trail, search-trail): the
 * sidebar publishes filter changes, the view publishes result counts back.
 * Every one of those channels is dead under Vue 3.
 *
 * This is a ~30-line mitt-equivalent rather than a new dependency. It
 * deliberately keeps the exact Vue 2 semantics the call sites relied on:
 *
 *   - `off(event)` with NO handler removes ALL handlers for that event, which
 *     is how every `beforeDestroy`/`beforeUnmount` in this app calls it;
 *   - `off(event, handler)` removes just that one;
 *   - handlers receive the emitted payload as a single argument.
 *
 * ⚠️ A shared event bus belongs in @conduction/nextcloud-vue — several apps in
 * the fleet use the same `$root` pattern and all of them need this. Until the
 * library ships one, this copy is app-local.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

/** @type {Map<string, Array<Function>>} */
const listeners = new Map()

/**
 * Subscribe to an event.
 *
 * @param {string} event The event name.
 * @param {Function} handler The handler.
 * @return {void}
 */
export function on(event, handler) {
	if (typeof handler !== 'function') {
		// Vue 2's `$on` accepted a non-function and only blew up later, at emit
		// time, in whichever component happened to publish. Silently ignoring it
		// here would be worse — it converts a latent throw into a permanently
		// dead channel with no signal at all. `DeletedIndex.vue` already
		// subscribes `deleted-export-filtered` to `this.exportFiltered`, which
		// does not exist (the method is `exportFilteredItems`); that is a
		// pre-existing bug this warning is meant to surface rather than hide.
		console.warn(
			`[openregister/eventBus] refusing to subscribe "${event}": handler is `
			+ `${typeof handler}, not a function. The channel will never fire.`,
		)
		return
	}
	const existing = listeners.get(event)
	if (existing === undefined) {
		listeners.set(event, [handler])
	} else {
		existing.push(handler)
	}
}

/**
 * Unsubscribe. Omitting `handler` removes every handler for the event, which
 * matches how Vue 2's `$off(event)` behaved and how this app calls it.
 *
 * @param {string} event The event name.
 * @param {Function} [handler] The specific handler to remove.
 * @return {void}
 */
export function off(event, handler) {
	if (handler === undefined) {
		listeners.delete(event)
		return
	}
	const existing = listeners.get(event)
	if (existing === undefined) {
		return
	}
	const next = existing.filter((entry) => entry !== handler)
	if (next.length === 0) {
		listeners.delete(event)
	} else {
		listeners.set(event, next)
	}
}

/**
 * Publish an event.
 *
 * A handler that throws must not prevent the remaining handlers from running —
 * the counts-back channels here are fire-and-forget and one bad subscriber
 * used to be able to strand the rest.
 *
 * @param {string} event The event name.
 * @param {*} [payload] The payload passed to each handler.
 * @return {void}
 */
export function emit(event, payload) {
	const existing = listeners.get(event)
	if (existing === undefined) {
		return
	}
	for (const handler of [...existing]) {
		try {
			handler(payload)
		} catch (e) {
			console.error(`[openregister/eventBus] handler for "${event}" threw`, e)
		}
	}
}

export default { on, off, emit }
