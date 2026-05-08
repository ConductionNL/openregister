/**
 * AVG (GDPR) Store Module
 *
 * Wraps the OpenRegister AVG admin surface:
 *   - CRUD over verwerkingsactiviteiten:
 *     GET/POST/PUT/DELETE /api/avg/verwerkingsactiviteiten[/{id}]
 *   - Art 30 §4 verantwoordingsdocument:
 *     GET /api/avg/verantwoording
 *   - Data-subject rights (Art 15/16/17/20):
 *     GET  /api/avg/inzage
 *     GET  /api/avg/portabiliteit
 *     POST /api/avg/vergetelheid
 *     POST /api/avg/rectificatie
 *   - Compliance audit:
 *     GET /api/avg/compliance
 *
 * @package
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 */

/* eslint-disable no-console */
import { defineStore } from 'pinia'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const API_BASE = generateUrl('/apps/openregister/api/avg')

/**
 * Article 6 GDPR legal-basis vocabulary. Mirrors
 * `Verwerkingsactiviteit::RECHTSGROND_VOCABULARY` on the backend.
 */
export const RECHTSGROND_VOCABULARY = Object.freeze([
	'toestemming',
	'overeenkomst',
	'wettelijke_verplichting',
	'vitaal_belang',
	'publieke_taak',
	'gerechtvaardigd_belang',
])

/**
 * Lifecycle status vocabulary. Mirrors
 * `Verwerkingsactiviteit::STATUS_VOCABULARY`.
 */
export const STATUS_VOCABULARY = Object.freeze(['concept', 'published', 'archived'])

