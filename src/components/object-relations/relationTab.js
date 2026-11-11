/**
 * What the relation tabs of an object share: the object they are bound to,
 * their failure state and their reload when the object changes.
 *
 * @spec exclude Client-side tab plumbing; each relation's contract lives in its own capability.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

/**
 * A mixin for a tab of one object. The tab supplies `load()`.
 */
export const relationTab = {
	props: {
		register: {
			type: [String, Number],
			required: true,
		},

		schema: {
			type: [String, Number],
			required: true,
		},

		objectId: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			error: false,
			errorMessage: '',
		}
	},

	watch: {
		objectId: {
			immediate: true,
			/**
			 * @param {string} newId The object now shown
			 * @spec exclude watcher reloading the tab on objectId change, UI plumbing
			 */
			handler(newId) {
				if (newId) {
					this.load()
				}
			},
		},
	},

	methods: {
		t,

		/**
		 * @param {string} value A date
		 * @return {string} The date in the user's locale, or the value as given
		 * @spec exclude date display helper, UI plumbing
		 */
		formatDate(value) {
			if (!value) {
				return ''
			}

			try {
				const d = new Date(value)
				if (Number.isNaN(d.getTime())) {
					return value
				}

				return d.toLocaleString()
			} catch {
				return value
			}
		},

		/**
		 * @param {string} path The endpoint under the object, e.g. `/emails`
		 * @param {object} extra Further parameters the path names
		 * @return {string} The URL of the endpoint for this tab's object
		 * @spec exclude URL builder of the tab's object, UI plumbing
		 */
		objectApiUrl(path, extra = {}) {
			return generateUrl(
				'/apps/openregister/api/objects/{register}/{schema}/{id}' + path,
				{
					register: this.register,
					schema: this.schema,
					id: this.objectId,
					...extra,
				},
			)
		},

		/**
		 * @param {object} err The failure of a request
		 * @spec exclude failure state of the tab, UI plumbing
		 */
		showFailure(err) {
			this.error = true
			this.errorMessage = err.response?.data?.error || err.message || ''
		},

		/**
		 * @spec exclude failure state of the tab, UI plumbing
		 */
		clearFailure() {
			this.error = false
			this.errorMessage = ''
		},
	},
}

/**
 * A mixin for a tab whose items live in a per-object relation store.
 *
 * @param {() => object} useStore The store of the relation
 * @return {object} The mixin
 */
export function storeRelationTab(useStore) {
	return {
		mixins: [relationTab],

		data() {
			return { store: useStore() }
		},

		computed: {
			/**
			 * @spec exclude store cache key of the object, UI plumbing
			 */
			key() {
				return `${this.register}:${this.schema}:${this.objectId}`
			},

			/**
			 * @spec exclude items of the object in the store, UI plumbing
			 */
			items() {
				return this.store.byObject[this.key] || []
			},

			/**
			 * @spec exclude loading state of the object in the store, UI plumbing
			 */
			loading() {
				return !!this.store.loading[this.key]
			},
		},

		methods: {
			/**
			 * @spec exclude store passthrough loading the items, UI plumbing
			 * @return {Promise<void>}
			 */
			async load() {
				this.clearFailure()
				try {
					await this.store.fetch(this.register, this.schema, this.objectId)
				} catch (err) {
					this.showFailure(err)
				}
			},

			/**
			 * @spec exclude store passthrough unlinking an item, UI plumbing
			 * @param {string|number} ref The reference of the item in the store
			 * @param {string} changed The event that reports the new count
			 * @return {Promise<void>}
			 */
			async unlink(ref, changed) {
				try {
					await this.store.unlink(this.register, this.schema, this.objectId, ref)
					this.$emit(changed, this.items.length)
				} catch (err) {
					this.showFailure(err)
				}
			},
		},
	}
}
