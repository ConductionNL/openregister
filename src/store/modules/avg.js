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

import { defineStore } from 'pinia'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const API_BASE = generateUrl('/apps/openregister/api/avg')

/**
 * Base path for the Phase-1 DSAR case-management API
 * (`/api/gdpr/cases/...`). Isolated in ONE constant so a route-shape
 * change is a single-line edit (design decision: the engine marks the
 * case routes "provisional").
 */
const CASE_API_BASE = generateUrl('/apps/openregister/api/gdpr/cases')

/**
 * Base path for the generic OpenRegister objects API. Used to LIST cases
 * and to read policy packs (there is no bespoke list endpoint — cases and
 * packs are plain OR objects).
 */
const OBJECTS_API_BASE = generateUrl('/apps/openregister/api/objects')

/**
 * Register + schema slugs for the Phase-1 case register and the Phase-2
 * policy-pack register. Mirror `CaseObjectAccessor::REGISTER_SLUG` /
 * `SCHEMA_SLUG` and the `dsar-policy-packs` register respectively.
 */
export const CASE_REGISTER_SLUG = 'data-subject-requests'
export const CASE_SCHEMA_SLUG = 'dataSubjectRequest'
export const PACK_REGISTER_SLUG = 'dsar-policy-packs'
export const PACK_SCHEMA_SLUG = 'dsarPolicyPack'

/**
 * Jurisdiction key of the neutral fail-closed baseline pack, used as the
 * fallback when a case carries no jurisdiction (or no matching pack).
 */
export const DEFAULT_JURISDICTION = 'default'

/**
 * Declarative MIRROR of the case register's `x-openregister-lifecycle`
 * transition graph (`data_subject_request_register.json`). The UI offers
 * only the transitions declared FROM the case's current state; it embeds
 * no state machine of its own — the declared graph and its guards remain
 * authoritative server-side (the `transition` endpoint accepts or refuses).
 * Kept here (like `RECHTSGROND_VOCABULARY` mirrors its backend vocab) so a
 * single source drives the buttons.
 */
export const CASE_LIFECYCLE_TRANSITIONS = Object.freeze([
	{ action: 'startVerifying', from: ['received', 'verifying', 'in-progress'], to: 'verifying' },
	{ action: 'startProgress', from: ['received', 'verifying', 'in-progress'], to: 'in-progress' },
	{ action: 'assign', from: ['received', 'verifying', 'in-progress', 'evidence-collection', 'denial-drafted'], to: 'verifying' },
	{ action: 'collectEvidence', from: ['verifying', 'in-progress', 'evidence-collection'], to: 'evidence-collection' },
	{ action: 'draftDenial', from: ['verifying', 'in-progress', 'evidence-collection', 'denial-drafted'], to: 'denial-drafted' },
	{ action: 'finaliseDenial', from: ['denial-drafted'], to: 'refused', guarded: 'regulatorReference' },
	{ action: 'redact', from: ['in-progress', 'evidence-collection'], to: 'in-progress' },
	{ action: 'bundle', from: ['in-progress', 'evidence-collection'], to: 'fulfilled' },
	{ action: 'fulfil', from: ['received', 'verifying', 'in-progress'], to: 'fulfilled' },
	{ action: 'refuse', from: ['received', 'verifying', 'in-progress'], to: 'refused' },
	{ action: 'close', from: ['received', 'verifying', 'in-progress'], to: 'closed' },
	{ action: 'retain', from: ['fulfilled', 'refused', 'closed'], to: 'closed' },
])

/**
 * Case status vocabulary (mirrors the `status` enum on the
 * `dataSubjectRequest` schema). Used to populate the status filter select.
 */
export const CASE_STATUS_VOCABULARY = Object.freeze([
	'received',
	'verifying',
	'in-progress',
	'evidence-collection',
	'denial-drafted',
	'fulfilled',
	'refused',
	'closed',
])

/**
 * Deadline-tracking tier keys emitted by the Phase-1 `escalationTier`
 * calculation, in ascending severity. Their human LABELS resolve from the
 * active pack (see `resolveTierLabel`), never inlined here.
 */
export const CASE_ESCALATION_TIERS = Object.freeze(['on-track', 'reminder', 'escalation', 'breached'])

/**
 * Humanise a generic kebab/enum key into an English source label
 * (ADR-007 — English is the i18n source; NC handles translation).
 *
 * @param {string} key The generic key.
 * @return {string} A humanised label.
 */
