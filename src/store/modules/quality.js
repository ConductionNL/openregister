/**
 * MDM Quality Store Module
 *
 * Backs the steward-facing "Data quality" views (Data Quality dashboard,
 * Duplicate Candidates, Master entities, Queue / sync-health, Merge
 * Operations) added by the mdm-frontend (#3) and mdm-merge-ui (#C) changes.
 * Mirrors `reports.js`: Options-API `defineStore` + `@nextcloud/axios` +
 * `generateUrl`, no custom store base class. The read-only actions are
 * GET-only passthroughs; the merge actions (`previewMerge`, `executeMerge`,
 * `fetchMergeOperations`, `reverseMerge`) are thin wrappers over the
 * already-merged MDM merge engine (#B); the conflict-resolution actions
 * (`setAttributeOverride`, `clearAttributeOverride`, `persistTrustRule`) are
 * thin wrappers over the per-object override endpoint (#E) and the generic
 * object CRUD surface (ADR-022) — no merge/survivorship logic lives
 * client-side.
 *
 * @package
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/mdm-frontend/tasks.md#task-1.1
 * @spec openspec/changes/mdm-merge-ui/tasks.md#task-1
 * @spec openspec/changes/mdm-survivorship-override/tasks.md#2.1
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { defineStore } from 'pinia'

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

		mergeOperations: [],
		mergeOperationsTotal: 0,
		mergeOperationsLimit: 20,
		mergeOperationsOffset: 0,

		webhooks: [],
		webhookStats: {},
		webhookFailures: [],

		loading: false,
		error: null,
	}),
	getters: {
		isLoading: (state) => state.loading,
		getError: (state) => state.error,
		hasSelection: (state) =>
			Boolean(state.selectedRegister && state.selectedSchema),
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
				this.error =
					e.response?.data?.error
					?? e.message
					?? 'Failed to fetch registers'
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
				this.error =
					e.response?.data?.error ?? e.message ?? 'Failed to fetch schemas'
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
		 * @spec openspec/specs/mdm-frontend/spec.md#requirement-data-quality-dashboard
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
				this.error =
					e.response?.data?.error
					?? e.message
					?? 'Failed to fetch quality statistics'
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
		 * @spec openspec/specs/mdm-frontend/spec.md#requirement-data-quality-dashboard
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
				this.error =
					e.response?.data?.error
					?? e.message
					?? 'Failed to fetch lowest-quality objects'
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
		 * @spec openspec/specs/mdm-frontend/spec.md#requirement-duplicate-candidates-view-read-only
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
				this.error =
					e.response?.data?.error
					?? e.message
					?? 'Failed to fetch duplicate candidates'
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
		 * @spec openspec/specs/mdm-frontend/spec.md#requirement-master-entity-list-with-golden-record-detail
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
				this.error =
					e.response?.data?.error
					?? e.message
					?? 'Failed to fetch master entities'
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
		 * @spec openspec/specs/mdm-frontend/spec.md#requirement-master-entity-list-with-golden-record-detail
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
				this.error =
					e.response?.data?.error
					?? e.message
					?? 'Failed to fetch golden record'
				console.error('[quality.fetchGoldenRecord]', e)
				throw e
			} finally {
				this.loading = false
			}
		},

		/**
		 * Fetch a master's resolved competing source records (embedded or
		 * reverse-FK), for the conflict-resolution modal. A reverse-FK master
		 * carries no embedded source array, so the modal cannot compute
		 * per-attribute conflicts without this server-resolved list.
		 *
		 * @param {string|number} id Master object id.
		 * @return {Promise<Array<object>>} Resolved source records (empty on failure).
		 * @spec openspec/changes/mdm-reverse-fk-source-resolution/tasks.md#2.1
		 */
		async fetchMasterSources(id) {
			if (!id) return []
			try {
				const response = await axios.get(
					`${API_BASE}/objects/survivorship/${encodeURIComponent(id)}/sources`,
				)
				return response.data?.sources ?? []
			} catch (e) {
				console.error('[quality.fetchMasterSources]', e)
				return []
			}
		},

		/**
		 * Side-effect-free preview of a merge: projected survivor golden
		 * record, per-attribute provenance and reversal deadline. Thin
		 * wrapper over the merge engine's preview endpoint — no
		 * survivorship/conflict-resolution logic lives here (design.md).
		 *
		 * @param {string|number} from Merged-away object id.
		 * @param {string|number} into Surviving object id.
		 * @spec openspec/specs/mdm-merge-ui/spec.md#requirement-merge-wizard-previews-the-post-merge-golden-record-before-executing
		 */
		async previewMerge(from, into) {
			this.loading = true
			this.error = null
			try {
				const response = await axios.post(
					`${API_BASE}/objects/merge/preview`,
					{ from, into },
				)
				return response.data
			} catch (e) {
				this.error =
					e.response?.data?.error ?? e.message ?? 'Failed to preview merge'
				console.error('[quality.previewMerge]', e)
				throw e
			} finally {
				this.loading = false
			}
		},

		/**
		 * Execute an auditable merge: relink source records, recompute the
		 * survivor, and persist a `mergeOperation` row. Thin wrapper over the
		 * merge engine's execute endpoint.
		 *
		 * @param {string|number} from   Merged-away object id.
		 * @param {string|number} into   Surviving object id.
		 * @param {string}        reason Steward-supplied merge reason.
		 * @spec openspec/specs/mdm-merge-ui/spec.md#requirement-merge-wizard-captures-a-reason-and-executes-an-auditable-merge
		 */
		async executeMerge(from, into, reason) {
			this.loading = true
			this.error = null
			try {
				const response = await axios.post(
					`${API_BASE}/objects/merge/execute`,
					{ from, into, reason },
				)
				return response.data
			} catch (e) {
				this.error =
					e.response?.data?.error ?? e.message ?? 'Failed to execute merge'
				console.error('[quality.executeMerge]', e)
				throw e
			} finally {
				this.loading = false
			}
		},

		/**
		 * List recent `mergeOperation` audit rows, read through the generic
		 * object-read surface (no dedicated merge-operations endpoint).
		 *
		 * @param {object} params { register, schema, limit, offset } — register/schema
		 *                        default to the mergeOperation register/schema (#B).
		 * @spec openspec/specs/mdm-merge-ui/spec.md#requirement-steward-reviews-recent-merge-operations-in-a-dedicated-view
		 */
		async fetchMergeOperations(params = {}) {
			this.loading = true
			this.error = null
			const limit = params.limit ?? this.mergeOperationsLimit
			const offset = params.offset ?? this.mergeOperationsOffset
			try {
				// The generic objects endpoint treats `limit`/`offset` as data
				// FILTERS; pagination uses the underscored `_limit`/`_offset`.
				// Passing `limit` here filtered the list down to nothing.
				const response = await axios.get(
					`${API_BASE}/objects/merge-operation/mergeOperation`,
					{ params: { _limit: limit, _offset: offset } },
				)
				const data = response.data ?? {}
				this.mergeOperations = data.results ?? data.items ?? []
				this.mergeOperationsTotal = data.total ?? this.mergeOperations.length
				this.mergeOperationsLimit = data.limit ?? limit
				this.mergeOperationsOffset = data.offset ?? offset
				return data
			} catch (e) {
				this.error =
					e.response?.data?.error
					?? e.message
					?? 'Failed to fetch merge operations'
				console.error('[quality.fetchMergeOperations]', e)
				throw e
			} finally {
				this.loading = false
			}
		},

		/**
		 * Reverse a merge within its reversal window, restoring both objects
		 * and flipping the operation to non-reversible. Thin wrapper over
		 * the merge engine's reverse endpoint.
		 *
		 * @param {string|number} id `mergeOperation` uuid.
		 * @spec openspec/specs/mdm-merge-ui/spec.md#requirement-steward-reverses-a-merge-within-its-reversal-window
		 */
		async reverseMerge(id) {
			this.loading = true
			this.error = null
			try {
				const response = await axios.post(
					`${API_BASE}/objects/merge/${encodeURIComponent(id)}/reverse`,
				)
				return response.data
			} catch (e) {
				this.error =
					e.response?.data?.error ?? e.message ?? 'Failed to reverse merge'
				console.error('[quality.reverseMerge]', e)
				throw e
			} finally {
				this.loading = false
			}
		},

		/**
		 * Set (with a value) or clear (with `clear: true`) one attribute
		 * override on a master object via the survivorship override endpoint
		 * (#E). The endpoint triggers a recompute server-side; the caller is
		 * responsible for refreshing the golden record afterwards.
		 *
		 * @param {string|number} id        Master object id/uuid.
		 * @param {string} attribute        Attribute name to override.
		 * @param {*} value                 Winning value to pin.
		 * @param {string} [rationale]      Optional steward rationale.
		 * @spec openspec/specs/mdm-conflict-resolution-ui/spec.md#scenario-setattributeoverride-posts-to-the-override-endpoint
		 */
		async setAttributeOverride(id, attribute, value, rationale) {
			this.loading = true
			this.error = null
			try {
				const response = await axios.post(
					`${API_BASE}/objects/survivorship/${encodeURIComponent(id)}/override`,
					{ attribute, value, rationale },
				)
				return response.data
			} catch (e) {
				this.error =
					e.response?.data?.error
					?? e.message
					?? 'Failed to set the attribute override'
				console.error('[quality.setAttributeOverride]', e)
				throw e
			} finally {
				this.loading = false
			}
		},

		/**
		 * Clear a previously-set attribute override, falling back to
		 * tier-based resolution for that attribute on recompute.
		 *
		 * @param {string|number} id   Master object id/uuid.
		 * @param {string} attribute   Attribute name whose override to clear.
		 * @spec openspec/specs/mdm-survivorship/spec.md#scenario-clearing-an-override-falls-back-to-trust-resolution
		 */
		async clearAttributeOverride(id, attribute) {
			this.loading = true
			this.error = null
			try {
				const response = await axios.post(
					`${API_BASE}/objects/survivorship/${encodeURIComponent(id)}/override`,
					{ attribute, clear: true },
				)
				return response.data
			} catch (e) {
				this.error =
					e.response?.data?.error
					?? e.message
					?? 'Failed to clear the attribute override'
				console.error('[quality.clearAttributeOverride]', e)
				throw e
			} finally {
				this.loading = false
			}
		},

		/**
		 * Persist a `trustConfiguration` row for an (entityType, attribute,
		 * sourceSystem) tuple via the generic `/api/objects` CRUD surface
		 * (ADR-022) — no bespoke trust-rule endpoint.
		 *
		 * @param {object} params              Trust-rule fields.
		 * @param {string} params.entityType   Entity type the rule applies to.
		 * @param {string} params.attribute    Attribute the rule applies to.
		 * @param {string} params.sourceSystem Source system the rule applies to.
		 * @param {string} params.trustTier    Winning trust tier for this tuple.
		 * @param {string} [params.rationale]  Optional steward rationale.
		 * @spec openspec/specs/mdm-conflict-resolution-ui/spec.md#scenario-persisttrustrule-creates-a-trust-configuration-object
		 */
		async persistTrustRule({
			entityType,
			attribute,
			sourceSystem,
			trustTier,
			rationale,
		}) {
			this.loading = true
			this.error = null
			try {
				const response = await axios.post(
					`${API_BASE}/objects/trust-configuration/trustConfiguration`,
					{ entityType, attribute, sourceSystem, trustTier, rationale },
				)
				return response.data
			} catch (e) {
				this.error =
					e.response?.data?.error
					?? e.message
					?? 'Failed to persist the trust rule'
				console.error('[quality.persistTrustRule]', e)
				throw e
			} finally {
				this.loading = false
			}
		},

		/**
		 * Touch-save a master object (empty PATCH) via the generic object
		 * CRUD surface (ADR-022) so its `SurvivorshipRecomputeListener`
		 * recompute runs against freshly-persisted trust rows. Used after a
		 * persistent trust-rule write, which does not itself touch the
		 * master object and therefore would not otherwise trigger a
		 * recompute of this object's golden record (design.md).
		 *
		 * @param {string|number} register Register reference.
		 * @param {string|number} schema   Schema reference.
		 * @param {string|number} id       Master object id/uuid.
		 * @spec openspec/specs/mdm-conflict-resolution-ui/spec.md#scenario-persistent-choice-writes-a-trust-configuration-row
		 */
		async touchObject(register, schema, id) {
			this.loading = true
			this.error = null
			try {
				const response = await axios.patch(
					`${API_BASE}/objects/${encodeURIComponent(register)}/${encodeURIComponent(schema)}/${encodeURIComponent(id)}`,
					{},
				)
				return response.data
			} catch (e) {
				this.error =
					e.response?.data?.error
					?? e.message
					?? 'Failed to recompute the master object'
				console.error('[quality.touchObject]', e)
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
		 * @spec openspec/specs/mdm-frontend/spec.md#requirement-queue-sync-health-view
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
							console.error(
								'[quality.fetchWebhookHealth] per-webhook stats failed',
								{ webhook, error: e },
							)
							return [webhook.id, null]
						}
					}),
				)
				this.webhookStats = Object.fromEntries(statsEntries)

				const failuresResponse = await axios.get(
					`${API_BASE}/webhooks/logs`,
					{
						params: { success: 'false' },
					},
				)
				this.webhookFailures = failuresResponse.data?.results ?? []

				return {
					webhooks: this.webhooks,
					stats: this.webhookStats,
					failures: this.webhookFailures,
				}
			} catch (e) {
				this.error =
					e.response?.data?.error
					?? e.message
					?? 'Failed to fetch webhook health'
				console.error('[quality.fetchWebhookHealth]', e)
				throw e
			} finally {
				this.loading = false
			}
		},
	},
})
