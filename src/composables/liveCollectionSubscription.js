/**
 * The live collection subscription of a register+schema view
 * (adopt-live-updates-ui, dashboard-live-updates): the methods that follow
 * one or-collection-{register}-{schema} scope through the package object
 * store's liveUpdatesPlugin — notify_push when available, visibility-gated
 * polling otherwise. Events are refetch hints only.
 *
 * Spread into a component's `methods`; the component keeps the state in its
 * own data: liveHandle, liveType, livePendingType (an in-flight subscribe,
 * so a concurrent same-scope call does not double-subscribe) and liveEpoch
 * (invalidates in-flight resolutions after a release: scope change or
 * destroy).
 *
 * @spec openspec/specs/realtime-updates/spec.md
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import {
	objectStore,
	registerStore,
	schemaStore,
} from '../store/store.js'

/**
 * Build the live collection methods.
 *
 * @param {object} options How the view differs
 * @param {string} options.tag Log prefix of the view, e.g. '[ObjectsList]'
 * @param {function(object): string} options.currentType (vm) => the collection type to follow, '' for none
 * @param {function(object): void} [options.onRelease] (vm) => extra cleanup when the subscription is released
 * @return {object} Methods: syncLiveSubscription, releaseLiveSubscription and their helpers
 */
export function liveCollectionMethods({ tag, currentType, onRelease }) {
	return {
		/**
		 * Follow the current scope: idempotent per type, releases the previous
		 * subscription when the scope changes, no-op without a scope.
		 *
		 * @spec openspec/specs/realtime-updates/spec.md
		 * @return {Promise<void>}
		 */
		async syncLiveSubscription() {
			if (typeof objectStore.subscribe !== 'function') {
				return
			}
			const type = currentType(this)
			if (!type) {
				this.releaseLiveSubscription()
				return
			}
			if (this.isLiveFor(type)) {
				return
			}
			this.releaseLiveSubscription()
			try {
				await this.subscribeLive(type)
			} catch (e) {
				this.liveFailed(type, e)
			}
		},

		/**
		 * Whether this scope is already followed, or a subscribe for it is in
		 * flight (re-subscribing would leak the first handle).
		 *
		 * @spec openspec/specs/realtime-updates/spec.md
		 * @param {string} type The collection type
		 * @return {boolean} Whether to leave the subscription as it is
		 */
		isLiveFor(type) {
			return (
				Boolean(this.liveHandle && this.liveType === type)
				|| this.livePendingType === type
			)
		},

		/**
		 * Register the collection type with the store when it is not known
		 * yet, so the subscription does not depend on another view's timing.
		 *
		 * @spec openspec/specs/realtime-updates/spec.md
		 * @param {string} type The collection type
		 * @return {boolean} False when the register or schema is not in scope
		 */
		ensureLiveObjectType(type) {
			if (objectStore.objectTypes.includes(type)) {
				return true
			}
			const registerId = registerStore.registerItem?.id
			const schemaId = schemaStore.schemaItem?.id
			if (!registerId || !schemaId) {
				return false
			}
			objectStore.registerObjectType(type, schemaId, registerId)
			return true
		},

		/**
		 * Subscribe to the collection type and keep the handle, unless the
		 * type cannot be registered, or the subscription was released while it
		 * was in flight: then the stale handle is dropped instead of leaked.
		 *
		 * @spec openspec/specs/realtime-updates/spec.md
		 * @param {string} type The collection type
		 * @return {Promise<void>}
		 */
		async subscribeLive(type) {
			if (!this.ensureLiveObjectType(type)) {
				return
			}
			const epoch = this.liveEpoch
			this.livePendingType = type
			this.liveType = type
			const handle = await objectStore.subscribe(type)
			this.clearLivePending(type)
			if (this.liveEpoch !== epoch) {
				objectStore.unsubscribe(handle)
				return
			}
			this.liveHandle = handle
		},

		/**
		 * Forget the pending subscribe for a type, unless a newer one replaced it.
		 *
		 * @spec openspec/specs/realtime-updates/spec.md
		 * @param {string} type The collection type
		 * @return {void}
		 */
		clearLivePending(type) {
			if (this.livePendingType === type) {
				this.livePendingType = ''
			}
		},

		/**
		 * Reset the live state after a subscribe that threw.
		 *
		 * @spec openspec/specs/realtime-updates/spec.md
		 * @param {string} type The collection type
		 * @param {Error|string|null} e What was thrown
		 * @return {void}
		 */
		liveFailed(type, e) {
			this.clearLivePending(type)
			this.liveHandle = null
			this.liveType = ''
			// eslint-disable-next-line no-console -- the views logged this warning before the extraction
			console.warn(`${tag} live subscription failed:`, e?.message ?? e)
		},

		/**
		 * Release the current subscription, if any, and invalidate any
		 * in-flight subscribe (its resolution unsubscribes itself via the
		 * epoch check).
		 *
		 * @spec openspec/specs/realtime-updates/spec.md
		 * @return {void}
		 */
		releaseLiveSubscription() {
			this.liveEpoch += 1
			this.livePendingType = ''
			if (onRelease) {
				onRelease(this)
			}
			if (this.liveHandle && typeof objectStore.unsubscribe === 'function') {
				objectStore.unsubscribe(this.liveHandle)
			}
			this.liveHandle = null
			this.liveType = ''
		},
	}
}
