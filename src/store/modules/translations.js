/**
 * Translations Store Module
 *
 * Wraps the OpenRegister translations sidecar API:
 *   - GET    /api/translations/search
 *   - GET    /api/translations/object/{uuid}?schema={ref}
 *   - POST   /api/translations/object/{uuid}/{property}/{language}/status
 *   - POST   /api/translations/object/{uuid}/bulk-translate
 *
 * @package
 * @author   Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license  EUPL-1.2
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { defineStore } from 'pinia'

const API_BASE = generateUrl('/apps/openregister/api/translations')

/**
 * BCP 47 RTL languages the editor SHOULD render right-to-left.
 *
 * @type {Set<string>}
 */
export const RTL_LANGUAGES = new Set([
	'ar', // Arabic
	'arc', // Aramaic
	'dv', // Divehi
	'fa', // Persian
	'ha', // Hausa
	'he', // Hebrew
	'khw', // Khowar
	'ks', // Kashmiri
	'ku', // Kurdish
	'ps', // Pashto
	'ur', // Urdu
	'yi', // Yiddish
])

/**
 * @param {string} language - BCP 47 language code (may include region, e.g. "ar-SA")
 * @return {boolean}
 * @spec openspec/specs/frontend-store-client-state/spec.md
 */
export function isRtlLanguage(language) {
	if (typeof language !== 'string' || language === '') return false
	const base = language.toLowerCase().split('-')[0]
	return RTL_LANGUAGES.has(base)
}

/**
 * Translation slot statuses (mirrors lib/Db/Translation.php constants).
 */
export const TRANSLATION_STATUSES = Object.freeze({
	DRAFT: 'draft',
	MACHINE_TRANSLATED: 'machine_translated',
	HUMAN_REVIEWED: 'human_reviewed',
	APPROVED: 'approved',
})

/**
 * Run a translations request with the loading flag raised and the error
 * cleared; a failure is stored, logged and rethrown.
 *
 * @param {object} store The translations store.
 * @param {{run: function(): Promise<void>, failure: string, tag: string}} job
 *     The request, the error text when the failure has no message, and the log tag.
 * @return {Promise<void>}
 * @spec openspec/specs/frontend-store-client-state/spec.md
 */
async function whileTranslating(store, job) {
	store.loading = true
	store.error = null
	try {
		await job.run()
	} catch (e) {
		store.error = e.message ?? job.failure
		console.error(job.tag, e)
		throw e
	} finally {
		store.loading = false
	}
}

/**
 * Set the status of one cached translation slot, when the object is cached.
 *
 * @param {object|undefined} cached The cached translations of the object.
 * @param {{property: string, language: string, status: string}} slot The slot and its new status.
 * @return {void}
 * @spec openspec/specs/frontend-store-client-state/spec.md
 */
function patchSlotStatus(cached, slot) {
	if (!cached?.translations) {
		return
	}
	cached.translations = cached.translations.map((entry) =>
		entry.property === slot.property && entry.language === slot.language
			? { ...entry, status: slot.status }
			: entry,
	)
}

export const useTranslationsStore = defineStore('translations', {
	state: () => ({
		// Cache: per-object slots map keyed by uuid.
		// { [uuid]: { translations: [...], completeness: { [lang]: {translated, total, ratio} } } }
		byObject: {},
		loading: false,
		error: null,
	}),
	getters: {
		/**
		 * @param {object} state
		 */
		getSlotsForObject: (state) => (uuid) =>
			state.byObject[uuid]?.translations ?? [],
		/**
		 * @param {object} state
		 */
		getCompletenessForObject: (state) => (uuid) =>
			state.byObject[uuid]?.completeness ?? {},
		isLoading: (state) => state.loading,
		getError: (state) => state.error,
	},
	actions: {
		/**
		 * Fetch all translation slots for an object + completeness.
		 *
		 * @param {string} uuid - Object UUID
		 * @param {string|number} schema - Schema id, slug, or uuid (required for completeness calc)
		 * @spec openspec/specs/frontend-store-client-state/spec.md
		 */
		async fetchByObject(uuid, schema) {
			if (!uuid) return
			return whileTranslating(this, {
				failure: 'Failed to fetch translations',
				tag: '[translations.fetchByObject]',
				run: async () => {
					const response = await axios.get(
						`${API_BASE}/object/${encodeURIComponent(uuid)}`,
						{ params: schema ? { schema } : {} },
					)
					this.byObject = {
						...this.byObject,
						[uuid]: {
							translations: response.data.translations ?? [],
							completeness: response.data.completeness ?? {},
						},
					}
				},
			})
		},

		/**
		 * Promote a translation slot to a new workflow status.
		 *
		 * @param {string} uuid - Object UUID
		 * @param {string} property - Property name
		 * @param {string} language - Language code
		 * @param {string} status - One of TRANSLATION_STATUSES
		 * @spec openspec/specs/frontend-store-client-state/spec.md
		 */
		async setStatus(uuid, property, language, status) {
			if (!Object.values(TRANSLATION_STATUSES).includes(status)) {
				throw new Error(`Invalid translation status "${status}"`)
			}
			return whileTranslating(this, {
				failure: 'Failed to update status',
				tag: '[translations.setStatus]',
				run: async () => {
					await axios.post(
						`${API_BASE}/object/${encodeURIComponent(uuid)}/${encodeURIComponent(property)}/${encodeURIComponent(language)}/status`,
						{ status },
					)
					// Patch local cache: update the matching slot in place.
					patchSlotStatus(this.byObject[uuid], {
						property,
						language,
						status,
					})
				},
			})
		},

		/**
		 * Bulk-translate an object from one language to another via the
		 * configured TranslationProvider.
		 *
		 * @param {string} uuid - Object UUID
		 * @param {string} from - Source language
		 * @param {string} to - Target language
		 * @param {string[]} [properties] - Optional property whitelist
		 * @return {Promise<object>} - { translated: {prop: value}, skipped: {prop: reason} }
		 * @spec exclude API passthrough to POST /api/translations/object/{uuid}/bulk-translate
		 */
		async bulkTranslate(uuid, from, to, properties) {
			this.loading = true
			this.error = null
			try {
				const response = await axios.post(
					`${API_BASE}/object/${encodeURIComponent(uuid)}/bulk-translate`,
					{ from, to, properties },
				)
				return response.data
			} catch (e) {
				this.error = e.message ?? 'Failed to bulk translate'
				console.error('[translations.bulkTranslate]', e)
				throw e
			} finally {
				this.loading = false
			}
		},

		/**
		 * Search the translations sidecar.
		 *
		 * @param {object} params
		 * @param {string} [params.query]
		 * @param {string} [params.language]
		 * @param {string} [params.status]
		 * @param {string} [params.objectUuid]
		 * @param {number} [params.limit]
		 * @spec exclude API passthrough to GET /api/translations/search
		 */
		async search(params = {}) {
			this.loading = true
			this.error = null
			try {
				const response = await axios.get(`${API_BASE}/search`, { params })
				return response.data
			} catch (e) {
				this.error = e.message ?? 'Failed to search translations'
				console.error('[translations.search]', e)
				throw e
			} finally {
				this.loading = false
			}
		},
	},
})