export const useAvgStore = defineStore('avg', {
	state: () => ({
		activities: [],
		activeActivity: null,
		verantwoording: null,
		dsarResults: null,
		dsarSummary: null,
		complianceReport: null,
		loading: false,
		error: null,
	}),
	getters: {
		isLoading: (state) => state.loading,
		getError: (state) => state.error,
		getActivities: (state) => state.activities,
		getActiveActivity: (state) => state.activeActivity,
		getVerantwoording: (state) => state.verantwoording,
		getDsarResults: (state) => state.dsarResults,
		getDsarSummary: (state) => state.dsarSummary,
		getComplianceReport: (state) => state.complianceReport,
		/**
		 * @param {object} state
		 */
		getActivityByUuid: (state) => (uuid) =>
			state.activities.find((a) => a.uuid === uuid) ?? null,
	},
	actions: {
		clearError() {
			this.error = null
		},

		/**
		 * Fetch all verwerkingsactiviteiten.
		 *
		 * @param {object} params Optional `?status=` and `?organisation=` query filters.
		 */
		async fetchActivities(params = {}) {
			this.loading = true
			this.error = null
			try {
				const response = await axios.get(`${API_BASE}/verwerkingsactiviteiten`, { params })
				this.activities = response.data?.results ?? []
				return this.activities
			} catch (e) {
				this.error = e.message ?? 'Failed to fetch verwerkingsactiviteiten'
				console.error('[avg.fetchActivities]', e)
				throw e
			} finally {
				this.loading = false
			}
		},

		/**
		 * Fetch one activity by id (numeric), uuid, or short readable code.
		 *
		 * @param {string|number} identifier
		 */
		async fetchActivity(identifier) {
			if (!identifier) return null
			this.loading = true
			this.error = null
			try {
				const response = await axios.get(
					`${API_BASE}/verwerkingsactiviteiten/${encodeURIComponent(identifier)}`,
				)
				this.activeActivity = response.data ?? null
				return this.activeActivity
			} catch (e) {
				this.error = e.message ?? 'Failed to fetch verwerkingsactiviteit'
				console.error('[avg.fetchActivity]', e)
				throw e
			} finally {
				this.loading = false
			}
		},

		/**
		 * Create a new verwerkingsactiviteit. Admin-only on the backend.
		 *
		 * @param {object} payload Mirrors the entity's `set*` accepting fields.
		 */
		async createActivity(payload) {
			this.loading = true
			this.error = null
			try {
				const response = await axios.post(
					`${API_BASE}/verwerkingsactiviteiten`,
					payload,
				)
				const created = response.data
				this.activities = [...this.activities, created]
				this.activeActivity = created
				return created
			} catch (e) {
				this.error = e.response?.data?.error ?? e.message ?? 'Failed to create verwerkingsactiviteit'
				console.error('[avg.createActivity]', e)
				throw e
			} finally {
				this.loading = false
			}
		},

		/**
		 * Update an existing verwerkingsactiviteit. Admin-only.
		 *
		 * @param {string|number} identifier id|uuid|code
		 * @param {object}        payload    Fields to overwrite.
		 */
		async updateActivity(identifier, payload) {
			this.loading = true
			this.error = null
			try {
				const response = await axios.put(
					`${API_BASE}/verwerkingsactiviteiten/${encodeURIComponent(identifier)}`,
					payload,
				)
				const updated = response.data
				this.activities = this.activities.map((a) =>
					a.uuid === updated.uuid ? updated : a,
				)
				if (this.activeActivity?.uuid === updated.uuid) {
					this.activeActivity = updated
				}
				return updated
			} catch (e) {
				this.error = e.response?.data?.error ?? e.message ?? 'Failed to update verwerkingsactiviteit'
				console.error('[avg.updateActivity]', e)
				throw e
			} finally {
				this.loading = false
			}
		},

		/**
		 * Soft-archive an activity. Admin-only. Never hard-deletes —
		 * audit-trail FKs need the row to remain resolvable.
		 *
		 * @param {string|number} identifier id|uuid|code
		 */
		async archiveActivity(identifier) {
			this.loading = true
			this.error = null
			try {
				await axios.delete(
					`${API_BASE}/verwerkingsactiviteiten/${encodeURIComponent(identifier)}`,
				)
				// Reflect locally — flip status to archived.
				this.activities = this.activities.map((a) =>
					(a.id === identifier || a.uuid === identifier || a.code === identifier)
						? { ...a, status: 'archived' }
						: a,
				)
				return true
			} catch (e) {
				this.error = e.response?.data?.error ?? e.message ?? 'Failed to archive verwerkingsactiviteit'
				console.error('[avg.archiveActivity]', e)
				throw e
			} finally {
				this.loading = false
			}
		},

		/**
		 * Fetch the Art 30 §4 verantwoordingsdocument — joins activities
		 * with audit-trail row counts per processing activity.
		 */
		async fetchVerantwoording() {
			this.loading = true
			this.error = null
			try {
				const response = await axios.get(`${API_BASE}/verantwoording`)
				this.verantwoording = response.data
				return this.verantwoording
			} catch (e) {
				this.error = e.message ?? 'Failed to fetch verantwoordingsdocument'
				console.error('[avg.fetchVerantwoording]', e)
				throw e
			} finally {
				this.loading = false
			}
		},

		/**
		 * Run a DSAR inzageverzoek (Art 15) for the given subject.
		 *
		 * @param {object} params {subject, type?, mode?}
		 */
		async runInzage({ subject, type, mode }) {
			if (!subject) return null
			this.loading = true
			this.error = null
			try {
				const params = { subject }
				if (type) params.type = type
				if (mode) params.mode = mode
				const response = await axios.get(`${API_BASE}/inzage`, { params })
				this.dsarResults = response.data
				return this.dsarResults
			} catch (e) {
				this.error = e.response?.data?.error ?? e.message ?? 'Failed to run inzage'
				console.error('[avg.runInzage]', e)
				throw e
			} finally {
				this.loading = false
			}
		},

		/**
		 * Run a vergetelheid request (Art 17). Pass `dryRun: true` to
		 * preview the matched set before committing.
		 *
		 * @param {object} params {subject, type?, dryRun?}
		 */
		async runVergetelheid({ subject, type, dryRun = false }) {
			if (!subject) return null
			this.loading = true
			this.error = null
			try {
				const params = { subject }
				if (type) params.type = type
				if (dryRun) params.dryRun = 'true'
				const response = await axios.post(`${API_BASE}/vergetelheid`, null, { params })
				this.dsarSummary = response.data
				return this.dsarSummary
			} catch (e) {
				this.error = e.response?.data?.error ?? e.message ?? 'Failed to run vergetelheid'
				console.error('[avg.runVergetelheid]', e)
				throw e
			} finally {
				this.loading = false
			}
		},

		/**
		 * Fetch the Art 20 portabiliteit envelope for the given subject.
		 *
		 * @param {object} params {subject, type?}
		 */
		async runPortabiliteit({ subject, type }) {
			if (!subject) return null
			this.loading = true
			this.error = null
			try {
				const params = { subject }
				if (type) params.type = type
				const response = await axios.get(`${API_BASE}/portabiliteit`, { params })
				return response.data
			} catch (e) {
				this.error = e.response?.data?.error ?? e.message ?? 'Failed to run portabiliteit'
				console.error('[avg.runPortabiliteit]', e)
				throw e
			} finally {
				this.loading = false
			}
		},

		/**
		 * Apply a rectificatie change set to a single object.
		 *
		 * @param {object} payload {objectId, changes}
		 */
		async runRectificatie(payload) {
			this.loading = true
			this.error = null
			try {
				const response = await axios.post(`${API_BASE}/rectificatie`, payload)
				return response.data
			} catch (e) {
				this.error = e.response?.data?.error ?? e.message ?? 'Failed to run rectificatie'
				console.error('[avg.runRectificatie]', e)
				throw e
			} finally {
				this.loading = false
			}
		},

		/**
		 * Fetch the compliance report (currently: schemas with PII but
		 * no `x-openregister-processing-activity` annotation).
		 */
		async fetchCompliance() {
			this.loading = true
			this.error = null
			try {
				const response = await axios.get(`${API_BASE}/compliance`)
				this.complianceReport = response.data
				return this.complianceReport
			} catch (e) {
				this.error = e.response?.data?.error ?? e.message ?? 'Failed to fetch compliance report'
				console.error('[avg.fetchCompliance]', e)
				throw e
			} finally {
				this.loading = false
			}
		},
	},
})