function humaniseKey(key) {
	if (!key) return ''
	return String(key)
		.replace(/[-_]/g, ' ')
		.replace(/^\w/, (c) => c.toUpperCase())
}

/**
 * Resolve the human label for a case status from the active pack.
 * Honours an optional `statusLabels` map on the pack (so a steward can
 * override wording without a code change); falls back to the humanised
 * generic key. No jurisdiction-specific string is inlined.
 *
 * @param {object|null} pack   The active policy pack.
 * @param {string}      status The generic status key.
 * @return {string} The resolved label.
 */
export function resolveStatusLabel(pack, status) {
	const overrides = pack?.statusLabels
	if (overrides && typeof overrides === 'object' && overrides[status]) {
		return overrides[status]
	}
	return humaniseKey(status)
}

/**
 * Resolve the human label for an escalation tier from the active pack's
 * `escalationTiers` (index 0 = reminder, 1 = escalation, 2 = breach). The
 * `on-track` tier and any unmapped tier fall back to the humanised key.
 * No tier wording is inlined.
 *
 * @param {object|null} pack The active policy pack.
 * @param {string}      tier The tier key (on-track|reminder|escalation|breached).
 * @return {string} The resolved tier label.
 */
export function resolveTierLabel(pack, tier) {
	if (!tier || tier === 'on-track') {
		return humaniseKey(tier || 'on-track')
	}
	const tiers = Array.isArray(pack?.escalationTiers) ? pack.escalationTiers : []
	const index = { reminder: 0, escalation: 1, breached: 2 }[tier]
	const packTier = index !== undefined ? tiers[index]?.tier : undefined
	return packTier || humaniseKey(tier)
}

/**
 * Map the active pack's denial-grounds enum (key -> label + citation) to
 * NcSelect option objects. The generic keys live on the case schema; the
 * jurisdiction label + citation live on the pack (ADR-047).
 *
 * @param {object|null} pack The active policy pack.
 * @return {Array<{value: string, label: string, citation: string}>} Options.
 */
export function resolveGroundOptions(pack) {
	const grounds = Array.isArray(pack?.denialGrounds) ? pack.denialGrounds : []
	return grounds
		.filter((g) => g && g.key)
		.map((g) => ({ value: g.key, label: g.label ?? humaniseKey(g.key), citation: g.citation ?? '' }))
}

/**
 * Resolve a letter/notification template REFERENCE (a `template:` leaf id,
 * ADR-022) from the active pack. The UI references the template — it never
 * inlines the body text.
 *
 * @param {object|null} pack The active policy pack.
 * @param {string}      key  The template key (acknowledgement|denial|extension).
 * @return {string} The template reference, or empty string.
 */
