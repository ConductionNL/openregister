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

/* eslint-disable no-console */
import { defineStore } from 'pinia'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

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
		getSlotsForObject: (state) => (uuid) => state.byObject[uuid]?.translations ?? [],
		/**
		 * @param {object} state
		 */
		getCompletenessForObject: (state) => (uuid) => state.byObject[uuid]?.completeness ?? {},
		isLoading: (state) => state.loading,
		getError: (state) => state.error,
	},
	actions: {
		/**
		 * Fetch all translation slots for an object + completeness.
		 *
		 * @param {string} uuid - Object UUID
		 * @param {string|number} schema - Schema id, slug, or uuid (required for completeness calc)
		 */
		async fetchByObject(uuid, schema) {
			if (!uuid) return
			this.loading = true
			this.error = null
			try {
				const params = schema ? { schema } : {}
				const response = await axios.get(`${API_BASE}/object/${encodeURIComponent(uuid)}`, { params })
				this.byObject = {
					...this.byObject,
					[uuid]: {
						translations: response.data.translations ?? [],
						completeness: response.data.completeness ?? {},
					},
				}
			} catch (e) {
				this.error = e.message ?? 'Failed to fetch translations'
				console.error('[translations.fetchByObject]', e)
				throw e
			} finally {
				this.loading = false
			}
		},

		/**
		 * Promote a translation slot to a new workflow status.
		 *
		 * @param {string} uuid - Object UUID
		 * @param {string} property - Property name
		 * @param {string} language - Language code
		 * @param {string} status - One of TRANSLATION_STATUSES
		 */
		async setStatus(uuid, property, language, status) {
			if (!Object.values(TRANSLATION_STATUSES).includes(status)) {
				throw new Error(`Invalid translation status "${status}"`)
			}
			this.loading = true
			this.error = null
			try {
				await axios.post(
					`${API_BASE}/object/${encodeURIComponent(uuid)}/${encodeURIComponent(property)}/${encodeURIComponent(language)}/status`,
					{ status },
				)
				// Patch local cache: update the matching slot in place.
				const cached = this.byObject[uuid]
				if (cached?.translations) {
					cached.translations = cached.translations.map((slot) => {
						if (slot.property === property && slot.language === language) {
							return { ...slot, status }
						}
						return slot
					})
				}
			} catch (e) {
				this.error = e.message ?? 'Failed to update status'
				console.error('[translations.setStatus]', e)
				throw e
			} finally {
				this.loading = false
			}
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
