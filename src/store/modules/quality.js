/**
 * MDM Quality Store Module
 *
 * Backs the four steward-facing "Data quality" views (Data Quality
 * dashboard, Duplicate Candidates, Master entities, Queue / sync-health)
 * added by the mdm-frontend change. Mirrors `reports.js`: Options-API
 * `defineStore` + `@nextcloud/axios` + `generateUrl`, no custom store base
 * class. Every action is a GET-only read against the already-merged
 * `mdm-surface-api` / `mdm-survivorship-engine` backends plus the existing
 * webhook telemetry endpoints — this module introduces no new backend
 * surface.
 *
 * @package
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/mdm-frontend/tasks.md#task-1.1
 */

import { defineStore } from 'pinia'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const API_BASE = generateUrl('/apps/openregister/api')

export const useQualityStore = defineStore('quality', {
	state: () => ({
		// Shared (register, schema) selection — held here so switching
		// between the four MDM views keeps the same selection.
		selectedRegister: null,
		selectedSchema: null,

		registers: [],
		schemas: [],

		qualityStats: null,
		lowQualityObjects: [],
		lowQualityTotal: 0,
		lowQualityLimit: 20,
		lowQualityOffset: 0,

		duplicates: [],
		duplicatesTotal: 0,
		duplicatesLimit: 20,
		duplicatesOffset: 0,

		masterEntities: [],
		masterEntitiesTotal: 0,
		goldenRecord: null,

		webhooks: [],
		webhookStats: {},
		webhookFailures: [],

		loading: false,
		error: null,
	}),
	getters: {
		isLoading: (state) => state.loading,
		getError: (state) => state.error,
		hasSelection: (state) => Boolean(state.selectedRegister && state.selectedSchema),
	},
	actions: {
		/**
		 * Clear the store error.
		 *
		 * @spec exclude store setter (clears local error state)
		 */
		clearError() {
			this.error = null
		},

		/**
		 * Set the shared (register, schema) selection used by every MDM view.
		 *
		 * @param {string|number|null} register Register id/slug.
		 * @param {string|number|null} schema   Schema id/slug.
		 * @spec exclude client-state setter — shared selection, no backend contract.
		 */
		setSelection(register, schema) {
			this.selectedRegister = register || null
			this.selectedSchema = schema || null
		},

		/**
		 * List registers (extended with their schemas) for the register/schema
		 * selector.
		 *
		 * @spec exclude API passthrough to GET /api/registers?_extend[]=schemas (selector data source)
		 */
		async fetchRegisters() {
			this.loading = true
			this.error = null
			try {
				const response = await axios.get(`${API_BASE}/registers`, {
					params: { '_extend[]': 'schemas' },
				})
				this.registers = response.data?.results ?? response.data ?? []
				return this.registers
			} catch (e) {
				this.error = e.response?.data?.error ?? e.message ?? 'Failed to fetch registers'
				console.error('[quality.fetchRegisters]', e)
				throw e
			} finally {
				this.loading = false
			}
		},

		/**
		 * List the schemas belonging to a register, for the schema selector.
		 *
		 * @param {string|number} registerId Register id/slug.
		 * @spec exclude API passthrough to GET /api/registers/{id}/schemas (selector data source)
		 */
		async fetchSchemasForRegister(registerId) {
			if (!registerId) {
				this.schemas = []
				return this.schemas
			}
			this.loading = true
			this.error = null
			try {
				const response = await axios.get(
					`${API_BASE}/registers/${encodeURIComponent(registerId)}/schemas`,
				)
				this.schemas = response.data?.results ?? response.data ?? []
				return this.schemas
			} catch (e) {
				this.error = e.response?.data?.error ?? e.message ?? 'Failed to fetch schemas'
				console.error('[quality.fetchSchemasForRegister]', e)
				throw e
			} finally {
				this.loading = false
			}
		},

		/**
		 * Fetch quality statistics (average, buckets, histogram) for a
		 * register/schema.
		 *
		 * @param {string|number} register Register reference.
		 * @param {string|number} schema   Schema reference.
		 * @spec openspec/changes/mdm-frontend/specs/mdm-frontend/spec.md#requirement-data-quality-dashboard
		 */
		async fetchQualityStats(register, schema) {
			if (!register || !schema) return null
			this.loading = true
			this.error = null
			try {
				const response = await axios.get(
					`${API_BASE}/objects/quality/${encodeURIComponent(register)}/${encodeURIComponent(schema)}/stats`,
				)
				this.qualityStats = response.data ?? null
				return this.qualityStats
			} catch (e) {
				this.error = e.response?.data?.error ?? e.message ?? 'Failed to fetch quality statistics'
				console.error('[quality.fetchQualityStats]', e)
				throw e
			} finally {
				this.loading = false
			}
		},

		/**
		 * Fetch the lowest-quality object listing (paginated).
		 *
		 * @param {string|number} register Register reference.
		 * @param {string|number} schema   Schema reference.
		 * @param {object} params { limit, offset, qualityStatus, sort, order }
		 * @spec openspec/changes/mdm-frontend/specs/mdm-frontend/spec.md#requirement-data-quality-dashboard
		 */
		async fetchLowQualityObjects(register, schema, params = {}) {
			if (!register || !schema) return { items: [], total: 0 }
			this.loading = true
			this.error = null
			const limit = params.limit ?? this.lowQualityLimit
			const offset = params.offset ?? this.lowQualityOffset
			try {
				const response = await axios.get(
					`${API_BASE}/objects/quality/${encodeURIComponent(register)}/${encodeURIComponent(schema)}`,
					{ params: { ...params, limit, offset } },
				)
				const data = response.data ?? {}
				this.lowQualityObjects = data.items ?? []
				this.lowQualityTotal = data.total ?? 0
				this.lowQualityLimit = data.limit ?? limit
				this.lowQualityOffset = data.offset ?? offset
				return data
			} catch (e) {
				this.error = e.response?.data?.error ?? e.message ?? 'Failed to fetch lowest-quality objects'
				console.error('[quality.fetchLowQualityObjects]', e)
				throw e
			} finally {
				this.loading = false
			}
		},

		/**
		 * Fetch duplicate-candidate pairs (paginated, read-only).
		 *
		 * @param {string|number} register Register reference.
		 * @param {string|number} schema   Schema reference.
		 * @param {object} params { limit, offset, threshold }
		 * @spec openspec/changes/mdm-frontend/specs/mdm-frontend/spec.md#requirement-duplicate-candidates-view-read-only
		 */
		async fetchDuplicates(register, schema, params = {}) {
			if (!register || !schema) return { items: [], total: 0 }
			this.loading = true
			this.error = null
			const limit = params.limit ?? this.duplicatesLimit
			const offset = params.offset ?? this.duplicatesOffset
			try {
				const response = await axios.get(
					`${API_BASE}/objects/duplicates/${encodeURIComponent(register)}/${encodeURIComponent(schema)}`,
					{ params: { ...params, limit, offset } },
				)
				const data = response.data ?? {}
				this.duplicates = data.items ?? []
				this.duplicatesTotal = data.total ?? 0
				this.duplicatesLimit = data.limit ?? limit
				this.duplicatesOffset = data.offset ?? offset
				return data
			} catch (e) {
				this.error = e.response?.data?.error ?? e.message ?? 'Failed to fetch duplicate candidates'
				console.error('[quality.fetchDuplicates]', e)
				throw e
			} finally {
				this.loading = false
			}
		},

		/**
		 * Fetch master-entity objects (with qualityScore/qualityStatus) for a
		 * survivorship-enabled register/schema, via the existing object-read
		 * endpoint (ADR-022 — reuses the generic object list, does not
		 * re-implement object fetching).
		 *
		 * @param {string|number} register Register reference.
		 * @param {string|number} schema   Schema reference.
		 * @param {object} params { limit, offset, sort, order }
		 * @spec openspec/changes/mdm-frontend/specs/mdm-frontend/spec.md#requirement-master-entity-list-with-golden-record-detail
		 */
		async fetchMasterEntities(register, schema, params = {}) {
			if (!register || !schema) return { results: [], total: 0 }
			this.loading = true
			this.error = null
			try {
				const response = await axios.get(
					`${API_BASE}/objects/${encodeURIComponent(register)}/${encodeURIComponent(schema)}`,
					{ params },
				)
				const data = response.data ?? {}
				this.masterEntities = data.results ?? data.items ?? []
				this.masterEntitiesTotal = data.total ?? this.masterEntities.length
				return data
			} catch (e) {
				this.error = e.response?.data?.error ?? e.message ?? 'Failed to fetch master entities'
				console.error('[quality.fetchMasterEntities]', e)
				throw e
			} finally {
				this.loading = false
			}
		},

		/**
		 * Fetch a single master entity's golden record (including its
		 * materialised `attributeProvenance` map) for the detail panel.
		 *
		 * @param {string|number} register Register reference.
		 * @param {string|number} schema   Schema reference.
		 * @param {string|number} id       Object id/uuid.
		 * @spec openspec/changes/mdm-frontend/specs/mdm-frontend/spec.md#requirement-master-entity-list-with-golden-record-detail
		 */
		async fetchGoldenRecord(register, schema, id) {
			if (!register || !schema || !id) return null
			this.loading = true
			this.error = null
			try {
				const response = await axios.get(
					`${API_BASE}/objects/${encodeURIComponent(register)}/${encodeURIComponent(schema)}/${encodeURIComponent(id)}`,
				)
				this.goldenRecord = response.data ?? null
				return this.goldenRecord
			} catch (e) {
				this.error = e.response?.data?.error ?? e.message ?? 'Failed to fetch golden record'
				console.error('[quality.fetchGoldenRecord]', e)
				throw e
			} finally {
				this.loading = false
			}
		},

		/**
		 * Fetch webhook queue-health: the webhook list, per-webhook
		 * delivered/failed/pendingRetries stats, and recent failed
		 * deliveries. Consumes OR's EXISTING webhook read APIs
		 * (webhooks#index, webhooks#logStats, webhooks#allLogs) — no new
		 * backend endpoint (design.md D1).
		 *
		 * @spec openspec/changes/mdm-frontend/specs/mdm-frontend/spec.md#requirement-queue--sync-health-view
		 */
		async fetchWebhookHealth() {
			this.loading = true
			this.error = null
			try {
				const listResponse = await axios.get(`${API_BASE}/webhooks`)
				const webhooks = listResponse.data?.results ?? []
				this.webhooks = webhooks

				if (webhooks.length === 0) {
					this.webhookStats = {}
					this.webhookFailures = []
					return { webhooks: [], stats: {}, failures: [] }
				}

				const statsEntries = await Promise.all(
					webhooks.map(async (webhook) => {
						try {
							const statsResponse = await axios.get(
								`${API_BASE}/webhooks/${encodeURIComponent(webhook.id)}/logs/stats`,
							)
							return [webhook.id, statsResponse.data ?? {}]
						} catch (e) {
							console.error('[quality.fetchWebhookHealth] per-webhook stats failed', { webhook, error: e })
							return [webhook.id, null]
						}
					}),
				)
				this.webhookStats = Object.fromEntries(statsEntries)

				const failuresResponse = await axios.get(`${API_BASE}/webhooks/logs`, {
					params: { success: 'false' },
				})
				this.webhookFailures = failuresResponse.data?.results ?? []

				return { webhooks: this.webhooks, stats: this.webhookStats, failures: this.webhookFailures }
			} catch (e) {
				this.error = e.response?.data?.error ?? e.message ?? 'Failed to fetch webhook health'
				console.error('[quality.fetchWebhookHealth]', e)
				throw e
			} finally {
				this.loading = false
			}
		},
	},
})