export function resolveTemplateRef(pack, key) {
	return pack?.templates?.[key] ?? ''
}

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
		// Case-management (Phase-2) surface state.
		cases: [],
		activeCase: null,
		activePolicyPack: null,
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
		getCases: (state) => state.cases,
		getActiveCase: (state) => state.activeCase,
		getActivePolicyPack: (state) => state.activePolicyPack,
		/**
		 * @param {object} state
		 */
		getActivityByUuid: (state) => (uuid) =>
			state.activities.find((a) => a.uuid === uuid) ?? null,
	},
	actions: {
		/**
		 * @spec exclude Pure client UI-state mutator — clears the store error flag. No backend contract.
		 */
		clearError() {
			this.error = null
		},

		/**
		 * Fetch all verwerkingsactiviteiten.
		 *
		 * @param {object} params Optional `?status=` and `?organisation=` query filters.
		 *
		 * @spec exclude Thin API passthrough — GET /api/avg/verwerkingsactiviteiten; observable contract owned by avg-verwerkingsregister.
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
		 *
		 * @spec exclude Thin API passthrough — GET /api/avg/verwerkingsactiviteiten/{id}; observable contract owned by avg-verwerkingsregister.
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
		 *
		 * @spec exclude Thin API passthrough — POST /api/avg/verwerkingsactiviteiten; observable contract owned by avg-verwerkingsregister.
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
		 *
		 * @spec exclude Thin API passthrough — PUT /api/avg/verwerkingsactiviteiten/{id}; observable contract owned by avg-verwerkingsregister.
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
		 *
		 * @spec exclude Thin API passthrough — DELETE /api/avg/verwerkingsactiviteiten/{id} (soft-archive); observable contract owned by avg-verwerkingsregister.
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
		 *
		 * @spec exclude Thin API passthrough — GET /api/avg/verantwoording (Art 30 §4); observable contract owned by avg-verwerkingsregister.
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
		 *
		 * @param params.subject
		 * @param params.type
		 * @param params.mode
		 * @spec exclude Thin API passthrough — GET /api/avg/inzage (Art 15 DSAR); observable contract owned by avg-verwerkingsregister.
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
		 *
		 * @param params.subject
		 * @param params.type
		 * @param params.dryRun
		 * @spec exclude Thin API passthrough — POST /api/avg/vergetelheid (Art 17 erasure); observable contract owned by avg-verwerkingsregister.
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
		 *
		 * @param params.subject
		 * @param params.type
		 * @spec exclude Thin API passthrough — GET /api/avg/portabiliteit (Art 20 portability); observable contract owned by avg-verwerkingsregister.
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
		 *
		 * @spec exclude Thin API passthrough — POST /api/avg/rectificatie (Art 16 rectification); observable contract owned by avg-verwerkingsregister.
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
		 *
		 * @spec exclude Thin API passthrough — GET /api/avg/compliance; observable contract owned by avg-verwerkingsregister.
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

		// -------------------------------------------------------------
		// DSAR case management (Phase-2). Thin passthroughs over the
		// Phase-1 `/api/gdpr/cases/...` API + the generic objects API,
		// mirroring the runInzage/runVergetelheid style above. No
		// business logic lives here — the lifecycle, guards, deadlines,
		// and seams stay declarative + server-side.
		// -------------------------------------------------------------

		/**
		 * List tracked DSAR cases (RBAC + tenant scoped server-side).
		 *
		 * @param {object} params Optional `status`, `handler`, `isOverdue` filters.
		 *
		 * @spec exclude Thin API passthrough — GET /api/objects/{caseRegister}/{caseSchema}; observable contract owned by dsar-case-engine.
		 */
		async fetchCases(params = {}) {
			this.loading = true
			this.error = null
			try {
				const response = await axios.get(
					`${OBJECTS_API_BASE}/${encodeURIComponent(CASE_REGISTER_SLUG)}/${encodeURIComponent(CASE_SCHEMA_SLUG)}`,
					{ params: { _limit: 200, ...params } },
				)
				this.cases = response.data?.results ?? response.data ?? []
				return this.cases
			} catch (e) {
				this.error = e.response?.data?.error ?? e.message ?? 'Failed to fetch cases'
				console.error('[avg.fetchCases]', e)
				throw e
			} finally {
				this.loading = false
			}
		},

		/**
		 * Fetch a single case by id|uuid.
		 *
		 * @param {string} identifier The case uuid.
		 *
		 * @spec exclude Thin API passthrough — GET /api/objects/{caseRegister}/{caseSchema}/{id}; observable contract owned by dsar-case-engine.
		 */
		async fetchCase(identifier) {
			if (!identifier) return null
			this.loading = true
			this.error = null
			try {
				const response = await axios.get(
					`${OBJECTS_API_BASE}/${encodeURIComponent(CASE_REGISTER_SLUG)}/${encodeURIComponent(CASE_SCHEMA_SLUG)}/${encodeURIComponent(identifier)}`,
				)
				this.activeCase = response.data ?? null
				return this.activeCase
			} catch (e) {
				this.error = e.response?.data?.error ?? e.message ?? 'Failed to fetch case'
				console.error('[avg.fetchCase]', e)
				throw e
			} finally {
				this.loading = false
			}
		},

		/**
		 * Patch case fields (e.g. `denialGround`, `regulatorReference`)
		 * through the generic objects API before a gated transition.
		 *
		 * @param {string} identifier The case uuid.
		 * @param {object} patch      The partial fields to write.
		 *
		 * @spec exclude Thin API passthrough — PUT /api/objects/{caseRegister}/{caseSchema}/{id}; observable contract owned by dsar-case-engine.
		 */
		async updateCase(identifier, patch) {
			this.loading = true
			this.error = null
			try {
				const response = await axios.put(
					`${OBJECTS_API_BASE}/${encodeURIComponent(CASE_REGISTER_SLUG)}/${encodeURIComponent(CASE_SCHEMA_SLUG)}/${encodeURIComponent(identifier)}`,
					patch,
				)
				this.activeCase = response.data ?? this.activeCase
				return this.activeCase
			} catch (e) {
				this.error = e.response?.data?.error ?? e.message ?? 'Failed to update case'
				console.error('[avg.updateCase]', e)
				throw e
			} finally {
				this.loading = false
			}
		},

		/**
		 * Run a declared lifecycle transition on a case.
		 *
		 * @param {string} identifier The case uuid.
		 * @param {string} action     The declared transition name.
		 *
		 * @spec exclude Thin API passthrough — POST /api/gdpr/cases/{id}/transition; guard + state graph owned by dsar-case-engine.
		 */
		async transitionCase(identifier, action) {
			this.loading = true
			this.error = null
			try {
				const response = await axios.post(`${CASE_API_BASE}/${encodeURIComponent(identifier)}/transition`, { action })
				this.activeCase = response.data ?? this.activeCase
				return this.activeCase
			} catch (e) {
				this.error = e.response?.data?.error ?? e.message ?? 'Transition refused'
				console.error('[avg.transitionCase]', e)
				throw e
			} finally {
				this.loading = false
			}
		},

		/**
		 * Draft a denial (ungated). Records the ground then posts the
		 * `draftDenial` transition.
		 *
		 * @param {string} identifier The case uuid.
		 * @param {string} ground     The selected denial-ground key.
		 *
		 * @spec exclude Thin API passthrough — records denialGround then POST /api/gdpr/cases/{id}/transition draftDenial; owned by dsar-case-engine.
		 */
		async draftDenial(identifier, ground) {
			if (ground) {
				await this.updateCase(identifier, { denialGround: ground })
			}
			return this.transitionCase(identifier, 'draftDenial')
		},

		/**
		 * Finalise a denial. Gated server-side on a `regulatorReference`
		 * (the `DenialFinaliseGuard`); the server refusal is authoritative.
		 *
		 * @param {string} identifier The case uuid.
		 *
		 * @spec exclude Thin API passthrough — POST /api/gdpr/cases/{id}/transition finaliseDenial; guard owned by dsar-case-engine.
		 */
		async finaliseDenial(identifier) {
			return this.transitionCase(identifier, 'finaliseDenial')
		},

		/**
		 * Trigger the evidence harvest for a case.
		 *
		 * @param {string} identifier The case uuid.
		 *
		 * @spec exclude Thin API passthrough — POST /api/gdpr/cases/{id}/evidence; harvest owned by dsar-case-engine.
		 */
		async collectEvidence(identifier) {
			this.loading = true
			this.error = null
			try {
				const response = await axios.post(`${CASE_API_BASE}/${encodeURIComponent(identifier)}/evidence`)
				return response.data
			} catch (e) {
				this.error = e.response?.data?.error ?? e.message ?? 'Evidence harvest failed'
				console.error('[avg.collectEvidence]', e)
				throw e
			} finally {
				this.loading = false
			}
		},

		/**
		 * Apply a field-level redaction (before/after + ground) to a case.
		 *
		 * @param {string} identifier The case uuid.
		 * @param {object} payload    {field, after, ground}.
		 *
		 * @spec exclude Thin API passthrough — POST /api/gdpr/cases/{id}/redactions; redaction write owned by dsar-case-engine.
		 */
		async applyRedaction(identifier, payload) {
			this.loading = true
			this.error = null
			try {
				const response = await axios.post(`${CASE_API_BASE}/${encodeURIComponent(identifier)}/redactions`, payload)
				return response.data
			} catch (e) {
				this.error = e.response?.data?.error ?? e.message ?? 'Redaction failed'
				console.error('[avg.applyRedaction]', e)
				throw e
			} finally {
				this.loading = false
			}
		},

		/**
		 * Generate the signed export bundle for a case. Returns metadata +
		 * a one-time download token (not the bytes).
		 *
		 * @param {string} identifier The case uuid.
		 *
		 * @spec exclude Thin API passthrough — POST /api/gdpr/cases/{id}/bundle; bundle mint + token burn owned by dsar-case-engine.
		 */
		async generateBundle(identifier) {
			this.loading = true
			this.error = null
			try {
				const response = await axios.post(`${CASE_API_BASE}/${encodeURIComponent(identifier)}/bundle`)
				return response.data
			} catch (e) {
				this.error = e.response?.data?.error ?? e.message ?? 'Bundle generation failed'
				console.error('[avg.generateBundle]', e)
				throw e
			} finally {
				this.loading = false
			}
		},

		/**
		 * Redeem the one-time bundle token for a single authenticated
		 * download of the bytes (blob).
		 *
		 * @param {string} identifier The case uuid.
		 * @param {string} token      The one-time download token.
		 *
		 * @spec exclude Thin API passthrough — GET /api/gdpr/cases/{id}/bundle/download; one-time token burned server-side.
		 */
		async downloadBundle(identifier, token) {
			this.loading = true
			this.error = null
			try {
				const response = await axios.get(
					`${CASE_API_BASE}/${encodeURIComponent(identifier)}/bundle/download`,
					{ params: { token }, responseType: 'blob' },
				)
				return response.data
			} catch (e) {
				this.error = e.response?.data?.error ?? e.message ?? 'Download failed'
				console.error('[avg.downloadBundle]', e)
				throw e
			} finally {
				this.loading = false
			}
		},

		/**
		 * Trigger the pack-selected identity-verify seam (fail-closed).
		 * Returns the three-state result ({status, provider, message}).
		 *
		 * @param {string} identifier The case uuid.
		 *
		 * @spec exclude Thin API passthrough — POST /api/gdpr/cases/{id}/verify-identity; seam resolution owned by dsar-integration-seams.
		 */
		async verifyIdentity(identifier) {
			this.loading = true
			this.error = null
			try {
				const response = await axios.post(`${CASE_API_BASE}/${encodeURIComponent(identifier)}/verify-identity`)
				return response.data
			} catch (e) {
				this.error = e.response?.data?.error ?? e.message ?? 'Identity verification failed'
				console.error('[avg.verifyIdentity]', e)
				throw e
			} finally {
				this.loading = false
			}
		},

		/**
		 * Trigger the pack-selected regulator-escalate seam (fail-closed).
		 * Returns the result ({status, provider, reference, message}).
		 *
		 * @param {string} identifier The case uuid.
		 *
		 * @spec exclude Thin API passthrough — POST /api/gdpr/cases/{id}/escalate; seam resolution owned by dsar-integration-seams.
		 */
		async escalateRegulator(identifier) {
			this.loading = true
			this.error = null
			try {
				const response = await axios.post(`${CASE_API_BASE}/${encodeURIComponent(identifier)}/escalate`)
				return response.data
			} catch (e) {
				this.error = e.response?.data?.error ?? e.message ?? 'Regulator escalation failed'
				console.error('[avg.escalateRegulator]', e)
				throw e
			} finally {
				this.loading = false
			}
		},

		/**
		 * Load the active policy pack (tenant scope, with a per-case
		 * `jurisdiction` override) and cache it in state, so labels /
		 * grounds / tier wording resolve client-side without a per-row
		 * round-trip. Selects the pack whose `jurisdiction` matches, else
		 * the `default` pack.
		 *
		 * @param {object} params               Options.
		 * @param {string} [params.jurisdiction] Optional per-case jurisdiction override.
		 *
		 * @spec exclude Thin API passthrough — GET /api/objects/{packRegister}/{packSchema}; pack contract owned by dsar-policy-pack-and-seams.
		 */
		async fetchActivePolicyPack({ jurisdiction } = {}) {
			this.loading = true
			this.error = null
			try {
				const response = await axios.get(
					`${OBJECTS_API_BASE}/${encodeURIComponent(PACK_REGISTER_SLUG)}/${encodeURIComponent(PACK_SCHEMA_SLUG)}`,
					{ params: { _limit: 200 } },
				)
				const packs = response.data?.results ?? response.data ?? []
				const wanted = jurisdiction || DEFAULT_JURISDICTION
				this.activePolicyPack = packs.find((p) => p.jurisdiction === wanted)
					?? packs.find((p) => p.jurisdiction === DEFAULT_JURISDICTION)
					?? packs[0]
					?? null
				return this.activePolicyPack
			} catch (e) {
				this.error = e.response?.data?.error ?? e.message ?? 'Failed to fetch policy pack'
				console.error('[avg.fetchActivePolicyPack]', e)
				throw e
			} finally {
				this.loading = false
			}
		},
	},
})
