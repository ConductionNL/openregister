/**
 * The quota fields of the organisation and application edit modals. Storage
 * and bandwidth are entered in MB and stored in bytes, 0 means unlimited.
 *
 * @spec exclude Reactive quota form bindings; UI plumbing.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */

const BYTES_PER_MB = 1024 * 1024

/**
 * A quota without limits.
 *
 * @return {object} The quota
 */
export function unlimitedQuota() {
	return { storage: 0, bandwidth: 0, requests: 0, users: 0, groups: 0 }
}

/**
 * The whole number in a form value, 0 for an empty one.
 *
 * @param {string|number} value The form value
 * @return {number} The number
 */
function quotaNumber(value) {
	return value ? parseInt(value) : 0
}

/**
 * Stored bytes as whole MB.
 *
 * @param {number} [bytes] The stored bytes
 * @return {number} The MB
 */
function asMegabytes(bytes) {
	return bytes ? Math.round(bytes / BYTES_PER_MB) : 0
}

/**
 * The quota of a form item, created when the item has none yet.
 *
 * @param {object} item The form item
 * @return {object} The quota
 */
function quotaOf(item) {
	item.quota ||= unlimitedQuota()
	return item.quota
}

/**
 * A mixin with the quota fields. The component names its form item through a
 * `quotaItem` computed.
 *
 * @spec exclude Reactive quota form bindings; UI plumbing.
 */
export const quotaFields = {
	computed: {
		/** @spec exclude Reactive quota form binding; UI plumbing. */
		storageQuotaMB() {
			return asMegabytes(this.quotaItem.quota?.storage)
		},

		/** @spec exclude Reactive quota form binding; UI plumbing. */
		bandwidthQuotaMB() {
			return asMegabytes(this.quotaItem.quota?.bandwidth)
		},
	},

	methods: {
		/**
		 * @param {string|number} value The MB entered
		 * @spec exclude Reactive quota form binding; UI plumbing.
		 */
		updateStorageQuota(value) {
			quotaOf(this.quotaItem).storage = quotaNumber(value) * BYTES_PER_MB
		},

		/**
		 * @param {string|number} value The MB entered
		 * @spec exclude Reactive quota form binding; UI plumbing.
		 */
		updateBandwidthQuota(value) {
			quotaOf(this.quotaItem).bandwidth = quotaNumber(value) * BYTES_PER_MB
		},

		/**
		 * @param {string|number} value The requests per day entered
		 * @spec exclude Reactive quota form binding; UI plumbing.
		 */
		updateRequestQuota(value) {
			quotaOf(this.quotaItem).requests = quotaNumber(value)
		},

		/**
		 * @param {string|number} value The users entered
		 * @spec exclude Reactive quota form binding; UI plumbing.
		 */
		updateUserQuota(value) {
			quotaOf(this.quotaItem).users = quotaNumber(value)
		},

		/**
		 * @param {string|number} value The groups entered
		 * @spec exclude Reactive quota form binding; UI plumbing.
		 */
		updateGroupQuota(value) {
			quotaOf(this.quotaItem).groups = quotaNumber(value)
		},
	},
}
